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