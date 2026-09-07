<?php
// Standalone regression: no WordPress bootstrap, database or Java connection.
define('ABSPATH', __DIR__);
define('MINUTE_IN_SECONDS', 60);
define('HOUR_IN_SECONDS', 3600);
$options = [];
$ticks = [];
function __(string $value, string $domain = ''): string { return $value; }
function absint($value): int { return abs((int)$value); }
function sanitize_key($value): string { return strtolower((string)$value); }
function sanitize_text_field($value): string { return (string)$value; }
function sanitize_textarea_field($value): string { return (string)$value; }
function wp_json_encode($value, $flags = 0) { return json_encode($value, $flags); }
function current_time($type, $gmt = false) { return date('Y-m-d H:i:s'); }
function get_option($key, $default = false) { return $GLOBALS['options'][$key] ?? $default; }
function update_option($key, $value, $autoload = false) { $GLOBALS['options'][$key] = $value; }
function wp_next_scheduled($hook) { return false; }
function wp_schedule_single_event($at, $hook) { $GLOBALS['ticks'][] = $hook; return true; }
function wp_clear_scheduled_hook($hook) { $GLOBALS['ticks'] = []; }
function add_action(...$args) {}
function lps_accounting_prices_native_normalize_warehouse_ids($ids): array { return array_values(array_unique(array_map('intval', $ids))); }
function lps_accounting_prices_native_sanitize_report_value($value) { return $value; }
require __DIR__ . '/../inc/accounting-price-campaign.php';

function check($condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
function fixture(bool $processed = true): array {
    $GLOBALS['options'] = [];
    $GLOBALS['ticks'] = [];
    return [
        'campaign_id' => 'budget-regression', 'active' => true, 'status' => 'running',
        'phase' => 'select_batch', 'current_warehouse_id' => 5,
        'warehouse_ids' => [5, 6], 'warehouse_index' => 0,
        'deadline_at' => time() + 240, 'include_unverified' => true,
        'counts_after' => ['UNVERIFIED' => 20037, 'NEW' => 141, 'DIRTY' => 154, 'FAILED' => 5, 'REMOVED' => 195],
        'reports' => $processed ? [['warehouse_id' => 5, 'status' => 'COMPLETED_WITH_WARNINGS', 'sku_count' => 500]] : [],
    ];
}

$state = fixture();
update_option(LPS_ACCOUNTING_PRICE_CAMPAIGN_OPTION, $state);
lps_accounting_price_campaign_tick();
$state = lps_accounting_price_campaign_state();
check($state['phase'] === 'snapshot_after_start', 'Final snapshot must precede stopping');
check($state['active'] && $state['stop_reason'] === 'time_limit', 'Persist budget exhaustion before snapshot');
// A later budget change must not accidentally reopen the queue.
$state['deadline_at'] = time() + 3600;
lps_accounting_price_campaign_finish_warehouse($state);
check(!$state['active'] && $state['status'] === 'paused_time_limit', 'Pause the entire queue');
check($state['current_warehouse_id'] === 5, 'Do not start warehouse 6');
check($state['remaining_skus'] === 20332, 'Exclude FAILED and REMOVED from remaining work');
check(end($state['reports'])['status'] === 'PARTIAL_TIME_LIMIT', 'Partial warehouse must not be labelled complete');
check(str_contains($state['message'], '20332'), 'Show remaining work in the message');
check(get_option(LPS_ACCOUNTING_PRICE_CAMPAIGN_WAREHOUSE_HISTORY_PREFIX . '5')['status'] === 'PARTIAL_TIME_LIMIT', 'Persist partial warehouse history');
check(get_option(LPS_ACCOUNTING_PRICE_CAMPAIGN_WAREHOUSE_HISTORY_PREFIX . '6') === false, 'Do not create successful history for untouched warehouses');
check($GLOBALS['ticks'] === [], 'Remove campaign ticks');

$state = fixture(false);
lps_accounting_price_campaign_finish_warehouse($state);
check(end($state['reports'])['status'] === 'NOT_PROCESSED_TIME_LIMIT', 'Snapshot-only warehouse is not processed');

$state = fixture();
$state['deadline_at'] = time() - 1;
lps_accounting_price_campaign_finish_warehouse($state);
check(!$state['active'], 'Deadline during final snapshot must pause queue');

$state = fixture();
$state['counts_after'] = ['UNVERIFIED' => 0, 'NEW' => 0, 'DIRTY' => 0, 'FAILED' => 5];
lps_accounting_price_campaign_finish_warehouse($state);
check(end($state['reports'])['status'] === 'SNAPSHOT_CONFIRMED', 'Completed warehouse stays complete');
check(!$state['active'], 'Completed warehouse must not start next warehouse without budget');

$state = fixture();
$state['include_unverified'] = false;
lps_accounting_price_campaign_finish_warehouse($state);
check($state['remaining_skus'] === 295, 'Regular-only mode counts NEW and DIRTY');

$state = fixture();
$state['deadline_at'] = time() + 3600;
$state['warnings'] = [['warehouseId' => 5, 'code' => 'NEGATIVE_CHRONOLOGICAL_STOCK', 'severity' => 'warning']];
lps_accounting_price_campaign_finish_warehouse($state);
check($state['active'] && $state['current_warehouse_id'] === 6, 'Warnings alone must not pause queue');

$export = lps_accounting_price_campaign_snapshot_export_values([
    'sku' => 'ТП-0001',
    'verification_state' => 'FAILED',
    'last_error' => 'NEGATIVE_CHRONOLOGICAL_STOCK: negative',
    'latest_diagnostic' => [
        'errorCode' => 'NEGATIVE_CHRONOLOGICAL_STOCK',
        'details' => [
            'quantityBefore' => 27,
            'quantityAfter' => -23,
            'shortageQuantity' => 23,
            'operation' => [
                'documentType' => 'Р',
                'documentNumber' => 62,
                'documentDate' => '2026-07-23T00:00:00',
                'recno' => 8997390,
                'quantity' => 50,
            ],
        ],
    ],
], ['warehouseId' => 5], 'FAILED');
check($export['Document No.'] === 62 && $export['Document date'] === '2026-07-23T00:00:00', 'Snapshot exports expose error document details');
check($export['Before operation'] === 27 && $export['After operation'] === -23 && $export['Shortage'] === 23, 'Snapshot exports expose negative stock quantities');
echo "PASS: campaign budget, final snapshot, history, remaining counts and warning continuation\n";
