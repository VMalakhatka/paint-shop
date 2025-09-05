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

/**
 * Отримати GTIN із продукту/варіації.
 * - Пріоритет: константа PC_GTIN_META_KEY, далі — список кандидатів
 * - Нормалізуємо: лишаємо тільки цифри
 * - Фільтр: приймаємо тільки довжини 8/12/13/14 (GTIN-8/UPC-A/EAN-13/GTIN-14)
 * - Для підозрілих (коротких) повертаємо ''.
 */
function pc_get_product_gtin(WC_Product $product): string {
    $candidates = array_filter(array_unique(array_merge(
        [ defined('PC_GTIN_META_KEY') ? PC_GTIN_META_KEY : '' ],
        apply_filters('pc_gtin_meta_keys', [
            '_global_unique_id',   // твій на сайті
            '_wpm_gtin_code',
            '_alg_ean',
            '_ean',
            '_sku_gtin',
        ])
    )));

    $fetch = function(int $post_id) use ($candidates): string {
        foreach ($candidates as $key) {
            if ($key === '') continue;
            $raw = get_post_meta($post_id, $key, true);
            if ($raw === '' || $raw === null) continue;
            // нормалізація: тільки цифри
            $digits = preg_replace('/\D+/', '', (string)$raw);
            $len = strlen($digits);
            if (in_array($len, [8,12,13,14], true)) {
                return $digits;
            }
        }
        return '';
    };

    // спершу сам товар/варіація
    $v = $fetch($product->get_id());
    if ($v !== '') return $v;

    // якщо варіація — спробувати батька
    if ($product->is_type('variation')) {
        $pid = (int)$product->get_parent_id();
        if ($pid) {
            $v = $fetch($pid);
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