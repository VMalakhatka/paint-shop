const {test}=require('node:test'),assert=require('node:assert/strict');
const {average,compare,rows,validRefs}=require('../profit-comparison.js');
test('equal weights use exact decimals and the unrounded average for percent',()=>{
 const m=average(['0.01','0.02','0.02']);assert.equal(m.count,3);
 assert.deepEqual(compare('0.02',m),{average:'0.02',delta:'0.00',percent:'20.00'});
 assert.deepEqual(compare('150',average(['100','100'])),{average:'100.00',delta:'50.00',percent:'50.00'});
});
test('negative and zero baselines have defined directional deviations',()=>{
 assert.equal(compare('-50',average(['-100','-100'])).percent,'50.00');
 assert.equal(compare('-150',average(['-100'])).percent,'-50.00');
 assert.equal(compare('5',average(['0'])).percent,null);assert.equal(compare('0',average(['0'])).percent,'0.00');
});
test('missing values never become zero or silently change the denominator',()=>{
 const m=average(['100',null]);assert.equal(m.count,1);assert.equal(m.complete,false);
 assert.equal(compare('100',m).average,null);assert.equal(average([]).complete,false);
 assert.deepEqual(compare(null,average(['100'])),{average:'100.00',delta:null,percent:null});
});
test('row identity uses stable city and line IDs; duplicates are unavailable',()=>{
 const r={expenseLines:[{city:'KYIV',lineId:'RENT',label:'Rent',amount:'1',accountingTreatment:'OPERATING_EXPENSE'},{city:'ODESA',lineId:'RENT',label:'Rent',amount:'2',accountingTreatment:'OPERATING_EXPENSE'}]};
 assert.equal(rows(r,{}).get('KYIV:expense:RENT').value,'1');assert.equal(rows(r,{}).get('ODESA:expense:RENT').value,'2');
 r.expenseLines.push(r.expenseLines[0]);assert.equal(rows(r,{}).get('KYIV:expense:RENT').value,null);
});
test('reference validation rejects duplicate months and limits retained snapshots',()=>{
 const ref={month:'2025-10',revisionId:'45'};assert(validRefs([ref]));assert(!validRefs([ref,ref]));assert(!validRefs([{...ref,revisionId:'bad'}]));
 assert(!validRefs([{...ref,month:'2025-13'}]));
});
const {previousMonths,rowBaseline}=require('../profit-comparison.js');
const manager=require('../profit-manager.js');
const labels=new Proxy({fields:new Proxy({},{get:(_,k)=>k})},{get:(o,k)=>o[k]||k});
test('comparison row order and numbering come from the manager workbook, Kyiv then Odesa',()=>{
 const d=require('./fixtures/profit-template-java.json'),layout=manager.comparisonLayout(d,labels),sheets=manager.sheets(d,labels);
 const expected=sheets.flatMap(s=>s.rows.filter(r=>typeof r[0]==='number').map(r=>[r[0],r[1]]));
 assert.deepEqual(layout.map(r=>[r.number,r.label]),expected);
 assert.equal(layout[0].id,'KYIV:expense:KYIV_RENT_SHOP');
 const split=layout.findIndex(r=>r.city==='ODESA');assert(layout.slice(split).every(r=>r.city==='ODESA'));
 assert.equal(layout.filter(r=>r.id==='KYIV:total:operatingExpenses').length,2);
});
test('rolling average uses only four preceding calendar months and at least one available value',()=>{
 assert.deepEqual(previousMonths('2026-01'),['2025-09','2025-10','2025-11','2025-12']);
 const h=(month,amount)=>({month,report:{cities:[{city:'KYIV',profit:amount}]}}),id='KYIV:total:profit';
 const history=[h('2025-05','999'),h('2025-08','0'),h('2025-09','100'),h('2025-10','999'),h('2025-11','999')];
 const b=rowBaseline(id,'2025-10',history,{});assert.equal(b.count,2);assert.equal(b.mean.total,4);assert.equal(compare('300',b.mean).average,'50.00');
 assert.equal(compare('300',rowBaseline(id,'2025-10',[h('2025-09','100')],{}).mean).percent,'200.00');
 assert.equal(rowBaseline(id,'2025-10',[h('2025-05','999')],{}).source,'required');
});
test('manual baseline overrides history, retains explicit zero and accepts no-history rows',()=>{
 const id='KYIV:total:profit',history=[{month:'2025-09',report:{cities:[{city:'KYIV',profit:'100'}]}}];
 assert.equal(compare('300',rowBaseline(id,'2025-10',history,{[id]:'200'}).mean).percent,'50.00');
 assert.equal(rowBaseline(id,'2025-10',history,{[id]:'0'}).source,'manual');
 assert.equal(compare('300',rowBaseline(id,'2025-10',history,{[id]:'0'}).mean).percent,null);
 assert.equal(compare('300',rowBaseline(id,'2025-10',[],{[id]:'-100'}).mean).percent,'400.00');
});
