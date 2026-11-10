<?php
/** Offline boundary tests: no accounts, emails, stock changes, or network. */
define('ABSPATH',__DIR__);
require getenv('PCOE_VENDOR_AUTOLOAD')?:dirname(__DIR__,3).'/vendor/autoload.php';
foreach(['PartnerCatalogData','PartnerCatalogFormats','PartnerCatalog','PartnerCatalogPhotos'] as $c)require dirname(__DIR__).'/inc/'.$c.'.php';
function __($s,$d=''){return $s;}
function get_bloginfo($s){return 'Shop & Studio';}
function home_url($s){return 'https://shop.example/';}
function wp_json_encode($v,$flags=0){return json_encode($v,$flags);}
function wp_salt($key){return 'test-secret';}
function absint($v){return abs((int)$v);}
function term_exists($id,$tax){return $id===3;}
function sanitize_file_name($s){return preg_replace('/[^a-zA-Z0-9._-]/','_',$s);}
function is_wp_error($v){return false;}
function wp_safe_remote_get($url,$args){$GLOBALS['request_args']=$args;return ['code'=>200,'body'=>$url==='https://photos.example/good.png'?base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jKxkAAAAASUVORK5CYII='):'broken'];}
function wp_remote_retrieve_body($r){return $r['body'];}
function wp_remote_retrieve_response_code($r){return $r['code'];}
function get_userdata($u){return $u===5?(object)['ID'=>5]:false;}
function pc_wholesale_customer_can_access($u){return $u->ID===5;}
require dirname(__DIR__).'/inc/BroadcastStore.php';
use PaintCore\PCOE\PartnerCatalog as C;
use PaintCore\PCOE\PartnerCatalogData as D;
use PaintCore\PCOE\PartnerCatalogFormats as F;
use PaintCore\PCOE\PartnerCatalogPhotos as P;
$n=0;function ok($v,$why){global $n;$n++;if(!$v)throw new RuntimeException($why);}
function fails($fn){try{$fn();return false;}catch(RuntimeException $e){return true;}}
ok(D::skus("A\r\nB\nA\n0012")==['A','B','0012'],'SKU values and dedup');
ok(fails(fn()=>D::skus(implode("\n",range(1,501)))),'reject >500, no truncation');
ok(fails(fn()=>D::selection(['skus'=>['A']])),'reject arrays');
ok(fails(fn()=>D::selection([])),'require explicit selection');
ok(fails(fn()=>D::selection(['category'=>99])),'validate filter');
ok(D::selection(['category'=>3])['category']===3,'valid category');
$r=['id'=>17,'sku'=>'=BAD','gtin'=>'001234','name'=>'Paint & <colour>','category'=>'Art / Colour','category_id'=>2,'description'=>"Full <description>\x01",'short_description'=>'Short','cost'=>12.3456789,'retail'=>99.50,'stock'=>null,'unit'=>'29 ml','pack'=>5,'images'=>['https://photos.example/good.png','https://photos.example/bad.png'],'url'=>'https://shop.example/item','attributes'=>['Colour'=>'Red & blue']];
$d=['categories'=>[1=>['name'=>'Art','parent'=>0],2=>['name'=>'Colour','parent'=>1]],'rows'=>[$r],'at'=>'2026-11-10T12:00:00+00:00','currency'=>'UAH'];
$xml=F::yml($d);$dom=new DOMDocument();ok($dom->loadXML($xml),'valid XML');$x=new DOMXPath($dom);
ok($x->evaluate('string(//offer/@id)')==='17','stable id');
ok($x->evaluate('string(//offer/@available)')==='false','unknown stock not advertised');
ok($x->evaluate('string(//offer/price)')==='99.50','retail, never customer cost');
ok(!str_contains($xml,'12.3456789') && !str_contains($xml,'cost') && !str_contains($xml,'supplier'),'no private fields');
ok($x->evaluate('string(//offer/name)')==='Paint & <colour>','XML escaping');
ok($x->evaluate('count(//category)')===2.0,'category hierarchy');
ok($x->evaluate('string(//offer/param[@name="GTIN"])')==='001234','GTIN separate from vendorCode');
$d['rows'][0]['images']=array_fill(0,12,'https://photos.example/good.png');$dom->loadXML(F::yml($d));ok((new DOMXPath($dom))->evaluate('count(//picture)')===10.0,'Prom ten-photo limit');
$d['rows'][0]['stock']=3;$dom->loadXML(F::yml($d));ok((new DOMXPath($dom))->evaluate('string(//offer/@available)')==='true','positive stock available');
$d['rows'][0]['retail']=null;ok(fails(fn()=>F::yml($d)),'missing retail fails closed');$d['rows']=[$r];
$c=['seed'=>'alpha'];$key=C::key(5,$c);ok(C::validKey(5,$c,$key),'valid link');ok(!C::validKey(6,$c,$key),'owner isolation');ok(!C::validKey(5,['seed'=>'beta'],$key),'rotation revokes old key');ok(!C::validKey(5,[],$key),'unconfigured key rejected');ok(!C::allowed(6)&&C::allowed(5),'role/access');
$csv=F::csv($d);ok(str_contains($csv,"'=BAD"),'CSV injection escaped');ok(str_contains($csv,'12.3456789'),'private CSV cost included');ok(!str_contains(F::csv($d,true),'12.3456789'),'Woo public CSV omits cost');
$bytes=F::xlsx($d);$path=tempnam(sys_get_temp_dir(),'pcoe-test');file_put_contents($path,$bytes);$book=\PhpOffice\PhpSpreadsheet\IOFactory::load($path);ok($book->getActiveSheet()->getCell('A2')->getDataType()==='s','XLSX formula stays string');ok($book->getActiveSheet()->getCell('B2')->getValue()==='001234','GTIN leading zero preserved');$book->disconnectWorksheets();unlink($path);
$path=tempnam(sys_get_temp_dir(),'pcoe-test');file_put_contents($path,P::zip($d));$z=new ZipArchive();$z->open($path);$manifest=$z->getFromName('manifest.csv');ok(str_contains($manifest,'OK')&&str_contains($manifest,'Image unavailable'),'missing photos documented');ok($GLOBALS['request_args']['redirection']===0,'no redirect SSRF');$z->close();unlink($path);
$d['rows'][0]['images']=array_fill(0,101,'https://photos.example/good.png');ok(fails(fn()=>P::zip($d)),'ZIP limit explicit');
echo "PASS $n partner catalogue checks\n";
