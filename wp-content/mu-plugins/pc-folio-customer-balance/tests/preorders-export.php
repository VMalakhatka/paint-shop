<?php
// Standalone XLSX regression: current outstanding invoices stay separate from posted ledger.
define('ABSPATH', __DIR__);
function __($text, $domain = '') { return $text; }
function pc_folio_balance_export_date($value) { return (string) $value; }
require dirname(__DIR__, 3) . '/vendor/autoload.php';
require dirname(__DIR__) . '/inc/preorders-export.php';
$book = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
$book->getActiveSheet()->setCellValue('A1', 'Ledger');
pc_folio_balance_add_preorders_sheet($book, ['summary' => ['prepaymentAmount' => 181000], 'preorders' => [
    ['documentNumber' => '555265', 'documentDate' => '2026-07-21', 'warehouseLabel' => 'Київ ОПТ', 'amount' => 180000, 'information' => '=1+1'],
    ['documentNumber' => '555266', 'documentDate' => '2026-07-22', 'warehouseLabel' => 'Київ', 'amount' => 1000, 'information' => 'ПРД'],
]]);
$file = tempnam(sys_get_temp_dir(), 'preorders-');
(new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($book))->save($file);
$read = \PhpOffice\PhpSpreadsheet\IOFactory::load($file);
unlink($file);
function check($condition, $message) { if (!$condition) throw new RuntimeException($message); }
check($read->getSheetCount() === 2 && $read->getSheet(0)->getCell('A1')->getValue() === 'Ledger', 'Ledger preserved separately');
$sheet = $read->getSheet(1);
check($sheet->getCell('A2')->getValue() === '555265', 'Invoice identity exported');
check($sheet->getCell('E2')->getDataType() === 's', 'Folio text cannot become an Excel formula');
check($sheet->getCell('D2')->getDataType() === 'n', 'Amounts are numeric');
check(abs($sheet->getCell('D4')->getValue() - 181000) < 0.001, 'Reserve total exported');
$main = $read->getSheet(0);
check($main->getCell('A4')->getValue() === 'Preorder invoices', 'Preorders visible on the first sheet');
check($main->getCell('A6')->getValue() === '555265', 'First-sheet invoice identity');
check($main->getCell('I6')->getDataType() === 's', 'First-sheet information is not a formula');
check($main->getCell('G6')->getDataType() === 'n', 'First-sheet amount numeric');
check(abs($main->getCell('G8')->getValue() - 181000) < 0.001, 'First-sheet reserve total');
echo "Preorder XLSX round-trip passed (main and detail sheets)\n";
