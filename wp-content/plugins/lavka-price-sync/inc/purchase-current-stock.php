<?php
if (!defined('ABSPATH')) exit;
const LPS_CURRENT_NETWORK_STOCK_PATH = '/admin/folio/product-analytics/current-network-stock';

/** Accept the whole source cut before changing any rows; history stays immutable. */
function lps_purchase_apply_current_stock(array $state, array $capture): array {
    $ids = array_values(array_unique(array_merge($state['query']['warehouseIds'], $state['transitWarehouseIds'], [7]))); sort($ids, SORT_NUMERIC);
    $analysis_ids = $state['query']['warehouseIds']; sort($analysis_ids, SORT_NUMERIC);
    $failure = static function (): void { throw new RuntimeException(__('The current network stock read is incomplete or changed its scope. Start a new preview.', 'lavka-price-sync')); };
    if (($capture['ok'] ?? null) !== true || ($capture['version'] ?? null) !== 1
        || !is_string($capture['captureId'] ?? null) || !preg_match('/^[a-f0-9-]{36}$/D', $capture['captureId'])
        || ($capture['sourceDatabase'] ?? '') !== $state['query']['sourceDatabase'] || ($capture['method'] ?? '') !== 'FOLIO_SERIALIZABLE_READ'
        || ($capture['warehouseIds'] ?? null) !== $ids || ($capture['analysisWarehouseIds'] ?? null) !== $analysis_ids
        || empty($capture['capturedAt']) || !is_array($capture['cards'] ?? null)
        || ($capture['assemblyGraph']['revision'] ?? null) !== $state['assemblyGraph']['revision']) $failure();
    $nodes = array_fill_keys(array_map('lps_assembly_key', array_column($state['assemblyGraph']['nodes'], 'sku')), true);
    $cards = [];
    foreach ($capture['cards'] as $card) {
        $sku = lps_assembly_key((string)($card['sku'] ?? '')); $id = $card['warehouseId'] ?? null;
        if (!isset($nodes[$sku]) || !in_array($id, $ids, true) || isset($cards[$sku][$id])
            || !in_array($card['status'] ?? '', ['CAPTURED', 'SKU_NOT_PRESENT', 'INVALID_STOCK', 'INTERNAL_TRANSFER_RESERVE_MISMATCH'], true)
            || !is_array($card['internalTransferAccounts'] ?? null)) $failure();
        if ($card['status'] === 'CAPTURED') {
            $physical = lps_purchase_number($card['physicalQuantity'] ?? null, -1000000000);
            $free = lps_purchase_number($card['availableQuantity'] ?? null, -1000000000);
            $reserved = lps_purchase_number($card['reservedQuantity'] ?? null);
            if ($physical === null || $free === null || $reserved === null || abs($physical - $free - $reserved) > 0.000001
                || !is_array($card['orderPolicy'] ?? null)) $failure();
            $sum = 0.0; $seen = [];
            foreach ($card['internalTransferAccounts'] as $account) {
                $doc = $account['documentId'] ?? null; $quantity = lps_purchase_number($account['quantity'] ?? null, 0.000001);
                if (($account['sourceWarehouseId'] ?? null) !== $id || !is_int($doc) || $doc <= 0 || isset($seen[$doc]) || $quantity === null) $failure();
                $seen[$doc] = true; $sum += $quantity;
            }
            if ($sum > $reserved + 0.000001) $failure();
        }
        $cards[$sku][$id] = $card;
    }
    foreach ($nodes as $sku => $_) if (!isset($cards[$sku]) || count($cards[$sku]) !== count($ids)) $failure();
    $proof = array_intersect_key($capture, array_flip(['version', 'captureId', 'sourceDatabase', 'method', 'startedAt', 'capturedAt', 'warehouseIds', 'analysisWarehouseIds']));
    foreach ($state['rows'] as $sku => &$row) {
        $current = $cards[lps_assembly_key((string)$sku)] ?? null; if ($current === null) $failure();
        $row['currentStockCapture'] = $proof;
        $members = array_column($row['warehouseBreakdown'] ?? [], null, 'warehouseId'); $accounts = []; $internal_ready = true;
        foreach ($analysis_ids as $id) {
            $card = $current[$id]; $ready = $card['status'] === 'CAPTURED';
            $member = $members[$id] ?? ['warehouseId' => $id, 'metrics' => []];
            $member['snapshotStockMetrics'] = array_intersect_key($member['metrics'] ?? [], array_flip(['physicalQuantity', 'availableQuantity', 'reservedQuantity']));
            foreach (['physicalQuantity', 'availableQuantity', 'reservedQuantity'] as $key) $member['metrics'][$key] = $ready ? $card[$key] : null;
            $member['orderPolicy'] = $card['orderPolicy']; $member['currentStockStatus'] = $card['status']; $member['currentStockCaptureId'] = $capture['captureId'];
            $members[$id] = $member; $internal_ready = $internal_ready && $ready;
            foreach ($card['internalTransferAccounts'] as $account) $accounts[] = $account + ['captureId' => $capture['captureId']];
        }
        $row['warehouseBreakdown'] = array_values($members);
        $row['internalTransferReservations'] = ['calculationVersion' => 2, 'status' => $internal_ready ? 'CAPTURED' : 'CURRENT_STOCK_NOT_READY', 'captureId' => $capture['captureId'], 'accounts' => $accounts];
        $policy = $current[7]['orderPolicy'];
        $row['networkOrderPolicy'] = ['policyWarehouseId' => 7, 'captureId' => $capture['captureId'], 'policy' => $policy,
            'orderAllowed' => $policy['orderAllowed'] ?? null,
            'status' => ($policy['orderAllowed'] ?? null) === true && ($policy['validationState'] ?? '') === 'VALID' ? 'ALLOWED' : 'CURRENT_POLICY_REVIEW_REQUIRED'];
        $historical_names = array_column($row['inTransitStock']['sources'] ?? [], 'warehouseName', 'warehouseId');
        $sources = []; $total = 0.0; $transit_ready = true;
        foreach ($state['transitWarehouseIds'] as $id) {
            $card = $current[$id]; $quantity = null;
            if ($card['status'] === 'CAPTURED' && (float)$card['physicalQuantity'] >= 0) $quantity = max(0.0, (float)$card['availableQuantity'] + array_sum(array_column($card['internalTransferAccounts'], 'quantity')));
            else $transit_ready = false;
            $total += $quantity ?? 0; $sources[] = $card + ['warehouseName' => $historical_names[$id] ?? (string)$id, 'completedAt' => $capture['capturedAt'], 'captureId' => $capture['captureId'], 'availableForNetworkPlanningQuantity' => $quantity];
        }
        $row['inTransitStock'] = ['calculationVersion' => 4, 'enabled' => (bool)$state['transitWarehouseIds'],
            'warehouseIds' => $state['transitWarehouseIds'], 'configurationRevision' => hash('sha256', json_encode($state['transitWarehouseIds'])),
            'captureId' => $capture['captureId'], 'sources' => $sources,
            'networkPlanningReady' => $transit_ready, 'availableForNetworkPlanningQuantity' => $transit_ready ? $total : null,
            'networkPlanningStatus' => $transit_ready ? 'CURRENT_NETWORK_STOCK_CAPTURED' : 'INCOMPLETE_TRANSIT_DATA',
            'networkSnapshotConsistency' => $proof + ['confirmed' => true, 'status' => 'CURRENT_NETWORK_STOCK_CAPTURED']];
    }
    unset($row); $state['currentStockCapture'] = $proof;
    return $state;
}

function lps_purchase_capture_current_stock(array $state): array {
    if (!$state['rootSkus']) return $state;
    $ids = array_values(array_unique(array_merge($state['query']['warehouseIds'], $state['transitWarehouseIds'], [7]))); sort($ids, SORT_NUMERIC);
    $capture = lps_product_analytics_v4_request_java(LPS_CURRENT_NETWORK_STOCK_PATH, [
        'sourceDatabase' => $state['query']['sourceDatabase'], 'warehouseIds' => $ids,
        'analysisWarehouseIds' => $state['query']['warehouseIds'], 'rootSkus' => $state['rootSkus'],
    ]);
    if (is_wp_error($capture)) throw new RuntimeException($capture->get_error_message());
    return lps_purchase_apply_current_stock($state, $capture);
}
