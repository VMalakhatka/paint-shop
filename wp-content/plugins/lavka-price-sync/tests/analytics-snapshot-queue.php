<?php
if (PHP_SAPI !== 'cli') exit;
define('ABSPATH', __DIR__); define('HOUR_IN_SECONDS', 3600); define('MINUTE_IN_SECONDS', 60);
function add_action(...$args) {}
function __($s, $d = '') { return $s; }
function absint($s) { return abs((int)$s); }
function sanitize_key($s) { return strtolower((string)$s); }
function sanitize_text_field($s) { return (string)$s; }
function sanitize_textarea_field($s) { return (string)$s; }
function get_option($key, $default = false) { return $GLOBALS['options'][$key] ?? $default; }
function update_option($key, $value, ...$args) { $GLOBALS['options'][$key] = $value; }
function current_time($type) { return date('Y-m-d H:i:s'); }
function wp_generate_uuid4() { return 'queue-' . uniqid(); }
function wp_next_scheduled($key) { return $GLOBALS['events'][$key] ?? false; }
function wp_schedule_single_event($time, $key) { $GLOBALS['events'][$key] = $time; return true; }
function wp_clear_scheduled_hook($key) { unset($GLOBALS['events'][$key]); }
function is_wp_error($r) { return false; }
function wp_remote_retrieve_response_code($r) { return $r['http'] ?? 200; }
function wp_remote_retrieve_body($r) { return json_encode($r); }
function lps_java_get($path, $args) { $GLOBALS['gets'][] = $path; return $GLOBALS['java_status']; }
function lps_java_post($path, $payload, $args) {
    $GLOBALS['posts'][] = [$path, $payload];
    check(lps_analytics_snapshot_state()['phase'] === 'POLLING', 'Persist before POST');
    return $GLOBALS['java_post'];
}
function lavka_ecosystem_lock_acquire(...$args) { if (!empty($GLOBALS['lock'])) return ['ok' => false]; $GLOBALS['lock'] = 'token'; return ['ok' => true, 'token' => 'token']; }
function lavka_ecosystem_lock_touch($token, ...$args) { return ($GLOBALS['lock'] ?? '') === $token; }
function lavka_ecosystem_lock_release($token) { if (($GLOBALS['lock'] ?? '') === $token) $GLOBALS['lock'] = ''; }
$wpdb = new class {
    public $prefix = 'fixture_';
    function prepare($sql, ...$args) { return $sql; }
    function get_var($sql) { return $GLOBALS['mutex_available'] ?? 1; }
    function get_row(...$args) { return ['id' => 10]; }
};
define('ARRAY_A', 'ARRAY_A');
require __DIR__ . '/../inc/accounting-prices-cron.php';
require __DIR__ . '/../inc/accounting-price-campaign.php';
require __DIR__ . '/../inc/analytics-snapshot-queue.php';
function check($ok, $message) { if (!$ok) throw new RuntimeException($message); }
function advance(): array {
    $s = lps_analytics_snapshot_state(); $s['next_at'] = 0; update_option(LPS_ANALYTICS_SNAPSHOT_OPTION, $s);
    return lps_analytics_snapshot_dispatch('tick');
}
function reset_queue(): void { $GLOBALS['options'] = []; $GLOBALS['lock'] = ''; $GLOBALS['posts'] = []; $GLOBALS['gets'] = []; $GLOBALS['events'] = []; }
function start_queue(): array { return lps_analytics_snapshot_dispatch('start', ['warehouseIds' => [7, 9], 'horizonMonths' => 36, 'confirmed' => true]); }
function completed(int $warehouse = 7, int $generation = 11): array { return ['running' => false, 'status' => 'ACTIVE', 'phase' => 'COMPLETED', 'warehouseId' => $warehouse, 'generationId' => $generation, 'analyticsSchemaVersion' => 5]; }
reset_queue();
$java_status = completed(7, 10); $java_post = ['accepted' => true, 'warehouseId' => 7, 'generationId' => 11];
start_queue(); check(count($posts) === 0, 'Start must enqueue only');
advance(); check(count($posts) === 1 && $posts[0][1] === ['warehouseId' => 7, 'horizonMonths' => 36], 'Only snapshot payload');
$java_status = completed(); $java_status['running'] = true;
advance(); check(count($posts) === 1 && lps_analytics_snapshot_state()['index'] === 0, 'No next warehouse while running');
$java_status = completed(); advance();
check(lps_analytics_snapshot_state()['index'] === 1 && count($posts) === 1, 'First completion only advances queue');
$java_post = ['accepted' => true, 'warehouseId' => 9, 'generationId' => 12]; advance();
check(count($posts) === 2 && $posts[1][1]['warehouseId'] === 9, 'Second snapshot is sequential');
$java_status = completed(9, 12); advance();
check(lps_analytics_snapshot_state()['status'] === 'COMPLETED' && !$lock, 'Queue completes and releases lock');
foreach ($posts as [$path]) check($path === LPS_ACCOUNTING_PRICE_CAMPAIGN_SNAPSHOT_PATH, 'Forbidden recalculation endpoint');
check(!isset($options[LPS_ACCOUNTING_PRICE_CAMPAIGN_OPTION]), 'Price campaign history must stay untouched');

reset_queue(); $java_status = completed(7, 10); $java_post = ['http' => 503]; start_queue(); advance();
$java_status = ['http' => 503]; advance(); advance(); check(count($posts) === 1 && lps_analytics_snapshot_state()['active'], 'Poll outage cannot replay POST');
$java_status = completed(7, 10); advance(); check(lps_analytics_snapshot_state()['status'] === 'REVIEW_REQUIRED', 'Old generation not success');

reset_queue(); $java_status = completed(7, 10); $java_post = ['accepted' => true, 'warehouseId' => 7, 'generationId' => 11]; start_queue(); advance();
lps_analytics_snapshot_dispatch('stop'); $java_status = completed(); advance();
check(lps_analytics_snapshot_state()['status'] === 'STOPPED' && count($posts) === 1, 'Stop after current only');

foreach (['INTERRUPTED', 'BUILDING', 'QUEUED'] as $status) {
    reset_queue(); $java_status = completed(7, 10); start_queue(); advance();
    $java_status = ['running' => false, 'status' => $status, 'phase' => 'RECOVERY_REQUIRED']; advance();
    check(lps_analytics_snapshot_state()['status'] === 'INTERRUPTED' && !$lock, 'Restart unlocks without another POST');
}
reset_queue(); $java_status = completed(7, 10); start_queue();
$java_post = ['http' => 400, 'errorCode' => LPS_ACCOUNTING_PRICE_CAMPAIGN_UNSUPPORTED_MODE_ERROR, 'accountingMode' => 'NO_RECALCULATION']; advance();
check(lps_analytics_snapshot_state()['index'] === 1 && lps_analytics_snapshot_state()['reports'][0]['status'] === 'SKIPPED_UNSUPPORTED_MODE', 'Skip unsupported warehouse');
reset_queue(); $lock = 'other'; start_queue(); advance(); check(!$posts, 'Shared lock prevents start');
echo "PASS: snapshot-only endpoints, sequential warehouses, persisted POST intent, no retry, stop, restart, unsupported mode and shared lock\n";
