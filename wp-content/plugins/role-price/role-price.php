<?php
/*
Plugin Name: Role Price
Description: Цены по ролям пользователей для WooCommerce.
Author: Volodymyr
Version: 1.0.0
*/
defined('ABSPATH') || exit;
// === Кастомные цены по ролям: override только если найдена своя цена ===
// Приоритет 5: если своей цены нет, WPC (priority ~10) применит глобальные скидки.
add_filter('woocommerce_product_get_price',          'vp_role_price_override', 5, 2);
add_filter('woocommerce_product_variation_get_price','vp_role_price_override', 5, 2);

function vp_role_price_override($price, $product) {
    if ((is_admin() && !wp_doing_ajax()) || ! $product) return $price;

    $user = wp_get_current_user();
    if (!$user || empty($user->roles)) return $price;

    $role = $user->roles[0];
    $meta_key = '_wpc_price_role_' . $role;

    $custom_price = get_post_meta($product->get_id(), $meta_key, true);
    if ($custom_price !== '' && $custom_price !== null) {
        $custom_price = wc_format_decimal($custom_price, wc_get_price_decimals());
        if ($custom_price !== '') {
            return $custom_price; // наша цена по роли главнее
        }
    }

    // своей цены нет — НЕ мешаем WPC/ядру WooCommerce
    return $price;
}

// Чтобы цены вариаций не кэшировались одинаково для разных ролей
add_filter('woocommerce_get_variation_prices_hash', function($hash, $product, $display){
    $user = wp_get_current_user();
    $hash['vp_role'] = ($user && !empty($user->roles)) ? $user->roles[0] : 'guest';
    return $hash;
}, 20, 3);

// Обновляем цену в корзине (если товар был добавлен до смены роли/правил)
add_action('woocommerce_before_calculate_totals', function($cart){
    if (is_admin() && !wp_doing_ajax()) return;
    if (empty($cart) || !method_exists($cart, 'get_cart')) return;
    foreach ($cart->get_cart() as $item) {
        if (!empty($item['data']) && method_exists($item['data'], 'get_price')) {
            $item['data']->set_price( $item['data']->get_price() );
        }
    }
}, 20);