<?php
/**
 * Plugin Name: PC Order Import/Export
 * Description: Export cart/orders to CSV/XLSX + import to cart/draft. WooCommerce integration.
 * Text Domain: pc-order-import-export
 * Domain Path: /languages
 */

if (!defined('ABSPATH')) exit;

add_action('plugins_loaded', function () {
    // обычная загрузка
    load_plugin_textdomain('pc-order-import-export', false, dirname(plugin_basename(__FILE__)).'/languages');

    // подстраховка (иногда WP не подхватывает uk без суффикса)
    if (!is_textdomain_loaded('pc-order-import-export')) {
        $locale = function_exists('determine_locale') ? determine_locale() : get_locale();
        $candidates = ["pc-order-import-export-$locale.mo"];
        foreach ($candidates as $name) {
            $p = plugin_dir_path(__FILE__) . "languages/$name";
            if (file_exists($p) && load_textdomain('pc-order-import-export', $p)) break;
        }
    }
});

define('PCOE_DIR', __DIR__);
define('PCOE_URL', plugin_dir_url(__FILE__));

/**
 * ВАЖНО: НЕ подключаем здесь никакие vendor/autoload.php.
 * MU-плагин 00-composer-autoload.php уже подхватывает wp-content/vendor/autoload.php
 * для всего сайта.
 */

// автозагрузка только наших классов из inc/
spl_autoload_register(function($class){
    $pfx = 'PaintCore\\PCOE\\';
    if (strpos($class, $pfx) !== 0) return;
    $rel = str_replace('\\','/', substr($class, strlen($pfx)));
    $file = PCOE_DIR . '/inc/' . $rel . '.php';
    if (is_file($file)) require $file;
});

add_action('plugins_loaded', function () {
    // Мягкая зависимость от WooCommerce
    if (!class_exists('WooCommerce')) return;

    // Если PhpSpreadsheet отсутствует — просто отключим XLSX (экспорт/импорт CSV останутся)
    if (!class_exists(\PhpOffice\PhpSpreadsheet\Spreadsheet::class)) {
        // Поведением управляет Ui::disableXlsx()
        if (class_exists(\PaintCore\PCOE\Ui::class)) {
            \PaintCore\PCOE\Ui::disableXlsx();
        }
    }

    (new PaintCore\PCOE\Plugin())->init();
});