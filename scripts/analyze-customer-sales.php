<?php
/**
 * Read-only, anonymous Woo sales analysis. Run with WP-CLI eval-file.
 * Environment: LCA_REPORT_FROM / LCA_REPORT_TO (inclusive YYYY-MM-DD), max 5000 rows.
 * No customer IDs, names, contacts, addresses, user agents or order keys in output.
 */
if (!defined('WP_CLI') || !WP_CLI || !function_exists('wc_get_orders')) { exit(1); }
$from = getenv('LCA_REPORT_FROM') ?: '2026-07-01';
$to = getenv('LCA_REPORT_TO') ?: '2026-10-10';
foreach ([$from, $to] as $date) {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !strtotime($date)) { WP_CLI::error('Invalid report date'); }
}
if ($from > $to) { WP_CLI::error('Invalid date range'); }
$orders = [];
$statuses = array_keys(wc_get_order_statuses());
if (!in_array('wc-pc-draft', $statuses, true)) { $statuses[] = 'wc-pc-draft'; }
for ($page = 1; $page <= 51; $page++) {
    $batch = wc_get_orders(['limit' => 100, 'page' => $page, 'status' => $statuses,
        'date_created' => $from . '...' . $to, 'orderby' => 'ID', 'order' => 'ASC', 'type' => 'shop_order']);
    if (!$batch) { break; }
    if ($page === 51) { WP_CLI::error('Over 5000 order rows: shorten the report period'); }
    foreach ($batch as $order) { $orders[$order->get_id()] = $order; }
}
$groups = [];
foreach ($orders as $order) {
    $root = (int) ($order->get_meta('_folio_parent_order_id') ?: $order->get_meta('_folio_split_from_order_id'));
    $groups[$root ?: $order->get_id()][] = $order;
}
$report = ['period' => [$from, $to], 'generated_at' => gmdate('c'), 'order_rows' => count($orders),
    'commercial_groups' => 0, 'draft_only_groups' => 0, 'root_outside_period_groups' => 0,
    'status_rows' => [], 'segments' => [], 'devices' => [], 'sources' => [], 'regions' => [],
    'payment_evidence' => [], 'products' => [], 'pairs' => [], 'products_by_segment' => [], 'segment_devices' => []];
$product_counts = [];
$segment_products = [];
$pair_counts = [];
$segment_statuses = ['pending', 'on-hold', 'processing', 'completed', 'failed', 'cancelled', 'refunded'];
$accepted = ['on-hold', 'processing', 'completed', 'refunded'];
$rollup = static function (&$target, $key, $total, $accepted_total) {
    if (!isset($target[$key])) { $target[$key] = ['groups' => 0, 'accepted_groups' => 0, 'accepted_net_order_total' => 0]; }
    $target[$key]['groups']++;
    if ($accepted_total) { $target[$key]['accepted_groups']++; $target[$key]['accepted_net_order_total'] += $total; }
};
foreach ($groups as $root_id => $rows) {
    $root = $orders[$root_id] ?? wc_get_order($root_id);
    if (!$root) { $root = $rows[0]; }
    if (!isset($orders[$root_id])) { $report['root_outside_period_groups']++; }
    $children = array_values(array_filter($rows, static fn($o) => $o->get_id() !== $root_id));
    $rows = $children ?: [$root]; // Never add informational parent totals to child totals.
    $commercial = array_values(array_filter($rows, static fn($o) => in_array($o->get_status(), $segment_statuses, true)));
    if (!$commercial) { $report['draft_only_groups']++; continue; }
    $report['commercial_groups']++;
    $segment = $root->get_meta('_lca_segment');
    if (!in_array($segment, ['retail', 'wholesale', 'staff'], true)) {
        $user = $root->get_customer_id() ? get_userdata($root->get_customer_id()) : null;
        $roles = $user ? (array) $user->roles : [];
        $segment = array_intersect(['administrator', 'shop_manager'], $roles) ? 'staff_current_role' :
            (array_intersect(['opt', 'partner', 'opt_osn', 'schule'], $roles) ? 'wholesale_current_role' : 'retail_or_guest');
    }
    $device = strtolower((string) ($root->get_meta('_lca_device') ?: $root->get_meta('_wc_order_attribution_device_type')));
    $device = in_array($device, ['mobile', 'tablet', 'desktop'], true) ? $device : 'unknown';
    $source = (string) ($root->get_meta('_lca_source') ?: $root->get_meta('_wc_order_attribution_utm_source'));
    $source_type = (string) $root->get_meta('_wc_order_attribution_source_type');
    if ($source === '(direct)' || $source_type === 'typein') { $source = 'direct'; }
    if ($source_type === 'admin') { $source = 'admin'; }
    // Public campaign labels only; free-form or PII-like source strings suppressed.
    $source = preg_match('/^[a-zA-Z0-9._-]{1,60}$/', $source) ? strtolower($source) : 'unknown';
    $region = $root->get_billing_country() . ':' . $root->get_billing_state();
    $region = preg_match('/^[A-Z]{2}:[A-Za-z0-9_-]{0,8}$/', $region) ? $region : 'unknown';
    $total = 0; $has_accepted = false; $items = [];
    $currencies = [];
    foreach ($commercial as $order) {
        $status = $order->get_status();
        $report['status_rows'][$status] = ($report['status_rows'][$status] ?? 0) + 1;
        $evidence = $order->get_meta('_lca_payment_confirmed_at') ? 'payment_complete_hook' :
            ($order->get_transaction_id() && !in_array($order->get_payment_method(), ['bacs', 'cod', 'cheque'], true)
                ? 'gateway_transaction_present_needs_reconciliation' : 'no_confirmed_payment_evidence');
        $report['payment_evidence'][$evidence] = ($report['payment_evidence'][$evidence] ?? 0) + 1;
        if (!in_array($status, $accepted, true)) { continue; }
        $has_accepted = true;
        $currency = $order->get_currency();
        $currencies[$currency] = true;
        $net_total = (float) $order->get_total() - (float) $order->get_total_refunded();
        $report['currency_totals'][$currency] = ($report['currency_totals'][$currency] ?? 0) + $net_total;
        $total += $net_total;
        if ($status === 'refunded') { continue; }
        foreach ($order->get_items('line_item') as $line_id => $line) {
            if (str_starts_with($segment, 'staff')) { continue; }
            $qty = max(0, (float) $line->get_quantity() + (float) $order->get_qty_refunded_for_item($line_id));
            if ($qty <= 0) { continue; }
            $product = $line->get_product();
            $sku = $product ? $product->get_sku() : '';
            $key = $sku ?: 'woo:' . ($line->get_variation_id() ?: $line->get_product_id());
            if (!$sku && !$line->get_product_id()) { continue; } // Do not export free-form custom lines.
            $item_key = $currency . '|' . $key;
            if (!isset($product_counts[$item_key])) {
                $product_counts[$item_key] = ['sku' => $key, 'currency' => $currency, 'name' => $product ? $product->get_name() : '(deleted product)',
                    'groups' => 0, 'quantity' => 0, 'net_item_value_ex_tax' => 0];
            }
            $product_counts[$item_key]['quantity'] += $qty;
            $product_counts[$item_key]['net_item_value_ex_tax'] += (float) $line->get_total() - (float) $order->get_total_refunded_for_item($line_id);
            if (!isset($segment_products[$segment][$item_key])) {
                $segment_products[$segment][$item_key] = array_merge($product_counts[$item_key], ['groups' => 0, 'quantity' => 0, 'net_item_value_ex_tax' => 0]);
            }
            $segment_products[$segment][$item_key]['quantity'] += $qty;
            $segment_products[$segment][$item_key]['net_item_value_ex_tax'] += (float) $line->get_total() - (float) $order->get_total_refunded_for_item($line_id);
            $items[$item_key] = true;
        }
    }
    // Prevent mixed-currency sums. Such groups are counted with amount zero and explicitly flagged.
    if (count($currencies) > 1) { $report['mixed_currency_groups'] = ($report['mixed_currency_groups'] ?? 0) + 1; $total = 0; }
    $currency_key = count($currencies) === 1 ? array_key_first($currencies) : 'unknown';
    foreach (['segments' => $segment, 'devices' => $device, 'sources' => $source, 'regions' => $region] as $field => $key) {
        $rollup($report[$field], $currency_key . '|' . $key, $total, $has_accepted);
    }
    $rollup($report['segment_devices'], $currency_key . '|' . $segment . '|' . $device, $total, $has_accepted);
    $keys = array_keys($items); sort($keys, SORT_STRING);
    foreach ($keys as $key) { $product_counts[$key]['groups']++; $segment_products[$segment][$key]['groups']++; }
    // Large wholesale baskets generate meaningless quadratic combinations; expose the exclusion.
    if (count($keys) > 80) { $report['pair_large_basket_exclusions'] = ($report['pair_large_basket_exclusions'] ?? 0) + 1; continue; }
    for ($i = 0; $i < count($keys); $i++) {
        for ($j = $i + 1; $j < count($keys); $j++) {
            $key = $segment . "\t" . $keys[$i] . "\t" . $keys[$j];
            $pair_counts[$key] = ($pair_counts[$key] ?? 0) + 1;
        }
    }
}
usort($product_counts, static fn($a, $b) => $b['groups'] <=> $a['groups'] ?: $b['net_item_value_ex_tax'] <=> $a['net_item_value_ex_tax']);
$report['products'] = array_slice($product_counts, 0, 30);
foreach ($segment_products as $segment => $products) {
    usort($products, static fn($a, $b) => $b['groups'] <=> $a['groups'] ?: $b['net_item_value_ex_tax'] <=> $a['net_item_value_ex_tax']);
    $report['products_by_segment'][$segment] = array_slice($products, 0, 20);
}
arsort($pair_counts);
foreach (array_slice($pair_counts, 0, 20, true) as $key => $n) {
    [$segment, $a, $b] = explode("\t", $key);
    $report['pairs'][] = ['segment' => $segment, 'product_a' => $a, 'product_b' => $b, 'groups' => $n, 'interpretation' => $n < 5 ? 'small_sample_hypothesis' : 'test_bundle_not_causality'];
}
$report['limitations'] = [
    'Accepted orders are demand, not paid revenue or Folio shipments; failed/pending orders excluded from product ranking.',
    'Split parents are not added to child totals; groups with roots outside the period are separately flagged.',
    'Segments without saved analytics metadata use CURRENT user role, not historical role at order time.',
    'Device/source are order attribution, not all site visitors: cannot infer checkout losses or device conversion.',
    'Region is billing country/state, NOT IP geolocation or actual location; missing fields stay unknown.',
    'Woo date_paid/processing alone never proves money received; offline settlement requires ledger reconciliation.',
    'Co-purchases are hypotheses; small samples and large wholesale baskets require separate validation.',
];
echo wp_json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
