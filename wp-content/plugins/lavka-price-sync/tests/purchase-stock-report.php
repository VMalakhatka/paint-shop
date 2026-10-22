<?php
// Reuse the deterministic product fixture; never connects to WordPress/Folio.
require __DIR__ . '/purchase-assembly.php';
$groups = [
    ['code'=>'a','name'=>'A','warehouseIds'=>[1,7],'stockOnlyWarehouseIds'=>[7],'receivingWarehouseId'=>1,'leadTimeDays'=>0,'targetDays'=>30,'safetyDays'=>0],
    ['code'=>'b','name'=>'B','warehouseIds'=>[5],'receivingWarehouseId'=>5,'leadTimeDays'=>0,'targetDays'=>30,'safetyDays'=>0],
];
$row=product('P',100,30);
$row['warehouseBreakdown'][0]['metrics']['availableQuantity']=90;
$row['warehouseBreakdown'][0]['metrics']['expenseQuantity']=35;
$row['warehouseBreakdown'][0]['orderPolicy']['reserveAboveForecast']=10;
$only=$row['warehouseBreakdown'][0]; $only['warehouseId']=7;
$only['metrics']=['physicalQuantity'=>40,'availableQuantity'=>40,'expenseQuantity'=>999,'regularSoldUnits'=>999];
$other=$row['warehouseBreakdown'][0]; $other['warehouseId']=5;
$other['metrics']=['physicalQuantity'=>5,'availableQuantity'=>5,'expenseQuantity'=>20,'regularSoldUnits'=>30,'returnQuantity'=>0];
$other['orderPolicy']['reserveAboveForecast']=0;
$row['warehouseBreakdown'][]=$only; $row['warehouseBreakdown'][]=$other;
$report=lps_purchase_calculate($row,$groups,60,false,[],[],['consumption'=>['a'=>10.0,'b'=>0.0]])['stockReport'];
check($report['total']['physical']===145.0,'Physical stock sums all warehouses including stock-only');
check($report['total']['periodExpense']===55.0,'Actual expenses are independent of regular sales and exclude stock-only movements');
check($report['groups']['a']['excessStock']===95.0,'Surplus subtracts own forecast, minimum and child needs');
check($report['total']['excessStock']===85.0,'Network surplus nets a deficit in another group, not sum of positive surpluses');
check($report['total']['stockMonths']===4.5,'Network months divide summed stocks by summed demand, not average group months');
$withReceipts=lps_purchase_calculate($row,$groups,60,false,['a'=>['openOrders'=>1000]],[],['consumption'=>['a'=>10.0,'b'=>0.0]])['stockReport'];
check($withReceipts['total']===$report['total'],'Incoming orders do not become current stock or surplus');
$row['warehouseBreakdown'][0]['orderPolicy']['orderAllowed']=false;
$row['warehouseBreakdown'][0]['orderPolicy']['reserveAboveForecast']=0;
$result=lps_purchase_calculate($row,$groups,60,false,[],[],['consumption'=>['a'=>10.0,'b'=>0.0]]);
check($result['groups'][0]['finalQuantity']===null && $result['stockReport']['groups']['a']['excessStock']===105.0,'Procurement ban must not hide surplus');
$row['warehouseBreakdown'][0]['metrics']['expenseQuantity']=null;
check(lps_purchase_calculate($row,$groups,60,false,[],[])['stockReport']['total']['periodExpense']===null,'Old Java or missing source expense is unknown, not sales or zero');
$row['warehouseBreakdown'][0]['metrics']['regularSoldUnits']=null;
check(lps_purchase_calculate($row,$groups,60,false,[],[])['stockReport']['total']['stockMonths']===null,'Missing demand must never turn into infinity');
check(lps_purchase_stock_months(10,0,30)===9999.0,'Positive stock with no regular demand displays 9999');
check(lps_purchase_stock_months(0,0,30)===0.0 && lps_purchase_stock_months(-3,1,30)===0.0,'No usable stock is zero months, not infinity');
$bad=lps_purchase_calculate(product('P',10,0),[$groups[1]+[]],30,false,[],[]);
check($bad['stockReport']['total']['physical']===null,'Missing warehouse never invents zero stock');
echo "PASS: period expense, warehouse totals, child-aware surplus, no-sale 9999, missing data, MIN=0 and current-stock-only coverage\n";
