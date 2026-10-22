<?php
/** No WordPress database or Folio writes. */
define('ABSPATH', __DIR__);
function current_time($format) { return '2026-10-16'; }
function __($s, $d = '') { return $s; }
function esc_html__($s, $d = '') { return htmlspecialchars($s); }
function esc_html($s) { return htmlspecialchars($s); }
function selected($a, $b, $echo = true) { return $a === $b ? ' selected="selected"' : ''; }
function wp_json_encode($v) { return json_encode($v); }
function wc_format_decimal($v, $dp = 2) { return number_format($v, $dp, '.', ''); }
class WC_Order {
    public array $meta = [];
    function get_meta($key) { return $this->meta[$key] ?? ''; }
    function update_meta_data($key, $value) { $this->meta[$key] = $value; }
    function get_id() { return 123; }
    function get_items($type = '') { return []; }
    function get_status() { return 'pc-draft'; }
}
require __DIR__ . '/../inc/DraftPurpose.php';
require __DIR__ . '/../inc/DraftFolioWorkflow.php';
use PaintCore\PCOE\DraftPurpose as Purpose;
use PaintCore\PCOE\DraftFolioWorkflow as Workflow;
function check($ok, $message) { if (!$ok) throw new RuntimeException($message); }
$order = new WC_Order();
$header = ['additionalInfo' => 'Int 123 date', 'operationType' => 'СЧЕТ', 'warehouseId' => 7, 'documentDate'=>'2025-01-01T00:00:00'];
$draft = Purpose::header($header, $order);
check($draft['additionalInfo'] === 'Черновик Int 123' && !$draft['accountingEnabled'], 'Default must be an ordinary non-accounting draft');
$fingerprint = new ReflectionMethod(Workflow::class, 'fingerprint');
$lines = new ReflectionMethod(Workflow::class, 'order_lines_fingerprint');
$before = $fingerprint->invoke(null, $order, ['rows'=>[]], 7);
$beforeLines = $lines->invoke(null, $order);
Purpose::store($order, ['draft_purpose'=>'preorder']);
$preorder = Purpose::header($header, $order);
check($preorder['additionalInfo'] === 'ПРД Int 123' && $preorder['folioOperationKind'] === '*РАЗОВАЯ' && !$preorder['accountingEnabled'], 'Preorder must satisfy the Folio reserve classification');
check($preorder['documentDate'] === '2026-10-16T00:00:00', 'New preorder must not inherit an expired draft date');
check($preorder['operationType'] === 'СЧЕТ' && $preorder['warehouseId'] === 7, 'Keep document type and configured warehouse');
check($before !== $fingerprint->invoke(null, $order, ['rows'=>[]], 7) && $beforeLines !== $lines->invoke(null, $order), 'Changing intent must invalidate both preview guards');
$shortage = Purpose::header($header, $order, true);
check($shortage['additionalInfo'] === 'Нет на складе Int 123' && $shortage['sourceInfo'] === 'нет на складе', 'Actual shortage overrides preorder selection');
Purpose::store($order, []);
check(Purpose::current($order) === 'preorder', 'Missing selection preserves saved intent');
Purpose::store($order, ['draft_purpose'=>['preorder']]);
check(Purpose::current($order) === 'draft', 'Malformed input cannot create a preorder');
ob_start(); Purpose::field('preorder'); $html=ob_get_clean();
check(str_contains($html, 'value="preorder" selected="selected"') && str_contains($html, 'value="draft"'), 'Visible saved choice and both options');
echo "PASS: manual draft, preorder, real shortage, preview invalidation and selector\n";
function pc_folio_build_order_preview_payload($order) {
    return ['items'=>[['order_item_id'=>5,'sku'=>'EXAMPLE','unit_price'=>12]], 'woo_order'=>[], 'folio_account_header'=>['operationType'=>'СЧЕТ','folioOperationKind'=>'*ПРЕДОПЛАТ']];
}
$build = new ReflectionMethod(Workflow::class, 'build_payload');
Purpose::store($order, ['draft_purpose'=>'preorder']);
foreach (['whole_draft'=>'ПРД Int 123', 'partial_to_cart'=>'Нет на складе Int 123'] as $mode=>$info) {
    $payload = $build->invoke(null, $order, ['mode'=>$mode,'rows'=>[5=>['unavailable'=>3]]], 7);
    check($payload['folio_account_header']['additionalInfo'] === $info, 'Workflow must distinguish manual document from calculated shortage');
    check($payload['items'][0]['quantity'] === 3.0 && $payload['woo_order']['total'] === 36.0 && $payload['folio_account_header']['warehouseId'] === 7, 'Keep selected quantities, prices and warehouse');
}
echo "PASS: customer workflow payloads for manual preorder and automatic shortage\n";
