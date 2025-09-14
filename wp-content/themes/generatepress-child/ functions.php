<?php
// Подключение стилей дочерней темы
add_action('wp_enqueue_scripts', function() {
    wp_enqueue_style('generatepress-child-style', get_stylesheet_uri());
});

// Хлебные крошки: меняем разделитель
add_filter('woocommerce_breadcrumb_defaults', function($defaults) {
    $defaults['delimiter'] = ' <span class="breadcrumb-delimiter">→</span> ';
    return $defaults;
});

// Показать сниппет Relevanssi под заголовком в выдаче поиска
add_action('woocommerce_after_shop_loop_item_title', function(){
    if (!is_search() || !function_exists('relevanssi_the_excerpt')) return;
    echo '<div class="relevanssi-snippet" style="margin:.35rem 0 .5rem; font-size:.9em; color:#555;">';
    relevanssi_the_excerpt();
    echo '</div>';
}, 8);

/** ================= Quick Order: переписываем ссылки категорий ================= */

// Слаг страницы «Швидке замовлення» (однократно)
if (!defined('PCQO_PAGE_SLUG')) {
    define('PCQO_PAGE_SLUG', 'shvydke-zamovlennia'); // замени на свой
}

/**
 * На странице с шорткодом [pc_quick_order] переписываем ссылки
 * категорий на ту же страницу с ?cat=<slug>.
 */
add_filter('term_link', function ($url, $term, $taxonomy) {
    if ($taxonomy !== 'product_cat' || is_admin()) return $url;

    global $post;
    if (!$post) return $url;

    $is_quick_order_page =
        is_page(PCQO_PAGE_SLUG) ||
        has_shortcode((string)$post->post_content, 'pc_quick_order');

    if (!$is_quick_order_page) return $url;

    $page = $post ?: get_page_by_path(PCQO_PAGE_SLUG);
    if (!$page) return $url;

    $page_url = get_permalink(is_object($page) ? $page->ID : (int)$page);
    if (!$page_url) return $url;

    return add_query_arg('cat', $term->slug, $page_url);
}, 10, 3);

/** ================= Stock Locations UI: опциональный кастом лейблов ============== */
/*
// Включай ТОЛЬКО если нужны свои аббревиатуры, отличные от перевода плагина
add_filter('slu_ui_labels', function($L){
    $L['from']       = 'Зі складу';
    $L['others']     = 'Інші скл.';
    $L['total']      = 'Загал.';
    $L['allocation'] = 'Списання';
    // $L['in_cart']  = 'В кошику'; // при желании
    return $L;
});
*/

/** =============== Чек-аут: фиксируем дату создания ордера ======================= */
add_action('woocommerce_checkout_create_order', function(\WC_Order $order, $data){
    // Зафиксировать «сейчас» с учётом таймзоны WP
    $order->set_date_created( current_time('timestamp') );
}, 10, 2);