<?php
/** Product image fallback for categories without an assigned thumbnail. */
defined('ABSPATH') || exit;

function psu_category_fallback_thumbnail_id(int $category_id): int {
    $suppliers = function_exists('psu_catalog_supplier_terms') ? psu_catalog_supplier_terms() : [];
    $signature = md5(wp_json_encode([
        wp_list_pluck($suppliers, 'term_id'),
        get_option('woocommerce_hide_out_of_stock_items'),
    ]));
    // A supplier reorder selects a new cache immediately; the previous daily
    // cache expires naturally. Version 2 also discards the old newest-20 rule.
    $cache_key = 'psu_cat_thumb_v2_' . $category_id . '_' . $signature;
    $cached = get_transient($cache_key);
    if (is_array($cached)) return (int) ($cached['attachment_id'] ?? 0);

    $visibility = wc_get_product_visibility_term_ids();
    $excluded = array_filter([$visibility['exclude-from-catalog'] ?? 0]);
    if (get_option('woocommerce_hide_out_of_stock_items') === 'yes') {
        $excluded[] = $visibility['outofstock'] ?? 0;
    }
    $tax_query = [[
        'taxonomy' => 'product_cat', 'field' => 'term_id',
        'terms' => [$category_id], 'include_children' => true,
    ]];
    if ($excluded) $tax_query[] = [
        'taxonomy' => 'product_visibility', 'field' => 'term_taxonomy_id',
        'terms' => $excluded, 'operator' => 'NOT IN',
    ];
    $query = new WP_Query([
        'post_type' => 'product', 'post_status' => 'publish', 'has_password' => false,
        'posts_per_page' => 1, 'fields' => 'ids', 'no_found_rows' => true,
        'orderby' => ['title' => 'ASC', 'ID' => 'ASC'],
        'tax_query' => $tax_query,
        'meta_query' => [['key' => '_thumbnail_id', 'value' => 0, 'compare' => '>', 'type' => 'UNSIGNED']],
        '_psu_category_thumbnail' => true,
    ]);
    $attachment_id = $query->posts ? (int) get_post_thumbnail_id($query->posts[0]) : 0;
    set_transient($cache_key, ['attachment_id' => $attachment_id], DAY_IN_SECONDS);
    return $attachment_id;
}
