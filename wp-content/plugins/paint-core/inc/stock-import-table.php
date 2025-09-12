<?php
/**
 * Create (if not exists) table wp_stock_import for stock uploads per location.
 */
namespace PaintCore\StockImport;
defined('ABSPATH') || exit;

add_action('plugins_loaded', __NAMESPACE__.'\\maybe_create_table');
function maybe_create_table() {
    global $wpdb;
    $table = $wpdb->prefix . 'stock_import';

    // Check if table exists
    $exists = $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM information_schema.tables
         WHERE table_schema=%s AND table_name=%s",
        $wpdb->dbname, $table
    ) );

    if ( ! $exists ) {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            sku           VARCHAR(191) NOT NULL,
            location_slug VARCHAR(191) NOT NULL,
            qty           DECIMAL(18,3) NOT NULL,
            PRIMARY KEY (sku, location_slug)
        ) {$charset_collate};";
        dbDelta($sql);
    }
}