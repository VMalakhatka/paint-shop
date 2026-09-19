<?php
if (!defined('ABSPATH')) exit;

function lps_purchase_number($value, float $min = 0, float $max = 1000000000): ?float {
    if ($value === null || $value === '' || !is_numeric($value)) return null;
    $number = (float)$value;
    return is_finite($number) && $number >= $min && $number <= $max ? $number : null;
}

function lps_purchase_transit_warehouses(): array {
    return function_exists('lavka_get_transit_warehouse_ids') ? lavka_get_transit_warehouse_ids() : [9];
}

function lps_transit_configuration(): array {
    $ids = lps_purchase_transit_warehouses();
    return ['warehouseIds' => $ids, 'configurationRevision' => hash('sha256', json_encode(array_values($ids)))];
}

function lps_transit_matches(array $value, array $ids): bool {
    return ($value['warehouseIds'] ?? null) === $ids
        && ($value['configurationRevision'] ?? '') === hash('sha256', json_encode(array_values($ids)));
}

function lps_purchase_transit(array $transit, array $warehouse_ids): array {
    $version = (int)($transit['calculationVersion'] ?? 0);
    if ($version === 3) {
        $status = (string)($transit['networkPlanningStatus'] ?? $transit['status'] ?? 'INCOMPLETE_TRANSIT_DATA');
        $unknown = ['quantity' => null, 'status' => $status, 'issue' => 'NETWORK_TRANSIT_NOT_READY'];
        if (!lps_transit_matches($transit, $warehouse_ids)) return ['quantity' => null, 'status' => 'TRANSIT_SOURCE_MISMATCH', 'issue' => 'TRANSIT_SOURCE_MISMATCH'];
        if (!$warehouse_ids) return ($transit['enabled'] ?? null) === false && ($transit['status'] ?? '') === 'DISABLED'
            ? ['quantity' => 0.0, 'status' => 'DISABLED', 'issue' => null] : $unknown;
        if (($transit['enabled'] ?? null) !== true || ($transit['networkPlanningReady'] ?? null) !== true
            || ($transit['networkSnapshotConsistency']['confirmed'] ?? null) !== true) return $unknown;
        $sources = $transit['sources'] ?? [];
        $ids = array_column($sources, 'warehouseId'); sort($ids, SORT_NUMERIC);
        if ($ids !== $warehouse_ids) return $unknown;
        $sum = 0.0;
        foreach ($sources as $source) {
            $source_quantity = lps_purchase_number($source['availableForNetworkPlanningQuantity'] ?? null);
            if ($source_quantity === null || !in_array($source['status'] ?? '', ['AVAILABLE_PHYSICAL_STOCK', 'NO_AVAILABLE_TRANSIT_STOCK'], true)
                || (int)($source['generationId'] ?? 0) < 1) return $unknown;
            $sum += $source_quantity;
        }
        $quantity = lps_purchase_number($transit['availableForNetworkPlanningQuantity'] ?? null);
        if ($quantity === null || abs($quantity - $sum) > 0.000001) return $unknown;
        return ['quantity' => $quantity, 'status' => $status, 'issue' => null];
    }
    if ($version > 0) return ['quantity' => null, 'status' => 'TRANSIT_CONTRACT_OUTDATED', 'issue' => 'TRANSIT_CONTRACT_OUTDATED'];
    if (!$warehouse_ids) return ['quantity' => 0.0, 'status' => 'TRANSIT_DISABLED', 'issue' => null];
    // The current contract has one source. Never silently accept a partial source set.
    if (count($warehouse_ids) !== 1 || (int)($transit['warehouseId'] ?? 0) !== $warehouse_ids[0]) {
        return ['quantity' => null, 'status' => 'TRANSIT_SOURCE_MISMATCH', 'issue' => 'TRANSIT_SOURCE_MISMATCH'];
    }
    $status = (string)($transit['status'] ?? 'UNKNOWN');
    $quantity = null;
    if ((int)($transit['generationId'] ?? 0) > 0) {
        if ($status === 'NO_IN_TRANSIT_STOCK') $quantity = 0.0;
        elseif ($status === 'CONFIRMED_SUPPLIER_ORIGIN' && ($transit['supplierOriginConfirmed'] ?? null) === true) {
            $quantity = lps_purchase_number($transit['availableForPlanningQuantity'] ?? null);
        }
    }
    return ['quantity' => $quantity, 'status' => $status, 'issue' => $quantity === null ? 'TRANSIT_NOT_CONFIRMED' : null];
}

function lps_purchase_pack_mode(array $input, string $fallback = 'UP'): string {
    if (isset($input['packRounding'])) {
        if (!in_array($input['packRounding'], ['NONE', 'UP', 'DOWN'], true)) {
            throw new InvalidArgumentException(__('Select a valid pack rounding mode.', 'lavka-price-sync'));
        }
        return $input['packRounding'];
    }
    return array_key_exists('respectPack', $input) ? ($input['respectPack'] === false ? 'NONE' : 'UP') : $fallback;
}

function lps_purchase_rounded_quantity(float $need, float $moq, ?float $pack, string $mode): float {
    $units = round(max(0, $need), 0, PHP_ROUND_HALF_DOWN);
    if ($units <= 0) return 0.0;
    $units = max($units, $moq);
    if ($mode === 'NONE') return ceil($units);
    $packs = $units / $pack;
    return round(($mode === 'DOWN' ? floor($packs + 0.0000001) : ceil($packs - 0.0000001)) * $pack, 6);
}

function lps_analytics_stock_only_ids(array $calculation, array $scope): array {
    $values = $calculation['stockOnlyWarehouseIds'] ?? [];
    if (!is_array($values)) throw new InvalidArgumentException(__('Invalid stock-only warehouse selection.', 'lavka-price-sync'));
    $ids = [];
    foreach ($values as $value) {
        if ((!is_int($value) && !is_string($value)) || !preg_match('/^[1-9][0-9]*$/D', (string)$value)
            || !in_array((int)$value, array_map('intval', $scope), true)) {
            throw new InvalidArgumentException(__('Stock-only warehouses must belong to the scenario.', 'lavka-price-sync'));
        }
        $ids[] = (int)$value;
    }
    $ids = array_values(array_unique($ids)); sort($ids, SORT_NUMERIC);
    return $ids;
}

function lps_purchase_availability(array $group, array $row): array {
    $unknown = ['status' => 'HISTORY_NOT_READY', 'availableDays' => null, 'stockoutDays' => null];
    $expected = array_map('intval', $group['demandWarehouseIds'] ?? $group['warehouseIds']);
    sort($expected);
    foreach ((array)($row['warehouseGroupBreakdown'] ?? []) as $source) {
        $ids = array_map('intval', (array)($source['warehouseIds'] ?? []));
        sort($ids);
        if (($source['code'] ?? '') !== $group['code'] || $ids !== $expected) continue;
        $history = $source['availability'] ?? [];
        if (($history['status'] ?? '') !== 'MEASURED') return $unknown;
        $available = lps_purchase_number($history['availableDays'] ?? null);
        $stockout = lps_purchase_number($history['stockoutDays'] ?? null);
        if ($available === null || $stockout === null) return $unknown;
        return ['status' => 'MEASURED', 'availableDays' => $available, 'stockoutDays' => $stockout];
    }
    return $unknown;
}

function lps_purchase_demand(float $sales, array $group, array $row): array {
    $enabled = !empty($group['stockoutCorrectionEnabled']);
    $multiplier = (float)($group['maxDemandMultiplier'] ?? 1.1);
    $base = ['enabled' => $enabled, 'maxDemandMultiplier' => $multiplier, 'estimatedLostSales' => null,
        'appliedLostSales' => 0.0, 'adjustedSales' => $sales, 'status' => 'DISABLED'];
    if (!$enabled) return $base;
    if ($multiplier == 1 || $sales == 0) return array_merge($base, ['status' => 'CAP_ZERO']);
    foreach ((array)($row['warehouseGroupBreakdown'] ?? []) as $source) {
        $ids = array_map('intval', (array)($source['warehouseIds'] ?? [])); sort($ids);
        $expected = $group['demandWarehouseIds'] ?? $group['warehouseIds']; sort($expected);
        if (($source['code'] ?? '') !== $group['code'] || $ids !== $expected) continue;
        $estimate = $source['stockoutDemand'] ?? [];
        $lost = lps_purchase_number($estimate['estimatedLostSales'] ?? null);
        if (($source['availability']['status'] ?? '') !== 'MEASURED'
            || ($estimate['status'] ?? '') !== 'ESTIMATED'
            || ($estimate['method'] ?? '') !== 'SALES_ON_AVAILABLE_END_OF_DAY_DAYS_V1' || $lost === null) break;
        $applied = min($lost, max(0, $sales * ($multiplier - 1)));
        return array_merge($base, ['estimatedLostSales' => $lost, 'appliedLostSales' => $applied,
            'adjustedSales' => $sales + $applied, 'status' => 'APPLIED']);
    }
    return array_merge($base, ['status' => 'HISTORY_NOT_READY', 'appliedLostSales' => null, 'adjustedSales' => null]);
}

function lps_purchase_profile(array $input): array {
    $groups = [];
    foreach (array_slice((array)($input['groups'] ?? []), 0, 50) as $group) {
        if (!is_array($group)) continue;
        $groups[] = [
            'code' => sanitize_key((string)($group['code'] ?? '')),
            'receivingWarehouseId' => absint($group['receivingWarehouseId'] ?? 0),
            'supplyFromGroupCode' => sanitize_key((string)($group['supplyFromGroupCode'] ?? '')),
            'leadTimeDays' => lps_purchase_number($group['leadTimeDays'] ?? null, 0, 730),
            'targetDays' => lps_purchase_number($group['targetDays'] ?? null, 1, 730),
            'safetyDays' => lps_purchase_number($group['safetyDays'] ?? null, 0, 365),
        ];
    }
    $mode = lps_purchase_pack_mode($input);
    $multiplier = lps_purchase_number($input['maxDemandMultiplier'] ?? 1.1, 1, 100);
    if ($multiplier === null) throw new InvalidArgumentException(__('Set the demand multiplier between 1 and 100.', 'lavka-price-sync'));
    return ['version' => 2, 'enabled' => !empty($input['enabled']),
        'packRounding' => $mode, 'respectPack' => $mode !== 'NONE',
        'stockoutCorrectionEnabled' => !empty($input['stockoutCorrectionEnabled']), 'maxDemandMultiplier' => $multiplier,
        'allowTransfers' => !empty($input['allowTransfers']), 'groups' => $groups];
}

function lps_purchase_resolve_groups(array $profile, array $configured): array {
    $plan = $profile['purchasePlanning'] ?? [];
    if (empty($plan['enabled']) || empty($plan['groups'])) {
        throw new InvalidArgumentException(__('Enable purchase planning and select destination groups in the scenario.', 'lavka-price-sync'));
    }
    $catalog = array_column($configured, null, 'code');
    $scope = array_map('intval', (array)($profile['context']['warehouseIds'] ?? []));
    $stock_only = lps_analytics_stock_only_ids((array)($profile['calculation'] ?? []), $scope);
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
        if (in_array($group['receivingWarehouseId'], $stock_only, true)) {
            throw new InvalidArgumentException(__('A stock-only warehouse cannot receive a planned order. Select a warehouse with full analytics.', 'lavka-price-sync'));
        }
        if (!in_array($group['receivingWarehouseId'], $current['warehouseIds'], true)
            || $group['leadTimeDays'] === null || $group['targetDays'] === null || $group['safetyDays'] === null) {
            throw new InvalidArgumentException(__('Set a receiving warehouse, lead time, target coverage and safety stock days for every destination group.', 'lavka-price-sync'));
        }
        $resolved[] = array_merge($group, ['packRounding' => lps_purchase_pack_mode($plan),
            'respectPack' => lps_purchase_pack_mode($plan) !== 'NONE',
            'stockoutCorrectionEnabled' => !empty($plan['stockoutCorrectionEnabled']),
            'maxDemandMultiplier' => lps_purchase_number($plan['maxDemandMultiplier'] ?? 1.1, 1, 100) ?? 1.1, 'name' => $current['name'], 'warehouseIds' => $current['warehouseIds'],
            'stockOnlyWarehouseIds' => array_values(array_intersect($current['warehouseIds'], $stock_only)),
            'demandWarehouseIds' => array_values(array_diff($current['warehouseIds'], $stock_only))]);
    }
    $destinations = array_column($resolved, null, 'code');
    foreach ($resolved as $group) {
        $source = $group['supplyFromGroupCode'] ?? '';
        if ($source !== '' && ($source === $group['code'] || !isset($destinations[$source])
            || !empty($destinations[$source]['supplyFromGroupCode']))) {
            throw new InvalidArgumentException(__('Select a different supplier-order group as the replenishment source. Chained routes are not supported.', 'lavka-price-sync'));
        }
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

function lps_purchase_filter_data(array $dimensions): array {
    $group_name = trim((string)($dimensions['groupLevel1Name'] ?? ''));
    $group_code = trim((string)($dimensions['groupLevel1Code'] ?? ''));
    $group_value = $group_code !== '' ? $group_code : $group_name;
    $path = $group_name !== '' ? [$group_name] : [];
    $subgroups = [];
    for ($level = 2; $level <= 6; $level++) {
        $name = trim((string)($dimensions['groupLevel' . $level . 'Name'] ?? ''));
        $code = trim((string)($dimensions['groupLevel' . $level . 'Code'] ?? ''));
        if ($name === '' && $code === '') continue;
        $label = $name !== '' ? $name : $code;
        $path[] = $label;
        $subgroups[] = [
            'value' => $level . ':' . ($code !== '' ? $code : $name),
            'label' => implode(' › ', $path),
        ];
    }
    return [
        'minimumStock' => lps_purchase_number($dimensions['minimumStock'] ?? null, -1000000000),
        'group' => ['value' => $group_value, 'label' => $group_name !== '' ? $group_name : $group_code],
        'subgroups' => $subgroups,
    ];
}

// Operates on Java's confirmed metrics; never reconstructs Folio movements.
function lps_purchase_calculate(array $row, array $groups, int $period_days, bool $allow_transfers, array $edits = [], ?array $transit_ids = null): array {
    $members = array_column((array)($row['warehouseBreakdown'] ?? []), null, 'warehouseId');
    $transit = $row['inTransitStock'] ?? [];
    $transit_ids = $transit_ids ?? lps_purchase_transit_warehouses();
    $confirmed_transit = lps_purchase_transit($transit, $transit_ids);
    $transit_pool = $confirmed_transit['quantity'];
    $network = $row['networkOrderPolicy'] ?? [];
    $result = [];
    $allocated_transit = 0;
    foreach ($groups as $group) {
        $edit = $edits[$group['code']] ?? [];
        $pack_mode = lps_purchase_pack_mode($edit, lps_purchase_pack_mode($group));
        $respect_pack = $pack_mode !== 'NONE';
        $issues = [];
        if ($confirmed_transit['issue']) $issues[] = $confirmed_transit['issue'];
        if ($transit_ids && ($edit['receiptsReviewed'] ?? false) !== true) $issues[] = 'RECEIPTS_REVIEW_REQUIRED';
        if (array_intersect($group['warehouseIds'], $transit_ids)) $issues[] = 'TRANSIT_DESTINATION_OVERLAP';
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
            $stock_only = in_array($id, $group['stockOnlyWarehouseIds'] ?? [], true);
            foreach ($stock_only ? ['physicalQuantity', 'availableQuantity'] : ['physicalQuantity', 'availableQuantity', 'regularSoldUnits', 'returnQuantity'] as $key) {
                if (lps_purchase_number($metrics[$key] ?? null, in_array($key, ['physicalQuantity', 'availableQuantity'], true) ? -1000000000 : 0) === null) $valid = false;
            }
            $physical += (float)($metrics['physicalQuantity'] ?? 0);
            $available += (float)($metrics['availableQuantity'] ?? 0);
            if ($stock_only) continue;
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
        $demand = lps_purchase_demand($sales, $group, $row);
        if ($valid && $eligible && $demand['status'] === 'HISTORY_NOT_READY') $issues[] = 'STOCKOUT_HISTORY_REQUIRED';
        $daily = $valid && $demand['adjustedSales'] !== null ? $demand['adjustedSales'] / $period_days : null;
        $target = $valid && $eligible && $daily !== null ? $daily * ($group['leadTimeDays'] + $group['targetDays'] + $group['safetyDays']) + $reserve : null;
        if ($target !== null && !$unlimited) $target = min($target, $cap);
        $incoming = lps_purchase_number($edit['inTransit'] ?? ((count($groups) === 1 || $transit_pool === 0.0) ? $transit_pool : null));
        $open_orders = lps_purchase_number($edit['openOrders'] ?? null);
        $pack = lps_purchase_number($edit['pack'] ?? ($row['dimensions']['packageQuantity'] ?? null), 0.000001);
        $moq = lps_purchase_number($edit['moq'] ?? ($row['dimensions']['minimumOrderQuantity'] ?? null));
        $allocated_transit += $incoming ?? 0;
        $ready = !$issues && $incoming !== null && $open_orders !== null && (!empty($group['supplyFromGroupCode']) || ((!$respect_pack || $pack !== null) && $moq !== null));
        $position = $available + ($incoming ?? 0) + ($open_orders ?? 0);
        $result[$group['code']] = [
            'groupCode' => $group['code'], 'groupName' => $group['name'], 'warehouseIds' => $group['warehouseIds'],
            'stockOnlyWarehouseIds' => $group['stockOnlyWarehouseIds'] ?? [],
            'demandWarehouseIds' => $group['demandWarehouseIds'] ?? $group['warehouseIds'],
            'receivingWarehouseId' => $group['receivingWarehouseId'],
            'supplyFromGroupCode' => $group['supplyFromGroupCode'] ?? '',
            'requiredTransferOverride' => lps_purchase_number($edit['requiredTransfer'] ?? null),
            'plannedTransferIn' => 0.0, 'plannedTransferOut' => 0.0,
            'physical' => $valid ? $physical : null,
            'available' => $valid ? $available : null, 'regularSales' => $valid ? $sales : null,
            'availability' => lps_purchase_availability($group, $row),
            'returns' => $valid ? $returns : null, 'coverageDays' => $daily > 0 ? max(0, $available) / $daily : null,
            'target' => $target, 'needBeforeReceipts' => $target === null ? null : max(0, $target - $available),
            'inputs' => ['inTransit' => $incoming, 'openOrders' => $open_orders, 'pack' => $pack, 'moq' => $moq],
            'respectPack' => $respect_pack, 'packRounding' => $pack_mode, 'demand' => $demand,
            'receiptsReviewed' => ($edit['receiptsReviewed'] ?? false) === true,
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
    // Destination forecast becomes source demand even when stock must first be purchased.
    // These are planned replenishments, not executable transfers of stock on hand.
    foreach ($result as $code => &$receiver) {
        $source = $receiver['supplyFromGroupCode'];
        if ($source === '') continue;
        if (!isset($result[$source]) || $source === $code || $result[$source]['supplyFromGroupCode'] !== '') {
            $receiver['ready'] = false; $receiver['issues'][] = 'REPLENISHMENT_DEPENDENCY_REQUIRED'; continue;
        }
        $donor = &$result[$source];
        $override = $receiver['requiredTransferOverride'];
        if ($override !== null && (floor($override) !== $override || $receiver['managerReason'] === '')) {
            $receiver['ready'] = false; $receiver['issues'][] = 'TRANSFER_OVERRIDE_INVALID';
        }
        if ($receiver['managerQuantity'] !== null) {
            $receiver['ready'] = false; $receiver['issues'][] = 'ROUTED_PURCHASE_NOT_ALLOWED';
        }
        if (!$receiver['ready'] || !$donor['ready']) {
            $receiver['ready'] = $donor['ready'] = false;
            $receiver['issues'][] = 'REPLENISHMENT_DEPENDENCY_REQUIRED';
            $donor['issues'][] = 'REPLENISHMENT_DEPENDENCY_REQUIRED';
            unset($donor); continue;
        }
        $quantity = $override ?? round(max(0, $receiver['target'] - $receiver['position']), 0, PHP_ROUND_HALF_DOWN);
        $destination = $members[$receiver['receivingWarehouseId']];
        if (($destination['orderPolicy']['maximumStockLimited'] ?? null) === true
            && $quantity + $receiver['inputs']['inTransit'] + $receiver['inputs']['openOrders']
                + (float)$destination['metrics']['availableQuantity'] > (float)$destination['orderPolicy']['maximumStockLimit'] + 0.000001) {
            $receiver['ready'] = $donor['ready'] = false;
            $receiver['issues'][] = 'DESTINATION_MAXIMUM_EXCEEDED';
            $donor['issues'][] = 'REPLENISHMENT_DEPENDENCY_REQUIRED';
        } else {
            $receiver['plannedTransferIn'] = $quantity;
            $donor['plannedTransferOut'] += $quantity;
        }
        unset($donor);
    }
    unset($receiver);
    foreach ($result as &$receiver) {
        $source = $receiver['supplyFromGroupCode'];
        if ($source !== '' && isset($result[$source]) && !$result[$source]['ready']) {
            $receiver['ready'] = false; $receiver['plannedTransferIn'] = 0.0;
            $result[$source]['plannedTransferOut'] = 0.0;
            $receiver['issues'][] = 'REPLENISHMENT_DEPENDENCY_REQUIRED';
        }
    }
    unset($receiver);
    // Reserve each donor's own target. Transfer only stock on hand, never expected receipts.
    if ($allow_transfers) {
        foreach ($result as $to => &$receiver) {
            if (!$receiver['ready'] || $receiver['supplyFromGroupCode'] !== '') continue;
            $need = max(0, $receiver['target'] - $receiver['position'] + $receiver['plannedTransferOut']);
            $destination = $members[$receiver['receivingWarehouseId']];
            if (($destination['orderPolicy']['maximumStockLimited'] ?? null) === true) {
                $headroom = max(0, (float)$destination['orderPolicy']['maximumStockLimit']
                    - (float)$destination['metrics']['availableQuantity'] - $receiver['inputs']['inTransit'] - $receiver['inputs']['openOrders']);
                $need = min($need, $headroom);
            }
            foreach ($result as $from => &$donor) {
                if ($from === $to || !$donor['ready'] || $need <= 0) continue;
                $surplus = max(0, $donor['available'] - $donor['target'] - $donor['transferOut'] - $donor['plannedTransferOut']);
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
            $need = max(0, $item['target'] - $item['position'] - $item['transferIn'] + $item['transferOut'] - $item['plannedTransferIn'] + $item['plannedTransferOut']);
            $item['recommendedQuantity'] = $item['supplyFromGroupCode'] !== '' ? 0.0 : lps_purchase_rounded_quantity($need, $item['inputs']['moq'], $item['inputs']['pack'], $item['packRounding']);
            $destination = $members[$item['receivingWarehouseId']];
            $policy = $destination['orderPolicy'];
            $quantity = $item['managerQuantity'] ?? $item['recommendedQuantity'];
            if (($policy['maximumStockLimited'] ?? null) === true && $quantity + $item['inputs']['inTransit'] + $item['inputs']['openOrders'] + $item['transferIn'] + (float)$destination['metrics']['availableQuantity'] > (float)$policy['maximumStockLimit'] + 0.000001) {
                $item['issues'][] = 'DESTINATION_MAXIMUM_EXCEEDED';
                $item['recommendedQuantity'] = null;
            }
            if ($quantity > 0 && ($quantity + 0.000001 < $item['inputs']['moq'] || ($item['respectPack'] && abs($quantity / $item['inputs']['pack'] - round($quantity / $item['inputs']['pack'])) > 0.000001))) $item['issues'][] = 'PACK_OR_MOQ_VIOLATION';
        } else $item['issues'][] = 'SUPPLY_INPUTS_REQUIRED';
        if ($item['managerQuantity'] !== null && $item['managerReason'] === '') $item['issues'][] = 'MANAGER_REASON_REQUIRED';
        $chosen = $item['managerQuantity'] ?? $item['recommendedQuantity'];
        if ($item['plannedTransferOut'] > 0 && $chosen !== null
            && $item['position'] + $chosen + $item['transferIn'] - $item['transferOut'] + 0.000001 < $item['plannedTransferOut']) {
            $item['issues'][] = 'PLANNED_REPLENISHMENT_UNCOVERED';
        }
        $item['finalQuantity'] = !$item['issues'] ? ($item['managerQuantity'] ?? $item['recommendedQuantity']) : null;
        $item['status'] = $item['finalQuantity'] === null ? 'REVIEW_REQUIRED' : 'PREVIEW_READY';
        unset($item['position'], $item['ready']);
    }
    unset($item);
    foreach ($result as &$item) {
        $source = $item['supplyFromGroupCode'];
        if ($source !== '' && isset($result[$source]) && $result[$source]['finalQuantity'] === null) {
            $item['issues'][] = 'REPLENISHMENT_DEPENDENCY_REQUIRED';
            $item['finalQuantity'] = null; $item['status'] = 'REVIEW_REQUIRED';
        }
        $item['issues'] = array_values(array_unique($item['issues']));
    }
    unset($item);
    return ['sku' => $row['sku'] ?? '', 'productName' => $row['productName'] ?? '',
        'filterData' => lps_purchase_filter_data((array)($row['dimensions'] ?? [])),
        'supplierPrices' => $row['supplierPrices'] ?? [],
        'supplier' => $row['dimensions']['currentSuppliers'] ?? [], 'transitPool' => $transit_pool,
        'transitStatus' => $confirmed_transit['status'], 'transitWarehouseIds' => $transit_ids,
        'transitGenerationId' => $transit['generationId'] ?? null,
        'transitConsistency' => $transit['networkSnapshotConsistency'] ?? null,
        'supplierTransitQuantity' => $transit['supplierInTransitAvailableQuantity'] ?? null,
        'transitSources' => $transit['sources'] ?? ($transit ? [$transit] : []),
        'transitWarnings' => $transit['warnings'] ?? [], 'groups' => array_values($result)];
}
