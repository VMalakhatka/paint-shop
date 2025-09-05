<?php
namespace PaintCore\Stock;

defined('ABSPATH') || exit;

/**
 * Email: SKU, GTIN і коректний «Склад: …» під назвою товару.
 * Джерело складу:
 *  1) видима мета рядка "Склад" (від аллокатора, якщо вже записана);
 *  2) _pc_stock_breakdown (term_id => qty), з іменами термінів;
 *  3) _stock_location_id / _stock_location_slug;
 *  4) інакше — пусто.
 */

/** ========================= Лейбли (можна перевизначити фільтром) ========================= */
function pc_email_labels(): array {
    $L = [
        'sku'      => 'Артикул',
        'gtin'     => 'GTIN',
        'location' => __('Склад','woocommerce'),
    ];
    return apply_filters('pc_email_meta_labels', $L);
}

/** ========================= GTIN (без нормалізації) ========================= */

/**
 * Отримати GTIN із продукту/варіації:
 * - якщо існує глобальний хелпер \pc_get_product_gtin() — використовуємо його;
 * - інакше шукаємо “як є” в PC_GTIN_META_KEY або у списку кандидатів;
 * - для варіацій: якщо порожньо — дивимось у батька.
 */
function pc_email_get_product_gtin(\WC_Product $product): string {
    // 1) Глобальний хелпер (якщо підключений десь у твоєму коді)
    if (function_exists('\\pc_get_product_gtin')) {
        $v = \pc_get_product_gtin($product);
        if ($v !== '' && $v !== null) return (string) $v;
    }

    // 2) Локальний пошук без нормалізації (як є)
    $candidates = array_filter(array_unique(array_merge(
        [ defined('PC_GTIN_META_KEY') ? PC_GTIN_META_KEY : '' ],
        apply_filters('pc_gtin_meta_keys', [
            '_global_unique_id',   // ваш ключ
            '_wpm_gtin_code',      // WebToffee/Woo product GTIN
            '_alg_ean',            // EAN/UPC/GTIN by Alg
            '_ean',
            '_sku_gtin',
        ])
    )));

    $fetch_raw = function(int $post_id) use ($candidates): string {
        foreach ($candidates as $key) {
            if ($key === '') continue;
            $raw = get_post_meta($post_id, $key, true);
            if ($raw !== '' && $raw !== null) {
                return (string) $raw; // повертаємо як є (можуть бути букви)
            }
        }
        return '';
    };

    // Спочатку сама варіація/товар
    $v = $fetch_raw($product->get_id());
    if ($v !== '') return $v;

    // Якщо варіація — пробуємо батька
    if ($product->is_type('variation')) {
        $pid = (int)$product->get_parent_id();
        if ($pid) {
            $v = $fetch_raw($pid);
            if ($v !== '') return $v;
        }
    }
    return '';
}

/** ========================= Локації (із рядка замовлення) ========================= */

/**
 * Побудувати підпис «Київ × 3, Одеса × 2» по даним рядка замовлення.
 */
function pc_build_location_label_from_item(\WC_Order_Item_Product $item): string {
    $visible = (string) $item->get_meta(__('Склад','woocommerce'));
    if ($visible !== '') {
        return $visible;
    }

    $plan = $item->get_meta('_pc_stock_breakdown', true);
    if (!is_array($plan)) {
        $try = json_decode((string)$plan, true);
        if (is_array($try)) $plan = $try; else $plan = [];
    }

    if (!empty($plan)) {
        $terms = get_terms(['taxonomy'=>'location','hide_empty'=>false]);
        $dict  = [];
        if (!is_wp_error($terms)) {
            foreach ($terms as $t) $dict[(int)$t->term_id] = $t;
        }

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

/** ========================= Вивід під назвою товару ========================= */

add_action('woocommerce_email_order_item_meta', function($item, $sent_to_admin, $plain, $email){
    if (!($item instanceof \WC_Order_Item_Product)) return;

    $L = pc_email_labels();

    $product = $item->get_product();

    // SKU (для варіацій: якщо пусто у варіації — беремо у батька)
    $sku = '';
    if ($product instanceof \WC_Product) {
        $sku = (string) $product->get_sku();
        if (!$sku && $product->is_type('variation')) {
            $parent = wc_get_product($product->get_parent_id());
            if ($parent) $sku = (string) $parent->get_sku();
        }
    }

    // GTIN — як є (без нормалізації)
    $gtin = '';
    if ($product instanceof \WC_Product) {
        $gtin = pc_email_get_product_gtin($product);
    }

    // Склад(и)
    $loc_label = pc_build_location_label_from_item($item);

    // Збірка рядка
    $parts = [];
    if ($sku !== '')  $parts[] = $L['sku'].': '.$sku;
    if ($gtin !== '') $parts[] = $L['gtin'].': '.$gtin;

    if ($plain) {
        if ($parts) echo "\n" . implode(' | ', $parts) . "\n";
        if ($loc_label !== '') echo $L['location'] . ': ' . $loc_label . "\n";
    } else {
        if ($parts) {
            echo '<br><small style="color:#555">' . esc_html(implode(' | ', $parts)) . '</small>';
        }
        if ($loc_label !== '') {
            echo '<br><small style="color:#555">'
               . esc_html($L['location']) . ': ' . esc_html($loc_label)
               . '</small>';
        }
    }
}, 10, 4);