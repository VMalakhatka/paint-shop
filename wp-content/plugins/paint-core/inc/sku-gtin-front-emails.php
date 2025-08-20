<?php
namespace PaintCore\SKU;

defined('ABSPATH') || exit;

/**
 * SKU + GTIN на витрине/чекауте/страницах заказа и в письмах WooCommerce.
 * Вынесено из Code Snippets, без глобальных функций.
 */

/* ===== 1) КОРЗИНА/ЧЕКАУТ ===== */

// Через фильтр (корзина/чекаут, классические шаблоны)
add_filter('woocommerce_cart_item_name', function ($name, $cart_item, $cart_item_key) {
    $product = isset($cart_item['data']) ? $cart_item['data'] : null;
    if ($product instanceof \WC_Product) {
        $sku  = $product->get_sku();
        $gtin = get_post_meta($product->get_id(), '_global_unique_id', true);
        if (!$gtin && $product->is_type('variation')) {
            $parent = wc_get_product($product->get_parent_id());
            if ($parent) {
                $gtin = get_post_meta($parent->get_id(), '_global_unique_id', true);
            }
        }

        if ($sku || $gtin) {
            $info = '';
            if ($sku)  $info .= esc_html__('Артикул', 'woocommerce') . ': ' . esc_html($sku);
            if ($gtin) $info .= ($info ? ' | ' : '') . 'GTIN: ' . esc_html($gtin);

            $name .= '<div class="wc-item-sku" style="font-size:12px;color:#555;margin-top:2px">'
                   . $info . '</div>';
        }
    }
    return $name;
}, 10, 3);

// Через экшен (если тема не вызывает фильтр выше)
add_action('woocommerce_after_cart_item_name', function ($cart_item, $cart_item_key) {
    $product = isset($cart_item['data']) ? $cart_item['data'] : null;
    if ($product instanceof \WC_Product) {
        $sku  = $product->get_sku();
        $gtin = get_post_meta($product->get_id(), '_global_unique_id', true);
        if (!$gtin && $product->is_type('variation')) {
            $parent = wc_get_product($product->get_parent_id());
            if ($parent) {
                $gtin = get_post_meta($parent->get_id(), '_global_unique_id', true);
            }
        }

        if ($sku || $gtin) {
            $info = '';
            if ($sku)  $info .= esc_html__('Артикул', 'woocommerce') . ': ' . esc_html($sku);
            if ($gtin) $info .= ($info ? ' | ' : '') . 'GTIN: ' . esc_html($gtin);

            echo '<div class="wc-item-sku" style="font-size:12px;color:#555;margin-top:2px">'
               . $info . '</div>';
        }
    }
}, 10, 2);


/* ===== 2) СТРАНИЦЫ ЗАКАЗОВ («Спасибо», ЛК) ===== */
add_action('woocommerce_order_item_meta_end', function ($item_id, $item, $order, $plain_text) {
    $product = $item->get_product();
    if (!($product instanceof \WC_Product)) return;

    $sku  = $product->get_sku();
    $gtin = get_post_meta($product->get_id(), '_global_unique_id', true);
    if (!$gtin && $product->is_type('variation')) {
        $parent = wc_get_product($product->get_parent_id());
        if ($parent) $gtin = get_post_meta($parent->get_id(), '_global_unique_id', true);
    }

    if ($sku || $gtin) {
        if ($plain_text) {
            if ($sku)  echo "\n" . __('Артикул', 'woocommerce') . ': ' . $sku;
            if ($gtin) echo "\nGTIN: " . $gtin;
        } else {
            $info = '';
            if ($sku)  $info .= esc_html__('Артикул', 'woocommerce') . ': ' . esc_html($sku);
            if ($gtin) $info .= ($info ? ' | ' : '') . 'GTIN: ' . esc_html($gtin);

            echo '<div class="wc-item-sku" style="font-size:12px;color:#555;margin-top:2px">'
               . $info . '</div>';
        }
    }
}, 10, 4);


/* ===== 3) ПИСЬМА WOOCOMMERCE ===== */
add_action('woocommerce_email_order_item_meta', function ($item, $sent_to_admin, $plain_text, $email) {
    $product = $item->get_product();
    if (!($product instanceof \WC_Product)) return;

    $sku  = $product->get_sku();
    $gtin = get_post_meta($product->get_id(), '_global_unique_id', true);
    if (!$gtin && $product->is_type('variation')) {
        $parent = wc_get_product($product->get_parent_id());
        if ($parent) $gtin = get_post_meta($parent->get_id(), '_global_unique_id', true);
    }

    if ($sku || $gtin) {
        if ($plain_text) {
            if ($sku)  echo "\n" . __('Артикул', 'woocommerce') . ': ' . $sku;
            if ($gtin) echo "\nGTIN: " . $gtin;
        } else {
            $info = '';
            if ($sku)  $info .= esc_html__('Артикул', 'woocommerce') . ': ' . esc_html($sku);
            if ($gtin) $info .= ($info ? ' | ' : '') . 'GTIN: ' . esc_html($gtin);

            echo '<br><small class="wc-item-sku" style="color:#555">' . $info . '</small>';
        }
    }
}, 10, 4);