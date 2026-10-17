<?php
/** Actual AJAX handlers with offline API/storage boundaries. No network. */
require __DIR__ . '/executor-safety.php';
require __DIR__ . '/../src/Admin/SettingsPage.php';
class JsonResult extends RuntimeException {
    public function __construct(public bool $success, public array $data) { parent::__construct('JSON'); }
}
$allowed=true; $nonceValid=true; $transients=[];
function current_user_can($cap) { global $allowed; return $allowed && $cap==='manage_pc_checkbox_fiscalization'; }
function check_ajax_referer(...$a) { global $nonceValid; if(!$nonceValid) throw new JsonResult(false,['code'=>'nonce']); }
function get_current_user_id() { return 7; }
function set_transient($k,$v,$ttl) { $GLOBALS['transients'][$k]=$v; }
function get_transient($k) { return $GLOBALS['transients'][$k]??false; }
function delete_transient($k) { unset($GLOBALS['transients'][$k]); }
function wp_unslash($v) { return $v; }
function __($s,$d='') { return $s; }
function wp_send_json_error($v,$status=400) { throw new JsonResult(false,$v); }
function wp_send_json_success($v) { throw new JsonResult(true,$v); }
function invoke($page,$method) { try {$page->$method();} catch(JsonResult $e) {return $e;} throw new RuntimeException('Missing JSON'); }
$page=new \Paint\CheckboxFiscalization\Admin\SettingsPage(new \Paint\CheckboxFiscalization\Config(),$service,$repository,$manager);
$wpdb->deny=false; $options=[]; $api->shift='CLOSED'; $api->resolved='OPENED'; $opens=$api->opens; $checks=0;
$allowed=false;
check(!invoke($page,'shiftPreview')->success && !invoke($page,'shiftOpen')->success, 'capability required for both handlers');
$allowed=true; $nonceValid=false;
check(invoke($page,'shiftOpen')->data['code']==='nonce' && $api->opens===$opens, 'invalid nonce cannot open');
$nonceValid=true; $_POST=[];
check(!invoke($page,'shiftOpen')->success && $api->opens===$opens, 'opening requires preview');
$preview=invoke($page,'shiftPreview');
check($preview->success && !isset($preview->data['fingerprint']) && $api->opens===$opens, 'preview produces ticket without opening');
$_POST=['ticket'=>['bad']];
check(!invoke($page,'shiftOpen')->success && $api->opens===$opens, 'non-scalar ticket rejected');
$_POST=['ticket'=>$preview->data['ticket']];
check(invoke($page,'shiftOpen')->success && $api->opens===$opens+1, 'explicit confirmation opens');
check(!invoke($page,'shiftOpen')->success && $api->opens===$opens+1, 'consumed ticket cannot replay');
echo "PASS: $checks admin shift checks\n";
