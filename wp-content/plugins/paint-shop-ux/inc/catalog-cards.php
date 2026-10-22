<?php
/** Catalogue presentation; product names and stock data remain authoritative. */
if (!defined('ABSPATH')) exit;

function psu_catalog_uses_compact_titles(): bool {
    if (!is_product_category() || is_search()) return false;
    if (function_exists('psu_catalog_search_text') && psu_catalog_search_text() !== '') return false;
    $term = get_queried_object();
    if (!($term instanceof WP_Term) || !$term->parent) return false;

    static $results = [];
    $id = (int) $term->term_id;
    if (isset($results[$id])) return $results[$id];
    $index = PSU_Category_Menu::index();
    $visible = static function ($child) use ($index): bool {
        return isset($index['visible'][$child]) && !isset($index['blocked'][$child]);
    };
    // Only a final subgroup supplies enough context to omit the shared name.
    foreach ($index['children'][$id] ?? [] as $child) {
        if ($visible($child)) return $results[$id] = false;
    }
    return $results[$id] = true;
}

function psu_get_catalog_title(string $title, int $product_id = 0): string {
    if (psu_catalog_uses_compact_titles()) return psu_get_compact_title($title, $product_id);
    return trim(preg_replace('/\s*\|\s*/u', ' ', wp_strip_all_tags($title)));
}

add_filter('body_class', static function (array $classes): array {
    if (is_shop() || is_product_taxonomy() || (is_search() && get_query_var('post_type') === 'product')) {
        $classes[] = 'psu-catalog';
    }
    return $classes;
});

add_action('wp_enqueue_scripts', static function (): void {
    if (is_admin()) return;
    $file = dirname(__DIR__) . '/assets/catalog-cards.css';
    wp_enqueue_style('psu-catalog-cards', plugins_url('assets/catalog-cards.css', dirname(__DIR__) . '/paint-shop-ux.php'), [], (string) filemtime($file));
    if (psu_catalog_uses_compact_titles()) {
        $script = dirname(__DIR__) . '/assets/catalog-context.js';
        wp_enqueue_script('psu-catalog-context', plugins_url('../assets/catalog-context.js', __FILE__), [], (string) filemtime($script), true);
    }
}, 30);

// Woo omits descriptions after page one. A short, textual subgroup description
// supplies the shared part of compact names on every page of the same subgroup.
add_action('woocommerce_archive_description', static function (): void {
    if (!psu_catalog_uses_compact_titles() || (int) get_query_var('paged') < 1 || wc_get_loop_prop('is_shortcode')) return;
    $term = get_queried_object();
    $description = apply_filters('woocommerce_taxonomy_archive_description_raw', $term->description, $term);
    $html = wc_format_content(wp_kses_post($description));
    if (!preg_match('/^\s*<(p|h[2-6])\b[^>]*>.*<\/\1>\s*$/is', $html)) return;
    $tags = new WP_HTML_Tag_Processor($html);
    $blocks = 0;
    while ($tags->next_tag()) {
        $tag = $tags->get_tag();
        if (!in_array($tag, ['P', 'H2', 'H3', 'H4', 'H5', 'H6', 'SPAN', 'STRONG', 'EM', 'B', 'I', 'U', 'S', 'SMALL', 'BR'], true)) return;
        if (in_array($tag, ['P', 'H2', 'H3', 'H4', 'H5', 'H6'], true)) $blocks++;
    }
    if ($blocks !== 1) return;
    echo '<div class="term-description">' . $html . '</div>'; // Already sanitized and formatted by the Woo description pipeline.
}, 11);
