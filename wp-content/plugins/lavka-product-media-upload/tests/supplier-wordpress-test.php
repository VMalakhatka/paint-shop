<?php
error_reporting(E_ERROR|E_PARSE);
$base=dirname(__DIR__);
if (!defined('WP_CLI') || !WP_CLI || !in_array(wp_get_environment_type(), ['local','development'], true)) { throw new RuntimeException('Run only in local/development WordPress.'); }
require $base.'/inc/class-supplier-feed.php';require $base.'/inc/class-supplier-catalog.php';
use Lavka\ProductMediaUpload\SupplierCatalog;use Lavka\ProductMediaUpload\SupplierFeed;
function check_lsc($ok,$why){if(!$ok)throw new RuntimeException($why);echo "PASS $why\n";}
$admin=get_users(['role'=>'administrator','number'=>1,'fields'=>'ID']);wp_set_current_user((int)$admin[0]);SupplierCatalog::install();
$p=null;foreach(wc_get_products(['limit'=>30,'status'=>'publish']) as $candidate){if($candidate->get_sku()){$p=$candidate;break;}}
if(!$p)throw new RuntimeException('No local product SKU');
$id='test'.substr(md5(uniqid()),0,20);$saved=get_option(SupplierCatalog::OPTION,[]);$sources=$saved;
$sources[$id]=['name'=>'Integration test','url'=>'https://example.com/feed.xml','type'=>'xml','mapping'=>SupplierFeed::defaults(),'match_sku'=>true];update_option(SupplierCatalog::OPTION,$sources,false);
$xml='<?xml version="1.0"?><!DOCTYPE yml_catalog SYSTEM "shops.dtd"><yml_catalog><offer id="001"><model>'.htmlspecialchars($p->get_sku(),ENT_XML1).'</model><name>Test catalogue item</name><picture>https://example.com/photo.jpg</picture></offer></yml_catalog>';
$interceptor=function($pre,$args,$url)use(&$xml){if($url==='https://example.com/feed.xml'){file_put_contents($args['filename'],$xml);return ['response'=>['code'=>200],'headers'=>[],'body'=>''];}return $pre;};add_filter('pre_http_request',$interceptor,10,3);
$c=new SupplierCatalog();$invoke=function($method,...$args)use($c){return (new ReflectionMethod($c,$method))->invoke($c,...$args);};global $wpdb;$table=$wpdb->prefix.'lpmu_supplier_items';
try {
 $_FILES['xml']=['error'=>UPLOAD_ERR_OK,'tmp_name'=>'/nonexistent/untrusted.xml','size'=>10];
 $active=$invoke('refresh',$id);unset($_FILES['xml']);check_lsc($active['count']===1,'XML import stages one product');
 $_POST=['filter'=>'matched'];$list=$invoke('listing',$id);check_lsc($list['total']===1 && $list['items'][0]['product']['sku']===$p->get_sku(),'matched product and comparison');$item=$list['items'][0]['id'];
 $_POST=['selection'=>wp_slash(wp_json_encode([['item'=>$item,'index'=>0,'role'=>'main','position'=>0,'extension'=>'jpg']]))];$registry=$invoke('registry');$file=tempnam(sys_get_temp_dir(),'lsc-xlsx');file_put_contents($file,base64_decode($registry['xlsx']));$book=\PhpOffice\PhpSpreadsheet\IOFactory::load($file);
 check_lsc($book->getActiveSheet()->getCell('A2')->getValue()===$p->get_sku() && $book->getActiveSheet()->getCell('A2')->getDataType()==='s','registry exact SKU stored as text');check_lsc($book->getActiveSheet()->getCell('C2')->getValue()===$registry['files'][0],'registry filenames match');unlink($file);
 $wpdb->update($table,['manual_sku'=>$p->get_sku(),'match_state'=>'manual'],['id'=>$item]);$next=$invoke('refresh',$id);$_POST=[];$list=$invoke('listing',$id);check_lsc($list['items'][0]['state']==='manual','manual match survives refresh');
 $rejected=false;try{$invoke('item',$item);}catch(Throwable $e){$rejected=true;}check_lsc($rejected,'stale selections rejected');
 $xml='<yml_catalog><offer id="partial"/><offer>';$rejected=false;try{$invoke('refresh',$id);}catch(Throwable $e){$rejected=true;}check_lsc($rejected,'malformed refresh fails');
 check_lsc(get_option('lpmu_supplier_active_'.$id)['generation']===$next['generation'],'failed refresh retains snapshot');check_lsc((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE source=%s",$id))===1,'failed staging cleaned');
 $xml='<yml_catalog><offer id="replacement"><model>'.htmlspecialchars($p->get_sku(),ENT_XML1).'</model></offer></yml_catalog>';
 $failPointer=static fn($new,$old)=>$old;
 add_filter('pre_update_option_lpmu_supplier_active_'.$id,$failPointer,10,2);
 $rejected=false;try{$invoke('refresh',$id);}catch(Throwable $e){$rejected=true;}
 remove_filter('pre_update_option_lpmu_supplier_active_'.$id,$failPointer,10);
 check_lsc($rejected && get_option('lpmu_supplier_active_'.$id)['generation']===$next['generation'],'failed pointer update retains active snapshot');
 check_lsc((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE source=%s",$id))===1,'failed pointer staging cleaned');
 foreach(['http://127.0.0.1/x','http://169.254.169.254/x','file:///etc/passwd','http://10.0.0.1/x','http://[::1]/x','http://user:pass@example.com/x'] as $bad){$blocked=false;try{SupplierFeed::url($bad);}catch(Throwable $e){$blocked=true;}check_lsc($blocked,'unsafe source rejected');}
 ob_start();$c->render();$html=ob_get_clean();check_lsc(str_contains($html,'lsc-results') && str_contains($html,'lpmu-registry'),'page renders with existing uploader');echo "Integration complete; no product/media writes.\n";
} finally {
 $wpdb->delete($table,['source'=>$id]);update_option(SupplierCatalog::OPTION,$saved,false);foreach(['active','status','import'] as $key)delete_option('lpmu_supplier_'.$key.'_'.$id);remove_filter('pre_http_request',$interceptor,10);
}
