<?php
/** Read-only integration acceptance; launch with lavka-theme-bootstrap-local.php. */
if (!defined('WP_CLI') || !WP_CLI || wp_get_environment_type() !== 'local'
    || parse_url(home_url(), PHP_URL_HOST) !== 'paint.local'
    || !isset($GLOBALS['lavka_theme_test_options_before'])) throw new RuntimeException('Use the local theme bootstrap');

$checks = 0;
$check = static function ($condition, string $label) use (&$checks): void {
    if (!$condition) throw new RuntimeException($label);
    $checks++;
};
$saved_globals = [];
foreach (['wp_query', 'wp_the_query', 'post', 'product', 'psu_public_page_view', 'current_screen', 'wp_registered_widgets'] as $name) {
    $saved_globals[$name] = ['exists' => array_key_exists($name, $GLOBALS), 'value' => $GLOBALS[$name] ?? null];
}
$saved_hooks = [];
foreach (['pre_option_theme_mods_lavka-storefront', 'pre_option_generate_settings', 'sidebars_widgets',
    'woocommerce_is_cart', 'woocommerce_is_checkout', 'get_post_metadata', 'psu_public_pages_enabled',
    'woocommerce_before_main_content', 'woocommerce_after_main_content', 'woocommerce_sidebar',
    'wp_head', 'wp_body_open', 'wp_footer', 'get_header', 'get_footer', 'lavka_storefront_before_main_content'] as $name) {
    $saved_hooks[$name] = isset($GLOBALS['wp_filter'][$name]) ? clone $GLOBALS['wp_filter'][$name] : null;
}
$woo_page_route = static function ($value): bool { return is_page(wc_get_page_id('cart')); };
$checkout_route = static function ($value): bool { return is_page(wc_get_page_id('checkout')); };
add_filter('woocommerce_is_cart', $woo_page_route);
add_filter('woocommerce_is_checkout', $checkout_route);
$query = static function (string $kind, $object = null, array $vars = []): WP_Query {
    $q = new WP_Query(); $q->query_vars = $vars;
    if ($kind === 'page') { $q->is_page = true; $q->is_singular = true; $q->set('page_id', $object->ID); }
    if ($kind === 'product' || $kind === 'single') { $q->is_single = true; $q->is_singular = true; }
    if ($kind === 'category') { $q->is_tax = true; $q->is_archive = true; }
    if ($kind === 'search') $q->is_search = true;
    if ($kind === 'not-found') $q->is_404 = true;
    if ($kind === 'archive') { $q->is_archive = true; $q->is_post_type_archive = true; }
    if ($object) {
        $q->queried_object = $object; $q->queried_object_id = $object->ID ?? $object->term_id ?? 0;
        if ($object instanceof WP_Post) { $q->posts = [$object]; $q->post_count = 1; }
    }
    $GLOBALS['wp_query'] = $q; $GLOBALS['wp_the_query'] = $q;
    $GLOBALS['post'] = $object instanceof WP_Post ? $object : null;
    unset($GLOBALS['psu_public_page_view']);
    return $q;
};
$root = get_template_directory();
$public_template = dirname(__DIR__) . '/templates/public-page.php';

try {
    $check(get_template() === 'lavka-storefront' && get_stylesheet() === 'lavka-storefront', 'Independent theme selected only in this process');
    $check(!wp_get_theme()->parent(), 'Theme has no parent');
    $check(!function_exists('generate_get_layout') && !function_exists('generate_woocommerce_start'), 'GeneratePress functions were not loaded');
    foreach (['title-tag', 'post-thumbnails', 'html5', 'custom-logo', 'align-wide', 'responsive-embeds',
        'woocommerce', 'wc-product-gallery-zoom', 'wc-product-gallery-lightbox', 'wc-product-gallery-slider', 'psu-public-pages'] as $support) {
        $check(current_theme_supports($support), 'Independent theme supplies support: ' . $support);
    }
    $check(isset(get_registered_nav_menus()['primary']), 'Primary menu location retained');
    foreach (['sidebar-1', 'sidebar-2', 'header', 'footer-1', 'footer-2', 'footer-3', 'footer-4', 'footer-5', 'footer-bar', 'top-bar'] as $sidebar) {
        $check(isset($GLOBALS['wp_registered_sidebars'][$sidebar]), 'Existing widget area remains registered: ' . $sidebar);
    }
    $legacy = get_option('theme_mods_generatepress-child', []);
    $own = get_option('theme_mods_lavka-storefront', []);
    if (!array_key_exists('nav_menu_locations', $own)) {
        $check((get_nav_menu_locations()['primary'] ?? 0) === ($legacy['nav_menu_locations']['primary'] ?? 0), 'Existing primary menu is visible without copying options');
    }
    $override_mods = ['nav_menu_locations' => []];
    $mods_filter = static function () use (&$override_mods): array { return $override_mods; };
    add_filter('pre_option_theme_mods_lavka-storefront', $mods_filter, 20);
    $check(get_nav_menu_locations() === [], 'An explicitly empty menu assignment is not replaced with legacy settings');
    $override_mods['nav_menu_locations'] = $legacy['nav_menu_locations'] ?? [];
    $check(get_nav_menu_locations() === $override_mods['nav_menu_locations'], 'Explicit own menu assignments take precedence');
    remove_filter('pre_option_theme_mods_lavka-storefront', $mods_filter, 20);

    $legal_ids = get_option(PC_WAYFORPAY_PAGES_OPTION, []);
    foreach ($legal_ids as $key => $id) {
        $page = get_post((int) $id); $check($page instanceof WP_Post, 'Managed legal page available: ' . $key);
        $stored = $page->post_content; $query('page', clone $page);
        $check(psu_public_page_context() === 'page', 'Ordinary content scope works with the new theme: ' . $key);
        $check(psu_public_page_template($root . '/page.php') === $public_template, 'Ordinary content template retained: ' . $key);
        $check(get_post_field('post_content', $page->ID, 'raw') === $stored, 'Legal page data is unchanged: ' . $key);
        $check(lavka_storefront_sidebar_layout() === 'no-sidebar', 'Legal page has no catalogue sidebar: ' . $key);
    }
    $reference = get_post((int) reset($legal_ids));
    $page = clone $reference; $page->post_content = '<p>Plain independent content.</p>';
    $query('page', $page);
    foreach ([WP_PLUGIN_DIR . '/other-owner/page.php', $root . '/custom-page.php'] as $incoming) {
        $check(psu_public_page_template($incoming) === $incoming, 'Foreign/custom page template remains owned by its provider');
    }
    $disable_public = static fn(): bool => false;
    add_filter('psu_public_pages_enabled', $disable_public);
    $check(psu_public_page_template($root . '/page.php') === $root . '/page.php', 'Theme fallback works when the ordinary-page module is disabled');
    remove_filter('psu_public_pages_enabled', $disable_public);
    foreach (['cart' => 'cart', 'checkout' => 'checkout', 'myaccount' => 'account'] as $name => $context) {
        $page = get_post(wc_get_page_id($name)); $check($page instanceof WP_Post, 'Woo option resolves page: ' . $name);
        $query('page', $page);
        $check(psu_storefront_context() === $context, 'Woo task context remains: ' . $name);
        $check(psu_public_page_template($root . '/page.php') === $root . '/page.php', 'Woo task keeps its shortcode template: ' . $name);
        $check(lavka_storefront_sidebar_layout() === 'no-sidebar', 'Woo task has no sidebar: ' . $name);
    }
    $page = clone $reference; $page->post_content = '[pc_quick_order]'; $query('page', $page);
    $check(psu_storefront_context() === 'quick-order' && psu_public_page_context() === '', 'Quick order remains an application page');
    $check(lavka_storefront_sidebar_layout() === 'no-sidebar', 'Quick order retains the full content width');
    foreach (['[evercompare_table]', '<!-- wp:third-party/report /-->'] as $content) {
        $page = clone $reference; $page->post_content = $content; $query('page', $page);
        $check(psu_public_page_template($root . '/page.php') === $root . '/page.php', 'Other application content remains with its owner');
    }
    $elementor_page = clone $reference;
    $meta_filter = static fn($value, $id, $key) => $id === $elementor_page->ID && $key === '_elementor_edit_mode' ? 'builder' : $value;
    add_filter('get_post_metadata', $meta_filter, 99, 3); $query('page', $elementor_page);
    $check(psu_public_page_context() === '', 'Elementor stays outside ordinary-page replacement');
    remove_filter('get_post_metadata', $meta_filter, 99);

    $category = get_term_by('slug', 'number-one', 'product_cat');
    $check($category instanceof WP_Term, 'Existing final catalogue subgroup available');
    $query('category', $category);
    $check(psu_catalog_uses_compact_titles(), 'Compact-title subgroup context survives the theme change');
    $check(psu_get_catalog_title('Shared | Variant | Suffix') === 'Variant', 'Existing compact-name parser remains authoritative');
    $check(psu_storefront_context() === 'catalog' && psu_public_page_context() === '', 'Product category remains a catalogue');
    $check(in_array(lavka_storefront_sidebar_layout(), ['left-sidebar', 'right-sidebar'], true), 'Configured catalogue sidebar remains available');
    $sidebar_filter = static fn(array $areas): array => array_merge($areas, ['sidebar-1' => ['theme-test-widget'], 'sidebar-2' => ['theme-test-widget']]);
    add_filter('sidebars_widgets', $sidebar_filter);
    add_filter('pre_option_theme_mods_lavka-storefront', $mods_filter, 20);
    foreach (['left-sidebar', 'right-sidebar', 'no-sidebar', 'invalid-layout'] as $layout) {
        $override_mods = ['catalog_sidebar' => $layout];
        $expected = in_array($layout, ['left-sidebar', 'right-sidebar'], true) ? $layout : 'no-sidebar';
        $check(lavka_storefront_sidebar_layout() === $expected, 'Explicit catalogue layout respected: ' . $layout);
        $check(in_array($expected, apply_filters('body_class', []), true), 'Body carries its actual sidebar geometry: ' . $layout);
    }
    $override_mods = ['catalog_sidebar' => 'right-sidebar'];
    remove_filter('sidebars_widgets', $sidebar_filter);
    $empty_sidebars = static fn(): array => [];
    add_filter('sidebars_widgets', $empty_sidebars);
    $check(lavka_storefront_sidebar_layout() === 'no-sidebar', 'An empty sidebar does not reserve a blank catalogue column');
    remove_filter('sidebars_widgets', $empty_sidebars);
    remove_filter('pre_option_theme_mods_lavka-storefront', $mods_filter, 20);

    // Exercise the real registered theme callback in the same order as wp,
    // without unrelated application hooks or writes to a visitor session.
    $theme_wp = [];
    foreach ($GLOBALS['wp_filter']['wp']->callbacks as $priority => $callbacks) foreach ($callbacks as $callback) {
        if ($callback['function'] instanceof Closure
            && (new ReflectionFunction($callback['function']))->getFileName() === $root . '/functions.php') {
            $theme_wp[] = $callback['function'];
        }
    }
    $check((bool) $theme_wp, 'Theme Woo wrapper adapter is registered on wp');
    foreach ($theme_wp as $callback) $callback();
    $check(has_action('woocommerce_before_main_content', 'woocommerce_output_content_wrapper') === false
        && has_action('woocommerce_after_main_content', 'woocommerce_output_content_wrapper_end') === false, 'Catalogue removes native Woo wrapper pair');
    $check(has_action('woocommerce_before_main_content', 'lavka_storefront_woocommerce_start') === 10
        && has_action('woocommerce_after_main_content', 'lavka_storefront_woocommerce_end') === 10, 'Catalogue installs one matching own wrapper pair');
    $check(has_action('woocommerce_sidebar', 'woocommerce_get_sidebar') === false
        && has_action('woocommerce_sidebar', 'lavka_storefront_woocommerce_sidebar') === 10, 'Catalogue sidebar has one owner');
    ob_start(); lavka_storefront_woocommerce_start(); echo '<p>Catalogue fixture.</p>'; lavka_storefront_woocommerce_end(); $wrapper_html = ob_get_clean();
    $check(substr_count($wrapper_html, 'id="primary"') === 1 && substr_count($wrapper_html, 'id="main"') === 1
        && substr_count($wrapper_html, 'class="entry-content"') === 1, 'Catalogue shell keeps unique content IDs and the existing content hook container');
    $query('search', null, ['s' => 'paint', 'post_type' => ['product'], '_psu_catalog_query' => true]);
    $check(psu_public_page_context() === '', 'Array product search cannot be replaced by ordinary-page search');
    $check(psu_public_page_template($root . '/search.php') === $root . '/search.php', 'Product search retains the theme fallback when Woo does not replace it');
    // Render the real fallback template with one in-memory widget. Isolate
    // chrome hooks so this checks template geometry without visitor sessions.
    add_filter('sidebars_widgets', $sidebar_filter);
    $override_mods = ['catalog_sidebar' => 'right-sidebar'];
    add_filter('pre_option_theme_mods_lavka-storefront', $mods_filter, 20);
    wp_register_sidebar_widget('theme-test-widget', 'Theme test widget', static function (): void {
        echo '<p data-lavka-theme-fixture="catalogue-widget">Catalogue widget</p>';
    });
    $chrome_hooks = ['wp_head', 'wp_body_open', 'wp_footer', 'get_header', 'get_footer', 'lavka_storefront_before_main_content'];
    foreach ($chrome_hooks as $name) remove_all_actions($name);
    try {
        $check(lavka_storefront_sidebar_layout() === 'right-sidebar', 'Product search fallback reserves the configured catalogue column');
        ob_start(); require $root . '/search.php'; $search_html = ob_get_clean();
        $check(substr_count($search_html, 'id="right-sidebar"') === 1, 'Product search fallback renders exactly one reserved sidebar');
        $check(str_contains($search_html, 'data-lavka-theme-fixture="catalogue-widget"'), 'Product search fallback includes the sidebar widget content');
    } finally {
        foreach ($chrome_hooks as $name) {
            if ($saved_hooks[$name] === null) unset($GLOBALS['wp_filter'][$name]); else $GLOBALS['wp_filter'][$name] = clone $saved_hooks[$name];
        }
        remove_filter('sidebars_widgets', $sidebar_filter);
        remove_filter('pre_option_theme_mods_lavka-storefront', $mods_filter, 20);
    }
    $query('search', null, ['s' => 'article', 'post_type' => 'post']);
    $check(lavka_storefront_sidebar_layout() === 'no-sidebar', 'Ordinary search does not reserve a catalogue sidebar');
    $check(psu_public_page_template($root . '/search.php') === $public_template, 'Ordinary search remains available');
    $query('not-found');
    $check(psu_public_page_template($root . '/404.php') === $public_template, '404 remains available');

    $product_ids = wc_get_products(['status' => 'publish', 'limit' => 1, 'return' => 'ids']);
    $check((bool) $product_ids, 'Existing published product available');
    $product_post = get_post($product_ids[0]); $query('product', $product_post, ['post_type' => 'product']);
    $GLOBALS['product'] = wc_get_product($product_post->ID);
    $check(psu_single_product_enabled(), 'Own product page remains enabled without GeneratePress');
    $check(lavka_storefront_sidebar_layout() === 'no-sidebar', 'Product page remains full width');
    $check(apply_filters('wc_get_template_part', 'foreign.php', 'content', 'single-product') === dirname(__DIR__) . '/templates/content-single-product.php', 'PDP content continues to be owned by Paint Shop UX');
    foreach (['woocommerce_before_main_content', 'woocommerce_after_main_content', 'woocommerce_sidebar'] as $name) {
        if ($saved_hooks[$name] === null) unset($GLOBALS['wp_filter'][$name]); else $GLOBALS['wp_filter'][$name] = clone $saved_hooks[$name];
    }
    psu_single_product_setup();
    foreach ($theme_wp as $callback) $callback();
    $check(has_action('woocommerce_before_main_content', 'psu_single_product_content_start') === 10
        && has_action('woocommerce_after_main_content', 'psu_single_product_content_end') === 10, 'PDP keeps the existing PSU wrapper pair');
    $check(has_action('woocommerce_before_main_content', 'lavka_storefront_woocommerce_start') === false
        && has_action('woocommerce_after_main_content', 'lavka_storefront_woocommerce_end') === false, 'Theme does not nest a second wrapper around PSU product content');
    $query('archive', null, ['post_type' => 'lavka_workshop']);
    $workshops = apply_filters('template_include', $root . '/index.php');
    $check($workshops === WP_PLUGIN_DIR . '/lavka-workshops/templates/page.php', 'Workshop archive retains its plugin template');
    $check(lavka_storefront_sidebar_layout() === 'no-sidebar', 'Workshop remains outside catalogue sidebars');

    $check(($GLOBALS['lavka_theme_test_snapshot'])() === $GLOBALS['lavka_theme_test_options_before'], 'Stored active theme, menu, widget and Woo-page options are unchanged');
    echo wp_json_encode(['passed' => $checks, 'theme' => get_stylesheet(), 'stored_options_unchanged' => true]), "\n";
} finally {
    foreach ($saved_hooks as $name => $hook) {
        if ($hook === null) unset($GLOBALS['wp_filter'][$name]); else $GLOBALS['wp_filter'][$name] = $hook;
    }
    foreach ($saved_globals as $name => $state) {
        if ($state['exists']) $GLOBALS[$name] = $state['value']; else unset($GLOBALS[$name]);
    }
}
