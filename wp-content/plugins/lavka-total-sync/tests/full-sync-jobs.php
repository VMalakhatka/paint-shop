<?php
/** Offline protocol tests. No WordPress database or network is used. */
define('ABSPATH', __DIR__ . '/');
define('MINUTE_IN_SECONDS', 60); define('HOUR_IN_SECONDS', 3600); define('DAY_IN_SECONDS', 86400);
$options = []; $scheduled = []; $http = []; $requests = []; $logs = []; $actions = [];
function add_action($name, $callback, ...$rest) { $GLOBALS['actions'][$name][] = $callback; }
function add_filter(...$args) {}
function __($text, ...$args) { return isset($GLOBALS['mo']) ? $GLOBALS['mo']->translate($text) : $text; }
function esc_html($text) { return htmlspecialchars((string)$text, ENT_QUOTES); }
function esc_attr($text) { return esc_html($text); }
function esc_html_e($text, ...$args) { echo esc_html(__($text)); }
function get_option($key, $fallback = false) { return $GLOBALS['options'][$key] ?? $fallback; }
function add_option($key, $value, ...$rest) { if (isset($GLOBALS['options'][$key])) return false; $GLOBALS['options'][$key]=$value; return true; }
function update_option($key, $value, ...$rest) { $GLOBALS['options'][$key]=$value; return true; }
function delete_option($key) {
    if (($GLOBALS['fail_delete'] ?? '') === $key) return false;
    $had=isset($GLOBALS['options'][$key]); unset($GLOBALS['options'][$key]); return $had;
}
function maybe_serialize($value) { return serialize($value); }
function wp_cache_delete(...$args) {}
function wp_generate_uuid4() { static $id=0; return sprintf('00000000-0000-4000-8000-%012d', ++$id); }
function wp_next_scheduled($hook) { return $GLOBALS['scheduled'][$hook] ?? false; }
function wp_schedule_single_event($at, $hook) { $GLOBALS['scheduled'][$hook]=$at; }
function wp_clear_scheduled_hook($hook) { unset($GLOBALS['scheduled'][$hook]); }
function sanitize_key($value) { return $value; }
function sanitize_text_field($value) { return $value; }
function get_current_user_id() { return 1; }
function current_time($type, ...$rest) { return date('Y-m-d H:i:s'); }
function apply_filters($name, $value, ...$rest) { return $value; }
function wp_json_encode($value, ...$rest) { return json_encode($value); }
function lts_get_options() { return ['java_base_url'=>'https://synthetic.invalid','api_token'=>'synthetic-test-only']; }
function lts_normalize_cursor_after($cursor) { return trim((string)$cursor) ?: '!'; }
function lts_ecosystem_lock_release($token) { lavka_ecosystem_lock_release($token); }
function lts_log_db($level, $action, $data) { $GLOBALS['logs'][]=[$level,$action,$data]; }
class WP_Error {}
function is_wp_error($res) { return $res instanceof WP_Error; }
function wp_remote_retrieve_response_code($res) { return $res['http']; }
function wp_remote_retrieve_body($res) { return json_encode($res['data']); }
function wp_remote_request($url, $args) {
    if ($args['timeout'] !== 10) throw new RuntimeException('Long HTTP wait');
    $GLOBALS['requests'][]=[$url,$args];
    if (!$GLOBALS['http']) throw new RuntimeException('Unexpected network request: '.$url);
    return array_shift($GLOBALS['http']);
}
$wpdb = new class {
    public $options='wp_options', $prefix='wp_', $insert_id=1;
    function prepare($sql, ...$args) { return [$sql,$args]; }
    function get_var($prepared) { return isset($GLOBALS['options'][$prepared[1][0]]) ? serialize($GLOBALS['options'][$prepared[1][0]]) : null; }
    function query($prepared) {
        [$sql,$args]=$prepared;
        if (($GLOBALS['options'][$args[0]] ?? null) !== unserialize($args[1])) return 0;
        unset($GLOBALS['options'][$args[0]]); return 1;
    }
    function insert(...$args) { return 1; }
};
require dirname(__DIR__, 3).'/mu-plugins/lavka-ecosystem-lock.php';
require dirname(__DIR__).'/inc/full-sync-jobs.php';
if (in_array('--render-panel', $argv, true)) {
    require (getenv('WP_TEST_ROOT') ?: dirname(__DIR__, 4)).'/wp-includes/pomo/mo.php';
    $mo=new MO(); $mo->import_from_file(dirname(__DIR__).'/languages/lavka-total-sync-uk.mo');
    lts_full_job_render_panel();
    exit;
}
function check($ok, $message) { if (!$ok) throw new RuntimeException($message); $GLOBALS['checks']++; }
function reset_test() { $GLOBALS['options']=[]; $GLOBALS['scheduled']=[]; $GLOBALS['http']=[]; $GLOBALS['requests']=[]; $GLOBALS['logs']=[]; }
function enqueue() { return lts_full_job_enqueue(['limit'=>900000,'pageSizeWoo'=>200,'cursorAfter'=>'!','dryRun'=>false], 'cron'); }
function cap() { return ['http'=>200,'data'=>['protocol'=>1,'persistent'=>true,'activeJobId'=>'']]; }
function absent() { return ['http'=>404,'data'=>['error'=>'JOB_NOT_FOUND','protocol'=>1]]; }
function status_response($status='RUNNING', $safe=false) {
    $job=get_option(LTS_FULL_JOB_OPTION);
    return ['http'=>200,'data'=>$job['request']+['protocol'=>1,'jobId'=>$job['jobId'],'status'=>$status,'safeToRelease'=>$safe,
        'chunks'=>1,'processed'=>50,'created'=>0,'updated'=>49,'drafted'=>0,'errorCount'=>0,'nextAfter'=>'P-50','errors'=>[]]];
}
$checks=0;
reset_test(); $first=enqueue(); $second=enqueue();
check($first['job']['jobId']===$second['job']['jobId'], 'Duplicate enqueue created a second job');
check(count($requests)===0, 'Enqueue must not wait for Java');
check(get_option(LTS_FULL_JOB_OPTION)['request']['pageSizeWoo']===100, 'Chunk cap missing');
check(!isset($first['job']['request']), 'Private request leaked');
$http=[cap(),absent(),new WP_Error]; lts_full_job_tick();
$started=get_option(LTS_FULL_JOB_OPTION); $token=$started['lock_token'];
check($started['status']==='STARTING', 'Unknown acceptance must not become a failure');
check(lavka_ecosystem_lock_get()['token']===$token, 'Timeout released lock');
$lock=lavka_ecosystem_lock_get(); $lock['expires_at']=time()-86400; update_option(LAVKA_ECOSYSTEM_LOCK_OPTION,$lock);
check(!lavka_ecosystem_lock_is_stale(), 'Expired timestamp invalidated protected lease');
check(!lavka_ecosystem_lock_release($token), 'Unconfirmed job lock was released');
$http=[status_response()]; lts_full_job_tick();
check(get_option(LTS_FULL_JOB_OPTION)['accepted']===true, 'Did not reconcile accepted job');
check(count(array_filter($requests,fn($r)=>$r[1]['method']==='POST'))===1, 'Blindly repeated start after lost response');
$http=[absent()]; lts_full_job_tick();
check(get_option(LTS_FULL_JOB_OPTION)['status']==='OUTCOME_UNKNOWN', 'Accepted missing job was replayed');
check(lavka_ecosystem_lock_get()!==null, 'Accepted missing job released lease');
$http=[status_response('COMPLETED',true)]; lts_full_job_tick();
check(lavka_ecosystem_lock_get()===null, 'Confirmed completion did not release lease');
check(!wp_next_scheduled(LTS_FULL_JOB_TICK), 'Terminal poll was not cleared');
check(enqueue()['job']['jobId']!==$first['job']['jobId'], 'Future schedule blocked after completion');

reset_test(); enqueue();
$job=get_option(LTS_FULL_JOB_OPTION);
$lock=lavka_ecosystem_lock_acquire('lavka-total-sync','product_fields_job','cron','test',900,['full_sync_job_id'=>$job['jobId']]);
$http=[cap(),absent(),new WP_Error]; lts_full_job_tick();
check(get_option(LTS_FULL_JOB_OPTION)['lock_token']===$lock['token'], 'Crash before token save cannot be recovered');
$http=[absent(),new WP_Error]; lts_full_job_tick();
$posts=array_values(array_filter($requests,fn($r)=>$r[1]['method']==='POST'));
check($posts[0][1]['body']===$posts[1][1]['body'], 'Acceptance retry changed id or payload');

reset_test(); enqueue();
lavka_ecosystem_lock_acquire('other','stock','cron','Other job',900);
$http=[cap()]; lts_full_job_tick();
check(get_option(LTS_FULL_JOB_OPTION)['status']==='WAITING_QUEUE', 'Busy ecosystem reported failure');
check(count($requests)===1, 'Started Java while other process held lock');
check(wp_next_scheduled(LTS_FULL_JOB_TICK)!==false, 'Queue has no future retry');

reset_test(); enqueue(); $http=[['http'=>200,'data'=>['protocol'=>0]]]; lts_full_job_tick();
check(lavka_ecosystem_lock_get()===null, 'Old Java caused protected lock');
check(get_option(LTS_FULL_JOB_OPTION)['poll_error']==='JOB_API_UNAVAILABLE_OR_INCOMPATIBLE','Incompatible Java is not visible');

reset_test(); enqueue(); $http=[cap(),absent(),new WP_Error]; lts_full_job_tick();
$bad=status_response('COMPLETED',true); $bad['data']['jobId']='wrong-id'; $http=[$bad]; lts_full_job_tick();
check(lavka_ecosystem_lock_get()!==null,'Foreign job completion released lock');
$bad=status_response('COMPLETED',true); $bad['data']['limit']=1; $http=[$bad]; lts_full_job_tick();
check(lavka_ecosystem_lock_get()!==null,'Different payload completion released lock');
$bad=status_response('COMPLETED','true'); $http=[$bad]; lts_full_job_tick();
check(lavka_ecosystem_lock_get()!==null,'Non-boolean proof released lock');
$http=[status_response('COMPLETED_WITH_ERRORS',true)]; lts_full_job_tick();
check(lavka_ecosystem_lock_get()===null,'Known terminal errors blocked future jobs');
check(!str_contains(json_encode($logs), 'lock_token'),'Lock token exposed in task logs');

reset_test(); $old=enqueue(); $http=[cap(),absent(),new WP_Error]; lts_full_job_tick();
$fail_delete=LAVKA_ECOSYSTEM_LOCK_OPTION;
$http=[status_response('COMPLETED',true)]; lts_full_job_tick();
check(wp_next_scheduled(LTS_FULL_JOB_TICK)!==false,'Failed lock release lost recovery tick');
check(enqueue()['job']['jobId']===$old['job']['jobId'],'Terminal proof overwritten before lock release');
$fail_delete=''; lts_full_job_tick();
check(lavka_ecosystem_lock_get()===null,'Release retry did not recover');
check(enqueue()['job']['jobId']!==$old['job']['jobId'],'Recovered terminal prevented next job');
$claim=lts_full_job_claim();
update_option('lts_full_sync_control_claim',['token'=>'new-controller','time'=>time()]);
try { lts_full_job_save(get_option(LTS_FULL_JOB_OPTION)); check(false,'Stale controller saved progress'); }
catch (RuntimeException $error) { check($error->getMessage()==='JOB_CONTROLLER_LEASE_LOST','Unexpected controller failure'); }
lts_full_job_unclaim($claim);
check(get_option('lts_full_sync_control_claim')['token']==='new-controller','Old controller deleted a newer claim');
echo "PASS: $checks full-sync protocol assertions\n";
