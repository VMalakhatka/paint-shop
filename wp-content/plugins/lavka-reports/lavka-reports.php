<?php
/**
 * Plugin Name: Lavka Reports
 * Description: WooCommerce admin reports. Report #1: No movement by warehouses (settings step).
 * Author: Volodymyr
 * Version: 0.1.0
 * Text Domain: lavka-reports
 * Domain Path: /languages
 */

if (!defined('ABSPATH')) exit;

define('LAVR_VER', '0.1.0');
define('LAVR_FILE', __FILE__);
define('LAVR_PATH', plugin_dir_path(__FILE__));
define('LAVR_URL',  plugin_dir_url(__FILE__));

add_action('plugins_loaded', function(){
    load_plugin_textdomain('lavka-reports', false, dirname(plugin_basename(__FILE__)) . '/languages');
});

require_once LAVR_PATH . 'inc/class-admin.php';
require_once LAVR_PATH . 'inc/class-ajax.php';
require_once LAVR_PATH . 'inc/no-movement/class-settings.php';

add_action('plugins_loaded', function(){
    new Lavka_Reports_Admin();
    new Lavka_Reports_Ajax();
    new Lavka_Reports_NoMovement_Settings();
});