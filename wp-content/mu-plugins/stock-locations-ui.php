<?php
/*
Plugin Name: Stock Locations UI (archive + single + cart)
Description: Универсальный блок складов/остатков + план списания. Работает в карточке, каталоге и корзине/чекауте.
Version: 1.1.2
Author: PaintCore
*/
if (!defined('ABSPATH')) exit;

/** ========================= HELPERS ========================= */

if (!function_exists('slu_get_primary_location_term_id')) {
    function slu_get_primary_location_term_id($product_id){
        $pid = (int)$product_id; if(!$pid) return 0;
        $term_id = (int)get_post_meta($pid, '_yoast_wpseo_primary_location', true);
        if(!$term_id){
            $parent_id = (int)wp_get_post_parent_id($pid);
            if($parent_id){
                $term_id = (int)get_post_meta($parent_id, '_yoast_wpseo_primary_location', true);
            }
        }
        return $term_id ?: 0;
    }
}

if (!function_exists('slu_total_available_qty')) {
    /** сумма остатков по всем складам (с кешем в _stock, фолбэк — суммирование _stock_at_%) */
    function slu_total_available_qty(WC_Product $product){
        $pid = (int)$product->get_id();
        $sum = get_post_meta($pid, '_stock', true);
        if ($sum !== '' && $sum !== null) {
            return max(0, (int)round((float)$sum));
        }
        global $wpdb;
        $rows = $wpdb->get_col($wpdb->prepare(
            "SELECT meta_value FROM {$wpdb->postmeta}
             WHERE post_id=%d AND meta_key LIKE %s",
            $pid, $wpdb->esc_like('_stock_at_').'%'
        ));
        $total = 0.0;
        foreach ((array)$rows as $v) $total += (float)$v;

        if ($total <= 0 && $product->is_type('variation')) {
            $parent_id = (int)$product->get_parent_id();
            if ($parent_id) {
                $rows = $wpdb->get_col($wpdb->prepare(
                    "SELECT meta_value FROM {$wpdb->postmeta}
                     WHERE post_id=%d AND meta_key LIKE %s",
                    $parent_id, $wpdb->esc_like('_stock_at_').'%'
                ));
                foreach ((array)$rows as $v) $total += (float)$v;
            }
        }
        return max(0, (int)round($total));
    }
}

// доступное к добавлению = общий остаток минус то, что уже в корзине
if (!function_exists('slu_available_for_add')) {
    function slu_available_for_add(WC_Product $product): int {
        $total   = slu_total_available_qty($product);
        $in_cart = slu_cart_qty_for_product($product);
        return max(0, (int)$total - (int)$in_cart);
    }
}

if (!function_exists('slu_cart_qty_for_product')) {
    /** сколько этого товара/вариации уже в корзине */
    function slu_cart_qty_for_product(WC_Product $product): int{
        $pid = (int)$product->get_id();
        $vid = $product->is_type('variation') ? $pid : 0;
        $sum = 0;
        if (WC()->cart) {
            foreach (WC()->cart->get_cart() as $item) {
                $p = (int)($item['product_id'] ?? 0);
                $v = (int)($item['variation_id'] ?? 0);
                if ($p === $pid && $v === $vid) $sum += (int)($item['quantity'] ?? 0);
            }
        }
        return $sum;
    }
}

if (!function_exists('slu_collect_location_stocks_for_product')) {
    /** собрать “имя — qty” по всем складам, привязанным к товару */
    function slu_collect_location_stocks_for_product(WC_Product $product): array{
        $result   = [];
        $term_ids = wp_get_object_terms($product->get_id(), 'location', ['fields'=>'ids','hide_empty'=>false]);
        if (is_wp_error($term_ids)) $term_ids = [];

        if (empty($term_ids)) {
            $term_ids = get_terms(['taxonomy'=>'location','fields'=>'ids','hide_empty'=>false]);
            if (is_wp_error($term_ids)) $term_ids = [];
        }
        if (empty($term_ids)) return $result;

        $is_var    = $product->is_type('variation');
        $parent_id = $is_var ? (int)$product->get_parent_id() : 0;

        foreach ($term_ids as $tid) {
            $tid = (int)$tid;
            $meta_key = '_stock_at_'.$tid;
            $qty = get_post_meta($product->get_id(), $meta_key, true);
            if (($qty === '' || $qty === null) && $is_var && $parent_id) {
                $qty = get_post_meta($parent_id, $meta_key, true);
            }
            $qty = ($qty === '' || $qty === null) ? null : (int)$qty;
            if ($qty === null) continue;

            $term = get_term($tid, 'location');
            if (!$term || is_wp_error($term)) continue;

            $result[$tid] = ['name'=>$term->name, 'qty'=>$qty];
        }
        return $result;
    }
}

if (!function_exists('slu_render_other_locations_line')) {
    function slu_render_other_locations_line(WC_Product $product): string{
        $primary = slu_get_primary_location_term_id($product->get_id());
        $all     = slu_collect_location_stocks_for_product($product);
        if (empty($all)) return '';
        $parts = [];
        foreach ($all as $tid=>$row) {
            if ($primary && (int)$tid === (int)$primary) continue;
            if ((int)$row['qty'] <= 0) continue;
            $parts[] = esc_html($row['name']).' — '.(int)$row['qty'];
        }
        return $parts ? implode(', ', $parts) : '';
    }
}

if (!function_exists('slu_render_primary_location_line')) {
    function slu_render_primary_location_line(WC_Product $product): string{
        $primary = slu_get_primary_location_term_id($product->get_id());
        if (!$primary) return '';
        $meta_key = '_stock_at_'.(int)$primary;
        $qty = get_post_meta($product->get_id(), $meta_key, true);
        if (($qty === '' || $qty === null) && $product->is_type('variation')) {
            $parent_id = $product->get_parent_id();
            if ($parent_id) $qty = get_post_meta($parent_id, $meta_key, true);
        }
        $qty  = ($qty === '' || $qty === null) ? null : (int)$qty;
        $term = get_term($primary, 'location');
        if (!$term || is_wp_error($term)) return '';
        return esc_html($term->name).( $qty !== null ? ' — '.(int)$qty : '' );
    }
}

/** =================== ПЛАН СПИСАНИЯ (АЛГОРИТМ) =================== */

if (!function_exists('slu_get_allocation_plan')) {
    /**
     * Вернёт массив [ term_id => qty_to_deduct ] для нужного количества $need.
     * Приоритет: primary -> остальные (по убыванию остатка). Переопределяемо фильтром 'slu_allocation_plan'.
     */
    function slu_get_allocation_plan(WC_Product $product, int $need, string $strategy='primary_first'): array{
        $need = max(0,(int)$need);
        if ($need === 0) return [];
        $custom = apply_filters('slu_allocation_plan', null, $product, $need, $strategy);
        if (is_array($custom)) return $custom;

        $all = slu_collect_location_stocks_for_product($product);
        if (empty($all)) return [];

        $primary_id = (int)slu_get_primary_location_term_id($product->get_id());

        $ordered = [];
        if ($primary_id && isset($all[$primary_id])) {
            $ordered[$primary_id] = $all[$primary_id];
            unset($all[$primary_id]);
        }
        uasort($all, function($a,$b){ return (int)$b['qty'] <=> (int)$a['qty']; });
        $ordered += $all;

        $plan = []; $left = $need;
        foreach ($ordered as $tid=>$row) {
            if ($left <= 0) break;
            $take = min($left, max(0,(int)$row['qty']));
            if ($take > 0) { $plan[(int)$tid] = $take; $left -= $take; }
        }
        return $plan;
    }
}

if (!function_exists('slu_render_allocation_line')) {
    /** "Київ — 2, Одеса — 1" по плану списания */
    function slu_render_allocation_line(WC_Product $product, int $need): string{
        $plan = slu_get_allocation_plan($product, $need);
        if (empty($plan)) return '';
        $parts = [];
        foreach ($plan as $tid=>$qty) {
            $t = get_term((int)$tid, 'location');
            if ($t && !is_wp_error($t)) $parts[] = esc_html($t->name).' — '.(int)$qty;
        }
        return implode(', ', $parts);
    }
}

/** =================== УНИВЕРСАЛЬНЫЙ ПАНЕЛЬ-БЛОК =================== */

if (!function_exists('slu_render_stock_panel')) {
    /**
     * $opts:
     *  - show_primary, show_others, show_total, show_incart, show_incart_plan
     *  - hide_when_zero (bool) — спрятать блок, если total <= 0
     *  - wrap_class (string)
     */
    function slu_render_stock_panel(WC_Product $product, array $opts=[]): string{
        $o = array_merge([
            'show_primary'     => true,
            'show_others'      => true,
            'show_total'       => true,
            'show_incart'      => true,
            'show_incart_plan' => true,
            'hide_when_zero'   => false,
            'wrap_class'       => '',
        ], $opts);

        $primary_line = $o['show_primary'] ? slu_render_primary_location_line($product) : '';
        $others_line  = $o['show_others']  ? slu_render_other_locations_line($product) : '';
        $total        = $o['show_total']   ? slu_total_available_qty($product) : null;
        $in_cart      = $o['show_incart']  ? slu_cart_qty_for_product($product) : null;

        if ($o['hide_when_zero'] && $total !== null && (int)$total <= 0) return '';
        if (!$primary_line && !$others_line && $total === null && $in_cart === null) return '';

        ob_start(); ?>
        <div class="slu-stock-box <?= esc_attr($o['wrap_class']) ?>">
            <?php if ($primary_line): ?>
                <div><strong><?= esc_html__('Заказ со склада','woocommerce') ?>:</strong> <?= $primary_line ?></div>
            <?php endif; ?>
            <?php if ($others_line): ?>
                <div><strong><?= esc_html__('Другие склады','woocommerce') ?>:</strong> <?= $others_line ?></div>
            <?php endif; ?>
            <?php if ($total !== null): ?>
                <div><strong><?= esc_html__('Всего','woocommerce') ?>:</strong> <?= (int)$total ?></div>
            <?php endif; ?>
            <?php if ($in_cart !== null): ?>
                <div><strong><?= esc_html__('В корзине','woocommerce') ?>:</strong> <?= (int)$in_cart ?></div>
                <?php if ($o['show_incart_plan'] && (int)$in_cart > 0):
                    $alloc = slu_render_allocation_line($product, (int)$in_cart);
                    if ($alloc): ?>
                        <div style="color:#555"><strong><?= esc_html__('Списание','woocommerce') ?>:</strong> <?= $alloc ?></div>
                    <?php endif;
                endif; ?>
            <?php endif; ?>
        </div>
        <?php
        return (string)ob_get_clean();
    }
}

/** =================== ВСТАВКИ В ШАБЛОНЫ =================== */

/* PDP */
add_action('woocommerce_single_product_summary', function(){
    global $product;
    if (!($product instanceof WC_Product)) return;
    echo slu_render_stock_panel($product, [
        'show_primary'     => true,
        'show_others'      => true,
        'show_total'       => true,
        'show_incart'      => true,
        'show_incart_plan' => true,
        'hide_when_zero'   => false,
        'wrap_class'       => '',
    ]);
}, 25);

/* Каталог */
add_action('woocommerce_after_shop_loop_item_title', function(){
    global $product;
    if (!($product instanceof WC_Product)) return;
    echo slu_render_stock_panel($product, [
        'show_primary'     => true,
        'show_others'      => true,
        'show_total'       => true,
        'show_incart'      => false,
        'show_incart_plan' => false,
        'hide_when_zero'   => true,
        'wrap_class'       => 'slu-stock-mini',
    ]);
}, 11);

/** =================== КОРЗИНА / ЧЕКАУТ =================== */

/* отключаем «старые» строки в paint-core (если он таковые добавляет) 
 add_filter('pc_disable_legacy_cart_locations', '__return_true');
*/

/* добавляем нашу строку "Списание" — только на страницах корзины/чекаута */
if (!function_exists('slu_cart_allocation_row')) {
    function slu_cart_allocation_row($item_data, $cart_item){
        if (!is_array($item_data)) $item_data = [];

        // работаем только в корзине/чекауте
        $is_ctx = (function_exists('is_cart') && is_cart()) || (function_exists('is_checkout') && is_checkout());
        if (!$is_ctx) return $item_data;

        $product = $cart_item['data'] ?? null;
        if (!($product instanceof WC_Product)) return $item_data;

        $qty = max(0, (int)($cart_item['quantity'] ?? 0));
        if ($qty <= 0) return $item_data;

        $line = slu_render_allocation_line($product, $qty);
        if ($line !== '') {
            $item_data[] = [
                'key'     => __('Списание','woocommerce'),
                'value'   => $line,
                'display' => $line,
            ];
        }
        return $item_data;
    }
}
add_filter('woocommerce_get_item_data', 'slu_cart_allocation_row', 30, 2);

/** =================== (опц.) Шорткод для любых мест =================== */
// [pc_stock_allocation product_id="43189" qty="3"]
add_shortcode('pc_stock_allocation', function($atts){
    $a = shortcode_atts(['product_id'=>0,'qty'=>0], $atts, 'pc_stock_allocation');
    $prod = wc_get_product((int)$a['product_id']);
    if (!($prod instanceof WC_Product)) return '';
    $qty  = max(0,(int)$a['qty']);
    $line = slu_render_allocation_line($prod, $qty);
    if (!$line) return '';
    return '<div class="slu-stock-box slu-stock-mini"><strong>'.esc_html__('Списание','woocommerce').':</strong> '.esc_html($line).'</div>';
});

/** =================== Немного CSS (можно перенести в общие стили) =================== */
add_action('wp_head', function(){
    echo '<style>
    .single-product .slu-stock-box{border:1px dashed #e0e0e0;padding:8px 10px;border-radius:6px;background:#fafafa;font-size:14px;color:#333;margin:10px 0 6px}
    .products .slu-stock-mini{border:0;padding:0;margin-top:6px;background:transparent;font-size:12px;line-height:1.25;color:#2e7d32}
    .products .slu-stock-mini div{margin:0 0 2px}
    .products .slu-stock-mini strong{color:#333;font-weight:600}
    @media (max-width:480px){.products .slu-stock-mini{font-size:11px;margin-top:4px}}
    </style>';
});