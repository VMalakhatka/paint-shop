<?php
namespace PaintCore\Stock;

defined('ABSPATH') || exit;

/**
 * Stock Locations: показать «Другие склады» и «Заказ со склада» в корзине/чекауте,
 * а также сохранить/показать склад в заказах и письмах.
 * Требования: таксономия `location` (плагин Stock Locations),
 * мета остатка `_stock_at_{term_id}`, primary-локация в `_yoast_wpseo_primary_location`.
 */

/* ========== ХЕЛПЕРЫ ========== */

function primary_location_term_id( $product_id ) {
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

function primary_location_name_for_product( \WC_Product $product ) {
    $term_id = primary_location_term_id( $product->get_id() );
    if ( ! $term_id ) return '';
    $term = get_term( $term_id, 'location' );
    return ( $term && ! is_wp_error( $term ) ) ? $term->name : '';
}

function qty_at_primary_location_for_product( \WC_Product $product ) {
    $term_id = primary_location_term_id( $product->get_id() );
    if ( ! $term_id ) return null;

    $meta_key = '_stock_at_' . $term_id;
    $qty = get_post_meta( $product->get_id(), $meta_key, true );

    if ( ($qty === '' || $qty === null) && $product->is_type('variation') ) {
        $parent_id = $product->get_parent_id();
        if ( $parent_id ) $qty = get_post_meta( $parent_id, $meta_key, true );
    }
    return ($qty === '' || $qty === null) ? null : (int) $qty;
}

function other_locations_stock_for_product( \WC_Product $product ) {
    $primary_id   = primary_location_term_id( $product->get_id() );
    $primary_name = primary_location_name_for_product( $product );

    $terms = get_terms([
        'taxonomy'   => 'location',
        'hide_empty' => false,
    ]);
    if ( is_wp_error($terms) || empty($terms) ) return [];

    $stocks = [];
    foreach ( $terms as $t ) {
        if ( $primary_id && (int)$t->term_id === (int)$primary_id ) continue;

        $meta_key = '_stock_at_' . $t->term_id;
        $qty = get_post_meta( $product->get_id(), $meta_key, true );

        if ( ($qty === '' || $qty === null) && $product->is_type('variation') ) {
            $parent_id = $product->get_parent_id();
            if ( $parent_id ) $qty = get_post_meta( $parent_id, $meta_key, true );
        }

        if ( $qty !== '' && $qty !== null && (int)$qty > 0 ) {
            $stocks[ $t->name ] = (int) $qty;
        }
    }

    if ( $primary_name && isset($stocks[$primary_name]) ) {
        unset($stocks[$primary_name]);
    }

    return $stocks;
}

/* ========== КОРЗИНА / ЧЕКАУТ: 2 строки под названием позиции ========== */

add_filter('woocommerce_get_item_data', function( $item_data, $cart_item ){
    $product = $cart_item['data'] ?? null;
    if ( ! ($product instanceof \WC_Product) ) return $item_data;

    // 1) Другие склады
    $others = other_locations_stock_for_product( $product );
    if ( !empty($others) ) {
        $parts = [];
        foreach ( $others as $name => $qty ) {
            $parts[] = $name . ' — ' . (int)$qty;
        }
        $value = implode(', ', $parts);
        $item_data[] = [
            'key'     => __('Другие склады','woocommerce'),
            'value'   => $value,
            'display' => $value, // для Blocks
        ];
    }

    // 2) Заказ со склада (primary)
    $loc_name = primary_location_name_for_product( $product );
    if ( $loc_name ) {
        $qty   = qty_at_primary_location_for_product( $product );
        $value = $loc_name . ( $qty !== null ? ' — ' . (int)$qty : '' );
        $item_data[] = [
            'key'     => __('Заказ со склада','woocommerce'),
            'value'   => $value,
            'display' => $value, // для Blocks
        ];
    }

    return $item_data;
}, 20, 2);

/* ========== СОХРАНЕНИЕ В ЗАКАЗ ========== */

add_action( 'woocommerce_checkout_create_order_line_item', function( $item, $cart_item_key, $values, $order ){
    $product = $item->get_product();
    if ( ! ( $product instanceof \WC_Product ) ) return;

    $term_id = primary_location_term_id( $product->get_id() );
    if ( $term_id ) {
        $term = get_term( $term_id, 'location' );
        if ( $term && ! is_wp_error( $term ) ) {
            // машинные меты
            $item->add_meta_data( '_stock_location_id',   (int)$term_id, true );
            $item->add_meta_data( '_stock_location_slug', $term->slug,   true );
            // видимая мета
            $item->add_meta_data( __( 'Склад', 'woocommerce' ), $term->name, true );
        }
    }
}, 10, 4 );

/* ========== ВЫВОД В ЗАКАЗЕ И ПИСЬМАХ (если видимая мета отсутствует) ========== */

add_action( 'woocommerce_order_item_meta_end', function( $item_id, $item, $order, $plain ){
    if ( $item->get_meta( __( 'Склад', 'woocommerce' ) ) ) return;

    $product = $item->get_product();
    if ( ! ( $product instanceof \WC_Product ) ) return;

    $term_id = (int) $item->get_meta('_stock_location_id');
    if ( ! $term_id ) $term_id = primary_location_term_id( $product->get_id() );
    if ( ! $term_id ) return;

    $term = get_term( $term_id, 'location' );
    if ( ! $term || is_wp_error( $term ) ) return;

    if ( $plain ) {
        echo "\n".__('Склад','woocommerce').': '.$term->name."\n";
    } else {
        echo '<div class="wc-item-loc" style="font-size:12px;color:#555;margin-top:2px">'
           . esc_html__('Склад','woocommerce') . ': ' . esc_html($term->name)
           . '</div>';
    }
}, 10, 4 );

add_action( 'woocommerce_email_order_item_meta', function( $item, $sent_to_admin, $plain, $email ){
    if ( $item->get_meta( __( 'Склад', 'woocommerce' ) ) ) return;

    $product = $item->get_product();
    if ( ! ( $product instanceof \WC_Product ) ) return;

    $term_id = (int) $item->get_meta('_stock_location_id');
    if ( ! $term_id ) $term_id = primary_location_term_id( $product->get_id() );
    if ( ! $term_id ) return;

    $term = get_term( $term_id, 'location' );
    if ( ! $term || is_wp_error( $term ) ) return;

    if ( $plain ) {
        echo "\n".__('Склад','woocommerce').': '.$term->name."\n";
    } else {
        echo '<br><small class="wc-item-loc" style="color:#555">'
           . esc_html__('Склад','woocommerce') . ': ' . esc_html($term->name)
           . '</small>';
    }
}, 10, 4 );