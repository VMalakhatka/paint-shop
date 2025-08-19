<?php
// Подключение стилей дочерней темы
add_action('wp_enqueue_scripts', function() {
    wp_enqueue_style('generatepress-child-style', get_stylesheet_uri());
});
add_filter( 'woocommerce_breadcrumb_defaults', function( $defaults ) {
    $defaults['delimiter'] = ' <span class="breadcrumb-delimiter">→</span> ';
    return $defaults;
});