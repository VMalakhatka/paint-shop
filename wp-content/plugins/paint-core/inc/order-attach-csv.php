<?php
namespace PaintCore\Orders;
defined('ABSPATH') || exit;

// error_log('[paint-core] module loaded: order-attach-csv');

/**
 * CSV‑вложение к письмам WooCommerce
 */

add_filter('woocommerce_email_attachments', function ($attachments, $email_id, $order, $email) {

    if (!($order instanceof \WC_Order)) {
        // error_log('[paint-core] skip: not a WC_Order');
        return $attachments;
    }

    // ЛОГ: видим, что фильтр сработал
    //error_log('[paint-core] attachments filter fired: email_id=' . $email_id . ' order=' . $order->get_id());

    // ---- временно НЕ фильтруем типы писем, чтобы убедиться, что работает ----
    // $targets = ['new_order','customer_processing_order','customer_completed_order'];
    // if (!in_array($email_id, $targets, true)) return $attachments;

    // заголовок CSV
    $rows   = [];
    $rows[] = ['SKU','GTIN','Quantity','Price','Location'];

    // получение названия "primary location"
    $get_location_name = function ($product_id) {
        $primary_id = (int) get_post_meta($product_id, '_yoast_wpseo_primary_location', true);
        if ($primary_id) {
            $term = get_term($primary_id, 'location');
            if ($term && !is_wp_error($term)) return $term->name;
        }
        $terms = wp_get_post_terms($product_id, 'location', ['orderby'=>'term_id','order'=>'ASC','number'=>1]);
        if (!empty($terms) && !is_wp_error($terms)) return $terms[0]->name;
        return '';
    };

    foreach ($order->get_items() as $item) {
        if (!($item instanceof \WC_Order_Item_Product)) continue;
        $product = $item->get_product();
        if (!$product) continue;

        $sku = $product->get_sku();
        if (!$sku && $product->is_type('variation')) {
            $parent = wc_get_product($product->get_parent_id());
            if ($parent) $sku = $parent->get_sku();
        }

        $gtin = get_post_meta($product->get_id(), '_global_unique_id', true);
        if (!$gtin && $product->is_type('variation')) {
            $parent = wc_get_product($product->get_parent_id());
            if ($parent) $gtin = get_post_meta($parent->get_id(), '_global_unique_id', true);
        }

        $location_name = $get_location_name(
            $product->is_type('variation') ? $product->get_parent_id() : $product->get_id()
        );

        $qty        = (int) $item->get_quantity();
        $line_total = (float) $item->get_total();
        $unit_price = $qty > 0 ? $line_total / $qty : $line_total;

        $rows[] = [
            $sku ?: '',
            $gtin ?: '',
            $qty,
            wc_format_decimal($unit_price, wc_get_price_decimals()),
            $location_name,
        ];
    }

    // создаём файл
    $uploads  = function_exists('wp_upload_dir') ? wp_upload_dir() : null;
    $tmp_base = ($uploads && is_dir($uploads['basedir']) && is_writable($uploads['basedir']))
        ? $uploads['basedir'] : sys_get_temp_dir();

    $filename = sprintf('wc-order-%d-%s.csv', $order->get_id(), uniqid());
    $path     = trailingslashit($tmp_base) . $filename;

    if ($fh = fopen($path, 'w')) {
        fwrite($fh, "\xEF\xBB\xBF"); // BOM
        foreach ($rows as $r) fputcsv($fh, $r, ';');
        fclose($fh);

        $attachments[] = $path;
        set_transient('wc_csv_is_mine_' . md5($path), 1, HOUR_IN_SECONDS);
        // error_log('[paint-core] attached: ' . $path . ' size=' . @filesize($path));
    } else {
        // error_log('[paint-core] ERROR: cannot write file: ' . $path);
    }

    return $attachments;
}, 99, 4);

// удаляем временные файлы
add_action('wp_mail_succeeded', function($data){
    foreach ((array)($data['attachments'] ?? []) as $file) {
        if ($file && get_transient('wc_csv_is_mine_' . md5($file))) {
            @unlink($file);
            delete_transient('wc_csv_is_mine_' . md5($file));
            // error_log('[paint-core] cleanup OK: ' . $file);
        }
    }
});
add_action('wp_mail_failed', function($err){
    $data = method_exists($err, 'get_error_data') ? (array) $err->get_error_data() : [];
    foreach ((array)($data['attachments'] ?? []) as $file) {
        if ($file && get_transient('wc_csv_is_mine_' . md5($file))) {
            @unlink($file);
            delete_transient('wc_csv_is_mine_' . md5($file));
            //error_log('[paint-core] cleanup FAIL: ' . $file);
        }
    }
});