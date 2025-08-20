<?php
namespace PaintUX\Catalog;
defined('ABSPATH') || exit;

/**
 * WooCommerce: qty в листинге, кнопка с SVG‑иконкой и количеством (без перезагрузки),
 * скрыть “View cart” и галочку темы, мобильная qty, показ остатка + лимит по складу,
 * компоновка: qty и кнопка в один ряд.
 * ФИКС: количество передаём через событие `adding_to_cart`, чтобы не было «всегда 1».
 */

/* ==== НАСТРОЙКИ ==== */
add_action('init', function () {
    $GLOBALS['wc_loop_cfg'] = [
        'format_in_cart'  => '(%d)',
        'format_zero'     => '',
        'apply_on_single' => false,
        'min'             => 1,
        'max'             => 99,    // 0 = без лимита
        'show_plus_minus' => true,
    ];
});

/* ==== Сколько этого товара уже в корзине ==== */
function wc_loop_get_in_cart_count( $product ) {
    if ( ! WC()->cart ) return 0;
    $pid = $product->get_id();
    $qty = 0;
    foreach ( WC()->cart->get_cart() as $item ) {
        if ( (int)$item['product_id'] === (int)$pid ) {
            $qty += (int)$item['quantity'];
        }
    }
    return $qty;
}

/* ==== Текст кнопки в каталоге ==== */
add_filter( 'woocommerce_product_add_to_cart_text', function( $text, $product ){
    $cfg = $GLOBALS['wc_loop_cfg'] ?? [];
    $qty = wc_loop_get_in_cart_count($product);
    return $qty > 0 ? sprintf($cfg['format_in_cart'], $qty) : ($cfg['format_zero'] ?? '');
}, 10, 2 );

/* ==== Текст кнопки на странице товара (если включено) ==== */
add_filter( 'woocommerce_product_single_add_to_cart_text', function( $text ){
    $cfg = $GLOBALS['wc_loop_cfg'] ?? [];
    if ( empty($cfg['apply_on_single']) ) return $text;
    global $product;
    $qty = wc_loop_get_in_cart_count($product);
    return $qty > 0 ? sprintf($cfg['format_in_cart'], $qty) : ($cfg['format_zero'] ?? '');
}, 10, 1 );

/* ==== Количество в листинге (перед кнопкой) ==== */
add_action('woocommerce_after_shop_loop_item', function () {
    global $product;
    if ( ! ($product instanceof \WC_Product) ) return;
    if ( ! $product->is_purchasable() || $product->is_sold_individually() ) return;
    if ( $product->is_type('variable') ) return;

    $cfg = $GLOBALS['wc_loop_cfg'] ?? [];
    $min = max(1, (int)($cfg['min'] ?? 1));
    $max = (int)($cfg['max'] ?? 0);
    $pid = $product->get_id();

    echo '<div class="loop-qty-wrap" data-product-id="'.esc_attr($pid).'">';
    if ( ! empty($cfg['show_plus_minus']) ) {
        echo '<button type="button" class="loop-qty-btn loop-qty-minus" aria-label="Minus">−</button>';
    }
    echo '<input type="text" inputmode="numeric" pattern="[0-9]*" class="input-text qty text loop-qty" '
        .'value="'.esc_attr($min).'" min="'.esc_attr($min).'" '
        .($max>0 ? 'max="'.esc_attr($max).'" ' : '')
        .'step="1" />';
    echo '<span class="loop-qty-view" aria-hidden="true">'.esc_html($min).'</span>';
    if ( ! empty($cfg['show_plus_minus']) ) {
        echo '<button type="button" class="loop-qty-btn loop-qty-plus" aria-label="Plus">+</button>';
    }
    echo '</div>';
}, 9);

/* ==== Остаток сразу после цены (добавляем data-stock) ==== */
add_action( 'woocommerce_after_shop_loop_item_title', function () {
    global $product;
    if ( ! $product instanceof \WC_Product ) return;

    if ( $product->is_in_stock() ) {
        if ( $product->managing_stock() ) {
            $qty = (int) $product->get_stock_quantity();
            if ( $qty > 0 ) {
                echo '<span class="loop-stock-top in" data-stock="'.esc_attr($qty).'">На складе: ' . esc_html( $qty ) . '</span>';
            } else {
                echo '<span class="loop-stock-top pre" data-stock="0">Под заказ</span>';
            }
        } else {
            echo '<span class="loop-stock-top in" data-stock="">В наличии</span>';
        }
    } else {
        echo '<span class="loop-stock-top out" data-stock="0">Нет в наличии</span>';
    }
}, 11);

/* ==== Подключение CSS/JS из assets ==== */
add_action('wp_enqueue_scripts', function () {
    if ( is_admin() ) return;

    // грузим только там, где есть витрина Woo (каталог/категории/метки/архив)
    $is_wc_catalog = function_exists('is_woocommerce') && (
        is_shop() ||
        (function_exists('is_product_taxonomy') && is_product_taxonomy()) ||
        (function_exists('is_product_category') && is_product_category()) ||
        (function_exists('is_product_tag') && is_product_tag())
    );

    if ( ! $is_wc_catalog ) return;

    $css_rel = 'assets/css/catalog-qty.css';
    $js_rel  = 'assets/js/catalog-qty.js';

    $css_abs = PAINT_CORE_PATH . $css_rel;
    $js_abs  = PAINT_CORE_PATH . $js_rel;

    $css_ver = file_exists($css_abs) ? filemtime($css_abs) : '1.0.0';
    $js_ver  = file_exists($js_abs)  ? filemtime($js_abs)  : '1.0.0';

    wp_enqueue_style(
        'paint-core-catalog-qty',
        PAINT_CORE_URL . $css_rel,
        [],
        $css_ver
    );

    // нужен jQuery и wc-add-to-cart, чтобы слушать adding_to_cart/added_to_cart
    wp_enqueue_script(
        'paint-core-catalog-qty',
        PAINT_CORE_URL . $js_rel,
        ['jquery', 'wc-add-to-cart'],
        $js_ver,
        true
    );
}, 20);