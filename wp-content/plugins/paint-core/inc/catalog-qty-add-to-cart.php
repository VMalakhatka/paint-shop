<?php
namespace PaintUX\Catalog;

defined('ABSPATH') || exit;

/**
 * Каталожная qty + кнопка добавления в корзину без перезагрузки.
 * - Показывает поле количества и "+ / −" в листинге.
 * - Учитывает фактический доступный остаток (сумма наших _stock_at_% или кэш _stock).
 * - Не даёт ввести > Всего (и на фронте, и на бэке).
 * - Для товаров без остатков рисует такой же контейнер, но disabled (чтобы сетка не "прыгала").
 * - Передаёт реальное количество при добавлении в корзину (через событие adding_to_cart).
 */

/* ===== Доступное количество (Всего) ===== */
function pcux_available_qty(\WC_Product $product): int {
    // если есть функция из mu-плагина — используем её
    if (function_exists('\\slu_total_available_qty')) {
        return (int) max(0, (int) \slu_total_available_qty($product));
    }

    global $wpdb;
    $pid = (int) $product->get_id();
    $sum = 0.0;

    // быстрый путь — кэш в _stock
    $stock = get_post_meta($pid, '_stock', true);
    if ($stock !== '' && $stock !== null) {
        $sum = (float) $stock;
    } else {
        // суммируем все _stock_at_%
        $rows = $wpdb->get_col($wpdb->prepare(
            "SELECT meta_value FROM {$wpdb->postmeta}
             WHERE post_id=%d AND meta_key LIKE %s",
            $pid, $wpdb->esc_like('_stock_at_') . '%'
        ));
        foreach ((array)$rows as $v) $sum += (float)$v;

        // фолбэк для вариаций — попробуем родителя
        if ($sum <= 0 && $product->is_type('variation')) {
            $parent_id = (int) $product->get_parent_id();
            if ($parent_id) {
                $rows = $wpdb->get_col($wpdb->prepare(
                    "SELECT meta_value FROM {$wpdb->postmeta}
                     WHERE post_id=%d AND meta_key LIKE %s",
                    $parent_id, $wpdb->esc_like('_stock_at_') . '%'
                ));
                foreach ((array)$rows as $v) $sum += (float)$v;
            }
        }
    }
    return (int) max(0, round($sum));
}

/* ===== Конфиг виджета в каталоге ===== */
add_action('init', function () {
    $GLOBALS['wc_loop_cfg'] = [
        'format_in_cart'  => '(%d)', // что показывать на кнопке, если уже в корзине
        'format_zero'     => '',     // текст на кнопке, если ещё не в корзине
        'apply_on_single' => false,  // применять на PDP
        'min'             => 1,
        'max'             => 99,     // игнорируется, если есть реальный остаток — тогда лимит равен ему
        'show_plus_minus' => true,
    ];
});

/* ===== Сколько уже в корзине данного товара ===== */
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

/* ===== Текст кнопки в каталоге ===== */
add_filter( 'woocommerce_product_add_to_cart_text', function( $text, $product ){
    $cfg = $GLOBALS['wc_loop_cfg'] ?? [];
    $qty = wc_loop_get_in_cart_count($product);
    return $qty > 0 ? sprintf($cfg['format_in_cart'], $qty) : ($cfg['format_zero'] ?? '');
}, 10, 2 );

/* ===== Текст кнопки на PDP (опционально) ===== */
add_filter( 'woocommerce_product_single_add_to_cart_text', function( $text ){
    $cfg = $GLOBALS['wc_loop_cfg'] ?? [];
    if ( empty($cfg['apply_on_single']) ) return $text;
    global $product;
    $qty = wc_loop_get_in_cart_count($product);
    return $qty > 0 ? sprintf($cfg['format_in_cart'], $qty) : ($cfg['format_zero'] ?? '');
}, 10, 1 );

/* ===== Количество + кнопки в каталоге ===== */
add_action('woocommerce_after_shop_loop_item', function () {
    global $product;
    if (!($product instanceof \WC_Product)) return;
    if ($product->is_sold_individually()) return;
    if ($product->is_type('variable')) return; // упрощаем

    $available   = pcux_available_qty($product);
    $cfg         = $GLOBALS['wc_loop_cfg'] ?? [];
    $min         = max(1, (int)($cfg['min'] ?? 1));
    $pid         = $product->get_id();

    // если нет остатков — рисуем контейнер disabled (чтобы сетка не ломалась)
    $max         = (int) max(0, $available);
    $is_disabled = ($max <= 0);
    $val         = $is_disabled ? 0 : min($min, $max);

    echo '<div class="loop-qty-wrap'.($is_disabled?' is-disabled':'').'" data-product-id="'.esc_attr($pid).'" data-max="'.esc_attr($max).'">';

    if (!empty($cfg['show_plus_minus'])) {
        echo '<button type="button" class="loop-qty-btn loop-qty-minus" aria-label="Minus" '.($is_disabled?'disabled':'').'>−</button>';
    }

    echo '<input type="text" inputmode="numeric" pattern="[0-9]*" class="input-text qty text loop-qty" '
        .'value="'.esc_attr($val).'" min="'.esc_attr($min).'" '
        .($max>0 ? 'max="'.esc_attr($max).'" ' : '')
        .'step="1" '.($is_disabled?'disabled':'').' />';

    echo '<span class="loop-qty-view" aria-hidden="true">'.esc_html($val).'</span>';

    if (!empty($cfg['show_plus_minus'])) {
        echo '<button type="button" class="loop-qty-btn loop-qty-plus" aria-label="Plus" '.($is_disabled?'disabled':'').'>+</button>';
    }

    echo '</div>';
}, 9);

/* ===== Маркер «В наличии/Нет в наличии» под ценой (как было) ===== */
add_action( 'woocommerce_after_shop_loop_item_title', function () {
    global $product;
    if ( ! $product instanceof \WC_Product ) return;

    if ( $product->is_in_stock() ) {
        if ( $product->managing_stock() ) {
            $qty = (int) $product->get_stock_quantity();
            if ( $qty > 0 ) {
                echo '<span class="loop-stock-top in" data-stock="'.esc_attr($qty).'">'.esc_html__('На складе: ', 'woocommerce') . esc_html( $qty ) . '</span>';
            } else {
                echo '<span class="loop-stock-top pre" data-stock="0">'.esc_html__('Под заказ', 'woocommerce').'</span>';
            }
        } else {
            echo '<span class="loop-stock-top in" data-stock="">'.esc_html__('В наличии', 'woocommerce').'</span>';
        }
    } else {
        echo '<span class="loop-stock-top out" data-stock="0">'.esc_html__('Нет в наличии', 'woocommerce').'</span>';
    }
}, 11);

/* ===== Подключение CSS/JS ===== */
add_action('wp_enqueue_scripts', function () {
    if ( is_admin() ) return;

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

    // нужен jQuery и wc-add-to-cart для событий adding_to_cart/added_to_cart
    wp_enqueue_script(
        'paint-core-catalog-qty',
        PAINT_CORE_URL . $js_rel,
        ['jquery', 'wc-add-to-cart'],
        $js_ver,
        true
    );
}, 20);

/* ===== Серверные проверки кол-ва (не больше "Всего") ===== */

/**
 * Проверка при добавлении в корзину (AJAX/не AJAX).
 * Принудительно используем глобальные функции WP/WC через \, чтобы избежать фаталов в неймспейсе.
 */
/* ===== Серверные проверки кол-ва (не больше "Всего") — версия с try/catch ===== */

\add_filter('woocommerce_add_to_cart_validation',
function ($passed, $product_id, $qty, $variation_id = 0) {
    try {
        $prod = \wc_get_product($variation_id ?: $product_id);
        if (!($prod instanceof \WC_Product)) return $passed;

        $available = \PaintUX\Catalog\pcux_available_qty($prod);

        // сколько уже в корзине
        $in_cart = 0;
        $cart = (\function_exists('\\WC') && \WC()) ? \WC()->cart : null;
        if ($cart && \is_object($cart)) {
            foreach ($cart->get_cart() as $item) {
                $pid = (int)($item['product_id'] ?? 0);
                $vid = (int)($item['variation_id'] ?? 0);
                if ($pid === (int)$product_id && $vid === (int)($variation_id ?: 0)) {
                    $in_cart += (int)($item['quantity'] ?? 0);
                }
            }
        }

        if ($available <= 0) {
            \wc_add_notice(\__('Товар сейчас недоступен на складе.', 'woocommerce'), 'error');
            return false;
        }

        if (($qty + $in_cart) > $available) {
            \wc_add_notice(
                \sprintf(\__('Доступно только %d шт. на складе.', 'woocommerce'), (int)$available),
                'error'
            );
            return false;
        }

        return $passed;
    } catch (\Throwable $e) {
        if (\function_exists('\\PaintCore\\pc_log')) {
            \PaintCore\pc_log('add_to_cart_validation ERROR: ' . $e->getMessage());
        }
        return $passed; // не валим AJAX
    }
}, 10, 4);

\add_filter('woocommerce_update_cart_validation', function ($passed, $cart_item_key, $values, $quantity) {
    try {
        $product = $values['data'] ?? null;
        if (!($product instanceof \WC_Product)) return $passed;

        $available = \PaintUX\Catalog\pcux_available_qty($product);

        if ($available <= 0) {
            \wc_add_notice(\__('Товар сейчас недоступен на складе.', 'woocommerce'), 'error');
            return false;
        }
        if ($quantity > $available) {
            \wc_add_notice(
                \sprintf(\__('Доступно только %d шт. на складе.', 'woocommerce'), (int)$available),
                'error'
            );
            return false;
        }
        return $passed;
    } catch (\Throwable $e) {
        if (\function_exists('\\PaintCore\\pc_log')) {
            \PaintCore\pc_log('update_cart_validation ERROR: ' . $e->getMessage());
        }
        return $passed;
    }
}, 10, 4);