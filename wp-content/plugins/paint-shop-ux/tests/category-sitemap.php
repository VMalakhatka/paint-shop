<?php
namespace RankMath {
    class Helper {
        public static $settings = ['sitemap.items_per_page' => 100];
        public static function get_settings($key, $default = null) { return self::$settings[$key] ?? $default; }
    }
}
namespace {
    define('ABSPATH', __DIR__);
    $hooks = [];
    $count = '1251';
    $queries = [];
    function add_filter($name, $callback, $priority, $args) { $GLOBALS['hooks'][$name] = [$callback, $priority, $args]; }
    function wp_parse_url($url, $component) { return parse_url($url, $component); }
    function absint($value) { return abs((int) $value); }
    function wp_parse_id_list($value) { return $value ? array_map('intval', explode(',', $value)) : []; }
    function get_terms($args) { $GLOBALS['queries'][] = $args; $count = $GLOBALS['count']; return is_numeric($count) ? ($count ? range(1, (int) $count) : []) : $count; }
    function is_wp_error($value) { return $value instanceof \RuntimeException; }
    function expect($value, $message) { if (!$value) throw new \RuntimeException($message); }
    require dirname(__DIR__) . '/inc/category-sitemap.php';
    function entry($page = '', $type = 'product_cat') { return ['loc' => 'https://example.org/' . $type . '-sitemap' . $page . '.xml', 'lastmod' => '2026-10-08']; }
    $filter = 'psu_category_sitemap_index_entry';
    expect($hooks['rank_math/sitemap/index/entry'] === [$filter, 20, 3], 'Native index hook');
    for ($page = 1; $page <= 17; $page++) {
        expect($filter(entry($page), 'term', 'product_cat') === ($page <= 13 ? entry($page) : false), 'Correct page ' . $page);
    }
    expect(end($queries)['hierarchical'] === false && end($queries)['fields'] === 'ids', 'Distinct flat IDs');
    expect(end($queries)['orderby'] === 'term_order' && end($queries)['number'] === 0, 'Native ordering without pagination; avoid Woo name-order meta joins');
    $before = count($queries);
    expect($filter(entry(14, 'product'), 'post', 'product') === entry(14, 'product'), 'Products unchanged');
    expect($filter(entry('', 'product_brand'), 'term', 'product_brand') === entry('', 'product_brand'), 'Other taxonomies unchanged');
    expect($filter(false, 'term', 'product_cat') === false, 'Prior exclusion preserved');
    expect(count($queries) === $before, 'Unrelated entries do not query');
    $count = 100;
    expect($filter(entry(), 'term', 'product_cat') === entry(), 'Unnumbered page preserved');
    expect($filter(entry(2), 'term', 'product_cat') === false, 'Exact page boundary');
    $count = 101;
    expect($filter(entry(2), 'term', 'product_cat') === entry(2), 'Partial last page');
    \RankMath\Helper::$settings['sitemap.items_per_page'] = 200;
    expect($filter(entry(2), 'term', 'product_cat') === false, 'Configured page size');
    \RankMath\Helper::$settings['sitemap.tax_product_cat_include_empty'] = true;
    \RankMath\Helper::$settings['sitemap.exclude_terms'] = '12,34';
    $filter(entry(), 'term', 'product_cat');
    expect(end($queries)['hide_empty'] === false && end($queries)['exclude'] === [12, 34], 'Empty/excluded term settings');
    expect(end($queries)['meta_query'][0]['value'] === 'noindex', 'Noindex terms excluded before pagination');
    $count = 0;
    expect($filter(entry(), 'term', 'product_cat') === false, 'Empty taxonomy omitted');
    $count = new \RuntimeException('DB unavailable');
    expect($filter(entry(17), 'term', 'product_cat') === entry(17), 'Errors preserve original index');
    echo "Category sitemap index: OK\n";
}
