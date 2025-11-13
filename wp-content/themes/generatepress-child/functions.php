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

add_filter('psu_products_per_page', fn()=>24);

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

// ВЕРХНИЙ БАР С ЛОГОТИПОМ (inline SVG) НАД ШАПКОЙ ТЕМЫ
add_action( 'generate_before_header', 'lavka_topbar', 1 );
function lavka_topbar() {
    ?>
    <div class="lavka-topbar">
        <div class="lavka-topbar__inner">
            <div class="lavka-topbar__logo">
                <?php lavka_inline_svg_logo(); ?>
            </div>
    <div class="lavka-topbar__info">
        <div>м. Київ, ТЦ «Олімпійський», +38044&nbsp;593-26-05</div>
        <div>м. Одеса, Ланжеронівська, 17, +38063&nbsp;857-17-68</div>
        <div>Оптові продажі, +38050&nbsp;348-01-38</div>
    </div>
        </div>
    </div>
    <?php
}

function lavka_inline_svg_logo() {
    $svg_path = get_stylesheet_directory() . '/assets/logo.svg';

    // Для отладки: если файл не найден, покажем заглушку
    if ( ! file_exists( $svg_path ) ) {
        echo '<a href="' . esc_url( home_url('/') ) . '" class="site-logo site-logo--svg site-logo--missing" aria-label="' . esc_attr( get_bloginfo('name') ) . '">LOGO.SVG NOT FOUND</a>';
        if ( function_exists( 'error_log' ) ) {
            error_log( 'lavka_inline_svg_logo: logo.svg not found at ' . $svg_path );
        }
        return;
    }

    $svg = file_get_contents( $svg_path );

    if ( ! $svg ) {
        echo '<a href="' . esc_url( home_url('/') ) . '" class="site-logo site-logo--svg site-logo--missing" aria-label="' . esc_attr( get_bloginfo('name') ) . '">SVG READ ERROR</a>';
        if ( function_exists( 'error_log' ) ) {
            error_log( 'lavka_inline_svg_logo: unable to read logo.svg at ' . $svg_path );
        }
        return;
    }

    // Оборачиваем в ссылку на главную
    echo '<a href="' . esc_url( home_url('/') ) . '" class="site-logo site-logo--svg" aria-label="' . esc_attr( get_bloginfo('name') ) . '">';
    echo $svg;  // здесь прямо вставляется <svg>...</svg>
    echo '</a>';
}
// Заменяем стандартный логотип GeneratePress (букву "P") на наш SVG-логотип
add_filter( 'generate_logo_output', function( $output ) {
    ob_start();
    lavka_inline_svg_logo();
    return ob_get_clean();
});