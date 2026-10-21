<?php
/** Independent WordPress document shell. Store behaviour belongs to plugins. */
defined('ABSPATH') || exit;

add_action('after_setup_theme', static function (): void {
    load_theme_textdomain('lavka-storefront', get_template_directory() . '/languages');
    foreach (['automatic-feed-links', 'post-thumbnails', 'title-tag', 'align-wide', 'responsive-embeds', 'customize-selective-refresh-widgets', 'woocommerce', 'wc-product-gallery-zoom', 'wc-product-gallery-lightbox', 'wc-product-gallery-slider', 'psu-public-pages'] as $feature) add_theme_support($feature);
    add_theme_support('html5', ['search-form', 'comment-form', 'comment-list', 'gallery', 'caption', 'script', 'style']);
    add_theme_support('custom-logo', ['height' => 70, 'width' => 350, 'flex-height' => true, 'flex-width' => true]);
    register_nav_menus(['primary' => __('Primary menu', 'lavka-storefront')]);
    $GLOBALS['content_width'] = 1200;
});

add_action('widgets_init', static function (): void {
    $areas = [
        'sidebar-1' => __('Right sidebar', 'lavka-storefront'),
        'sidebar-2' => __('Left sidebar', 'lavka-storefront'),
        'header' => __('Header', 'lavka-storefront'),
        'footer-1' => __('Footer widget 1', 'lavka-storefront'),
        'footer-2' => __('Footer widget 2', 'lavka-storefront'),
        'footer-3' => __('Footer widget 3', 'lavka-storefront'),
        'footer-4' => __('Footer widget 4', 'lavka-storefront'),
        'footer-5' => __('Footer widget 5', 'lavka-storefront'),
        'footer-bar' => __('Footer bar', 'lavka-storefront'),
        'top-bar' => __('Top bar', 'lavka-storefront'),
    ];
    foreach ($areas as $id => $name) register_sidebar([
        'id' => $id, 'name' => $name,
        'before_widget' => '<section id="%1$s" class="widget %2$s">', 'after_widget' => '</section>',
        'before_title' => '<h2 class="widget-title">', 'after_title' => '</h2>',
    ]);
});

/** Preserve the saved primary menu without copying or rewriting theme settings. */
function lavka_storefront_menu_locations($locations): array {
    $mods = get_theme_mods();
    if (is_array($mods) && array_key_exists('nav_menu_locations', $mods)) return is_array($locations) ? $locations : [];
    $legacy = get_option('theme_mods_generatepress-child', []);
    $primary = is_array($legacy) ? absint($legacy['nav_menu_locations']['primary'] ?? 0) : 0;
    if ($primary && wp_get_nav_menu_object($primary)) return ['primary' => $primary];
    return is_array($locations) ? $locations : [];
}
add_filter('theme_mod_nav_menu_locations', 'lavka_storefront_menu_locations');

function lavka_storefront_is_catalog(): bool {
    if (!function_exists('is_shop')) return false;
    return is_shop() || is_product_taxonomy()
        || (is_search() && (in_array('product', (array) get_query_var('post_type'), true) || get_query_var('_psu_catalog_query')));
}

/** Only catalogue pages use a sidebar; the widget IDs and contents stay intact. */
function lavka_storefront_sidebar_layout(): string {
    if (!lavka_storefront_is_catalog()) return 'no-sidebar';
    $legacy = get_option('generate_settings', []);
    $default = is_array($legacy) ? ($legacy['blog_layout_setting'] ?? 'right-sidebar') : 'right-sidebar';
    $layout = get_theme_mod('catalog_sidebar', $default);
    $layout = apply_filters('lavka_storefront_sidebar_layout', $layout);
    $areas = ['left-sidebar' => 'sidebar-2', 'right-sidebar' => 'sidebar-1'];
    return is_string($layout) && isset($areas[$layout]) && is_active_sidebar($areas[$layout]) ? $layout : 'no-sidebar';
}

add_filter('body_class', static function (array $classes): array {
    $classes[] = 'lavka-theme';
    $classes[] = lavka_storefront_sidebar_layout();
    return $classes;
});

add_action('wp_enqueue_scripts', static function (): void {
    wp_enqueue_style('lavka-storefront', get_stylesheet_uri(), [], (string) filemtime(get_stylesheet_directory() . '/style.css'));
    if (is_singular() && comments_open() && get_option('thread_comments')) wp_enqueue_script('comment-reply');
}, 5);

function lavka_storefront_woocommerce_start(): void {
    echo '<div id="primary" class="content-area"><main id="main" class="site-main">';
    do_action('lavka_storefront_before_main_content');
    echo '<div class="inside-article lavka-woocommerce-shell"><div class="entry-content">';
}
function lavka_storefront_woocommerce_end(): void {
    echo '</div></div></main></div>';
}
function lavka_storefront_woocommerce_sidebar(): void {
    if (lavka_storefront_sidebar_layout() !== 'no-sidebar') get_sidebar();
}
add_action('wp', static function (): void {
    if (!class_exists('WooCommerce')) return;
    remove_action('woocommerce_sidebar', 'woocommerce_get_sidebar', 10);
    add_action('woocommerce_sidebar', 'lavka_storefront_woocommerce_sidebar', 10);
    // Paint Shop UX already provides product wrappers and all product hooks.
    if (function_exists('psu_single_product_enabled') && psu_single_product_enabled()) return;
    remove_action('woocommerce_before_main_content', 'woocommerce_output_content_wrapper', 10);
    remove_action('woocommerce_after_main_content', 'woocommerce_output_content_wrapper_end', 10);
    add_action('woocommerce_before_main_content', 'lavka_storefront_woocommerce_start', 10);
    add_action('woocommerce_after_main_content', 'lavka_storefront_woocommerce_end', 10);
}, 20);

function lavka_storefront_show_title(): bool {
    // Keep an existing editor choice from the previous theme without migrating it.
    $show = !is_singular() || !get_post_meta(get_the_ID(), '_generate-disable-headline', true);
    return (bool) apply_filters('lavka_storefront_show_title', $show);
}

function lavka_storefront_has_own_header(): bool {
    return function_exists('psu_site_header_enabled') && psu_site_header_enabled();
}
function lavka_storefront_has_own_footer(): bool {
    return function_exists('psu_site_footer_enabled') && psu_site_footer_enabled();
}
