<?php
/** Standalone: fake HTTP/database, synthetic payloads; real bounded pacing clock. */
define('ABSPATH', __DIR__);
define('PNPM_NOVA_POSHTA_API_KEY', 'synthetic-rate-key');
define('PNPM_ALLOW_REAL_TTN', true);
class WP_Error {
    public function __construct(public $code, public $message) {}
    public function get_error_code() { return $this->code; }
}
function __($s, $d = '') { return $s; }
function is_wp_error($v) { return $v instanceof WP_Error; }
function get_option($n, $d = []) { return ['writes_enabled'=>'yes']; }
function esc_url_raw($s) { return $s; }
function wp_json_encode($v) { return json_encode($v); }
function wp_remote_retrieve_response_code($r) { return $r['response']['code']; }
function wp_remote_retrieve_body($r) { return $r['body']; }
$cache=[]; $persist=true; $external=false; $forceReads=0; $calls=0; $responses=[];
function wp_using_ext_object_cache() { return $GLOBALS['external']; }
function wp_cache_get($k, $g, $force) { if ($force) $GLOBALS['forceReads']++; return $GLOBALS['cache'][$k] ?? false; }
function wp_cache_delete($k, $g) { /* DB-backed option cache only, not the stored transient. */ }
function get_transient($k) { return $GLOBALS['cache'][$k] ?? false; }
function set_transient($k, $v, $ttl) { if (!$GLOBALS['persist']) return false; $GLOBALS['cache'][$k]=$v; return true; }
function wp_remote_post($url, $args) {
    $GLOBALS['calls']++;
    if (!$GLOBALS['responses']) throw new RuntimeException('Unexpected transport');
    return array_shift($GLOBALS['responses']);
}
$wpdb=new class {
    public $prefix='fixture_'; public $available=true; public $held=false; public $released=0;
    public function prepare($q, ...$args) { return $q; }
    public function get_var($q) {
        if (str_contains($q,'GET_LOCK')) { $this->held=$this->available; return $this->available?'1':'0'; }
        $this->held=false; $this->released++; return '1';
    }
};
require __DIR__.'/../src/Infrastructure/ApiRequestThrottle.php';
require __DIR__.'/../src/Infrastructure/ApiClient.php';
$checks=0;
$check=static function($ok,$label)use(&$checks){if(!$ok)throw new RuntimeException($label);$checks++;};
$reply=static fn($body,$status=200)=>['response'=>['code'=>$status],'body'=>json_encode($body)];
$ok=$reply(['success'=>true,'data'=>[['Ref'=>'fixture']]]);
$limited=$reply(['success'=>false,'errors'=>['To many requests'],'errorCodes'=>['20000401501']]);
$noWait=new class extends Paint\NovaPoshta\Infrastructure\ApiRequestThrottle {
    public function run(string $key, callable $request) { return $request(); }
};
$api=new Paint\NovaPoshta\Infrastructure\ApiClient($noWait);
foreach ([$limited,$reply(['success'=>false,'errorCodes'=>[20000401501]]),$reply(['success'=>false,'errors'=>['Too many requests']]),$reply([],429)] as $failure) {
    $responses=[$failure,$ok];$start=$calls;
    $check($api->call('Address','getWarehouses')['success']===true && $calls===$start+2,'Allowlisted read retries rate limit once');
}
$responses=[$limited,$limited];$start=$calls;
$check($api->call('Address','getWarehouses')->get_error_code()==='pnpm_api_rate_limited'&&$calls===$start+2,'Repeated limit stops at two attempts');
foreach (['CounterpartyGeneral','InternetDocumentGeneral'] as $model) {
    $responses=[$limited];$start=$calls;
    $check($api->call($model,'save',[],true)->get_error_code()==='pnpm_api_rate_limited'&&$calls===$start+1,'Save never retries rate limit');
    $responses=[new WP_Error('timeout','fixture')];$start=$calls;
    $check($api->call($model,'save',[],true)->get_error_code()==='pnpm_api_unavailable'&&$calls===$start+1,'Save timeout never retried');
}
$responses=[$limited];$start=$calls;
$check($api->call('Unknown','getAnything')->get_error_code()==='pnpm_api_rate_limited'&&$calls===$start+1,'Unknown read not retried');
$responses=[$reply(['success'=>false,'errors'=>['Invalid Ref']])];$start=$calls;
$check($api->call('Address','getWarehouses')['success']===false&&$calls===$start+1,'Validation rejection not retried');
$responses=[new WP_Error('timeout','fixture')];$start=$calls;
$check($api->call('Address','getWarehouses')->get_error_code()==='pnpm_api_unavailable'&&$calls===$start+1,'Transport failure not retried');
$start=$calls;$responses=[];
$check($api->call('InternetDocumentGeneral','save')->get_error_code()==='pnpm_write_disabled'&&$calls===$start,'Write gate precedes pacing and HTTP');

$gate=new Paint\NovaPoshta\Infrastructure\ApiRequestThrottle();
$times=[];
$send=static function()use(&$times,$wpdb){if(!$wpdb->held)throw new RuntimeException('Lock not held during transport');$times[]=microtime(true);return 'ok';};
$check($gate->run('key-a',$send)==='ok','Paced transport succeeds');
$check((new Paint\NovaPoshta\Infrastructure\ApiRequestThrottle())->run('key-a',$send)==='ok','Different instance uses shared timestamp');
$check($times[1]-$times[0]>=1.09,'Requests spaced by at least 1.1 seconds');
$external=true;$before=$forceReads;
$gate->run('key-a',$send);
$check($forceReads===$before+1&&$times[2]-$times[1]>=1.09,'Redis force-read preserves shared reservation');
$start=microtime(true);$gate->run('key-b',$send);
$check(microtime(true)-$start<0.5,'Different key independent');
$wpdb->available=false;$count=count($times);
$check($gate->run('key-a',$send)->get_error_code()==='pnpm_api_busy'&&count($times)===$count,'Lock contention prevents request');
$wpdb->available=true;$persist=false;
$check($gate->run('key-c',$send)->get_error_code()==='pnpm_api_busy'&&count($times)===$count&&!$wpdb->held,'Reservation failure prevents request and releases lock');
$persist=true;
try{$gate->run('key-d',static function(){throw new RuntimeException('fixture');});}catch(RuntimeException $e){}
$check(!$wpdb->held,'Exception releases lock');
$gate->run('key-e',static fn()=>new WP_Error('pnpm_api_rate_limited','fixture'));
$start=microtime(true);$gate->run('key-e',$send);
$check(microtime(true)-$start>=1.99,'Rate limit imposes two-second cooldown');
echo "API pacing: {$checks} checks passed; no real HTTP or database.\n";
