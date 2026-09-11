<?php
define('ABSPATH', '/tmp/');
require __DIR__.'/../inc/supplier-price-model.php';
function expect($v,$m){if(!$v)throw new RuntimeException($m);}
foreach (array_slice($argv,1) as $path) {
    $sheets=lps_sp_xlsx($path)['sheets'];$rows=lps_sp_xlsx($path,$sheets[0])['rows'];
    $defaults=lps_sp_defaults($rows);
    expect(!empty($defaults),'Format detected');
    $config=lps_sp_config($defaults+['sheet'=>$sheets[0],'validFrom'=>'2026-01-01'],$sheets);
    $preview=lps_sp_preview($rows,$config,[]);
    $pebeo=$config['article']==='B';
    expect(count($preview['offers'])===($pebeo?3299:16541),'Complete file read');
    $prices=array_filter($preview['offers'],static fn($o)=>$o['price']!==null);
    expect(count($prices)===($pebeo?3299:16253),'Valid prices preserved');
    if ($pebeo) {
        expect($preview['offers'][0]['price']==='3.54','Rounded net price');
        expect($preview['offers'][6]['offerStatus']==='DISCONTINUED','Explicit discontinued status');
        foreach($preview['offers'] as $offer) if($offer['supplierStatus']==='DISCONTINUED 2027')expect($offer['offerStatus']!=='DISCONTINUED','Future status not premature');
    } else {
        $first=$preview['offers'][0];
        expect($first['price']==='0.5915','Use values from U');
        expect($first['minimumOrder']==='25' && $first['boxQuantity']==='200' && $first['invoiceQuantity']==='1' && $first['invoiceUnit']==='Pce','Separate ordering quantities');
        expect($first['packGtin']==='3663619063056','Separate pack barcode');
    }
    expect(in_array('INVALID_GTIN',$preview['blockers'],true),'Unresolved identifiers remain flagged');
    echo 'PASS: '.($pebeo?'Pebeo':'Clairefontaine')." detection, complete parsing, prices and ordering data\n";
    unset($rows,$preview,$prices);
}
