<?php
if (PHP_SAPI !== 'cli') exit;
define('ABSPATH', __DIR__);
function __($s, $d='') { return $s; }
function sanitize_text_field($s) { return (string)$s; }
require __DIR__ . '/../inc/purchase-planning-model.php';
require __DIR__ . '/../inc/purchase-assembly.php';
function check($ok, $message) { if (!$ok) throw new RuntimeException($message); }
function product($sku, $stock, $sales, $supplier='Supplier'): array {
    return ['sku'=>$sku,'dimensions'=>['currentSuppliers'=>[$supplier],'packageQuantity'=>1,'minimumOrderQuantity'=>0],
        'metrics'=>['grossProfit'=>10], 'networkOrderPolicy'=>['orderAllowed'=>true,'status'=>'ALLOWED'],
        'internalTransferReservations'=>['calculationVersion'=>1,'status'=>'CAPTURED','accounts'=>[]],
        'warehouseBreakdown'=>[['warehouseId'=>1,'metrics'=>['physicalQuantity'=>$stock,'availableQuantity'=>$stock,'regularSoldUnits'=>$sales,'returnQuantity'=>0],
            'orderPolicy'=>['orderAllowed'=>true,'reserveAboveForecast'=>0,'maximumStockLimited'=>false]]]];
}
function node($sku,$child): array {return ['sku'=>$sku,'manufactured'=>$child,'issues'=>[]];}
function edge($parent,$child,$factor): array {return ['parent'=>$parent,'child'=>$child,'factor'=>$factor,'source'=>'ALL_RAZBORKA_SLOJ','rowId'=>$parent.$child];}
function calculate($s): array {return array_column(lps_purchase_calculate_network($s),null,'sku');}
$s=['rootSkus'=>['P','Q','CHILD'],'orderSupplier'=>'Supplier','periodDays'=>30,'allowTransfers'=>false,'transitWarehouseIds'=>[],
    'groups'=>[['code'=>'group','name'=>'Group','warehouseIds'=>[1],'receivingWarehouseId'=>1,'leadTimeDays'=>0,'targetDays'=>30,'safetyDays'=>0,'packRounding'=>'NONE']],
    'rows'=>['P'=>product('P',2,3),'Q'=>product('Q',0,0),'CHILD'=>product('CHILD',4,14,'Our company')],
    'edits'=>['P'=>['group'=>['openOrders'=>0]],'Q'=>['group'=>['openOrders'=>0]],'CHILD'=>['group'=>['openOrders'=>0]]],
    'assemblyGraph'=>['nodes'=>[node('P',false),node('Q',false),node('CHILD',true)],'edges'=>[edge('P','CHILD',0.25),edge('Q','CHILD',2),edge('FOREIGN','CHILD',0.1)]]];
$a=calculate($s);
check(count($a)===3,'Selected child must not be duplicated; external component does not become a demand root');
check($a['CHILD']['groups'][0]['manufacturingNeed']===10.0,'Use child shortage, not gross forecast');
check($a['P']['groups'][0]['componentDemand']===2.5 && $a['Q']['groups'][0]['componentDemand']===20.0,'Each component receives its coefficient of the same shortage');
check($a['P']['groups'][0]['finalQuantity']===3.0,'Add own demand, net parent stock once, then existing supplier rounding');
check(!$a['CHILD']['assembly']['purchasable'] && $a['P']['assembly']['purchasable'],'Child excluded even when in initial selection');
check($a['CHILD']['groups'][0]['finalQuantity']===0.0,'Never order manufactured child');
$s['edits']['CHILD']['group']['openOrders']=7;
$a=calculate($s);
check($a['P']['groups'][0]['componentDemand']===0.75 && $a['Q']['groups'][0]['componentDemand']===6.0,'Child receipt edit updates every component');
$s['rows']['Q']['dimensions']['currentSuppliers']=['Foreign'];
check(!calculate($s)['Q']['assembly']['purchasable'],'Foreign supplier cannot leak into export even if accidentally returned as root');
$s['rows']['CHILD']['warehouseBreakdown'][0]['orderPolicy']['orderAllowed']=false;
$s['rows']['CHILD']['networkOrderPolicy']['orderAllowed']=false;
check(calculate($s)['CHILD']['groups'][0]['manufacturingNeed']===3.0,'Purchase ban on child does not prohibit forecasting manufacturing');
unset($s['edits']['CHILD']);
$a=calculate($s);
check($a['P']['groups'][0]['finalQuantity']===null && in_array('ASSEMBLY_DEPENDENCY_REQUIRED',$a['P']['groups'][0]['issues'],true),'Unknown receipts block parent, never treated as zero shortage');
check($a['P']['groups'][0]['componentDemand']===null && $a['P']['groups'][0]['target']===null,'Unknown component demand is displayed as unknown, not a partial sum or zero');
unset($s['rows']['CHILD']);
check(calculate($s)['P']['groups'][0]['finalQuantity']===null,'Missing descendant analytics blocks parent');

// Diamond: grandchild D consumes intermediate B and C, both consume A.
$s['rootSkus']=['A']; $s['rows']=[]; $s['edits']=[];
foreach (['A','B','C','D'] as $sku) { $s['rows'][$sku]=product($sku,$sku==='B'?1:0,$sku==='D'?10:0); $s['edits'][$sku]=['group'=>['openOrders'=>0]]; }
$s['assemblyGraph']=['nodes'=>[node('A',false),node('B',true),node('C',true),node('D',true)],
    'edges'=>[edge('A','B',0.5),edge('A','C',0.1),edge('B','D',0.25),edge('C','D',2)]];
$a=calculate($s);
check($a['D']['groups'][0]['manufacturingNeed']===10.0,'Shared grandchild forecast once');
check($a['B']['groups'][0]['manufacturingNeed']===1.5,'Intermediate stock netted before propagating');
check($a['A']['groups'][0]['componentDemand']===2.75,'Diamond accumulates real distinct component paths without rounding intermediates');
$s['assemblyGraph']['edges'][]=edge('D','A',1);
check(in_array('ASSEMBLY_CYCLE',calculate($s)['A']['groups'][0]['issues'],true),'Cycle blocked without recursion');
array_pop($s['assemblyGraph']['edges']);
$s['assemblyGraph']['nodes'][3]['issues']=['ASSEMBLY_INVALID_RECIPE'];
check(calculate($s)['A']['groups'][0]['finalQuantity']===null,'Invalid grandchild recipe blocks ancestor');
echo "PASS: supplier isolation, shared children, incoming stock, purchase bans, diamond chain, fractional factors, incomplete data and cycles\n";

// Rounding/manual choices remain available, but uncovered manufacturing demand is visible.
$s=['rootSkus'=>['P'],'orderSupplier'=>'Supplier','periodDays'=>30,'allowTransfers'=>false,'transitWarehouseIds'=>[],
 'groups'=>[['code'=>'group','name'=>'Group','warehouseIds'=>[1],'receivingWarehouseId'=>1,'leadTimeDays'=>0,'targetDays'=>30,'safetyDays'=>0,'packRounding'=>'UP']],
 'rows'=>['P'=>product('P',0,0),'C'=>product('C',0,1)],
 'edits'=>['P'=>['group'=>['openOrders'=>0]],'C'=>['group'=>['openOrders'=>0]]],
 'assemblyGraph'=>['nodes'=>[node('P',false),node('C',true)],'edges'=>[edge('P','C',0.25)]]];
foreach (['UP','DOWN','NONE'] as $mode) {
 $s['groups'][0]['packRounding']=$mode; $a=calculate($s);
 check($a['P']['groups'][0]['finalQuantity']===0.0 && $a['P']['groups'][0]['childCoverageShortfall']===0.25,'Small component shortage survives every rounding mode as a warning');
}
$s['edits']['P']['group']['quantity']=1; $s['edits']['P']['group']['reason']='Cover child production';
check(calculate($s)['P']['groups'][0]['childCoverageShortfall']===0.0,'Manual quantity covering children clears warning');
$s['edits']['P']['group']['quantity']=0;
check(calculate($s)['P']['groups'][0]['childCoverageShortfall']===0.25,'Manual zero keeps warning without silently changing quantity');
unset($s['edits']['P']['group']['quantity']);
$s['edits']['C']['group']['openOrders']=1;
check(calculate($s)['P']['groups'][0]['childCoverageShortfall']===0.0,'Child receipts remove manufacturing deficit and warning');
$s['edits']['C']['group']['openOrders']=0;
$s['rows']['P']=product('P',10.25,10);
check(calculate($s)['P']['groups'][0]['childCoverageShortfall']===0.0,'Stock covers own demand and child demand exactly');
$s['rows']['P']=product('P',10,10);
check(calculate($s)['P']['groups'][0]['childCoverageShortfall']===0.25,'Own forecast remains reserved before child coverage');
unset($s['edits']['P']);
check(calculate($s)['P']['groups'][0]['childCoverageShortfall']===null,'Unknown supply cannot claim zero uncovered demand');
$s['edits']['P']=['group'=>['openOrders'=>0]];
$s['rows']['P']=product('P',0,0.25); $s['rows']['C']=product('C',0,0);
check(calculate($s)['P']['groups'][0]['childCoverageShortfall']===0.0,'Own forecast rounding without children does not generate a child warning');
$s['rows']['P']=product('P',0,0); $s['rows']['C']=product('C',0,0); $s['rows']['G']=product('G',0,4);
$s['edits']['G']=['group'=>['openOrders'=>0]];
$s['assemblyGraph']=['nodes'=>[node('P',false),node('C',true),node('G',true)],'edges'=>[edge('P','C',0.1),edge('C','G',0.25)]];
check(abs(calculate($s)['P']['groups'][0]['childCoverageShortfall']-0.1)<1e-12,'Four grandchildren require one sheet and 0.1 parent pack, not an invisible zero');
echo "PASS: visible child shortages after rounding, manual choices, stock/receipts, unknown inputs and grandchild chain\n";
