const {chromium}=require('playwright');
const {execFileSync}=require('child_process');
const assert=require('assert');
const php=process.env.PHP_BINARY || 'php';
const fixture=require('path').join(__dirname,'purchase-planning-ui.php');
const output=process.env.UI_OUTPUT_DIR || require('os').tmpdir();
 const calculate=(edits)=>JSON.parse(execFileSync(php,[fixture,'calculate',JSON.stringify(edits||{})],{encoding:'utf8'}));
 const calculateZero=()=>JSON.parse(execFileSync(php,[fixture,'calculate-zero'],{encoding:'utf8'}));
(async()=>{
 const browser=await chromium.launch({headless:true,...(process.env.CHROME_BINARY ? {executablePath:process.env.CHROME_BINARY} : {})});
 const page=await browser.newPage();const errors=[];page.on('pageerror',e=>errors.push(e.message));
 let fail=false;
 await page.route('https://fixture.local/api',async route=>{
  const request=new URLSearchParams(route.request().postData()); const op=request.get('operation');const payload=JSON.parse(request.get('payload'));
  const data=op==='start'?{token:'fixture',scenario:{name:'Kreul',version:2},groups:[{name:'Kyiv',warehouseIds:[1]}],query:{period:{from:'2026-08-01',to:'2026-08-30'}}}:op==='page'?{items:[calculate(),calculateZero()],complete:true,page:1,loaded:2,total:2,warnings:[],context:{analyticsSchemaVersion:6}}:{item:calculate(payload.groups)};
  await route.fulfill({status:fail?400:200,contentType:'application/json',body:JSON.stringify(fail?{success:false,data:{message:'Fixture validation error'}}:{success:true,data})});
 });
 for(const width of [1440,390]){
  await page.setViewportSize({width,height:1000});await page.setContent(execFileSync(php,[fixture],{encoding:'utf8'}));
  await page.locator('#lps-purchase-scenario').selectOption('1');await page.locator('#lps-purchase-start').click();
  await page.locator('[data-edit-form]').first().waitFor({state:'attached'});
  assert.equal(await page.locator('.lps-purchase-sku').count(),2);
  const form=page.locator('[data-edit-form="0"]');
  await form.locator('..').locator('summary').click();
  const pack=form.locator('[data-field="respectPack"]');assert(await pack.isChecked());
  await pack.uncheck();await form.locator('[data-field="quantity"]').fill('2');await form.locator('[data-field="reason"]').fill('Unit purchase');
  await form.locator('button').click();await page.waitForFunction(()=>document.querySelector('[data-field="quantity"]').value==='2'&&!document.querySelector('#lps-purchase-start').disabled);
  assert(!await page.locator('.lps-purchase-sku').first().locator('[data-field="respectPack"]').isChecked());assert.equal(await page.locator('.lps-purchase-sku').first().locator('tbody tr td').last().innerText(),'2');
  await page.locator('[data-edit-form="0"]').locator('..').locator('summary').click();
  await page.locator('#lps-purchase-hide-minimum-zero').check();assert.equal(await page.locator('.lps-purchase-sku').count(),1);
  await page.locator('#lps-purchase-reset-filters').click();
  await page.locator('#lps-purchase-hide-no-sales').check();assert.equal(await page.locator('.lps-purchase-sku').count(),1);
  await page.locator('#lps-purchase-reset-filters').click();
  await page.locator('#lps-purchase-product-group').selectOption('ART');assert.equal(await page.locator('.lps-purchase-sku').count(),1);
  await page.locator('#lps-purchase-product-subgroup').selectOption('3:GLUE');assert.equal(await page.locator('.lps-purchase-sku').count(),1);
  await page.locator('#lps-purchase-reset-filters').click();
  await page.locator('#lps-purchase-order-filter').selectOption('need');assert.equal(await page.locator('.lps-purchase-sku').count(),1);
  assert((await page.locator('#lps-purchase-filter-count').innerText()).includes('1'));
  await page.locator('#lps-purchase-reset-filters').click();
  assert(await page.evaluate(()=>document.documentElement.scrollWidth<=window.innerWidth));
  await page.screenshot({path:`${output}/purchase-preview-${width}.png`,fullPage:true});
 }
 fail=true;await page.locator('[data-edit-form="0"]').locator('..').locator('summary').click();await page.locator('[data-edit-form="0"] button').click();await page.waitForFunction(()=>document.querySelector('#lps-purchase-message').textContent.includes('Fixture validation error'));
 assert(await page.locator('[data-export="csv"]').isDisabled());assert.deepEqual(errors,[]);
 await browser.close();console.log('PASS: desktop/mobile, pack toggle, manual quantity, error state, no page overflow');
})().catch(e=>{console.error(e);process.exit(1)});
