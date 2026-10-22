<?php
// Run only against paint.local: wp eval-file .../tests/supplier-sync-local.php
if (!defined('WP_CLI') || !WP_CLI || parse_url(home_url(), PHP_URL_HOST) !== 'paint.local') {
    throw new RuntimeException('Local WordPress test only');
}

$checks = 0;
$assert = static function ($condition, string $message) use (&$checks): void {
    if (!$condition) throw new RuntimeException($message);
    $checks++;
};
$products = [];
$terms = [];
$old_user = get_current_user_id();
$settings = get_option('psu_catalog_suppliers', null);
$tag = 'LTS-SUPPLIER-TEST-' . wp_generate_password(10, false);
$request = static function (string $method, string $path, array $params = []) {
    $req = new WP_REST_Request($method, '/wc/v3/' . $path);
    $req->set_body_params($params);
    return rest_do_request($req);
};
$insert_failure = static function ($term) use ($tag) {
    return $term === $tag . '-fail' ? new WP_Error('fixture_failure', 'Synthetic failure') : $term;
};
$corrupt = null;
$display_override = null;
$old_query = $GLOBALS['wp_query'] ?? null;
try {
    $schema = $request('OPTIONS', 'products')->get_data();
    $assert(($schema['schema']['properties']['lavka_supplier']['x-lavka-full-sync-supplier'] ?? false) === true, 'OPTIONS advertises contract');
    wp_set_current_user(0);
    $denied = $request('POST', 'products', ['name' => $tag, 'lavka_supplier' => $tag . '-denied']);
    $assert($denied->get_status() >= 400, 'Anonymous write rejected');
    $assert(!get_term_by('name', $tag . '-denied', 'product_brand'), 'Denied request creates no term');
    $admins = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID']);
    if (!$admins) throw new RuntimeException('Local administrator required');
    wp_set_current_user((int) $admins[0]);

    $response = $request('POST', 'products', [
        'name' => $tag, 'sku' => $tag, 'status' => 'draft', 'regular_price' => '17.50',
        'manage_stock' => true, 'stock_quantity' => 9, 'lavka_supplier' => $tag . '-first',
        'meta_data' => [['key' => '_ms_hash', 'value' => 'first-hash']],
    ]);
    $data = $response->get_data();
    if (!empty($data['id'])) $products[] = (int) $data['id'];
    $assert($response->get_status() === 201, 'REST create succeeds: ' . wp_json_encode($data));
    $id = $products[0];
    $commercial_state = static fn() => array_map(static fn($key) => get_post_meta($id, $key, true), ['_regular_price', '_price', '_manage_stock', '_stock']);
    $commercial_before = $commercial_state();
    $first = get_term_by('name', $tag . '-first', 'product_brand');
    $assert($first && has_term($first->term_id, 'product_brand', $id), 'New product supplier saved');
    $terms[] = (int) $first->term_id;
    $assert(get_post_meta($id, '_ms_hash', true) === 'first-hash', 'Hash saved with supplier');

    $product = wc_get_product($id);
    $product->set_status('publish');
    $product->save();
    $selected = $request('GET', 'products', ['brand' => $first->term_id, 'include' => [$id]])->get_data();
    $assert(in_array($id, array_column($selected, 'id'), true), 'Supplier filter selects assigned product');
    $display_settings = is_array($settings) ? $settings : [];
    $display_settings[$first->term_id] = ['visible' => true, 'order' => 73];
    $display_override = static function () use (&$display_settings) { return $display_settings; };
    add_filter('pre_option_psu_catalog_suppliers', $display_override);
    $query = new WP_Query();
    $query->is_singular = true;
    $query->queried_object = get_post($id);
    $GLOBALS['wp_query'] = $query;
    $assert(in_array((int) $first->term_id, wp_list_pluck(psu_catalog_supplier_terms(true), 'term_id'), true), 'Enabled supplier remains in storefront filter');
    $assert(psu_single_product_brands_output('supplier visible', [$first]) === 'supplier visible', 'Enabled supplier shown in product metadata');
    $display_settings[$first->term_id]['visible'] = false;
    $assert(psu_single_product_brands_output('supplier visible', [$first]) === '', 'Hidden supplier stays hidden in product metadata');
    remove_filter('pre_option_psu_catalog_suppliers', $display_override);
    $GLOBALS['wp_query'] = $old_query;
    $product->set_status('draft');
    $product->save();

    $response = $request('PUT', 'products/' . $id, ['lavka_supplier' => $tag . '-second',
        'meta_data' => [['key' => '_ms_hash', 'value' => 'second-hash']]]);
    $assert($response->get_status() === 200, 'REST update succeeds');
    $second = get_term_by('name', $tag . '-second', 'product_brand');
    $terms[] = (int) $second->term_id;
    $assert(wp_get_object_terms($id, 'product_brand', ['fields' => 'ids']) === [(int) $second->term_id], 'Supplier replaces old relationship');
    $again = $request('PUT', 'products/' . $id, ['lavka_supplier' => $tag . '-second']);
    $assert($again->get_status() === 200, 'Repeated supplier update succeeds');
    $assert(count(get_terms(['taxonomy' => 'product_brand', 'hide_empty' => false, 'name' => $tag . '-second'])) === 1, 'Repeat does not duplicate supplier');
    $assert($commercial_state() === $commercial_before, 'Price and stock unchanged from post-create state');

    $request('PUT', 'products/' . $id, ['name' => $tag . '-renamed']);
    $assert(has_term($second->term_id, 'product_brand', $id), 'Missing supplier field preserves assignment');
    $response = $request('PUT', 'products/' . $id, ['lavka_supplier' => '']);
    $assert($response->get_status() === 200 && wp_get_object_terms($id, 'product_brand', ['fields' => 'ids']) === [], 'Explicit empty supplier clears assignment');

    $request('PUT', 'products/' . $id, ['lavka_supplier' => $tag . '-first']);
    $old_hash = get_post_meta($id, '_ms_hash', true);
    add_filter('pre_insert_term', $insert_failure);
    $failed = $request('POST', 'products/batch', ['update' => [['id' => $id,
        'lavka_supplier' => $tag . '-fail', 'meta_data' => [['key' => '_ms_hash', 'value' => 'must-not-save']]]]])->get_data();
    $assert(($failed['update'][0]['error']['code'] ?? '') === 'lts_supplier_save_failed', 'Batch returns per-item supplier failure');
    $assert(get_post_meta($id, '_ms_hash', true) === $old_hash && has_term($first->term_id, 'product_brand', $id), 'Failed term creation preserves old hash and assignment');
    remove_filter('pre_insert_term', $insert_failure);

    $invalid = $request('PUT', 'products/' . $id, ['lavka_supplier' => '<invalid>', 'meta_data' => [['key' => '_ms_hash', 'value' => 'bad']]]);
    $assert($invalid->get_status() === 400 && get_post_meta($id, '_ms_hash', true) === $old_hash, 'Invalid supplier rejected before hash');
    $conflict = $request('PUT', 'products/' . $id, ['lavka_supplier' => '', 'brands' => [['id' => $first->term_id]]]);
    $assert($conflict->get_status() === 400, 'Conflicting assignments rejected');

    $corrupt = static function ($product, $req) use ($id) {
        if ($product->get_id() === $id && $req->has_param('lavka_supplier')) wp_set_object_terms($id, [], 'product_brand');
    };
    add_action('woocommerce_rest_insert_product_object', $corrupt, 90, 2);
    $failed = $request('PUT', 'products/' . $id, ['lavka_supplier' => $tag . '-second', 'meta_data' => [['key' => '_ms_hash', 'value' => 'unverified']]]);
    $assert($failed->get_status() === 500 && get_post_meta($id, '_ms_hash', true) === '', 'Readback mismatch invalidates hash');
    remove_action('woocommerce_rest_insert_product_object', $corrupt, 90);

    $fallback = lts_upsert_product_from_item(['sku' => $tag, 'name' => $tag, 'supplier' => $tag . '-first', 'hash' => 'php-hash'], []);
    $assert($fallback['ok'] && has_term($first->term_id, 'product_brand', $id), 'PHP fallback assigns supplier');
    $assert(get_post_meta($id, '_ms_hash', true) === 'php-hash', 'PHP fallback acknowledges hash');
    lts_upsert_product_from_item(['sku' => $tag, 'name' => $tag, 'hash' => 'legacy-hash'], []);
    $assert(has_term($first->term_id, 'product_brand', $id), 'Old Java payload preserves supplier in fallback');
    lts_upsert_product_from_item(['sku' => $tag, 'name' => $tag, 'supplier' => '', 'hash' => 'empty-hash'], []);
    $assert(wp_get_object_terms($id, 'product_brand', ['fields' => 'ids']) === [], 'Fallback clears empty supplier');
    $assert(get_option('psu_catalog_suppliers', null) === $settings, 'Visibility and ordering option unchanged');
    $visible = function_exists('psu_catalog_supplier_terms') ? wp_list_pluck(psu_catalog_supplier_terms(true), 'term_id') : [];
    $assert(!array_intersect($terms, $visible), 'New suppliers are not made visible automatically');
    echo "PASS: {$checks} supplier checks\n";
} finally {
    remove_filter('pre_insert_term', $insert_failure);
    if ($display_override) remove_filter('pre_option_psu_catalog_suppliers', $display_override);
    $GLOBALS['wp_query'] = $old_query;
    if ($corrupt) remove_action('woocommerce_rest_insert_product_object', $corrupt, 90);
    foreach ($products as $id) wp_delete_post($id, true);
    foreach (get_terms(['taxonomy' => 'product_brand', 'hide_empty' => false, 'search' => $tag]) as $term) {
        if (str_starts_with($term->name, $tag)) wp_delete_term($term->term_id, 'product_brand');
    }
    wp_set_current_user($old_user);
}
