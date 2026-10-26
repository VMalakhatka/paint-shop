<?php
/**
 * Read-only integration for the independent header. Run each scenario in a fresh
 * WP-CLI request: guest, customer, administrator, wholesale[:role], rollback, fallback, empty-menu.
 * Existing users/menus/locations are read; no fixture records or settings persist.
 */
if (!defined('WP_CLI') || !WP_CLI || wp_get_environment_type() !== 'local'
    || parse_url(home_url(), PHP_URL_HOST) !== 'paint.local') throw new RuntimeException('Local only');

$requested_scenario = $args[0] ?? 'guest';
$wholesale_role = str_starts_with($requested_scenario, 'wholesale:') ? substr($requested_scenario, 10) : '';
$scenario = $wholesale_role !== '' ? 'wholesale' : $requested_scenario;
if (!in_array($scenario, ['guest', 'customer', 'administrator', 'wholesale', 'rollback', 'fallback', 'empty-menu'], true)
    || ($wholesale_role !== '' && !in_array($wholesale_role, pc_wholesale_customer_roles(), true))) {
    throw new RuntimeException('Unknown header scenario');
}
$checks = 0;
$check = static function ($condition, string $label) use (&$checks): void {
    if (!$condition) throw new RuntimeException($label);
    $checks++;
};
$hooks = ['wp_body_open', 'generate_before_header', 'generate_header', 'generate_after_header',
    'generate_after_header_content', 'generate_before_right_sidebar_content', 'generate_before_left_sidebar_content',
    'wp_footer', 'wp_nav_menu_items', 'pc_alloc_header_rendered', 'psu_site_header_enabled', 'theme_mod_nav_menu_locations'];
$saved_hooks = [];
foreach ($hooks as $name) $saved_hooks[$name] = isset($GLOBALS['wp_filter'][$name]) ? clone $GLOBALS['wp_filter'][$name] : null;
$saved = ['user' => get_current_user_id(), 'cookies' => $_COOKIE, 'query' => $GLOBALS['wp_query'],
    'rendered_exists' => array_key_exists('psu_site_header_rendered', $GLOBALS),
    'rendered' => $GLOBALS['psu_site_header_rendered'] ?? null];
$disabled = static fn(): bool => false;
$empty_menu = static fn(): array => [];
$parse = static function (string $html): DOMXPath {
    $previous = libxml_use_internal_errors(true);
    try {
        $document = new DOMDocument();
        $document->loadHTML('<?xml encoding="UTF-8">' . $html);
        return new DOMXPath($document);
    } finally { libxml_clear_errors(); libxml_use_internal_errors($previous); }
};
$links = static function (DOMXPath $xpath, string $selector): array {
    $result = [];
    foreach ($xpath->query($selector) as $link) $result[] = $link->getAttribute('href');
    return $result;
};

try {
    // Render a real shop context without navigating or changing stored options.
    $GLOBALS['wp_query'] = new WP_Query();
    $GLOBALS['wp_query']->is_post_type_archive = true;
    $GLOBALS['wp_query']->set('post_type', 'product');
    wp_set_current_user(0);
    if (in_array($scenario, ['customer', 'administrator', 'wholesale'], true)) {
        $roles = $scenario === 'wholesale' ? ($wholesale_role !== '' ? [$wholesale_role] : pc_wholesale_customer_roles()) : [$scenario];
        $users = get_users(['role__in' => $roles, 'role__not_in' => $scenario === 'administrator' ? pc_wholesale_customer_roles() : [], 'number' => 1, 'fields' => 'ID']);
        $check((bool) $users, 'Existing user available: ' . $scenario);
        wp_set_current_user((int) $users[0]);
    }
    if ($scenario === 'administrator') {
        $check(current_user_can('manage_options') && !pc_wholesale_customer_can_access(), 'Administrator rights alone do not grant wholesale access');
    }
    $pref_before = pc_get_alloc_pref();
    $menu_locations_before = get_theme_mod('nav_menu_locations', []);
    $search_filter_before = has_filter('wp_nav_menu_items', 'generate_menu_search_icon');
    $check(function_exists('psu_site_header_enabled') && function_exists('psu_site_header_menu'), 'Header module is loaded');

    if ($scenario === 'rollback') {
        $before = [];
        foreach (['generate_construct_header', 'lavka_topbar', 'generate_top_bar', 'generate_do_skip_to_content_link'] as $callback) {
            $before[$callback] = has_action($callback === 'generate_construct_header' ? 'generate_header' : 'generate_before_header', $callback);
        }
        add_filter('psu_site_header_enabled', $disabled);
        $check(!psu_site_header_enabled(), 'Rollback filter disables the header');
        psu_site_header_setup();
        foreach ($before as $callback => $priority) {
            $hook = $callback === 'generate_construct_header' ? 'generate_header' : 'generate_before_header';
            $check(has_action($hook, $callback) === $priority, 'Rollback preserves inherited callback: ' . $callback);
        }
        ob_start(); psu_render_site_header(); $html = ob_get_clean();
        $check($html === '', 'Disabled header does not render');
        $check(!apply_filters('pc_alloc_header_rendered', false), 'Rollback keeps legacy warehouse mounting available');
    } else {
        if ($scenario === 'empty-menu') add_filter('theme_mod_nav_menu_locations', $empty_menu);
        $native = wp_nav_menu(['theme_location' => 'primary', 'container' => false, 'echo' => false, 'fallback_cb' => false]);
        $expected_links = $native ? $links($parse($native), '//a[@href]') : [];
        $check(psu_site_header_enabled(), 'Own header enabled on the storefront');
        $check(!apply_filters('pc_alloc_header_rendered', false), 'Before output the legacy allocation fallback remains available');
        psu_site_header_setup();
        $check(has_action('wp_body_open', 'psu_render_site_header') === 5, 'Header uses the standard WordPress body hook');
        foreach (['generate_construct_header' => 'generate_header', 'lavka_topbar' => 'generate_before_header',
            'generate_top_bar' => 'generate_before_header', 'generate_do_skip_to_content_link' => 'generate_before_header',
            'generate_add_navigation_after_header' => 'generate_after_header',
            'generate_add_navigation_before_header' => 'generate_before_header',
            'generate_add_navigation_float_right' => 'generate_after_header_content',
            'generate_add_navigation_before_right_sidebar' => 'generate_before_right_sidebar_content',
            'generate_add_navigation_before_left_sidebar' => 'generate_before_left_sidebar_content',
            'generate_clone_sidebar_navigation' => 'wp_footer'] as $callback => $hook) {
            $check(has_action($hook, $callback) === false, 'Inherited header/navigation placement removed: ' . $callback);
        }
        ob_start();
        // The direct call is the adapter for a future minimal theme; standard WP
        // placement is used today. Both paths must remain safe when called twice.
        if ($scenario === 'fallback') psu_render_site_header(); else do_action('wp_body_open');
        psu_render_site_header();
        $html = ob_get_clean();
        $xpath = $parse($html);
        $toolbars = $xpath->query('//header//section[contains(@class,"pcoe-catalog-toolbar")]')->length;
        $check($toolbars === (is_user_logged_in() ? 1 : 0), 'Existing shopping-mode visibility preserved inside the header');
        ob_start();
        do_action('pcoe_catalog_toolbar');
        $late_toolbar = ob_get_clean();
        $check($late_toolbar === '', 'Legacy catalogue placement does not duplicate the toolbar');
        $check($xpath->query('//header[contains(concat(" ", normalize-space(@class), " "), " psu-site-header ")]')->length === 1, 'Exactly one header across repeated placements');
        $check($xpath->query('//*[@id="psu-primary-menu"]')->length === 1, 'Exactly one primary navigation list');
        $check($xpath->query('//nav[contains(concat(" ", normalize-space(@class), " "), " psu-site-header__nav ")]')->length === 1, 'One independent navigation landmark');
        $check($xpath->query('//*[@id="pc-slu-location"]')->length === 1, 'Warehouse control renders once in the header');
        $check($xpath->query('//*[@id="pc-slu-mode"]')->length === 1, 'Allocation mode renders once');
        $check($xpath->query('//*[@id="pc-alloc-mount"]')->length === 0, 'Visible header needs no hidden warehouse mount');
        $check(apply_filters('pc_alloc_header_rendered', false) === true, 'Paint Core can skip the legacy footer mount after header output');
        $check($xpath->query('//a[@href="tel:+380445932605"]')->length === 1, 'Kyiv phone preserved as a callable link');
        $check($xpath->query('//a[@href="tel:+380638571768"]')->length === 1, 'Odesa phone preserved as a callable link');
        $check($xpath->query('//a[@href="tel:+380503480138"]')->length === 1, 'Wholesale phone preserved as a callable link');
        $check($xpath->query('//a[@href="' . esc_url(home_url('/')) . '"]//*[local-name()="svg"]')->length === 1, 'Existing SVG logo links home');
        $actual_links = $links($xpath, '//*[@id="psu-primary-menu"]//a[@href]');
        foreach ($xpath->query('//*[@id="psu-primary-menu"]//a[@href="' . esc_url(wc_get_page_permalink('cart')) . '"]') as $cart_link) {
            $check(trim($cart_link->textContent) === __('Cart', 'paint-shop-ux') && $cart_link->getElementsByTagName('svg')->length === 1, 'Configured cart link uses a translated short label and decorative cart icon');
        }
        $check(!has_filter('nav_menu_item_title', 'psu_site_header_menu_title'), 'Cart label filter does not leak to other menus');
        $cart_item = (object) ['object' => 'page', 'object_id' => wc_get_page_id('cart')];
        $check(psu_site_header_menu_title('Original cart title', $cart_item, (object) ['menu_id' => 'footer']) === 'Original cart title', 'Other menu titles are preserved');
        $check(has_filter('wp_nav_menu_items', 'generate_menu_search_icon') === $search_filter_before, 'Theme search filter restored after rendering the own menu');
        if ($expected_links) {
            $check($actual_links === $expected_links, 'Saved primary links, order and role filters preserved');
        } else {
            $fallback_links = array_map('esc_url', [wc_get_page_permalink('shop'), wc_get_page_permalink('cart'), wc_get_page_permalink('myaccount')]);
            $check($actual_links === $fallback_links, 'Missing menu exposes only the finite shop/cart/account fallback');
        }
        $multiple = pc_wholesale_customer_can_access();
        $check(($xpath->query('//select[@id="pc-slu-mode"]')->length === 1) === $multiple, 'Allocation strategies follow the existing wholesale gate');
        $check(($xpath->query('//input[@id="pc-slu-mode" and @type="hidden" and @value="single"]')->length === 1) === !$multiple, 'Retail keeps the single-store policy');
        $check(($xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " pc-alloc-warning ")]')->length === 1) === $multiple, 'Split-delivery notice is shown only to wholesale customers');
        $quick_path = wp_parse_url(pc_wholesale_help_quick_order_url(), PHP_URL_PATH);
        if (!$multiple) {
            $check(!array_filter($actual_links, static fn($url): bool => wp_parse_url($url, PHP_URL_PATH) === $quick_path), 'Restricted quick-order link is absent for retail and guests');
        }
        $check(pc_get_alloc_pref() === $pref_before && $_COOKIE === $saved['cookies'], 'Header rendering leaves warehouse preference and cookies unchanged');
    }
    remove_filter('theme_mod_nav_menu_locations', $empty_menu);
    $check(get_theme_mod('nav_menu_locations', []) === $menu_locations_before, 'Saved menu configuration is unchanged');
    foreach (['uk', 'ru_RU'] as $locale) {
        $switched = switch_to_locale($locale);
        try {
            foreach (['Cart', 'Skip to content', 'Kyiv, Olimpiiskyi shopping centre', 'Odesa, 17 Lanzheronivska St.',
                'Wholesale sales', 'Menu', 'Main navigation', 'Submenu: %s'] as $label) {
                $translated = __($label, 'paint-shop-ux');
                $check($translated !== $label && preg_match('/[А-Яа-яІіЇїЄєҐґ]/u', $translated)
                    && (!str_contains($label, '%s') || substr_count($translated, '%s') === 1), 'Header label translated with placeholders preserved: ' . $locale . '/' . $label);
            }
        } finally { if ($switched) restore_previous_locale(); }
    }
    echo wp_json_encode(['scenario' => $requested_scenario, 'passed' => $checks]), "\n";
} finally {
    foreach ($saved_hooks as $name => $hook) {
        if ($hook === null) unset($GLOBALS['wp_filter'][$name]); else $GLOBALS['wp_filter'][$name] = $hook;
    }
    if ($saved['rendered_exists']) $GLOBALS['psu_site_header_rendered'] = $saved['rendered']; else unset($GLOBALS['psu_site_header_rendered']);
    $_COOKIE = $saved['cookies'];
    $GLOBALS['wp_query'] = $saved['query'];
    wp_set_current_user($saved['user']);
}
