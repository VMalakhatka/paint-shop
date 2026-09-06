<?php
if (PHP_SAPI !== 'cli') exit;
define('ABSPATH', __DIR__); define('HOUR_IN_SECONDS', 3600); define('LPS_CAP', 'manage_options');
define('LPS_PRODUCT_ANALYTICS_QUERY_PATH', '/analytics/query');
function add_action(...$args) {}
function __($s, $d = '') { return $s; }
function sanitize_key($s) { return strtolower((string)$s); }
function sanitize_text_field($s) { return (string)$s; }
function absint($n) { return abs((int)$n); }
function get_current_user_id() { return $GLOBALS['user'] ?? 1; }
function get_transient($key) { return $GLOBALS['sessions'][$key] ?? false; }
function set_transient($key, $value, $ttl) { $GLOBALS['sessions'][$key] = $value; }
function wp_json_encode($value, $flags = 0) { return json_encode($value, $flags); }
function wp_date($format) { return date($format); }
function lps_analytics_scenario_row($id) { return $id === 1 ? $GLOBALS['scenario'] : null; }
function lps_analytics_scenario_decode_row($row) { return $row; }
function lavka_get_global_warehouse_groups_revision() { return $GLOBALS['revision'] ?? 'revision-1'; }
function lavka_get_global_warehouse_groups() { return [['code' => 'group', 'name' => 'Group', 'warehouseIds' => [1, 7]]]; }
function lps_product_analytics_v4_sanitize_query($query) { return $query; }
function lps_product_analytics_v4_request_java($path, $query) { $GLOBALS['queries'][] = $query; return $GLOBALS['response']; }
function is_wp_error($value) { return false; }
require __DIR__ . '/../inc/purchase-planning-model.php';
require __DIR__ . '/../inc/purchase-planning.php';
function check($condition, $message) { if (!$condition) throw new RuntimeException($message); }
function rejected(callable $call, string $message): void {
    $rejected = false;
    try { $call(); } catch (InvalidArgumentException | RuntimeException $expected) { $rejected = true; }
    check($rejected, $message);
}
$scenario = ['id' => 1, 'uuid' => 'scenario-1', 'version' => 2, 'status' => 'active', 'schemaVersion' => 4, 'name' => 'Test', 'profile' => [
    'context' => ['sourceDatabase' => 'Paint_Ua', 'warehouseIds' => [1, 7]], 'period' => ['from' => '2026-08-01', 'to' => '2026-08-30'],
    'productFilters' => ['currentSuppliers' => ['mode' => 'INCLUDE', 'values' => ['Kreul']]],
    'movementFilters' => ['operationKinds' => ['mode' => 'EXCLUDE', 'values' => ['TRANSFER']]],
    'calculation' => ['includeReturns' => true], 'purchasePlanning' => ['enabled' => true, 'allowTransfers' => false, 'groups' => [
        ['code' => 'group', 'receivingWarehouseId' => 7, 'leadTimeDays' => 5, 'targetDays' => 20, 'safetyDays' => 5]]]]];
$token = lps_purchase_start(1, 2)['token'];
$user = 2;
rejected(fn() => lps_purchase_session($token), 'Preview token must be user-bound');
$user = 1;
rejected(fn() => lps_purchase_start(1, 1), 'Reject stale scenario version');
$response = ['ok' => true, 'rows' => [['sku' => 'ONE']], 'context' => ['analyticsSchemaVersion' => 4,
    'periodFrom' => '2026-08-01', 'periodTo' => '2026-08-30', 'warehouses' => [['id' => 1, 'generationId' => 10], ['id' => 7, 'generationId' => 11]]],
    'totals' => ['productCount' => 2], 'errors' => [], 'nextCursor' => 'page-two'];
$first = lps_purchase_page($token, 0);
check(!$first['complete'] && $first['loaded'] === 1, 'Partial pagination stays incomplete');
check($queries[0]['productFilters'] === $scenario['profile']['productFilters'], 'Keep supplier filter on query');
check($queries[0]['movementFilters'] === $scenario['profile']['movementFilters'], 'Keep operation filter on query');
rejected(fn() => lps_purchase_adjust($token, 'ONE', []), 'Reject editing incomplete preview');
rejected(fn() => lps_purchase_page($token, 0), 'Reject duplicate page');
$response['context']['warehouses'][0]['generationId'] = 11;
rejected(fn() => lps_purchase_page($token, 1), 'Reject generation drift');
$response['context']['warehouses'][0]['generationId'] = 10;
$response['rows'] = [['sku' => 'TWO']]; $response['nextCursor'] = null;
$last = lps_purchase_page($token, 1);
check($last['complete'] && $last['loaded'] === 2, 'Only accept complete unique SKU set');
$revision = 'revision-2';
rejected(fn() => lps_purchase_session($token), 'Reject changed group revision');
$revision = 'revision-1';
$scenario['version']++;
rejected(fn() => lps_purchase_session($token), 'Reject changed scenario after completion');
echo "PASS: user isolation, scenario versions, immutable filters, pagination completeness and generation/group drift\n";
