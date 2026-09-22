<?php
// Local-only fixtures use direct SQL so product sync/save hooks never run.
error_reporting(E_ERROR | E_PARSE);
if (!defined('WP_CLI') || !WP_CLI || !in_array(wp_get_environment_type(), ['local', 'development'], true)) {
    throw new RuntimeException('Run only in local/development WordPress.');
}
require dirname(__DIR__) . '/inc/class-supplier-feed.php';
require dirname(__DIR__) . '/inc/class-supplier-catalog.php';
use Lavka\ProductMediaUpload\SupplierCatalog;
global $wpdb;
SupplierCatalog::install();
$id = 'visibility' . substr(md5(uniqid()), 0, 15);
$table = $wpdb->prefix . 'lpmu_supplier_items';
$saved = get_option(SupplierCatalog::OPTION, []);
$stock = get_option('woocommerce_hide_out_of_stock_items', null);
$posts = [];
$terms = wc_get_product_visibility_term_ids();
$taxonomy = [];
foreach (['exclude-from-catalog', 'exclude-from-search', 'outofstock'] as $slug) {
    $taxonomy[$slug] = (int) $wpdb->get_var($wpdb->prepare("SELECT term_taxonomy_id FROM {$wpdb->term_taxonomy} WHERE term_id=%d AND taxonomy='product_visibility'", $terms[$slug]));
    if (!$taxonomy[$slug]) { throw new RuntimeException('Missing Woo visibility terms'); }
}
$create = function ($name, $status = 'publish', $parent = 0, $hidden = [], $password = '') use (&$posts, $wpdb, $taxonomy) {
    $wpdb->insert($wpdb->posts, ['post_title' => $name, 'post_status' => $status, 'post_type' => $parent ? 'product_variation' : 'product', 'post_parent' => $parent,
        'post_password' => $password, 'post_content' => '', 'post_excerpt' => '', 'to_ping' => '', 'pinged' => '', 'post_content_filtered' => '']);
    $pid = (int) $wpdb->insert_id;
    if (!$pid) { throw new RuntimeException($wpdb->last_error); }
    $posts[] = $pid;
    foreach ($hidden as $slug) { $wpdb->insert($wpdb->term_relationships, ['object_id' => $pid, 'term_taxonomy_id' => $taxonomy[$slug]]); }
    return $pid;
};
$c = new SupplierCatalog();
$listing = new ReflectionMethod($c, 'listing');
$check = function ($expected, $label, $extra = []) use ($listing, $c, $id) {
    $_POST = array_merge(['visible_only' => '1'], $extra);
    $result = $listing->invoke($c, $id);
    $names = array_column($result['items'], 'name');
    sort($names); sort($expected);
    if ($result['total'] !== count($expected) || $names !== $expected) { throw new RuntimeException($label . ': ' . json_encode($result)); }
    echo "PASS $label\n";
};
try {
    update_option(SupplierCatalog::OPTION, array_merge($saved, [$id => ['name' => 'Test visibility', 'type' => 'xml', 'url' => 'https://example.com/feed.xml']]), false);
    update_option('lpmu_supplier_active_' . $id, ['generation' => 'test'], false);
    $visible = $create('visible');
    $hidden = $create('hidden', 'publish', 0, ['exclude-from-catalog', 'exclude-from-search']);
    $draft = $create('draft', 'draft');
    $fixtures = [
        'visible' => $visible,
        'hidden' => $hidden,
        'draft' => $draft,
        'private' => $create('private', 'private'),
        'password' => $create('password', 'publish', 0, [], 'test'),
        'catalogue' => $create('catalogue', 'publish', 0, ['exclude-from-search']),
        'search' => $create('search', 'publish', 0, ['exclude-from-catalog']),
        'no-stock' => $create('no-stock', 'publish', 0, ['outofstock']),
        'variation' => $create('variation', 'publish', $visible),
        'hidden-parent' => $create('hidden-parent', 'publish', $hidden),
        'draft-parent' => $create('draft-parent', 'publish', $draft),
        'disabled-variation' => $create('disabled-variation', 'private', $visible),
        'unmatched' => 0,
        'deleted' => 2147483647,
    ];
    foreach ($fixtures as $name => $pid) {
        if (!$wpdb->insert($table, ['source' => $id, 'generation' => 'test', 'external_key' => hash('sha256', $name), 'sku' => $name, 'barcode' => '', 'name' => $name, 'brand' => '', 'category' => '', 'product_id' => $pid,
            'match_state' => $pid ? 'sku' : 'unmatched', 'manual_sku' => '', 'payload' => json_encode(['description' => '', 'images' => []])])) { throw new RuntimeException($wpdb->last_error); }
    }
    update_option('woocommerce_hide_out_of_stock_items', 'no');
    $eligible = ['visible', 'catalogue', 'search', 'no-stock', 'variation'];
    $check($eligible, 'published, catalogue/search visibility and variation parents');
    $check($eligible, 'visibility combines with missing main photo', ['filter' => 'missing']);
    $wpdb->insert($wpdb->postmeta, ['post_id' => $visible, 'meta_key' => '_thumbnail_id', 'meta_value' => '123']);
    $check(['catalogue', 'search', 'no-stock', 'variation'], 'missing main photo excludes assigned image', ['filter' => 'missing']);
    $check([], 'visibility excludes unmatched products', ['filter' => 'unmatched']);
    update_option('woocommerce_hide_out_of_stock_items', 'yes');
    $check(['visible', 'catalogue', 'search', 'variation'], 'respects Woo hide out of stock');
    $check(['search'], 'visibility combines with search', ['q' => 'search']);
    $wpdb->update($wpdb->posts, ['post_status' => 'draft'], ['ID' => $visible]);
    $check(['catalogue', 'search'], 'publication changes apply without feed refresh');
    $_POST = [];
    $unfiltered = $listing->invoke($c, $id);
    if ($unfiltered['total'] !== count($fixtures)) { throw new RuntimeException('Unfiltered catalogue changed'); }
    echo "PASS unchecked filter preserves full catalogue\n";
} finally {
    foreach ($posts as $pid) {
        $wpdb->delete($wpdb->term_relationships, ['object_id' => $pid]);
        $wpdb->delete($wpdb->postmeta, ['post_id' => $pid]);
        $wpdb->delete($wpdb->posts, ['ID' => $pid]);
        clean_post_cache($pid);
    }
    $wpdb->delete($table, ['source' => $id]);
    delete_option('lpmu_supplier_active_' . $id);
    update_option(SupplierCatalog::OPTION, $saved, false);
    if ($stock === null) { delete_option('woocommerce_hide_out_of_stock_items'); } else { update_option('woocommerce_hide_out_of_stock_items', $stock); }
}
