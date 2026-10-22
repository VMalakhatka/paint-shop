<?php
define('ABSPATH',__DIR__);define('LPS_CAP','manage_lavka_prices');
$actions=[];$options=[];$calls=[];$cap=true;$nonce=true;$owner=7;$released=0;$throwTransport=false;
function add_action($n,$f){$GLOBALS['actions'][$n]=$f;}
function __($s,$d){return $s;}
function current_user_can($c){return $GLOBALS['cap'];}
function get_current_user_id(){return $GLOBALS['owner'];}
function check_ajax_referer(...$a){if(!$GLOBALS['nonce'])throw new RuntimeException('BAD_NONCE');}
function wp_send_json_error($d,$s=400){throw new RuntimeException('RESPONSE_ERROR');}
function wp_send_json_success($d){$GLOBALS['response']=$d;}
function get_option($n,$default=false){return $GLOBALS['options'][$n]??$default;}
function update_option($n,$v,...$a){$GLOBALS['options'][$n]=$v;return true;}
function add_option($n,$v,...$a){if(isset($GLOBALS['options'][$n]))return false;$GLOBALS['options'][$n]=$v;return true;}
function lavka_ecosystem_lock_acquire(...$a){return ['ok'=>true,'token'=>'lock'];}
function lavka_ecosystem_lock_release($t){$GLOBALS['released']++;}
function lps_java_post($path,$body,...$a){$GLOBALS['calls'][]=[$path,$body];if($GLOBALS['throwTransport'])throw new RuntimeException('network');return $GLOBALS['remote'];}
function is_wp_error($x){return false;}
function wp_remote_retrieve_body($r){return json_encode($r);}
function wp_remote_retrieve_response_code($r){return 200;}
require __DIR__.'/../inc/folio-currency.php';
$id='11111111-1111-1111-1111-111111111111';
$base=['token'=>'backend-token','sourceDatabase'=>'Paint_Ua','status'=>'PREVIEW','input'=>[],'before'=>[]];
function resetJob(){global $options,$id,$base;$options=['lps_currency_job_'.$id=>['id'=>$id,'user'=>7,'status'=>'PREVIEW','result'=>$base]];}
function check($x){if(!$x)throw new RuntimeException('Assertion failed');}
function invoke(){($GLOBALS['actions']['wp_ajax_lps_currency'])();}
function denied(){try{invoke();}catch(RuntimeException $e){return;}throw new RuntimeException('Expected denial');}
$_POST=['operation'=>'apply','job'=>$id,'confirmed'=>'1'];resetJob();
$cap=false;denied();$cap=true;$nonce=false;denied();$nonce=true;$owner=8;denied();$owner=7;
$_POST['confirmed']='0';denied();$_POST['confirmed']='1';check(count($calls)===0);
$remote=$base;$remote['status']='APPLIED';invoke();check(count($calls)===1);check($response['status']==='APPLIED');check(!isset($response['token']));invoke();check(count($calls)===1);check($released===1);
resetJob();$throwTransport=true;invoke();check($response['status']==='UNKNOWN');$count=count($calls);invoke();check(count($calls)===$count);check($released===1);
$_POST['operation']='status';$throwTransport=false;$remote['status']='APPLIED';invoke();check($response['status']==='APPLIED');
resetJob();$remote['sourceDatabase']='Paint_Rus';$_POST['operation']='status';invoke();check($response['status']==='EXPIRED');
echo "PASS: capability, nonce, ownership, explicit confirmation, durable single use, transport unknown and status recovery\n";
