<?php
if (!defined('ABSPATH')) exit;

/** Read only the chosen column. Never evaluate formulas or follow workbook links. */
function lps_card_excel_skus(string $path, string $column, int $firstRow): array {
    $column = strtoupper(trim($column));
    if (!preg_match('/^[A-Z]{1,3}$/D', $column) || $firstRow < 1 || $firstRow > 10000
        || !is_file($path) || filesize($path) > 5 * 1024 * 1024
        || !class_exists(\PhpOffice\PhpSpreadsheet\IOFactory::class)) throw new RuntimeException('INVALID_EXCEL');
    if (\PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($column) > 16384) throw new RuntimeException('INVALID_EXCEL');
    // Bound expanded XLSX size before the reader allocates XML/shared strings.
    if (file_get_contents($path, false, null, 0, 2) === 'PK') {
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) throw new RuntimeException('INVALID_EXCEL');
        try {
            $bytes = 0;
            if ($zip->numFiles > 10000) throw new RuntimeException('INVALID_EXCEL');
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $entry = $zip->statIndex($i);
                if ($entry === false) throw new RuntimeException('INVALID_EXCEL');
                $bytes += $entry['size'];
                if ($bytes > 64 * 1024 * 1024) throw new RuntimeException('INVALID_EXCEL');
            }
        } finally { $zip->close(); }
    }
    $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($path, ['Xls', 'Xlsx']);
    $sheets = $reader->listWorksheetNames($path);
    if (!$sheets) throw new RuntimeException('INVALID_EXCEL');
    $reader->setLoadSheetsOnly($sheets[0]);
    $reader->setReadFilter(new class($column, $firstRow) implements \PhpOffice\PhpSpreadsheet\Reader\IReadFilter {
        public function __construct(private string $column, private int $firstRow) {}
        public function readCell(string $columnAddress, int $row, string $worksheetName = ''): bool {
            return $columnAddress === $this->column && $row >= $this->firstRow;
        }
    });
    $book = $reader->load($path);
    try {
        $sheet = $book->getSheet(0); $skus = [];
        // Iterate allocated cells, not a possibly enormous formatted worksheet range.
        foreach ($sheet->getCellCollection()->getCoordinates() as $coordinate) {
            $cell = $sheet->getCell($coordinate);
            if ($cell->getDataType() === 'f' || $cell->getDataType() === 'e') throw new RuntimeException('INVALID_EXCEL');
            $raw = $cell->getValue();
            if ($raw === null || $raw === '') continue;
            // Formatting retains numeric identifiers stored with a zero-padding mask.
            $value = trim((string)$cell->getFormattedValue());
            if ($value === '') continue;
            if (mb_strlen($value) > 100 || preg_match('/[\x00-\x1f\x7f]/u', $value)) throw new RuntimeException('INVALID_EXCEL');
            $skus[$value] = $value;
            if (count($skus) > 500) throw new RuntimeException('INVALID_EXCEL');
        }
        if (!$skus) throw new RuntimeException('INVALID_EXCEL');
        return array_values($skus);
    } finally { $book->disconnectWorksheets(); }
}

add_action('wp_ajax_lps_folio_product_cards_import', static function (): void {
    if (!current_user_can(LPS_CAP)) wp_send_json_error(['message'=>__('Access denied.', 'lavka-price-sync')], 403);
    check_ajax_referer(LPS_CARD_NONCE, '_wpnonce');
    try {
        $file = $_FILES['file'] ?? [];
        $column = $_POST['column'] ?? 'A'; $firstRow = $_POST['firstRow'] ?? '2';
        if (!is_string($column) || !is_string($firstRow) || !ctype_digit($firstRow)
            || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
            || !is_string($file['tmp_name'] ?? null) || !is_uploaded_file($file['tmp_name'])
            || !is_string($file['name'] ?? null) || !in_array(strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)), ['xls','xlsx'], true)) throw new RuntimeException('INVALID_EXCEL');
        $skus = lps_card_excel_skus($file['tmp_name'], $column, (int)$firstRow);
        wp_send_json_success(['skus'=>$skus]);
    } catch (Throwable $e) {
        wp_send_json_error(['message'=>__('Could not read the list. Choose an XLS/XLSX file up to 5 MB, a valid column and first data row. Use up to 500 unique SKUs; formulas are not accepted.', 'lavka-price-sync')], 400);
    }
    // PHP removes the uploaded temporary file at request shutdown. No Media Library copy.
});
