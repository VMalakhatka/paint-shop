<?php
/** Offline gateway failure tests. HTTP is stubbed; no purchases or account writes. */
if (PHP_SAPI !== 'cli') exit;
class WP_Error { public $errors=[]; public function add($k,$v){$this->errors[$k]=$v;} }
function __($v,$d=''){return $GLOBALS['translations'][$v]??$v;}
function esc_html__($v,$d=''){return htmlspecialchars(__($v,$d),ENT_QUOTES);}
function esc_url($v){return htmlspecialchars($v,ENT_QUOTES);}
function is_wp_error($v){return $v instanceof WP_Error;}
function wp_json_encode($v){return json_encode($v);}
function add_action(...$v){$GLOBALS['hooks'][]=$v;}
function add_filter(...$v){$GLOBALS['hooks'][]=$v;}
function remove_action(...$v){$GLOBALS['removed'][]=$v;}
function get_option($k,$default=[]){return ['merchant_account'=>'fixture','secret_key'=>$GLOBALS['secret']??'fixture-secret'];}
function get_transient($k){return $GLOBALS['cache'][$k]??false;}
function set_transient($k,$v,$ttl){$GLOBALS['cache'][$k]=$v;$GLOBALS['ttl']=$ttl;}
function wp_remote_post($url,$options){$GLOBALS['requests'][]=[$url,$options];return $GLOBALS['response'];}
function wp_remote_retrieve_response_code($v){return $v['http'];}
function wp_remote_retrieve_body($v){return $v['body'];}
function is_admin(){return $GLOBALS['admin']??false;}
function wp_doing_ajax(){return false;}
function wc_add_notice($text,$type){$GLOBALS['notices'][]=[$text,$type];}
function wc_print_notice($text,$type){echo '<div role="alert" class="woocommerce-'.$type.'">'.htmlspecialchars($text,ENT_QUOTES).'</div>';}
class WC_Order {public function get_checkout_payment_url(){return 'https://example.test/order-pay/7/?key=fixture';}}
class FixtureGateways {public function payment_gateways(){return ['wayforpay'=>$GLOBALS['gateway']];}}
class FixtureWoo {public function payment_gateways(){return new FixtureGateways();}}
function WC(){return new FixtureWoo();}
function wc_get_order($id){return new WC_Order();}
require __DIR__.'/../src/Availability.php';
use Paint\WayForPayCheckbox\Availability;
$checks=0;
function check($ok,$label){global $checks;if(!$ok)throw new RuntimeException($label);$checks++;}
function response($code,$http=200){$GLOBALS['cache']=[];$GLOBALS['response']=['http'=>$http,'body'=>json_encode(['reasonCode'=>$code,'transactionList'=>[['private'=>'never retain']]])];return new Availability();}
$GLOBALS['requests']=[];$GLOBALS['gateway']=new stdClass();
$gate=new Availability();$gate->boot();
check(count($GLOBALS['hooks'])===6,'checkout, pay and legacy receipt protected');
$gate=response(1118);
$methods=['wayforpay'=>new stdClass(),'bacs'=>new stdClass(),'cod'=>new stdClass()];
$result=$gate->gateways($methods);
check(!isset($result['wayforpay'])&&isset($result['bacs'],$result['cod']),'merchant restriction removes only WFP');
ob_start();$gate->notice();$notice=ob_get_clean();
check(str_contains($notice,'temporarily unavailable')&&!str_contains($notice,'1118'),'customer sees clear nontechnical explanation');
$errors=new WP_Error();$gate->validate(['payment_method'=>'wayforpay'],$errors);
check(isset($errors->errors['pc_wfp_unavailable']),'stale checkout submission rejected with explanation');
$errors=new WP_Error();$gate->validate(['payment_method'=>'bacs'],$errors);
check(!$errors->errors,'other payment method not blocked');
$_POST=['payment_method'=>'wayforpay'];$gate->validatePay(new WC_Order());
check(str_contains($GLOBALS['notices'][0][0],'temporarily unavailable'),'existing order payment POST explained');
ob_start();$gate->receipt(7);$html=ob_get_clean();
check($GLOBALS['removed'][0][0]==='woocommerce_receipt_wayforpay','legacy automatic submission handler removed');
check(!str_contains($html,'<form')&&str_contains($html,'Choose another payment method'),'blocked receipt has no submit form and offers safe navigation');
check(count($GLOBALS['requests'])===1,'same checkout request checks service only once');
(new Availability())->status();check(count($GLOBALS['requests'])===1,'cached health shared by requests');
$request=$GLOBALS['requests'][0];$body=json_decode($request[1]['body'],true);
check($request[0]==='https://api.wayforpay.com/api'&&$body['transactionType']==='TRANSACTION_LIST','probe cannot create a payment');
check($body['dateEnd']-$body['dateBegin']===1&&$request[1]['timeout']===3&&$request[1]['sslverify']&&$request[1]['redirection']===0,'minimal window, bounded timeout, verified TLS and no redirects');
check(hash_equals(hash_hmac('md5','fixture;'.$body['dateBegin'].';'.$body['dateEnd'],'fixture-secret'),$body['merchantSignature']),'request correctly signed');
check(!str_contains(json_encode($GLOBALS['cache']),'never retain')&&!str_contains(json_encode($GLOBALS['cache']),'fixture-secret'),'cache retains no transactions or secrets');
check($GLOBALS['ttl']===30,'failure checked again after short cache');
$gate=response(1100);check(count($gate->gateways($methods))===3,'recovered service restores WFP');
$removedCount=count($GLOBALS['removed']);ob_start();$gate->receipt(7);$empty=ob_get_clean();
check($empty===''&&count($GLOBALS['removed'])===$removedCount,'healthy legacy form unaffected');
check($GLOBALS['ttl']===120,'successful probe briefly cached');
$GLOBALS['secret']='rotated';(new Availability())->status();
check(count($GLOBALS['requests'])===3,'credential rotation invalidates cache');
foreach([['http'=>503,'body'=>'bad'],['http'=>200,'body'=>'invalid json'],['http'=>200,'body'=>'{"reasonCode":[]}'],new WP_Error()] as $failure){
 $GLOBALS['cache']=[];$GLOBALS['response']=$failure;
 check(!(new Availability())->status()['available'],'transport/malformed result stops automatic payment launch');
}
$gate=response(1118);$GLOBALS['admin']=true;$n=count($GLOBALS['requests']);
check(count($gate->gateways($methods))===3&&count($GLOBALS['requests'])===$n,'admin gateway editor unchanged and no probe');
echo "PASS: $checks availability checks\n";
if(in_array('--html',$argv,true)){
 if(isset($argv[2]))$GLOBALS['translations']=json_decode(file_get_contents($argv[2]),true);
 ob_start();response(1118)->receipt(7);$html=ob_get_clean();
 echo '<!doctype html><html><meta name="viewport" content="width=device-width, initial-scale=1"><style>body{font:18px system-ui;margin:24px;line-height:1.6}.woocommerce-error,.woocommerce-notice{padding:20px;border-left:4px solid #b46a00;background:#fff4df}.button{display:inline-block;padding:12px;background:#eee;max-width:100%;box-sizing:border-box}</style><main>'.$html.'</main></html>';
}
