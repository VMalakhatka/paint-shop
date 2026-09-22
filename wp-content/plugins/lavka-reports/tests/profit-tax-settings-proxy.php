<?php
// Isolated capability, validation and API forwarding checks. No live DB or HTTP.
define('ABSPATH',__DIR__);
function add_action(...$args){}
function __($s,$domain=''){return $s;}
function current_user_can($cap){return $GLOBALS['allowed']??true;}
function check_ajax_referer(...$args){return $GLOBALS['nonce']??true;}
function wp_unslash($s){return $s;}
function get_option(...$args){return ['java_base_url'=>'https://synthetic.invalid','api_token'=>'synthetic'];}
function wp_json_encode($v){return json_encode($v);}
function wp_remote_request($url,$args){$GLOBALS['calls'][]=[$url,$args];return [];}
function is_wp_error($v){return false;}
function wp_remote_retrieve_response_code($v){return $GLOBALS['status']??200;}
function wp_remote_retrieve_body($v){return '{"version":1,"retailFirmCodes":[],"wholesaleFirmCodes":[]}';}
class Result extends Exception{function __construct(public bool $ok,public array $data,public int $status){}}
function wp_send_json_success($d){throw new Result(true,$d,200);}
function wp_send_json_error($d,$s){throw new Result(false,$d,$s);}
require dirname(__DIR__).'/inc/class-profit-tax-settings.php';
function request($post){$GLOBALS['calls']=[];$_POST=$post;try{(new Lavka_Reports_Profit_Tax_Settings())->ajax();}catch(Result $r){return $r;}throw new Exception('No result');}
function verify($v,$m){if(!$v)throw new Exception($m);}
$valid=['version'=>0,'retailFirmCodes'=>[' михнфоп ','МАЛАФОП'],'wholesaleFirmCodes'=>['КУЗНФОП','КОНДФОП']];
$r=request(['operation'=>'get']);verify($r->ok&&$GLOBALS['calls'][0][1]['method']==='GET','GET proxy');
$r=request(['operation'=>'save','settings'=>json_encode($valid)]);verify($r->ok,'save');$args=$GLOBALS['calls'][0][1];verify($args['method']==='PUT','PUT');verify(json_decode($args['body'],true)['retailFirmCodes'][0]==='МИХНФОП','normalize');
foreach([['version'=>'0'],['version'=>-1],['retailFirmCodes'=>['']],['retailFirmCodes'=>['A A']],['retailFirmCodes'=>[str_repeat('A',65)]],['retailFirmCodes'=>array_fill(0,101,'A')],['retailFirmCodes'=>['МАЛАФОП','малафоп']],['wholesaleFirmCodes'=>['МАЛАФОП']],['retailFirmCodes'=>['key'=>'A']]] as $change){$r=request(['operation'=>'save','settings'=>json_encode(array_replace($valid,$change))]);verify(!$r->ok&&$r->status===400&&count($GLOBALS['calls'])===0,'reject invalid '.json_encode($change));}
$r=request(['operation'=>'save','settings'=>json_encode(['version'=>1,'retailFirmCodes'=>[],'wholesaleFirmCodes'=>[]])]);verify($r->ok,'empty lists explicit');
$GLOBALS['status']=409;$r=request(['operation'=>'save','settings'=>json_encode($valid)]);verify($r->data['httpStatus']===409,'preserve conflict');
$GLOBALS['allowed']=false;$r=request(['operation'=>'get']);verify($r->status===403&&!$GLOBALS['calls'],'capability');
$GLOBALS['allowed']=true;$GLOBALS['nonce']=false;$r=request(['operation'=>'save','settings'=>json_encode($valid)]);verify($r->status===403&&!$GLOBALS['calls'],'nonce');
echo "PASS tax settings proxy: auth, nonce, validation, normalization, GET/PUT and conflict\n";
