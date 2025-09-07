<?php
// Подключение стилей дочерней темы
add_action('wp_enqueue_scripts', function() {
    wp_enqueue_style('generatepress-child-style', get_stylesheet_uri());
});

// Хлебные крошки: меняем разделитель
add_filter('woocommerce_breadcrumb_defaults', function( $defaults ) {
    $defaults['delimiter'] = ' <span class="breadcrumb-delimiter">→</span> ';
    return $defaults;
});

// Показать сниппет Relevanssi под заголовком в выдаче поиска
add_action('woocommerce_after_shop_loop_item_title', function(){
    if (!is_search()) return;
    if (!function_exists('relevanssi_the_excerpt')) return;
    echo '<div class="relevanssi-snippet" style="margin:.35rem 0 .5rem; font-size:.9em; color:#555;">';
    relevanssi_the_excerpt();
    echo '</div>';
}, 8);

// 1) Укажи слаг страницы быстрого заказа
const PCQO_PAGE_SLUG = 'shvydke-zamovlennia'; // ПРИМЕР: поставь свой слаг страницы

/**
 * На странице «Швидке замовлення» переписываем ссылки категорий
 * на ту же страницу с параметром ?cat=<slug>.
 */
add_filter('term_link', function ($url, $term, $taxonomy) {
    if ($taxonomy !== 'product_cat') {
        return $url;
    }
    // мы только на нашей quick-order странице переписываем ссылки
    if (!is_page() || !is_page(PCQO_PAGE_SLUG)) {
        return $url;
    }
    $page_url = get_permalink(get_page_by_path(PCQO_PAGE_SLUG));
    if (!$page_url) {
        return $url;
    }
    return add_query_arg('cat', $term->slug, $page_url);
}, 10, 3);

add_filter('slu_ui_labels', function($L){
    $L['from']       = 'Зі складу';
    $L['others']     = 'Інші скл.';
    $L['total']      = 'Загал.';
    $L['allocation'] = 'Списання';
    return $L;
});


// === Динамічний H1 для "Мій кабінет" (перекриваємо "Orders" від Woo) ===
add_action('wp', function () {
    // знімаємо стандартну підстановку WooCommerce "Orders", "Addresses" тощо
    remove_filter('the_title', 'wc_page_endpoint_title', 9);
});

/**
 * Повертаємо свій заголовок для сторінки /my-account/ та її ендпойнтів
 */
add_filter('the_title', function ($title, $post_id) {
    $account_id = (int) get_option('woocommerce_myaccount_page_id');

    // працюємо тільки для сторінки "Мій кабінет" і лише в головному циклі
    if ($post_id !== $account_id || !is_main_query() || !in_the_loop()) {
        return $title;
    }

    if (function_exists('is_wc_endpoint_url')) {
        if (is_wc_endpoint_url('orders'))        return 'Мої замовлення';
        if (is_wc_endpoint_url('edit-address'))  return 'Адреси доставки і оплати';
        if (is_wc_endpoint_url('edit-account'))  return 'Профіль і пароль';
    }

    // коренева /my-account/
    return 'Кабінет';
}, 20, 2);

add_filter('woocommerce_account_menu_items', function ($items) {
    // Прибрати пункти
    unset($items['dashboard']);
    unset($items['downloads']);

    // Переклади підписів
    $map = [
        'orders'           => 'Замовлення',
        'edit-address'     => 'Адреси',
        'payment-methods'  => 'Платіжні методи',
        'edit-account'     => 'Профіль',
        'customer-logout'  => 'Вийти',
    ];
    foreach ($items as $k => $v) {
        if (isset($map[$k])) $items[$k] = $map[$k];
    }
    return $items;
}, 999);