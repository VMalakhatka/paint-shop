<?php
/**
 * Plugin Name: PC WayForPay Checkbox
 * Description: Verifies WayForPay callbacks and queues Checkbox receipts and independent Folio bank income.
 * Version: 0.2.1
 * Requires PHP: 8.1
 * Requires Plugins: woocommerce, pc-checkbox-fiscalization
 * Text Domain: pc-wayforpay-checkbox
 */
defined('ABSPATH') || exit;

spl_autoload_register(static function (string $class): void {
    $prefix = 'Paint\\WayForPayCheckbox\\';
    if (str_starts_with($class, $prefix)) {
        $path = __DIR__ . '/src/' . substr($class, strlen($prefix)) . '.php';
        if (is_readable($path)) {
            require_once $path;
        }
    }
});
register_activation_hook(__FILE__, [Paint\WayForPayCheckbox\Store::class, 'install']);
register_activation_hook(__FILE__, [Paint\WayForPayCheckbox\FolioStore::class, 'install']);
add_action('before_woocommerce_init', static function (): void {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});
add_action('plugins_loaded', static function (): void {
    load_plugin_textdomain('pc-wayforpay-checkbox', false, dirname(plugin_basename(__FILE__)) . '/languages');
    if (class_exists('WooCommerce')) {
        Paint\WayForPayCheckbox\FolioStore::upgrade();
        (new Paint\WayForPayCheckbox\Bridge())->boot();
        (new Paint\WayForPayCheckbox\ReturnPage())->boot();
        (new Paint\WayForPayCheckbox\FolioPayments())->boot();
        (new Paint\WayForPayCheckbox\ReceiptPanel())->boot();
    }
}, 30);
