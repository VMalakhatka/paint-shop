<?php
/**
 * Plugin Name: PSU Products Per Page
 * Description: Stable catalogue page sizes, independent of responsive grid columns.
 * Author: PaintCore
 * Version: 1.2.0
 * Text Domain: psu-force-per-page
 * Domain Path: /languages
 */

if (!defined('ABSPATH')) exit;

/** A valid explicit selection; pp takes precedence over the legacy alias. */
function psufp_explicit_per_page(): array {
    foreach (['pp' => [6, 120], 'per_page' => [1, 200]] as $key => [$min, $max]) {
        $value = $_GET[$key] ?? null;
        if (!is_scalar($value) || !preg_match('/^[0-9]+$/D', (string) $value)) continue;
        $number = (int) $value;
        if ($number >= $min && $number <= $max) return [$key, $number];
    }
    return [];
}

function psufp_calc_per_page(): int {
    $explicit = psufp_explicit_per_page();
    if ($explicit) return $explicit[1];
    $default = (int) apply_filters('psu_products_per_page', 24);
    return $default >= 6 && $default <= 120 ? $default : 24;
}

function psufp_apply_page_size(WP_Query $query): void {
    if (is_admin() || !$query->is_main_query() || $query->is_feed()) return;
    $product_search = $query->is_search() && $query->get('post_type') === 'product';
    if (!$query->is_post_type_archive('product')
        && !$query->is_tax(get_object_taxonomies('product')) && !$product_search) return;

    // WP_Query can replace posts_per_page with posts_per_archive_page later.
    $size = psufp_calc_per_page();
    $query->set('posts_per_page', $size);
    $query->set('posts_per_archive_page', $size);
}

add_filter('loop_shop_per_page', 'psufp_calc_per_page', 9999);
add_action('pre_get_posts', 'psufp_apply_page_size', 9999);
add_action('woocommerce_product_query', 'psufp_apply_page_size', 9999);
