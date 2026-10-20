<?php
if(PHP_SAPI!=='cli')exit;
define('ABSPATH',__DIR__);
function add_action(...$args){}
function wp_json_encode($v,$flags=0){return json_encode($v,$flags);}
function check($ok,$message){if(!$ok)throw new RuntimeException($message);}
function rejected($fn){try{$fn();}catch(Throwable $e){check($e->getMessage()==='STORAGE_ERROR','Controlled corrupt-preview error');return;}throw new RuntimeException('Expected rejection');}
require (getenv('LPS_PLUGIN_DIR') ?: dirname(__DIR__)).'/inc/supplier-prices.php';
$small=['offers'=>[['sku'=>'SKU','article'=>'0001','pack'=>'2','price'=>'1.23','currency'=>'EUR','raw'=>['A'=>['value'=>'0001','formula'=>null]]]],'statuses'=>['SKU'=>'PRESENT'],'counts'=>['rows'=>1]];
check(lps_sp_encode_preview($small)===lps_sp_json($small),'Small preview remains plain JSON');
check(lps_sp_decode(lps_sp_json($small))===$small,'Legacy JSON remains readable');
$large=$small;$large['offers']=[];
for($i=0;$i<10000;$i++){$o=$small['offers'][0];$o['article']=str_pad((string)$i,8,'0',STR_PAD_LEFT);$o['raw']['Z']=['value'=>str_repeat('Supplier raw source / ',160),'formula'=>'A'.($i+2).'*(1-B'.($i+2).')'];$large['offers'][]=$o;}
$large['counts']['rows']=count($large['offers']);
$json=lps_sp_json($large);$encoded=lps_sp_encode_preview($large);
check(strlen($json)>16777216,'Fixture reproduces packet overflow');
check(strlen($encoded)<16777216,'Encoded preview fits packet limit');
check(lps_sp_decode($encoded)===$large,'Every row, variant, identifier and source formula survives exact roundtrip');
check(hash('sha256',lps_sp_json(lps_sp_decode($encoded)))===hash('sha256',$json),'Approval hashing uses unchanged logical JSON');
// Activated previews and merged partial imports must use the same storage codec.
$activated=lps_sp_decode($encoded);foreach($activated['offers'] as &$offer)$offer['versionId']=42;unset($offer);
$activated['offers'][]=$small['offers'][0];$activated['sourceCounts']=$activated['counts'];
check(lps_sp_decode(lps_sp_encode_preview($activated))===$activated,'Version IDs and retained partial-import offers survive activation storage');
$envelope=json_decode($encoded,true);
foreach(['supplierPreviewStorageVersion'=>2,'encoding'=>'unknown','bytes'=>268435457,'sha256'=>str_repeat('0',64),'payload'=>'@@@'] as $key=>$bad){$v=$envelope;$v[$key]=$bad;rejected(fn()=>lps_sp_decode(json_encode($v)));}
$v=$envelope;$v['bytes']--;rejected(fn()=>lps_sp_decode(json_encode($v)));
$v=$envelope;unset($v['sha256']);rejected(fn()=>lps_sp_decode(json_encode($v)));
echo 'PASS: legacy/small JSON, large lossless storage, activation data, approval hash, bounded decompression and corruption checks; '.strlen($json).' -> '.strlen($encoded)." bytes\n";
unset($large,$activated,$json,$encoded,$envelope,$v);
if($path=getenv('LPS_SP_LARGE_XLSX')){
 $source=json_decode(file_get_contents(getenv('LPS_SP_CATALOG_JSON')),true,512,JSON_THROW_ON_ERROR);
 check(hash_file('sha256',$path)===$source['checksum'],'Fixture is the exact uploaded original');
 $sheets=lps_sp_xlsx($path)['sheets'];$rows=lps_sp_xlsx($path,$sheets[0])['rows'];
 $config=lps_sp_config(lps_sp_defaults($rows)+['sheet'=>$sheets[0],'validFrom'=>'2026-01-01'], $sheets);
 $preview=lps_sp_preview($rows,$config,$source['catalog']);
 $plain=lps_sp_json($preview);$digest=hash('sha256',$plain);$plainBytes=strlen($plain);$counts=$preview['counts'];
 $stored=lps_sp_encode_preview($preview);
 // Saving and reopening are separate web requests; do not retain the parsed XLSX
 // alongside a second decoded copy in this production-size fixture.
 unset($rows,$preview,$plain);gc_collect_cycles();
 $roundtrip=lps_sp_decode($stored);
 check(hash('sha256',lps_sp_json($roundtrip))===$digest,'Actual supplier price file roundtrip');
 check(strlen($stored)<16777216,'Actual preview fits production packet size');
 echo json_encode(['actualCounts'=>$counts,'plainBytes'=>$plainBytes,'storedBytes'=>strlen($stored),'peakMemory'=>memory_get_peak_usage(true)],JSON_UNESCAPED_UNICODE)."\n";
}
