<?php
namespace PaintCore\PCOE {final class BroadcastPricing {static function group($id){return 'fixture';}}}
namespace {
define('ABSPATH',__DIR__);require dirname(__DIR__).'/inc/PartnerCatalog.php';require dirname(__DIR__).'/inc/PartnerCatalogFormats.php';
function __($s,$d=''){return $s;}function absint($v){return abs((int)$v);}function wp_salt($s){return 'test';}function wp_json_encode($v){return json_encode($v);}function nocache_headers(){}function status_header($n){echo 'STATUS:'.$n.':';}function get_bloginfo($s){return 'Fixture';}function home_url($s){return 'https://example.test/';}
function get_userdata($id){return $id===5?(object)['ID'=>$id]:false;}
function pc_wholesale_customer_can_access($u){return ($GLOBALS['case']??'')!=='role-revoked';}
function get_user_meta($id,$key,$single){return ['seed'=>($GLOBALS['case']??'')==='rotated'?'new':'seed','enabled'=>($GLOBALS['case']??'')!=='paused','selection'=>['skus'=>['A'],'category'=>0,'brand'=>0]];}
function get_transient($key){$c=get_user_meta(5,'',true);return ['signature'=>hash('sha256',wp_json_encode([$c['selection'],'fixture'])),'data'=>['currency'=>'UAH','at'=>'2026-11-10T12:00:00Z','categories'=>[1=>['name'=>'Paint','parent'=>0]],'rows'=>[['id'=>1,'sku'=>'A','name'=>'Product','category_id'=>1,'category'=>'Paint','gtin'=>'','description'=>'Description','short_description'=>'','stock'=>3,'retail'=>$GLOBALS['case']==='failure'?null:50,'cost'=>12345.67,'images'=>[],'attributes'=>[]]]]];}
use PaintCore\PCOE\PartnerCatalog as C;
if(isset($argv[1])){$GLOBALS['case']=$argv[1];$_GET=['user'=>$argv[1]==='wrong-owner'?6:5,'key'=>C::key(5,['seed'=>'seed'])];if($argv[1]==='bad-key')$_GET['key']='bad';C::feed();}
$cases=['paused'=>'STATUS:403','bad-key'=>'STATUS:403','rotated'=>'STATUS:403','role-revoked'=>'STATUS:403','wrong-owner'=>'STATUS:403','failure'=>'STATUS:503','valid'=>'<?xml'];
foreach($cases as $case=>$prefix){$out=[];$status=0;exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg(__FILE__).' '.escapeshellarg($case),$out,$status);$result=implode("\n",$out);if($status!==0 || !str_starts_with($result,$prefix) || str_contains($result,'12345.67'))throw new RuntimeException('Failed '.$case);}
echo "PASS 7 feed endpoint access checks\n";
}
