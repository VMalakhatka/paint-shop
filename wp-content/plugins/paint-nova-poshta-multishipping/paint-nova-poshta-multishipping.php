<?php
/**
 * Plugin Name: Paint Nova Poshta Multishipping
 * Description: Direct multi-warehouse Nova Poshta integration for WooCommerce orders.
 * Version: 0.1.0
 * Author: Paint / Lavka
 * Text Domain: paint-nova-poshta-multishipping
 * Domain Path: /languages
 * Requires Plugins: woocommerce
 * Requires PHP: 8.1
 * WC requires at least: 9.0
 * WC tested up to: 11.0
 */

defined('ABSPATH') || exit;

define('PNPM_VERSION', '0.1.0');
define('PNPM_FILE', __FILE__);
define('PNPM_DIR', plugin_dir_path(__FILE__));
define('PNPM_URL', plugin_dir_url(__FILE__));

spl_autoload_register(static function (string $class): void {
    $prefix = 'Paint\\NovaPoshta\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $path = PNPM_DIR . 'src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_readable($path)) {
        require_once $path;
    }
});

add_action('before_woocommerce_init', static function (): void {
    if (class_exists(Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            'custom_order_tables',
            PNPM_FILE,
            true
        );
    }
});

register_activation_hook(PNPM_FILE, [Paint\NovaPoshta\Activator::class, 'activate']);

add_action('plugins_loaded', static function (): void {
    load_plugin_textdomain(
        'paint-nova-poshta-multishipping',
        false,
        dirname(plugin_basename(PNPM_FILE)) . '/languages'
    );

    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', static function (): void {
            echo '<div class="notice notice-error"><p>';
            echo esc_html__('Paint Nova Poshta Multishipping requires WooCommerce.', 'paint-nova-poshta-multishipping');
            echo '</p></div>';
        });
        return;
    }

    Paint\NovaPoshta\Plugin::instance()->boot();
});

