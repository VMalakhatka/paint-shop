<?php
/** Presentation boundary for customer pages. Business rules stay with their owners. */
defined('ABSPATH') || exit;

function psu_storefront_context(): string {
    if (is_admin() || !function_exists('is_shop')) return '';
    if (is_product()) return 'product';
    if (is_cart()) return 'cart';
    if (is_checkout()) return 'checkout';
    if (is_account_page()) return 'account';
    if (is_shop() || is_product_taxonomy()
        || (is_search() && get_query_var('post_type') === 'product')) return 'catalog';
    $page = is_page() ? get_queried_object() : null;
    if ($page instanceof WP_Post && has_shortcode($page->post_content, 'pc_quick_order')) return 'quick-order';
    return '';
}

add_filter('body_class', static function (array $classes): array {
    $context = psu_storefront_context();
    if ($context !== '') {
        $classes[] = 'psu-storefront';
        $classes[] = 'psu-storefront--' . $context;
    }
    return $classes;
});

add_action('wp_enqueue_scripts', static function (): void {
    if (psu_storefront_context() === '') return;
    $file = dirname(__DIR__) . '/assets/storefront.css';
    wp_enqueue_style('psu-storefront', plugins_url('../assets/storefront.css', __FILE__), [], (string) filemtime($file));
}, 90);

// Wide task pages need the whole content column, including on tablets. Catalogue
// sidebars retain their widgets and configured side; no widget settings are changed.
add_filter('generate_sidebar_layout', static function ($layout) {
    return in_array(psu_storefront_context(), ['cart', 'checkout', 'account', 'quick-order'], true)
        ? 'no-sidebar' : $layout;
}, 110);
