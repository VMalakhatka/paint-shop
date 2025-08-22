<?php
/**
 * Plugin Core: фиксация реального склада списания
 * Смотрим, какой склад реально уменьшился при оформлении заказа
 */

// 1. Больше НЕ пишем склад на этапе создания строки заказа
add_action('init', function () {
    remove_all_actions('woocommerce_checkout_create_order_line_item');
});

// Внутренний кэш "до"
$GLOBALS['pc_before_stock_map'] = [];

// 2. Снимок остатков ДО редукции (priority 9 — раньше плагина)
add_action('woocommerce_reduce_order_stock', function(WC_Order $order){
    $snap = [];
    foreach ( $order->get_items('line_item') as $item_id => $item ) {
        $product = $item->get_product();
        if ( ! $product ) continue;
        $pid = $product->get_id();

        $terms = get_terms(['taxonomy'=>'location','hide_empty'=>false]);
        if ( is_wp_error($terms) ) continue;

        foreach ($terms as $t) {
            $key = '_stock_at_' . (int)$t->term_id;
            $qty = get_post_meta($pid, $key, true);
            $snap[$pid][(int)$t->term_id] = ($qty === '' || $qty === null) ? 0 : (float)$qty;
        }
    }
    $GLOBALS['pc_before_stock_map'] = $snap;
}, 9);

// 3. Сравнение ПОСЛЕ редукции — пишем фактический склад(ы) в мету строки заказа
add_action('woocommerce_reduce_order_stock', function(WC_Order $order){
    $before = $GLOBALS['pc_before_stock_map'] ?? [];
    if (empty($before)) return;

    $terms_all = get_terms(['taxonomy'=>'location','hide_empty'=>false]);
    if ( is_wp_error($terms_all) ) $terms_all = [];
    $dict = [];
    foreach ($terms_all as $t) {
        $dict[(int)$t->term_id] = $t;
    }

    foreach ( $order->get_items('line_item') as $item_id => $item ) {
        $product = $item->get_product();
        if ( ! $product ) continue;
        $pid = $product->get_id();

        if ( empty($before[$pid]) ) continue;

        $diffs = [];
        foreach ($before[$pid] as $term_id => $qty_before) {
            $key  = '_stock_at_' . (int)$term_id;
            $after = get_post_meta($pid, $key, true);
            $qty_after = ($after === '' || $after === null) ? 0 : (float)$after;
            $delta = $qty_before - $qty_after;
            if ( $delta > 0.0001 ) {
                $diffs[$term_id] = $delta;
            }
        }

        if (empty($diffs)) continue;

        arsort($diffs, SORT_NUMERIC);
        $parts = [];
        $first_id = null;
        $first_slug = null;

        foreach ($diffs as $term_id => $delta) {
            $t = $dict[$term_id] ?? null;
            $name = $t ? $t->name : ('#'.$term_id);
            $slug = $t ? $t->slug : (string)$term_id;

            $parts[] = sprintf('%s × %s', $name, (int)$delta);
            if ($first_id === null) {
                $first_id = (int)$term_id;
                $first_slug = $slug;
            }
        }

        $item->update_meta_data('_stock_location_id',   $first_id);
        $item->update_meta_data('_stock_location_slug', $first_slug);
        $item->update_meta_data( __( 'Склад', 'woocommerce' ), implode(', ', $parts) );
        $item->save();
    }
}, 99);