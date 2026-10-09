<?php
if (!defined('ABSPATH')) exit;

const LPS_ASSEMBLY_PATH = '/admin/folio/product-analytics/assembly-graph';

function lps_assembly_key(string $sku): string { return mb_strtoupper(trim($sku), 'UTF-8'); }

function lps_purchase_assembly_graph(array $state): array {
    if (!$state['rootSkus']) return ['version' => 2, 'revision' => 'empty', 'nodes' => [], 'edges' => []];
    $graph = lps_product_analytics_v4_request_java(LPS_ASSEMBLY_PATH, [
        'sourceDatabase' => $state['query']['sourceDatabase'], 'warehouseIds' => $state['query']['warehouseIds'],
        'rootSkus' => $state['rootSkus'],
    ]);
    if (is_wp_error($graph)) throw new RuntimeException($graph->get_error_message());
    if (($graph['version'] ?? 0) !== 2 || empty($graph['revision']) || !is_array($graph['nodes'] ?? null)
        || !is_array($graph['edges'] ?? null) || count($graph['nodes']) > LPS_PURCHASE_MAX_SKUS) {
        throw new RuntimeException(__('Assembly recipes are unavailable or incomplete. Update Java and start a new preview.', 'lavka-price-sync'));
    }
    $nodes = [];
    foreach ($graph['nodes'] as $node) {
        $key = lps_assembly_key((string)($node['sku'] ?? ''));
        if ($key === '' || isset($nodes[$key]) || !is_bool($node['manufactured'] ?? null) || !is_array($node['issues'] ?? null))
            throw new RuntimeException(__('Assembly recipes are unavailable or incomplete. Update Java and start a new preview.', 'lavka-price-sync'));
        $nodes[$key] = true;
    }
    foreach ($state['rootSkus'] as $sku) if (!isset($nodes[lps_assembly_key($sku)]))
        throw new RuntimeException(__('Assembly recipes are unavailable or incomplete. Update Java and start a new preview.', 'lavka-price-sync'));
    foreach ($graph['edges'] as $edge) if (!isset($nodes[lps_assembly_key((string)($edge['child'] ?? ''))]))
        throw new RuntimeException(__('Assembly recipes are unavailable or incomplete. Update Java and start a new preview.', 'lavka-price-sync'));
    return $graph;
}

/** Evaluate each product once, in reverse topological order. Stock is netted at each node. */
function lps_purchase_calculate_network(array $state): array {
    $nodes = $raw = $children = $parents = $remaining = $results = $components = [];
    $roots = array_fill_keys(array_map('lps_assembly_key', $state['rootSkus']), true);
    foreach ($state['rows'] as $sku => $row) $raw[lps_assembly_key((string)$sku)] = $row;
    foreach ($state['assemblyGraph']['nodes'] as $node) {
        $key = lps_assembly_key($node['sku']); $nodes[$key] = $node; $remaining[$key] = 0;
    }
    foreach ($state['assemblyGraph']['edges'] as $edge) {
        $parent = lps_assembly_key($edge['parent']); $child = lps_assembly_key($edge['child']);
        $components[$child][] = $edge;
        if (!isset($nodes[$parent], $nodes[$child])) continue; // Co-components outside the supplier closure are not demand roots.
        $children[$parent][] = $edge;
        $parents[$child][] = $parent;
        $remaining[$parent]++;
    }
    $queue = [];
    foreach ($remaining as $key => $count) if ($count === 0) $queue[] = $key;
    $evaluate = static function (string $key, bool $cycle) use (&$results, $state, $nodes, $raw, $roots, $children, $components): array {
        $node = $nodes[$key];
        $row = $raw[$key] ?? ['sku' => $node['sku'], 'productName' => $node['sku']];
        $issues = $node['issues'];
        $groups = $state['groups'];
        $cold_status = $node['coldStatus'] ?? 'UNKNOWN';
        $coverage = $state['coldCoverage'] ?? [];
        $target_days = null;
        if (!empty($coverage['enabled'])) {
            $target_days = $cold_status === 'FREEZES' ? ($coverage['freezingTargetDays'] ?? null)
                : ($cold_status === 'NON_FREEZING' ? ($coverage['nonFreezingTargetDays'] ?? null) : null);
            $target_days = lps_purchase_number($target_days, 1, 730);
            if ($target_days === null) $issues[] = 'COLD_CLASSIFICATION_REQUIRED';
            else foreach ($groups as &$group) $group['targetDays'] = $target_days;
            unset($group);
        }
        if ($cycle) $issues[] = 'ASSEMBLY_CYCLE';
        if (!isset($raw[$key])) $issues[] = 'ASSEMBLY_CHILD_DATA_REQUIRED';
        $supplier = $row['dimensions']['currentSuppliers'] ?? [];
        $purchasable = isset($roots[$key]) && !$node['manufactured'] && count($supplier) === 1
            && lps_assembly_key((string)$supplier[0]) === lps_assembly_key($state['orderSupplier']);
        if (isset($roots[$key]) && !$node['manufactured'] && !$purchasable) $issues[] = 'SUPPLIER_NOT_CONFIRMED';
        $assembly = ['manufactured' => $node['manufactured'], 'consumption' => [], 'contributions' => [], 'issues' => []];
        foreach ($groups as $group) {
            $code = $group['code']; $assembly['issues'][$code] = $issues;
            $known = true; $total = 0.0;
            foreach ($children[$key] ?? [] as $edge) {
                $child = lps_assembly_key($edge['child']);
                $child_groups = array_column($results[$child]['groups'] ?? [], null, 'groupCode');
                $child_group = $child_groups[$code] ?? [];
                $factor = lps_purchase_number($edge['factor'] ?? null, 0.000000000001);
                $need = $child_group['manufacturingNeed'] ?? null;
                $consumption = $factor !== null && $need !== null ? $need * $factor : null;
                if ($consumption !== null && (!is_finite($consumption) || $consumption > 1000000000)) $consumption = null;
                if ($consumption === null || !empty($child_group['issues'])) { $assembly['issues'][$code][] = 'ASSEMBLY_DEPENDENCY_REQUIRED'; $known = false; }
                $total += $consumption ?? 0.0;
                $assembly['contributions'][$code][] = [
                    'sku' => $edge['child'], 'factor' => $factor, 'manufacturingNeed' => $need, 'consumption' => $consumption,
                    'source' => $edge['source'], 'rowId' => $edge['rowId'],
                ];
            }
            if (!is_finite($total) || $total > 1000000000) { $known = false; $assembly['issues'][$code][] = 'ASSEMBLY_INVALID_RECIPE'; }
            $assembly['consumption'][$code] = $known ? $total : null;
        }
        $result = lps_purchase_calculate($row, $groups, $state['periodDays'], $state['allowTransfers'],
            $state['edits'][$row['sku']] ?? [], $state['transitWarehouseIds'], $assembly);
        $result['coldCoverage'] = ['enabled' => !empty($coverage['enabled']), 'status' => $cold_status, 'targetDays' => $target_days];
        $result['assembly'] = ['manufactured' => $node['manufactured'], 'selected' => isset($roots[$key]), 'purchasable' => $purchasable,
            'components' => $components[$key] ?? []];
        return $result;
    };
    for ($i = 0; $i < count($queue); $i++) {
        $key = $queue[$i]; $results[$key] = $evaluate($key, false);
        foreach ($parents[$key] ?? [] as $parent) if (--$remaining[$parent] === 0) $queue[] = $parent;
    }
    // A cycle blocks itself and every ancestor; unrelated branches remain usable.
    foreach ($nodes as $key => $_) if (!isset($results[$key])) $results[$key] = $evaluate($key, true);
    $ordered = [];
    foreach ($state['rootSkus'] as $sku) $ordered[lps_assembly_key($sku)] = $results[lps_assembly_key($sku)];
    return array_values($ordered + $results);
}
