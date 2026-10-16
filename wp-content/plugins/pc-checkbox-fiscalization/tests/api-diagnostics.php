<?php
if (PHP_SAPI !== 'cli') exit;
define('ABSPATH', __DIR__.'/'); define('PCCF_VERSION','fixture');
define('PC_CHECKBOX_LICENSE_KEY','fixture-license'); define('PC_CHECKBOX_CASHIER_PIN','fixture-pin');
function get_option($k,$d=[]) { return $d; }
function wp_parse_args($a,$d) { return array_merge($d,$a); }
function wp_strip_all_tags($s) { return strip_tags($s); }
function wp_json_encode($v,$f=0) { return json_encode($v,$f); }
function sanitize_key($s) { return preg_replace('/[^a-z0-9_-]/','',strtolower($s)); }
function is_wp_error($v) { return false; }
function wp_remote_request($url,$args) {
    $GLOBALS['calls'][]=[$url,$args['method']];
    return ['http'=>400,'body'=>json_encode($GLOBALS['response'])];
}
function wp_remote_retrieve_response_code($r) { return $r['http']; }
function wp_remote_retrieve_body($r) { return $r['body']; }
require __DIR__.'/../src/Config.php'; require __DIR__.'/../src/Infrastructure/ApiClient.php';
$api=new \Paint\CheckboxFiscalization\Infrastructure\ApiClient(new \Paint\CheckboxFiscalization\Config());
$response=['code'=>'base:bad_request','message'=>'<b>Signing service unavailable</b> fixture-license fixture-pin fixture-token buyer@example.test +380501234567 https://example.test/?secret=hidden'];
$r=$api->openShift('fixture-token','fixture-id'); $checks=0;
function check($v,$s){global $checks;if(!$v)throw new RuntimeException($s);$checks++;}
check($r['http_code']===400 && $r['error_code']==='basebad_request','HTTP and provider code retained');
check(str_contains($r['error_message'],'Signing service unavailable'),'reason preserved');
foreach(['fixture-license','fixture-pin','fixture-token','buyer@example.test','380501234567','secret=hidden','<b>'] as $v){check(!str_contains($r['error_message'],$v),'redacted '.$v);}
$response=['detail'=>[['msg'=>'Invalid shift identifier','input'=>'do-not-store','ctx'=>['secret'=>'never-store']]]];
$r=$api->openShift('fixture-token','fixture-id');
check($r['error_message']==='Invalid shift identifier','validation text without raw inputs');
echo "PASS: $checks API diagnostic checks\n";
