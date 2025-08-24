<?php
namespace PaintCore\Stock;

defined('ABSPATH') || exit;

/**
 * Вывод SKU, GTIN и корректного «Склад: …» в email‑письмах WooCommerce.
 * Источник склада:
 *  1) видимая мета строки заказа "Склад" (если уже записана аллокатором);
 *  2) иначе — собираем из _pc_stock_breakdown (term_id => qty);
 *  3) иначе — пробуем _stock_location_id/_stock_location_slug;
 *  4) иначе — молчим.
 */

/**
 * Сервис: собрать подпись «Київ × 3, Одеса × 2» по данным строки заказа.
 */
function pc_build_location_label_from_item(\WC_Order_Item_Product $item): string {
    // 1) если уже есть видимая мета "Склад" — используем её как есть
    $visible = (string) $item->get_meta(__('Склад','woocommerce'));
    if ($visible !== '') {
        return $visible;
    }

    // 2) план списания _pc_stock_breakdown
    $plan = $item->get_meta('_pc_stock_breakdown', true);
    if (!is_array($plan)) {
        $try = json_decode((string)$plan, true);
        if (is_array($try)) $plan = $try; else $plan = [];
    }

    if (!empty($plan)) {
        // загрузим словарь терминов
        $terms = get_terms(['taxonomy'=>'location','hide_empty'=>false]);
        $dict  = [];
        if (!is_wp_error($terms)) {
            foreach ($terms as $t) $dict[(int)$t->term_id] = $t;
        }

        // красивый порядок: по убыванию количества
        arsort($plan, SORT_NUMERIC);

        $parts = [];
        foreach ($plan as $tid => $qty) {
            $t    = $dict[(int)$tid] ?? null;
            $name = $t ? $t->name : '#'.(int)$tid;
            $parts[] = sprintf('%s × %d', $name, (int)$qty);
        }
        if ($parts) {
            return implode(', ', $parts);
        }
    }

    // 3) fallback: одиночный склад
    $term_id = (int) $item->get_meta('_stock_location_id');
    $slug    = (string) $item->get_meta('_stock_location_slug');
    if ($term_id) {
        $term = get_term($term_id, 'location');
        if ($term && !is_wp_error($term)) return $term->name;
    }
    if ($slug !== '') {
        $term = get_term_by('slug', $slug, 'location');
        if ($term && !is_wp_error($term)) return $term->name;
    }

    return '';
}

/**
 * Выводим блок под названием товара в письмах.
 */
add_action('woocommerce_email_order_item_meta', function($item, $sent_to_admin, $plain, $email){
    if (!($item instanceof \WC_Order_Item_Product)) return;

    $product = $item->get_product();
    $pid     = $product ? $product->get_id() : 0;

    // SKU: учитываем вариации (если у вариации пусто — пробуем родителя)
    $sku = '';
    if ($product) {
        $sku = (string) $product->get_sku();
        if (!$sku && $product->is_type('variation')) {
            $parent = wc_get_product($product->get_parent_id());
            if ($parent) $sku = (string) $parent->get_sku();
        }
    }

    // GTIN: берём с вариации, потом с родителя
    $gtin = '';
    if ($pid) {
        $gtin = (string) get_post_meta($pid, '_global_unique_id', true);
        if (!$gtin && $product && $product->is_type('variation')) {
            $parent = wc_get_product($product->get_parent_id());
            if ($parent) $gtin = (string) get_post_meta($parent->get_id(), '_global_unique_id', true);
        }
    }

    // Склад(ы)
    $loc_label = pc_build_location_label_from_item($item);

    // Сборка строки
    $parts = [];
    if ($sku !== '')  $parts[] = 'Артикул: ' . $sku;
    if ($gtin !== '') $parts[] = 'GTIN: '   . $gtin;

    // выводим
    if ($plain) {
        if ($parts) echo "\n" . implode(' | ', $parts) . "\n";
        if ($loc_label !== '') echo __('Склад','woocommerce') . ': ' . $loc_label . "\n";
    } else {
        if ($parts) {
            echo '<br><small style="color:#555">' . esc_html(implode(' | ', $parts)) . '</small>';
        }
        if ($loc_label !== '') {
            echo '<br><small style="color:#555">'
               . esc_html__('Склад','woocommerce') . ': ' . esc_html($loc_label)
               . '</small>';
        }
    }
}, 10, 4);