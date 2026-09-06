<?php
if (PHP_SAPI !== 'cli') exit;
define('ABSPATH', __DIR__);
function add_action(...$args) {}
function __($s, $d = '') { return $s; }
function sanitize_text_field($s) { return (string)$s; }
function sanitize_key($s) { return strtolower((string)$s); }
function absint($n) { return abs((int)$n); }
function rest_sanitize_boolean($v) { return (bool)$v; }
function wp_json_encode($v, $f = 0) { return json_encode($v, $f); }
function wp_date($f) { return date($f); }
function current_time($type) { return time(); }
function wp_send_json_error($data, $status = 400) { throw new InvalidArgumentException($data['message']); }
function lavka_get_transit_warehouse_ids() { return $GLOBALS['ids']; }
function lps_get_options() { return ['java_base_url' => $GLOBALS['server'] ?? 'fixture']; }
class WP_Error {
    function __construct(public $code, public $message, public $data = []) {}
    function get_error_message() { return $this->message; }
}
function is_wp_error($v) { return $v instanceof WP_Error; }
function wp_remote_retrieve_response_code($r) { return $r['http'] ?? 200; }
function wp_remote_retrieve_body($r) { return json_encode($r); }
function lps_java_post($path, $payload, $options) {
    $GLOBALS['requests'][] = [$path, $payload];
    if ($path === LPS_PRODUCT_ANALYTICS_CAPABILITIES_PATH) {
        if (empty($GLOBALS['modern'])) return ['ok' => true];
        $config = $payload['calculation']['transit'] ?? ['warehouseIds' => [9], 'configurationRevision' => hash('sha256', '[9]')];
        return ['ok' => true, 'features' => ['configurableTransit' => ['supported' => true]],
            'transit' => $config + ['configurable' => true, 'calculationVersion' => 3,
                'sources' => array_values(array_filter($GLOBALS['sources'], fn($s) => in_array($s['warehouseId'], $config['warehouseIds'], true)))]];
    }
    return array_shift($GLOBALS['responses']);
}
require __DIR__ . '/../inc/purchase-planning-model.php';
require __DIR__ . '/../inc/product-availability.php';
require __DIR__ . '/../inc/product-analytics.php';
require __DIR__ . '/../inc/product-analytics-export.php';
function check($ok, $message) { if (!$ok) throw new RuntimeException($message); }
$ids = [9, 10];
$sources = array_map(fn($id, $quantity) => ['warehouseId' => $id, 'warehouseName' => 'Transport ' . $id, 'generationId' => $id + 100,
    'status' => 'AVAILABLE_PHYSICAL_STOCK', 'availableForNetworkPlanningQuantity' => $quantity,
    'supplierOriginStatus' => 'MIXED_ORIGIN', 'supplierOriginConfirmed' => false, 'supplierInTransitAvailableQuantity' => null,
    'physicalQuantity' => $quantity, 'reservedQuantity' => 0, 'availableQuantity' => $quantity], [9, 10], [12, 8]);
$consistency = ['status' => 'CONFIRMED', 'confirmed' => true, 'salesSources' => [], 'transitSources' => []];
$transit = lps_transit_configuration() + ['calculationVersion' => 3, 'enabled' => true, 'ready' => true,
    'networkPlanningReady' => true, 'networkPlanningStatus' => 'AVAILABLE_PHYSICAL_STOCK',
    'networkSnapshotConsistency' => $consistency, 'availableForNetworkPlanningQuantity' => 20,
    'supplierInTransitAvailableQuantity' => null, 'sources' => $sources];
check(lps_purchase_transit($transit, $ids)['quantity'] === 20.0, '12 + 8 physical network stock = 20 despite mixed supplier origin');
$bad = $transit; $bad['sources'][1]['status'] = 'INCONSISTENT_TRANSIT_BALANCE';
check(lps_purchase_transit($bad, $ids)['quantity'] === null, 'Do not trust aggregate when a physical source is invalid');
$bad['networkPlanningReady'] = false; $bad['availableForNetworkPlanningQuantity'] = null;
check(lps_purchase_transit($bad, $ids)['quantity'] === null, 'Diagnostic source subtotal is not certified network stock');
$bad = $transit; $bad['sources'][] = $sources[0];
check(lps_purchase_transit($bad, $ids)['quantity'] === null, 'Reject duplicate sources');
$bad = $transit; $bad['configurationRevision'] = 'wrong';
check(lps_purchase_transit($bad, $ids)['issue'] === 'TRANSIT_SOURCE_MISMATCH', 'Validate configuration hash');
$bad = $transit; $bad['networkPlanningReady'] = false; $bad['networkPlanningStatus'] = 'TRANSIT_SCOPE_OVERLAP'; $bad['availableForNetworkPlanningQuantity'] = null;
check(lps_purchase_transit($bad, $ids)['quantity'] === null, 'Scope overlap is never deducted');
$bad = $transit; $bad['networkPlanningReady'] = false; $bad['networkPlanningStatus'] = 'NETWORK_SNAPSHOT_CONSISTENCY_UNCONFIRMED';
$bad['networkSnapshotConsistency'] = ['status' => 'NETWORK_SNAPSHOT_CONSISTENCY_UNCONFIRMED', 'confirmed' => false]; $bad['availableForNetworkPlanningQuantity'] = null;
check(lps_purchase_transit($bad, $ids)['quantity'] === null, 'Independent snapshots block the automatic deduction');
$empty = ['calculationVersion' => 3, 'warehouseIds' => [], 'configurationRevision' => hash('sha256', '[]'), 'enabled' => false, 'status' => 'DISABLED', 'availableForNetworkPlanningQuantity' => 0];
check(lps_purchase_transit($empty, [])['quantity'] === 0.0, 'Explicit disabled configuration');
$group = ['code' => 'kyiv', 'name' => 'Kyiv', 'warehouseIds' => [1], 'receivingWarehouseId' => 1, 'leadTimeDays' => 0, 'targetDays' => 30, 'safetyDays' => 0];
$row = ['sku' => 'TEST', 'inTransitStock' => $transit, 'dimensions' => ['currentSuppliers' => ['Kreul'], 'packageQuantity' => 10, 'minimumOrderQuantity' => 10],
    'metrics' => ['grossProfit' => 50], 'networkOrderPolicy' => ['status' => 'ALLOWED', 'orderAllowed' => true],
    'warehouseBreakdown' => [['warehouseId' => 1, 'metrics' => ['physicalQuantity' => 30, 'availableQuantity' => 30, 'regularSoldUnits' => 90, 'returnQuantity' => 0],
        'orderPolicy' => ['orderAllowed' => true, 'reserveAboveForecast' => 0, 'maximumStockLimited' => false]]]];
$a = lps_purchase_calculate($row, [$group], 30, false, ['kyiv' => ['openOrders' => 0]]);
check($a['groups'][0]['finalQuantity'] === null, 'Manual zero does not confirm deduplication');
$a = lps_purchase_calculate($row, [$group], 30, false, ['kyiv' => ['openOrders' => 0, 'receiptsReviewed' => true]]);
check($a['groups'][0]['finalQuantity'] === 40.0 && $a['groups'][0]['target'] === 90.0, 'Reviewed transit reduces purchase, not forecast');
check($a['transitConsistency']['confirmed'] === true && $a['supplierTransitQuantity'] === null, 'Preview exposes consistency and supplier subset separately');
$blocked_row = $row; $blocked_row['inTransitStock'] = $bad;
$blocked = lps_purchase_calculate($blocked_row, [$group], 30, false, ['kyiv' => ['openOrders' => 0, 'receiptsReviewed' => true]]);
check($blocked['groups'][0]['finalQuantity'] === null && in_array('NETWORK_TRANSIT_NOT_READY', $blocked['groups'][0]['issues'], true), 'Current independent snapshots block purchase even after manager review');
$query = ['sourceDatabase' => 'Paint_Ua', 'warehouseIds' => [1], 'period' => ['from' => '2026-08-01', 'to' => '2026-08-30'], 'page' => ['size' => 50]];
$frozen = lps_product_analytics_v4_sanitize_query($query + ['calculation' => ['transit' => ['warehouseIds' => [99]]]]);
check($frozen['calculation']['transit'] === lps_transit_configuration(), 'Browser cannot override global sources');
$modern = false; $server = 'old';
$responses = [['ok' => true, 'rows' => [['sku' => 'TEST', 'inTransitStock' => $sources[0]]]]];
$body = lps_product_analytics_v4_request_java(LPS_PRODUCT_ANALYTICS_QUERY_PATH, $frozen);
foreach ($requests as [$path, $request]) check(!isset($request['calculation']['transit']), 'No new field before capability confirmation');
check($body['rows'][0]['inTransitStock']['availableForPlanningQuantity'] === null, 'Legacy must not masquerade as full multi-transit');
$modern = true; $server = 'new'; $requests = [];
$context = ['transit' => $transit];
$response = ['ok' => true, 'context' => $context, 'rows' => [$row], 'totals' => ['productCount' => 1], 'nextCursor' => null];
$responses = [$response];
$body = lps_product_analytics_v4_request_java(LPS_PRODUCT_ANALYTICS_QUERY_PATH, $frozen);
check(!is_wp_error($body), 'Modern response accepted');
check(!isset($requests[0][1]['calculation']['transit']) && $requests[1][1]['calculation']['transit'] === lps_transit_configuration(), 'Probe then configurable query');
$cap = lps_product_analytics_v4_request_java(LPS_PRODUCT_ANALYTICS_CAPABILITIES_PATH, $frozen);
check($cap['transit']['warehouseIds'] === $ids, 'Same configuration applied to capabilities');
$responses = [$response]; $responses[0]['rows'][0]['inTransitStock']['sources'][1]['generationId']++;
check(is_wp_error(lps_product_analytics_v4_request_java(LPS_PRODUCT_ANALYTICS_QUERY_PATH, $frozen)), 'Row source must match context generation');
$responses = [$response];
$export = lps_product_analytics_export_fetch_rows($frozen, 'products');
check(!is_wp_error($export) && $export['rows'][0]['transitQuantity'] === 20.0, 'Export includes confirmed total');
check(count(json_decode($export['rows'][0]['transitDetails'], true)['sources']) === 2, 'Export retains both source diagnostics');
$first = $response; $first['nextCursor'] = 'next'; $first['totals']['productCount'] = 2;
$second = $first; $second['nextCursor'] = null; $second['rows'][0]['sku'] = 'NEXT';
$second['rows'][0]['inTransitStock']['sources'][1]['generationId']++;
$second['context']['transit']['sources'][1]['generationId']++;
$responses = [$first, $second];
check(is_wp_error(lps_product_analytics_export_fetch_rows($frozen, 'products')), 'Export rejects second transit generation change');
$ids = [9];
check(is_wp_error(lps_product_analytics_v4_request_java(LPS_PRODUCT_ANALYTICS_QUERY_PATH, $frozen)), 'Global setting drift invalidates frozen query');
$ids = [];
$disabled = lps_transit_configuration() + ['calculationVersion' => 3, 'enabled' => false, 'ready' => true, 'status' => 'DISABLED', 'sources' => [], 'availableForNetworkPlanningQuantity' => 0];
$response['context']['transit'] = $disabled;
$response['rows'][0]['inTransitStock'] = $disabled;
$responses = [$response];
$body = lps_product_analytics_v4_request_java(LPS_PRODUCT_ANALYTICS_QUERY_PATH, $query);
check(!is_wp_error($body) && end($requests)[1]['calculation']['transit']['warehouseIds'] === [], 'Send explicit empty list, never fall back to 9');
$ids = range(1, 17);
check(is_wp_error(lps_product_analytics_v4_request_java(LPS_PRODUCT_ANALYTICS_QUERY_PATH, $query)), 'Enforce 16-source limit before HTTP');
echo "PASS: v3 physical/supplier split, consistency/disabled/overlap, manager review, capability gate, export and generation drift\n";
