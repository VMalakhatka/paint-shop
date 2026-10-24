const {test}=require('node:test'),assert=require('node:assert/strict'),{chromium}=require('playwright');
const fs=require('node:fs'),path=require('node:path'),{execFileSync}=require('node:child_process');
const base=path.resolve(__dirname,'..'),out='/tmp/profit-scope-settings';fs.mkdirSync(out,{recursive:true});
const html=execFileSync(process.env.PHP_BIN||'php',[path.join(__dirname,'profit-history-page.php')],{encoding:'utf8'});
const source=fs.readFileSync(path.join(base,'inc/class-profit-scope-settings.php'),'utf8');
const labels=Object.fromEntries([...source.matchAll(/'([a-zA-Z]+)'=>__\('([^']+)'/g)].map(m=>[m[1],m[2]]));
const settings={version:2,kyivWarehouseIds:[1,7],odesaWarehouseIds:[5],kyivStockWarehouseIds:[1,7,12],odesaStockWarehouseIds:[5],excludedInventoryGroupIds:[],excludedInventorySubgroupIds:[]};
const paths={10:['Матеріали'],11:['Послуги'],20:['Матеріали','Фарби'],21:['Послуги','Майстер-класи'],22:['Послуги','Майстер-класи','Дитячі'],99:['Видалена']};
const branch=id=>'g:'+Buffer.from(JSON.stringify(paths[id])).toString('base64url');
const catalog={warehouses:[{id:1,name:'Київ'},{id:5,name:'Одеса'},{id:7,name:'Опт'},{id:12,name:'Резерв'}],groups:[10,11].map(id=>({id:branch(id),name:paths[id].at(-1),path:paths[id]})),subgroups:[20,21,22].map(id=>({id:branch(id),groupId:branch(id===20?10:11),parentId:branch(id===22?21:id===20?10:11),level:paths[id].length,path:paths[id],name:paths[id].at(-1)}))};
let browser;
test.before(async()=>{browser=await chromium.launch({headless:true,...(process.env.CHROME_BIN?{executablePath:process.env.CHROME_BIN}:{})});});test.after(async()=>browser.close());
async function setup(mode='normal'){
 const page=await browser.newPage({viewport:{width:1280,height:960}}),calls=[],errors=[];let stored=structuredClone(settings);
 if(mode==='missing')stored.excludedInventoryGroupIds=[branch(99)];
 page.on('pageerror',e=>errors.push(e.message));
 await page.route('https://scope.test/**',async route=>{
  if(!route.request().url().endsWith('/ajax'))return route.fulfill({contentType:'text/html',body:html});
  const q=Object.fromEntries(new URLSearchParams(route.request().postData()));calls.push(q);
  if(mode==='unavailable')return route.fulfill({json:{success:true,data:{httpStatus:404,bodyRaw:'{}'}}});
  if(q.operation==='save'){
   if(['conflict','invalid'].includes(mode))return route.fulfill({json:{success:true,data:{httpStatus:mode==='conflict'?409:400,bodyRaw:'{}'}}});
   if(mode==='lost')return route.fulfill({json:{success:false,data:{}}});
   const data=JSON.parse(q.settings);assert.equal(data.version,stored.version);stored={...data,version:stored.version+1};
  }
  const body=q.operation==='catalog'?catalog:mode==='changed'&&calls.filter(c=>c.operation==='get').length>1?{...stored,version:3}:stored;
  return route.fulfill({json:{success:true,data:{httpStatus:200,bodyRaw:JSON.stringify(body)}}});
 });
 await page.goto('https://scope.test/');await page.addStyleTag({content:'body{font-family:Arial;margin:20px}*{box-sizing:border-box}button{padding:8px}'});await page.addStyleTag({path:path.join(base,'profit-report.css')});
 await page.evaluate(i18n=>window.LavkaProfitScopeSettingsConfig={ajaxUrl:'https://scope.test/ajax',action:'scope',nonce:'test',i18n},labels);
 await page.addScriptTag({path:path.join(base,'profit-scope-settings.js')});await page.locator('#lavr-profit-scope-settings summary').click();
 await page.waitForFunction(()=>document.querySelectorAll('#lps-fields input').length||!document.getElementById('lps-error').hidden);
 await page.waitForFunction(()=>!document.getElementById('lps-reload').disabled);return {page,calls,errors};
}
const choice=(page,key,id)=>page.locator('[data-field="'+key+'"] input[type=checkbox][value="'+(key.startsWith('excluded')?branch(id):id)+'"]');
test('group exclusion covers children without losing explicit subgroup choices; save and mobile',async()=>{
 const {page,calls,errors}=await setup();await choice(page,'excludedInventorySubgroupIds',21).check();assert.equal(await choice(page,'excludedInventorySubgroupIds',22).isDisabled(),true);assert.equal(await choice(page,'excludedInventorySubgroupIds',22).isChecked(),true);await choice(page,'excludedInventoryGroupIds',11).check();
 assert.equal(await choice(page,'excludedInventorySubgroupIds',21).isDisabled(),true);assert.equal(await choice(page,'excludedInventorySubgroupIds',21).isChecked(),true);
 assert.match(await page.locator('[data-field="excludedInventorySubgroupIds"]').innerText(),/Excluded with its group/);
 assert.match(await page.evaluate(()=>{try{window.LavkaProfitScopeSettings.assertReady();return '';}catch(e){return e.message;}}),/Unsaved/);
 await choice(page,'excludedInventoryGroupIds',11).uncheck();assert.equal(await choice(page,'excludedInventorySubgroupIds',21).isChecked(),true);
 await choice(page,'excludedInventoryGroupIds',11).check();await page.locator('#lps-save').click();await page.waitForFunction(()=>document.getElementById('lps-state').textContent.includes('Settings saved'));
 const sent=JSON.parse(calls.find(c=>c.operation==='save').settings);assert.deepEqual(sent.excludedInventoryGroupIds,[branch(11)]);assert.deepEqual(sent.excludedInventorySubgroupIds,[branch(21)]);assert.deepEqual(sent.kyivWarehouseIds,[1,7]);
 await page.locator('#lavr-profit-scope-settings').screenshot({path:path.join(out,'desktop.png')});await page.setViewportSize({width:390,height:844});assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),true);await page.locator('#lavr-profit-scope-settings').screenshot({path:path.join(out,'mobile.png')});
 await page.evaluate(()=>window.LavkaProfitScopeSettings.setReportBusy(true));assert.equal(await page.locator('#lps-reload').isDisabled(),true);assert.equal(await choice(page,'kyivWarehouseIds',1).isDisabled(),true);assert.deepEqual(errors,[]);await page.close();
});
test('empty warehouses and overlapping cities are rejected; search does not discard selection',async()=>{
 const {page,calls}=await setup();await choice(page,'odesaWarehouseIds',1).check();await page.locator('#lps-save').click();assert.match(await page.locator('#lps-error').innerText(),/overlaps/);assert(!calls.some(c=>c.operation==='save'));
 await choice(page,'odesaWarehouseIds',1).uncheck();await choice(page,'odesaWarehouseIds',5).uncheck();await page.locator('#lps-save').click();assert(!calls.some(c=>c.operation==='save'));
 await choice(page,'odesaWarehouseIds',5).check();await page.locator('[data-field="odesaWarehouseIds"] input[type=search]').fill('Опт');await page.locator('#lps-save').click();await page.waitForFunction(()=>document.getElementById('lps-state').textContent.includes('Settings saved'));assert.deepEqual(JSON.parse(calls.find(c=>c.operation==='save').settings).odesaWarehouseIds,[5]);await page.close();
});
test('conflict or lost save keeps edits, locks retry and allows explicit reload',async()=>{
 for(const mode of ['conflict','lost']){
  const {page,calls}=await setup(mode);await choice(page,'excludedInventoryGroupIds',11).check();await page.locator('#lps-save').click();await page.waitForFunction(()=>!document.getElementById('lps-error').hidden);
  assert.equal(await choice(page,'excludedInventoryGroupIds',11).isChecked(),true);assert.equal(await page.locator('#lps-save').isDisabled(),true);assert.equal(calls.filter(c=>c.operation==='save').length,1);
  page.once('dialog',d=>d.accept());await page.locator('#lps-reload').click();await page.waitForFunction(()=>!document.getElementById('lps-reload').disabled);assert.equal(await choice(page,'excludedInventoryGroupIds',11).isChecked(),false);assert.equal(calls.filter(c=>c.operation==='save').length,1);await page.close();
 }
});
test('old Java is not treated as empty settings; missing catalog IDs remain visible',async()=>{
 const {page,calls}=await setup('unavailable');assert.equal(await page.locator('#lps-save').isDisabled(),true);assert.match(await page.locator('#lps-error').innerText(),/Java/);assert(!calls.some(c=>c.operation==='save'));await page.close();
 const missing=await setup('missing');assert.equal(await choice(missing.page,'excludedInventoryGroupIds',99).isChecked(),true);assert.match(await missing.page.locator('.lps-missing').innerText(),/Not in/);await choice(missing.page,'excludedInventoryGroupIds',99).uncheck();await missing.page.locator('#lps-save').click();await missing.page.waitForFunction(()=>document.getElementById('lps-state').textContent.includes('Settings saved'));await missing.page.close();
});
test('a newer reviewed version blocks calculation instead of silently replacing it',async()=>{
 const {page}=await setup('changed');const error=await page.evaluate(async()=>{try{await window.LavkaProfitScopeSettings.calculationVersion();return '';}catch(e){return e.message;}});assert.match(error,/Another user/);assert.equal(await choice(page,'kyivWarehouseIds',1).isDisabled(),true);await page.close();
});
test('confirmed validation rejection allows correction',async()=>{
 const {page}=await setup('invalid');await choice(page,'excludedInventoryGroupIds',11).check();await page.locator('#lps-save').click();await page.waitForFunction(()=>!document.getElementById('lps-error').hidden);assert.equal(await page.locator('#lps-save').isDisabled(),false);assert.equal(await choice(page,'excludedInventoryGroupIds',11).isDisabled(),false);await page.close();
});
