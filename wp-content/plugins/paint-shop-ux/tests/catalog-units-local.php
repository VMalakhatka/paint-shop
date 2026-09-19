<?php
// Read-only local integration: existing category, attributes and product relationships.
if (!defined('WP_CLI') || !WP_CLI || wp_get_environment_type() !== 'local'
    || parse_url(home_url(), PHP_URL_HOST) !== 'paint.local') throw new RuntimeException('Local only');
$checks = 0;
$check = static function ($ok, $message) use (&$checks) {
    if (!$ok) throw new RuntimeException($message);
    $checks++;
};
$saved = [$_GET, $GLOBALS['wp_query'], $GLOBALS['wp_the_query']];
$values_for = static function (int $id): array {
    $meta = trim((string) get_post_meta($id, '_edin_izmer', true));
    if ($meta !== '') return [$meta];
    return array_map('trim', wp_get_post_terms($id, 'pa_edin_izmer', ['fields' => 'names']));
};
$run = static function (array $get): WP_Query {
    $_GET = $get;
    $q = new WP_Query();
    $GLOBALS['wp_query'] = $GLOBALS['wp_the_query'] = $q;
    $q->query(apply_filters('request', array_merge(['post_type' => 'product'], $get)));
    return $q;
};
try {
    $category = get_term_by('slug', 'farbi-akrilovi', 'product_cat');
    $unit = '80мл';
    $check($category instanceof WP_Term, 'Existing local fixture required');
    $get = ['psu_filters' => '1', 'product_cat' => $category->slug, 'unit' => $unit, 'pp' => '12'];
    $query = $run($get);
    $check($query->is_tax('product_cat') && !$query->is_search(), 'Category route retained');
    $check(count($query->posts) === 12 && $query->found_posts > 12, 'Selected unit is paginated');
    $first = wp_list_pluck($query->posts, 'ID');
    foreach ($first as $id) $check(in_array($unit, $values_for($id), true), 'Every result has the exact unit');
    $check(!str_contains($query->request, 'wc_product_attributes_lookup'), 'No dependency on partial attribute lookup');
    $check(apply_filters('woocommerce_is_filtered', false), 'Filtered state includes units');
    $branch = array_merge([$category->term_id], get_term_children($category->term_id, 'product_cat'));
    foreach ($first as $id) $check((bool) array_intersect($branch, wp_get_post_terms($id, 'product_cat', ['fields' => 'ids'])), 'Category descendants respected');
    $units = psu_catalog_unit_values($category);
    $check(in_array($unit, $units, true), 'Unit offered in current branch');
    $names = $units;
    $sorted = $names; usort($sorted, 'strnatcasecmp');
    $check($names === $sorted && in_array('80мл', $names, true), 'Natural ordering and exact Folio spelling');
    $scope_query = WC()->query->get_tax_query([['taxonomy' => 'product_cat', 'terms' => [$category->term_id]]]);
    $all = get_posts(['post_type' => 'product', 'post_status' => 'publish', 'numberposts' => -1, 'fields' => 'ids', 'tax_query' => $scope_query]);
    $expected = [];
    foreach ($all as $id) $expected = array_merge($expected, $values_for($id));
    $expected = array_values(array_unique($expected));
    usort($expected, static fn($a, $b): int => strnatcasecmp($a, $b) ?: strcmp($a, $b));
    $check($units === $expected, 'All and only visible branch values from both storage paths are offered');
    $eligible = array_values(array_filter($all, static fn($id): bool => in_array($unit, $values_for($id), true)));
    file_put_contents('/tmp/psu-catalog-units-fixture.json', wp_json_encode(['ids' => array_map('strval', $eligible)]));
    $sku = get_post_meta($first[0], '_sku', true);
    $exact = $run(array_merge($get, ['catalog_search' => $sku]));
    $check(in_array($first[0], wp_list_pluck($exact->posts, 'ID'), true), 'Exact SKU and unit intersect');
    $other = array_values(array_diff($units, [$unit]))[0];
    $mismatch = $run(array_merge($get, ['catalog_search' => $sku, 'unit' => $other]));
    $check(!$mismatch->posts, 'Exact SKU cannot bypass a different unit');
    $second = $run(array_merge($get, ['paged' => 2]));
    $check(count($second->posts) > 0 && !array_intersect($first, wp_list_pluck($second->posts, 'ID')), 'Unit pagination has no duplicates');
    $unknown = $run(array_merge($get, ['unit' => 'psu-unit-does-not-exist']));
    $check(!$unknown->posts, 'Unknown unit fails closed');
    ob_start(); psu_render_catalog_filters(); $html = ob_get_clean();
    $check(str_contains($html, 'value="psu-unit-does-not-exist" selected'), 'Unknown selection remains clearable on empty results');
    $_GET = ['unit' => ['invalid']];
    $check(psu_catalog_unit_selection() === '', 'Array input safely ignored');
    $_GET = ['unit' => '250мл'];
    $check(psu_catalog_unit_selection() === '250мл', 'Compact volume is a whole value');
    $_GET = ['unit' => '2,5г'];
    $check(psu_catalog_unit_selection() === '2,5г', 'Decimal comma is preserved, not confused with 25г');
    $supplier = wp_get_post_terms($first[0], 'product_brand')[0] ?? null;
    $check($supplier instanceof WP_Term, 'Supplier fixture exists');
    $combined = $run(array_merge($get, ['brand' => $supplier->slug, 'in_stock' => '1', 'orderby' => 'price', 'min_price' => '1', 'max_price' => '1000000']));
    $check((bool) $combined->posts, 'Combined unit/supplier/stock filter has products');
    foreach ($combined->posts as $post) {
        $check(in_array($unit, $values_for($post->ID), true) && has_term($supplier->term_id, 'product_brand', $post->ID)
            && get_post_meta($post->ID, '_stock_status', true) === 'instock', 'Combined filter intersection retained');
    }
    // Regression: Java-only units in the exact category reported by the user.
    $varnishes = get_term_by('slug', 'laki', 'product_cat');
    $check($varnishes instanceof WP_Term, 'Varnish fixture exists');
    $varnish_units = psu_catalog_unit_values($varnishes);
    $check(in_array('250мл', $varnish_units, true), 'Java-only 250мл is offered in varnishes');
    $varnish_get = ['psu_filters' => '1', 'product_cat' => 'laki', 'unit' => '250мл'];
    $varnish_query = $run($varnish_get);
    $check((bool) $varnish_query->posts, 'Java-only unit returns products');
    foreach ($varnish_query->posts as $post) $check(in_array('250мл', $values_for($post->ID), true), 'Varnish filter uses actual unit');
    $example_id = wc_get_product_id_by_sku('KR-79406');
    $check(in_array($example_id, wp_list_pluck($varnish_query->posts, 'ID'), true), 'KR-79406 is found without resynchronization');
    $exact = $run(array_merge($varnish_get, ['catalog_search' => 'KR-79406']));
    $check(wp_list_pluck($exact->posts, 'ID') === [$example_id], 'Java unit and exact SKU intersect');
    $check(!$run(array_merge($varnish_get, ['unit' => '125мл', 'catalog_search' => 'KR-79406']))->posts, 'Java unit mismatch remains empty');
    $glue = $run(['psu_filters' => '1', 'product_cat' => 'klej', 'unit' => '125мл', 'catalog_search' => 'KR-49602']);
    $check(wp_list_pluck($glue->posts, 'ID') === [wc_get_product_id_by_sku('KR-49602')], 'KR-49602 is found in glue at 125мл');
    $check(!$run(array_merge($varnish_get, ['unit' => "250мл' OR 1=1 --"]))->posts, 'Unit SQL injection cannot broaden results');

    $main = $run($varnish_get);
    $secondary = new WP_Query(['post_type' => 'product', 'post__in' => [$first[0]]]);
    $check(!$secondary->get('_psu_unit_filter') && !str_contains($secondary->request, '_edin_izmer'), 'Secondary queries are untouched');
    $params = apply_filters('relevanssi_search_params', ['post_query' => false], $main);
    $restriction = apply_filters('relevanssi_post_query_filter', '', $params['post_query']);
    $check(str_contains($restriction, 'relevanssi.doc') && str_contains($restriction, '_edin_izmer'), 'Relevanssi receives same restriction');
    $check(apply_filters('relevanssi_search_params', ['post_query' => false], $secondary) === ['post_query' => false], 'Secondary Relevanssi is untouched');
    $check(apply_filters('relevanssi_post_query_filter', '', []) === '', 'No Relevanssi restriction leaks after main query');
    echo wp_json_encode(['passed' => $checks, 'units_in_category' => $names], JSON_UNESCAPED_UNICODE), "\n";
} finally {
    [$_GET, $GLOBALS['wp_query'], $GLOBALS['wp_the_query']] = $saved;
}
