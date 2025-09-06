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

// (опційно для XLSX) – якщо є vendor/
$vendor = PCOE_DIR.'/vendor/autoload.php';
if (is_readable($vendor)) require_once $vendor;

spl_autoload_register(function($class){
    $pfx = 'PaintCore\\PCOE\\';
    if (strpos($class, $pfx) !== 0) return;
    $rel = str_replace('\\','/', substr($class, strlen($pfx)));
    $file = PCOE_DIR.'/inc/'.$rel.'.php';
    if (is_file($file)) require $file;
});

add_action('plugins_loaded', function(){
    // М’яка залежність від WooCommerce
    if (!class_exists('WooCommerce')) return;
    (new PaintCore\PCOE\Plugin())->init();
});

add_action('plugins_loaded', function () {
    if (!class_exists('WooCommerce') || !function_exists('WC')) return;
    (new \PaintCore\PCOE\Plugin())->init();
});

add_action('init', function () {
    load_plugin_textdomain('pc-oi-export', false, dirname(plugin_basename(__FILE__)).'/languages');
});

if (version_compare(PHP_VERSION, '8.1', '<')) {
    // можна показати адмін-нотіс, а XLSX — тихо падати в CSV
}