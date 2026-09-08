<?php
// Proxy contract test. No WordPress bootstrap, credentials or real HTTP requests.
define('ABSPATH', __DIR__);
function add_action(...$args) {}
function current_user_can($cap) { return true; }
function check_ajax_referer(...$args) { return $GLOBALS['nonce_valid'] ?? true; }
function __($s, $domain = '') { return $s; }
function wp_unslash($s) { return $s; }
function sanitize_key($s) { return $s; }
function sanitize_text_field($s) { return $s; }
function get_option($name, $default = []) { return ['java_base_url' => 'https://synthetic.invalid']; }
function add_query_arg($query, $url) { $GLOBALS['query'] = $query; return $url; }
function wp_remote_get(...$args) { $GLOBALS['http_calls']++; return []; }
function is_wp_error($response) { return false; }
function wp_remote_retrieve_response_code($response) { return 200; }
function wp_remote_retrieve_body($response) { return '{"ok":true,"complete":false}'; }
class ProxyResult extends Exception {
    public function __construct(public bool $success, public array $data, public int $status) { parent::__construct(); }
}
function wp_send_json_success($data) { throw new ProxyResult(true, $data, 200); }
function wp_send_json_error($data, $status = 400) { throw new ProxyResult(false, $data, $status); }
require dirname(__DIR__) . '/inc/class-profit-report.php';
$report = new Lavka_Reports_Profit_Report();
function request($params) {
    global $report;
    $_POST = array_merge(['month'=>'2026-07'], $params);
    $GLOBALS['query'] = []; $GLOBALS['http_calls'] = 0;
    try { $report->ajax_report(); } catch (ProxyResult $result) { return $result; }
    throw new RuntimeException('No response');
}
function verify($condition, $message) { if (!$condition) throw new RuntimeException($message); }
foreach (['odesaTaxShare'=>'2', 'rubToUahRate'=>'0', 'odesaAdditionalSalary'=>'-1', 'kyivAdditionalSalary'=>'-1'] as $name=>$value) {
    $r=request([$name=>$value]);
    verify($r->success && $GLOBALS['http_calls']===1 && $GLOBALS['query'][$name]===$value, 'Java must receive semantic range: '.$name);
}
$r=request(['odesaAdditionalSalary'=>'0','kyivAdditionalSalary'=>'']);
verify($r->success && $GLOBALS['query']['odesaAdditionalSalary']==='0' && !isset($GLOBALS['query']['kyivAdditionalSalary']), 'Zero must differ from omitted');
$r=request(['rubToUahRate'=>'abc']);
verify(!$r->success && $r->status===400 && $r->data['field']==='rubToUahRate' && $GLOBALS['http_calls']===0, 'Malformed number must be actionable');
$r=request(['month'=>'2026-13']);
verify(!$r->success && $r->data['field']==='month' && $GLOBALS['http_calls']===0, 'Invalid month must remain rejected');
echo "PASS: ranges delegated, zero/blank preserved, malformed inputs identified without HTTP\n";

$GLOBALS['nonce_valid']=false;
$r=request([]);
verify(!$r->success && $r->status===403 && $r->data['code']==='REPORT_SESSION_EXPIRED' && $GLOBALS['http_calls']===0, 'Expired nonce must remain protected and actionable');
echo "PASS: expired nonce explains reload and does not call Java\n";
