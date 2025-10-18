<?php
/*
 * Plugin Name: Lavka Total Sync
 * Plugin URI: https://example.com/
 * Description: Total synchronisation of products from external MSSQL or file sources into WooCommerce, excluding price and stock. Provides an admin interface to configure endpoints and launch synchronisation tasks.
 * Version: 0.1.0
 * Author: Your Name
 * Text Domain: lavka-total-sync
 * Domain Path: /languages
 */

// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

// Define capability to manage this plugin.
if (!defined('LTS_CAP')) {
    define('LTS_CAP', 'manage_lavka_sync');
}

// Define option key for storing settings.
// Use the same option key as in the main plugin file to avoid mismatch
if (!defined('LTS_OPT'))        define('LTS_OPT',        'lts_options');

// Load i18n.
add_action('init', function () {
    load_plugin_textdomain('lavka-total-sync', false, dirname(plugin_basename(__FILE__)) . '/languages');
});

// Define additional constants for batch limits. These mirror values used in other Lavka sync plugins.
if (!defined('LTS_DEF_BATCH')) {
    define('LTS_DEF_BATCH', 500);
}
if (!defined('LTS_MIN_BATCH')) {
    define('LTS_MIN_BATCH', 50);
}
if (!defined('LTS_MAX_BATCH')) {
    define('LTS_MAX_BATCH', 2000);
}

// Load helper functions and admin UI.
require_once __DIR__ . '/inc/helpers.php';
require_once __DIR__ . '/inc/admin-ui.php';
// [LTS] ANCHOR: include sync goods
require_once __DIR__ . '/inc/sync_goods.php';
// Future: require_once __DIR__ . '/inc/cron.php';

// [LTS] ANCHOR: include logs
require_once __DIR__ . '/inc/logs.php';

// [LTS] ANCHOR: include admin logs
if (is_admin()) {
    require_once __DIR__ . '/inc/admin-logs.php';
}

// Activation hook: could set defaults or create cron.
register_activation_hook(__FILE__, function () {
    // Set default options if not present.
    if (false === get_option(LTS_OPT)) {
        $defaults = [
            'base_url'    => '',
            'api_token'   => '',
            'path_sync'   => '/sync/goods',
            'path_status' => '/sync/goods/{id}',
            'path_cancel' => '/sync/goods/{id}/cancel',
            'batch'       => 500,
            'timeout'     => 160,
        ];
        add_option(LTS_OPT, $defaults);
    }
});

// [LTS] ANCHOR: activation - create logs table
register_activation_hook(__FILE__, function(){
    global $wpdb;
    $table = $wpdb->prefix . 'lts_sync_logs';
    $charset = $wpdb->get_charset_collate();
    $sql = "CREATE TABLE IF NOT EXISTS $table (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        ts DATETIME NOT NULL,
        level VARCHAR(16) NOT NULL,
        action VARCHAR(32) NOT NULL,
        sku VARCHAR(191) NULL,
        post_id BIGINT UNSIGNED NULL,
        result VARCHAR(32) NULL,
        message TEXT NULL,
        ctx LONGTEXT NULL,
        PRIMARY KEY (id),
        KEY ts_idx (ts),
        KEY sku_idx (sku),
        KEY action_idx (action),
        KEY level_idx (level),
        KEY post_idx (post_id)
    ) $charset;";
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);
});