const {test}=require('node:test'),assert=require('node:assert/strict'),{chromium}=require('playwright');
const fs=require('node:fs'),path=require('node:path'),{execFileSync}=require('node:child_process');
const base=path.resolve(__dirname,'..'),out='/tmp/profit-tax-settings';fs.mkdirSync(out,{recursive:true});
const html=execFileSync(process.env.PHP_BIN||'php',[path.join(__dirname,'profit-history-page.php')],{encoding:'utf8'});
const source=fs.readFileSync(path.join(base,'inc/class-profit-tax-settings.php'),'utf8');
// Test labels are literal msgids; actual catalogs are separately compiled and checked.
const labels=Object.fromEntries([...source.matchAll(/'([a-z]+)'=>__\('([^']+)'/g)].map(m=>[m[1],m[2]]));
let browser;
test.before(async()=>{browser=await chromium.launch({headless:true,...(process.env.CHROME_BIN?{executablePath:process.env.CHROME_BIN}:{})});});test.after(async()=>browser.close());
async function setup(mode='normal'){
 const page=await browser.newPage({viewport:{width:1280,height:960}}),calls=[],errors=[];
 let stored={version:0,retailFirmCodes:['МИХНФОП','МАЛАФОП'],wholesaleFirmCodes:['КУЗНФОП','КОНДФОП']};
 page.on('pageerror',e=>errors.push(e.message));
 await page.route('https://tax.test/**',async route=>{
  if(!route.request().url().endsWith('/ajax'))return route.fulfill({contentType:'text/html',body:html});
  const request=Object.fromEntries(new URLSearchParams(route.request().postData()));calls.push(request);
  if(request.operation==='save'){
   if(mode==='invalid')return route.fulfill({json:{success:true,data:{httpStatus:400,bodyRaw:'{}'}}});
   if(mode==='conflict')return route.fulfill({json:{success:true,data:{httpStatus:409,bodyRaw:'{}'}}});
   if(mode==='lost')return route.fulfill({json:{success:false,data:{message:'lost'}}});
   const data=JSON.parse(request.settings);assert.equal(data.version,stored.version);stored={...data,version:stored.version+1};
  }
  if(mode==='unavailable')return route.fulfill({json:{success:true,data:{httpStatus:404,bodyRaw:'{}'}}});
  await route.fulfill({json:{success:true,data:{httpStatus:200,bodyRaw:JSON.stringify(stored)}}});
 });
 await page.goto('https://tax.test/');await page.addStyleTag({content:'body{font-family:Arial;margin:20px}*{box-sizing:border-box}'});await page.addStyleTag({path:path.join(base,'profit-report.css')});
 await page.evaluate(i18n=>window.LavkaProfitTaxSettingsConfig={ajaxUrl:'https://tax.test/ajax',action:'test',nonce:'test',i18n},labels);
 await page.addScriptTag({path:path.join(base,'profit-tax-settings.js')});
 await page.locator('#lavr-profit-tax-settings summary').click();
 await page.waitForFunction(()=>document.querySelectorAll('#lpt-groups input').length>0 || !document.getElementById('lpt-error').hidden);
 await page.waitForFunction(()=>!document.getElementById('lpt-reload').disabled);
 return {page,calls,errors};
}
test('editable lists save normalized codes, show version and fit mobile',async()=>{
 const {page,calls,errors}=await setup();assert.equal(await page.locator('#lpt-groups input').count(),4);
 const group=page.locator('[data-group="retailFirmCodes"]');await group.locator(':scope > button').click();await group.locator('input').last().fill(' тест_фоп ');
 assert.match(await page.evaluate(()=>{try{window.LavkaProfitTaxSettings.assertReady();return '';}catch(e){return e.message;}}),/Unsaved/);
 await page.locator('#lpt-save').click();await page.waitForFunction(()=>document.getElementById('lpt-state').textContent.includes('lists saved'));
 assert.equal(JSON.parse(calls.find(c=>c.operation==='save').settings).retailFirmCodes[2],'ТЕСТ_ФОП');assert.match(await page.locator('#lpt-version').innerText(),/1/);
 await page.locator('#lavr-profit-tax-settings').screenshot({path:path.join(out,'desktop.png')});
 await page.setViewportSize({width:390,height:844});assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),true);await page.locator('#lavr-profit-tax-settings').screenshot({path:path.join(out,'mobile.png')});
 await page.evaluate(()=>window.LavkaProfitTaxSettings.setReportBusy(true));assert.equal(await group.locator('input').first().isDisabled(),true);assert.equal(await page.locator('#lpt-reload').isDisabled(),true);
 assert.deepEqual(errors,[]);await page.close();
});
test('overlapping lists and blank rows never reach Java',async()=>{
 const {page,calls}=await setup();await page.locator('[data-group="retailFirmCodes"] input').first().fill(' кондфоп ');await page.locator('#lpt-save').click();assert.match(await page.locator('#lpt-error').innerText(),/duplicate/);assert.equal(calls.filter(c=>c.operation==='save').length,0);
 await page.locator('[data-group="retailFirmCodes"] input').first().fill('');await page.locator('#lpt-save').click();assert.equal(calls.length,1);await page.close();
});
test('conflict and uncertain saves preserve edits and block automatic retry until reload',async()=>{
 for(const mode of ['conflict','lost']){
  const {page,calls}=await setup(mode);await page.locator('#lpt-groups input').first().fill('НОВЫЙ');await page.locator('#lpt-save').click();await page.waitForFunction(()=>!document.getElementById('lpt-error').hidden);
  assert.match(await page.locator('#lpt-error').innerText(),mode==='conflict'?/Another user/:/not confirmed/);assert.equal(await page.locator('#lpt-save').isDisabled(),true);assert.equal(await page.locator('#lpt-groups input').first().inputValue(),'НОВЫЙ');assert.equal(calls.filter(c=>c.operation==='save').length,1);
  page.once('dialog',d=>d.accept());await page.locator('#lpt-reload').click();await page.waitForFunction(()=>!document.getElementById('lpt-reload').disabled);assert.equal(await page.locator('#lpt-groups input').first().inputValue(),'МИХНФОП');assert.equal(calls.filter(c=>c.operation==='save').length,1);await page.close();
 }
});
test('old Java cannot be mistaken for loaded or saved settings',async()=>{
 const {page,calls}=await setup('unavailable');assert.equal(await page.locator('#lpt-groups input').count(),0);assert.equal(await page.locator('#lpt-save').isDisabled(),true);assert.match(await page.locator('#lpt-error').innerText(),/Java/);assert.equal(calls.length,1);await page.close();
});

test('a confirmed validation rejection lets the manager correct the row',async()=>{
 const {page,calls}=await setup('invalid');await page.locator('#lpt-groups input').first().fill('НОВЫЙ');await page.locator('#lpt-save').click();await page.waitForFunction(()=>!document.getElementById('lpt-error').hidden);
 assert.match(await page.locator('#lpt-error').innerText(),/Check the firm codes/);assert.equal(await page.locator('#lpt-save').isDisabled(),false);assert.equal(await page.locator('#lpt-groups input').first().isDisabled(),false);assert.equal(calls.filter(c=>c.operation==='save').length,1);await page.close();
});
