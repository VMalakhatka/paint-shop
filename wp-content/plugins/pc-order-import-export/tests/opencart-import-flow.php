<?php
namespace PaintCore\PCOE { class FolioCustomerImport {static function username($email){return 'test-'.substr(hash('sha256',$email),0,18);}} }
namespace {
define('ABSPATH',__DIR__);define('WP_CLI',true);
require dirname(__DIR__,3).'/vendor/autoload.php';
require dirname(__DIR__).'/inc/OpenCartCustomerImport.php';
use PaintCore\PCOE\OpenCartCustomerImport as I;
class WP_CLI {static function log($s){}}
function is_email($v){return filter_var($v,FILTER_VALIDATE_EMAIL);}
function wp_json_encode($v){return json_encode($v);}
$GLOBALS['options']=[];$GLOBALS['users']=[];$GLOBALS['meta']=[];$GLOBALS['mails']=0;$GLOBALS['locks']=0;
function email_exists($email){foreach($GLOBALS['users'] as $id=>$u)if($u->user_email===$email)return $id;return false;}
function get_role($r){return (object)['capabilities'=>['read'=>true]];}
function get_option($k,$d=false){return $GLOBALS['options'][$k]??$d;}
function update_option($k,$v,...$a){$GLOBALS['options'][$k]=$v;}
function wp_cache_delete(...$a){}function add_filter(...$a){}function remove_filter(...$a){}
function wp_generate_password(...$a){return 'fixture-password';}function wp_slash($s){return $s;}
function lavka_ecosystem_lock_acquire(...$a){$GLOBALS['locks']++;return ['ok'=>true,'token'=>'fixture'];}
function lavka_ecosystem_lock_release(...$a){$GLOBALS['locks']--;}
function wp_insert_user($r){$id=count($GLOBALS['users'])+1;$GLOBALS['users'][$id]=(object)['ID'=>$id,'user_email'=>$r['user_email'],'roles'=>[$r['role']]];return $id;}
function is_wp_error($v){return false;}
function update_user_meta($id,$k,$v){$GLOBALS['meta'][$id][$k]=$v;}
function get_user_meta($id,$k,...$a){return $GLOBALS['meta'][$id][$k]??'';}
function get_userdata($id){return $GLOBALS['users'][$id]??false;}
function get_password_reset_key($u){return 'fixture-reset';}
function network_site_url($path,...$a){return 'https://example.test/'.$path;}
function wp_mail(...$a){$GLOBALS['mails']++;return true;}
$path=$argv[1]??'';$rows=I::read($path);
$GLOBALS['users'][1]=(object)['ID'=>1,'user_email'=>$rows[0]['email'],'roles'=>['administrator']];
$preview=I::run($path);if($preview['existing']!==1 || $GLOBALS['mails'])throw new \RuntimeException('Preview changed data');
$result=I::run($path,true,true);
if(($result['status']['created']??0)!==count($rows)-1 || $result['status']['skipped']!==1)throw new \RuntimeException('Counts');
if($GLOBALS['users'][1]->roles!==['administrator'])throw new \RuntimeException('Existing role overwritten');
$before=$GLOBALS['mails'];I::run($path,true,true);
if($GLOBALS['mails']!==$before || $GLOBALS['locks']!==0)throw new \RuntimeException('Retry or lock failure');
foreach($GLOBALS['users'] as $id=>$u) if($u->roles===['customer'] && get_user_meta($id,'_folio_partner_id')!=='ИНЕТ КЛ')throw new \RuntimeException('Retail Folio mapping');
echo 'PASS real XLSX validation, dry run, '.($before).' mocked creations/invitations, existing account protection and repeat prevention'.PHP_EOL;
}
