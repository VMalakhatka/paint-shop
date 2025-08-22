<?php
/**
 * Plugin Name: WP Stock Import – Diagnose
 * Description: Простая диагностика вставки в wp_stock_import и вывод последней ошибки $wpdb.
 */
if (!defined('ABSPATH')) exit;

add_action('admin_init', function () {
    if (!current_user_can('manage_options')) return;

    // Запусти руками: /wp-admin/?stock_diag=1
    if (empty($_GET['stock_diag'])) return;

    global $wpdb;
    $table = $wpdb->prefix . 'stock_import';

    // Попытка вставить одну тестовую строку
    $sql = "INSERT INTO {$table} (sku, location_slug, qty)
            VALUES (%s,%s,%f)
            ON DUPLICATE KEY UPDATE qty=VALUES(qty)";
    $prepared = $wpdb->prepare($sql, 'CR-TEST-999', 'kiev1', 5.0);
    $res = $wpdb->query($prepared);

    // Соберём максимум сигналов
    $out = [
        'table'        => $table,
        'query_ok'     => (int)$res,
        'last_error'   => $wpdb->last_error,
        'last_query'   => $wpdb->last_query,
        'table_exists' => (bool)$wpdb->get_var( $wpdb->prepare(
                              "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=%s",
                              $table
                          )),
        'show_create'  => $wpdb->get_row("SHOW CREATE TABLE {$table}", ARRAY_A),
        'current_user' => wp_get_current_user()->user_login,
        'cap_ok'       => current_user_can('manage_options') ? 'yes' : 'no',
        'db_version'   => $wpdb->db_version(),
        'prefix'       => $wpdb->prefix,
    ];

    // Красиво выведем и остановим выполнение
    wp_die('<pre style="white-space:pre-wrap">'.esc_html(print_r($out, true)).'</pre>', 'Stock Import Diagnose');
});