<?php
/** Read-only scope/template checks for ordinary pages; no fixtures are persisted. */
if (!defined('WP_CLI') || !WP_CLI || wp_get_environment_type() !== 'local'
    || parse_url(home_url(), PHP_URL_HOST) !== 'paint.local') throw new RuntimeException('Local only');

$checks = 0;
$check = static function ($condition, string $label) use (&$checks): void {
    if (!$condition) throw new RuntimeException($label);
    $checks++;
};
$global_names = ['wp_query', 'wp_the_query', 'post', 'psu_public_page_view', 'current_screen'];
$saved_globals = [];
foreach ($global_names as $name) $saved_globals[$name] = ['exists' => array_key_exists($name, $GLOBALS), 'value' => $GLOBALS[$name] ?? null];
$hook_names = ['woocommerce_is_cart', 'woocommerce_is_checkout', 'get_post_metadata', 'template', 'psu_public_pages_enabled', 'generate_after_header'];
$saved_hooks = [];
foreach ($hook_names as $name) $saved_hooks[$name] = isset($GLOBALS['wp_filter'][$name]) ? clone $GLOBALS['wp_filter'][$name] : null;
$cart_route = static fn(): bool => is_page(wc_get_page_id('cart'));
$checkout_route = static fn(): bool => is_page(wc_get_page_id('checkout'));
add_filter('woocommerce_is_cart', $cart_route);
add_filter('woocommerce_is_checkout', $checkout_route);
$disabled = static fn(): bool => false;
$other_theme = static fn(): string => 'psu-test-other-theme';
$meta = [];
$meta_override = static function ($value, $object_id, $key, $single) use (&$meta) {
    return isset($meta[$object_id]) && array_key_exists($key, $meta[$object_id]) ? $meta[$object_id][$key] : $value;
};
add_filter('get_post_metadata', $meta_override, 99, 4);
$theme_root = get_template_directory();
$child_root = get_stylesheet_directory();
$own_template = dirname(__DIR__) . '/templates/public-page.php';

try {
    $check(function_exists('psu_public_page_context') && function_exists('psu_public_page_template'), 'Ordinary-page module is loaded');
    $check(has_filter('template_include', 'psu_public_page_template') === 99, 'Template boundary registered after other owners');
    $legal_ids = get_option(PC_WAYFORPAY_PAGES_OPTION, []);
    $legal_id = (int) ($legal_ids['terms'] ?? 0);
    $reference = get_post($legal_id);
    $check($reference instanceof WP_Post && $reference->post_type === 'page', 'Existing legal page available from its owning provider');
    $stored_content = get_post_field('post_content', $legal_id, 'raw');
    $meta[$legal_id] = ['_wp_page_template' => 'default', '_elementor_edit_mode' => '', '_elementor_data' => ''];

    $query = static function (string $kind, ?WP_Post $post = null, array $vars = []): WP_Query {
        $q = new WP_Query();
        $q->query_vars = $vars;
        if ($kind === 'page') { $q->is_page = true; $q->is_singular = true; $q->set('page_id', $post->ID); }
        if ($kind === 'search') $q->is_search = true;
        if ($kind === 'not-found') $q->is_404 = true;
        if ($kind === 'single') { $q->is_single = true; $q->is_singular = true; }
        if ($kind === 'archive') { $q->is_archive = true; $q->is_post_type_archive = true; }
        if ($post) {
            $q->queried_object = $post;
            $q->queried_object_id = $post->ID;
            $q->posts = [$post];
            $q->post_count = 1;
        }
        $GLOBALS['wp_query'] = $q;
        $GLOBALS['wp_the_query'] = $q;
        $GLOBALS['post'] = $post;
        unset($GLOBALS['psu_public_page_view']);
        return $q;
    };
    $page = static function (string $content) use ($reference): WP_Post {
        $post = clone $reference;
        $post->post_content = $content;
        return $post;
    };
    $assert_excluded = static function (string $label, string $incoming) use ($check): void {
        $check(psu_public_page_context() === '', 'Context excluded: ' . $label);
        $check(psu_public_page_template($incoming) === $incoming, 'Template preserved: ' . $label);
        $check(!in_array('psu-public-page', apply_filters('body_class', []), true), 'No ordinary-page body scope: ' . $label);
    };

    foreach ($legal_ids as $key => $id) {
        $post = get_post((int) $id);
        $check($post instanceof WP_Post, 'Managed page exists: ' . $key);
        $before = $post->post_content;
        $query('page', clone $post);
        $check(psu_public_page_context() === 'page', 'Managed page uses ordinary layout: ' . $key);
        $check(psu_public_page_template($theme_root . '/page.php') === $own_template, 'Managed page gets own template: ' . $key);
        $check($GLOBALS['post']->post_content === $before && get_post_field('post_content', $post->ID, 'raw') === $before, 'Managed page content unchanged: ' . $key);
    }

    $plain = '<h2>Ordinary page</h2><p>Content and <a href="/example/">links</a> remain intact.</p>';
    foreach ([$theme_root . '/page.php', $theme_root . '/index.php', $child_root . '/page.php'] as $incoming) {
        $query('page', $page($plain));
        $check(psu_public_page_context() === 'page', 'Plain page classified without relying on a fixed ID');
        $check(psu_public_page_template($incoming) === $own_template, 'Default theme page/fallback replaced: ' . basename($incoming));
        $classes = apply_filters('body_class', []);
        $check(in_array('psu-public-page', $classes, true) && in_array('psu-storefront', $classes, true), 'Selected ordinary template receives its own shared style scope');
        $check($GLOBALS['post']->post_content === $plain, 'Classification and template choice leave HTML untouched');
    }

    // Each route starts with GP's normal featured-image callback attached.
    // Only an accepted ordinary page owns that image; other routes keep GP's hook.
    $foreign_header = static function (): void {};
    $later_header = static function (): void {};
    add_action('generate_after_header', $foreign_header, 10);
    add_action('generate_after_header', $later_header, 17);
    foreach (['page', 'plugin-template', 'excluded-content', 'rollback', 'search', 'not-found'] as $route) {
        add_action('generate_after_header', 'generate_featured_page_header', 10);
        $kind = in_array($route, ['search', 'not-found'], true) ? $route : 'page';
        $query($kind, $kind === 'page' ? $page($route === 'excluded-content' ? '[not_a_registered_shortcode]' : $plain) : null,
            $kind === 'search' ? ['s' => 'ordinary', 'post_type' => 'post'] : []);
        $incoming = $route === 'plugin-template' ? WP_PLUGIN_DIR . '/other-owner/page.php'
            : $theme_root . '/' . ($kind === 'not-found' ? '404' : $kind) . '.php';
        if ($route === 'rollback') add_filter('psu_public_pages_enabled', $disabled);
        try {
            $chosen = psu_public_page_template($incoming);
        } finally {
            if ($route === 'rollback') remove_filter('psu_public_pages_enabled', $disabled);
        }
        $accepted = in_array($route, ['page', 'search', 'not-found'], true);
        $check($chosen === ($accepted ? $own_template : $incoming), 'Featured-image hook check uses the intended template boundary: ' . $route);
        $check(has_action('generate_after_header', 'generate_featured_page_header') === ($route === 'page' ? false : 10),
            'GP featured image is removed only for an accepted ordinary page: ' . $route);
        $check(has_action('generate_after_header', $foreign_header) === 10 && has_action('generate_after_header', $later_header) === 17,
            'Unrelated after-header callbacks and their priorities remain intact: ' . $route);
    }
    remove_action('generate_after_header', $foreign_header, 10);
    remove_action('generate_after_header', $later_header, 17);

    foreach (['[lavka_reports]', '[pc_quick_order]', '[woocommerce_cart]', '[not_a_registered_shortcode]'] as $content) {
        $query('page', $page($content));
        $assert_excluded('application/unknown shortcode ' . $content, $theme_root . '/page.php');
    }
    foreach (['<!-- wp:woocommerce/cart /-->', '<!-- wp:third-party/report /-->',
        '<!-- wp:group --><div class="wp-block-group"><!-- wp:third-party/report /--></div><!-- /wp:group -->'] as $content) {
        $query('page', $page($content));
        $assert_excluded('plugin block, including a nested block', $theme_root . '/page.php');
    }
    foreach (['<!-- wp:paragraph --><p>Ordinary block text.</p><!-- /wp:paragraph -->', '[gallery]', '[caption]Caption[/caption]', 'Literal [[unknown_shortcode]] text'] as $content) {
        $query('page', $page($content));
        $check(psu_public_page_context() === 'page', 'Core text/media and escaped shortcode text remain ordinary content');
    }
    $query('page', $page('<!-- wp:query /-->'));
    $assert_excluded('dynamic core query block keeps its existing owner', $theme_root . '/page.php');

    foreach (['cart', 'checkout', 'myaccount', 'shop'] as $name) {
        $woo_page = get_post(wc_get_page_id($name));
        $check($woo_page instanceof WP_Post, 'Configured Woo page available: ' . $name);
        $query('page', $woo_page);
        $assert_excluded('Woo ' . $name, $theme_root . '/page.php');
    }
    $query('page', $page($plain));
    $meta[$legal_id]['_wp_page_template'] = 'custom-page.php';
    $assert_excluded('custom page template metadata', $theme_root . '/page.php');
    $meta[$legal_id]['_wp_page_template'] = 'default';
    $meta[$legal_id]['_elementor_edit_mode'] = 'builder';
    $assert_excluded('Elementor owns the page', $theme_root . '/page.php');
    $meta[$legal_id]['_elementor_edit_mode'] = '';

    foreach ([WP_PLUGIN_DIR . '/other-owner/page.php', $theme_root . '/custom/page.php',
        $theme_root . '/single.php', $theme_root . '/content-page.php'] as $incoming) {
        $query('page', $page($plain));
        $check(psu_public_page_template($incoming) === $incoming, 'Custom/plugin template preserved: ' . $incoming);
        $check(!in_array('psu-public-page', apply_filters('body_class', []), true), 'A preserved template receives no ordinary-page style scope');
    }

    foreach (['product', ['product'], ['post', 'product']] as $post_type) {
        $query('search', null, ['s' => 'paint', 'post_type' => $post_type]);
        $assert_excluded('product search string/array', $theme_root . '/search.php');
    }
    $query('search', null, ['s' => 'paint', 'post_type' => 'post', '_psu_catalog_query' => true]);
    $assert_excluded('catalogue query marker', $theme_root . '/search.php');
    foreach (['post', 'page'] as $post_type) {
        $q = $query('search', null, ['s' => 'needle', 'post_type' => $post_type, 'paged' => 2]);
        $before = $q->query_vars;
        $check(psu_public_page_context() === 'search', 'Ordinary search classified: ' . $post_type);
        $check(psu_public_page_template($theme_root . '/search.php') === $own_template, 'Ordinary search gets own template');
        $check($q->query_vars === $before && $q->is_search, 'Search routing, phrase and pagination unchanged');
    }
    // Test the search form independently; it must escape the phrase and keep
    // standard GET routing rather than introducing a separate search endpoint.
    $phrase = '<img src=x onerror=alert(1)> & "quoted"';
    $query('search', null, ['s' => $phrase, 'post_type' => 'post']);
    ob_start(); psu_public_page_search_form(); psu_public_page_search_form(); $forms = ob_get_clean();
    $previous_errors = libxml_use_internal_errors(true);
    try {
        $document = new DOMDocument(); $document->loadHTML('<?xml encoding="UTF-8">' . $forms);
        $xpath = new DOMXPath($document);
        $inputs = $xpath->query('//input[@type="search" and @name="s"]');
        $check($inputs->length === 2 && $inputs->item(0)->getAttribute('id') !== $inputs->item(1)->getAttribute('id'), 'Repeated search forms have unique search field IDs');
        foreach ($inputs as $input) {
            $check($input->getAttribute('value') === $phrase, 'Search input retains the original phrase through attribute escaping');
            $check($xpath->query('//label[@for="' . $input->getAttribute('id') . '"]')->length === 1, 'Search input has an explicit matching label');
        }
        $check($xpath->query('//img|//script')->length === 0, 'Search text cannot inject markup');
        $check($xpath->query('//form[@method="get" and @action="' . esc_url(home_url('/')) . '"]')->length === 2, 'Search forms preserve normal WordPress GET routing');
        $check($xpath->query('//input[@name="post_type"]')->length === 0, 'Default ordinary search forms do not force product routing');

        ob_start(); psu_public_page_search_form(true); $product_form = ob_get_clean();
        $product_document = new DOMDocument(); $product_document->loadHTML('<?xml encoding="UTF-8">' . $product_form);
        $product_xpath = new DOMXPath($product_document);
        $check($product_xpath->query('//input[@name="post_type"]')->length === 1
            && $product_xpath->query('//input[@type="hidden" and @name="post_type" and @value="product"]')->length === 1,
            'Product recovery search explicitly selects the product post type once');
        $check($product_xpath->query('//form[@method="get" and @action="' . esc_url(home_url('/')) . '"]')->length === 1,
            'Product recovery search keeps the normal WordPress GET endpoint');
        $product_inputs = $product_xpath->query('//input[@type="search" and @name="s"]');
        $check($product_inputs->length === 1 && $product_inputs->item(0)->getAttribute('value') === $phrase
            && $product_xpath->query('//img|//script')->length === 0, 'Product recovery search also escapes the original phrase');
        $product_input = $product_inputs->item(0);
        $check($product_xpath->query('//label[@for="' . $product_input->getAttribute('id') . '"]')->length === 1,
            'Product recovery search has a matching explicit label');
        $check($product_input->getAttribute('id') !== $inputs->item(0)->getAttribute('id')
            && $product_input->getAttribute('id') !== $inputs->item(1)->getAttribute('id'),
            'Product recovery search also receives a unique field ID');
    } finally { libxml_clear_errors(); libxml_use_internal_errors($previous_errors); }
    $q = $query('not-found');
    $check(psu_public_page_context() === 'not-found', '404 context classified');
    $check(psu_public_page_template($theme_root . '/404.php') === $own_template, '404 gets own template');
    $check($q->is_404 && !$q->is_page, '404 query remains a 404');

    $post = clone $reference; $post->post_type = 'post';
    $query('single', $post, ['post_type' => 'post']);
    $assert_excluded('ordinary blog article outside page scope', $theme_root . '/single.php');
    $query('archive', null, ['post_type' => 'lavka_workshop']);
    $assert_excluded('workshop archive', WP_PLUGIN_DIR . '/lavka-workshops/templates/page.php');

    foreach (['is_feed', 'is_embed', 'is_home'] as $flag) {
        $q = $query('page', $page($plain));
        $q->$flag = true;
        $assert_excluded('special WordPress route: ' . $flag, $theme_root . '/page.php');
    }
    $query('page', $page($plain));
    add_filter('template', $other_theme);
    $assert_excluded('different parent theme', $theme_root . '/page.php');
    remove_filter('template', $other_theme);
    add_filter('psu_public_pages_enabled', $disabled);
    $assert_excluded('rollback filter', $theme_root . '/page.php');
    remove_filter('psu_public_pages_enabled', $disabled);
    $GLOBALS['current_screen'] = new class { public function in_admin(): bool { return true; } };
    $assert_excluded('WordPress administration', $theme_root . '/page.php');
    if ($saved_globals['current_screen']['exists']) $GLOBALS['current_screen'] = $saved_globals['current_screen']['value']; else unset($GLOBALS['current_screen']);

    $check(get_post_field('post_content', $legal_id, 'raw') === $stored_content, 'Persisted legal content is unchanged after all synthetic routes');
    echo wp_json_encode(['passed' => $checks]), "\n";
} finally {
    foreach ($saved_hooks as $name => $hook) {
        if ($hook === null) unset($GLOBALS['wp_filter'][$name]); else $GLOBALS['wp_filter'][$name] = $hook;
    }
    foreach ($saved_globals as $name => $state) {
        if ($state['exists']) $GLOBALS[$name] = $state['value']; else unset($GLOBALS[$name]);
    }
}
