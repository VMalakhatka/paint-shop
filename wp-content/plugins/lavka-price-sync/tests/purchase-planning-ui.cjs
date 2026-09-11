const {chromium}=require('playwright');
const {execFileSync}=require('child_process');
const assert=require('assert');
const php=process.env.PHP_BINARY || 'php';
const fixture=require('path').join(__dirname,'purchase-planning-ui.php');
const output=process.env.UI_OUTPUT_DIR || require('os').tmpdir();
const calculate=(edits)=>JSON.parse(execFileSync(php,[fixture,'calculate',JSON.stringify(edits||{})],{encoding:'utf8'}));
(async()=>{
 const browser=await chromium.launch({headless:true,...(process.env.CHROME_BINARY ? {executablePath:process.env.CHROME_BINARY} : {})});
 const page=await browser.newPage();const errors=[];page.on('pageerror',e=>errors.push(e.message));
 let fail=false;
 await page.route('https://fixture.local/api',async route=>{
  const request=new URLSearchParams(route.request().postData()); const op=request.get('operation');const payload=JSON.parse(request.get('payload'));
  const data=op==='start'?{token:'fixture',scenario:{name:'Kreul',version:2},groups:[{name:'Kyiv',warehouseIds:[1]}],query:{period:{from:'2026-08-01',to:'2026-08-30'}}}:op==='page'?{items:[calculate()],complete:true,page:1,loaded:1,total:1,warnings:[],context:{analyticsSchemaVersion:6}}:{item:calculate(payload.groups)};
  await route.fulfill({status:fail?400:200,contentType:'application/json',body:JSON.stringify(fail?{success:false,data:{message:'Fixture validation error'}}:{success:true,data})});
 });
 for(const width of [1440,390]){
  await page.setViewportSize({width,height:1000});await page.setContent(execFileSync(php,[fixture],{encoding:'utf8'}));
  await page.locator('#lps-purchase-scenario').selectOption('1');await page.locator('#lps-purchase-start').click();
  await page.locator('[data-edit-form]').waitFor({state:'attached'});
  await page.locator('details').filter({has:page.locator('[data-edit-form]')}).locator('summary').click();
  const pack=page.locator('[data-field="respectPack"]');assert(await pack.isChecked());
  await pack.uncheck();await page.locator('[data-field="quantity"]').fill('2');await page.locator('[data-field="reason"]').fill('Unit purchase');
  await page.locator('[data-edit-form] button').click();await page.waitForFunction(()=>document.querySelector('[data-field="quantity"]').value==='2'&&!document.querySelector('#lps-purchase-start').disabled);
  assert(!await pack.isChecked());assert.equal(await page.locator('tbody tr td').last().innerText(),'2');
  await page.locator('details').filter({has:page.locator('[data-edit-form]')}).locator('summary').click();
  assert(await page.evaluate(()=>document.documentElement.scrollWidth<=window.innerWidth));
  await page.screenshot({path:`${output}/purchase-preview-${width}.png`,fullPage:true});
 }
 fail=true;await page.locator('[data-edit-form] button').click();await page.waitForFunction(()=>document.querySelector('#lps-purchase-message').textContent.includes('Fixture validation error'));
 assert(await page.locator('[data-export="csv"]').isDisabled());assert.deepEqual(errors,[]);
 await browser.close();console.log('PASS: desktop/mobile, pack toggle, manual quantity, error state, no page overflow');
})().catch(e=>{console.error(e);process.exit(1)});
