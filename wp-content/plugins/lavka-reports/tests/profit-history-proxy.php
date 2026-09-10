<?php
// Proxy contract test. No WordPress bootstrap, credentials or real HTTP requests.
define('ABSPATH', __DIR__);
function add_action(...$args) {}
function current_user_can($cap) { return $GLOBALS["allowed"] ?? true; }
function check_ajax_referer(...$args) { return $GLOBALS['nonce_valid'] ?? true; }
function __($s, $domain = '') { return $s; }
function wp_unslash($s) { return $s; }
function sanitize_key($s) { return $s; }
function sanitize_text_field($s) { return $s; }
function get_option($name, $default = []) { return ['java_base_url' => 'https://synthetic.invalid']; }
function add_query_arg($query, $url) { $GLOBALS['query'] = $query; return $url; }
function wp_remote_get($url,$args) { $GLOBALS['http_calls']++; $GLOBALS['verb']='GET'; $GLOBALS['url']=$url; return []; }
function wp_remote_post($url,$args) { $GLOBALS['http_calls']++; $GLOBALS['verb']='POST'; $GLOBALS['body']=json_decode($args['body'],true); return []; }
function wp_json_encode($v) { return json_encode($v); }
function is_wp_error($response) { return false; }
function wp_remote_retrieve_response_code($response) { return 200; }
function wp_remote_retrieve_body($response) { return '{"ok":true,"complete":false}'; }
class ProxyResult extends Exception {
    public function __construct(public bool $success, public array $data, public int $status) { parent::__construct(); }
}
function wp_send_json_success($data) { throw new ProxyResult(true, $data, 200); }
function wp_send_json_error($data, $status = 400) { throw new ProxyResult(false, $data, $status); }
require dirname(__DIR__) . '/inc/class-profit-history.php';
$report = new Lavka_Reports_Profit_History();
function request($params) {
    global $report;
    $_POST = array_merge(['operation'=>'range','fromMonth'=>'2025-07','toMonth'=>'2025-11'], $params);
    $GLOBALS['query'] = []; $GLOBALS['http_calls'] = 0;
    try { $report->ajax(); } catch (ProxyResult $result) { return $result; }
    throw new RuntimeException('No response');
}
function verify($condition, $message) { if (!$condition) throw new RuntimeException($message); }

$r=request([]);verify($r->success && $GLOBALS['verb']==='GET' && str_ends_with($GLOBALS['url'],'/saved'), 'History uses GET saved only');
$r=request(['operation'=>'calculate','month'=>'2025-07','requestId'=>'11111111-2222-3333-4444-555555555555','odesaAdditionalSalary'=>'0','kyivAdditionalSalary'=>'','odesaTaxShare'=>'0.4285714286']);
verify($r->success && $GLOBALS['verb']==='POST' && $GLOBALS['body']['odesaAdditionalSalary']==='0' && !isset($GLOBALS['body']['kyivAdditionalSalary']) && $GLOBALS['body']['odesaTaxShare']==='0.4285714286','POST preserves decimal precision and zero/omission');
foreach ([['fromMonth'=>'2025-13'],['toMonth'=>'2028-01'],['toMonth'=>'2024-01'],['operation'=>[]],['operation'=>'calculate','month'=>'2025-07','requestId'=>'bad'],['operation'=>'month','month'=>'2025-07','revisionId'=>'1/../../']] as $params){$r=request($params);verify(!$r->success && $GLOBALS['http_calls']===0,'Reject invalid request before Java');}
$GLOBALS['nonce_valid']=false;$r=request([]);verify(!$r->success && $r->status===403 && $GLOBALS['http_calls']===0,'Nonce required');
echo "PASS: saved report read/write separation, dates, request IDs and exact decimals\n";

$GLOBALS["nonce_valid"]=true;$GLOBALS["allowed"]=false;$r=request([]);verify(!$r->success && $r->status===403 && $GLOBALS["http_calls"]===0,"Capability required for reads and writes");
