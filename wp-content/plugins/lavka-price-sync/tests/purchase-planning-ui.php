<?php
// CLI-only visual fixture: no WordPress bootstrap and no Folio connection.
if (PHP_SAPI !== 'cli') exit;
define('ABSPATH', __DIR__); define('LPS_CAP', 'manage_options'); define('LPS_ANALYTICS_SCENARIOS_PAGE', 'scenarios');
function add_action(...$args) {}
function __($s, $d = '') { return $s; }
function esc_html($s) { return htmlspecialchars((string)$s); }
function esc_attr($s) { return esc_html($s); }
function esc_url($s) { return esc_html($s); }
function esc_html__($s, $d = '') { return __($s, $d); }
function current_user_can($s) { return true; }
function admin_url($s) { return $s; }
function sanitize_text_field($s) { return strip_tags((string)$s); }
function lps_analytics_scenarios_list() { return [['id'=>1,'name'=>'Kreul preview','version'=>2,'status'=>'active','profile'=>['purchasePlanning'=>['enabled'=>true]]]]; }
$base = dirname(__DIR__);
require $base . '/inc/purchase-planning-model.php';
require $base . '/inc/purchase-planning.php';
$groups = [['code'=>'kyiv','name'=>'Kyiv','warehouseIds'=>[1],'receivingWarehouseId'=>1,'leadTimeDays'=>0,'targetDays'=>30,'safetyDays'=>0,'respectPack'=>true]];
$row = ['sku'=>'KR-17817','productName'=>'Acrylic marker','dimensions'=>['currentSuppliers'=>['Kreul'],'packageQuantity'=>6,'minimumOrderQuantity'=>0],
    'metrics'=>['grossProfit'=>100],'networkOrderPolicy'=>['orderAllowed'=>true,'status'=>'ALLOWED'],
    'warehouseBreakdown'=>[['warehouseId'=>1,'metrics'=>['physicalQuantity'=>6,'availableQuantity'=>6,'regularSoldUnits'=>11,'returnQuantity'=>0],
        'orderPolicy'=>['orderAllowed'=>true,'reserveAboveForecast'=>0,'maximumStockLimited'=>false]]]];
if (($argv[1] ?? '') === 'calculate') {
    $edits = json_decode($argv[2] ?? '{}', true);
    echo json_encode(lps_purchase_calculate($row, $groups, 30, false, $edits ?: ['kyiv'=>['openOrders'=>0]], [])); exit;
}
echo '<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><style>body{font:14px system-ui;background:#f0f0f1;color:#1d2327;margin:20px}input,select,button{font:inherit;padding:6px}table{background:white;border-collapse:collapse}td,th{padding:10px;text-align:left}details{background:white;margin:8px 0;padding:8px}button{cursor:pointer}';
echo file_get_contents($base . '/assets/purchase-planning.css'); echo '</style>';
lps_purchase_render();
echo '<script>window.LPS_PURCHASE=' . json_encode(['ajaxUrl'=>'https://fixture.local/api','nonce'=>'fixture','locale'=>'en','i18n'=>lps_purchase_i18n()]) . ';</script><script>';
echo file_get_contents($base . '/assets/purchase-planning.js'); echo '</script></html>';
