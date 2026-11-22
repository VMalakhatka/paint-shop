<?php
/** Offline lifecycle + formatter + no-login endpoint checks. No production or external services. */
namespace PaintCore\PCOE {
final class PartnerCatalog {static function allowed($u){return $u===5 && empty($GLOBALS['revoked']);}static function url(){return 'https://example.test/my-account/';}}
final class CrmCatalogData {
 const BATCH=100;const MAX_PRODUCTS=30000;
 static function context($u,$s){return $GLOBALS['context']??'context-a';}
 static function page($s,$after){$end=min($after+100,$GLOBALS['total']??605);return $after<$end?range($after+1,$end):[];}
 static function rows($u,$ids,$s){
  if(in_array($GLOBALS['read_failure_id']??0,$ids,true))throw new \RuntimeException('Fixture catalogue read failure');
  $rows=[];foreach($ids as $id)$rows[]=['id'=>$id,'sku'=>'SKU-'.$id,'gtin'=>'0012345678905','name'=>'Paint & colour','category_id'=>2,'description'=>'<text>','short_description'=>'','brand'=>'Brand','retail'=>99.99,'cost'=>(array_key_exists($id,$GLOBALS['costs']??[])?$GLOBALS['costs'][$id]:12.34),'stock'=>($id%2?3:null),'images'=>['https://images.example.test/1.jpg'],'url'=>'https://example.test/product/'.$id,'attributes'=>['Colour'=>'Blue'],'unit'=>'ml','pack'=>null];return ['rows'=>$rows,'categories'=>[2=>['name'=>'Paint','parent'=>0]],'at'=>gmdate('c'),'currency'=>'UAH'];}
}
final class BroadcastStore {static function temporary_file(){return tempnam(sys_get_temp_dir(),'crm-test-');}}
}
namespace {
define('ABSPATH','/nonexistent/test-site/');define('DB_NAME','fixture');
foreach(['PartnerCatalogFormats','CrmCatalogFormat','CrmCatalogStore','CrmCatalog'] as $class)require dirname(__DIR__).'/inc/'.$class.'.php';
use PaintCore\PCOE\CrmCatalog as C;use PaintCore\PCOE\CrmCatalogStore as S;use PaintCore\PCOE\CrmCatalogFormat as F;use PaintCore\PCOE\CrmCatalogData as D;
class DB {public $prefix='wp_';function prepare($q,...$args){return $q;}function get_var($q){return empty($GLOBALS['owner_busy'])?'1':'0';}}
$GLOBALS['wpdb']=new DB();$GLOBALS['options']=[];$GLOBALS['meta']=[];$GLOBALS['events']=[];
function __($s,$d=''){return $s;}function get_option($k,$d=false){return $GLOBALS['options'][$k]??$d;}function update_option($k,$v,$autoload=null){$GLOBALS['options'][$k]=$v;return true;}function delete_option($k){unset($GLOBALS['options'][$k]);}
function get_user_meta($u,$k,$single){return $GLOBALS['meta'][$u][$k]??[];}function update_user_meta($u,$k,$v){$GLOBALS['meta'][$u][$k]=$v;return true;}function wp_slash($x){return $x;}
function get_woocommerce_currency(){return 'UAH';}function get_bloginfo($k){return 'Test shop';}function home_url($p){return 'https://example.test'.$p;}function wp_salt($k){return 'test-secret';}
function wp_next_scheduled($h,$args){return $GLOBALS['events'][$args[0]]??false;}function wp_schedule_single_event($at,$h,$args){$GLOBALS['events'][$args[0]]=$at;return true;}function wp_unschedule_event($at,$h,$args){unset($GLOBALS['events'][$args[0]]);}function wp_clear_scheduled_hook($h,$args){unset($GLOBALS['events'][$args[0]]);}
function wp_cache_supports($f){return false;}function nocache_headers(){}function absint($v){return abs((int)$v);}function status_header($s){$GLOBALS['status']=$s;}
function ok($v,$reason){if(!$v)throw new \RuntimeException($reason);$GLOBALS['checks']=($GLOBALS['checks']??0)+1;}
function get_current_user_id(){return 5;}
function check_admin_referer($action){if($action!=='pcoe_crm_catalog')throw new RuntimeException('Unexpected nonce action');}
function set_transient($key,$value,$ttl){$GLOBALS['notice']=$value;}
function wp_safe_redirect($url){status_header(302);}
if(($argv[1]??'')==='endpoint'){
 $state=json_decode(file_get_contents($argv[2]),true);foreach($state as $k=>$v)$GLOBALS[$k]=$v;
 $_GET=['user'=>5,'key'=>C::key(5,C::config(5))];$case=$argv[3];
 if(in_array($case,['preview-download','expired-download'],true)){
    $GLOBALS['meta'][5][C::META]['enabled']=false;
    if($case==='expired-download')$GLOBALS['options']['pcoe_crm_snapshot_5']['at']=time()-C::MAX_AGE;
    $_POST=['operation'=>'download'];
    register_shutdown_function(function(){
        fwrite(STDERR,'STATUS:'.($GLOBALS['status']??200).' ENABLED:'.(int)C::config(5)['enabled'].' '.($GLOBALS['notice']??''));
    });
    C::post();exit;
 }
 if($case==='bad-key')$_GET['key']=str_repeat('f',64);
 if($case==='prom-key')$_GET['key']=hash_hmac('sha256','5:'.C::config(5)['seed'],wp_salt('auth'));
 if($case==='wrong-owner')$_GET['user']=6;
 if($case==='paused')$GLOBALS['meta'][5][C::META]['enabled']=false;
 if($case==='revoked')$GLOBALS['revoked']=true;
 if($case==='stale')$GLOBALS['options']['pcoe_crm_snapshot_5']['at']=time()-C::MAX_AGE;
 if($case==='changed')$GLOBALS['context']='different';
 if($case==='incomplete')unset($GLOBALS['options'][array_key_first(array_filter($GLOBALS['options'],fn($v)=>is_string($v)))]);
 register_shutdown_function(function(){fwrite(STDERR,'STATUS:'.($GLOBALS['status']??200));});C::feed();exit;
}
$c=['selection'=>['brands'=>[1,2],'locations'=>[10]],'revision'=>'rev-1','seed'=>'seed-one','enabled'=>false];C::save(5,$c);$start=C::start(5,$c);S::put(5,'build',$start);
$GLOBALS['owner_busy']=true;C::tick(5);ok(!S::get(5,'snapshot') && S::get(5,'build')['after']===0,'Concurrent owner job does not read/build a snapshot');unset($GLOBALS['owner_busy']);
$GLOBALS['costs']=[2=>0,153=>null];
C::tick(5);$snapshot=S::get(5,'snapshot');ok($snapshot['count']===605,'All 605 rows across multiple batches, no old 500 cap');ok(S::get(5,'build')===[],'Build complete');ok(C::ready(5,$c,$snapshot),'Complete snapshot ready');ok(empty(C::config(5)['enabled']),'Build does not enable a private link');
$path=S::file(5,$snapshot);$xml=file_get_contents($path);unlink($path);$dom=new \DOMDocument();ok($dom->loadXML($xml),'Complete valid XML');$xp=new \DOMXPath($dom);
ok($xp->evaluate('count(//offer)')===605.0,'Every row appears once');ok($xp->evaluate('string(//offer/price)')==='12.34','Price is purchase price');ok($xp->evaluate('string(//offer/purchase_price)')==='12.34','Explicit purchase tag');ok($xp->evaluate('string(//offer/barcode)')==='0012345678905','EAN leading zero preserved');ok($xp->evaluate('count(//offer/retail_price[text()="99.99"])')===605.0 && $xp->evaluate('count(//offer/price[text()="99.99"] | //offer/purchase_price[text()="99.99"])')===0.0,'All available public retail prices have their own field');ok($xp->evaluate('string(//offer[@id="2"]/quantity_in_stock)')==='0','Unknown stock never positive');ok($xp->evaluate('string(//offer/@selling_type)')==='w','Wholesale type');
$key=C::key(5,$c);$changed=$c;$changed['seed']='new-seed';$changed['enabled']=true;ok(!C::authorized(5,$changed,$key),'Rotated key invalid');ok(!C::authorized(5,$c,$key),'Disabled denied');
// Simulate termination after snapshot publication but before clearing its build pointer.
$replay=$start;$replay['generation']=$snapshot['generation'];S::put(5,'build',$replay);
$GLOBALS['read_failure_id']=1;C::tick(5);unset($GLOBALS['read_failure_id']);
ok(S::get(5,'build')===[],'Recovery recognizes committed publication without rebuilding it');
S::discard(5,$replay);$path=S::file(5,S::get(5,'snapshot'));ok(filesize($path)>0,'Retry cleanup cannot delete the just-published generation');unlink($path);
$c['enabled']=true;C::save(5,$c);$state=tempnam(sys_get_temp_dir(),'crm-state-');file_put_contents($state,json_encode(['options'=>$GLOBALS['options'],'meta'=>$GLOBALS['meta']]));
foreach(['valid'=>200,'preview-download'=>200,'expired-download'=>302,'bad-key'=>403,'prom-key'=>403,'wrong-owner'=>403,'paused'=>403,'revoked'=>403,'stale'=>503,'changed'=>503,'incomplete'=>503] as $case=>$status){
 $pipes=[];$proc=proc_open([PHP_BINARY,__FILE__,'endpoint',$state,$case],[1=>['pipe','w'],2=>['pipe','w']],$pipes);$body=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($proc);
 ok($exit===0 && str_contains($err,'STATUS:'.$status),'Endpoint '.$case.' '.$err);ok($status===200?str_contains($body,'<purchase_price>12.34</purchase_price>'):!str_contains($body,'<offer'),'No partial/sensitive XML on failure '.$case);
 if($case==='preview-download')ok($body===$xml && str_contains($err,'ENABLED:0'),'Owner downloads the complete preview while the private URL stays paused');
 if($case==='expired-download')ok(str_contains($err,'no current CRM file to download') && !str_contains($err,'enabling'),'Expired download explains the download action');
}unlink($state);
ok($snapshot['unpriced'] === 2, 'Counts unpriced products across batches');
foreach ([2 => '0', 153 => '3'] as $id => $quantity) {
    $offer = "//offer[@id='$id']";
    ok($xp->evaluate("count($offer)") === 1.0, 'Unpriced SKU appears once');
    ok($xp->evaluate("count($offer/price | $offer/purchase_price)") === 0.0, 'Unpriced SKU omits both numeric prices');
    ok($xp->evaluate("string($offer/purchase_price_status)") === 'unavailable', 'Missing price explicitly marked');
    ok($xp->evaluate("string($offer/quantity_in_stock)") === $quantity, 'Missing price preserves real stock');
    ok($xp->evaluate("string($offer/vendorCode)") === 'SKU-'.$id && $xp->evaluate("string($offer/barcode)") === '0012345678905', 'Keeps SKU and EAN');
    ok($xp->evaluate("string($offer/description)") === '<text>' && $xp->evaluate("string($offer/picture)") === 'https://images.example.test/1.jpg'
        && $xp->evaluate("string($offer/param[@name='Colour'])") === 'Blue' && $xp->evaluate("string($offer/url)") === 'https://example.test/product/'.$id, 'Keeps descriptions, photos, attributes and links');
}
$sample = D::rows(5,[1],[]); $sample['rows'][0]['retail'] = null;
$part = F::part($sample);
ok(!str_contains($part['xml'],'<retail_price>') && str_contains($part['xml'],'<purchase_price>12.34</purchase_price>'), 'Unavailable retail does not suppress purchase price');
ok($xp->evaluate('count(//offer/purchase_price)') === 603.0 && $xp->evaluate('count(//offer/price)') === 603.0, 'Every other SKU retains its purchase price');
foreach (['', 'invalid', -1, INF, NAN, 0.001, 10000000000] as $cost) {
    $sample = D::rows(5,[1],[]); $sample['rows'][0]['cost'] = $cost;
    $part = F::part($sample);
    ok($part['unpriced'] === 1 && str_contains($part['xml'],'<vendorCode>SKU-1</vendorCode>') && !str_contains($part['xml'],'<price>'), 'Invalid price only affects its fields');
}
$sample=D::rows(5,[1],[]);$retail=\PaintCore\PCOE\PartnerCatalogFormats::yml($sample);ok(str_contains($retail,'<price>99.99</price>') && !str_contains($retail,'<purchase_price>'),'Prom formatter remains retail-only');
$GLOBALS['context']='updated-shared-settings';ok(!C::ready(5,$c,S::get(5,'snapshot')),'Shared site setting changes invalidate old snapshot');
C::tick(5);ok(C::ready(5,$c,S::get(5,'snapshot')),'Refresh uses updated shared site settings automatically');
$old=S::get(5,'snapshot');$GLOBALS['read_failure_id']=150;S::put(5,'build',C::start(5,$c));C::tick(5);
ok(S::get(5,'build')['status']==='failed' && S::get(5,'snapshot')['generation']===$old['generation'],'Failed second batch never replaces complete snapshot');
S::clear(5);S::put(5,'build',C::start(5,$c));C::tick(5);ok(!S::get(5,'snapshot'),'Failed partial preparation cannot publish a first snapshot');unset($GLOBALS['read_failure_id']);
// A build interrupted before this release must re-encode all of its rows.
$previousBuild=C::start(5,$c);$previousBuild['offer_format']=2;$previousBuild['after']=100;
S::put(5,'build',$previousBuild);C::tick(5);
ok(S::get(5,'snapshot')['count']===605,'A pre-upgrade partial build restarts from the first SKU');
$GLOBALS['total']=2; $GLOBALS['costs']=[1=>0,2=>null];
S::clear(5); S::put(5,'build',C::start(5,$c)); C::tick(5);
$unpricedSnapshot=S::get(5,'snapshot');
ok(C::ready(5,$c,$unpricedSnapshot) && $unpricedSnapshot['count']===2 && $unpricedSnapshot['unpriced']===2, 'A selection entirely without prices still produces a complete file');
$GLOBALS['costs']=[1=>13.50,2=>null]; C::tick(5);
$updated=S::get(5,'snapshot');$path=S::file(5,$updated);$dom->load($path);unlink($path);
ok($updated['unpriced']===1 && (new \DOMXPath($dom))->evaluate('string(//offer[@id="1"]/purchase_price)')==='13.50','Corrected price returns on refresh under the same offer ID');
$GLOBALS['total']=0;S::clear(5);S::put(5,'build',C::start(5,$c));C::tick(5);ok(S::get(5,'build')['status']==='failed' && !S::get(5,'snapshot'),'Empty catalogue cannot publish successful empty feed');
echo 'PASS '.$GLOBALS['checks']." CRM lifecycle and endpoint checks\n";
}
