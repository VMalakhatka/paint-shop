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

/** === SINGLE mode helpers === */
function pc_pref_single_mode_term(): array {
    if (function_exists('\\pc_get_alloc_pref')) {
        $p = \pc_get_alloc_pref();
        return [$p['mode'] ?? 'auto', (int)($p['term_id'] ?? 0)];
    }
    return ['auto', 0];
}
function pc_qty_on_term(\WC_Product $product, int $term_id): int {
    $pid = (int)$product->get_id();
    $key = '_stock_at_'.(int)$term_id;
    $v   = get_post_meta($pid, $key, true);
    if (($v === '' || $v === null) && $product->is_type('variation')) {
        $parent = (int)$product->get_parent_id();
        if ($parent) $v = get_post_meta($parent, $key, true);
    }
    return max(0, (int)round((float)($v === '' || $v === null ? 0 : $v)));
}

/* ===== Доступное количество (Всего) ===== */
function pcux_available_qty(\WC_Product $product): int {
    // SINGLE: только выбранный склад
    [$mode, $tid] = pc_pref_single_mode_term();
    if ($mode === 'single' && $tid > 0) {
        return pc_qty_on_term($product, $tid);
    }

    // иначе — общий остаток (как было)
    if (function_exists('\\slu_total_available_qty')) {
        return (int) max(0, (int) \slu_total_available_qty($product));
    }
    global $wpdb;
    $pid = (int) $product->get_id();
    $sum = 0.0;
    $stock = get_post_meta($pid, '_stock', true);
    if ($stock !== '' && $stock !== null) {
        $sum = (float) $stock;
    } else {
        $rows = $wpdb->get_col($wpdb->prepare(
            "SELECT meta_value FROM {$wpdb->postmeta}
             WHERE post_id=%d AND meta_key LIKE %s",
            $pid, $wpdb->esc_like('_stock_at_') . '%'
        ));
        foreach ((array)$rows as $v) $sum += (float)$v;
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

/* ===== Доступно к добавлению (остаток минус уже в корзине) ===== */
function pcux_available_for_add(\WC_Product $product): int {
    // базовый доступный остаток (уже учитывает SINGLE через п.2)
    $total = (int) pcux_available_qty($product);

    // уже в корзине — считаем по конкретному продукту/вариации
    $in_cart = 0;
    if (function_exists('WC') && WC() && WC()->cart) {
        foreach (WC()->cart->get_cart() as $item) {
            $pid = (int)($item['product_id'] ?? 0);
            $vid = (int)($item['variation_id'] ?? 0);
            if ($product->is_type('variation')) {
                if ($vid === (int)$product->get_id()) $in_cart += (int)($item['quantity'] ?? 0);
            } else {
                if ($pid === (int)$product->get_id()) $in_cart += (int)($item['quantity'] ?? 0);
            }
        }
    }
    return max(0, $total - $in_cart);
}

// SINGLE: если на выбранном складе 0 — товар нельзя купить
add_filter('woocommerce_is_purchasable', function($ok, $product){
    if (!($product instanceof \WC_Product)) return $ok;
    [$mode, $tid] = pc_pref_single_mode_term();
    if ($mode !== 'single' || $tid <= 0) return $ok;
    return pc_qty_on_term($product, $tid) > 0;
}, 10, 2);

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

    $available   = pcux_available_for_add($product);
    $cfg         = $GLOBALS['wc_loop_cfg'] ?? [];
    $min         = max(1, (int)($cfg['min'] ?? 1));
    $pid         = $product->get_id();

    // если нет остатков — рисуем контейнер disabled (чтобы сетка не ломалась)
    $max         = (int) max(0, $available);
    $is_disabled = ($max <= 0);
    $val         = $is_disabled ? 0 : min($min, $max);

    echo '<div class="loop-qty-wrap'.($is_disabled?' is-disabled':'').'" data-product-id="'.esc_attr($pid).'" data-max="'.esc_attr($max).'">';

    if (!empty($cfg['show_plus_minus'])) {
        echo '<button type="button" class="loop-qty-btn loop-qty-minus" aria-label="' . esc_attr__( 'Minus', 'paint-core' ) . '" '.($is_disabled?'disabled':'').'>−</button>';
    }

    echo '<input type="text" inputmode="numeric" pattern="[0-9]*" class="input-text qty text loop-qty" '
        .'value="'.esc_attr($val).'" min="'.esc_attr($min).'" '
        .($max>0 ? 'max="'.esc_attr($max).'" ' : '')
        .'step="1" '.($is_disabled?'disabled':'').' />';

    echo '<span class="loop-qty-view" aria-hidden="true">'.esc_html($val).'</span>';

    if (!empty($cfg['show_plus_minus'])) {
        echo '<button type="button" class="loop-qty-btn loop-qty-plus" aria-label="' . esc_attr__( 'Plus', 'paint-core' ) . '" '.($is_disabled?'disabled':'').'>+</button>';
    }

    echo '</div>';
}, 9);

/* ===== Маркер под ценой: показываем ТОЛЬКО "Нет в наличии" ===== */
add_action('woocommerce_after_shop_loop_item_title', function () {
    global $product;
    if (!($product instanceof \WC_Product)) return;

    $is_out = false;

    if (function_exists('pc_build_stock_view')) {
        $v = pc_build_stock_view($product);

        if (($v['mode'] ?? 'auto') === 'single') {
            // режим "Только выбранный склад": смотрим остаток только по выбранному
            $qty = 0;
            if (!empty($v['ordered'])) {
                $row = reset($v['ordered']);
                $qty = (int)($row['qty'] ?? 0);
            }
            $is_out = ($qty <= 0);
        } else {
            // авто/приоритет: нет суммарного остатка
            $is_out = ((int)($v['sum'] ?? 0) <= 0);
        }
    } else {
        // фолбэк на стандартный Woo
        $is_out = !$product->is_in_stock();
    }

    if ($is_out) {
        echo '<span class="loop-stock-top out" data-stock="0">' .
            esc_html__( 'Out of stock', 'paint-core' ) .
            '</span>';
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
/* ===== Серверные проверки кол-ва (не больше доступного) ===== */

// Вспомогалка: доступно к добавлению прямо сейчас
function _pcux_available_now(\WC_Product $prod, int $product_id, int $variation_id = 0): int {
    // 1) если есть наша функция — используем её
    if (\function_exists('\\PaintUX\\Catalog\\pcux_available_for_add')) {
        return (int) \PaintUX\Catalog\pcux_available_for_add($prod);
    }

    // 2) фолбэк: общий остаток минус уже в корзине
    $total = 0;
    if (\function_exists(__NAMESPACE__ . '\\pcux_available_qty')) {
        // т.к. мы уже в том же namespace, можно вызвать коротко:
        $total = (int) pcux_available_qty($prod);
        // или полностью: $total = (int) \PaintUX\Catalog\pcux_available_qty($prod);
    }

    $in_cart = 0;
    $cart = (\function_exists('\\WC') && \WC()) ? \WC()->cart : null;
    if ($cart && \is_object($cart)) {
        foreach ($cart->get_cart() as $item) {
            $pid = (int)($item['product_id'] ?? 0);
            $vid = (int)($item['variation_id'] ?? 0);
            if ($pid === $product_id && $vid === ($variation_id ?: 0)) {
                $in_cart += (int)($item['quantity'] ?? 0);
            }
        }
    }

    return max(0, $total - $in_cart);
}

/** Добавление в корзину (AJAX/не AJAX) */
\add_filter('woocommerce_add_to_cart_validation',
function ($passed, $product_id, $qty, $variation_id = 0) {
    try {
        $prod = \wc_get_product($variation_id ?: $product_id);
        if (!($prod instanceof \WC_Product)) return $passed;

        $available = _pcux_available_now($prod, (int)$product_id, (int)$variation_id);

        if ($available <= 0) {
            \wc_add_notice( \__( 'This product is currently unavailable in stock.', 'paint-core' ), 'error' );
            return false;
        }
        if ((int)$qty > (int)$available) {
            \wc_add_notice(
                 /* translators: %d: number of units available in stock */
                \sprintf( \__( 'Only %d units are available in stock.', 'paint-core' ), (int) $available ),
                'error'
            );
            return false;
        }
        return $passed;
    } catch (\Throwable $e) {
        if (\function_exists('\\PaintCore\\pc_log')) {
            \PaintCore\pc_log('add_to_cart_validation ERROR: ' . $e->getMessage());
        }
        return $passed; // не роняем процесс
    }
}, 10, 4);


/** Обновление количества существующей строки корзины */
\add_filter('woocommerce_update_cart_validation',
function ($passed, $cart_item_key, $values, $new_qty) {
    try {
        /** @var \WC_Product|null $product */
        $product = $values['data'] ?? null;
        if (!($product instanceof \WC_Product)) return $passed;

        $pid = (int)($values['product_id'] ?? $product->get_id());
        $vid = (int)($values['variation_id'] ?? 0);
        $current_line_qty = (int)($values['quantity'] ?? 0); // сколько было ДО обновления

        // Сколько ещё можно добавить сверх уже лежащего (по всем строкам)
        $available_for_add = _pcux_available_now($product, $pid, $vid);

        // При апдейте текущей строки мы «возвращаем» её старое кол-во во доступ
        $available_for_update = max(0, $available_for_add + $current_line_qty);

        if ($available_for_update <= 0) {
            \wc_add_notice( \__( 'This product is currently unavailable in stock.', 'paint-core' ), 'error' );
            return false;
        }
        if ((int)$new_qty > (int)$available_for_update) {
            \wc_add_notice(
                 /* translators: %d: number of units available in stock */
                \sprintf( \__( 'Only %d units are available in stock.', 'paint-core' ), (int) $available_for_update ),
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

// ===== PDP: ограничиваем qty по "доступно к добавлению" =====

/** Доступно к добавлению для текущего товара (с учётом корзины) */
function pcux_single_available_for_add( \WC_Product $product ): int {
    // 1) если есть функция из MU — используем её
    if ( function_exists('\\slu_available_for_add') ) {
        return max(0, (int) \slu_available_for_add($product));
    }

    // 2) фолбэк: общее - уже в корзине (для simple достаточно)
    $total = function_exists('\\PaintUX\\Catalog\\pcux_available_qty')
        ? (int) \PaintUX\Catalog\pcux_available_qty($product)
        : 0;

    $in_cart = 0;
    if ( function_exists('WC') && WC() && WC()->cart ) {
        foreach ( WC()->cart->get_cart() as $item ) {
            $pid = (int) ($item['product_id'] ?? 0);
            $vid = (int) ($item['variation_id'] ?? 0);
            if ( $product->is_type('variation') ) {
                if ( $vid === (int) $product->get_id() ) $in_cart += (int) ($item['quantity'] ?? 0);
            } else {
                if ( $pid === (int) $product->get_id() ) $in_cart += (int) ($item['quantity'] ?? 0);
            }
        }
    }
    return max(0, $total - $in_cart);
}

/**
 * Подставляем min/max/value в поле количества на PDP
 */
// PDP: жёстко ограничиваем qty доступным к добавлению (учитывает уже лежащее в корзине)
add_filter('woocommerce_quantity_input_args', function(array $args, $product){
    // только страница товара и только реальный продукт
    if ( ! function_exists('is_product') || ! is_product() ) return $args;
    if ( ! ($product instanceof \WC_Product) ) return $args;

    // (упрощение) вариативные пропустим — для них нужен отдельный хук на выбор вариации
    if ( $product->is_type('variable') ) return $args;

    // сколько ещё можно добавить прямо сейчас
    if (function_exists('\\slu_available_for_add')) {
        $avail = (int) \slu_available_for_add($product);
    } elseif (function_exists(__NAMESPACE__.'\\pcux_available_for_add')) {
        $avail = (int) \PaintUX\Catalog\pcux_available_for_add($product);
    } else {
        // самый грубый фолбэк
        $avail = max(0, (int) $product->get_stock_quantity());
    }

    if ($avail <= 0) {
        // ничего нельзя — показываем 0 и блокируем
        $args['min_value']   = 0;
        $args['max_value']   = 0;
        $args['input_value'] = 0;
        $args['classes'][]   = 'qty--disabled';
        return $args;
    }

    $min = 1;
    $cur = isset($args['input_value']) ? (int)$args['input_value'] : $min;

    $args['min_value']   = $min;
    $args['max_value']   = $avail;
    $args['input_value'] = min(max($min, $cur), $avail);

    return $args;
}, 999, 2);