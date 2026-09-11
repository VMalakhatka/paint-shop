<?php
/** Read-only integration smoke: wp eval-file <file> <category-slug> <supplier-name>. */
if (!defined('WP_CLI') || !WP_CLI) exit;

$category = get_term_by('slug', $args[0] ?? 'laki', 'product_cat');
$supplier = get_term_by('name', $args[1] ?? 'Kreul', 'product_brand');
$check = static function (bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
    WP_CLI::log('PASS ' . $message);
};
$check($category instanceof WP_Term && $supplier instanceof WP_Term, 'category and supplier available');
$branch = array_merge([$category->term_id], get_term_children($category->term_id, 'product_cat'));
$saved_get = $_GET;
$saved_query = $GLOBALS['wp_query'];
$saved_main = $GLOBALS['wp_the_query'];
$override = static fn() => [$supplier->term_id => ['visible' => false, 'order' => 0]];
$run = static function (array $get): WP_Query {
    $_GET = $get;
    $query = new WP_Query();
    $GLOBALS['wp_query'] = $query;
    $GLOBALS['wp_the_query'] = $query;
    $query->query(apply_filters('request', array_merge(['post_type' => 'product', 'posts_per_page' => 12], $get)));
    return $query;
};
try {
    $filter = ['psu_filters' => '1', 'product_cat' => $category->slug, 'catalog_search' => '', 'brand' => $supplier->slug, 'min_price' => '', 'max_price' => ''];
    $query = $run($filter);
    $check(!$query->is_search() && $query->is_tax('product_cat') && !$query->is_post_type_archive('product'), 'empty search retains category template');
    $check(!isset($_GET['max_price']), 'empty price is not zero');
    $check(count($query->posts) > 0, 'filtered branch has products');
    foreach ($query->posts as $post) {
        if (!array_intersect($branch, wp_get_post_terms($post->ID, 'product_cat', ['fields' => 'ids'])) || !has_term($supplier->term_id, 'product_brand', $post->ID)) throw new RuntimeException('Product escaped category/supplier intersection');
    }
    $check(true, 'all results belong to branch and supplier');
    $product = $query->posts[0];
    $sku = get_post_meta($product->ID, '_sku', true);
    $exact = $run(array_merge($filter, ['catalog_search' => $sku]));
    $check(in_array($product->ID, wp_list_pluck($exact->posts, 'ID'), true), 'SKU search stays functional');
    $outside = get_posts(['post_type' => 'product', 'numberposts' => 1, 'meta_query' => [['key' => '_sku', 'value' => '', 'compare' => '!=']], 'tax_query' => [['taxonomy' => 'product_cat', 'field' => 'term_id', 'terms' => $branch, 'operator' => 'NOT IN']]]);
    $check((bool) $outside, 'out-of-category control product available');
    $excluded = $run(array_merge($filter, ['catalog_search' => get_post_meta($outside[0]->ID, '_sku', true)]));
    $check(!in_array($outside[0]->ID, wp_list_pluck($excluded->posts, 'ID'), true), 'exact identifier cannot escape category');
    add_filter('pre_option_psu_catalog_suppliers', $override);
    $check(!in_array($supplier->term_id, wp_list_pluck(psu_catalog_supplier_terms(true), 'term_id'), true), 'visibility controls dropdown');
    $ordered = $run(['product_cat' => $category->slug]);
    $check(has_term($supplier->term_id, 'product_brand', $ordered->posts[0]->ID), 'hidden supplier products retain priority');
    $page2 = $run(['product_cat' => $category->slug, 'paged' => 2]);
    $check(!array_intersect(wp_list_pluck($ordered->posts, 'ID'), wp_list_pluck($page2->posts, 'ID')), 'stable pagination without duplicates');
    $price = $run(['product_cat' => $category->slug, 'orderby' => 'price']);
    $check(strpos($price->request, 'psu_tr') === false, 'explicit sorting overrides supplier priority');
} finally {
    remove_filter('pre_option_psu_catalog_suppliers', $override);
    $_GET = $saved_get;
    $GLOBALS['wp_query'] = $saved_query;
    $GLOBALS['wp_the_query'] = $saved_main;
}
