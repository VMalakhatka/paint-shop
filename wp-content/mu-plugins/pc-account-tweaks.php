<?php
/**
 * Plugin Name: PC Account Tweaks
 * Description: Redirect /my-account/ -> /my-account/orders/ + hide dashboard/downloads in My Account menu.
 */

// 1) Redirect: /my-account/ -> /my-account/orders/  (only logged-in and only on the root of My Account)
add_action('template_redirect', function () {
    if ( is_admin() || wp_doing_ajax() ) return;
    if ( ! function_exists('is_account_page') ) return;

    $is_root = is_account_page()
        && ( ! function_exists('is_wc_endpoint_url') || ! is_wc_endpoint_url() );

    if ( $is_root && is_user_logged_in() ) {
        $orders_url = wc_get_endpoint_url('orders', '', wc_get_page_permalink('myaccount'));

        // спроба звичайним редіректом
        if ( ! headers_sent() ) {
            error_log('pc(mu): redirecting to ' . $orders_url);
            wp_safe_redirect($orders_url, 302);
            exit;
        }

        // фолбек, якщо заголовки вже відправлені
        add_action('wp_print_footer_scripts', function () use ($orders_url) {
            echo '<meta http-equiv="refresh" content="0;url=' . esc_attr($orders_url) . '">';
            echo '<script>location.replace(' . json_encode($orders_url) . ');</script>';
        }, 999);
    }
}, 10);

// 2) Hide unneeded My Account menu items
add_filter('woocommerce_account_menu_items', function ($items) {
    unset($items['dashboard']);   // Панель
    unset($items['downloads']);   // Завантаження (якщо потрібно — прибери цей рядок)
    return $items;
}, 999);

// === Кабінет: власні заголовки та ховаємо H1 теми ===
add_action('plugins_loaded', function () {

    // 0) Прибираємо стандартний H1 від теми (GeneratePress) на сторінці кабінету
    add_filter('generate_show_title', function ($show) {
        if (function_exists('is_account_page') && is_account_page()) {
            return false;
        }
        return $show;
    });

    // Fallback: якщо тема все одно намалює entry-header — сховаємо стилем
    add_action('wp_enqueue_scripts', function () {
        if (function_exists('is_account_page') && is_account_page()) {
            wp_register_style('pc-mu-inline', false);
            wp_enqueue_style('pc-mu-inline');
            wp_add_inline_style('pc-mu-inline', '.woocommerce-account .entry-header{display:none}');
        }
    });

    // 1) H1 «Кабінет» — над лівим меню
    add_action('woocommerce_account_navigation', function () {
        echo '<h1 class="account-title" style="margin:0 0 1rem">Кабінет</h1>';
    }, 1);

    // 2) H2 над контентом ендпойнтів (таблиця замовлень тощо)
    add_action('woocommerce_account_content', function () {
        if (!function_exists('is_wc_endpoint_url')) return;

        $title = '';
        if (is_wc_endpoint_url('orders'))          $title = 'Замовлення';
        elseif (is_wc_endpoint_url('edit-address'))$title = 'Адреси';
        elseif (is_wc_endpoint_url('edit-account'))$title = 'Особисті дані';

        if ($title !== '') {
            echo '<h2 class="account-subtitle" style="margin:0 0 1rem">'.$title.'</h2>';
        }
    }, 1);
});