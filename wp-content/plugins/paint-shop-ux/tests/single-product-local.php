<?php
/** Read-only local product-template and theme-adapter checks. */
if (!defined('WP_CLI') || !WP_CLI || wp_get_environment_type() !== 'local'
    || parse_url(home_url(), PHP_URL_HOST) !== 'paint.local') throw new RuntimeException('Local only');

$checks = 0;
$check = static function ($condition, string $label) use (&$checks): void {
    if (!$condition) throw new RuntimeException($label);
    $checks++;
};
$saved = ['query' => $GLOBALS['wp_query'], 'post' => $GLOBALS['post'] ?? null,
    'product' => $GLOBALS['product'] ?? null, 'user' => get_current_user_id()];
$hook_names = ['woocommerce_before_main_content', 'woocommerce_after_main_content', 'woocommerce_sidebar'];
$hooks = [];
foreach ($hook_names as $name) $hooks[$name] = isset($GLOBALS['wp_filter'][$name]) ? clone $GLOBALS['wp_filter'][$name] : null;
$id = wc_get_product_id_by_sku('KR-79406');
if (!$id) $id = (int) get_posts(['post_type' => 'product', 'post_status' => 'publish', 'numberposts' => 1, 'fields' => 'ids'])[0];
$disabled = static function (): bool { return false; };
try {
    $GLOBALS['wp_query'] = new WP_Query(['p' => $id, 'post_type' => 'product']);
    $GLOBALS['post'] = get_post($id);
    setup_postdata($GLOBALS['post']);
    $product = wc_get_product($id);
    $GLOBALS['product'] = $product;
    $check(psu_single_product_enabled(), 'Main product page uses the new layout');
    $template = apply_filters('wc_get_template_part', '/default.php', 'content', 'single-product');
    $check($template === dirname(__DIR__) . '/templates/content-single-product.php', 'Own product template selected');
    $check(apply_filters('wc_get_template_part', '/loop.php', 'content', 'product') === '/loop.php', 'Catalogue template preserved');
    $check(in_array('psu-product-page', apply_filters('body_class', []), true), 'Scoped body class');
    psu_single_product_setup();
    foreach (['generate_woocommerce_start', 'woocommerce_output_content_wrapper'] as $callback) {
        $check(has_action('woocommerce_before_main_content', $callback) === false, 'Old opening wrapper removed: ' . $callback);
    }
    $check(has_action('woocommerce_after_main_content', 'generate_woocommerce_end') === false, 'Old closing wrapper removed');
    $check(has_action('woocommerce_sidebar', 'generate_construct_sidebars') === false, 'Product sidebar removed');
    $check(has_action('woocommerce_before_main_content', 'woocommerce_breadcrumb') !== false, 'Breadcrumbs preserved');
    $check(has_action('woocommerce_before_main_content', 'psu_single_product_content_start') === 10, 'Own opening wrapper');
    $check(has_action('woocommerce_after_main_content', 'psu_single_product_content_end') === 10, 'Own closing wrapper');
    $check(apply_filters('generate_sidebar_layout', 'right-sidebar') === 'no-sidebar', 'Full-width product content');

    // Exactly-once hooks preserve downstream extensions, schema and notices.
    $calls = [];
    $markers = [];
    foreach (['woocommerce_before_single_product', 'woocommerce_before_single_product_summary',
        'woocommerce_single_product_summary', 'woocommerce_after_single_product_summary', 'woocommerce_after_single_product'] as $name) {
        $markers[$name] = static function () use (&$calls, $name): void { $calls[] = $name; };
        add_action($name, $markers[$name], 999);
    }
    wp_set_current_user(0);
    ob_start(); include $template; $html = ob_get_clean();
    $check($calls === array_keys($markers), 'Woo hooks fire once in their original order');
    $check(str_contains($html, 'psu-product__hero') && str_contains($html, 'psu-product__purchase') && str_contains($html, 'psu-product__details'), 'Gallery, purchase and details rendered');
    $check(str_contains($html, 'product_title'), 'Full product title preserved');
    $check(str_contains($html, $product->get_price_html()), 'Guest price from Woo product getter');
    $check(str_contains($html, 'slu-stock-box'), 'Existing warehouse/allocation panel');
    $check(str_contains($html, 'psu-single-product-search'), 'Existing product search');
    $check(str_contains($html, 'product_meta'), 'SKU and categories retained');
    $check(has_action('woocommerce_single_product_summary', [WC()->structured_data, 'generate_product_data']) !== false, 'Structured-data callback retained');
    $check(str_contains($html, 'woocommerce-loop-product__title'), 'Related products keep their titles');
    $check(str_contains($html, 'psu-loop-sku'), 'Related products keep their SKUs');
    foreach ($markers as $name => $callback) remove_action($name, $callback, 999);

    // No database/profile changes: exercise empty imagery and product-type dispatch in memory.
    $GLOBALS['product'] = clone $product;
    $GLOBALS['product']->set_image_id(0);
    $GLOBALS['product']->set_gallery_image_ids([]);
    ob_start(); include $template; $html = ob_get_clean();
    $check(str_contains($html, 'woocommerce-product-gallery__image--placeholder'), 'Missing image uses Woo placeholder');
    foreach (['variable', 'grouped', 'external'] as $type) {
        $check(has_action('woocommerce_' . $type . '_add_to_cart', 'woocommerce_' . $type . '_add_to_cart') !== false, 'Native product-type purchase handler: ' . $type);
    }
    $GLOBALS['product'] = $product;
    $GLOBALS['post'] = clone $GLOBALS['post'];
    $GLOBALS['post']->post_password = 'local-render-test';
    ob_start(); include $template; $html = ob_get_clean();
    $check(str_contains($html, 'post-password-form') && !str_contains($html, 'psu-product__hero'), 'Password-protected product remains gated');
    $GLOBALS['post']->post_password = '';

    foreach (['uk' => 'Товар додано в кошик.', 'ru_RU' => 'Товар добавлен в корзину.'] as $locale => $translation) {
        $switched = switch_to_locale($locale);
        try {
            $check(__('Product added to cart.', 'paint-shop-ux') === $translation, 'Product feedback translated: ' . $locale);
            $gallery_help = __('Select any thumbnail below the product photo to switch images, or use the magnifying glass to enlarge the current photo. All images assigned to this product remain available; thumbnails fill the gallery width.', 'pc-wholesale-help');
            $check(str_contains($gallery_help, $locale === 'uk' ? 'Натисніть будь-яку мініатюру' : 'Нажмите любую миниатюру'), 'Gallery help translated: ' . $locale);
        } finally { if ($switched) restore_previous_locale(); }
    }

    add_filter('psu_single_product_enabled', $disabled);
    $check(!psu_single_product_enabled(), 'Rollback filter disables the layout');
    $check(apply_filters('wc_get_template_part', '/default.php', 'content', 'single-product') === '/default.php', 'Rollback restores original template');
    $check(!in_array('psu-product-page', apply_filters('body_class', []), true), 'Rollback removes new body class');
    remove_filter('psu_single_product_enabled', $disabled);
    $GLOBALS['wp_query'] = new WP_Query();
    $check(!psu_single_product_enabled(), 'Non-product pages remain unchanged');
    $check(apply_filters('generate_sidebar_layout', 'right-sidebar') === 'right-sidebar', 'Other pages keep their sidebar');
    echo wp_json_encode(['passed' => $checks]), "\n";
} finally {
    remove_filter('psu_single_product_enabled', $disabled);
    foreach ($markers ?? [] as $name => $callback) remove_action($name, $callback, 999);
    foreach ($hooks as $name => $hook) {
        if ($hook === null) unset($GLOBALS['wp_filter'][$name]); else $GLOBALS['wp_filter'][$name] = $hook;
    }
    $GLOBALS['wp_query'] = $saved['query']; $GLOBALS['post'] = $saved['post'];
    $GLOBALS['product'] = $saved['product']; wp_set_current_user($saved['user']);
}
