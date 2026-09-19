<?php
// Exercise the real admin-post handler with in-memory options and cron.
define('ABSPATH', __DIR__);
define('LPS_CAP', 'manage_woocommerce');
define('MINUTE_IN_SECONDS', 60);
define('HOUR_IN_SECONDS', 3600);
$actions = []; $options = []; $events = []; $cleared = [];
function add_action($hook, $callback, ...$args) { $GLOBALS['actions'][$hook] = $callback; }
function absint($v) { return abs((int)$v); }
function get_option($key, $default = false) { return $GLOBALS['options'][$key] ?? $default; }
function update_option($key, $value, $autoload = false) { $GLOBALS['options'][$key] = $value; return true; }
function wp_parse_args($args, $defaults) { return array_merge($defaults, $args); }
function wp_timezone() { return new DateTimeZone('Europe/Kyiv'); }
function current_user_can($cap) { return true; }
function check_admin_referer($key) {}
function wp_unslash($v) { return $v; }
function sanitize_key($v) { return strtolower((string)$v); }
function sanitize_text_field($v) { return trim((string)$v); }
function wp_clear_scheduled_hook($hook) { $GLOBALS['cleared'][] = $hook; unset($GLOBALS['events'][$hook]); }
function wp_schedule_single_event($at, $hook) { $GLOBALS['events'][$hook] = $at; return true; }
function admin_url($path) { return 'https://fixture.local/' . $path; }
function add_query_arg($args, $url) { return $url . '?' . http_build_query($args); }
function wp_safe_redirect($url) { $GLOBALS['redirect'] = $url; }
require __DIR__ . '/../inc/accounting-prices-cron.php';
$original = ['enabled' => true, 'automatic_apply_confirmed' => true, 'warehouse_ids' => [1, 5], 'weekday' => 'sun', 'time' => '20:01'];
$options[LPS_ACCOUNTING_PRICES_NATIVE_CRON_OPTION] = $original;
$options['lps_accounting_price_sku_campaign'] = ['active' => true, 'warehouse_ids' => [1, 5], 'current_warehouse_id' => 1];
$active = $options['lps_accounting_price_sku_campaign'];
$_POST = ['enabled' => '1', 'automatic_apply_confirmed' => '1', 'warehouse_ids' => ['1', '5', '7'], 'warehouse_positions' => [1 => 2, 5 => 3, 7 => 1], 'schedule_days_present' => '1', 'weekdays' => ['mon','wed','fri'], 'time' => '21:15'];
$case = $argv[1] ?? 'save';
if ($case === 'empty') unset($_POST['weekdays']);
if ($case === 'legacy') { unset($_POST['weekdays'], $_POST['schedule_days_present'], $_POST['warehouse_positions']); $_POST['weekday'] = 'sat'; }
if ($case === 'daily') $_POST['weekdays'] = ['mon','tue','wed','thu','fri','sat','sun'];
if ($case === 'unconfirmed') unset($_POST['automatic_apply_confirmed']);
register_shutdown_function(static function () use ($case, $original, $active) {
    $saved = get_option(LPS_ACCOUNTING_PRICES_NATIVE_CRON_OPTION);
    $failed = in_array($case, ['empty', 'unconfirmed'], true);
    $ok = $failed
        ? $saved === $original && $GLOBALS['events'] === [] && $GLOBALS['cleared'] === [] && str_contains($GLOBALS['redirect'], 'cron_saved=0')
        : $saved['warehouse_ids'] === ($case === 'legacy' ? [1,5,7] : [7,1,5])
            && $saved['weekdays'] === ($case === 'legacy' ? ['sat'] : ($case === 'daily' ? ['mon','tue','wed','thu','fri','sat','sun'] : ['mon','wed','fri']))
            && count($GLOBALS['events']) === 1 && str_contains($GLOBALS['redirect'], 'cron_saved=1');
    $ok = $ok && get_option('lps_accounting_price_sku_campaign') === $active;
    if (!$ok) { fwrite(STDERR, 'FAIL: save handler ' . $case . "\n"); exit(1); }
    echo 'PASS: save handler ' . $case . ", ordered queue and running campaign unchanged\n";
});
$actions['admin_post_lps_accounting_prices_save_cron']();
