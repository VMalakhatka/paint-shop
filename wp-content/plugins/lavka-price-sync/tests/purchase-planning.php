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
    'inTransitStock' => ['warehouseId' => 9, 'generationId' => 99, 'status' => 'NO_IN_TRANSIT_STOCK'],
    'warehouseBreakdown' => [member(1, 10, 60), member(7, 20, 30), member(5, 200, 30)]];
$inputs = ['kyiv' => ['openOrders' => 0, 'receiptsReviewed' => true], 'odesa' => ['openOrders' => 0, 'receiptsReviewed' => true]];
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
$edits['kyiv'] = ['openOrders' => 0, 'quantity' => 20, 'reason' => 'Manager confirmed', 'receiptsReviewed' => true];
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
$bad['inTransitStock'] = ['warehouseId' => 9, 'generationId' => 99, 'status' => 'CONFIRMED_SUPPLIER_ORIGIN', 'supplierOriginConfirmed' => true, 'availableForPlanningQuantity' => 20];
$edits = ['kyiv' => ['openOrders' => 0, 'inTransit' => 15], 'odesa' => ['openOrders' => 0, 'inTransit' => 10]];
$a = lps_purchase_calculate($bad, $groups, 30, true, $edits);
check($a['groups'][0]['finalQuantity'] === null && $a['groups'][1]['transferOut'] === 0, 'Do not allocate transit twice');
$single = [$groups[0]];
$a = lps_purchase_calculate($bad, $single, 30, false, $inputs);
check($a['groups'][0]['inputs']['inTransit'] === 20.0 && $a['groups'][0]['recommendedQuantity'] === 40.0, 'Single destination automatically deducts confirmed transit');
check($a['groups'][0]['target'] === 90.0 && $a['groups'][0]['regularSales'] === 90.0, 'Transit must not change demand forecast');
$a = lps_purchase_calculate($bad, $groups, 30, false, ['kyiv' => ['openOrders' => 0, 'inTransit' => 20, 'receiptsReviewed' => true], 'odesa' => ['openOrders' => 0, 'inTransit' => 0, 'receiptsReviewed' => true]]);
check($a['groups'][0]['recommendedQuantity'] === 40.0 && $a['groups'][1]['inputs']['inTransit'] === 0.0, 'Shared pool deducted only from allocated group');
$a = lps_purchase_calculate($bad, $groups, 30, false, $inputs);
check($a['groups'][0]['finalQuantity'] === null, 'Several destinations require explicit allocation');
foreach ([[9, 10], [10]] as $ids) {
    $a = lps_purchase_calculate($bad, $single, 30, false, $edits, $ids);
    check($a['transitPool'] === null && in_array('TRANSIT_SOURCE_MISMATCH', $a['groups'][0]['issues'], true), 'Never use legacy source for another configured set');
}
$a = lps_purchase_calculate($bad, $single, 30, false, $inputs, []);
check($a['groups'][0]['recommendedQuantity'] === 60.0 && $a['transitPool'] === 0.0, 'Explicit empty configuration disables deduction');
$bad['inTransitStock']['warehouseId'] = 7;
$a = lps_purchase_calculate($bad, $single, 30, false, $inputs, [7]);
check(in_array('TRANSIT_DESTINATION_OVERLAP', $a['groups'][0]['issues'], true), 'Prevent double counting on-hand and in-transit stock');
$bad['inTransitStock']['warehouseId'] = 9;
foreach (['MIXED_ORIGIN', 'NEGATIVE_TRANSIT_STOCK', 'OPENING_BALANCE_UNATTRIBUTED', 'UNKNOWN'] as $status) {
    $bad['inTransitStock']['status'] = $status;
    $a = lps_purchase_calculate($bad, $single, 30, false, $edits);
    check($a['transitPool'] === null && $a['groups'][0]['finalQuantity'] === null, 'Manual allocation must not bypass unconfirmed source: ' . $status);
}
$bad['inTransitStock']['status'] = 'CONFIRMED_SUPPLIER_ORIGIN';
$bad['inTransitStock']['generationId'] = null;
$a = lps_purchase_calculate($bad, $single, 30, false, $edits);
check($a['groups'][0]['finalQuantity'] === null, 'Require a source snapshot generation');
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
$filter = lps_purchase_filter_data(['minimumStock' => 0, 'groupLevel1Code' => 'ART', 'groupLevel1Name' => 'Art',
    'groupLevel2Code' => 'PAINT', 'groupLevel2Name' => 'Paint', 'groupLevel3Code' => 'ACRYLIC', 'groupLevel3Name' => 'Acrylic']);
check($filter['minimumStock'] === 0.0 && $filter['group']['value'] === 'ART', 'Expose exact minimum stock and primary group for result filters');
check($filter['subgroups'][1] === ['value' => '3:ACRYLIC', 'label' => 'Art › Paint › Acrylic'], 'Expose hierarchical subgroup paths without guessing from names');
try { lps_purchase_period_days(['from' => '2026-02-30', 'to' => '2026-03-01']); throw new RuntimeException('Accepted invalid date'); }
catch (InvalidArgumentException $expected) {}
echo "PASS: scenario roundtrip, group demand, transfer conservation, policy gates, transit, pack/MOQ and manager adjustments\n";

// User's KR-17817: observed sales 11, free stock 6, one pack of 6.
$one = [['code' => 'one', 'name' => 'One', 'warehouseIds' => [1], 'receivingWarehouseId' => 1,
    'leadTimeDays' => 0, 'targetDays' => 30, 'safetyDays' => 0]];
$fixture = ['sku' => 'KR-17817', 'dimensions' => ['currentSuppliers' => ['Kreul'], 'packageQuantity' => 6, 'minimumOrderQuantity' => 0],
    'metrics' => ['grossProfit' => 100], 'networkOrderPolicy' => ['orderAllowed' => true, 'status' => 'ALLOWED'],
    'warehouseBreakdown' => [member(1, 6, 11)]];
$edits = ['one' => ['openOrders' => 0]];
check(lps_purchase_calculate($fixture, $one, 30, false, $edits, [])['groups'][0]['finalQuantity'] === 6.0, 'Pack rounding uses database pack');
$edits['one']['respectPack'] = false;
check(lps_purchase_calculate($fixture, $one, 30, false, $edits, [])['groups'][0]['finalQuantity'] === 5.0, 'Without pack rounding only the need is purchased');
$edits['one'] += ['quantity' => 2, 'reason' => 'Unit purchase confirmed'];
check(lps_purchase_calculate($fixture, $one, 30, false, $edits, [])['groups'][0]['finalQuantity'] === 2.0, 'Two units allowed without pack rounding');
$edits['one']['respectPack'] = true;
check(lps_purchase_calculate($fixture, $one, 30, false, $edits, [])['groups'][0]['finalQuantity'] === null, 'Two units rejected when pack rounding enabled');
$edits['one']['respectPack'] = false;
$fixture['dimensions']['minimumOrderQuantity'] = 3;
check(lps_purchase_calculate($fixture, $one, 30, false, $edits, [])['groups'][0]['finalQuantity'] === null, 'Ignoring pack does not ignore supplier MOQ');
$fixture['dimensions']['minimumOrderQuantity'] = 0;
$fixture['dimensions']['packageQuantity'] = null;
check(lps_purchase_calculate($fixture, $one, 30, false, $edits, [])['groups'][0]['finalQuantity'] === 2.0, 'Unknown pack is not required when disabled');
$edits['one']['quantity'] = 0;
check(lps_purchase_calculate($fixture, $one, 30, false, $edits, [])['groups'][0]['finalQuantity'] === 0.0, 'Explicit zero manager override is retained');
$fixture['warehouseBreakdown'][0]['metrics']['availableQuantity'] = -11;
$fixture['warehouseBreakdown'][0]['metrics']['physicalQuantity'] = 19;
unset($edits['one']['quantity']);
$negative = lps_purchase_calculate($fixture, $one, 30, false, $edits, [])['groups'][0];
check($negative['available'] === -11.0 && $negative['finalQuantity'] === 22.0, 'Negative free stock increases deficit and is not missing data');
$profile['purchasePlanning']['respectPack'] = false;
check(lps_analytics_scenario_sanitize_profile($profile)['purchasePlanning']['respectPack'] === false, 'Scenario retains unpacked mode');
echo "PASS: packing modes, manual unit orders, MOQ, zero override and negative free stock\n";
