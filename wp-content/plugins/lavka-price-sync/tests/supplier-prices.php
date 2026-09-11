<?php
if (PHP_SAPI!=='cli') exit;
define('ABSPATH',__DIR__);
require __DIR__.'/../inc/supplier-price-model.php';
function check($v,$m) { if (!$v) throw new RuntimeException($m); }
function reject(callable $f,$m) { try { $f(); } catch (RuntimeException $e) { return; } throw new RuntimeException($m); }
function row($article,$gtin,$pack,$price,$type='n') {
    $r=[];foreach (['A'=>$article,'C'=>'Product','J'=>$gtin,'H'=>$pack,'D'=>'6.69','E'=>'0.35','F'=>$price,'I'=>''] as $c=>$v) $r[$c]=['value'=>$v,'type'=>$c==='F'?$type:'n','formula'=>null];return $r;
}
$config=lps_sp_config(['sheet'=>'Price','header'=>1,'validFrom'=>'2026-04-01','full'=>1],['Price']);
$catalog=[['sku'=>'KR-17003','gtin'=>'4000798170035'],['sku'=>'KR-17817','gtin'=>'4000798178178'],['sku'=>'NO-GTIN','gtin'=>'']];
$rows=[2=>row('17003','4000798170035','2','4.3485'),3=>row('17003','4000798170035','6','4.3485')];
$r=lps_sp_preview($rows,$config,$catalog);
check(count($r['offers'])===2 && $r['counts']['matched']===2,'Both package variants map to one SKU');
check($r['statuses']['KR-17817']==='DISCONTINUED' && $r['statuses']['NO-GTIN']==='REVIEW','Full absence does not guess missing catalog GTIN');
check($r['offers'][0]['price']==='4.3485' && $r['offers'][0]['pack']==='2','Keep precise decimal price and pack');
check(lps_sp_gtin('4000798170035')===lps_sp_gtin('04000798170035'),'Canonical GTIN preserves identity');
check(lps_sp_gtin('4000798170036')===null && lps_sp_gtin('4.000798170035E12')===null,'Reject checksum and precision risk');
$rows[4]=row('17817','4000798178178','6','#N/A','e');$r=lps_sp_preview($rows,$config,$catalog);
check($r['statuses']['KR-17817']==='PRESENT' && $r['offers'][2]['price']===null,'Bad price does not discontinue a present SKU');
$partial=$config;$partial['full']=false;
check(lps_sp_preview(array_slice($rows,0,2,true),$partial,$catalog)['statuses']['KR-17817']==='NOT_IN_PARTIAL','Partial list cannot discontinue absent product');
$rows[5]=row('Unknown','invalid','6','1');$r=lps_sp_preview($rows,$config,$catalog);
check($r['blockers']===['INVALID_GTIN'],'Invalid source identity blocks full activation');
$dup=$rows; $dup[6]=$dup[2];$r=lps_sp_preview($dup,$config,$catalog);
check(in_array('DUPLICATE_VARIANT',$r['offers'][0]['issues'],true),'Both duplicate variants are flagged');
$ambiguous=$catalog; $ambiguous[]=['sku'=>'OTHER','gtin'=>'4000798170035'];
check(lps_sp_preview($rows,$config,$ambiguous)['offers'][0]['sku']===null,'No arbitrary match for ambiguous GTIN');
$net=[2=>row('17003','4000798170035','2','')];$net[2]['I']['value']='Netto';
check(lps_sp_preview($net,$config,$catalog)['offers'][0]['price']===null,'No implicit fallback for Netto');
$fallback=$config;$fallback['netFallback']=true;
check(lps_sp_preview($net,$fallback,$catalog)['offers'][0]['price']==='6.69','Explicit Netto fallback never discounts again');
$formula=[2=>row('17003','4000798170035','2','4.3485')];$formula[2]['F']['formula']='+D2*(1-E2)';
check(!lps_sp_preview($formula,$config,$catalog)['offers'][0]['issues'],'Check price formula against inputs');
$formula[2]['F']['value']='1';check(lps_sp_preview($formula,$config,$catalog)['offers'][0]['price']===null,'Stale formula cache cannot become a price');
$formula[2]['F']['formula']='WEBSERVICE("https://example.invalid")';check(lps_sp_preview($formula,$config,$catalog)['offers'][0]['price']===null,'Never evaluate workbook instructions');
reject(fn()=>lps_sp_xml('<!DOCTYPE x [<!ENTITY e SYSTEM "file:///etc/passwd">]><x>&e;</x>'),'Reject entities');
reject(fn()=>lps_sp_config(['sheet'=>'Price','validFrom'=>'2026-02-30'],['Price']),'Reject invalid dates');
// Optional read-only acceptance test against a supplier file; never commit its commercial data.
if (!empty($argv[1])) {
    $p=$argv[1];$c=lps_sp_config(['sheet'=>'Preise 2025','validFrom'=>'2026-04-01','full'=>true],lps_sp_xlsx($p)['sheets']);
    $real=lps_sp_preview(lps_sp_xlsx($p,$c['sheet'])['rows'],$c,$catalog);
    $matches=array_values(array_filter($real['offers'],static fn($r)=>$r['article']==='17003'));
    check(count($matches)===2 && array_column($matches,'pack')===['2','6'],'Read both supplier VE values');
    check(array_column($matches,'price')===['4.3485','4.3485'],'Resolve shared XLSX formulas without evaluation');
    echo 'Supplier file: '.$real['counts']['rows']." rows read; control variants verified\n";
}
echo "PASS: GTIN, variants, full/partial presence, price errors, ambiguous matches, formula cache and unsafe XML\n";
