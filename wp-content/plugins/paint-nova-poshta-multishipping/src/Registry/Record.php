<?php

namespace Paint\NovaPoshta\Registry;

defined('ABSPATH') || exit;

final class Record
{
    public const START = '2026-06-01';
    public const DIRECTIONS = ['unknown', 'outgoing', 'incoming', 'internal'];
    public const PURPOSES = ['unclassified', 'sale', 'customer_return', 'purchase', 'supplier_return', 'transfer', 'other'];

    public static function ttn(string $value): string
    {
        $value = trim($value);
        if (!preg_match('/^\d{14}$/D', $value)) throw new \InvalidArgumentException('Enter a 14-digit TTN.');
        return $value;
    }

    public static function date(string $value): string
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$date || $date->format('Y-m-d') !== $value) throw new \InvalidArgumentException('Invalid date.');
        return $value;
    }

    /** Carrier response only, not request/auth data. Keep available fields, never secret-bearing URLs. */
    public static function clean(array $value, int $depth = 0): array
    {
        if ($depth > 8 || count($value) > 1000) throw new \InvalidArgumentException('Response is too large.');
        $out = [];
        foreach ($value as $key => $item) {
            if (preg_match('/api.?key|token|password|secret|authorization|cookie|url|^uri$/i', (string) $key)) continue;
            if (is_array($item)) $out[$key] = self::clean($item, $depth + 1);
            elseif (is_null($item) || is_bool($item) || is_int($item) || is_float($item)) $out[$key] = $item;
            elseif (is_string($item)) {
                if (strlen($item) > 10000) throw new \InvalidArgumentException('Response field is too large.');
                if (preg_match('~https?://|api.?key\s*[:=/]~i', $item)) continue;
                $out[$key] = sanitize_textarea_field($item);
            }
        }
        if (!array_is_list($out)) ksort($out);
        return $out;
    }

    public static function metadata(array $input): array
    {
        $direction = (string) ($input['direction'] ?? 'unknown');
        $purpose = (string) ($input['purpose'] ?? 'unclassified');
        if (!in_array($direction, self::DIRECTIONS, true) || !in_array($purpose, self::PURPOSES, true)) throw new \InvalidArgumentException('Invalid classification.');
        $expected = ['sale'=>'outgoing', 'supplier_return'=>'outgoing', 'purchase'=>'incoming', 'customer_return'=>'incoming', 'transfer'=>'internal'];
        if (isset($expected[$purpose]) && $direction !== $expected[$purpose]) throw new \InvalidArgumentException('Direction and purpose do not match.');
        $related = trim((string) ($input['related_ttn'] ?? ''));
        return ['direction'=>$direction, 'purpose'=>$purpose,
            'partner_code'=>self::text($input['partner_code'] ?? '', 32),
            'related_ttn'=>$related === '' ? '' : self::ttn($related),
            'note'=>self::text($input['note'] ?? '', 1000)];
    }

    public static function text($value, int $limit): string
    {
        if (!is_scalar($value)) throw new \InvalidArgumentException('Invalid field.');
        $value = trim(sanitize_text_field((string) $value));
        if (mb_strlen($value) > $limit) throw new \InvalidArgumentException('Field is too long.');
        return $value;
    }

    /** A stable site import format, not a claim to parse every carrier export layout. */
    public static function csv(string $text): array
    {
        if (strlen($text) > 262144) throw new \InvalidArgumentException('Import is too large.');
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, preg_replace('/^\xEF\xBB\xBF/', '', $text)); rewind($stream);
        $header = fgetcsv($stream, 0, ';', '"', '');
        $expected = ['ttn','date','sender','recipient','origin','destination','payer','delivery_cost'];
        if ($header !== $expected) { fclose($stream); throw new \InvalidArgumentException('Use the displayed CSV header.'); }
        $rows = []; $seen = [];
        try {
            while (($line = fgetcsv($stream, 0, ';', '"', '')) !== false) {
                if ($line === [null]) continue;
                if (count($line) !== count($expected) || count($rows) >= 100) throw new \InvalidArgumentException('Use at most 100 complete CSV rows.');
                $row = array_combine($expected, $line);
                $ttn = self::ttn($row['ttn']);
                if (isset($seen[$ttn])) throw new \InvalidArgumentException('Duplicate TTN in import.');
                $seen[$ttn] = true;
                if (self::date($row['date']) < self::START) throw new \InvalidArgumentException('Import starts on 2026-06-01.');
                if ($row['date'] > wp_date('Y-m-d')) throw new \InvalidArgumentException('Invalid date.');
                $cost = str_replace(',', '.', trim($row['delivery_cost']));
                if ($cost !== '' && !preg_match('/^\d{1,8}(\.\d{1,2})?$/D', $cost)) throw new \InvalidArgumentException('Invalid delivery cost.');
                $row['delivery_cost'] = $cost === '' ? null : $cost;
                $rows[] = ['ttn'=>$ttn, 'data'=>self::clean($row)];
            }
        } finally { fclose($stream); }
        if (!$rows) throw new \InvalidArgumentException('Import is empty.');
        return $rows;
    }
}
