<?php
/** Product-page presentation. WooCommerce and stock/price owners keep their hooks. */
if (!defined('ABSPATH')) exit;

function psu_single_product_enabled(): bool {
    return !is_admin() && function_exists('is_product') && is_product()
        && (bool) apply_filters('psu_single_product_enabled', true);
}

/** Keep product metadata aligned with the suppliers enabled in the catalogue filter. */
function psu_single_product_brands_output($output, $terms) {
    if (is_admin() || !is_product() || !function_exists('psu_catalog_supplier_terms')) return $output;
    if (!is_array($terms) || !$terms) return '';

    $allowed = array_fill_keys(wp_list_pluck(psu_catalog_supplier_terms(true), 'term_id'), true);
    $visible = array_values(array_filter($terms, static fn($term): bool => isset($allowed[$term->term_id])));
    if (!$visible) return '';
    if (count($visible) === count($terms)) return $output;

    $links = [];
    foreach ($visible as $term) {
        $url = get_term_link($term, 'product_brand');
        if (!is_wp_error($url)) {
            $links[] = '<a href="' . esc_url($url) . '" rel="tag">' . esc_html($term->name) . '</a>';
        }
    }
    if (!$links) return '';
    $labels = get_taxonomy('product_brand')->labels;
    $label = count($links) === 1 ? $labels->singular_name : $labels->name;
    return ' <span class="posted_in">' . esc_html($label) . ': ' . implode(', ', $links) . '</span>';
}
add_filter('woocommerce_product_brands_output', 'psu_single_product_brands_output', 20, 2);

add_filter('wc_get_template_part', function ($template, $slug, $name) {
    if ($slug !== 'content' || $name !== 'single-product' || !psu_single_product_enabled()) return $template;
    return dirname(__DIR__) . '/templates/content-single-product.php';
}, 20, 3);

add_filter('body_class', function (array $classes): array {
    if (psu_single_product_enabled()) $classes[] = 'psu-product-page';
    return $classes;
});

add_filter('pcux_catalog_assets_required', function ($required) {
    return $required || psu_single_product_enabled();
});

add_filter('generate_sidebar_layout', function ($layout) {
    return psu_single_product_enabled() ? 'no-sidebar' : $layout;
}, 100);

function psu_single_product_setup(): void {
    if (!psu_single_product_enabled()) return;

    // Replace only the current theme/default Woo wrappers, leaving all content hooks.
    remove_action('woocommerce_before_main_content', 'generate_woocommerce_start', 10);
    remove_action('woocommerce_after_main_content', 'generate_woocommerce_end', 10);
    remove_action('woocommerce_before_main_content', 'woocommerce_output_content_wrapper', 10);
    remove_action('woocommerce_after_main_content', 'woocommerce_output_content_wrapper_end', 10);
    remove_action('woocommerce_sidebar', 'generate_construct_sidebars', 10);
    remove_action('woocommerce_sidebar', 'woocommerce_get_sidebar', 10);
    add_action('woocommerce_before_main_content', 'psu_single_product_content_start', 10);
    add_action('woocommerce_after_main_content', 'psu_single_product_content_end', 10);
}
add_action('wp', 'psu_single_product_setup');

function psu_single_product_content_start(): void {
    echo '<div id="primary" class="psu-product-content"><main id="main" class="psu-product-main">';
}

function psu_single_product_content_end(): void {
    echo '</main></div>';
}

add_action('wp_enqueue_scripts', function (): void {
    if (!psu_single_product_enabled()) return;
    $file = dirname(__DIR__) . '/assets/single-product.css';
    wp_enqueue_style('psu-single-product', plugins_url('assets/single-product.css', dirname(__DIR__) . '/paint-shop-ux.php'), [], (string) filemtime($file));

    // The legacy handler intercepts every product type and retries failed POSTs.
    // Our page uses one simple-product request; other types keep native Woo forms.
    wp_dequeue_script('paint-core-single-ajax-atc');
    $script = dirname(__DIR__) . '/assets/single-product.js';
    wp_enqueue_script('psu-single-product', plugins_url('assets/single-product.js', dirname(__DIR__) . '/paint-shop-ux.php'), ['jquery', 'wc-add-to-cart'], (string) filemtime($script), true);
    wp_localize_script('psu-single-product', 'psuSingleProduct', [
        'success' => __('Product added to cart.', 'paint-shop-ux'),
        'rejected' => __('This quantity could not be added. Check availability or choose a smaller quantity.', 'paint-shop-ux'),
        'unknown' => __('Could not confirm whether the product was added. Check your cart before trying again.', 'paint-shop-ux'),
        'cartLabel' => __('View cart', 'woocommerce'),
        'cartUrl' => wc_get_cart_url(),
    ]);
}, 110);
