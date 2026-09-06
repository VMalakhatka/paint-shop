<?php
if (!defined('ABSPATH')) exit;

function lps_purchase_number($value, float $min = 0, float $max = 1000000000): ?float {
    if ($value === null || $value === '' || !is_numeric($value)) return null;
    $number = (float)$value;
    return is_finite($number) && $number >= $min && $number <= $max ? $number : null;
}

function lps_purchase_profile(array $input): array {
    $groups = [];
    foreach (array_slice((array)($input['groups'] ?? []), 0, 50) as $group) {
        if (!is_array($group)) continue;
        $groups[] = [
            'code' => sanitize_key((string)($group['code'] ?? '')),
            'receivingWarehouseId' => absint($group['receivingWarehouseId'] ?? 0),
            'leadTimeDays' => lps_purchase_number($group['leadTimeDays'] ?? null, 0, 730),
            'targetDays' => lps_purchase_number($group['targetDays'] ?? null, 1, 730),
            'safetyDays' => lps_purchase_number($group['safetyDays'] ?? null, 0, 365),
        ];
    }
    return ['version' => 1, 'enabled' => !empty($input['enabled']),
        'allowTransfers' => !empty($input['allowTransfers']), 'groups' => $groups];
}

function lps_purchase_resolve_groups(array $profile, array $configured): array {
    $plan = $profile['purchasePlanning'] ?? [];
    if (empty($plan['enabled']) || empty($plan['groups'])) {
        throw new InvalidArgumentException(__('Enable purchase planning and select destination groups in the scenario.', 'lavka-price-sync'));
    }
    $catalog = array_column($configured, null, 'code');
    $scope = array_map('intval', (array)($profile['context']['warehouseIds'] ?? []));
    $used = [];
    $resolved = [];
    foreach ($plan['groups'] as $group) {
        $current = $catalog[$group['code']] ?? null;
        if (!$current || array_diff($current['warehouseIds'], $scope)) {
            throw new InvalidArgumentException(__('Every destination group must exist and all its warehouses must be included in the analytics scenario.', 'lavka-price-sync'));
        }
        foreach ($current['warehouseIds'] as $id) {
            if (isset($used[$id])) throw new InvalidArgumentException(__('Destination groups must not share physical warehouses.', 'lavka-price-sync'));
            $used[$id] = true;
        }
        if (!in_array($group['receivingWarehouseId'], $current['warehouseIds'], true)
            || $group['leadTimeDays'] === null || $group['targetDays'] === null || $group['safetyDays'] === null) {
            throw new InvalidArgumentException(__('Set a receiving warehouse, lead time, target coverage and safety stock days for every destination group.', 'lavka-price-sync'));
        }
        $resolved[] = array_merge($group, ['name' => $current['name'], 'warehouseIds' => $current['warehouseIds']]);
    }
    return $resolved;
}

function lps_purchase_period_days(array $period): int {
    $dates = [];
    foreach (['from', 'to'] as $key) {
        $value = (string)($period[$key] ?? '');
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('UTC'));
        if (!$date || $date->format('Y-m-d') !== $value) throw new InvalidArgumentException(__('Select a valid report period.', 'lavka-price-sync'));
        $dates[$key] = $date;
    }
    if ($dates['to'] < $dates['from']) throw new InvalidArgumentException(__('Select a valid report period.', 'lavka-price-sync'));
    return (int)$dates['from']->diff($dates['to'])->days + 1;
}

// Operates on Java's confirmed metrics; never reconstructs Folio movements.
function lps_purchase_calculate(array $row, array $groups, int $period_days, bool $allow_transfers, array $edits = []): array {
    $members = array_column((array)($row['warehouseBreakdown'] ?? []), null, 'warehouseId');
    $transit = $row['inTransitStock'] ?? [];
    $transit_pool = ($transit['status'] ?? '') === 'NO_IN_TRANSIT_STOCK' ? 0.0
        : (($transit['supplierOriginConfirmed'] ?? null) === true
            ? lps_purchase_number($transit['availableForPlanningQuantity'] ?? null) : null);
    $network = $row['networkOrderPolicy'] ?? [];
    $result = [];
    $allocated_transit = 0;
    foreach ($groups as $group) {
        $edit = $edits[$group['code']] ?? [];
        $issues = [];
        if (count((array)($row['dimensions']['currentSuppliers'] ?? [])) !== 1) $issues[] = 'SUPPLIER_NOT_CONFIRMED';
        $physical = $available = $sales = $returns = $reserve = 0.0;
        $cap = 0.0;
        $unlimited = false;
        $valid = true;
        $eligible = false;
        foreach ($group['warehouseIds'] as $id) {
            $member = $members[$id] ?? null;
            $metrics = $member['metrics'] ?? [];
            // Missing warehouse rows are unknown, not proof of zero stock.
            foreach (['physicalQuantity', 'availableQuantity', 'regularSoldUnits', 'returnQuantity'] as $key) {
                if (lps_purchase_number($metrics[$key] ?? null) === null) $valid = false;
            }
            $physical += (float)($metrics['physicalQuantity'] ?? 0);
            $available += (float)($metrics['availableQuantity'] ?? 0);
            $sales += (float)($metrics['regularSoldUnits'] ?? 0);
            $returns += (float)($metrics['returnQuantity'] ?? 0);
            $policy = $member['orderPolicy'] ?? [];
            if (($policy['orderAllowed'] ?? null) === false) continue;
            if (($policy['orderAllowed'] ?? null) !== true || lps_purchase_number($policy['reserveAboveForecast'] ?? null) === null) {
                $valid = false;
                continue;
            }
            $eligible = true;
            $reserve += (float)$policy['reserveAboveForecast'];
            if (($policy['maximumStockLimited'] ?? null) === false) $unlimited = true;
            elseif (($policy['maximumStockLimited'] ?? null) === true && lps_purchase_number($policy['maximumStockLimit'] ?? null) !== null) $cap += (float)$policy['maximumStockLimit'];
            else $valid = false;
        }
        $destination = $members[$group['receivingWarehouseId']] ?? [];
        $destination_policy = $destination['orderPolicy'] ?? [];
        if (!$valid) $issues[] = 'INCOMPLETE_WAREHOUSE_DATA';
        if (!$eligible || ($destination_policy['orderAllowed'] ?? null) !== true) $issues[] = 'DESTINATION_POLICY_BLOCKED';
        if (($network['orderAllowed'] ?? null) !== true || ($network['status'] ?? '') !== 'ALLOWED') $issues[] = 'NETWORK_POLICY_NOT_CONFIRMED';
        $profit = lps_purchase_number($row['metrics']['grossProfit'] ?? null, -1000000000);
        if ($profit === null || $profit < 0) $issues[] = 'PROFIT_REVIEW_REQUIRED';
        $daily = $valid ? $sales / $period_days : null;
        $target = $valid && $eligible ? $daily * ($group['leadTimeDays'] + $group['targetDays'] + $group['safetyDays']) + $reserve : null;
        if ($target !== null && !$unlimited) $target = min($target, $cap);
        $incoming = lps_purchase_number($edit['inTransit'] ?? ($transit_pool === 0.0 ? 0 : null));
        $open_orders = lps_purchase_number($edit['openOrders'] ?? null);
        $pack = lps_purchase_number($edit['pack'] ?? ($row['dimensions']['packageQuantity'] ?? null), 0.000001);
        $moq = lps_purchase_number($edit['moq'] ?? ($row['dimensions']['minimumOrderQuantity'] ?? null));
        $allocated_transit += $incoming ?? 0;
        $ready = !$issues && $incoming !== null && $open_orders !== null && $pack !== null && $moq !== null;
        $position = $available + ($incoming ?? 0) + ($open_orders ?? 0);
        $result[$group['code']] = [
            'groupCode' => $group['code'], 'groupName' => $group['name'], 'warehouseIds' => $group['warehouseIds'],
            'receivingWarehouseId' => $group['receivingWarehouseId'], 'physical' => $valid ? $physical : null,
            'available' => $valid ? $available : null, 'regularSales' => $valid ? $sales : null,
            'returns' => $valid ? $returns : null, 'coverageDays' => $daily > 0 ? $available / $daily : null,
            'target' => $target, 'needBeforeReceipts' => $target === null ? null : max(0, $target - $available),
            'inputs' => ['inTransit' => $incoming, 'openOrders' => $open_orders, 'pack' => $pack, 'moq' => $moq],
            'position' => $position, 'ready' => $ready, 'issues' => $issues,
            'transferIn' => 0, 'transferOut' => 0, 'transfers' => [],
            'recommendedQuantity' => null, 'managerQuantity' => lps_purchase_number($edit['quantity'] ?? null),
            'managerReason' => sanitize_text_field((string)($edit['reason'] ?? '')),
        ];
    }
    if ($transit_pool !== null && $allocated_transit > $transit_pool + 0.000001) {
        foreach ($result as &$item) { $item['ready'] = false; $item['issues'][] = 'TRANSIT_OVERALLOCATED'; }
        unset($item);
    }
    // Reserve each donor's own target. Transfer only stock on hand, never expected receipts.
    if ($allow_transfers) {
        foreach ($result as $to => &$receiver) {
            if (!$receiver['ready']) continue;
            $need = max(0, $receiver['target'] - $receiver['position']);
            $destination = $members[$receiver['receivingWarehouseId']];
            if (($destination['orderPolicy']['maximumStockLimited'] ?? null) === true) {
                $headroom = max(0, (float)$destination['orderPolicy']['maximumStockLimit']
                    - (float)$destination['metrics']['availableQuantity'] - $receiver['inputs']['inTransit'] - $receiver['inputs']['openOrders']);
                $need = min($need, $headroom);
            }
            foreach ($result as $from => &$donor) {
                if ($from === $to || !$donor['ready'] || $need <= 0) continue;
                $surplus = max(0, $donor['available'] - $donor['target'] - $donor['transferOut']);
                $quantity = min($need, $surplus);
                if ($quantity <= 0) continue;
                $donor['transferOut'] += $quantity;
                $receiver['transferIn'] += $quantity;
                $receiver['transfers'][] = ['fromGroup' => $from, 'fromName' => $donor['groupName'], 'quantity' => $quantity];
                $need -= $quantity;
            }
            unset($donor);
        }
        unset($receiver);
    }
    foreach ($result as $code => &$item) {
        if ($item['ready']) {
            $need = max(0, $item['target'] - $item['position'] - $item['transferIn'] + $item['transferOut']);
            $item['recommendedQuantity'] = $need <= 0.000001 ? 0 : round(ceil((max($need, $item['inputs']['moq']) - 0.0000001) / $item['inputs']['pack']) * $item['inputs']['pack'], 6);
            $destination = $members[$item['receivingWarehouseId']];
            $policy = $destination['orderPolicy'];
            $quantity = $item['managerQuantity'] ?? $item['recommendedQuantity'];
            if (($policy['maximumStockLimited'] ?? null) === true && $quantity + $item['inputs']['inTransit'] + $item['inputs']['openOrders'] + $item['transferIn'] + (float)$destination['metrics']['availableQuantity'] > (float)$policy['maximumStockLimit'] + 0.000001) {
                $item['issues'][] = 'DESTINATION_MAXIMUM_EXCEEDED';
                $item['recommendedQuantity'] = null;
            }
            if ($quantity > 0 && ($quantity + 0.000001 < $item['inputs']['moq'] || abs($quantity / $item['inputs']['pack'] - round($quantity / $item['inputs']['pack'])) > 0.000001)) $item['issues'][] = 'PACK_OR_MOQ_VIOLATION';
        } else $item['issues'][] = 'SUPPLY_INPUTS_REQUIRED';
        if ($item['managerQuantity'] !== null && $item['managerReason'] === '') $item['issues'][] = 'MANAGER_REASON_REQUIRED';
        $item['finalQuantity'] = !$item['issues'] ? ($item['managerQuantity'] ?? $item['recommendedQuantity']) : null;
        $item['status'] = $item['finalQuantity'] === null ? 'REVIEW_REQUIRED' : 'PREVIEW_READY';
        unset($item['position'], $item['ready']);
    }
    unset($item);
    return ['sku' => $row['sku'] ?? '', 'productName' => $row['productName'] ?? '',
        'supplier' => $row['dimensions']['currentSuppliers'] ?? [], 'transitPool' => $transit_pool,
        'transitStatus' => $transit['status'] ?? 'UNKNOWN', 'groups' => array_values($result)];
}
