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
