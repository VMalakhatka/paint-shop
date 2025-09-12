<?php
namespace PaintCore\Stock;

defined('ABSPATH') || exit;

/**
 * UI для складов:
 *  - корзина/чекаут: показываем Primary + «Другие склады» (информативно до оформления);
 *  - заказ/письма: показываем ФАКТИЧЕСКИЙ склад списания.
 *
 * Фактический склад берём в приоритете из мет строки заказа плагина
 * Stock Locations for WooCommerce: `_stock_locations` (массив term_id) / `_stock_location` (term_id).
 * Если их нет — используем нашу мету/подпись, которую пишет inc/order-stock-locations.php после редукции.
 * Если и её нет — уже в самый последний момент падаем на Primary у товара.
 *
 * Требования: таксономия `location`, меты остатков `_stock_at_{TERM_ID}`,
 * primary в `_yoast_wpseo_primary_location`.
 */

/* ====================== ХЕЛПЕРЫ (товар) ====================== */
function primary_location_term_id( $product_id ) {
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

function primary_location_name_for_product( \WC_Product $product ) {
    $term_id = primary_location_term_id( $product->get_id() );
    if ( ! $term_id ) return '';
    $term = get_term( $term_id, 'location' );
    return ( $term && ! is_wp_error( $term ) ) ? $term->name : '';
}

function qty_at_primary_location_for_product( \WC_Product $product ) {
    $term_id = primary_location_term_id( $product->get_id() );
    if ( ! $term_id ) return null;

    $meta_key = '_stock_at_' . $term_id;
    $qty = get_post_meta( $product->get_id(), $meta_key, true );

    if ( ($qty === '' || $qty === null) && $product->is_type('variation') ) {
        $parent_id = $product->get_parent_id();
        if ( $parent_id ) $qty = get_post_meta( $parent_id, $meta_key, true );
    }
    return ($qty === '' || $qty === null) ? null : (int) $qty;
}

function other_locations_stock_for_product( \WC_Product $product ) {
    $primary_id   = primary_location_term_id( $product->get_id() );
    $primary_name = primary_location_name_for_product( $product );

    $terms = get_terms([
        'taxonomy'   => 'location',
        'hide_empty' => false,
    ]);
    if ( is_wp_error($terms) || empty($terms) ) return [];

    $stocks = [];
    foreach ( $terms as $t ) {
        if ( $primary_id && (int)$t->term_id === (int)$primary_id ) continue;

        $meta_key = '_stock_at_' . $t->term_id;
        $qty = get_post_meta( $product->get_id(), $meta_key, true );

        if ( ($qty === '' || $qty === null) && $product->is_type('variation') ) {
            $parent_id = $product->get_parent_id();
            if ( $parent_id ) $qty = get_post_meta( $parent_id, $meta_key, true );
        }

        if ( $qty !== '' && $qty !== null && (int)$qty > 0 ) {
            $stocks[ $t->name ] = (int) $qty;
        }
    }

    if ( $primary_name && isset($stocks[$primary_name]) ) {
        unset($stocks[$primary_name]);
    }

    return $stocks;
}

/* ====================== КОРЗИНА / ЧЕКАУТ ====================== */

add_filter(
  'woocommerce_get_item_data',
  __NAMESPACE__ . '\\pc_cart_item_locations_meta',
  20,
  2
);
function pc_cart_item_locations_meta( $item_data, $cart_item ){
    // если включено «новое списание» — не добавляем старые строки в корзину
    if ( apply_filters('pc_disable_legacy_cart_locations', false) ) {
        return $item_data;
    }
    $product = $cart_item['data'] ?? null;
    if ( ! ($product instanceof \WC_Product) ) return $item_data;

    // 1) Другие склады
    $others = other_locations_stock_for_product( $product );
    if ( !empty($others) ) {
        $parts = [];
        foreach ( $others as $name => $qty ) {
            $parts[] = $name . ' — ' . (int)$qty;
        }
        $value = implode(', ', $parts);
        $item_data[] = [
            'key'     => __('Другие склады','woocommerce'),
            'value'   => $value,
            'display' => $value, // для Blocks
        ];
    }

    // 2) Заказ со склада (primary)
    $loc_name = primary_location_name_for_product( $product );
    if ( $loc_name ) {
        $qty   = qty_at_primary_location_for_product( $product );
        $value = $loc_name . ( $qty !== null ? ' — ' . (int)$qty : '' );
        $item_data[] = [
            'key'     => __('Заказ со склада','woocommerce'),
            'value'   => $value,
            'display' => $value, // для Blocks
        ];
    }

    return $item_data;
}

/* ====================== РЕНДЕР ДЛЯ ЗАКАЗОВ/ПИСЕМ ====================== */

/**
 * Получить подпись склада(ов) для строки заказа с приоритетом:
 *  1) SLW: _stock_locations[] / _stock_location (id → имена)
 *  2) Наша видимая мета «Склад» (если инжектнули количественную подпись «Киев × 2, Одеса × 10»)
 *  3) Наши машинные меты _stock_location_id / _stock_location_slug
 *  4) Фолбэк: Primary у товара
 */
function pc_render_item_location_label( $item ) {
    // 1) План списания — основной источник
    $plan = ($item instanceof \WC_Order_Item_Product) ? \pc_get_order_item_plan($item) : [];
    if (!empty($plan)) {
        $terms = get_terms(['taxonomy'=>'location','hide_empty'=>false]);
        $dict  = [];
        if (!is_wp_error($terms)) {
            foreach ($terms as $t) $dict[(int)$t->term_id] = $t->name;
        }
        arsort($plan, SORT_NUMERIC);
        $parts = [];
        foreach ($plan as $tid => $q) {
            $name = $dict[(int)$tid] ?? ('#'.(int)$tid);
            $parts[] = $name . ' — ' . (int)$q;
        }
        if ($parts) return implode(', ', $parts);
    }

    // 2) SLW-меты (фолбэк)
    $loc_ids = $item->get_meta('_stock_locations', true);
    $loc_id  = (int) $item->get_meta('_stock_location');

    $names = [];
    if (is_array($loc_ids) && !empty($loc_ids)) {
        foreach ($loc_ids as $tid) {
            $t = get_term((int)$tid, 'location');
            if ($t && !is_wp_error($t)) $names[] = $t->name;
        }
    } elseif ($loc_id) {
        $t = get_term($loc_id, 'location');
        if ($t && !is_wp_error($t)) $names[] = $t->name;
    }
    if (!empty($names)) {
        return implode(', ', array_unique($names));
    }

    // 3) Наши машинные меты (резерв)
    $rid  = (int) $item->get_meta('_stock_location_id');
    $slug = (string) $item->get_meta('_stock_location_slug');
    if ($rid) {
        $t = get_term($rid, 'location');
        if ($t && !is_wp_error($t)) return $t->name;
    }
    if ($slug !== '') {
        $t = get_term_by('slug', $slug, 'location');
        if ($t && !is_wp_error($t)) return $t->name;
    }

    // 4) Фолбэк — Primary у товара
    $product = $item->get_product();
    if ($product instanceof \WC_Product) {
        $name = primary_location_name_for_product($product);
        if ($name) return $name;
    }

    return '';
}

/* ====================== ВЫВОД В ЗАКАЗЕ / ПИСЬМАХ ====================== */

// Админ/клиентские страницы заказа
add_action('woocommerce_order_item_meta_end', function($item_id, $item, $order, $plain){
    $label = pc_render_item_location_label($item);

    // >>> DEBUG
    if ($label === '') { $label = '[NO PLAN]'; }
    // <<< DEBUG

    if (!$label) return;

    if ($plain) {
        echo "\n".__('Warehouse','paint-core').': '.$label."\n";
    } else {
        echo '<div class="wc-item-loc" style="font-size:12px;color:#555;margin-top:2px">'
           . esc_html__('Склад','woocommerce') . ': ' . esc_html($label)
           . '</div>';
    }
}, 10, 4);