<?php
/** Ordinary page content; the active theme still owns the outer document. */
defined('ABSPATH') || exit;

function psu_public_page_blocks_supported(array $blocks): bool {
    $supported = ['paragraph', 'heading', 'list', 'list-item', 'quote', 'pullquote',
        'image', 'gallery', 'columns', 'column', 'group', 'buttons', 'button', 'table',
        'separator', 'spacer', 'cover', 'media-text', 'embed', 'video', 'audio', 'file',
        'details', 'code', 'preformatted', 'html', 'shortcode', 'freeform'];
    foreach ($blocks as $block) {
        $name = $block['blockName'] ?? null;
        if ($name !== null && !in_array($name, array_map(static fn($type) => 'core/' . $type, $supported), true)) return false;
        if (!psu_public_page_blocks_supported($block['innerBlocks'] ?? [])) return false;
    }
    return true;
}

function psu_public_page_context(): string {
    if (is_admin() || is_feed() || is_embed() || !apply_filters('psu_public_pages_enabled', true)) return '';
    // Other themes must explicitly provide the content-container contract.
    if (get_template() !== 'generatepress' && !current_theme_supports('psu-public-pages')) return '';
    if (function_exists('psu_storefront_context') && psu_storefront_context() !== '') return '';
    if (function_exists('is_woocommerce') && is_woocommerce()) return '';
    // Catalogue search may use an array post_type or an exact-SKU query with s cleared.
    if (get_query_var('_psu_catalog_query') || in_array('product', (array) get_query_var('post_type'), true)) return '';
    if (is_404()) return 'not-found';
    if (is_search()) return 'search';
    if (!is_page() || is_front_page() || is_home()) return '';
    $page = get_queried_object();
    if (!$page instanceof WP_Post || $page->post_type !== 'page') return '';
    $template = get_page_template_slug($page);
    if ($template && $template !== 'default') return '';
    if (get_post_meta($page->ID, '_elementor_edit_mode', true) === 'builder') return '';
    // Leave applications, including temporarily unregistered shortcodes, to their owner.
    preg_match_all('/(?<!\[)\[(?!\[|\/)([a-zA-Z][a-zA-Z0-9_-]*)\b/', $page->post_content, $matches);
    if (array_diff($matches[1], ['caption', 'gallery', 'audio', 'video', 'playlist', 'embed'])) return '';
    if (!psu_public_page_blocks_supported(parse_blocks($page->post_content))) return '';
    return 'page';
}

function psu_public_page_template(string $template): string {
    unset($GLOBALS['psu_public_page_view']);
    $context = psu_public_page_context();
    if ($context === '') return $template;
    // Respect plugin and deliberately selected theme templates. Only replace the
    // default page/search/404 content template, never the theme's header/footer.
    $names = ['page' => ['page.php', 'index.php'], 'search' => ['search.php', 'index.php'], 'not-found' => ['404.php', 'index.php']];
    $roots = [wp_normalize_path(get_template_directory()), wp_normalize_path(get_stylesheet_directory())];
    if (!in_array(wp_normalize_path(dirname($template)), $roots, true)
        || !in_array(basename($template), $names[$context], true)) return $template;
    if ($context === 'page') {
        // The content template owns the featured image, including its password guard.
        remove_action('generate_after_header', 'generate_featured_page_header', 10);
    }
    $GLOBALS['psu_public_page_view'] = $context;
    return dirname(__DIR__) . '/templates/public-page.php';
}
add_filter('template_include', 'psu_public_page_template', 99);

add_filter('body_class', static function (array $classes): array {
    if (!empty($GLOBALS['psu_public_page_view'])) {
        $classes[] = 'psu-storefront';
        $classes[] = 'psu-public-page';
        $classes[] = 'psu-public-page--' . $GLOBALS['psu_public_page_view'];
    }
    return $classes;
});

add_filter('generate_sidebar_layout', static function ($layout) {
    return !empty($GLOBALS['psu_public_page_view']) ? 'no-sidebar' : $layout;
}, 120);

add_action('wp_enqueue_scripts', static function (): void {
    if (empty($GLOBALS['psu_public_page_view'])) return;
    foreach (['storefront', 'public-pages'] as $asset) {
        $file = dirname(__DIR__) . '/assets/' . $asset . '.css';
        wp_enqueue_style('psu-' . $asset, plugins_url('../assets/' . $asset . '.css', __FILE__),
            $asset === 'public-pages' ? ['psu-storefront'] : [], (string) filemtime($file));
    }
}, 95);

function psu_public_page_catalog_url(): string {
    return function_exists('wc_get_page_permalink') && wc_get_page_id('shop') > 0
        ? wc_get_page_permalink('shop') : home_url('/');
}

function psu_public_page_search_form(bool $products = false): void {
    $id = wp_unique_id('psu-page-search-');
    ?>
    <form class="psu-page-search" role="search" method="get" action="<?php echo esc_url(home_url('/')); ?>">
        <?php if ($products) : ?><input type="hidden" name="post_type" value="product" /><?php endif; ?>
        <label for="<?php echo esc_attr($id); ?>"><?php esc_html_e('Search', 'paint-shop-ux'); ?></label>
        <div class="psu-page-search__fields">
            <input id="<?php echo esc_attr($id); ?>" type="search" name="s" value="<?php echo esc_attr(get_search_query(false)); ?>" />
            <button type="submit"><?php esc_html_e('Search', 'paint-shop-ux'); ?></button>
        </div>
    </form>
    <?php
}
