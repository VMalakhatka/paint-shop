<?php
if (!defined('ABSPATH')) exit;

function lps_purchase_report_sum(array $values): ?float {
    if (in_array(null, $values, true)) return null;
    return array_sum($values);
}

function lps_purchase_stock_months(?float $stock, ?float $sales, int $days): ?float {
    if ($stock === null || $sales === null || $days < 1) return null;
    if ($stock <= 0) return 0.0;
    return $sales > 0 ? $stock * $days / ($sales * 30) : 9999.0;
}

/** Display facts independently of purchase permission (MIN=0 must not hide dead stock). */
function lps_purchase_stock_report(array $row, array $settings, array $calculated, int $days): array {
    $members = array_column($row['warehouseBreakdown'] ?? [], null, 'warehouseId');
    $groups = array_column($settings, null, 'code');
    $reports = [];
    foreach ($calculated as $item) {
        $group = $groups[$item['groupCode']];
        $physical = $available = $expense = $sales = [];
        foreach ($group['warehouseIds'] as $id) {
            $m = $members[$id]['metrics'] ?? [];
            $physical[] = lps_purchase_number($m['physicalQuantity'] ?? null, -1000000000);
            $available[] = lps_purchase_number($m['availableQuantity'] ?? null, -1000000000);
            if (in_array($id, $group['stockOnlyWarehouseIds'] ?? [], true)) continue;
            $expense[] = lps_purchase_number($m['expenseQuantity'] ?? null, -1000000000);
            $sales[] = lps_purchase_number($m['regularSoldUnits'] ?? null);
        }
        $stock = lps_purchase_report_sum($available);
        if (array_intersect($item['issues'], ['INTERNAL_TRANSFER_DATA_REQUIRED','INTERNAL_TRANSFER_RESERVE_MISMATCH'])) $stock = null;
        if ($stock !== null) $stock += $item['internalRestored'];
        $regular = lps_purchase_report_sum($sales);
        $adjusted = $regular !== null ? $item['demand']['adjustedSales'] : null;
        $component = $item['componentDemand'];
        $target = $adjusted !== null && $item['minimumReserve'] !== null && $component !== null
            ? $adjusted / $days * ($group['leadTimeDays'] + $group['targetDays'] + $group['safetyDays']) + $item['minimumReserve'] + $component : null;
        // Do not mark a broken recipe's required components as surplus.
        foreach ($item['issues'] as $issue) if (str_starts_with($issue, 'ASSEMBLY_')) $target = null;
        $excess = $stock !== null && $target !== null ? max(0, $stock - $target - $item['plannedTransferOut']) : null;
        if (in_array('REPLENISHMENT_DEPENDENCY_REQUIRED', $item['issues'], true)) $excess = null;
        $reports[$item['groupCode']] = ['physical' => lps_purchase_report_sum($physical),
            'planningAvailable' => $stock, 'periodExpense' => lps_purchase_report_sum($expense), 'regularSales' => $regular,
            'componentDemand' => $component, 'excessStock' => $excess,
            'stockMonths' => lps_purchase_stock_months($stock, $regular, $days), 'inventoryTarget' => $target];
    }
    $total = [];
    foreach (['physical','planningAvailable','periodExpense','regularSales','componentDemand','inventoryTarget'] as $key)
        $total[$key] = lps_purchase_report_sum(array_column($reports, $key));
    // Network surplus nets deficits in other groups; internal movements are not extra demand.
    $total['excessStock'] = $total['planningAvailable'] !== null && $total['inventoryTarget'] !== null
        ? max(0, $total['planningAvailable'] - $total['inventoryTarget']) : null;
    $total['stockMonths'] = lps_purchase_stock_months($total['planningAvailable'], $total['regularSales'], $days);
    return ['total' => $total, 'groups' => $reports];
}
