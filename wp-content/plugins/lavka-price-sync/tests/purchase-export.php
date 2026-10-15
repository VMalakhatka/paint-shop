<?php
// Regression of the real export projection; source reads and orders are not run.
require __DIR__ . '/purchase-planning-session.php';
$raw = ['sku'=>'TEST-010','productName'=>'Test product','dimensions'=>['currentSuppliers'=>['Supplier'],'packageQuantity'=>5,'primaryBarcode'=>'03167860250109'],
    'metrics'=>['grossProfit'=>10], 'warehouseBreakdown'=>[]];
foreach ([1=>100,7=>69,5=>14,15=>999] as $id=>$sales) $raw['warehouseBreakdown'][]=['warehouseId'=>$id,
    'metrics'=>['regularSoldUnits'=>$sales,'expenseQuantity'=>$sales,'returnQuantity'=>0]];
$offer=['article'=>'0010','offerStatus'=>'PRESENT','price'=>1.07,'currency'=>'EUR','priceBasis'=>'UNIT','pack'=>5,'issues'=>[]];
$raw['supplierPrices']=[['supplier'=>'Supplier','offers'=>[$offer]]];
$scope=[1,5,7,9,10,15]; $recipe=['version'=>2,'revision'=>'export-fixture','nodes'=>[['sku'=>'TEST-010','manufactured'=>false,'issues'=>[]]],'edges'=>[]];
$state=['rootSkus'=>['TEST-010'],'orderSupplier'=>'Supplier','periodDays'=>246,'allowTransfers'=>false,'transitWarehouseIds'=>[9,10],
    'createdAt'=>'2026-10-11 12:00:00',
    'query'=>['sourceDatabase'=>'Paint_Ua','warehouseIds'=>[1,5,7,15],'period'=>['from'=>'2026-02-09','to'=>'2026-10-12']],
    'groups'=>[
        ['code'=>'kyiv','name'=>'Kyiv','warehouseIds'=>[1,7],'receivingWarehouseId'=>7,'minimumWarehouseId'=>7,'supplyFromGroupCode'=>'','targetDays'=>150,'leadTimeDays'=>45,'safetyDays'=>0,'packRounding'=>'UP'],
        ['code'=>'odessa','name'=>'Odessa','warehouseIds'=>[5,15],'stockOnlyWarehouseIds'=>[15],'receivingWarehouseId'=>5,'minimumWarehouseId'=>5,'supplyFromGroupCode'=>'kyiv','targetDays'=>150,'leadTimeDays'=>45,'safetyDays'=>0,'packRounding'=>'UP']],
    'rows'=>['TEST-010'=>$raw],'assemblyGraph'=>$recipe,'edits'=>[], 'coldCoverage'=>['enabled'=>false],
    'supplyConfirmation'=>['noOtherIncomingOrders'=>true,'noSupplierMinimumOrder'=>true,'currentReceiptsWithinLeadTime'=>true],
    'context'=>['warehouses'=>[['id'=>1,'name'=>'Kyiv'],['id'=>7,'name'=>'Wholesale'],['id'=>5,'name'=>'Odessa'],['id'=>15,'name'=>'Storage']],
        'transit'=>['sources'=>[['warehouseId'=>9,'warehouseName'=>'Transport'],['warehouseId'=>10,'warehouseName'=>'Receiving']]]]];
$capture=current_stock_fixture(['sourceDatabase'=>'Paint_Ua','warehouseIds'=>$scope,'analysisWarehouseIds'=>[1,5,7,15]],$recipe);
$stock=[1=>[5,5],7=>[92,82],5=>[3,3],15=>[0,0],9=>[45,45],10=>[0,0]];
foreach ($capture['cards'] as &$card) {
    [$card['physicalQuantity'],$card['availableQuantity']]=$stock[$card['warehouseId']];
    $card['reservedQuantity']=$card['physicalQuantity']-$card['availableQuantity'];
}
unset($card); $state=lps_purchase_apply_current_stock($state,$capture);
$results=lps_purchase_calculate_network($state); $summary=lps_purchase_export_summary($state,$results); $row=$summary['rows'][0];
$familyTemplate=$state;
check(count($summary['rows'])===1,'Two destinations produce one supplier-order row');
check(!in_array('preordersCoveredByCurrentReserve',array_column($summary['columns'],'key'),true),'Non-accounted preorders have no misleading current-reserve coverage column');
check($row['periodExpense']===183.0 && $row['sales']===183.0,'Outgoing/sales total is 169+14; storage-only movements excluded');
check($row['analysisPhysical']===100.0 && $row['transitPhysical']===45.0 && $row['networkPhysical']===145.0,'Physical groups and transit are separate and totalled once');
check($row['planningAvailable']===135.0,'Client reserve of ten remains deducted from planning stock');
check($row['warehouse_1_physical']===5 && $row['warehouse_7_physical']===92 && $row['warehouse_9_physical']===45,'Individual warehouse stock remains visible');
check($row['group_kyiv_periodExpense']===169.0 && $row['group_odessa_periodExpense']===14.0,'Group details reconcile with total');
check($row['supplierArticle']==='0010' && $row['supplierUnitPrice']===1.07 && $row['supplierCurrency']==='EUR','Supplier identifiers and unit price retained');
if ($qa=getenv('LPS_EXPORT_QA_DATA')) file_put_contents($qa, json_encode($summary));
$quantity=$results[0]['groups'][0]['finalQuantity'];
$results[0]['groups'][1]['finalQuantity']=999.0;
check(lps_purchase_export_summary($state,$results)['rows'][0]['final']===$quantity,'Internal destination replenishment is never purchased a second time');
$results[0]['groups'][0]['finalQuantity']=null;
check(lps_purchase_export_summary($state,$results)['rows'][0]['final']===null,'Unknown procurement never becomes zero or an internal order');
$independent=$results[0]['groups'][1];$independent['groupCode']='second';$independent['supplyFromGroupCode']='';$independent['finalQuantity']=2;
$results[0]['groups'][]=$independent;
$results[0]['stockReport']['groups']['second']=$results[0]['stockReport']['groups']['odessa'];
check(lps_purchase_export_summary($state,$results)['rows'][0]['final']===null,'One unknown independent procurement group prevents a false complete sum');
$state['rows']['TEST-010']['inTransitStock']['sources'][0]['status']='SKU_NOT_PRESENT';
$results=lps_purchase_calculate_network($state);
check(lps_purchase_export_summary($state,$results)['rows'][0]['transitPhysical']===null,'Missing transit card is not zero physical stock');
check(lps_purchase_export_price([['offers'=>[$offer+[],$offer+[]]]])['supplierUnitPrice']===1.07,'Identical offers do not make the price ambiguous');
$other=$offer;$other['price']=2;
check(lps_purchase_export_price([['offers'=>[$offer,$other]]])['supplierUnitPrice']===null,'Different valid prices require a choice');
$pack=$offer;$pack['priceBasis']='PACK';$pack['price']=5.35;
check(abs(lps_purchase_export_price([['offers'=>[$pack]]])['supplierUnitPrice']-1.07)<1e-9,'Pack price converted to price per unit');
$offer['offerStatus']='WHILE_STOCK_LASTS';
check(lps_purchase_export_price([['offers'=>[$offer]]])['supplierPriceNote']!=='','Limited-stock price remains usable with availability warning');

// Regression of a parent/child report, using synthetic quantities rather than production data.
$family=$familyTemplate; $parent='P-520500'; $child='P-520560-20R'; $grandchild='OUR-GRANDCHILD';
$parentRaw=$family['rows']['TEST-010']; $parentRaw['sku']=$parent;
$childRaw=$parentRaw; $childRaw['sku']=$child; $childRaw['productName']='Manufactured child';
$childRaw['dimensions']['currentSuppliers']=['Our company']; $childRaw['supplierPrices']=[];
foreach ($childRaw['warehouseBreakdown'] as &$member) {
    $id=$member['warehouseId']; $sold=$id===1?8:($id===5?2:0); $stock=$id===1?2:($id===5?1:0);
    $member['metrics']=array_replace($member['metrics'],['regularSoldUnits'=>$sold,'expenseQuantity'=>$sold,'physicalQuantity'=>$stock,'availableQuantity'=>$stock,'reservedQuantity'=>0]);
}
unset($member);
$childRaw['inTransitStock']['availableForNetworkPlanningQuantity']=0;
foreach ($childRaw['inTransitStock']['sources'] as &$source) foreach (['physicalQuantity','availableQuantity','reservedQuantity','availableForNetworkPlanningQuantity'] as $key) $source[$key]=0;
unset($source);
$grandchildRaw=$childRaw; $grandchildRaw['sku']=$grandchild;
foreach ($grandchildRaw['warehouseBreakdown'] as &$member) {
    $member['metrics']['regularSoldUnits']=$member['metrics']['expenseQuantity']=$member['warehouseId']===1?4:0;
    $member['metrics']['physicalQuantity']=$member['metrics']['availableQuantity']=0;
}
unset($member);
$family['rootSkus']=[$parent,$child];
$family['rows']=[$parent=>$parentRaw,$child=>$childRaw,$grandchild=>$grandchildRaw];
$family['assemblyGraph']=['version'=>2,'revision'=>'family-fixture','nodes'=>[
    ['sku'=>$parent,'manufactured'=>false,'issues'=>[]],['sku'=>$child,'manufactured'=>true,'issues'=>[]],['sku'=>$grandchild,'manufactured'=>true,'issues'=>[]]],
    'edges'=>[['parent'=>$parent,'child'=>$child,'factor'=>0.05,'source'=>'ALL_RAZBORKA','rowId'=>'1'],
        ['parent'=>$child,'child'=>$grandchild,'factor'=>0.25,'source'=>'ALL_RAZBORKA_SLOJ','rowId'=>'2']]];
$familyResults=lps_purchase_calculate_network($family);
$familySummary=lps_purchase_export_summary($family,$familyResults); $bySku=array_column($familySummary['rows'],null,'sku');
check(count($bySku)===3,'Selected child and discovered grandchild each appear once in the report');
check($bySku[$parent]['children']===$child && $bySku[$child]['parents']===$parent && $bySku[$child]['children']===$grandchild,'Parent, child and intermediate links are visible');
check($bySku[$child]['sales']===10.0 && $bySku[$child]['analysisPhysical']===3.0,'Child keeps its own actual sales and physical stock');
check($bySku[$child]['final']===0.0 && $bySku[$child]['supplierAmount']===0.0 && $bySku[$grandchild]['final']===0.0,'Children never add a supplier quantity or amount');
check($bySku[$child]['group_kyiv_final']===0.0 && $bySku[$child]['group_odessa_final']===0.0,'Every group displays zero supplier order for children');
check($bySku[$child]['manufacturingNeed']>0.0 && $bySku[$parent]['componentDemand']>0.0,'Manufacturing need remains visible and consumes parent components');
if ($qa=getenv('LPS_EXPORT_FAMILY_QA_DATA')) file_put_contents($qa,json_encode($familySummary));
unset($family['rows'][$grandchild]);
$unknownSummary=lps_purchase_export_summary($family,lps_purchase_calculate_network($family)); $unknownBySku=array_column($unknownSummary['rows'],null,'sku');
check($unknownBySku[$grandchild]['final']===0.0 && $unknownBySku[$grandchild]['sales']===null && $unknownBySku[$grandchild]['manufacturingNeed']===null && $unknownBySku[$grandchild]['status']==='Review required',
    'A missing child stays visible with zero supplier order, unknown statistics and review status');
echo "PASS: unique SKU summary, warehouse stock, transit, routing, unknown quantities and supplier variants\n";
echo "PASS: parent/child report links, child statistics, zero supplier purchases, grandchild discovery and missing data\n";
