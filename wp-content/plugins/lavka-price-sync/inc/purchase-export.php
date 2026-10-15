<?php
if (!defined('ABSPATH')) exit;

/** A supplier purchase is distinct from the destination's internal replenishment. */
function lps_purchase_export_sum(array $values): ?float {
    $sum = 0.0;
    foreach ($values as $value) {
        if (!is_numeric($value) || !is_finite((float)$value)) return null;
        $sum += (float)$value;
    }
    return $sum;
}

function lps_purchase_export_price(array $prices): array {
    $variants = []; $limited = false;
    foreach ($prices as $price) foreach ($price['offers'] ?? [] as $offer) {
        if (!in_array($offer['offerStatus'] ?? '', ['PRESENT', 'WHILE_STOCK_LASTS'], true) || !empty($offer['issues'])) continue;
        $amount = lps_purchase_number($offer['price'] ?? null);
        $pack = lps_purchase_number($offer['pack'] ?? null, 0.000001);
        $basis = $offer['priceBasis'] ?? '';
        if ($amount === null || !in_array($basis, ['UNIT', 'PACK'], true) || ($basis === 'PACK' && $pack === null)) continue;
        $currency = (string)($offer['currency'] ?? ''); $article = (string)($offer['article'] ?? '');
        if ($currency === '' || $article === '') continue;
        $unit = $basis === 'PACK' ? $amount / $pack : $amount;
        $variants[wp_json_encode([$article, $currency, round($unit, 12)])] = ['supplierArticle' => $article, 'supplierUnitPrice' => $unit, 'supplierCurrency' => $currency];
        $limited = $limited || ($offer['offerStatus'] === 'WHILE_STOCK_LASTS');
    }
    if (count($variants) !== 1) return ['supplierArticle' => '', 'supplierUnitPrice' => null, 'supplierCurrency' => '',
        'supplierPriceNote' => count($variants) ? __('Select a supplier price variant.', 'lavka-price-sync') : __('No confirmed supplier price.', 'lavka-price-sync')];
    return reset($variants) + ['supplierPriceNote' => $limited ? __('Confirm supplier availability: while stocks last.', 'lavka-price-sync') : ''];
}

function lps_purchase_report_is_child(array $item): bool {
    return !empty($item['assembly']['manufactured']) || !empty($item['assembly']['components']);
}

function lps_purchase_report_includes(array $item): bool {
    return !empty($item['assembly']['purchasable']) || lps_purchase_report_is_child($item);
}

/** Relations come from the full recipe, including children with zero current shortage. */
function lps_purchase_report_relations(array $state): array {
    $relations = [];
    foreach ($state['assemblyGraph']['edges'] ?? [] as $edge) {
        $parent = lps_assembly_key($edge['parent']); $child = lps_assembly_key($edge['child']);
        $relations[$parent]['children'][$child] = (string)$edge['child'];
        $relations[$child]['parents'][$parent] = (string)$edge['parent'];
    }
    foreach ($relations as &$entry) foreach ($entry as &$list) { ksort($list, SORT_STRING); $list = implode(', ', $list); }
    unset($entry, $list);
    return $relations;
}

/** One immutable row per SKU, including analytical child rows with zero supplier purchase. */
function lps_purchase_export_summary(array $state, array $results): array {
    $t = lps_purchase_i18n();
    $relations = lps_purchase_report_relations($state);
    $labels = [
        'sku' => $t['sku'], 'supplierArticle' => __('Supplier article', 'lavka-price-sync'), 'product' => $t['product'],
        'assemblyRole' => $t['assemblyRole'], 'parents' => $t['parents'], 'children' => $t['children'],
        'manufacturingNeed' => $t['manufacturingNeed'],
        'gtin' => __('GTIN', 'lavka-price-sync'), 'coldStatus' => __('Cold sensitivity', 'lavka-price-sync'),
        'final' => __('Total supplier order quantity', 'lavka-price-sync'),
        'supplierUnitPrice' => __('Supplier unit price', 'lavka-price-sync'), 'supplierCurrency' => __('Supplier currency', 'lavka-price-sync'),
        'supplierAmount' => __('Supplier order amount', 'lavka-price-sync'), 'supplierPriceNote' => __('Supplier price review', 'lavka-price-sync'),
        'periodExpense' => $t['periodExpense'], 'sales' => $t['sales'],
        'analysisPhysical' => __('Physical stock in warehouse groups, excluding Transport and Receiving', 'lavka-price-sync'),
        'transitPhysical' => __('Physical stock on Transport and Receiving', 'lavka-price-sync'),
        'networkPhysical' => __('Physical stock including Transport and Receiving', 'lavka-price-sync'),
        'planningAvailable' => __('Stock available for planning including Transport and Receiving', 'lavka-price-sync'),
        'transitPool' => $t['transitPool'], 'customerPreorders' => $t['customerPreorders'],
        'preorderAdditionalDemand' => $t['preorderAdditionalDemand'], 'expectedFreeAfterPreorders' => $t['expectedFreeAfterPreorders'],
        'preorderUncoveredAfterReceipts' => $t['preorderUncoveredAfterReceipts'], 'componentDemand' => $t['componentDemand'],
        'childCoverageShortfall' => $t['childCoverageShortfall'], 'childCoverageWarning' => __('Child demand coverage warning', 'lavka-price-sync'),
        'stockMonths' => $t['stockMonths'], 'excessStock' => $t['excessStock'],
        'status' => __('Status', 'lavka-price-sync'), 'planningNotes' => __('Calculation notes', 'lavka-price-sync'),
        'currentStockCapturedAt' => $t['currentStockCapture'], 'period' => __('Report period', 'lavka-price-sync'),
    ];
    $columns = [];
    $numeric = ['final','manufacturingNeed','supplierUnitPrice','supplierAmount','periodExpense','sales','analysisPhysical','transitPhysical','networkPhysical','planningAvailable','transitPool','customerPreorders','preorderAdditionalDemand','expectedFreeAfterPreorders','preorderUncoveredAfterReceipts','componentDemand','childCoverageShortfall','stockMonths','excessStock'];
    foreach ($labels as $key => $label) $columns[] = ['key' => $key, 'label' => $label,
        'numberFormat' => $key === 'supplierUnitPrice' ? '0.0000' : ($key === 'supplierAmount' ? '0.00' : (in_array($key, $numeric, true) ? '#,##0.###' : ''))];
    foreach ($state['groups'] as $group) foreach (['physical', 'sales', 'periodExpense', 'stockoutDays', 'minimumReserve', 'ownTarget', 'customerPreorders', 'preorderAdditionalDemand', 'expectedFreeAfterPreorders', 'preorderUncoveredAfterReceipts', 'componentDemand', 'plannedTransferIn', 'plannedTransferOut', 'final'] as $key) {
        $columns[] = ['key' => 'group_' . $group['code'] . '_' . $key, 'label' => $group['name'] . ': ' . $t[$key], 'numberFormat' => '#,##0.###'];
    }
    $warehouse_ids = array_values(array_unique(array_merge(...array_merge(array_column($state['groups'], 'warehouseIds'), [$state['transitWarehouseIds']]))));
    sort($warehouse_ids, SORT_NUMERIC);
    $warehouse_names = array_column($state['context']['warehouses'] ?? [], 'name', 'id');
    foreach ($state['context']['transit']['sources'] ?? [] as $source) $warehouse_names[$source['warehouseId']] = $source['warehouseName'];
    foreach ($warehouse_ids as $id) foreach (['physical' => $t['physical'], 'available' => $t['available']] as $key => $label) {
        $columns[] = ['key' => 'warehouse_' . $id . '_' . $key, 'label' => ($warehouse_names[$id] ?? '#' . $id) . ' (#' . $id . '): ' . $label, 'numberFormat' => '#,##0.###'];
    }
    $rows = [];
    foreach ($results as $item) {
        if (!lps_purchase_report_includes($item)) continue;
        $is_child = lps_purchase_report_is_child($item);
        $links = $relations[lps_assembly_key($item['sku'])] ?? [];
        $raw = $state['rows'][$item['sku']] ?? []; $total = $item['stockReport']['total'];
        $procurement = array_values(array_filter($item['groups'], static fn($group) => ($group['supplyFromGroupCode'] ?? '') === ''));
        $quantity = $is_child ? 0.0 : ($procurement ? lps_purchase_export_sum(array_map(static fn($group) => $group['finalQuantity'] ?? null, $procurement)) : null);
        $sources = array_column($item['transitSources'] ?? [], null, 'warehouseId'); $transit_physical = [];
        foreach ($state['transitWarehouseIds'] as $id) $transit_physical[] = ($sources[$id]['status'] ?? '') === 'CAPTURED' ? ($sources[$id]['physicalQuantity'] ?? null) : null;
        $transit_physical = lps_purchase_export_sum($transit_physical);
        $price = lps_purchase_export_price($raw['supplierPrices'] ?? []);
        if ($is_child) $price['supplierPriceNote'] = '';
        $record = ['sku' => (string)$item['sku'], 'product' => $item['productName'], 'gtin' => (string)($raw['dimensions']['primaryBarcode'] ?? ''),
            'assemblyRole' => $is_child ? $t['childProduct'] : (!empty($links['children']) ? $t['parentProduct'] : $t['supplierOrder']),
            'parents' => $links['parents'] ?? '', 'children' => $links['children'] ?? '',
            'manufacturingNeed' => $is_child ? lps_purchase_export_sum(array_map(static fn($group) => $group['manufacturingNeed'] ?? null, $item['groups'])) : null,
            'coldStatus' => $t['coldStatuses'][$item['coldCoverage']['status']] ?? $t['coldStatuses']['UNKNOWN'],
            'final' => $quantity, 'supplierAmount' => $is_child ? 0.0 : ($quantity !== null && $price['supplierUnitPrice'] !== null ? $quantity * $price['supplierUnitPrice'] : null),
            'periodExpense' => $total['periodExpense'], 'sales' => $total['regularSales'], 'analysisPhysical' => $total['physical'],
            'transitPhysical' => $transit_physical, 'networkPhysical' => lps_purchase_export_sum([$total['physical'], $transit_physical]),
            'planningAvailable' => lps_purchase_export_sum([$total['planningAvailable'], $item['transitPool']]),
            'transitPool' => $item['transitPool'],
            'customerPreorders' => lps_purchase_export_sum(array_column($item['groups'], 'customerPreorders')),
            'preorderAdditionalDemand' => lps_purchase_export_sum(array_column($item['groups'], 'preorderAdditionalDemand')),
            'expectedFreeAfterPreorders' => lps_purchase_export_sum(array_column($item['groups'], 'expectedFreeAfterPreorders')),
            'preorderUncoveredAfterReceipts' => lps_purchase_export_sum(array_column($item['groups'], 'preorderUncoveredAfterReceipts')),
            'componentDemand' => $total['componentDemand'],
            'childCoverageShortfall' => lps_purchase_export_sum(array_map(static fn($group) => $group['childCoverageShortfall'] ?? null, $item['groups'])),
            'childCoverageWarning' => '',
            'stockMonths' => $total['stockMonths'], 'excessStock' => $total['excessStock'],
            'status' => $quantity === null || ($is_child && array_filter($item['groups'], static fn($group) => !empty($group['issues']) || ($group['manufacturingNeed'] ?? null) === null)) ? $t['review'] : $t['ready'], 'planningNotes' => '',
            'currentStockCapturedAt' => $state['currentStockCapture']['capturedAt'] ?? '',
            'period' => $state['query']['period']['from'] . ' - ' . $state['query']['period']['to']] + $price;
        $notes = [];
        foreach ($item['groups'] as $group) {
            foreach (['physical', 'regularSales', 'plannedTransferIn', 'plannedTransferOut', 'minimumReserve', 'ownTarget', 'customerPreorders', 'preorderAdditionalDemand', 'expectedFreeAfterPreorders', 'preorderUncoveredAfterReceipts', 'componentDemand', 'finalQuantity'] as $key) {
                $mapped = ['regularSales' => 'sales', 'finalQuantity' => 'final'][$key] ?? $key;
                $record['group_' . $group['groupCode'] . '_' . $mapped] = $group[$key];
            }
            if ($is_child) $record['group_' . $group['groupCode'] . '_final'] = 0.0;
            $record['group_' . $group['groupCode'] . '_periodExpense'] = $item['stockReport']['groups'][$group['groupCode']]['periodExpense'];
            $record['group_' . $group['groupCode'] . '_stockoutDays'] = $group['availability']['stockoutDays'];
            foreach ($group['issues'] as $code) $notes[] = $group['groupName'] . ': ' . ($t['issues'][$code] ?? $code);
            foreach ($group['notes'] ?? [] as $code) $notes[] = $group['groupName'] . ': ' . ($t['notes'][$code] ?? $code);
        }
        $record['planningNotes'] = implode('; ', array_unique($notes));
        if ($record['childCoverageShortfall'] !== null && $record['childCoverageShortfall'] > 0) $record['childCoverageWarning'] = $t['childCoverageWarning'];
        $members = array_column($raw['warehouseBreakdown'] ?? [], null, 'warehouseId');
        foreach ($warehouse_ids as $id) {
            $metrics = $members[$id]['metrics'] ?? $sources[$id] ?? [];
            $record['warehouse_' . $id . '_physical'] = $metrics['physicalQuantity'] ?? null;
            $record['warehouse_' . $id . '_available'] = $metrics['availableQuantity'] ?? null;
        }
        foreach ($record as &$value) if (is_string($value) && preg_match('/^[\s]*[=+@\-]/u', $value)) $value = "'" . $value;
        unset($value); $rows[] = $record;
    }
    usort($rows, static fn($a, $b) => strcmp($a['sku'], $b['sku']));
    return ['columns' => $columns, 'rows' => $rows];
}
