<?php
/** Read-only integration: the native Woo brand row follows catalogue supplier visibility. */
if (!defined('WP_CLI') || !WP_CLI || wp_get_environment_type() !== 'local'
    || parse_url(home_url(), PHP_URL_HOST) !== 'paint.local') throw new RuntimeException('Local only');

$checks = 0;
$check = static function ($condition, string $label) use (&$checks): void {
    if (!$condition) throw new RuntimeException($label);
    $checks++;
};
$id = wc_get_product_id_by_sku('KR-79406');
$terms = array_slice(array_values(array_filter(psu_catalog_supplier_terms(), static fn($term): bool => $term->count > 0)), 0, 2);
$check($id && !is_wp_error($terms) && count($terms) === 2, 'Existing product and two populated brands available');
$saved = ['query' => $GLOBALS['wp_query'], 'post' => $GLOBALS['post'] ?? null];
$original_ids = wp_get_post_terms($id, 'product_brand', ['fields' => 'ids']);
$settings = [];
$fixture = $terms;
$legacy = static fn(): bool => false;
$options = static function () use (&$settings) { return $settings; };
$assignments = static function ($found, $post_id, $taxonomy) use ($id, &$fixture) {
    return $post_id === $id && $taxonomy === 'product_brand' ? $fixture : $found;
};
$render = static function (): string {
    ob_start();
    do_action('woocommerce_product_meta_end');
    return ob_get_clean();
};
try {
    $GLOBALS['wp_query'] = new WP_Query(['p' => $id, 'post_type' => 'product']);
    $GLOBALS['post'] = get_post($id);
    add_filter('pre_option_psu_catalog_suppliers', $options);
    add_filter('get_the_terms', $assignments, 99, 3);
    $urls = array_map(static fn($term) => esc_url(get_term_link($term)), $terms);
    $baseline = $render();
    $check(str_contains($baseline, $urls[0]) && str_contains($baseline, $urls[1]), 'Unset settings keep both brands visible, like the filter');
    $settings = [$terms[0]->term_id => ['visible' => false]];
    $html = $render();
    $check(!str_contains($html, $urls[0]) && str_contains($html, $urls[1]), 'Mixed assignment renders only the enabled brand');
    $check(str_contains($html, esc_html(get_taxonomy('product_brand')->labels->singular_name) . ': '), 'Single remaining brand uses singular label');
    $check(!in_array($terms[0]->term_id, wp_list_pluck(psu_catalog_supplier_terms(true), 'term_id'), true), 'Same hidden brand is absent from filter choices');
    $settings[$terms[1]->term_id] = ['visible' => false];
    $html = $render();
    $check(!str_contains($html, 'class="posted_in"') && !str_contains($html, $urls[1]), 'All hidden brands remove the whole row');
    add_filter('psu_single_product_enabled', $legacy);
    $check(!psu_single_product_enabled() && $render() === $html, 'Brand visibility also applies with the standard product template');
    remove_filter('psu_single_product_enabled', $legacy);
    $settings = [$terms[0]->term_id => ['visible' => true], $terms[1]->term_id => ['visible' => true]];
    $check($render() === $baseline, 'Re-enabling brands restores native Woo output');
    $fixture = false;
    $check(!str_contains($render(), 'class="posted_in"'), 'Product with no brand has no empty row');
    $fixture = new WP_Error('test', 'Unavailable terms');
    $check(!str_contains($render(), 'class="posted_in"'), 'Unavailable terms do not expose an empty row');
    $settings = [$terms[0]->term_id => ['visible' => false]];
    $GLOBALS['wp_query'] = new WP_Query();
    $check(apply_filters('woocommerce_product_brands_output', 'unchanged', $terms, $id) === 'unchanged', 'Non-product output unchanged');
    foreach (['uk', 'ru_RU'] as $locale) {
        $switched = switch_to_locale($locale);
        try {
            $help = __('Product pages show only brands enabled in the catalogue supplier filter. If none are enabled for a product, the brand row is omitted.', 'pc-wholesale-help');
            $check(str_contains($help, $locale === 'uk' ? 'лише бренди' : 'только бренды'), 'Brand help translated: ' . $locale);
        } finally { if ($switched) restore_previous_locale(); }
    }
} finally {
    remove_filter('psu_single_product_enabled', $legacy);
    remove_filter('pre_option_psu_catalog_suppliers', $options);
    remove_filter('get_the_terms', $assignments, 99);
    $GLOBALS['wp_query'] = $saved['query'];
    $GLOBALS['post'] = $saved['post'];
}
$check(wp_get_post_terms($id, 'product_brand', ['fields' => 'ids']) === $original_ids, 'Stored brand assignments unchanged');
echo wp_json_encode(['passed' => $checks]), "\n";
