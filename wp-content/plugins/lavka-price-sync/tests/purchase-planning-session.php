<?php
if (PHP_SAPI !== 'cli') exit;
define('ABSPATH', __DIR__); define('HOUR_IN_SECONDS', 3600); define('LPS_CAP', 'manage_options');
define('LPS_PRODUCT_ANALYTICS_QUERY_PATH', '/analytics/query');
function add_action($name, $callback, ...$args) { $GLOBALS['actions'][$name] = $callback; }
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
function lavka_get_transit_warehouse_ids() { return $GLOBALS['transitIds'] ?? [9]; }
function lavka_get_global_warehouse_groups() { return [['code' => 'group', 'name' => 'Group', 'warehouseIds' => [1, 7]]]; }
function lps_product_analytics_v4_sanitize_query($query) { return $query; }
function lps_product_analytics_v4_request_java($path, $query) {
    if ($path === LPS_ASSEMBLY_PATH) return $GLOBALS['graph'] ?? ['version'=>1,'revision'=>'fixture','nodes'=>array_map(static fn($sku)=>['sku'=>$sku,'manufactured'=>false,'issues'=>[]],$query['rootSkus']),'edges'=>[]];
    $GLOBALS['queries'][] = $query; return $GLOBALS['response'];
}
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
check(lps_purchase_session($token)['query']['calculation']['availability']['enabled'] === true, 'Days of stockout are requested even with correction disabled');
$user = 2;
rejected(fn() => lps_purchase_session($token), 'Preview token must be user-bound');
$user = 1;
rejected(fn() => lps_purchase_start(1, 1), 'Reject stale scenario version');
$transit = ['warehouseId' => 9, 'generationId' => 99, 'status' => 'NO_IN_TRANSIT_STOCK'];
$response = ['ok' => true, 'rows' => [['sku' => 'ONE', 'inTransitStock' => $transit]], 'context' => ['analyticsSchemaVersion' => 7,
    'periodFrom' => '2026-08-01', 'periodTo' => '2026-08-30', 'warehouses' => [['id' => 1, 'generationId' => 10], ['id' => 7, 'generationId' => 11]]],
    'totals' => ['productCount' => 2], 'errors' => [], 'nextCursor' => 'page-two'];
$response['context']['analyticsSchemaVersion'] = 5;
rejected(fn() => lps_purchase_page($token, 0), 'Old free-stock interpretation is rejected');
$response['context']['analyticsSchemaVersion'] = 7;
$response['rows'][0]['internalTransferReservations']=['calculationVersion'=>1,'status'=>'CAPTURED','accounts'=>[
    ['sourceWarehouseId'=>7,'generationId'=>999,'documentId'=>123,'quantity'=>6]]];
rejected(fn() => lps_purchase_page($token, 0), 'Reservation generation must match the stock snapshot');
$response['rows'][0]['internalTransferReservations']['accounts']=[];
$first = lps_purchase_page($token, 0);
check(!$first['complete'] && $first['loaded'] === 1, 'Partial pagination stays incomplete');
check($queries[0]['productFilters'] === $scenario['profile']['productFilters'], 'Keep supplier filter on query');
check($queries[0]['movementFilters'] === $scenario['profile']['movementFilters'], 'Keep operation filter on query');
rejected(fn() => lps_purchase_adjust($token, 'ONE', []), 'Reject editing incomplete preview');
rejected(fn() => lps_purchase_page($token, 0), 'Reject duplicate page');
$response['context']['warehouses'][0]['generationId'] = 11;
rejected(fn() => lps_purchase_page($token, 1), 'Reject generation drift');
$response['context']['warehouses'][0]['generationId'] = 10;
$response['rows'] = [['sku' => 'TWO', 'inTransitStock' => $transit]]; $response['nextCursor'] = null;
$response['rows'][0]['inTransitStock']['generationId'] = 100;
rejected(fn() => lps_purchase_page($token, 1), 'Reject transit source generation drift');
$response['rows'][0]['inTransitStock']['generationId'] = 99;
$last = lps_purchase_page($token, 1);
check($last['complete'] && $last['loaded'] === 2, 'Only accept complete unique SKU set');
$transitIds = [9, 10];
rejected(fn() => lps_purchase_session($token), 'Transport configuration change invalidates adjustment and export');
$transitIds = [];
rejected(fn() => lps_purchase_session($token), 'Disabling transit also invalidates preview');
$transitIds = [9];
$revision = 'revision-2';
rejected(fn() => lps_purchase_session($token), 'Reject changed group revision');
$revision = 'revision-1';
$scenario['version']++;
rejected(fn() => lps_purchase_session($token), 'Reject changed scenario after completion');
echo "PASS: user isolation, scenario versions, immutable filters, pagination completeness and generation/group drift\n";

$scenario['profile']['purchasePlanning']['packRounding'] = 'DOWN';
$scenario['profile']['purchasePlanning']['stockoutCorrectionEnabled'] = true;
$scenario['profile']['purchasePlanning']['maxDemandMultiplier'] = 1.1;
$scenario['profile']['calculation']['availability'] = ['enabled' => false, 'groupCode' => 'group', 'filter' => ['availabilityStatus' => ['MEASURED']]];
$start = lps_purchase_start(1, $scenario['version']);
check($start['groups'][0]['packRounding'] === 'DOWN' && $start['groups'][0]['maxDemandMultiplier'] === 1.1, 'Preview freezes scenario rounding and cap');
check($start['query']['calculation']['availability']['enabled'] === true
    && $start['query']['calculation']['availability']['groupCode'] === 'group'
    && $start['query']['calculation']['availability']['filter'] === ['availabilityStatus' => ['MEASURED']], 'Correction enables history without erasing availability filters');
$key = lps_purchase_session_key($start['token']);
$sessions[$key]['previewVersion'] = 5;
rejected(fn() => lps_purchase_session($start['token']), 'Old calculation version requires a fresh preview');
echo "PASS: scenario demand/rounding snapshot, history request and old-preview invalidation\n";

$graph = ['version'=>1,'revision'=>'fixture-with-child','nodes'=>[
    ['sku'=>'PARENT','manufactured'=>false,'issues'=>[]], ['sku'=>'CHILD','manufactured'=>true,'issues'=>[]]],
    'edges'=>[['parent'=>'PARENT','child'=>'CHILD','factor'=>0.25,'source'=>'ALL_RAZBORKA_SLOJ','rowId'=>'1']]];
$token = lps_purchase_start(1, $scenario['version'])['token'];
$response['rows'] = [['sku'=>'PARENT','inTransitStock'=>$transit]]; $response['totals']['productCount']=1;
$page=lps_purchase_page($token,0);
check(!$page['complete'] && $page['total']===2, 'Finding unselected children extends loading');
$response['context']['warehouses'][0]['generationId']=99;
$response['rows'] = [['sku'=>'CHILD','inTransitStock'=>$transit]];
rejected(fn()=>lps_purchase_page($token,1),'Dependent analytics must use the same snapshot generations');
$response['context']['warehouses'][0]['generationId']=10;
$page=lps_purchase_page($token,1);
$query=end($queries);
check($query['productFilters']===['skus'=>['mode'=>'INCLUDE','values'=>['CHILD']]],'Child query clears supplier and all other product filters');
check(!isset($query['calculation']['availability']['filter']),'Availability must not hide a consuming child');
check($page['complete'] && $page['replaceItems'] && count($page['items'])===2,'Complete network replaces provisional rows');
check(count(lps_purchase_adjust($token,'CHILD',[])['items'])===2,'Editing child recalculates the network');
$token = lps_purchase_start(1, $scenario['version'])['token'];
$response['rows'] = [['sku'=>'PARENT'],['sku'=>'CHILD']]; $response['totals']['productCount']=2;
$page=lps_purchase_page($token,0);
check($page['complete'] && count($page['items'])===2,'Already selected child is forecast once');
$scenario['profile']['productFilters']['currentSuppliers']['values'][]='Other';
rejected(fn()=>lps_purchase_start(1,$scenario['version']),'A supplier order requires exactly one supplier');
echo "PASS: descendant discovery, independent supplier scope, generation checks, recalculation and deduplication\n";

function current_user_can($cap) { return true; }
function check_admin_referer($nonce) {}
function wp_unslash($value) { return $value; }
function esc_html($value) { return $value; }
function wp_die($message) { throw new RuntimeException($message); }
function lps_product_analytics_export_metadata($query,$extra) { return []; }
function lps_product_analytics_export_csv($columns,$rows,$filename) { $GLOBALS['exported']=$rows; }
array_pop($scenario['profile']['productFilters']['currentSuppliers']['values']);
$graph['nodes'][]=['sku'=>'FOREIGN','manufactured'=>false,'issues'=>[]];
$token=lps_purchase_start(1,$scenario['version'])['token'];
$response['rows']=[['sku'=>'PARENT','dimensions'=>['currentSuppliers'=>['Kreul']]],
    ['sku'=>'CHILD','dimensions'=>['currentSuppliers'=>['Kreul']]], ['sku'=>'FOREIGN','dimensions'=>['currentSuppliers'=>['Other']]]];
$response['totals']['productCount']=3;
$response['rows'][0]['internalTransferReservations']=['calculationVersion'=>1,'status'=>'CAPTURED','accounts'=>[]];
$response['rows'][0]['warehouseBreakdown']=array_map(static fn($id)=>['warehouseId'=>$id,
    'metrics'=>['physicalQuantity'=>20,'availableQuantity'=>20,'regularSoldUnits'=>5,'expenseQuantity'=>10,'returnQuantity'=>0],
    'orderPolicy'=>['orderAllowed'=>true,'reserveAboveForecast'=>0,'maximumStockLimited'=>false]], [1,7]);
lps_purchase_page($token,0);
$_POST=['token'=>$token,'format'=>'csv'];
$actions['admin_post_lps_purchase_export']();
check(array_column($exported,'sku')===['PARENT'],'Actual export excludes selected children and foreign-supplier roots');
check($exported[0]['periodExpense']===20.0 && $exported[0]['allWarehousesPhysical']===40.0 && $exported[0]['all_stockMonths']===4.0,'Export contains actual period expense, combined stock and months');
$graph['revision']='changed';
rejected(fn()=>$actions['admin_post_lps_purchase_export'](),'Changed recipes block actual export until recalculation');
echo "PASS: actual CSV export supplier isolation and recipe revision guard\n";
