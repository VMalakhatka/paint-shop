<?php
/**
 * PaintCore – Unified Stock Allocation & Reduction
 *
 * План списання береться з `_pc_alloc_plan` (новий свічер у шапці) і
 * Реальне списання робимо ОДИН раз на статусах processing/completed.
 */

namespace PaintCore\Stock;
defined('ABSPATH') || exit;

const PC_STOCK_REDUCED_META = '_pc_stock_reduced';

/* ---------- per-term stock helpers ---------- */

// paint-core/inc/order-allocator.php (глобальный ns)
if (!function_exists(__NAMESPACE__ . '\\pc_compute_and_stamp_item_plan')) {
        function pc_compute_and_stamp_item_plan(\WC_Order_Item_Product $item, \WC_Product $product, int $need, string $ctx = 'auto'): void {
        $need = max(0, $need);
        if ($need === 0) return;

        // 1) внешний фильтр — даём шанс переопределить
        $plan = (array) apply_filters('slu_allocation_plan', [], $product, $need, $ctx);

        // 2) наш калькулятор
        if (empty($plan) && function_exists('pc_calc_plan_for')) {
            $plan = (array) \pc_calc_plan_for($product, $need);
        }
        error_log(sprintf('pc(plan): ctx=%s pid=%d need=%d plan=%s',
            $ctx, $product->get_id(), $need, json_encode($plan, JSON_UNESCAPED_UNICODE)));
        if (!empty($plan) && function_exists(__NAMESPACE__ . '\\stamp_item_plan')) {
            stamp_item_plan($item, $plan);
        }
    }
}

function read_term_stock(int $product_id, int $term_id): float {
    $key = '_stock_at_' . (int)$term_id;
    $v = get_post_meta($product_id, $key, true);
    if ($v === '' || $v === null) {
        $parent = (int) wp_get_post_parent_id($product_id);
        if ($parent) $v = get_post_meta($parent, $key, true);
    }
    return (float) (($v === '' || $v === null) ? 0 : $v);
}
function add_term_stock(int $product_id, int $term_id, float $delta): void {
    $key = '_stock_at_' . (int)$term_id;
    $cur = (float) get_post_meta($product_id, $key, true);
    $new = max(0.0, $cur + (float)$delta);
    update_post_meta($product_id, $key, wc_format_decimal($new, 3));
}
function recalc_total_stock(int $product_id): void {
    global $wpdb;
    $rows = (array) $wpdb->get_col( $wpdb->prepare(
        "SELECT meta_value FROM {$wpdb->postmeta}
         WHERE post_id=%d AND meta_key LIKE %s",
        $product_id, $wpdb->esc_like('_stock_at_') . '%'
    ) );
    $sum = 0.0; foreach ($rows as $v) $sum += (float)$v;
    update_post_meta($product_id, '_stock', wc_format_decimal($sum, 3));
    update_post_meta($product_id, '_stock_status', ($sum > 0 ? 'instock' : 'outofstock'));
    if (function_exists('wc_delete_product_transients')) wc_delete_product_transients($product_id);
}

/* ---------- plan read/build ---------- */

function ensure_item_plan(\WC_Order_Item_Product $item): array {
    // 1) читаем только новый ключ
    $plan = \pc_get_order_item_plan($item);
    if (!empty($plan)) return $plan;

    // 2) если плана нет — считаем (фильтр -> наш калькулятор)
    $product = $item->get_product();
    $need    = (int) $item->get_quantity();
    if (!($product instanceof \WC_Product) || $need <= 0) return [];

    // сначала внешний фильтр — даём шанс переопределить всё
    $plan = (array) apply_filters('slu_allocation_plan', [], $product, $need, 'order-fallback');

    // затем наш централизованный калькулятор (из header-allocation-switcher.php)
    if (empty($plan) && function_exists('pc_calc_plan_for')) {
        $plan = (array) \pc_calc_plan_for($product, $need);
    }

    // нормализация
    $norm = [];
    foreach ((array)$plan as $tid => $q) {
        $tid = (int)$tid; $q = (int)$q;
        if ($tid > 0 && $q > 0) $norm[$tid] = $q;
    }
    if (!empty($norm) && function_exists(__NAMESPACE__ . '\\stamp_item_plan')) {
        stamp_item_plan($item, $norm);
    }

    return $norm;
}

function stamp_item_plan(\WC_Order_Item_Product $item, array $plan): void {
    if (empty($plan)) return;
    arsort($plan, SORT_NUMERIC);

    $dict = [];
    $terms = get_terms(['taxonomy' => 'location', 'hide_empty' => false]);
    if (!is_wp_error($terms)) foreach ($terms as $t) $dict[(int)$t->term_id] = $t;

    $parts = [];
    $first_id = null; $first_slug = null;

    foreach ($plan as $tid => $qty) {
        $t = $dict[(int)$tid] ?? null;
        $name = $t ? $t->name : ('#' . (int)$tid);
        $slug = $t ? $t->slug : (string)$tid;
        $parts[] = sprintf('%s × %d', $name, (int)$qty);
        if ($first_id === null) { $first_id = (int)$tid; $first_slug = $slug; }
    }

    $item->update_meta_data('_pc_alloc_plan',       $plan);
    if ($first_id) {
        $item->update_meta_data('_stock_location_id',   $first_id);
        $item->update_meta_data('_stock_location_slug', $first_slug);
    }
    $item->save();
}

function build_allocation_plan_for_order($order_or_id): void {
    $order = ($order_or_id instanceof \WC_Order) ? $order_or_id : wc_get_order($order_or_id);
    if (!$order) return;
    foreach ($order->get_items('line_item') as $item) {
        if (!$item instanceof \WC_Order_Item_Product) continue;
        $plan = ensure_item_plan($item);
        if (!empty($plan)) stamp_item_plan($item, $plan);
    }
}

/* штамп мет у рядках замовлення одразу після створення (без списань) */
add_action('woocommerce_new_order', __NAMESPACE__.'\\build_allocation_plan_for_order', 5);

/* ---------- reduction (one time on processing/completed) ---------- */

function reduce_stock_from_plan($order_or_id): void {
    $order = ($order_or_id instanceof \WC_Order) ? $order_or_id : wc_get_order($order_or_id);
    if (!$order) return;
    if ($order->get_meta(PC_STOCK_REDUCED_META)) return;

    // >>> разрешаем записи на время нашей процедуры
    $GLOBALS['PC_ALLOW_STOCK_WRITE'] = true;

    $touched = [];
    foreach ($order->get_items('line_item') as $item) {
        if (!$item instanceof \WC_Order_Item_Product) continue;
        $product = $item->get_product(); if (!$product) continue;
        $pid = (int) $product->get_id();

        $plan = ensure_item_plan($item);
        if (empty($plan)) continue;

        foreach ($plan as $term_id => $qty) {
            if ($qty <= 0) continue;
            add_term_stock($pid, (int)$term_id, -(float)$qty);
            $touched[$pid] = true;
        }
    }
    foreach (array_keys($touched) as $pid) recalc_total_stock((int)$pid);
    if ($touched) {
        $order->update_meta_data(PC_STOCK_REDUCED_META, 'yes');
        $order->save();
    }

    // <<< закрываем коридор
    unset($GLOBALS['PC_ALLOW_STOCK_WRITE']);
}

add_action('woocommerce_order_status_processing', __NAMESPACE__.'\\reduce_stock_from_plan', 60);
add_action('woocommerce_order_status_completed',  __NAMESPACE__.'\\reduce_stock_from_plan', 60);

/* ---------- admin actions ---------- */

add_filter('woocommerce_order_actions', function($actions){
    $actions['pc_build_alloc_plan']  = __('Build stock allocation plan', 'paint-core') . ' (PaintCore)';
    $actions['pc_reduce_from_plan']  = __('Reduce stock from plan',    'paint-core') . ' (PaintCore)';
    $actions['pc_restore_from_plan'] = __('Restore stock from plan',   'paint-core') . ' (PaintCore)';
    return $actions;
}, 10, 1);

add_action('woocommerce_order_action_pc_build_alloc_plan', function($order){
    build_allocation_plan_for_order($order);
});
add_action('woocommerce_order_action_pc_reduce_from_plan', function($order){
    reduce_stock_from_plan($order);
});
add_action('woocommerce_order_action_pc_restore_from_plan', function($order_or_id){
    $order = ($order_or_id instanceof \WC_Order) ? $order_or_id : wc_get_order($order_or_id);
    if (!$order) return;
    foreach ($order->get_items('line_item') as $item) {
        if (!$item instanceof \WC_Order_Item_Product) continue;
        $product = $item->get_product(); if (!$product) continue;
        $pid = (int) $product->get_id();
        $plan = ensure_item_plan($item);
        foreach ((array)$plan as $term_id => $qty) {
            if ($qty <= 0) continue;
            add_term_stock($pid, (int)$term_id, +(float)$qty);
            recalc_total_stock($pid);
        }
    }
    $order->delete_meta_data(PC_STOCK_REDUCED_META);
    $order->save();
});

add_action('woocommerce_checkout_create_order_line_item', function($item, $cart_item_key, $values){
    if (!($item instanceof \WC_Order_Item_Product)) return;

    $product = $item->get_product();
    if (!($product instanceof \WC_Product)) return;

    // 1) если корзина уже посчитала план — ставим как есть
    $plan = [];
    if (!empty($values['pc_alloc_plan']) && is_array($values['pc_alloc_plan'])) {
        foreach ($values['pc_alloc_plan'] as $tid => $q) {
            $tid = (int)$tid; $q = (int)$q;
            if ($tid > 0 && $q > 0) $plan[$tid] = $q;
        }
    }
    if (!empty($plan)) {
        stamp_item_plan($item, $plan); // тот же namespace
        return;
    }

    // 2) иначе — считаем и штампуем здесь
    pc_compute_and_stamp_item_plan(
        $item,
        $product,
        (int) max(0, $item->get_quantity()),
        'checkout'
    );
}, 10, 3);