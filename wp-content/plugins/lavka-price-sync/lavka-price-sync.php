<?php
/**
 * Plugin Name: Lavka Price Sync
 * Description: Sync role-based prices from Java service to WooCommerce (meta: _wpc_price_role_<role>).
 * Version: 0.1.0
 * Text Domain: lavka-price-sync
 * Domain Path: /languages
 */
if (!defined('ABSPATH')) exit;

add_action('init', function () {
    load_plugin_textdomain('lavka-price-sync', false, dirname(plugin_basename(__FILE__)) . '/languages');
});

define('LPS_OPT_MAIN',    'lps_options');
define('LPS_OPT_MAPPING', 'lps_role_contract_map');
define('LPS_CRON_HOOK',   'lps_cron_price_sync');
define('LPS_CAP',         'manage_lavka_prices');
define('LPS_DEF_BATCH',   500);
define('LPS_MIN_BATCH',   50);
define('LPS_MAX_BATCH',   2000);

// ---- CRON (price sync) ----
if (!defined('LPS_OPT_CRON')) define('LPS_OPT_CRON', 'lps_cron_price'); // опция расписания

require_once __DIR__ . '/inc/cron.php';

register_activation_hook(__FILE__, function () {
    if (function_exists('lps_cron_reschedule_price')) lps_cron_reschedule_price();
});
register_deactivation_hook(__FILE__, function () {
    if (function_exists('lps_cron_clear_all')) lps_cron_clear_all();
});

require_once __DIR__.'/inc/helpers.php';
require_once __DIR__.'/inc/admin-ui.php';
require_once __DIR__.'/inc/mapping.php';
require_once __DIR__.'/inc/sync.php';

add_action('init', function () {
  foreach (['administrator','shop_manager'] as $r) {
    if ($role = get_role($r)) $role->add_cap(LPS_CAP);
  }
});

add_filter('cron_schedules', function ($s) {
  $s['lps_hourly'] = ['interval'=>3600, 'display'=>__('Lavka Price hourly','lavka-price-sync')];
  return $s;
});
register_activation_hook(__FILE__, function () {
  if (!wp_next_scheduled(LPS_CRON_HOOK)) wp_schedule_event(time()+300, 'lps_hourly', LPS_CRON_HOOK);
});
register_deactivation_hook(__FILE__, function () {
  wp_clear_scheduled_hook(LPS_CRON_HOOK);
});

// anchor: ACTIVATION-LOGS
register_activation_hook(__FILE__, function(){
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    global $wpdb;
    $table = $wpdb->prefix . 'lps_price_logs';
    $charset = $wpdb->get_charset_collate();
    $sql = "CREATE TABLE {$table} (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      started_at DATETIME NOT NULL,
      finished_at DATETIME NULL,
      mode VARCHAR(20) NOT NULL DEFAULT 'manual',   -- manual|cron
      triggered_by BIGINT UNSIGNED NULL,            -- user id, если есть
      ok TINYINT(1) NOT NULL DEFAULT 0,
      total INT UNSIGNED NOT NULL DEFAULT 0,
      updated_retail INT UNSIGNED NOT NULL DEFAULT 0,
      updated_roles INT UNSIGNED NOT NULL DEFAULT 0,
      not_found INT UNSIGNED NOT NULL DEFAULT 0,
      csv_path TEXT NULL,                           -- путь к CSV (uploads)
      sample_json LONGTEXT NULL,                    -- маленький sample
      PRIMARY KEY (id),
      KEY started_at (started_at),
      KEY mode (mode)
    ) {$charset};";
    dbDelta($sql);
});