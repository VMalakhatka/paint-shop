<?php
if (!defined('ABSPATH')) exit;

/** Rank Math counts hierarchical parents in its index but pages flat terms. */
function psu_category_sitemap_index_entry($entry, $kind, $type) {
    if (!$entry || $kind !== 'term' || $type !== 'product_cat'
        || !class_exists('RankMath\\Helper')) return $entry;

    $path = wp_parse_url($entry['loc'] ?? '', PHP_URL_PATH);
    if (!is_string($path) || !preg_match('~/product_cat-sitemap([0-9]*)\.xml$~D', $path, $match)) return $entry;
    $page = max(1, (int) $match[1]);
    $size = absint(\RankMath\Helper::get_settings('sitemap.items_per_page', 100));
    if (!$size) return $entry;

    // Match Taxonomy::get_terms(), not the menu's visibility or padded counts.
    // IDs preserve DISTINCT with meta joins; WP caches the query across entries.
    $terms = get_terms([
        'taxonomy' => 'product_cat',
        'fields' => 'ids',
        'orderby' => 'term_order',
        'number' => 0,
        'offset' => 0,
        'update_term_meta_cache' => false,
        'hide_empty' => !\RankMath\Helper::get_settings('sitemap.tax_product_cat_include_empty'),
        'hierarchical' => false,
        'exclude' => wp_parse_id_list(\RankMath\Helper::get_settings('sitemap.exclude_terms')),
        'meta_query' => [
            'relation' => 'OR',
            ['key' => 'rank_math_robots', 'value' => 'noindex', 'compare' => 'NOT LIKE'],
            ['key' => 'rank_math_robots', 'compare' => 'NOT EXISTS'],
        ],
    ]);
    // A failed lookup must not silently remove valid pages from the index.
    if (is_wp_error($terms) || !is_array($terms)) return $entry;
    return $page <= (int) ceil(count($terms) / $size) ? $entry : false;
}
add_filter('rank_math/sitemap/index/entry', 'psu_category_sitemap_index_entry', 20, 3);
