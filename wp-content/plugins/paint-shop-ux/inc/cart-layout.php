<?php
if (!defined('ABSPATH')) exit;

add_action('wp_enqueue_scripts', function (): void {
    if (!function_exists('is_cart') || !is_cart()) return;
    $path = dirname(__DIR__) . '/assets/cart-layout.css';
    wp_enqueue_style('psu-cart-layout', plugins_url('assets/cart-layout.css', dirname(__DIR__) . '/paint-shop-ux.php'), [], (string) filemtime($path));
}, 100);

add_filter('generate_sidebar_layout', function ($layout) {
    return function_exists('is_cart') && is_cart() ? 'no-sidebar' : $layout;
}, 100);
