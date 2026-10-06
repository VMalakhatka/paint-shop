<?php
// Offline regression: no WordPress bootstrap, network, databases or financial writes.
define('ABSPATH', __DIR__); define('MINUTE_IN_SECONDS', 60); define('HOUR_IN_SECONDS', 3600); define('ARRAY_A', 'ARRAY_A');
function add_action(...$args) {}
function __($s, $domain = '') { return $s; }
function absint($v) { return abs((int)$v); }
function sanitize_key($v) { return strtolower((string)$v); }
function sanitize_text_field($v) { return (string)$v; }
function sanitize_textarea_field($v) { return (string)$v; }
function current_time($type, $gmt = false) { return gmdate('Y-m-d H:i:s'); }
function get_current_user_id() { return 0; }
function wp_generate_uuid4() { return 'new-campaign'; }
function wp_parse_args($v, $defaults) { return array_merge($defaults, $v); }
function wp_timezone() { return new DateTimeZone('Europe/Kyiv'); }
function get_option($key, $default = false) { return $GLOBALS['options'][$key] ?? $default; }
function update_option($key, $value, ...$args) { $GLOBALS['options'][$key] = $value; }
function wp_next_scheduled($hook) { return $GLOBALS['events'][$hook] ?? false; }
function wp_schedule_single_event($time, $hook) { $GLOBALS['events'][$hook] = $time; return true; }
function wp_clear_scheduled_hook($hook) { unset($GLOBALS['events'][$hook]); }
function is_wp_error($v) { return false; }
function wp_remote_retrieve_response_code($v) { return $v['http'] ?? 200; }
function wp_remote_retrieve_body($v) { return json_encode($v['body'] ?? []); }
function lps_java_get(...$args) { return $GLOBALS['response']; }
function lps_java_post(...$args) { throw new RuntimeException('Unexpected apply or snapshot POST'); }
function lavka_ecosystem_lock_release($token) {}
function lavka_ecosystem_lock_acquire(...$args) { return ['ok'=>false, 'message'=>'Busy']; }
function lavka_ecosystem_log_event($event, $data) { $GLOBALS['logs'][] = $event; }
$wpdb = new class {
    function prepare($sql, ...$args) { return str_starts_with($sql, 'SHOW TABLES') ? $args[0] : $sql; }
    function esc_like($s) { return $s; }
    function get_var($sql) { return str_starts_with($sql, 'folio_product_') ? $sql : ''; }
    function get_row(...$args) { return []; }
    function get_results(...$args) { return []; }
};
require __DIR__.'/../inc/accounting-prices-cron.php';
require __DIR__.'/../inc/accounting-price-campaign.php';
function check($condition, $message) { if (!$condition) throw new RuntimeException($message); echo "PASS $message\n"; }
function reset_case(): array {
    $GLOBALS['options'] = [LPS_ACCOUNTING_PRICES_NATIVE_CRON_OPTION => [
        'enabled'=>true, 'automatic_apply_confirmed'=>true, 'warehouse_ids'=>[1,5],
        'weekdays'=>['mon','tue','wed','thu','fri','sat','sun'], 'time'=>'02:02',
    ]];
    $GLOBALS['events'] = []; $GLOBALS['logs'] = [];
    lps_accounting_prices_native_reschedule();
    return ['campaign_id'=>'old', 'active'=>true, 'status'=>'running', 'phase'=>'snapshot_before_poll',
        'snapshot_stage'=>'before', 'current_warehouse_id'=>1, 'warehouse_ids'=>[1,5], 'source'=>'cron'];
}
foreach (['FAILED','INTERRUPTED','ACTIVE'] as $status) {
  foreach (['before', 'after'] as $stage) {
    $state = reset_case(); $next = wp_next_scheduled(LPS_ACCOUNTING_PRICES_NATIVE_CRON_HOOK);
    $state['snapshot_stage'] = $stage;
    $response = ['body'=>['status'=>$status,'running'=>false,'warehouseId'=>1,'generationId'=>12,'error'=>'Snapshot test failure']];
    lps_accounting_price_campaign_poll_snapshot($state);
    if ($status !== 'ACTIVE') {
        check($state['active'] && $state['phase']==='waiting_snapshot_retry', "$status retries snapshot without stopping");
        check($state['retry_at']>=time()+119 && $state['retry_at']<=time()+120, 'Snapshot retry is two minutes');
        lps_accounting_price_campaign_tick();
        check(lps_accounting_price_campaign_state()['phase']==='waiting_snapshot_retry', 'Early polling cannot bypass retry delay');
        $state['retry_at']=time()-1; lps_accounting_price_campaign_store($state);
        lps_accounting_price_campaign_tick();
        check(lps_accounting_price_campaign_state()['phase']==='snapshot_'.$stage.'_start', 'Retry preserves before/after snapshot stage');
        $state['deadline_at']=time()-1;
        lps_accounting_price_campaign_retry_snapshot($state, 'Deadlock victim');
    }
    check(!$state['active'], "$status stops only current snapshot attempt");
    check(lps_accounting_prices_native_cron_options()['enabled'] && !lps_accounting_prices_native_cron_options()['paused_reason'], "$status keeps owner schedule enabled without hold");
    check(wp_next_scheduled(LPS_ACCOUNTING_PRICES_NATIVE_CRON_HOOK) === $next, "$status retains next calendar event");
    $result = lps_accounting_price_campaign_run_scheduled();
    check($result['ok'] && lps_accounting_price_campaign_state()['active'], "$status ($stage) permits next scheduled campaign");
  }
}
$state=reset_case();
$response=['body'=>['status'=>'FAILED','running'=>false,'warehouseId'=>1,'error'=>'Transaction (Process ID 51) was deadlocked on lock resources with another process and has been chosen as the deadlock victim. Rerun the transaction.']];
lps_accounting_price_campaign_poll_snapshot($state);
check($state['active'] && $state['retry_at']>=time()+119, 'Reported SQL Server deadlock retries in two minutes without apply');
$state=reset_case(); $response=['body'=>['status'=>'RUNNING','running'=>true,'warehouseId'=>1]];
lps_accounting_price_campaign_poll_snapshot($state);
check($state['phase']==='snapshot_before_poll' && empty($state['retry_at']), 'Running snapshot is polled, never restarted');
check(LPS_ACCOUNTING_PRICE_CAMPAIGN_LOCK_RETRY_DELAY===120, 'Proven rolled-back write lock retry is also two minutes');
$state = reset_case();
$state['first_poll_error_at'] = time()-7201; $response=['http'=>503];
lps_accounting_price_campaign_poll_snapshot($state);
check(!$state['active'] && lps_accounting_prices_native_cron_options()['enabled'], 'Snapshot outage does not disable future dates');

foreach (['outcome_unknown','failed_partial'] as $status) {
    $state = reset_case(); $state['active']=false; $state['status']=$status;
    $options[LPS_ACCOUNTING_PRICE_CAMPAIGN_OPTION]=$state;
    lps_accounting_prices_native_pause_schedule('Review required');
    check(lps_accounting_prices_native_cron_options()['enabled'], "$status preserves configured enabled flag");
    check(wp_next_scheduled(LPS_ACCOUNTING_PRICES_NATIVE_CRON_HOOK)>time(), "$status preserves calendar");
    unset($events[LPS_ACCOUNTING_PRICES_NATIVE_CRON_HOOK]); // WordPress consumes the current event before its callback.
    check(lps_accounting_price_campaign_run_scheduled()['ok'], "$status permits scheduled campaign");
    check(wp_next_scheduled(LPS_ACCOUNTING_PRICES_NATIVE_CRON_HOOK)>time(), "$status callback schedules the next date");
    $next = lps_accounting_price_campaign_state();
    check($next['warehouse_ids'] === [5] && isset($next['pending_reviews'][1]), 'Only unresolved warehouse is isolated');
    check(!lps_accounting_prices_native_cron_options()['paused_reason'], 'Historical global hold removed after accepted campaign');
    $next['active']=false; $next['status']='completed';
    $options[LPS_ACCOUNTING_PRICE_CAMPAIGN_OPTION]=$next;
    check(lps_accounting_price_campaign_run_scheduled()['ok'], 'Following day also runs');
    $next=lps_accounting_price_campaign_state();
    check($next['warehouse_ids']===[5] && $next['pending_reviews'][1]['campaign_id']==='old', 'Unresolved review survives subsequent successful campaigns');
    $next['active']=false; $options[LPS_ACCOUNTING_PRICE_CAMPAIGN_OPTION]=$next;
    check(!lps_accounting_price_campaign_create([1],'manual','wrong')['ok'], 'Stale review ID rejected');
    check(lps_accounting_price_campaign_public_state($state)['reviewRequired'], "$status exposes manual confirmation in UI");
    check(lps_accounting_price_campaign_create([1],'manual','new-campaign')['ok'], 'Explicit matching manual review accepted');
    check(lps_accounting_price_campaign_state()['reviewed_operation']['campaign_id']==='old', 'Review audit preserved');
    check(!lps_accounting_price_campaign_state()['pending_reviews'], 'Only explicit manual review releases held warehouse');
}
$state=reset_case(); $state['active']=false; $state['status']='outcome_unknown';
$options[LPS_ACCOUNTING_PRICE_CAMPAIGN_OPTION]=$state;
$options[LPS_ACCOUNTING_PRICES_NATIVE_CRON_OPTION]['warehouse_ids']=[1];
check(lps_accounting_price_campaign_run_scheduled()['ok'], 'Calendar attempt completes even when all warehouses require review');
$held=lps_accounting_price_campaign_state();
check(!$held['active'] && $held['status']==='completed_with_warnings' && isset($held['pending_reviews'][1]), 'All-held attempt does no apply and retains review');
check(wp_next_scheduled(LPS_ACCOUNTING_PRICES_NATIVE_CRON_HOOK)>time(), 'All-held attempt retains calendar');
foreach (['failed','snapshot_failed','manual_review','interrupted'] as $status) {
    $state=reset_case(); $state['active']=false; $state['status']=$status;
    $options[LPS_ACCOUNTING_PRICE_CAMPAIGN_OPTION]=$state;
    $options[LPS_ACCOUNTING_PRICES_NATIVE_CRON_OPTION]['paused_reason']='Historical deadlock';
    check(lps_accounting_price_campaign_run_scheduled()['ok'], "$status cannot veto next run");
    check(lps_accounting_price_campaign_state()['warehouse_ids']===[1,5], "$status retries all warehouses with fresh snapshots");
}
$state=reset_case(); $state['current_skus']=['SKU-1'];
$state['active']=false; $state['status']='completed';
$state['pending_reviews']=[1=>['campaign_id'=>'first','warehouse_id'=>1],5=>['campaign_id'=>'second','warehouse_id'=>5]];
$options[LPS_ACCOUNTING_PRICE_CAMPAIGN_OPTION]=$state;
check(lps_accounting_price_campaign_create([1],'manual','old')['ok'], 'Selected warehouse review is accepted');
check(array_keys(lps_accounting_price_campaign_state()['pending_reviews'])===[5], 'Manual review does not clear another warehouse');
$state=reset_case(); $state['current_skus']=['SKU-1'];
lps_accounting_price_campaign_start_range($state,['SKU-1']);
check($state['phase']==='waiting_lock' && $state['active'], 'Busy ecosystem lock waits without POST');
check(wp_next_scheduled(LPS_ACCOUNTING_PRICE_CAMPAIGN_TICK_HOOK)>=time()+299, 'Busy lock is checked again in five minutes');
$state=reset_case(); $options[LPS_ACCOUNTING_PRICE_CAMPAIGN_OPTION]=$state;
check(!lps_accounting_price_campaign_run_scheduled()['ok'], 'No duplicate campaign while active');
check(wp_next_scheduled(LPS_ACCOUNTING_PRICES_NATIVE_CRON_HOOK)>time(), 'Active campaign does not consume future calendar');
$state=reset_case(); $options[LPS_ACCOUNTING_PRICES_NATIVE_CRON_OPTION]['enabled']=false; $events=[];
lps_accounting_price_campaign_end_snapshot_attempt($state);
check(!$events && !$options[LPS_ACCOUNTING_PRICES_NATIVE_CRON_OPTION]['enabled'], 'Operator-disabled schedule is never reenabled');
echo "Failure schedule regression passed\n";
