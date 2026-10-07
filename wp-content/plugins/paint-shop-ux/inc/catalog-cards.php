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
    // A leaf and a parent containing only leaves are the final two levels.
    foreach ($index['children'][$id] ?? [] as $child) {
        if (!$visible($child)) continue;
        foreach ($index['children'][$child] ?? [] as $grandchild) {
            if ($visible($grandchild)) return $results[$id] = false;
        }
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
}, 30);
