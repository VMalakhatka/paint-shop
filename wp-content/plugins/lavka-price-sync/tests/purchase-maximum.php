<?php
require __DIR__.'/purchase-planning.php';
$plan=lps_purchase_profile(['groups'=>[['code'=>'kyiv'],['code'=>'odesa','respectMaximumStock'=>false]]]);
check($plan['groups'][0]['respectMaximumStock']===true && $plan['groups'][1]['respectMaximumStock']===false,'Legacy MAX default and independent group flags');
check(lps_purchase_profile($plan)===$plan,'MAX flags survive scenario sanitization');
$g=[['code'=>'kyiv','name'=>'Kyiv','warehouseIds'=>[7,1],'receivingWarehouseId'=>7,'minimumWarehouseId'=>7,'leadTimeDays'=>45,'targetDays'=>240,'safetyDays'=>0,'supplyFromGroupCode'=>'','packRounding'=>'UP'],
    ['code'=>'odesa','name'=>'Odesa','warehouseIds'=>[5],'receivingWarehouseId'=>5,'minimumWarehouseId'=>5,'leadTimeDays'=>45,'targetDays'=>240,'safetyDays'=>0,'supplyFromGroupCode'=>'kyiv','packRounding'=>'UP']];
$r=['sku'=>'KR-17009','dimensions'=>['currentSuppliers'=>['Kreul'],'packageQuantity'=>6,'minimumOrderQuantity'=>0],
 'metrics'=>['grossProfit'=>100],'internalTransferReservations'=>['calculationVersion'=>1,'status'=>'CAPTURED','accounts'=>[]],
 'networkOrderPolicy'=>['policyWarehouseId'=>7,'orderAllowed'=>true,'status'=>'ALLOWED'],
 'inTransitStock'=>['warehouseId'=>9,'generationId'=>99,'status'=>'CONFIRMED_SUPPLIER_ORIGIN','supplierOriginConfirmed'=>true,'availableForPlanningQuantity'=>12],
 'warehouseBreakdown'=>[member(7,0,2),member(1,2,4),member(5,1,10)]];
foreach($r['warehouseBreakdown'] as &$m){$m['orderPolicy']['reserveAboveForecast']=1;$m['orderPolicy']['minimumStock']=1;$m['orderPolicy']['replenishmentMode']='FORECAST_PLUS_MINIMUM_STOCK';$m['orderPolicy']['validationState']='VALID';}unset($m);
$r['warehouseBreakdown'][2]['orderPolicy']['maximumStockLimited']=true;
$r['warehouseBreakdown'][2]['orderPolicy']['maximumStockLimit']=3;
$input=['kyiv'=>['inTransit'=>12,'openOrders'=>0,'moq'=>0,'receiptsReviewed'=>true],'odesa'=>['inTransit'=>0,'openOrders'=>0,'moq'=>0,'receiptsReviewed'=>true]];
$assembly=['consumption'=>['kyiv'=>4.266634367385951,'odesa'=>0]];
$old=lps_purchase_calculate($r,$g,288,false,$input,[9],$assembly);
check($old['groups'][0]['finalQuantity']===0.0 && $old['groups'][1]['target']===3.0,'Reproduce winter order truncated by Odessa MAX');
foreach($g as &$group)$group['respectMaximumStock']=false;unset($group);
$new=lps_purchase_calculate($r,$g,288,false,$input,[9],$assembly);
check(abs($new['groups'][1]['ownTarget']-10.895833333333334)<1e-9,'Keep all winter demand and MIN for Odessa');
check($new['groups'][1]['plannedTransferIn']===10.0 && $new['groups'][0]['plannedTransferOut']===10.0,'Full period replenishment charged to wholesale once');
check($new['groups'][0]['finalQuantity']===12.0 && $new['groups'][1]['finalQuantity']===0.0,'Winter supplier order buys 12 at wholesale; destination never buys directly');
check($new['groups'][0]['componentDemand']===4.266634367385951,'Child consumption retained');
$r['warehouseBreakdown'][0]['orderPolicy']['maximumStockLimited']=true;
$r['warehouseBreakdown'][0]['orderPolicy']['maximumStockLimit']=3;
check(lps_purchase_calculate($r,$g,288,false,$input,[9],$assembly)['groups'][0]['finalQuantity']===12.0,'Disabled Kyiv MAX does not block rounded receipt');
$mixed=$g;$mixed[0]['respectMaximumStock']=true;
check(lps_purchase_calculate($r,$mixed,288,false,$input,[9],$assembly)['groups'][0]['finalQuantity']===null,'Enabled Kyiv MAX still blocks excessive receipt independently');
$mixed=$g;$mixed[1]['respectMaximumStock']=true;
check(lps_purchase_calculate($r,$mixed,288,false,$input,[9],$assembly)['groups'][1]['target']===3.0,'Enabled Odessa MAX still applies independently');
foreach(['MINIMUM_EXCEEDS_MAXIMUM','INVALID_NEGATIVE_MAXIMUM','MAXIMUM_STOCK_NOT_SET'] as $reason){
 $p=['minimumStock'=>1,'reserveAboveForecast'=>1,'replenishmentMode'=>'FORECAST_PLUS_MINIMUM_STOCK','orderAllowed'=>null,'validationState'=>$reason];
 check(lps_purchase_without_maximum($p)['orderAllowed']===true,'Ignore only maximum-derived policy error: '.$reason);
 $badMax=$r;$badMax['warehouseBreakdown'][0]['orderPolicy']=$p;
 $badMax['networkOrderPolicy']=['policyWarehouseId'=>7,'policy'=>$p,'orderAllowed'=>null,'status'=>'CURRENT_POLICY_REVIEW_REQUIRED'];
 check(lps_purchase_calculate($badMax,$g,288,false,$input,[9],$assembly)['groups'][0]['finalQuantity']===12.0,'MAX-only errors do not block the network purchase');
 $p['minimumStock']=null;check(lps_purchase_without_maximum($p)['orderAllowed']===null,'Unknown MIN remains blocked');
}
$unrelated=$r;$unrelated['networkOrderPolicy']['status']='UNCONFIRMED';
check(lps_purchase_calculate($unrelated,$g,288,false,$input,[9],$assembly)['groups'][0]['finalQuantity']===null,'MAX setting never bypasses unrelated network readiness');
$p=['minimumStock'=>0,'reserveAboveForecast'=>0,'replenishmentMode'=>'DO_NOT_ORDER','orderAllowed'=>false,'validationState'=>'VALID'];
check(lps_purchase_without_maximum($p)['orderAllowed']===false,'MIN zero still forbids buying');
$r['warehouseBreakdown'][0]['orderPolicy']=$p;
$r['networkOrderPolicy']=['policyWarehouseId'=>7,'policy'=>$p,'orderAllowed'=>false,'status'=>'DO_NOT_ORDER'];
check(lps_purchase_calculate($r,$g,288,false,$input,[9],$assembly)['groups'][0]['finalQuantity']!==12.0,'Ignoring MAX cannot bypass wholesale MIN zero');
echo "PASS: independent MAX switches, KR-17009 winter reserve, child demand, legacy defaults and MIN safeguards\n";
