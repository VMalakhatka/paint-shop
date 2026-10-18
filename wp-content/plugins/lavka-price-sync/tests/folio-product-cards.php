<?php
// Isolated transport + workbook regression, no WordPress/Folio database connection.
define('ABSPATH', __DIR__.'/'); define('LPS_CAP','manage_lavka_prices');
function add_action($name,$fn){$GLOBALS['hooks'][$name]=$fn;}
function __($s,$d=''){return $s;}
function get_temp_dir(){return sys_get_temp_dir().'/';}
function lps_get_options(){return ['java_base_url'=>'https://invalid.example','api_token'=>'test'];}
function lps_java_post($p,$selection,$args){$GLOBALS['selection']=$selection;return [];}
function is_wp_error($r){return false;}
function wp_remote_retrieve_response_code($r){return $GLOBALS['http']??200;}
function wp_remote_retrieve_body($r){return json_encode($GLOBALS['response']);}
require __DIR__.'/../inc/folio-product-cards.php';
require __DIR__.'/../inc/folio-product-cards-xlsx.php';
function ok($v){if(!$v)throw new RuntimeException('Assertion failed');}
function rejected($f){try{$f();}catch(RuntimeException $e){return;}throw new RuntimeException('Expected rejection');}
ok(lps_card_selection(['mode'=>'compare','skus'=>"00123\n00123\n=x\n"])===['mode'=>'compare','skus'=>['00123','=x']]);
rejected(fn()=>lps_card_selection(['mode'=>['view']]));rejected(fn()=>lps_card_selection(['skus'=>str_repeat('a',101)]));
rejected(fn()=>lps_card_selection(['skus'=>implode("\n",range(1,501))]));
$row=array_fill(0,54,null);$row[0]=$row[1]='00123';$row[3]='=HYPERLINK("bad")';$row[19]='12.3400';$row[26]='0012345678901';$row[53]='Опис & <текст>';
$columns=array_map(fn($k)=>['key'=>$k,'type'=>'text'],lps_card_keys());$columns[19]['type']='number';
$GLOBALS['response']=['ok'=>true,'sourceDatabase'=>'Paint_Ua','mode'=>'compare','data'=>['columns'=>$columns,'rows'=>[$row]],'identical'=>true,'directRows'=>1,'viewRows'=>1,'missingSkus'=>['no-such-sku']];
$d=lps_card_read(['mode'=>'compare','skus'=>[]]);
ok($d['data']['rows'][0][1]==='00123');ok(count(lps_card_headers())===54);
$GLOBALS['http']=503;rejected(fn()=>lps_card_read(['mode'=>'compare','skus'=>[]]));$GLOBALS['http']=200;
$GLOBALS['response']['data']['columns'][1]['key']='wrong';rejected(fn()=>lps_card_read(['mode'=>'compare','skus'=>[]]));
$path=lps_card_xlsx($d);$z=new ZipArchive();ok($z->open($path)===true);
foreach (range(0,$z->numFiles-1) as $i){$xml=$z->getFromIndex($i);ok(simplexml_load_string($xml)!==false);}
$sheet=simplexml_load_string($z->getFromName('xl/worksheets/sheet1.xml'));$sheet->registerXPathNamespace('x','http://schemas.openxmlformats.org/spreadsheetml/2006/main');
ok((string)$sheet->xpath('//x:c[@r="B2"]/x:is/x:t')[0]==='00123');
ok((string)$sheet->xpath('//x:c[@r="AA2"]/x:is/x:t')[0]==='0012345678901');
ok((string)$sheet->xpath('//x:c[@r="T2"]/x:v')[0]==='12.3400');
ok(count($sheet->xpath('//x:f'))===0);ok(count($sheet->xpath('//x:row[@r="1"]/x:c'))===54);
ok((string)$sheet->xpath('//x:c[@r="BB2"]/x:is/x:t')[0]==='Опис & <текст>');
ok(count($sheet->xpath('//x:c[@r="C2"]'))===0);$z->close();
if(getenv('LPS_CARD_TEST_OUTPUT'))copy($path,getenv('LPS_CARD_TEST_OUTPUT'));unlink($path);
echo "PASS: selection, protocol failure, schema, exact text identifiers, numbers, NULL, XML, 54 headers and formula safety\n";
