<?php
namespace Lavka\Workshops;
defined('ABSPATH') || exit;

function input_text(array $input, string $key): string {
    return isset($input[$key]) && is_scalar($input[$key]) ? sanitize_text_field(wp_unslash((string) $input[$key])) : '';
}

function cities(): array {
    return ['kyiv' => __('Kyiv', 'lavka-workshops'), 'odesa' => __('Odesa', 'lavka-workshops')];
}
function timezone(): \DateTimeZone { return new \DateTimeZone('Europe/Kyiv'); }

/** Strict validation: malformed rows never silently replace the previous schedule. */
function validate_sessions(array $rows): array|\WP_Error {
    if (count($rows) > 100) return new \WP_Error('sessions', __('Use at most 100 dates per workshop.', 'lavka-workshops'));
    $result = [];
    foreach ($rows as $row) {
        if (!is_array($row)) return new \WP_Error('sessions', __('Check the date, city, address, duration and price in every row.', 'lavka-workshops'));
        foreach ($row as $value) {
            if (!is_scalar($value)) return new \WP_Error('sessions', __('Check the date, city, address, duration and price in every row.', 'lavka-workshops'));
        }
        $date = (string) ($row['date'] ?? '');
        $time = (string) ($row['time'] ?? '');
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d H:i', "$date $time", timezone());
        $city = (string) ($row['city'] ?? '');
        $price = str_replace(',', '.', (string) ($row['price'] ?? ''));
        $duration = (string) ($row['duration'] ?? '');
        $state = (string) ($row['state'] ?? 'open');
        $address = sanitize_text_field($row['address'] ?? '');
        if (!$parsed || $parsed->format('Y-m-d H:i') !== "$date $time" || !isset(cities()[$city]) ||
            !preg_match('/^\d{1,6}(\.\d{1,2})?$/D', $price) || !ctype_digit($duration) || (int) $duration < 15 || (int) $duration > 1440 ||
            !in_array($state, ['open', 'full', 'cancelled'], true) || !$address || mb_strlen($address) > 240) {
            return new \WP_Error('sessions', __('Check the date, city, address, duration and price in every row.', 'lavka-workshops'));
        }
        $id = (string) ($row['id'] ?? '');
        if (!preg_match('/^[a-f0-9-]{36}$/D', $id)) $id = wp_generate_uuid4();
        if (isset($result[$id])) return new \WP_Error('sessions', __('Duplicate date identifier. Reload the editor.', 'lavka-workshops'));
        $result[$id] = ['id' => $id, 'date' => $date, 'time' => $time, 'timestamp' => $parsed->getTimestamp(), 'city' => $city,
            'address' => $address, 'price' => number_format((float) $price, 2, '.', ''), 'duration' => (int) $duration, 'state' => $state];
    }
    uasort($result, static fn($a, $b) => $a['timestamp'] <=> $b['timestamp']);
    return array_values($result);
}
function sessions(int $post_id, bool $future = false): array {
    $rows = get_post_meta($post_id, '_lw_sessions', true);
    if (!is_array($rows)) return [];
    return array_values(array_filter($rows, static fn($row) => is_array($row) && (!$future || ($row['timestamp'] > time() && $row['state'] !== 'cancelled'))));
}
function session_label(array $row): string {
    return wp_date('j F, H:i', $row['timestamp'], timezone()) . ' · ' . cities()[$row['city']];
}
function price_label(array $row): string {
    return number_format_i18n((float) $row['price'], (float) $row['price'] == (int) $row['price'] ? 0 : 2) . ' ₴';
}
