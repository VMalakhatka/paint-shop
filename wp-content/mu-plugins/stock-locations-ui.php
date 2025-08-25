<?php
/*
Plugin Name: Stock Locations UI (archive + single)
Description: Показывает остатки по складам и основной (Primary) склад в каталоге и на странице товара. Читает меты _stock_at_{TERM_ID} и primary из _yoast_wpseo_primary_location.
Version: 1.1.0
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

/** сумма остатков по всем складам (учитывает вариации, умеет падать на родителя) */
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

/** Сколько этого товара/вариации уже в корзине */
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

/* ===== single product ===== */
remove_all_actions('woocommerce_single_product_summary'); // на всякий случай, если уже есть старый хук
add_action('woocommerce_single_product_summary', function(){
    global $product;
    if ( ! ($product instanceof WC_Product) ) return;

    // На PDP показываем всё, включая "В корзине"
    echo slu_render_stock_panel( $product, [
        'show_primary' => true,
        'show_others'  => true,
        'show_total'   => true,
        'show_incart'  => true,
        'wrap_class'   => '',
    ] );
}, 25);

/* ===== product archive (loop) ===== */
remove_all_actions('woocommerce_after_shop_loop_item_title');
add_action('woocommerce_after_shop_loop_item_title', function(){
    global $product;
    if ( ! ($product instanceof WC_Product) ) return;

    // В каталоге — как раньше: primary + others + total (без "В корзине")
    echo slu_render_stock_panel( $product, [
        'show_primary' => true,
        'show_others'  => true,
        'show_total'   => true,
        'show_incart'  => false,
        'wrap_class'   => 'slu-stock-mini',
    ] );
}, 11);


/* ===== (опционально) немного CSS для витрин, можно убрать ===== */
add_action('wp_head', function(){
    echo '<style>
    .products .slu-stock-mini{line-height:1.25}
    .single-product .slu-stock-box{border:1px dashed #e0e0e0; padding:8px 10px; border-radius:6px; background:#fafafa}
    </style>';
});

/**
 * Универсальный блок складов/остатков.
 * $opts:
 *  - show_primary (bool)  — строка "Заказ со склада: ..."
 *  - show_others  (bool)  — "Другие склады: ..."
 *  - show_total   (bool)  — "Всего: N"
 *  - show_incart  (bool)  — "В корзине: N"
 *  - wrap_class   (string) дополнительный CSS‑класс
 */
function slu_render_stock_panel( WC_Product $product, array $opts = [] ): string {
    $o = array_merge([
        'show_primary' => true,
        'show_others'  => true,
        'show_total'   => true,
        'show_incart'  => true,
        'wrap_class'   => '',
    ], $opts);

    $primary_line = $o['show_primary'] ? slu_render_primary_location_line( $product ) : '';
    $others_line  = $o['show_others']  ? slu_render_other_locations_line( $product ) : '';
    $total        = $o['show_total']   ? slu_total_available_qty( $product ) : null;
    $in_cart      = $o['show_incart']  ? slu_cart_qty_for_product( $product ) : null;

    // Если вообще нечего показывать — возвращаем пусто
    if ( ! $primary_line && ! $others_line && $total === null && $in_cart === null ) {
        return '';
    }

    ob_start();
    ?>
    <div class="slu-stock-box <?= esc_attr( $o['wrap_class'] ) ?>"
         style="margin:10px 0 6px; font-size:14px; color:#333; border:1px dashed #e0e0e0; padding:8px 10px; border-radius:6px; background:#fafafa">
        <?php if ( $primary_line ): ?>
            <div><strong><?= esc_html__( 'Заказ со склада', 'woocommerce' ) ?>:</strong> <?= $primary_line ?></div>
        <?php endif; ?>

        <?php if ( $others_line ): ?>
            <div><strong><?= esc_html__( 'Другие склады', 'woocommerce' ) ?>:</strong> <?= $others_line ?></div>
        <?php endif; ?>

        <?php if ( $total !== null ): ?>
            <div><strong><?= esc_html__( 'Всего', 'woocommerce' ) ?>:</strong> <?= (int) $total ?></div>
        <?php endif; ?>

        <?php if ( $in_cart !== null ): ?>
            <div><strong><?= esc_html__( 'В корзине', 'woocommerce' ) ?>:</strong> <?= (int) $in_cart ?></div>
        <?php endif; ?>
    </div>
    <?php
    return (string) ob_get_clean();
}