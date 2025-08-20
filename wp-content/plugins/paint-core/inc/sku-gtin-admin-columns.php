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
            $new['order_skus']  = 'Артикул';
            $new['order_gtins'] = 'GTIN';
        }
    }
    // если не нашли order_total
    $new['order_skus']  = $new['order_skus']  ?? 'Артикул';
    $new['order_gtins'] = $new['order_gtins'] ?? 'GTIN';
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

/** Универсально вытащить GTIN у продукта/вариации, пробуя популярные ключи меты */
function pc_get_product_gtin(\WC_Product $product): string {
    $keys = [
        '_global_unique_id', // как было у тебя
        '_wpm_gtin_code',    // WebToffee/Woo product GTIN
        '_alg_ean',          // EAN/UPC/GTIN by Alg
        '_ean',              // встречается у ряда плагинов
        '_sku_gtin',         // на всякий случай
    ];

    // сперва у самой записи товара/вариации
    foreach ($keys as $k) {
        $v = get_post_meta($product->get_id(), $k, true);
        if ($v !== '' && $v !== null) return (string) $v;
    }

    // если вариация — пробуем родителя
    if ($product->is_type('variation')) {
        $parent_id = $product->get_parent_id();
        if ($parent_id) {
            foreach ($keys as $k) {
                $v = get_post_meta($parent_id, $k, true);
                if ($v !== '' && $v !== null) return (string) $v;
            }
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
    return array_keys($set);
}

/** Список уникальных GTIN из заказа (с поддержкой разных мета-ключей) */
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
    return array_keys($set);
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