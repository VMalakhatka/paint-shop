<?php
/**
 * Plugin Name: PC Order Import/Export
 * Description: Експорт кошика/замовлень у CSV/XLSX + імпорт у кошик/чернетку. Інтегровано з WooCommerce.
 * Version: 1.0.0
 * Author: PaintCore
 * Text Domain: pc-oi-export
 */

if (!defined('ABSPATH')) exit;

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