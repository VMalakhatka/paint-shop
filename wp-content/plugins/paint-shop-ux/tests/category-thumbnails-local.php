<?php
/** Local integration test. Temporary products/categories/brands are removed in finally. */
if (!defined('WP_CLI') || !WP_CLI || wp_get_environment_type() !== 'local'
    || parse_url(home_url(), PHP_URL_HOST) !== 'paint.local') throw new RuntimeException('Local only');

$checks = 0; $posts = []; $terms = []; $categories = [];
$saved_get = $_GET; $settings = []; $hide_stock = 'no';
$supplier_override = static function () use (&$settings) { return $settings; };
$stock_override = static function () use (&$hide_stock) { return $hide_stock; };
$check = static function ($ok, $label) use (&$checks) {
    if (!$ok) throw new RuntimeException($label);
    $checks++;
};
$prefix = 'psu-thumbnail-test-' . wp_generate_uuid4();
$term = static function ($suffix, $taxonomy, $parent = 0) use ($prefix, &$terms, &$categories) {
    $result = wp_insert_term($prefix . $suffix, $taxonomy, ['parent' => $parent]);
    if (is_wp_error($result)) throw new RuntimeException($result->get_error_message());
    $id = (int) $result['term_id']; $terms[] = [$id, $taxonomy];
    if ($taxonomy === 'product_cat') $categories[] = $id;
    return $id;
};
$product = static function ($title, $cat, $brands, $image = 0, $extra = []) use (&$posts) {
    $id = wp_insert_post(array_merge(['post_type' => 'product', 'post_status' => 'publish', 'post_title' => $title], $extra), true);
    if (is_wp_error($id)) throw new RuntimeException($id->get_error_message());
    $posts[] = $id;
    wp_set_object_terms($id, [$cat], 'product_cat');
    wp_set_object_terms($id, $brands, 'product_brand');
    if ($image) update_post_meta($id, '_thumbnail_id', $image);
    return $id;
};
$clear = static function () use (&$categories) {
    global $wpdb;
    foreach ($categories as $id) {
        $names = $wpdb->get_col($wpdb->prepare("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like('_transient_psu_cat_thumb_v2_' . $id . '_') . '%'));
        foreach ($names as $name) delete_transient(substr($name, strlen('_transient_')));
    }
};
try {
    // Reuse existing images without modifying their metadata or files.
    $images = get_posts(['post_type' => 'attachment', 'post_status' => 'inherit', 'post_mime_type' => 'image', 'numberposts' => 3, 'fields' => 'ids']);
    $check(count($images) === 3, 'Three local image fixtures available');
    [$image_a, $image_b, $image_manual] = $images;
    $root = $term('-root', 'product_cat');
    $child = $term('-child', 'product_cat', $root);
    $empty = $term('-empty', 'product_cat');
    $brand_a = $term('-a', 'product_brand'); $brand_b = $term('-b', 'product_brand');
    $settings = [$brand_a => ['order' => 0, 'visible' => false], $brand_b => ['order' => 1]];
    add_filter('pre_option_psu_catalog_suppliers', $supplier_override);
    add_filter('pre_option_woocommerce_hide_out_of_stock_items', $stock_override);
    $a = $product('B preferred supplier', $child, [$brand_a], $image_a);
    $b = $product('A second supplier', $root, [$brand_b], $image_b);
    $product('0 unassigned', $root, [], $image_manual);
    $product('0 draft', $root, [$brand_a], $image_manual, ['post_status' => 'draft']);
    $product('0 protected', $root, [$brand_a], $image_manual, ['post_password' => 'local-fixture']);
    $hidden = $product('0 hidden', $root, [$brand_a], $image_manual);
    wp_set_object_terms($hidden, ['exclude-from-catalog'], 'product_visibility');
    // The image must still be found beyond the old newest-20 candidate window.
    for ($i = 0; $i < 21; $i++) $product('0 no image ' . $i, $root, [$brand_a]);
    $check(psu_category_fallback_thumbnail_id($root) === $image_a, 'Supplier priority, descendants, visibility and image requirement');
    $check(psu_category_fallback_thumbnail_id($child) === $image_a, 'Subgroup image belongs to its own branch');
    $check(psu_category_fallback_thumbnail_id($empty) === 0, 'Empty category has no borrowed outside image');
    ob_start(); psu_subcategory_thumbnail(get_term($empty)); $html = ob_get_clean();
    $check(str_contains($html, 'psu-cat-faux-thumb'), 'Text tile remains when no image exists');
    update_term_meta($root, 'thumbnail_id', $image_manual);
    ob_start(); psu_subcategory_thumbnail(get_term($root)); $html = ob_get_clean();
    $check($html === wp_get_attachment_image($image_manual, 'woocommerce_thumbnail', false, ['loading' => 'lazy']), 'Assigned category image takes precedence');
    $settings[$brand_b]['order'] = -1;
    $check(psu_category_fallback_thumbnail_id($root) === $image_b, 'Supplier reorder bypasses cached selection immediately');
    $clear(); $_GET['orderby'] = 'price'; $_GET['s'] = 'unrelated search';
    $check(psu_category_fallback_thumbnail_id($root) === $image_b, 'Page sort/search do not alter representative image');
    wp_set_object_terms($a, [$brand_a, $brand_b], 'product_brand');
    wp_update_post(['ID' => $a, 'post_title' => '0 multi-supplier']); $clear();
    $check(psu_category_fallback_thumbnail_id($root) === $image_a, 'Multiple suppliers use earliest priority, then product title');
    wp_set_object_terms($a, ['outofstock'], 'product_visibility'); $hide_stock = 'yes';
    $check(psu_category_fallback_thumbnail_id($root) === $image_b, 'Hide-out-of-stock setting changes image candidates and cache');
    echo wp_json_encode(['passed' => $checks]), "\n";
} finally {
    $clear();
    remove_filter('pre_option_psu_catalog_suppliers', $supplier_override);
    remove_filter('pre_option_woocommerce_hide_out_of_stock_items', $stock_override);
    $_GET = $saved_get;
    foreach ($posts as $id) wp_delete_post($id, true);
    foreach (array_reverse($terms) as [$id, $taxonomy]) wp_delete_term($id, $taxonomy);
}
