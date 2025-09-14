<?php
/**
 * Plugin Name: PC Account Tweaks
 * Description: Redirect /my-account/ -> /my-account/orders/ + hide dashboard/downloads in My Account menu.
 * Text Domain: pc-account-tweaks
 * Domain Path: /languages
 */

if (!defined('ABSPATH')) exit;

// 1) Redirect: /my-account/ -> /my-account/orders/  (only logged-in and only on the root of My Account)
add_action('template_redirect', function () {
    if ( is_admin() || wp_doing_ajax() ) return;
    if ( ! function_exists('is_account_page') ) return;

    $is_root = is_account_page()
        && ( ! function_exists('is_wc_endpoint_url') || ! is_wc_endpoint_url() );

    if ( $is_root && is_user_logged_in() ) {
        $orders_url = wc_get_endpoint_url('orders', '', wc_get_page_permalink('myaccount'));

        if ( ! headers_sent() ) {
            error_log('pc(mu): redirecting to ' . $orders_url);
            wp_safe_redirect($orders_url, 302);
            exit;
        }

        // Fallback when headers already sent
        add_action('wp_print_footer_scripts', function () use ($orders_url) {
            echo '<meta http-equiv="refresh" content="0;url=' . esc_attr($orders_url) . '">';
            echo '<script>location.replace(' . json_encode($orders_url) . ');</script>';
        }, 999);
    }
}, 10);

// 2) Hide unneeded My Account menu items
add_filter('woocommerce_account_menu_items', function ($items) {
    unset($items['dashboard']);   // Dashboard
    unset($items['downloads']);   // Downloads (remove this line if you need it)
    return $items;
}, 999);

// === Account page: custom headings & hide theme H1 ===
add_action('plugins_loaded', function () {

    // 0) Remove theme H1 (GeneratePress) on account page
    add_filter('generate_show_title', function ($show) {
        if (function_exists('is_account_page') && is_account_page()) {
            return false;
        }
        return $show;
    });

    // Fallback: if entry-header still appears — hide with CSS
    add_action('wp_enqueue_scripts', function () {
        if (function_exists('is_account_page') && is_account_page()) {
            wp_register_style('pc-mu-inline', false);
            wp_enqueue_style('pc-mu-inline');
            wp_add_inline_style('pc-mu-inline', '.woocommerce-account .entry-header{display:none}');
        }
    });

    // 1) H1 "Account" above the left nav
    add_action('woocommerce_account_navigation', function () {
        echo '<h1 class="account-title" style="margin:0 0 1rem">' . esc_html( __('Account', 'pc-account-tweaks') ) . '</h1>';
    }, 1);

    // 2) H2 above endpoint content (orders table etc.)
    add_action('woocommerce_account_content', function () {
        if (!function_exists('is_wc_endpoint_url')) return;

        $title = '';
        if (is_wc_endpoint_url('orders')) {
            $title = __('Orders', 'pc-account-tweaks');
        } elseif (is_wc_endpoint_url('edit-address')) {
            $title = __('Addresses', 'pc-account-tweaks');
        } elseif (is_wc_endpoint_url('edit-account')) {
            $title = __('Account details', 'pc-account-tweaks');
        }

        if ($title !== '') {
            echo '<h2 class="account-subtitle" style="margin:0 0 1rem">' . esc_html($title) . '</h2>';
        }
    }, 1);
});