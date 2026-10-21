<?php
/** Read-only scope regression: use existing Woo page options, never local IDs. */
if (!defined('WP_CLI') || !WP_CLI || wp_get_environment_type() !== 'local'
    || parse_url(home_url(), PHP_URL_HOST) !== 'paint.local') throw new RuntimeException('Local only');
$saved_query = $GLOBALS['wp_query'];
$saved_post = $GLOBALS['post'] ?? null;
// Woo's block utilities memoize page flags for one HTTP request. This CLI test
// changes queries in one process; use Woo's public filters to emulate each route.
// Browser acceptance separately verifies the real HTTP conditionals.
$cart_route = static fn() => is_page(wc_get_page_id('cart'));
$checkout_route = static fn() => is_page(wc_get_page_id('checkout'));
add_filter('woocommerce_is_cart', $cart_route);
add_filter('woocommerce_is_checkout', $checkout_route);
$checks = 0;
$check = static function ($ok, $label) use (&$checks): void {
    if (!$ok) throw new RuntimeException($label);
    $checks++;
};
try {
    foreach (['cart' => 'cart', 'checkout' => 'checkout', 'myaccount' => 'account'] as $option => $context) {
        $page = get_post(wc_get_page_id($option));
        $check($page instanceof WP_Post, 'Configured page: ' . $option);
        $q = new WP_Query(['page_id' => $page->ID]);
        $GLOBALS['post'] = $page;
        $GLOBALS['wp_query'] = $q;
        $check(psu_storefront_context() === $context, 'Context uses Woo option: ' . $option);
        $check(in_array('psu-storefront--' . $context, apply_filters('body_class', []), true), 'Scoped body class: ' . $option);
        $check(apply_filters('generate_sidebar_layout', 'right-sidebar') === 'no-sidebar', 'Full content width: ' . $option);
    }
    $q = new WP_Query(); $q->is_page = true; $q->is_singular = true;
    $q->queried_object = new WP_Post((object) ['ID' => 0, 'post_type' => 'page', 'post_content' => '[pc_quick_order]']);
    $GLOBALS['post'] = $q->queried_object;
    $GLOBALS['wp_query'] = $q;
    $check(psu_storefront_context() === 'quick-order', 'Quick-order shortcode needs no fixed page ID');
    $q->queried_object->post_content = '[lavka_reports]';
    $check(psu_storefront_context() === '', 'Reports retain their owner');
    $check(!in_array('psu-storefront', apply_filters('body_class', []), true), 'No storefront class on unrelated pages');
    $check(apply_filters('generate_sidebar_layout', 'right-sidebar') === 'right-sidebar', 'Unrelated sidebar untouched');
    $q = new WP_Query(); $q->is_search = true; $q->set('post_type', 'product');
    $GLOBALS['wp_query'] = $q;
    $check(psu_storefront_context() === 'catalog', 'Product search styled');
    $check(apply_filters('generate_sidebar_layout', 'left-sidebar') === 'left-sidebar', 'Catalogue retains configured sidebar');
    $q->set('post_type', 'post');
    $check(psu_storefront_context() === '', 'Ordinary content search untouched');
    $q = new WP_Query(); $q->is_tax = true;
    $q->queried_object = get_term_by('slug', 'polimernij-modelin-cernit-number-one-56gr', 'product_cat');
    $GLOBALS['wp_query'] = $q;
    $check(psu_storefront_context() === 'catalog', 'Product category styled');
    echo wp_json_encode(['passed' => $checks]), "\n";
} finally {
    remove_filter('woocommerce_is_cart', $cart_route);
    remove_filter('woocommerce_is_checkout', $checkout_route);
    $GLOBALS['wp_query'] = $saved_query; $GLOBALS['post'] = $saved_post;
}
