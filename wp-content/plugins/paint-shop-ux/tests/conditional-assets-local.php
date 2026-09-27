<?php
/** wp eval-file; in-memory hook/query checks only, no settings or order writes. */
if (!defined('WP_CLI') || !WP_CLI || wp_get_environment_type() !== 'local'
    || wp_parse_url(home_url(), PHP_URL_HOST) !== 'paint.local') throw new RuntimeException('Local only');
add_filter('pre_http_request', static fn() => new WP_Error('test_http_blocked', 'Blocked'), PHP_INT_MAX);
function assets_check($condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
    echo "PASS $message\n";
}
function assets_reset(): void {
    $GLOBALS['wp_scripts'] = new WP_Scripts();
    $GLOBALS['wp_styles'] = new WP_Styles();
    $GLOBALS['wp_query'] = new WP_Query();
    $GLOBALS['wp_query']->is_home = false;
    $GLOBALS['wp_query']->is_page = true;
    $GLOBALS['wp_query']->queried_object_id = (int) get_option('page_on_front');
    $GLOBALS['wp_query']->queried_object = get_post($GLOBALS['wp_query']->queried_object_id);
}
function social_register(): void {
    foreach (['xs-front-style', 'xs_login_font_login_css'] as $h) wp_enqueue_style($h, '/test.css');
    foreach (['xs_front_main_js', 'xs_social_custom'] as $h) {
        wp_enqueue_script($h, '/test.js', ['jquery']);
        wp_localize_script($h, 'testSocialNonce', ['nonce'=>'retained']);
    }
}
$no_head = static fn() => false;
$yes_head = static fn() => true;
add_filter('psu_social_assets_required', $no_head);
foreach (['xs_social_share_widget', 'xs_social_counter_widget', 'xs-social-login-container'] as $marker) {
    assets_reset(); social_register();
    PSU_Social_Assets::defer_catalog_assets();
    assets_check(!wp_script_is('xs_social_custom', 'enqueued'), 'Catalog social JS deferred');
    assets_check(!wp_style_is('xs-front-style', 'enqueued'), 'Catalog social CSS deferred');
    assets_check(PSU_Social_Assets::rendered_content('<p>Catalog</p>') === '<p>Catalog</p>', 'Ordinary content unchanged');
    $GLOBALS['wp_actions']['wp_head'] = 1;
    $html = PSU_Social_Assets::rendered_content('<div class="'.$marker.'"></div>');
    assets_check(strpos($html, 'xs-front-style-css') !== false, "$marker gets late CSS");
    assets_check(wp_script_is('xs_social_custom', 'enqueued'), "$marker gets JS");
    assets_check(wp_scripts()->get_data('xs_social_custom', 'group') === 1, 'Late JS goes in footer');
    assets_check(strpos(wp_scripts()->get_data('xs_social_custom', 'data'), 'retained') !== false, 'Localized data retained');
    assets_check(strpos(PSU_Social_Assets::rendered_content('<div class="'.$marker.'"></div>'), '<link') === false, 'Styles printed once');
}
remove_filter('psu_social_assets_required', $no_head);
add_filter('psu_social_assets_required', $yes_head);
assets_reset(); social_register(); PSU_Social_Assets::defer_catalog_assets();
assets_check(wp_script_is('xs_social_custom', 'enqueued'), 'Custom social integration keeps head assets');
remove_filter('psu_social_assets_required', $yes_head);
foreach (['left_content','right_content','top_content','bottom_content'] as $position) {
    $fixed = static fn() => ['login_button_content'=>$position];
    add_filter('pre_option_xs_style_setting_data_share', $fixed);
    assets_reset(); social_register(); PSU_Social_Assets::defer_catalog_assets();
    assets_check(wp_script_is('xs_social_custom', 'enqueued'), "$position keeps fixed social assets");
    remove_filter('pre_option_xs_style_setting_data_share', $fixed);
}
foreach (['login_widget','share_widget','counter_widget'] as $base) {
    $id = $base.'-999';
    $GLOBALS['wp_registered_widgets'][$id] = ['callback'=>'__return_null','id_base'=>$base];
    $sidebar = static fn() => ['asset-test-sidebar'=>[$id]];
    add_filter('sidebars_widgets', $sidebar);
    assets_reset(); social_register(); PSU_Social_Assets::defer_catalog_assets();
    assets_check(wp_script_is('xs_social_custom', 'enqueued'), "$base keeps widget assets");
    remove_filter('sidebars_widgets', $sidebar);
    unset($GLOBALS['wp_registered_widgets'][$id]);
}
assets_reset(); social_register();
$GLOBALS['wp_query']->queried_object_id = wc_get_page_id('myaccount');
$GLOBALS['wp_query']->queried_object = get_post(wc_get_page_id('myaccount'));
PSU_Social_Assets::defer_catalog_assets();
assets_check(wp_script_is('xs_social_custom', 'enqueued'), 'Account social integration unchanged');

assets_reset();
\PaintCore\PCOE\Ui::enqueue_page_assets();
assets_check(!wp_script_is('pcoe-js', 'enqueued'), 'No import script on home');
assets_check(!wp_style_is('pcoe-inline', 'enqueued'), 'No import inline CSS on home');
$GLOBALS['wp_query']->queried_object_id = wc_get_page_id('cart');
$GLOBALS['wp_query']->queried_object = get_post(wc_get_page_id('cart'));
// Woo caches this predicate per request; simulate a new cart request explicitly.
add_filter('woocommerce_is_cart', '__return_true');
\PaintCore\PCOE\Ui::enqueue_page_assets();
remove_filter('woocommerce_is_cart', '__return_true');
assets_check(wp_script_is('pcoe-js', 'enqueued'), 'Cart retains import script');
$css = wp_styles()->get_data('pcoe-inline', 'after');
\PaintCore\PCOE\Ui::enqueue_js();
assets_check(wp_styles()->get_data('pcoe-inline', 'after') === $css, 'Repeated render does not duplicate inline CSS');
assets_reset();
ob_start(); \PaintCore\PCOE\Ui::render_account_import_block(); $rendered = ob_get_clean();
assets_check(wp_script_is('pcoe-js', 'enqueued'), 'Custom import renderer enqueues script');
assets_check(strpos($rendered, 'pcoe-inline-inline-css') !== false, 'Custom late renderer supplies CSS');
assets_check(strpos($rendered, 'pcoe-import-draft-form') !== false, 'Draft form retained');
assets_reset();
ob_start(); \PaintCore\PCOE\Ui::render_order_block(new WC_Order()); $rendered = ob_get_clean();
assets_check(wp_script_is('pcoe-js', 'enqueued') && strpos($rendered, 'pcoe-export') !== false, 'Unsaved order renderer retains export assets');
echo "Conditional asset checks passed\n";
