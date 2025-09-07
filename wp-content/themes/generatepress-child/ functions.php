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
// 1) Слаг сторінки «Швидке замовлення» (не дублюємо, якщо уже є)
if ( ! defined('PCQO_PAGE_SLUG') ) {
    define('PCQO_PAGE_SLUG', 'shvydke-zamovlennia');
}

/**
 * 2) На сторінці зі шорткодом [pc_quick_order] переписуємо посилання
 *    категорій на ту ж сторінку з ?cat=<slug>.
 */
add_filter('term_link', function ($url, $term, $taxonomy) {
    if ($taxonomy !== 'product_cat' || is_admin()) {
        return $url;
    }

    // Працюємо тільки коли реально відображається сторінка Quick Order
    global $post;
    if ( ! $post ) {
        return $url;
    }

    // Дозволяє або за слагом сторінки, або за наявністю шорткоду
    $is_quick_order_page =
        is_page( PCQO_PAGE_SLUG ) ||
        ( has_shortcode( (string) $post->post_content, 'pc_quick_order' ) );

    if ( ! $is_quick_order_page ) {
        return $url;
    }

    $page_url = get_permalink( $post->ID ?: get_page_by_path( PCQO_PAGE_SLUG ) );
    if ( ! $page_url ) {
        return $url;
    }

    return add_query_arg( 'cat', $term->slug, $page_url );
}, 10, 3);

add_filter('slu_ui_labels', function($L){
    $L['from']       = 'Зі складу';
    $L['others']     = 'Інші скл.';
    $L['total']      = 'Загал.';
    $L['allocation'] = 'Списання';
    return $L;
});