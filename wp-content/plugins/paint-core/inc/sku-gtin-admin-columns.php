<?php
namespace PaintCore\AdminOrderColumns;

defined('ABSPATH') || exit;

/**
 * Админка: добавить колонки "Артикул" и "GTIN" в списке заказов
 * (классический список + HPOS).
 */

/* ------------ Вставляем колонки после "Итого" ------------ */

// classic posts table
add_filter('manage_edit-shop_order_columns', function($columns){
    return add_my_order_columns($columns);
}, 20);

// HPOS (WooCommerce > Orders)
add_filter('manage_woocommerce_page_wc-orders_columns', function($columns){
    return add_my_order_columns($columns);
}, 20);

function add_my_order_columns(array $columns): array {
    $new = [];
    foreach ($columns as $key => $label) {
        $new[$key] = $label;
        if ($key === 'order_total') {
            // заголовки через i18n
            $new['order_skus']  = esc_html__( 'SKU',  'paint-core' );
            $new['order_gtins'] = esc_html__( 'GTIN', 'paint-core' );
        }
    }
    // если не нашли order_total — добавим в конец
    $new['order_skus']  = $new['order_skus']  ?? esc_html__( 'SKU',  'paint-core' );
    $new['order_gtins'] = $new['order_gtins'] ?? esc_html__( 'GTIN', 'paint-core' );
    return $new;
}

/* ------------ Заполняем колонки значениями ------------ */

// classic posts table
add_action('manage_shop_order_posts_custom_column', function($column, $post_id){
    if ($column === 'order_skus')  { echo esc_html( implode_list_unique( get_order_skus($post_id) ) ); }
    if ($column === 'order_gtins') { echo esc_html( implode_list_unique( get_order_gtins($post_id) ) ); }
}, 10, 2);

// HPOS (wc-orders) — второй аргумент: $order_id
add_action('manage_woocommerce_page_wc-orders_custom_column', function($column, $order_id){
    if ($column === 'order_skus')  { echo esc_html( implode_list_unique( get_order_skus($order_id) ) ); }
    if ($column === 'order_gtins') { echo esc_html( implode_list_unique( get_order_gtins($order_id) ) ); }
}, 10, 2);

/* ------------ Хелперы ------------ */

/**
 * GTIN из продукта/вариации «как есть».
 * Берём первый непустой из списка ключей, без нормализации.
 */
function pc_get_product_gtin(\WC_Product $product): string {
    $candidates = array_filter(array_unique(array_merge(
        [ defined('PC_GTIN_META_KEY') ? PC_GTIN_META_KEY : '' ],
        apply_filters('pc_gtin_meta_keys', [
            '_global_unique_id',
            '_wpm_gtin_code',
            '_alg_ean',
            '_ean',
            '_sku_gtin',
        ])
    )));

    $fetch_raw = function (int $post_id) use ($candidates): string {
        foreach ($candidates as $key) {
            if ($key === '') continue;
            $raw = get_post_meta($post_id, $key, true);
            if ($raw !== '' && $raw !== null) {
                return (string) $raw; // ← без изменений
            }
        }
        return '';
    };

    // 1) у самого товара/вариации
    $v = $fetch_raw($product->get_id());
    if ($v !== '') return $v;

    // 2) если это вариация — пробуем родителя
    if ($product->is_type('variation')) {
        $pid = (int) $product->get_parent_id();
        if ($pid) {
            $v = $fetch_raw($pid);
            if ($v !== '') return $v;
        }
    }

    return '';
}

/** Список уникальных SKU из заказа */
function get_order_skus($order_id): array {
    $order = wc_get_order($order_id);
    if (!$order) return [];

    $set = [];
    foreach ($order->get_items() as $item) {
        if (!($item instanceof \WC_Order_Item_Product)) continue;
        $product = $item->get_product();
        if (!$product) continue;

        $sku = $product->get_sku();
        if (!$sku && $product->is_type('variation')) {
            $parent = wc_get_product($product->get_parent_id());
            if ($parent) $sku = $parent->get_sku();
        }
        if ($sku) $set[$sku] = true;
    }
    return array_values(array_keys($set));
}

/** Список уникальных GTIN из заказа */
function get_order_gtins($order_id): array {
    $order = wc_get_order($order_id);
    if (!$order) return [];

    $set = [];
    foreach ($order->get_items() as $item) {
        if (!($item instanceof \WC_Order_Item_Product)) continue;
        $product = $item->get_product();
        if (!$product) continue;

        $gtin = pc_get_product_gtin($product);
        if ($gtin !== '') $set[$gtin] = true;
    }
    return array_values(array_keys($set));
}

/** Склейка списка, максимум 5 элементов + “+N”, либо тире */
function implode_list_unique(array $list, int $max = 5): string {
    if (empty($list)) return '—';
    if (count($list) > $max) {
        $shown = array_slice($list, 0, $max);
        $more  = count($list) - $max;
        return implode(', ', $shown) . ' +' . (int) $more;
    }
    return implode(', ', $list);
}

// Показать GTIN под "Артикул" в карточке заказа (classic + HPOS)
add_action('woocommerce_after_order_itemmeta', function ($item_id, $item, $product) {
    if (!$product && $item instanceof \WC_Order_Item_Product) {
        $product = $item->get_product();
    }
    if (!($product instanceof \WC_Product)) return;

    // берём «как есть»
    $gtin = pc_get_product_gtin($product);
    if ($gtin === '') return;

    echo '<div class="pc-order-item-gtin"><small>'
        . esc_html__('GTIN', 'paint-core') . ': '
        . esc_html($gtin)
        . '</small></div>';
}, 10, 3);

// Спрятать служебные меты строки заказа из админки (classic + HPOS)
add_filter('woocommerce_hidden_order_itemmeta', function ($hidden) {
    $hidden[] = '_stock_location_id';
    $hidden[] = '_stock_location_slug';
    return $hidden;
}, 10, 1);

/* ------------ Немного стилей для ширины/обрезки ------------ */
add_action('admin_head', function () { ?>
<style>
    .wp-list-table .column-order_skus,
    .wp-list-table .column-order_gtins{
        width: 14%;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
</style>
<?php });