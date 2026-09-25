<?php
// Standalone regression: php .../tests/customer-debtors-export.php
define('ABSPATH', __DIR__);
function add_action(...$args) {}
function __($text, $domain = '') { return $text; }
class WP_Error { public function __construct(public $code, public $message) {} }
function is_wp_error($value) { return $value instanceof WP_Error; }
function pc_folio_balance_export_date($value) { return (string) $value; }
require dirname(__DIR__, 3) . '/vendor/autoload.php';
require dirname(__DIR__) . '/inc/customer-debtors-export.php';
function check($value, $message) { if (!$value) throw new RuntimeException($message); }
$rows = [];
for ($i = 0; $i < 201; $i++) $rows[] = ['partner' => ['name' => '=1+1', 'shortName' => 'C' . $i, 'type' => 'Д'], 'commonDebt' => 12.34, 'payableNow' => 12.34, 'siteUsers' => [['displayName' => 'Test']]];
$filters = ['minPayable' => '10.00', 'q' => '', 'types' => ['Д']];
$base = ['asOfDate' => '2026-09-25', 'filters' => $filters, 'summary' => ['matchedClients' => 201, 'commonDebtTotal' => 2480.34, 'payableNowTotal' => 2480.34]];
$calls = [];
$fetch = function ($f) use ($rows, $base, &$calls) { $calls[] = $f; $r = $base; $r['debtors'] = array_slice($rows, $f['offset'], $f['limit']); $r['summary']['returnedClients'] = count($r['debtors']); return $r; };
$report = pc_folio_debtors_export_report($filters, $fetch);
check(!is_wp_error($report) && count($report['debtors']) === 201, 'All pages exported');
check(array_column($calls, 'offset') === [0, 200] && $calls[1]['minPayable'] === '10.00', 'Filters retained');
$book = pc_folio_debtors_export_workbook($report);
$file = tempnam(sys_get_temp_dir(), 'debtors-test-');
(new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($book))->save($file);
$read = \PhpOffice\PhpSpreadsheet\IOFactory::load($file)->getActiveSheet();
unlink($file);
check($read->getCell('A8')->getDataType() === 's', 'Untrusted text is not formula');
check($read->getCell('H8')->getDataType() === 'n', 'Amounts are numbers');
check(abs($read->getCell('H209')->getCalculatedValue() - 2480.34) < 0.001, 'Total includes all pages');
$bad = pc_folio_debtors_export_report($filters, function ($f) use ($fetch) { $r = $fetch($f); if ($f['offset']) $r['summary']['matchedClients']++; return $r; });
check(is_wp_error($bad), 'Changing dataset rejected');
$bad = pc_folio_debtors_export_report($filters, function ($f) use ($fetch) { $r = $fetch($f); if ($f['offset']) $r['debtors'] = []; return $r; });
check(is_wp_error($bad), 'Truncation rejected');
$bad = pc_folio_debtors_export_report($filters, fn($f) => new WP_Error('offline', 'offline'));
check(is_wp_error($bad), 'Upstream errors retained');
echo "Export pagination and XLSX round-trip passed\n";

$empty = pc_folio_debtors_export_report($filters, fn($f) => ['asOfDate' => '2026-09-25', 'filters' => $filters, 'summary' => ['matchedClients' => 0], 'debtors' => []]);
check(!is_wp_error($empty), 'Empty report supported');
check(pc_folio_debtors_export_workbook($empty)->getActiveSheet()->getCell('H8')->getValue() === 0, 'Empty total is zero');
function pc_folio_debtors_can_view() { return false; }
function esc_html__($s, $d) { return $s; }
function wp_die($message, $title = '', $args = []) { throw new RuntimeException((string) ($args['response'] ?? 0)); }
try { pc_folio_debtors_export_xlsx(); throw new RuntimeException('Access unexpectedly granted'); }
catch (RuntimeException $e) { check($e->getMessage() === '403', 'Unauthorized download rejected before reading data'); }
