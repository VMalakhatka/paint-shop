<?php
// CLI-only, deterministic fixtures. No WordPress bootstrap or Folio access.
if (PHP_SAPI !== 'cli') exit;
define('ABSPATH', __DIR__);
function sanitize_key($value) { return strtolower((string)$value); }
function sanitize_text_field($value) { return strip_tags((string)$value); }
function absint($value) { return abs((int)$value); }
function rest_sanitize_boolean($value) { return filter_var($value, FILTER_VALIDATE_BOOLEAN); }
function __($value, $domain = '') { return $value; }
function add_action(...$args) {}
require __DIR__ . '/../inc/purchase-planning-model.php';
require __DIR__ . '/../inc/analytics-scenarios.php';
function check($condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
function member(int $id, float $stock, float $sales): array {
    return ['warehouseId' => $id, 'metrics' => ['physicalQuantity' => $stock, 'availableQuantity' => $stock, 'regularSoldUnits' => $sales, 'returnQuantity' => 2],
        'orderPolicy' => ['orderAllowed' => true, 'reserveAboveForecast' => 0, 'maximumStockLimited' => false]];
}
$configured = [
    ['code' => 'kyiv', 'name' => 'Kyiv', 'warehouseIds' => [1, 7]],
    ['code' => 'odesa', 'name' => 'Odesa', 'warehouseIds' => [5]],
];
$profile = lps_analytics_scenario_default_profile_v4();
$profile['context']['warehouseIds'] = [1, 5, 7];
$profile['productFilters'] = ['currentSuppliers' => ['mode' => 'INCLUDE', 'values' => ['Kreul']]];
$profile['movementFilters'] = ['operationKinds' => ['mode' => 'EXCLUDE', 'values' => ['TRANSFER']]];
$profile['purchasePlanning'] = ['version' => 1, 'enabled' => true, 'allowTransfers' => true, 'groups' => [
    ['code' => 'kyiv', 'receivingWarehouseId' => 7, 'leadTimeDays' => 5, 'targetDays' => 20, 'safetyDays' => 5],
    ['code' => 'odesa', 'receivingWarehouseId' => 5, 'leadTimeDays' => 5, 'targetDays' => 20, 'safetyDays' => 5],
]];
$saved = lps_analytics_scenario_sanitize_profile($profile);
check($saved === lps_analytics_scenario_sanitize_profile($saved), 'Scenario survives repeated sanitization');
check($saved['productFilters'] === $profile['productFilters'] && $saved['movementFilters'] === $profile['movementFilters'], 'Purchase settings do not erase analytics filters');
check($saved['purchasePlanning']['groups'][0]['code'] === 'kyiv', 'Keep stable group identity');
check(!isset($saved['purchasePlanning']['groups'][0]['warehouseIds']), 'Do not copy mutable global composition into scenario');
$groups = lps_purchase_resolve_groups($saved, $configured);
$row = ['sku' => 'TEST-1', 'productName' => 'Test', 'dimensions' => ['currentSuppliers' => ['Kreul'], 'packageQuantity' => 10, 'minimumOrderQuantity' => 10],
    'metrics' => ['grossProfit' => 100], 'networkOrderPolicy' => ['orderAllowed' => true, 'status' => 'ALLOWED'],
    'inTransitStock' => ['status' => 'NO_IN_TRANSIT_STOCK'],
    'warehouseBreakdown' => [member(1, 10, 60), member(7, 20, 30), member(5, 200, 30)]];
$inputs = ['kyiv' => ['openOrders' => 0], 'odesa' => ['openOrders' => 0]];
$a = lps_purchase_calculate($row, $groups, 30, false, $inputs);
check($a['groups'][0]['regularSales'] === 90.0 && $a['groups'][0]['target'] === 90.0, 'Aggregate group demand exactly once');
check($a['groups'][0]['coverageDays'] === 10.0, 'Group coverage is computed from total stock and demand');
check($a['groups'][0]['recommendedQuantity'] === 60.0, 'Purchase subtracts stock once');
check($a['groups'][0]['returns'] === 4.0, 'Show returns without silently netting unclassified returns');
$a = lps_purchase_calculate($row, $groups, 30, true, $inputs);
check($a['groups'][0]['transferIn'] === 60.0 && $a['groups'][1]['transferOut'] === 60.0, 'Transfer conserves quantity');
check($a['groups'][0]['recommendedQuantity'] === 0, 'Transfer reduces purchase need');
check($a['groups'][1]['available'] - $a['groups'][1]['transferOut'] >= $a['groups'][1]['target'], 'Protect donor target');
$a = lps_purchase_calculate($row, $groups, 30, false);
check($a['groups'][0]['finalQuantity'] === null, 'Unknown incoming orders are not silently zero');
$edits = $inputs;
$edits['kyiv'] += ['pack' => 14, 'moq' => 100];
$a = lps_purchase_calculate($row, $groups, 30, false, $edits);
check($a['groups'][0]['recommendedQuantity'] === 112.0, 'Round to supplier pack after MOQ');
$edits['kyiv'] = ['openOrders' => 0, 'quantity' => 20, 'reason' => 'Manager confirmed'];
$a = lps_purchase_calculate($row, $groups, 30, false, $edits);
check($a['groups'][0]['finalQuantity'] === 20.0, 'Allow valid manager adjustment with reason');
$edits['kyiv']['quantity'] = 21;
$a = lps_purchase_calculate($row, $groups, 30, false, $edits);
check($a['groups'][0]['finalQuantity'] === null, 'Reject adjustment violating pack');
$bad = $row;
$bad['networkOrderPolicy']['orderAllowed'] = false;
$a = lps_purchase_calculate($bad, $groups, 30, false, $inputs);
check($a['groups'][0]['recommendedQuantity'] === null, 'Respect network purchase ban');
$bad = $row;
$bad['warehouseBreakdown'][1]['orderPolicy']['orderAllowed'] = false;
$a = lps_purchase_calculate($bad, $groups, 30, false, $inputs);
check($a['groups'][0]['finalQuantity'] === null, 'Respect receiving warehouse MIN=0');
$bad = $row;
array_shift($bad['warehouseBreakdown']);
$a = lps_purchase_calculate($bad, $groups, 30, false, $inputs);
check($a['groups'][0]['available'] === null && $a['groups'][0]['finalQuantity'] === null, 'Missing member is not zero');
$bad = $row;
$bad['inTransitStock'] = ['supplierOriginConfirmed' => true, 'availableForPlanningQuantity' => 20];
$edits = ['kyiv' => ['openOrders' => 0, 'inTransit' => 15], 'odesa' => ['openOrders' => 0, 'inTransit' => 10]];
$a = lps_purchase_calculate($bad, $groups, 30, true, $edits);
check($a['groups'][0]['finalQuantity'] === null && $a['groups'][1]['transferOut'] === 0, 'Do not allocate transit twice');
$bad = $row;
$bad['warehouseBreakdown'][1]['orderPolicy']['maximumStockLimited'] = true;
$bad['warehouseBreakdown'][1]['orderPolicy']['maximumStockLimit'] = 25;
$a = lps_purchase_calculate($bad, $groups, 30, true, $inputs);
check($a['groups'][0]['transferIn'] <= 5 && $a['groups'][0]['finalQuantity'] === null, 'Respect destination cap including transfers and pack rounding');
$overlapping = $configured;
$overlapping[1]['warehouseIds'][] = 7;
try { lps_purchase_resolve_groups($saved, $overlapping); throw new RuntimeException('Accepted overlapping groups'); }
catch (InvalidArgumentException $expected) {}
check(lps_purchase_period_days(['from' => '2024-02-01', 'to' => '2024-02-29']) === 29, 'Inclusive leap-year period');
try { lps_purchase_period_days(['from' => '2026-02-30', 'to' => '2026-03-01']); throw new RuntimeException('Accepted invalid date'); }
catch (InvalidArgumentException $expected) {}
echo "PASS: scenario roundtrip, group demand, transfer conservation, policy gates, transit, pack/MOQ and manager adjustments\n";
