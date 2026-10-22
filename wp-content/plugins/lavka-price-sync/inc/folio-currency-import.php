<?php
if (!defined('ABSPATH')) exit;

/** Strict three-column input. Decimal strings remain strings throughout the proxy. */
function lps_currency_rows(array $rows): array {
    $result = []; $seen = []; $first = true;
    foreach ($rows as $number => $row) {
        $row = array_map(static fn($v) => trim((string)$v), $row);
        if (!array_filter($row, static fn($v) => $v !== '')) continue;
        if ($first) {
            $first = false;
            $row[0] = preg_replace('/^\xEF\xBB\xBF/', '', $row[0]);
            if (array_map('strtoupper', $row) === ['CODE_ARTIC','PRICE','KOD_VALUT']) continue;
        }
        if (count($row) !== 3) throw new RuntimeException('ROW_'.($number + 1));
        [$sku,$price,$currency] = $row;
        $price = str_replace(',', '.', $price);
        if ($sku === '' || mb_strlen($sku) > 20 || preg_match('/[\x00-\x1f\x7f]/u', $sku)
            || strlen($price)>64 || !preg_match('/^[+-]?\d+(?:\.\d+)?$/D', $price)
            || $currency === '' || mb_strlen($currency)>4 || preg_match('/[\x00-\x1f\x7f]/u', $currency)) throw new RuntimeException('ROW_'.($number + 1));
        $key = mb_strtolower($sku, 'UTF-8');
        if (isset($seen[$key])) throw new RuntimeException('DUPLICATE_'.($number + 1));
        $seen[$key] = true;
        $result[] = ['sku'=>$sku, 'price'=>$price, 'currency'=>$currency];
        if (count($result) > 500) throw new RuntimeException('TOO_MANY_ROWS');
    }
    if (!$result) throw new RuntimeException('EMPTY_FILE');
    return $result;
}

function lps_currency_file(string $path, string $extension): array {
    if (!is_file($path) || filesize($path) > 5 * 1024 * 1024) throw new RuntimeException('FILE_TOO_LARGE');
    if ($extension === 'csv') {
        $text = file_get_contents($path);
        if (!mb_check_encoding($text, 'UTF-8')) throw new RuntimeException('CSV_UTF8');
        $candidates = [];
        foreach ([";", "\t", ","] as $delimiter) {
            $stream = fopen('php://temp','r+'); fwrite($stream, $text); rewind($stream); $rows = [];
            try {
                while (($row = fgetcsv($stream, 0, $delimiter, '"', '')) !== false) {
                    $rows[] = $row;
                    if (count($rows)>10000) throw new RuntimeException('TOO_MANY_ROWS');
                }
                $candidates[] = lps_currency_rows($rows);
            } catch (RuntimeException $e) { /* Try the remaining supported separators. */ }
            finally { fclose($stream); }
        }
        if (count($candidates) !== 1) throw new RuntimeException('CSV_FORMAT');
        return $candidates[0];
    }
    if (!in_array($extension, ['xls','xlsx'], true) || !class_exists(\PhpOffice\PhpSpreadsheet\IOFactory::class)) throw new RuntimeException('EXCEL_FORMAT');
    if (file_get_contents($path, false, null, 0, 2) === 'PK') {
        $zip = new ZipArchive(); if ($zip->open($path) !== true) throw new RuntimeException('EXCEL_FORMAT');
        try {
            $size = 0;
            if ($zip->numFiles > 10000) throw new RuntimeException('FILE_TOO_LARGE');
            for ($i=0; $i<$zip->numFiles; $i++) {
                $entry = $zip->statIndex($i); if (!$entry) throw new RuntimeException('EXCEL_FORMAT');
                $size += $entry['size']; if ($size>64*1024*1024) throw new RuntimeException('FILE_TOO_LARGE');
            }
        } finally { $zip->close(); }
    }
    $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($path, ['Xls','Xlsx']);
    $names = $reader->listWorksheetNames($path); if (!$names) throw new RuntimeException('EXCEL_FORMAT');
    $info = $reader->listWorksheetInfo($path);
    if (($info[0]['totalRows'] ?? 10001)>10000 || ($info[0]['totalColumns'] ?? 4)>3) throw new RuntimeException('EXCEL_RANGE');
    $reader->setLoadSheetsOnly($names[0]); $book = $reader->load($path);
    try {
        $rows = []; $sheet = $book->getSheet(0);
        foreach ($sheet->getCellCollection()->getCoordinates() as $coordinate) {
            $cell = $sheet->getCell($coordinate);
            if (in_array($cell->getDataType(), ['f','e'], true)) throw new RuntimeException('EXCEL_FORMULA');
            $r = $cell->getRow()-1; $c = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($cell->getColumn())-1;
            if ($c>2 || $r>9999) throw new RuntimeException('EXCEL_RANGE');
            if (!isset($rows[$r])) $rows[$r] = ['', '', ''];
            // Preserve article display masks; numbers use raw values (no currency/group formatting).
            $rows[$r][$c] = $c === 0 ? $cell->getFormattedValue() : $cell->getValue();
        }
        ksort($rows); return lps_currency_rows($rows);
    } finally { $book->disconnectWorksheets(); }
}
