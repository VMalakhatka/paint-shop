<?php
namespace PaintCore\Orders;

defined('ABSPATH') || exit;

/**
 * CSV‑вложение к письмам WooCommerce
 * Колонка Location теперь берётся из ФАКТИЧЕСКОГО склада списания,
 * который мы кладём в меты строки заказа:
 *  - видимая мета 'Склад' (строка, может быть "Одеса × 3, Київ × 1");
 *  - _stock_location_id / _stock_location_slug (машинные);
 *  - (фолбэк) SLW: _stock_locations[] / _stock_location;
 *  - (последний фолбэк) Primary локация товара.
 */

/** Primary term_id локации для продукта/вариации */
function pc_primary_location_term_id_for_product( \WC_Product $product ): int {
    $pid = $product->get_id();
    $tid = (int) get_post_meta($pid, '_yoast_wpseo_primary_location', true);
    if ( ! $tid && $product->is_type('variation') ) {
        $parent = $product->get_parent_id();
        if ( $parent ) $tid = (int) get_post_meta($parent, '_yoast_wpseo_primary_location', true);
    }
    return $tid ?: 0;
}

/** Название локации по term_id/slug (без ошибок) */
function pc_location_name_by( $term_id = 0, string $slug = '' ): string {
    if ( $term_id ) {
        $t = get_term( (int)$term_id, 'location' );
        if ( $t && ! is_wp_error($t) ) return $t->name;
    }
    if ( $slug !== '' ) {
        $t = get_term_by( 'slug', $slug, 'location' );
        if ( $t && ! is_wp_error($t) ) return $t->name;
    }
    return '';
}

/** Вернуть подпись склада(ов) для строки заказа — максимально честно */
function pc_order_item_location_label( \WC_Order_Item_Product $item ): string {
    // 1) Видимая мета, которую пишет наш пост‑редукционный код (самое точное)
    $human = (string) $item->get_meta( __( 'Склад', 'woocommerce' ) );
    if ( $human !== '' ) return $human;

    // 2) Машинные меты от нашего фиксатора
    $id   = (int) $item->get_meta('_stock_location_id');
    $slug = (string) $item->get_meta('_stock_location_slug');
    $name = pc_location_name_by($id, $slug);
    if ( $name !== '' ) return $name;

    // 3) Фолбэк: меты самого SLW (если наш фиксаж по какой-то причине не сработал)
    $loc_ids = $item->get_meta('_stock_locations', true);
    if ( is_array($loc_ids) && $loc_ids ) {
        $names = [];
        foreach ( $loc_ids as $tid ) {
            $n = pc_location_name_by((int)$tid, '');
            if ( $n !== '' ) $names[] = $n;
        }
        $names = array_values(array_unique(array_filter($names)));
        if ( $names ) return implode(', ', $names);
    }
    $loc_id = (int) $item->get_meta('_stock_location');
    $name   = pc_location_name_by($loc_id, '');
    if ( $name !== '' ) return $name;

    // 4) Последний фолбэк — Primary у товара
    $product = $item->get_product();
    if ( $product instanceof \WC_Product ) {
        $tid = pc_primary_location_term_id_for_product($product);
        if ( $tid ) {
            $n = pc_location_name_by($tid, '');
            if ( $n !== '' ) return $n;
        }
    }

    return '';
}

add_filter('woocommerce_email_attachments', function ($attachments, $email_id, $order, $email) {

    if (!($order instanceof \WC_Order)) return $attachments;

    // Заголовки CSV
    $rows   = [];
    $rows[] = ['SKU','GTIN','Quantity','Price','Location'];

    // Локации: словарик term_id => name
    $locById = [];
    $terms = get_terms(['taxonomy'=>'location','hide_empty'=>false]);
    if (!is_wp_error($terms)) {
        foreach ($terms as $t) $locById[(int)$t->term_id] = $t->name;
    }

    foreach ($order->get_items('line_item') as $item) {
        if (!($item instanceof \WC_Order_Item_Product)) continue;
        $product = $item->get_product();
        if (!$product) continue;

        // SKU (вариации — фолбэк на родителя)
        $sku = $product->get_sku();
        if (!$sku && $product->is_type('variation')) {
            $parent = wc_get_product($product->get_parent_id());
            if ($parent) $sku = $parent->get_sku();
        }

        // GTIN (фолбэк на родителя)
        $gtin = get_post_meta($product->get_id(), '_global_unique_id', true);
        if (!$gtin && $product->is_type('variation')) {
            $parent = wc_get_product($product->get_parent_id());
            if ($parent) $gtin = get_post_meta($parent->get_id(), '_global_unique_id', true);
        }

        $qty_total  = max(0, (int)$item->get_quantity());
        $line_total = (float)$item->get_total();
        $unit_price = $qty_total > 0 ? $line_total / $qty_total : $line_total;

        // План распределения по складам
        $plan = $item->get_meta('_pc_stock_breakdown', true);
        if (!is_array($plan)) $plan = json_decode((string)$plan, true);

        if (is_array($plan) && !empty($plan)) {
            foreach ($plan as $term_id => $q) {
                $term_id = (int)$term_id;
                $q = (int)$q;
                if ($term_id <= 0 || $q <= 0) continue;
                $rows[] = [
                    $sku ?: '',
                    $gtin ?: '',
                    $q,
                    wc_format_decimal($unit_price, wc_get_price_decimals()),
                    $locById[$term_id] ?? ('#'.$term_id),
                ];
            }
        } else {
            // Фолбэк: если плана нет — одной строкой с primary
            $primary_id = (int) get_post_meta($product->get_id(), '_yoast_wpseo_primary_location', true);
            if (!$primary_id && $product->is_type('variation')) {
                $parent_id = $product->get_parent_id();
                if ($parent_id) $primary_id = (int) get_post_meta($parent_id, '_yoast_wpseo_primary_location', true);
            }
            $rows[] = [
                $sku ?: '',
                $gtin ?: '',
                $qty_total,
                wc_format_decimal($unit_price, wc_get_price_decimals()),
                $locById[$primary_id] ?? '',
            ];
        }
    }

    // Создаём CSV
    $uploads  = function_exists('wp_upload_dir') ? wp_upload_dir() : null;
    $tmp_base = ($uploads && is_dir($uploads['basedir']) && is_writable($uploads['basedir']))
        ? $uploads['basedir'] : sys_get_temp_dir();

    $filename = sprintf('wc-order-%d-%s.csv', $order->get_id(), uniqid());
    $path     = trailingslashit($tmp_base) . $filename;

    if ($fh = fopen($path, 'w')) {
        fwrite($fh, "\xEF\xBB\xBF"); // UTF‑8 BOM
        foreach ($rows as $r) fputcsv($fh, $r, ';');
        fclose($fh);

        $attachments[] = $path;
        set_transient('wc_csv_is_mine_' . md5($path), 1, HOUR_IN_SECONDS);
    }
    return $attachments;
}, 99, 4);

// Очистка временных файлов (без изменений)
add_action('wp_mail_succeeded', function($data){
    foreach ((array)($data['attachments'] ?? []) as $file) {
        if ($file && get_transient('wc_csv_is_mine_' . md5($file))) {
            @unlink($file);
            delete_transient('wc_csv_is_mine_' . md5($file));
        }
    }
});
add_action('wp_mail_failed', function($err){
    $data = method_exists($err, 'get_error_data') ? (array) $err->get_error_data() : [];
    foreach ((array)($data['attachments'] ?? []) as $file) {
        if ($file && get_transient('wc_csv_is_mine_' . md5($file))) {
            @unlink($file);
            delete_transient('wc_csv_is_mine_' . md5($file));
        }
    }
});