<?php
if (PHP_SAPI !== 'cli') exit;
define('ABSPATH', __DIR__);
function __($s, $d='') { return $s; }
function sanitize_key($s) { return strtolower((string)$s); }
function absint($n) { return abs((int)$n); }
function sanitize_text_field($s) { return (string)$s; }
require __DIR__ . '/../inc/purchase-planning-model.php';
require __DIR__ . '/../inc/purchase-assembly.php';
require __DIR__ . '/../inc/purchase-current-stock.php';
require __DIR__ . '/fixtures/current-network-stock.php';
function check($ok, $message) { if (!$ok) throw new RuntimeException($message); }
function rejects($call, $message) { try { $call(); } catch (RuntimeException $e) { return; } throw new RuntimeException($message); }
$confirmed=lps_purchase_profile(['noOtherIncomingOrders'=>true,'noSupplierMinimumOrder'=>true,'currentReceiptsWithinLeadTime'=>true,'allowActualSalesWithoutAvailability'=>true]);
check(lps_purchase_profile($confirmed)===$confirmed,'Supplier confirmations survive normalized scenario roundtrip');
$defaults=lps_purchase_profile([]);
check(!$defaults['noOtherIncomingOrders'] && !$defaults['noSupplierMinimumOrder'] && !$defaults['currentReceiptsWithinLeadTime'] && !$defaults['allowActualSalesWithoutAvailability'],'No assumptions silently enabled for other suppliers');
$graph=['revision'=>'recipe-revision','nodes'=>[['sku'=>'P','manufactured'=>false,'issues'=>[]]],'edges'=>[]];
$history=['sku'=>'P','dimensions'=>['currentSuppliers'=>['Supplier'],'packageQuantity'=>6], 'metrics'=>['grossProfit'=>100],
    'warehouseBreakdown'=>array_map(static fn($id)=>['warehouseId'=>$id,'metrics'=>['physicalQuantity'=>200,'availableQuantity'=>100,'regularSoldUnits'=>30,'returnQuantity'=>2,'expenseQuantity'=>30]], [1,5,7,15]),
    'groupAvailability'=>[['groupCode'=>'kyiv','availableDays'=>20,'stockoutDays'=>10]]];
$groups=[];
foreach (['kyiv'=>[1,7], 'odessa'=>[5,15]] as $code=>$ids) $groups[]=['code'=>$code,'name'=>$code,'warehouseIds'=>$ids,
    'demandWarehouseIds'=>$code==='odessa'?[5]:$ids, 'stockOnlyWarehouseIds'=>$code==='odessa'?[15]:[],
    'receivingWarehouseId'=>$code==='kyiv'?7:5,'minimumWarehouseId'=>$code==='kyiv'?7:5,
    'supplyFromGroupCode'=>$code==='odessa'?'kyiv':'','leadTimeDays'=>45,'targetDays'=>150,'safetyDays'=>0,'packRounding'=>'UP'];
$state=['query'=>['sourceDatabase'=>'Paint_Ua','warehouseIds'=>[1,5,7,15]], 'transitWarehouseIds'=>[9,10], 'assemblyGraph'=>$graph,
    'rows'=>['P'=>$history],'groups'=>$groups,'rootSkus'=>['P'],'orderSupplier'=>'Supplier','periodDays'=>240,'allowTransfers'=>false,
    'supplyConfirmation'=>['noOtherIncomingOrders'=>true,'noSupplierMinimumOrder'=>true,'currentReceiptsWithinLeadTime'=>true]];
$capture=current_stock_fixture(['sourceDatabase'=>'Paint_Ua','warehouseIds'=>[1,5,7,9,10,15],'analysisWarehouseIds'=>[1,5,7,15]], $graph);
foreach ($capture['cards'] as &$card) $card['internalTransferAccounts']=[['sourceWarehouseId'=>$card['warehouseId'],'documentId'=>123,'quantity'=>2]];
unset($card);
$fresh=lps_purchase_apply_current_stock($state,$capture); $row=$fresh['rows']['P'];
check($row['warehouseBreakdown'][0]['metrics']['availableQuantity']===6,'Current free stock replaces historical free stock');
check($row['warehouseBreakdown'][0]['metrics']['regularSoldUnits']===30 && $row['groupAvailability']===$history['groupAvailability'], 'Historical sales and availability are unchanged');
check($row['warehouseBreakdown'][0]['snapshotStockMetrics']['availableQuantity']===100,'Historical stock kept as separate audit evidence');
$transit=lps_purchase_transit($row['inTransitStock'],[9,10],$row['currentStockCapture']);
check($transit['quantity']===16.0,'Transit uses free stock plus source internal reserves, retaining client reserves');
check(lps_purchase_transit($row['inTransitStock'],[9,10])['quantity']===null,'A transit proof cannot be used without the matching sales stock cut');
$a=lps_purchase_calculate_network($fresh)[0]; $g=array_column($a['groups'],null,'groupCode');
check($g['kyiv']['inputs']['inTransit']===16.0 && $g['odessa']['inputs']['inTransit']===0.0,'One transit pool allocated once to procurement group');
check($g['kyiv']['inputs']['openOrders']===0.0 && $g['kyiv']['inputs']['moq']===0.0 && $g['kyiv']['receiptsReviewed'], 'Scenario confirmations remove only confirmed supply blockers');
check($g['kyiv']['inputs']['pack']===6.0,'No MOQ does not remove pack size');
check($g['odessa']['regularSales']===30.0 && $g['odessa']['planningAvailable']===16.0,'Storage contributes stock and internal reserves, no historical demand');
check($g['kyiv']['finalQuantity']!==null, 'Complete consistent source and confirmed supply yield a ready quantity');
$fresh['edits']['P']['kyiv']=['openOrders'=>3,'moq'=>12,'receiptsReviewed'=>false];
$g=lps_purchase_calculate_network($fresh)[0]['groups'][0];
check($g['inputs']['openOrders']===3.0 && $g['inputs']['moq']===12.0 && !$g['receiptsReviewed'],'Explicit item edits override global confirmations');
$bad=$capture; array_pop($bad['cards']); rejects(fn()=>lps_purchase_apply_current_stock($state,$bad),'Truncated cut rejected');
$bad=$capture; $bad['cards'][]=$bad['cards'][0]; rejects(fn()=>lps_purchase_apply_current_stock($state,$bad),'Duplicate cards rejected');
$bad=$capture; $bad['assemblyGraph']['revision']='changed'; rejects(fn()=>lps_purchase_apply_current_stock($state,$bad),'Recipe/role/cold drift rejected');
$bad=$capture; $bad['cards'][0]['internalTransferAccounts'][0]['quantity']=5; rejects(fn()=>lps_purchase_apply_current_stock($state,$bad),'Internal reserve above total reserve rejected');
$bad=$capture; $bad['cards'][3]['status']='SKU_NOT_PRESENT'; $bad['cards'][3]['physicalQuantity']=null; $bad['cards'][3]['availableQuantity']=null;
$missing=lps_purchase_apply_current_stock($state,$bad);
check(lps_purchase_calculate_network($missing)[0]['groups'][0]['finalQuantity']===null,'Explicit missing Transport card is unknown, never zero');
$bad=$capture; $bad['cards'][3]['physicalQuantity']=-1; $bad['cards'][3]['availableQuantity']=-5; $bad['cards'][3]['reservedQuantity']=4;
$negative=lps_purchase_apply_current_stock($state,$bad);
check(lps_purchase_calculate_network($negative)[0]['groups'][0]['finalQuantity']===null,'Negative physical Transport stock remains blocked');
$fresh=$state; $fresh['supplyConfirmation']=[]; $fresh=lps_purchase_apply_current_stock($fresh,$capture);
check(lps_purchase_calculate_network($fresh)[0]['groups'][0]['finalQuantity']===null,'Unconfirmed supplier inputs remain blocked');
$fallback=$state; foreach ($fallback['groups'] as &$group) { $group['stockoutCorrectionEnabled']=true; $group['maxDemandMultiplier']=1.3; }
unset($group); $fallback=lps_purchase_apply_current_stock($fallback,$capture);
check(in_array('STOCKOUT_HISTORY_REQUIRED',lps_purchase_calculate_network($fallback)[0]['groups'][0]['issues'],true),'Missing history remains blocked unless fallback explicitly selected');
foreach ($fallback['groups'] as &$group) $group['allowActualSalesWithoutAvailability']=true;
unset($group); $g=lps_purchase_calculate_network($fallback)[0]['groups'][0];
check($g['finalQuantity']!==null && $g['demand']['adjustedSales']===60.0 && $g['demand']['estimatedLostSales']===null && $g['demand']['appliedLostSales']===null
    && in_array('STOCKOUT_CORRECTION_NOT_CALCULATED',$g['notes'],true),'Explicit fallback uses actual sales with unknown lost-sales estimate and visible warning');
$zero=$capture;
foreach ($zero['cards'] as &$card) if ($card['warehouseId']===5) { $card['orderPolicy']['minimumStock']=0; $card['orderPolicy']['reserveAboveForecast']=0; $card['orderPolicy']['orderAllowed']=false; }
unset($card); $zero=lps_purchase_apply_current_stock($state,$zero);
$zero['assemblyGraph']['nodes'][]=['sku'=>'UNRELATED','manufactured'=>false,'issues'=>['ASSEMBLY_ROLE_REQUIRED']];
$zero['rootSkus'][]='UNRELATED';
$result=array_column(lps_purchase_calculate_network($zero),null,'sku'); $g=array_column($result['P']['groups'],null,'groupCode');
check($g['kyiv']['finalQuantity']!==null && $g['kyiv']['plannedTransferOut']===0.0 && $g['odessa']['finalQuantity']===0.0 && $g['odessa']['plannedTransferIn']===0.0,
    'Odessa MIN=0 excludes replenishment without blocking allowed Kyiv purchase');
check(in_array('GROUP_REPLENISHMENT_DISABLED_BY_MINIMUM',$g['odessa']['notes'],true),'Zero-MIN exclusion visibly explained');
check($result['UNRELATED']['groups'][0]['finalQuantity']===null,'Unrelated missing node cannot block healthy product');
echo "PASS: atomic stock cut, history preservation, reserve ownership, client reserves, missing cards, one transit allocation, scenario confirmations and manual overrides\n";
