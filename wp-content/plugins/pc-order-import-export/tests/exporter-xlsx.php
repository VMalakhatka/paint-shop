<?php
// Standalone round-trip test of the real download handler, including its exit.
function fixture(string $case): array {
    if ($case === 'wide') return [range('A', 'Z') + [26 => 'AA'], [array_fill(0, 27, 'value')]];
    $header = ['SKU', 'Barcode', 'Name', 'Quantity', 'Price', 'Total', 'Warehouse'];
    $rows = [['sku' => 'P-200020', 'gtin' => '0012345678905', 'name' => 'Лак прозорий',
        'qty' => 2, 'price' => '288.98', 'total' => '577.96', 'note' => 'Київ'],
        ['sku' => 'KR-79406', 'gtin' => '', 'name' => 'Varnish',
        'qty' => 1.5, 'price' => '10.00', 'total' => '15.00', 'note' => 'Одеса']];
    return [$header, $case === 'empty' ? [] : $rows];
}

if (($argv[1] ?? '') === '--emit') {
    function nocache_headers() {}
    if ($argv[2] !== 'fallback') {
        require dirname(__DIR__, 3) . '/vendor/autoload.php';
    } else {
        class ExportTestLabels { public static function labels() { return ['xls_missing' => 'XLSX unavailable']; } }
        class_alias(ExportTestLabels::class, 'PaintCore\\PCOE\\Helpers');
    }
    require dirname(__DIR__) . '/inc/Exporter.php';
    [$header, $rows] = fixture($argv[2]);
    $method = new ReflectionMethod('PaintCore\\PCOE\\Exporter', 'send_xlsx');
    $method->setAccessible(true);
    $method->invoke(null, $header, $rows, 'export-test');
    throw new RuntimeException('Download handler did not exit');
}

require dirname(__DIR__, 3) . '/vendor/autoload.php';
function expect($condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
foreach (['normal', 'empty', 'wide', 'fallback'] as $case) {
    $file = tempnam(sys_get_temp_dir(), 'pcoe-xlsx-');
    try {
        $process = proc_open([PHP_BINARY, __FILE__, '--emit', $case],
            [0 => ['pipe', 'r'], 1 => ['file', $file, 'w'], 2 => ['pipe', 'w']], $pipes);
        expect(is_resource($process), 'Cannot start download test');
        fclose($pipes[0]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        expect(proc_close($process) === 0, 'Download failed: ' . $error);
        [$header, $rows] = fixture($case);
        if ($case === 'fallback') {
            $csv = file_get_contents($file);
            expect(str_starts_with($csv, "\xEF\xBB\xBF"), 'CSV BOM preserved');
            expect(str_contains($csv, 'XLSX unavailable') && str_contains($csv, 'P-200020'), 'CSV fallback preserved');
        } else {
            expect(file_get_contents($file, false, null, 0, 2) === 'PK', 'XLSX ZIP output without warnings');
            $book = \PhpOffice\PhpSpreadsheet\IOFactory::load($file);
            $sheet = $book->getActiveSheet();
            foreach (array_merge([$header], array_map('array_values', $rows)) as $r => $values) {
                foreach ($values as $c => $value) {
                    $cell = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($c + 1) . ($r + 1);
                    $actual = $sheet->getCell($cell)->getValue();
                    $numeric = $case !== 'wide' && $r > 0 && in_array($c, [3, 4, 5], true);
                    expect($numeric ? is_numeric($actual) && (float) $actual === (float) $value
                        : (string) $actual === (string) $value, 'Cell mismatch: ' . $cell);
                }
            }
            expect($sheet->getHighestDataRow() === count($rows) + 1, 'Row count');
            expect($sheet->getHighestDataColumn() === \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($header)), 'Column count');
            $book->disconnectWorksheets();
        }
        echo 'Exporter ' . $case . ": OK\n";
    } finally {
        unlink($file);
    }
}
