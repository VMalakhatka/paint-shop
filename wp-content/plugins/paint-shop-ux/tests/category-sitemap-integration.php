<?php
// Read-only integration check: wp eval-file <this file> on the local site.
if (!defined('ABSPATH') || !class_exists('RankMath\\Sitemap\\Providers\\Taxonomy')) {
    throw new RuntimeException('Run through WP-CLI with Rank Math and Paint Shop UX active.');
}
$provider = new \RankMath\Sitemap\Providers\Taxonomy();
$size = (int) \RankMath\Helper::get_settings('sitemap.items_per_page', 100);
$method = new ReflectionMethod($provider, 'get_terms');
$method->setAccessible(true);
$all = $method->invoke($provider, get_taxonomy('product_cat'), 0, 1);
$expected = (int) ceil(count($all) / $size);
$links = array_values(array_filter($provider->get_index_links($size), static function ($entry) {
    return strpos($entry['loc'], '/product_cat-sitemap') !== false;
}));
if (count($links) !== $expected) throw new RuntimeException('Index page count differs from native term selection.');
$old_page = get_query_var('sitemap_n');
try {
    foreach ($links as $i => $entry) {
        set_query_var('sitemap_n', $expected > 1 ? $i + 1 : 0);
        $urls = $provider->get_sitemap_links('product_cat', $size, $i + 1);
        if (!$urls) throw new RuntimeException('Index advertises an empty page: ' . $entry['loc']);
        echo 'Sitemap page ' . ($i + 1) . ': ' . count($urls) . " URLs\n";
    }
    if ($method->invoke($provider, get_taxonomy('product_cat'), $size, $expected + 1)) {
        throw new RuntimeException('Nonempty page missing from index.');
    }
} finally {
    set_query_var('sitemap_n', $old_page);
}
echo "Category sitemap integration: OK\n";
