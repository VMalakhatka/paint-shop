<?php
// Standalone contract regression: no WordPress bootstrap, database, Java, or Folio writes.
if (PHP_SAPI !== 'cli') exit;
define('ABSPATH', __DIR__);
define('HOUR_IN_SECONDS', 3600);
define('MINUTE_IN_SECONDS', 60);
define('ARRAY_A', 'ARRAY_A');
$options = [];
$ticks = [];
$tick_times = [];
$java_body = [];
$lock_releases = 0;

function add_action(...$args) {}
function __($value, $domain = '') { return $value; }
function absint($value): int { return abs((int)$value); }
function sanitize_key($value): string { return strtolower((string)$value); }
function sanitize_text_field($value): string { return (string)$value; }
function sanitize_textarea_field($value): string { return (string)$value; }
function wp_parse_args($args, $defaults = []) { return array_merge($defaults, is_array($args) ? $args : []); }
function current_time($type, $gmt = false) { return '2026-09-07 10:00:00'; }
function get_option($key, $default = false) { return $GLOBALS['options'][$key] ?? $default; }
function update_option($key, $value, ...$args) { $GLOBALS['options'][$key] = $value; return true; }
function wp_next_scheduled($hook) { return false; }
function wp_schedule_single_event($at, $hook) { $GLOBALS['ticks'][] = $hook; $GLOBALS['tick_times'][] = $at; return true; }
function wp_clear_scheduled_hook($hook) { $GLOBALS['ticks'] = array_values(array_filter($GLOBALS['ticks'], fn($item) => $item !== $hook)); }
function is_wp_error($value) { return false; }
function wp_remote_retrieve_response_code($response) { return $response['http'] ?? 200; }
function wp_remote_retrieve_body($response) { return json_encode($response['body'] ?? []); }
function lps_java_get($path, $args = []) { return ['http' => 200, 'body' => $GLOBALS['java_body']]; }
function lavka_ecosystem_lock_release($token) { $GLOBALS['lock_releases']++; }

$wpdb = new class {
    public function prepare($sql, ...$args) { return $sql; }
    public function esc_like($value) { return $value; }
    public function get_var($sql) { return ''; }
    public function get_row($sql, $output = null) { return []; }
};

require __DIR__ . '/../inc/accounting-prices-cron.php';
require __DIR__ . '/../inc/accounting-price-campaign.php';

function check($condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}

function campaign_fixture(): array {
    $GLOBALS['options'] = [];
    $GLOBALS['ticks'] = [];
    $GLOBALS['tick_times'] = [];
    $GLOBALS['lock_releases'] = 0;
    return [
        'campaign_id' => 'campaign-contract',
        'active' => true,
        'status' => 'running',
        'phase' => 'range_poll',
        'source' => 'manual',
        'current_warehouse_id' => 5,
        'warehouse_ids' => [5],
        'warehouse_index' => 0,
        'current_skus' => ['SKU-CLEAN-1', 'SKU-BROKEN', 'SKU-CLEAN-2'],
        'range_job_id' => 'job-8134',
        'range_started_at_unix' => time() - 10,
        'lock_token' => 'owned-lock',
        'processed_skus' => 0,
        'committed_skus' => 0,
        'skipped_skus' => 0,
        'successful_batches' => 0,
        'warning_count' => 0,
        'error_count' => 0,
        'warnings' => [],
        'reports' => [],
        'deadline_at' => time() + 3600,
    ];
}

function matching_request(): array {
    return [
        'warehouseId' => 5,
        'previewOnly' => false,
        'confirmApply' => true,
        'applyMode' => 'SAFE_APPLY_ONLY',
        'skus' => ['SKU-CLEAN-1', 'SKU-BROKEN', 'SKU-CLEAN-2'],
    ];
}

$state = campaign_fixture();
$GLOBALS['java_body'] = [
    'jobId' => 'job-8134',
    'running' => false,
    'status' => 'COMPLETED_WITH_WARNINGS',
    'processedSku' => 3,
    'totalUnits' => 3,
    'committedChunks' => 2,
    'warningCount' => 1,
    'request' => matching_request(),
    'warnings' => [[
        'code' => 'ACCOUNTING_PRICE_DIVIDE_BY_ZERO',
        'message' => 'Divide by zero error encountered.',
        'details' => [
            'sku' => 'SKU-BROKEN',
            'sourceDatabase' => 'Paint_Ua',
            'warehouseId' => 5,
            'jobId' => 'job-8134',
            'stage' => 'FOLIO_PROCEDURE_CALL',
            'skipped' => true,
            'committed' => false,
            'rollbackConfirmed' => true,
            'formulaConfirmed' => false,
            'documentConfirmed' => false,
            'sqlErrors' => [['errorCode' => 8134, 'sqlState' => '22012', 'message' => 'Divide by zero error encountered.']],
        ],
    ]],
];
lps_accounting_price_campaign_poll_range($state);
check($state['active'] && $state['phase'] === 'select_batch', 'Safe arithmetic skip must continue the campaign.');
check($state['processed_skus'] === 3, 'All attempted SKU must be counted as processed.');
check($state['committed_skus'] === 2, 'Only committed SKU must be counted as written.');
check($state['skipped_skus'] === 1, 'Rolled-back SKU must be counted as skipped.');
check($state['warnings'][0]['details']['rollbackConfirmed'] === true, 'Structured rollback diagnostics must be retained.');
check($state['reports'][0]['committed_chunks'] === 2 && $state['reports'][0]['skipped_sku'] === 1, 'Batch report must separate committed and skipped SKU.');
check(count($GLOBALS['ticks']) === 1, 'The next campaign tick must be scheduled.');

$state = campaign_fixture();
$GLOBALS['java_body'] = [
    'jobId' => 'job-8134', 'running' => false, 'status' => 'OUTCOME_UNKNOWN',
    'error' => 'Rollback could not be confirmed.', 'request' => matching_request(),
];
lps_accounting_price_campaign_poll_range($state);
check(!$state['active'] && $state['phase'] === 'manual_review', 'Unknown outcome must stop the campaign.');
check(($GLOBALS['options'][LPS_ACCOUNTING_PRICES_NATIVE_CRON_OPTION]['enabled'] ?? true) === false, 'Unknown outcome must pause the schedule.');
check($GLOBALS['ticks'] === [], 'Unknown outcome must not schedule an automatic retry.');

$state = campaign_fixture();
$GLOBALS['java_body'] = [
    'jobId' => 'job-8134', 'running' => false, 'status' => 'FAILED',
    'currentArt' => 'SKU-LAST-CONTEXT', 'error' => 'Legacy failure response.',
    'errors' => [['code' => 'LEGACY_FAILURE', 'message' => 'Legacy error without a diagnostic SKU.']],
    'request' => matching_request(),
];
lps_accounting_price_campaign_poll_range($state);
$last_issue = end($state['warnings']);
check($last_issue['code'] === 'LAST_KNOWN_FAILURE_CONTEXT', 'Legacy failure must create a context-only diagnostic.');
check($last_issue['sku'] === 'SKU-LAST-CONTEXT', 'Legacy currentArt must remain visible.');
check($last_issue['details']['contextOnly'] === true, 'Legacy currentArt must not be presented as a proven cause.');
check($state['error_count'] === 1, 'Context must not duplicate the real error count.');
check($state['phase'] === 'snapshot_after_start', 'Ordinary FAILED must move to final snapshot, not retry apply.');

$state = campaign_fixture();
$GLOBALS['java_body'] = [
    'jobId' => 'job-8134', 'running' => false, 'status' => 'FAILED_PARTIAL',
    'errorCode' => 'FOLIO_LOCK_BUSY_RETRYABLE_AFTER_SNAPSHOT',
    'processedSku' => 2, 'committedChunks' => 2,
    'error' => 'The Folio lock is temporarily busy.', 'request' => matching_request(),
];
lps_accounting_price_campaign_poll_range($state);
check($state['active'] && $state['status'] === 'waiting_retry', 'A proven retryable lock must keep the campaign active.');
check($state['phase'] === 'waiting_lock_retry', 'A proven retryable lock must enter the delayed retry phase.');
check($state['processed_skus'] === 2 && $state['committed_skus'] === 2, 'Verified partial progress must be retained before retry.');
check($state['lock_retry_count'] === 1, 'The automatic lock retry must be counted.');
check($state['retry_at'] >= time() + 599, 'The retry must be delayed by ten minutes.');
check(($state['reports'][0]['status'] ?? '') === 'LOCK_RETRY_SCHEDULED', 'The report must distinguish a scheduled retry from a fatal partial failure.');
check(($GLOBALS['options'][LPS_ACCOUNTING_PRICES_NATIVE_CRON_OPTION]['enabled'] ?? true) === true, 'A retryable lock must not pause the weekly schedule.');

$state['retry_at'] = time() - 1;
$GLOBALS['options'][LPS_ACCOUNTING_PRICE_CAMPAIGN_OPTION] = $state;
$GLOBALS['ticks'] = [];
lps_accounting_price_campaign_tick();
$state = $GLOBALS['options'][LPS_ACCOUNTING_PRICE_CAMPAIGN_OPTION];
check($state['active'] && $state['phase'] === 'snapshot_before_start', 'An elapsed retry delay with reserve must start a fresh snapshot.');
check($state['status'] === 'running' && $state['cursor'] === '', 'Retry continuation must reset selection instead of replaying the old batch.');

$state = campaign_fixture();
$state['status'] = 'waiting_retry';
$state['phase'] = 'waiting_lock_retry';
$state['retry_at'] = time() - 1;
$state['deadline_at'] = time() + 899;
$GLOBALS['options'][LPS_ACCOUNTING_PRICE_CAMPAIGN_OPTION] = $state;
lps_accounting_price_campaign_tick();
$state = $GLOBALS['options'][LPS_ACCOUNTING_PRICE_CAMPAIGN_OPTION];
check($state['phase'] === 'snapshot_after_start', 'Retry wake-up with less than 15 minutes must go to the final snapshot.');
check($state['stop_reason'] === 'time_limit', 'Lost retry reserve must stop the queue by time limit.');

$state = campaign_fixture();
$state['deadline_at'] = time() + 1499;
$GLOBALS['java_body'] = [
    'jobId' => 'job-8134', 'running' => false, 'status' => 'FAILED_PARTIAL',
    'errorCode' => 'FOLIO_LOCK_BUSY_RETRYABLE_AFTER_SNAPSHOT',
    'processedSku' => 2, 'committedChunks' => 2,
    'error' => 'The Folio lock is temporarily busy.', 'request' => matching_request(),
];
lps_accounting_price_campaign_poll_range($state);
check($state['active'] && $state['phase'] === 'snapshot_after_start', 'A retry must not be scheduled when fewer than 25 minutes remain.');
check($state['stop_reason'] === 'time_limit' && $state['retry_at'] === 0, 'Insufficient retry time must end through the mandatory final snapshot.');

$state = campaign_fixture();
$GLOBALS['java_body'] = [
    'jobId' => 'job-8134', 'running' => false, 'status' => 'FAILED_PARTIAL',
    'errorCode' => 'FOLIO_LOCK_BUSY',
    'processedSku' => 2, 'committedChunks' => 2,
    'error' => 'Unclassified lock failure.', 'request' => matching_request(),
];
lps_accounting_price_campaign_poll_range($state);
check(!$state['active'] && $state['phase'] === 'manual_review', 'An unclassified partial failure must remain manual.');

echo "PASS: arithmetic skip, committed/skipped counts, lock retry thresholds, unknown stop and legacy context\n";
