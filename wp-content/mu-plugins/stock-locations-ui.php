<?php
/*
Plugin Name: Stock Locations UI (archive + single)
Description: Показывает остатки по складам и основной (Primary) склад в каталоге и на странице товара. Читает меты _stock_at_{TERM_ID} и primary из _yoast_wpseo_primary_location.
Version: 1.1.1
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

/** сумма остатков по всем складам (с кешем в _stock, фолбэк — суммирование _stock_at_%) */
function slu_total_available_qty( WC_Product $product ) {
    $pid = (int) $product->get_id();

    // быстрый путь: если поддерживаем кэш суммы в _stock
    $sum = get_post_meta($pid, '_stock', true);
    if ($sum !== '' && $sum !== null) {
        return max(0, (int) round((float)$sum));
    }

    // иначе — суммируем все _stock_at_%
    global $wpdb;
    $rows = $wpdb->get_col( $wpdb->prepare(
        "SELECT meta_value FROM {$wpdb->postmeta}
         WHERE post_id=%d AND meta_key LIKE %s",
        $pid, $wpdb->esc_like('_stock_at_') . '%'
    ));
    $total = 0.0;
    foreach ( (array)$rows as $v ) $total += (float)$v;

    // фолбэк: для вариации попробуем родителя
    if ($total <= 0 && $product->is_type('variation')) {
        $parent_id = (int) $product->get_parent_id();
        if ($parent_id) {
            $rows = $wpdb->get_col( $wpdb->prepare(
                "SELECT meta_value FROM {$wpdb->postmeta}
                 WHERE post_id=%d AND meta_key LIKE %s",
                $parent_id, $wpdb->esc_like('_stock_at_') . '%'
            ));
            foreach ( (array)$rows as $v ) $total += (float)$v;
        }
    }

    return max(0, (int) round($total));
}

/** сколько этого товара/вариации уже в корзине */
function slu_cart_qty_for_product( WC_Product $product ): int {
    $pid = (int) $product->get_id();
    $vid = $product->is_type('variation') ? $pid : 0;

    $sum = 0;
    if ( WC()->cart ) {
        foreach ( WC()->cart->get_cart() as $item ) {
            $p = (int) ($item['product_id'] ?? 0);
            $v = (int) ($item['variation_id'] ?? 0);
            if ( $p === $pid && $v === $vid ) $sum += (int) ($item['quantity'] ?? 0);
        }
    }
    return $sum;
}

/** собрать “имя — qty” по другим складам (без primary) */
function slu_collect_location_stocks_for_product( WC_Product $product ) {
    $result = [];
    $term_ids = wp_get_object_terms( $product->get_id(), 'location', ['fields' => 'ids', 'hide_empty' => false] );
    if ( is_wp_error($term_ids) ) $term_ids = [];

    if ( empty($term_ids) ) {
        $term_ids = get_terms(['taxonomy'=>'location','fields'=>'ids','hide_empty'=>false]);
        if ( is_wp_error($term_ids) ) $term_ids = [];
    }
    if ( empty($term_ids) ) return $result;

    $is_var    = $product->is_type('variation');
    $parent_id = $is_var ? (int)$product->get_parent_id() : 0;

    foreach ( $term_ids as $tid ) {
        $tid = (int)$tid;
        $meta_key = '_stock_at_' . $tid;
        $qty = get_post_meta( $product->get_id(), $meta_key, true );

        if ( ($qty === '' || $qty === null) && $is_var && $parent_id ) {
            $qty = get_post_meta( $parent_id, $meta_key, true );
        }

        $qty = ($qty === '' || $qty === null) ? null : (int)$qty;
        if ( $qty === null ) continue;

        $term = get_term( $tid, 'location' );
        if ( ! $term || is_wp_error($term) ) continue;

        $result[$tid] = ['name' => $term->name, 'qty' => $qty];
    }
    return $result;
}

function slu_render_other_locations_line( WC_Product $product ) {
    $primary = slu_get_primary_location_term_id( $product->get_id() );
    $all     = slu_collect_location_stocks_for_product( $product );
    if ( empty($all) ) return '';

    $parts = [];
    foreach ( $all as $tid => $row ) {
        if ( $primary && (int)$tid === (int)$primary ) continue;
        if ( (int)$row['qty'] <= 0 ) continue;
        $parts[] = esc_html($row['name']) . ' — ' . (int)$row['qty'];
    }
    return $parts ? implode(', ', $parts) : '';
}

function slu_render_primary_location_line( WC_Product $product ) {
    $primary = slu_get_primary_location_term_id( $product->get_id() );
    if ( ! $primary ) return '';

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

/**
 * Универсальный блок складов/остатков.
 * $opts:
 *  - show_primary, show_others, show_total, show_incart — что показывать
 *  - hide_when_zero (bool) — скрывать весь блок, если total <= 0 (актуально для архива)
 *  - wrap_class (string) — доп. класс для оболочки
 */
function slu_render_stock_panel( WC_Product $product, array $opts = [] ): string {
    $o = array_merge([
        'show_primary'   => true,
        'show_others'    => true,
        'show_total'     => true,
        'show_incart'    => true,
        'hide_when_zero' => false,
        'wrap_class'     => '',
    ], $opts);

    $primary_line = $o['show_primary'] ? slu_render_primary_location_line( $product ) : '';
    $others_line  = $o['show_others']  ? slu_render_other_locations_line( $product ) : '';
    $total        = $o['show_total']   ? slu_total_available_qty( $product ) : null;
    $in_cart      = $o['show_incart']  ? slu_cart_qty_for_product( $product ) : null;

    if ( $o['hide_when_zero'] && $total !== null && (int)$total <= 0 ) {
        return '';
    }
    if ( ! $primary_line && ! $others_line && $total === null && $in_cart === null ) {
        return '';
    }

    ob_start(); ?>
    <div class="slu-stock-box <?= esc_attr( $o['wrap_class'] ) ?>">
        <?php if ( $primary_line ): ?>
            <div><strong><?= esc_html__('Заказ со склада', 'woocommerce') ?>:</strong> <?= $primary_line ?></div>
        <?php endif; ?>
        <?php if ( $others_line ): ?>
            <div><strong><?= esc_html__('Другие склады', 'woocommerce') ?>:</strong> <?= $others_line ?></div>
        <?php endif; ?>
        <?php if ( $total !== null ): ?>
            <div><strong><?= esc_html__('Всего', 'woocommerce') ?>:</strong> <?= (int)$total ?></div>
        <?php endif; ?>
        <?php if ( $in_cart !== null ): ?>
            <div><strong><?= esc_html__('В корзине', 'woocommerce') ?>:</strong> <?= (int)$in_cart ?></div>
        <?php endif; ?>
    </div>
    <?php
    return (string) ob_get_clean();
}

/* ===== single product (PDP) ===== */
add_action('woocommerce_single_product_summary', function(){
    global $product;
    if ( ! ($product instanceof WC_Product) ) return;

    echo slu_render_stock_panel( $product, [
        'show_primary'   => true,
        'show_others'    => true,
        'show_total'     => true,
        'show_incart'    => true,
        'hide_when_zero' => false,
        'wrap_class'     => '',
    ] );
}, 25);

/* ===== product archive (loop) ===== */
add_action('woocommerce_after_shop_loop_item_title', function(){
    global $product;
    if ( ! ($product instanceof WC_Product) ) return;

    echo slu_render_stock_panel( $product, [
        'show_primary'   => true,
        'show_others'    => true,
        'show_total'     => true,
        'show_incart'    => false,
        'hide_when_zero' => true,   // прячем панель у «Всего: 0»
        'wrap_class'     => 'slu-stock-mini',
    ] );
}, 11);

/* ===== чуть CSS (можно оставить, можно перенести в ваш общий css) ===== */
add_action('wp_head', function(){
    echo '<style>
    .single-product .slu-stock-box{border:1px dashed #e0e0e0;padding:8px 10px;border-radius:6px;background:#fafafa;font-size:14px;color:#333;margin:10px 0 6px}
    .products .slu-stock-mini{border:0;padding:0;margin-top:6px;background:transparent;font-size:12px;line-height:1.25;color:#2e7d32}
    .products .slu-stock-mini div{margin:0 0 2px}
    .products .slu-stock-mini strong{color:#333;font-weight:600}
    @media (max-width:480px){.products .slu-stock-mini{font-size:11px;margin-top:4px}}
    </style>';
});