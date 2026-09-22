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
 assert.deepEqual(manager.purpose({...d.expenseLines[0],filters:{purposeCodes:[]}}),[]);
});
