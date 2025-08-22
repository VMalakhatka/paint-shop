<?php
/*
Plugin Name: Stock Locations UI (archive + single)
Description: Показывает остатки по складам и основной (Primary) склад в каталоге и на странице товара. Читает меты _stock_at_{TERM_ID} и primary из _yoast_wpseo_primary_location.
Version: 1.0.0
Author: PaintCore
*/
if (!defined('ABSPATH')) exit;

/** ===== helpers ===== */

/** primary term_id для товара/вариации */
function slu_get_primary_location_term_id( $product_id ) {
    $pid = (int) $product_id;
    if ( ! $pid ) return 0;

    $term_id = (int) get_post_meta( $pid, '_yoast_wpseo_primary_location', true );
    if ( ! $term_id ) {
        $parent_id = (int) wp_get_post_parent_id( $pid );
        if ( $parent_id ) {
            $term_id = (int) get_post_meta( $parent_id, '_yoast_wpseo_primary_location', true );
        }
    }
    return $term_id ?: 0;
}

/** получить массив [ term_id => ['name'=>'...', 'qty'=>int] ] по всем привязанным к товару складам */
function slu_collect_location_stocks_for_product( WC_Product $product ) {
    $result = [];
    // какие термины привязаны к товару (так меньше «шуму»)
    $term_ids = wp_get_object_terms( $product->get_id(), 'location', ['fields' => 'ids', 'hide_empty' => false] );
    if ( is_wp_error($term_ids) ) $term_ids = [];

    // если ничего не привязано — всё равно пробуем по всем location (редкий случай)
    if ( empty($term_ids) ) {
        $term_ids = get_terms(['taxonomy'=>'location','fields'=>'ids','hide_empty'=>false]);
        if ( is_wp_error($term_ids) ) $term_ids = [];
    }
    if ( empty($term_ids) ) return $result;

    $is_var = $product->is_type('variation');
    $parent_id = $is_var ? (int)$product->get_parent_id() : 0;

    foreach ( $term_ids as $tid ) {
        $tid = (int)$tid;
        $meta_key = '_stock_at_' . $tid;
        $qty = get_post_meta( $product->get_id(), $meta_key, true );

        // для вариации попробуем у родителя, если у самой пусто
        if ( ($qty === '' || $qty === null) && $is_var && $parent_id ) {
            $qty = get_post_meta( $parent_id, $meta_key, true );
        }

        $qty = ($qty === '' || $qty === null) ? null : (int)$qty;
        if ( $qty === null ) continue; // не показываем пустые

        $term = get_term( $tid, 'location' );
        if ( ! $term || is_wp_error($term) ) continue;

        $result[$tid] = [
            'name' => $term->name,
            'qty'  => $qty,
        ];
    }

    return $result;
}

/** собрать HTML‑строчку вида “Киев — 5, Одеса — 17” (не включая primary) */
function slu_render_other_locations_line( WC_Product $product ) {
    $primary = slu_get_primary_location_term_id( $product->get_id() );
    $all = slu_collect_location_stocks_for_product( $product );
    if ( empty($all) ) return '';

    $parts = [];
    foreach ( $all as $tid => $row ) {
        if ( $primary && (int)$tid === (int)$primary ) continue; // исключим основной
        if ( (int)$row['qty'] <= 0 ) continue;
        $parts[] = esc_html($row['name']) . ' — ' . (int)$row['qty'];
    }
    return $parts ? implode(', ', $parts) : '';
}

/** вернуть “имя — qty” по primary, либо пусто */
function slu_render_primary_location_line( WC_Product $product ) {
    $primary = slu_get_primary_location_term_id( $product->get_id() );
    if ( ! $primary ) return '';

    // найдём qty для этого term_id
    $meta_key = '_stock_at_' . (int)$primary;
    $qty = get_post_meta( $product->get_id(), $meta_key, true );
    if ( ($qty === '' || $qty === null) && $product->is_type('variation') ) {
        $parent_id = $product->get_parent_id();
        if ( $parent_id ) $qty = get_post_meta( $parent_id, $meta_key, true );
    }
    $qty = ($qty === '' || $qty === null) ? null : (int)$qty;

    $term = get_term( $primary, 'location' );
    if ( ! $term || is_wp_error($term) ) return '';

    return esc_html($term->name) . ( $qty !== null ? ' — ' . (int)$qty : '' );
}

/** ===== single product ===== */
add_action('woocommerce_single_product_summary', function(){
    global $product;
    if ( ! ($product instanceof WC_Product) ) return;

    $primary_line = slu_render_primary_location_line( $product );
    $others_line  = slu_render_other_locations_line( $product );
    if ( ! $primary_line && ! $others_line ) return;

    echo '<div class="slu-stock-box" style="margin:10px 0 6px; font-size:14px; color:#333;">';
    if ( $primary_line ) {
        echo '<div><strong>' . esc_html__('Заказ со склада', 'woocommerce') . ':</strong> ' . $primary_line . '</div>';
    }
    if ( $others_line ) {
        echo '<div><strong>' . esc_html__('Другие склады', 'woocommerce') . ':</strong> ' . $others_line . '</div>';
    }
    echo '</div>';
}, 25); // после цены (обычно 10) и краткого описания (20)

/** ===== product archive (loop) ===== */
add_action('woocommerce_after_shop_loop_item_title', function(){
    global $product;
    if ( ! ($product instanceof WC_Product) ) return;

    $primary_line = slu_render_primary_location_line( $product );
    $others_line  = slu_render_other_locations_line( $product );
    if ( ! $primary_line && ! $others_line ) return;

    echo '<div class="slu-stock-mini" style="margin-top:4px; font-size:12px; color:#2e7d32;">';
    if ( $primary_line ) {
        echo '<div>' . esc_html__('Заказ со склада', 'woocommerce') . ': ' . $primary_line . '</div>';
    }
    if ( $others_line ) {
        echo '<div style="color:#555;">' . esc_html__('Другие склады', 'woocommerce') . ': ' . $others_line . '</div>';
    }
    echo '</div>';
}, 11); // сразу под заголовком/ценой

/* ===== (опционально) немного CSS для витрин, можно убрать ===== */
add_action('wp_head', function(){
    echo '<style>
    .products .slu-stock-mini{line-height:1.25}
    .single-product .slu-stock-box{border:1px dashed #e0e0e0; padding:8px 10px; border-radius:6px; background:#fafafa}
    </style>';
});