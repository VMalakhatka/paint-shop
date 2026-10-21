<?php
/**
 * Request-only theme preview for a fresh local WP-CLI process.
 * Usage: wp --require=.../lavka-theme-bootstrap-local.php eval-file .../lavka-theme-local.php
 * Never call switch_theme(): menu/widget/active-theme options must remain unchanged.
 */
if (!defined('WP_CLI') || !WP_CLI) throw new RuntimeException('WP-CLI only');
if (!defined('DISABLE_WP_CRON')) define('DISABLE_WP_CRON', true);
if (!defined('WP_HTTP_BLOCK_EXTERNAL')) define('WP_HTTP_BLOCK_EXTERNAL', true);

$GLOBALS['wp_filter']['muplugins_loaded'][1][] = ['accepted_args' => 0, 'function' => static function (): void {
    if (wp_get_environment_type() !== 'local' || parse_url(get_option('home'), PHP_URL_HOST) !== 'paint.local') {
        throw new RuntimeException('Local paint.local only');
    }
    $GLOBALS['lavka_theme_test_option_names'] = ['template', 'stylesheet', 'theme_mods_generatepress-child',
        'theme_mods_lavka-storefront', 'sidebars_widgets', 'generate_settings', 'woocommerce_shop_page_id',
        'woocommerce_cart_page_id', 'woocommerce_checkout_page_id', 'woocommerce_myaccount_page_id'];
    $GLOBALS['lavka_theme_test_snapshot'] = static function (): array {
        global $wpdb;
        $snapshot = [];
        foreach ($GLOBALS['lavka_theme_test_option_names'] as $name) {
            $raw = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name));
            $snapshot[$name] = $raw === null ? null : hash('sha256', $raw);
        }
        return $snapshot;
    };
    $GLOBALS['lavka_theme_test_options_before'] = ($GLOBALS['lavka_theme_test_snapshot'])();
}];
foreach (['template', 'stylesheet'] as $name) {
    $GLOBALS['wp_filter']['pre_option_' . $name][10][] = [
        'accepted_args' => 0, 'function' => static fn(): string => 'lavka-storefront',
    ];
}
