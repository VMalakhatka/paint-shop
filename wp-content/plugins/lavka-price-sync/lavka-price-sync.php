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