<?php
// Isolated transport checks: no WordPress database, Folio or network.
define('ABSPATH',__DIR__);
function add_action(...$args){}
function __($s,$domain=''){return $s;}
function current_user_can($cap){return $GLOBALS['allowed']??true;}
function check_ajax_referer(...$args){return $GLOBALS['nonce']??true;}
function wp_unslash($s){return $s;}
function get_option(...$args){return ['java_base_url'=>'https://synthetic.invalid'];}
function wp_json_encode($v){return json_encode($v);}
function wp_remote_request($url,$args){$GLOBALS['calls'][]=[$url,$args];return [];}
function is_wp_error($v){return false;}
function wp_remote_retrieve_response_code($v){return $GLOBALS['status']??200;}
function wp_remote_retrieve_body($v){return '{}';}
class Result extends Exception{function __construct(public bool $ok,public array $data,public int $status){}}
function wp_send_json_success($d){throw new Result(true,$d,200);}
function wp_send_json_error($d,$s){throw new Result(false,$d,$s);}
require dirname(__DIR__).'/inc/class-profit-scope-settings.php';
function request($post){$GLOBALS['calls']=[];$_POST=$post;try{(new Lavka_Reports_Profit_Scope_Settings())->ajax();}catch(Result $r){return $r;}throw new Exception('No result');}
function verify($v,$m){if(!$v)throw new Exception($m);}
$valid=['version'=>0,'kyivWarehouseIds'=>[1,7],'odesaWarehouseIds'=>[5],'kyivStockWarehouseIds'=>[1,7,12],'odesaStockWarehouseIds'=>[5],'excludedInventoryGroupIds'=>[],'excludedInventorySubgroupIds'=>['g:WyJBIiwiQiJd']];
foreach(['get'=>'scope-settings','catalog'=>'scope-catalog'] as $operation=>$suffix){$r=request(['operation'=>$operation]);verify($r->ok&&str_ends_with($GLOBALS['calls'][0][0],$suffix)&&$GLOBALS['calls'][0][1]['method']==='GET','read-only endpoint');}
$r=request(['operation'=>'save','settings'=>json_encode($valid)]);verify($r->ok,'save');$args=$GLOBALS['calls'][0][1];verify($args['method']==='PUT'&&json_decode($args['body'],true)===$valid,'PUT exact settings');
foreach([['version'=>'0'],['version'=>-1],['kyivWarehouseIds'=>[]],['kyivWarehouseIds'=>[1,1]],['odesaWarehouseIds'=>[7]],['odesaStockWarehouseIds'=>[12]],['excludedInventoryGroupIds'=>['2']],['excludedInventoryGroupIds'=>[2147483648]],['excludedInventorySubgroupIds'=>null],['excludedInventoryGroupIds'=>['key'=>1]]] as $change){$r=request(['operation'=>'save','settings'=>json_encode(array_replace($valid,$change))]);verify(!$r->ok&&$r->status===400&&!$GLOBALS['calls'],'invalid settings rejected before Java');}
$GLOBALS['status']=409;$r=request(['operation'=>'save','settings'=>json_encode($valid)]);verify($r->data['httpStatus']===409,'preserve CAS conflict');
$GLOBALS['allowed']=false;$r=request(['operation'=>'catalog']);verify($r->status===403&&!$GLOBALS['calls'],'capability');
$GLOBALS['allowed']=true;$GLOBALS['nonce']=false;$r=request(['operation'=>'save','settings'=>json_encode($valid)]);verify($r->status===403&&!$GLOBALS['calls'],'nonce');
echo "PASS scope settings proxy: auth, nonce, validation, catalogs, GET/PUT and conflict\n";
