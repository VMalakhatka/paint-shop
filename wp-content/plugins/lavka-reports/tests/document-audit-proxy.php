<?php
// Proxy contract test. No WordPress bootstrap, credentials or real HTTP requests.
define('ABSPATH', __DIR__);
function add_action(...$args) {}
function current_user_can($cap) { return $GLOBALS['allowed'] ?? true; }
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
require dirname(__DIR__) . '/inc/class-document-audit.php';
$report = new Lavka_Reports_Document_Audit();
function request($params) {
    global $report;
    $_POST = array_merge(['dateFrom'=>'2026-07-01','dateTo'=>'2026-07-31'], $params);
    $GLOBALS['query'] = []; $GLOBALS['http_calls'] = 0;
    try { $report->ajax_report(); } catch (ProxyResult $result) { return $result; }
    throw new RuntimeException('No response');
}
function verify($condition, $message) { if (!$condition) throw new RuntimeException($message); }

$r=request(['afterPaymentId'=>'123','upperPaymentId'=>'456','expectedRulesVersion'=>'2026-09-09.1']);
verify($r->success && $GLOBALS['query']['pageSize']===200 && $GLOBALS['query']['expectedRulesVersion']==='2026-09-09.1' && $GLOBALS['query']['upperPaymentId']==='456', 'Pagination and rules version must reach Java');
foreach ([['dateFrom'=>'2026-02-30'],['dateTo'=>'2026-06-30'],['dateTo'=>'2027-07-02'],['dateFrom'=>[]],['upperPaymentId'=>'1 OR 1=1'],['expectedRulesVersion'=>[]]] as $p) {
 $r=request($p); verify(!$r->success && $GLOBALS['http_calls']===0, 'Reject invalid parameters before Java');
}
$GLOBALS['nonce_valid']=false; $r=request([]); verify(!$r->success && $r->status===403 && $GLOBALS['http_calls']===0, 'Nonce required');
$GLOBALS['nonce_valid']=true; $GLOBALS['allowed']=false; $r=request([]); verify(!$r->success && $r->status===403 && $GLOBALS['http_calls']===0, 'Capability required');
echo "PASS: audit access, dates, cursor and rules version proxy checks\n";
