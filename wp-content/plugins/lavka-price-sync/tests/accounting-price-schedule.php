<?php
// Standalone scheduler regression. No WordPress, Java, database or real cron.
define('ABSPATH', __DIR__);
define('MINUTE_IN_SECONDS', 60);
define('HOUR_IN_SECONDS', 3600);
function add_action(...$args) {}
function absint($value) { return abs((int)$value); }
function get_option($key, $default = false) { return $GLOBALS['options'][$key] ?? $default; }
function wp_parse_args($args, $defaults) { return array_merge($defaults, $args); }
function wp_timezone() { return new DateTimeZone($GLOBALS['timezone'] ?? 'Europe/Kyiv'); }
require __DIR__ . '/../inc/accounting-prices-cron.php';
function check($value, $message) { if (!$value) throw new RuntimeException($message); }
function next_run(array $config, string $from): ?string {
    $timestamp = lps_accounting_prices_native_calculate_next($config + ['enabled' => true, 'automatic_apply_confirmed' => true, 'time' => '20:01'], (new DateTimeImmutable($from, wp_timezone()))->getTimestamp());
    return $timestamp === null ? null : (new DateTimeImmutable('@' . $timestamp))->setTimezone(wp_timezone())->format('Y-m-d H:i P');
}

$GLOBALS['options'][LPS_ACCOUNTING_PRICES_NATIVE_CRON_OPTION] = ['weekday' => 'sun', 'warehouse_ids' => [7, 1, 5]];
$options = lps_accounting_prices_native_cron_options();
check($options['weekdays'] === ['sun'], 'Legacy weekly schedule must not become daily');
check($options['warehouse_ids'] === [7, 1, 5], 'Keep stored warehouse order');
check(next_run(['weekday' => 'sun'], '2026-09-13 20:00') === '2026-09-13 20:01 +03:00', 'Same day before start');
check(next_run(['weekday' => 'sun'], '2026-09-13 20:01') === '2026-09-20 20:01 +03:00', 'Do not repeat at current instant');
check(next_run(['weekdays' => ['mon', 'wed', 'fri']], '2026-09-15 22:00') === '2026-09-16 20:01 +03:00', 'Next selected weekday');
check(next_run(['weekdays' => ['mon', 'wed']], '2026-09-16 20:02') === '2026-09-21 20:01 +03:00', 'Wrap across week');
$daily = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];
check(next_run(['weekdays' => $daily], '2026-09-15 21:00') === '2026-09-16 20:01 +03:00', 'Daily means next day');
check(next_run(['weekdays' => []], '2026-09-15') === null, 'Explicit empty selection does not fall back to Sunday');
check(next_run(['weekdays' => $daily, 'enabled' => false], '2026-09-15') === null, 'Disabled means no event');
check(next_run(['weekdays' => $daily, 'automatic_apply_confirmed' => false], '2026-09-15') === null, 'Consent required');
check(lps_accounting_prices_native_schedule_days(['weekdays' => ['fri', [], 'sun', 'fri', 'bad']]) === ['fri', 'sun'], 'Validate and deduplicate days');
check(lps_accounting_prices_native_schedule_days(['weekday' => 'bad']) === ['sun'], 'Keep legacy invalid-value fallback');
$GLOBALS['timezone'] = 'Europe/Berlin';
check(next_run(['weekdays' => $daily], '2026-03-28 21:00') === '2026-03-29 20:01 +02:00', 'Spring DST preserves local start');
check(next_run(['weekdays' => $daily], '2026-10-24 21:00') === '2026-10-25 20:01 +01:00', 'Autumn DST preserves local start');
$GLOBALS['timezone'] = '+03:00';
check(next_run(['weekdays' => $daily], '2026-10-24 21:00') === '2026-10-25 20:01 +03:00', 'Fixed site timezone stays fixed');

check(lps_accounting_prices_native_order_warehouses([1, 2, 5, 7], [7 => 1, 1 => 2, 5 => 3, 2 => 9]) === [7, 1, 5, 2], 'Priority order is not numeric warehouse order');
check(lps_accounting_prices_native_order_warehouses([7, 1, 5], []) === [7, 1, 5], 'Missing positions preserve legacy order');
check(lps_accounting_prices_native_order_warehouses([7, 1, 5, 7, 0], [7 => 1, 1 => 1, 5 => 2]) === [7, 1, 5], 'Stable ties and no duplicates');
check(lps_accounting_prices_native_order_warehouses([7, 1, 5], [7 => [], 1 => 2, 5 => 'bad', 9 => 1]) === [1, 7, 5], 'Malformed ranks do not add unselected warehouses');
echo "PASS: weekly migration, multiple days, daily, consent, empty days, DST and stable warehouse order\n";
