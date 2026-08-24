<?php
/**
 * Plugin Name: PC Checkbox Fiscalization
 * Description: Executes explicit, caller-supplied fiscalization commands through the Checkbox.ua API.
 * Version: 0.1.0
 * Author: Paint / Lavka
 * Text Domain: pc-checkbox-fiscalization
 * Domain Path: /languages
 * Requires PHP: 8.1
 */

defined('ABSPATH') || exit;

define('PCCF_VERSION', '0.1.0');
define('PCCF_DB_VERSION', '1');
define('PCCF_FILE', __FILE__);
define('PCCF_DIR', plugin_dir_path(__FILE__));
define('PCCF_URL', plugin_dir_url(__FILE__));

spl_autoload_register(static function (string $class): void {
    $prefix = 'Paint\\CheckboxFiscalization\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $path = PCCF_DIR . 'src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_readable($path)) {
        require_once $path;
    }
});

register_activation_hook(PCCF_FILE, [Paint\CheckboxFiscalization\Activator::class, 'activate']);

add_action('plugins_loaded', static function (): void {
    load_plugin_textdomain(
        'pc-checkbox-fiscalization',
        false,
        dirname(plugin_basename(PCCF_FILE)) . '/languages'
    );

    Paint\CheckboxFiscalization\Plugin::instance()->boot();
});

/**
 * Stable PHP entry point for trusted WordPress callers.
 *
 * @return array<string,mixed>|WP_Error
 */
function pc_checkbox_fiscalize(array $command, string $mode = 'preview')
{
    return Paint\CheckboxFiscalization\Plugin::instance()
        ->service()
        ->execute($command, $mode);
}

