const test=require('node:test'),assert=require('node:assert/strict');
const manager=require('../profit-manager.js'),writer=require('../profit-xlsx.js');
const labels=new Proxy({fields:new Proxy({},{get:(_,key)=>key})},{get:(o,k)=>o[k]||k});
function data(){return {month:'2026-07',calculatedAt:'2026-09-15T12:00:00Z',complete:true,
 inputs:{kyivEmployeeCount:4,odesaEmployeeCount:3,odesaTaxShare:'0.4285714286'},controls:{auditTruncated:false},
 cities:[{city:'KYIV',baseGrossProfit:'100',manualGrossAdjustments:'0',grossProfit:'100',operatingExpenses:'3.12',profit:'96.88'}, {city:'ODESA',baseGrossProfit:'100',manualGrossAdjustments:'0',grossProfit:'100',operatingExpenses:'0.08',profit:'99.92'}],
 expenseLines:[
 {lineId:'KYIV_TAX_MALAFOP',city:'KYIV',amount:'0.12',profitImpact:'0.12',documentCount:2,accountingTreatment:'OPERATING_EXPENSE'},
 {lineId:'KYIV_TAX_KONDFOP',city:'KYIV',amount:'3.00',profitImpact:'3.00',documentCount:1,accountingTreatment:'OPERATING_EXPENSE'},
 {lineId:'KYIV_IMPORT_TRANSPORT',city:'KYIV',amount:'12.00',profitImpact:'0.00',documentCount:1,accountingTreatment:'CAPITALIZED_IN_INVENTORY'},
 {lineId:'ODESA_TAX_MALAFOP',city:'ODESA',amount:'0.08',profitImpact:'0.08',documentCount:2,accountingTreatment:'OPERATING_EXPENSE'}],
 documents:[1,2].map(paymentId=>({paymentId,documentNumber:'=HYPERLINK("bad")',documentDate:'2026-07-01',reportAmount:'0.10',kyivAllocation:'0.06',odesaAllocation:'0.04',expenseLineIds:['KYIV_TAX_MALAFOP','ODESA_TAX_MALAFOP'],includedInProfit:true}))};}
test('template columns, separate pools, per-document rounding and import excluded',()=>{
 const [k,o]=manager.sheets(data(),labels);
 assert.equal(k.rows[7].length,9);assert.equal(k.rows[8][1],'retailTax');assert.equal(k.rows[9][1],'wholesaleTax');assert.equal(k.rows[9][4],'КОНДФОП');
 assert.equal(k.rows[8][7].value,'0.12');assert.equal(o.rows[8][7].value,'0.08');
 assert.match(k.rows.find(r=>r[1]==='operatingExpenses')[7].formula,/SUM\(H9,H10\)/);
 assert.equal(o.rows.filter(r=>r[1]==='wholesaleTax').length,0);
 assert.equal(k.rows[8][8],'');assert.equal(k.rows[2][7].formula,'SUM(H4:H5)');
 assert.equal(k.rows.filter(r=>r[8]?.formula?.includes('ROUND')).length,2);
 const xml=new TextDecoder().decode(writer.build([k,o]));
 assert.match(xml,/<f>H\d+-ROUND\(H\d+\*\$H\$5\/\$H\$3,2\)<\/f>/);
 assert.match(xml,/inlineStr.*?HYPERLINK/s);assert.doesNotMatch(xml,/<f>[^<]*HYPERLINK/);
});
test('truncated, missing or duplicate documents never generate a partial tax formula',()=>{
 for(const mutate of [d=>d.controls.auditTruncated=true,d=>d.documents.pop(),d=>d.documents[1].paymentId=1,d=>d.documents[0].kyivAllocation=null]){
  const d=data();mutate(d);const k=manager.sheets(d,labels)[0];assert.equal(k.rows[8][7].type,'number');assert.equal(k.rows[8][7].value,'0.12');
 }
});
test('legacy revisions keep the original share and do not invent employees',()=>{
 const d=data();d.inputs={odesaTaxShare:'0.41'};const k=manager.sheets(d,labels)[0];assert.equal(k.rows[3][7],'—');assert.equal(k.rows[2][7],'—');assert.equal(k.rows[5][7].type,'number');
});
test('zero city count is retained, unavailable totals remain unavailable',()=>{
 const d=data();d.inputs={kyivEmployeeCount:7,odesaEmployeeCount:0,odesaTaxShare:'0'};d.cities[1].profit=null;
 const o=manager.sheets(d,labels)[1];assert.equal(o.rows[4][7].value,'0');assert.equal(o.rows.find(r=>r[1]==='profit')[7],'—');
});
module.exports={data,labels};
test('historical tax firms use snapshot selection columns, including explicitly empty lists',()=>{
 const d=data();d.expenseLines[0].filters={purposeCodes:['МИХНФОП','МАЛАФОП']};d.expenseLines[1].filters={purposeCodes:['КУЗНФОП','КОНДФОП']};
 const k=manager.sheets(d,labels)[0];assert.equal(k.rows[8][4],'МИХНФОП / МАЛАФОП');assert.equal(k.rows[9][4],'КУЗНФОП / КОНДФОП');
 assert.deepEqual(manager.purpose({...d.expenseLines[0],filters:{purposeCodes:[]}},{retailFirmCodes:[],wholesaleFirmCodes:[]}),[]);
});

test('legacy empty selection metadata retains the old firm rather than inventing an empty configuration',()=>{
 assert.deepEqual(manager.purpose({lineId:'KYIV_TAX_MALAFOP',filters:{purposeCodes:[]}}),['МАЛАФОП']);
});

function currentData(){
 const d=data();d.inputs={taxAllocationMethod:'ALL_TAXES_KYIV'};
 const line=(city,id,order,amount='0',extra={})=>({city,lineId:city+'_'+id,label:id,sortOrder:order,amount,profitImpact:amount,source:'FOLIO',accountingTreatment:'OPERATING_EXPENSE',filters:{expenseCodes:[id],cashWarehouseMode:'ALL',bankWarehouseMode:'ALL'},...extra});
 d.expenseLines=[line('KYIV','SALARY',30,'10'),line('KYIV','SALARY_RUB',40,'2'),line('KYIV','ADDITIONAL_WORK',50,'0',{source:'NOT_APPLICABLE'}),line('KYIV','BANK_SERVICES',60,'1'),line('KYIV','TAXES',70,'3',{filters:{purposeCodes:['МИХНФОП','КОНДФОП'],cashWarehouseMode:'ALL',bankWarehouseMode:'ALL'}}),line('KYIV','RENT_WHOLESALE',150,'4'),line('KYIV','PHONE_KAL',160,'5'),line('KYIV','IMPORT_TRANSPORT',170,'12',{profitImpact:'0',accountingTreatment:'CAPITALIZED_IN_INVENTORY'}),line('ODESA','SALARY',30,'8'),line('ODESA','SALARY_RUB',40,'0',{source:'NOT_APPLICABLE'}),line('ODESA','ADDITIONAL_WORK',50,'5',{source:'REQUEST_OVERRIDE'}),line('ODESA','BANK_SERVICES',60,'0',{source:'NOT_APPLICABLE'}),line('ODESA','TAXES',70,'0',{source:'NOT_APPLICABLE'})];
 d.cities[0]={city:'KYIV',baseGrossProfit:'100',manualGrossAdjustments:'7',grossProfit:'107',operatingExpenses:'25',profit:'82'};d.cities[1]={city:'ODESA',baseGrossProfit:'100',manualGrossAdjustments:'7',grossProfit:'107',operatingExpenses:'13',profit:'94'};
 d.masterClassesByCity=Object.fromEntries(['KYIV','ODESA'].map(city=>[city,{sku:'Мастер-Класс июль',warehouseId:city==='KYIV'?1:5,articleFound:true,income:'10',returns:'2',netContribution:'8',grossProfitAlreadyInBase:'1',grossAdjustmentApplied:'7'}]));
 d.masterClass=d.masterClassesByCity.ODESA;d.masterClassDocuments=[{documentId:'o'}];d.masterClassDocumentsByCity={KYIV:[{documentId:'k'}],ODESA:[{documentId:'o'}]};
 d.grossProfitLines=['KYIV','ODESA'].flatMap(city=>[{city,label:'Own shops',organizationTypes:['S'],warehouseIds:city==='KYIV'?[1,7]:[5],amount:'90'},{city,label:'Other',organizationTypes:['C'],warehouseIds:city==='KYIV'?[1,7]:[5],amount:'10'}]);return d;
}
module.exports.currentData=currentData;
test('manual H formulas and fixed addresses match the owner workbook; API values remain independent in I',()=>{
 const d=require('./fixtures/profit-template-java.json'),[k,o]=manager.sheets(d,labels);
 for(const [s,expected,end,city]of[
  [k,{21:'SUM(H4:H20)',28:'H26-H27',37:'SUM(H28:H36)',38:'H21',39:'H37-H38'},39,'KYIV'],
  [o,{14:'SUM(H4:H13)',21:'H19-H20',30:'SUM(H21:H29)',31:'H14',32:'H30-H31'},32,'ODESA']]){
  assert.equal(s.rows[0][7],'manualTemplateAmount');assert.equal(s.rows[0][8],'siteAmount');
  for(const[r,f]of Object.entries(expected)){assert.equal(s.rows[r-1][7].formula,f);assert.equal(s.rows[r-1][8].type,'number');}
  assert.equal(s.rows[end-1][8].value,d.cities.find(c=>c.city===city).profit);
  assert.equal(s.rows[3][7],'');assert.equal(s.copyStartRow,4);assert.equal(s.copyEndRow,end);
  assert(s.bordered);assert.equal(s.freezeRows,2);
 }
 assert.equal(k.rows[8][8].value,d.expenseLines.find(l=>l.lineId==='KYIV_HOUSEHOLD').amount);
 assert.equal(k.rows.filter(r=>r[2]==='НЕРЕГУЛ').length,1);
 assert.equal(k.rows[14][1],'Услуги банка');assert.equal(k.rows[17][1],'Аренда ОПТ');
 assert.equal(k.rows[25][5],1);assert.equal(o.rows[18][5],5);
 const xml=new TextDecoder().decode(writer.build([k,o]));
 assert.match(xml,/<c r="H21"[^>]*><f>SUM\(H4:H20\)<\/f><v>0<\/v>/);
 assert.match(xml,/<c r="I39"[^>]*><v>90.00<\/v>/);
 assert.doesNotMatch(xml,/<c r="I\d+"[^>]*><f>/);
});
test('single household row uses all-source Java selection once, without frontend splitting or recalculation',()=>{
 const d=structuredClone(require('./fixtures/profit-template-java.json'));
 const line=d.expenseLines.find(l=>l.lineId==='KYIV_HOUSEHOLD');
 Object.assign(line,{label:'Household services and expenses',amount:'123.45',profitImpact:'123.45',filters:{expenseCodes:['НЕРЕГУЛ'],operationTypes:['РАСХОДЫ КИЕВОПТ','РАСХОДЫ КИЕВА'],cashWarehouseMode:'ALL',bankWarehouseMode:'ALL'}});
 const k=manager.sheets(d,labels)[0];assert.equal(k.rows[8][8].value,'123.45');assert.equal(k.rows[8][5],'allWarehouses');assert.equal(k.rows[8][6],'allWarehouses');
 assert.equal(k.rows.filter(r=>r[2]==='НЕРЕГУЛ').length,1);
 assert.equal(k.rows[8][3],'РАСХОДЫ КИЕВОПТ / РАСХОДЫ КИЕВА');
 assert.equal(k.rows[20][8].value,d.cities[0].operatingExpenses);
 // Historical snapshots retain their own narrower criteria and their amounts.
 line.filters.operationTypes=['РАСХОДЫ КИЕВА'];
 assert.equal(manager.sheets(d,labels)[0].rows[8][3],'РАСХОДЫ КИЕВА');
 d.expenseLines=d.expenseLines.filter(l=>l.lineId!=='KYIV_HOUSEHOLD');
 const missing=manager.sheets(d,labels)[0];assert.equal(missing.rows[8][8],'—');assert.equal(missing.rows[8][2],'templateRuleUnavailable');
});
test('unavailable results and unexpected nonzero rows are visible without moving the copy range',()=>{
 const d=structuredClone(require('./fixtures/profit-template-java.json'));
 d.cities[1].profit=null;d.expenseLines.find(r=>r.lineId==='ODESA_BANK_SERVICES').amount='10';
 const o=manager.sheets(d,labels)[1];assert.equal(o.rows[31][8],'—');assert.equal(o.copyEndRow,32);
 assert(o.rows.slice(32).some(r=>r[8]?.value==='10'));assert(o.rows.some(r=>r[0]==='templateExtraHelp'));
 assert.equal(manager.masterDocuments(d).length,4);
});
