const {chromium}=require('playwright');
const {execFileSync}=require('child_process');
const assert=require('assert');
const html=execFileSync(process.env.PHP_BINARY||'php',[require('path').join(__dirname,'warehouse-usage-ui.php')],{encoding:'utf8'});
(async()=>{
 const browser=await chromium.launch({headless:true,...(process.env.CHROME_BINARY?{executablePath:process.env.CHROME_BINARY}:{})});
 try {
 const page=await browser.newPage(); const errors=[];page.on('pageerror',e=>errors.push(e.message));
 let saved;
 const scenario=()=>({id:4,name:'Kreul',version:3,schemaVersion:4,status:'active',visibility:'shared',profile:saved||{schemaVersion:4,context:{warehouseIds:[5,15]},calculation:{stockOnlyWarehouseIds:[15]},period:{from:'2026-05-12',to:'2026-09-11'}}});
 await page.route('https://fixture.local/api',async route=>{
  const params=new URLSearchParams(route.request().postData());const op=params.get('operation');
  let data;
  if(op==='bootstrap') data={items:[scenario()],warehouses:[{id:5,name:'Одесса'},{id:15,name:'Одесса Хранение'}],warehouseDirectoryReady:true};
  else if(op==='v4_capabilities') data={analyticsSchemaVersion:6,compatibleGeneration:true,warehouses:[],filters:{},dictionaries:{},features:{warehouseUsage:{version:1}}};
  else if(op==='revisions') data={items:[]};
  else if(op==='save') {saved=JSON.parse(params.get('profileJson'));data={items:[scenario()],selectedId:4};}
  else throw new Error('Unexpected operation '+op);
  await route.fulfill({status:200,contentType:'application/json',body:JSON.stringify({success:true,data})});
 });
 for(const width of [1440,390]) {
  saved=null;await page.setViewportSize({width,height:1000});await page.setContent(html);
  await page.locator('[data-scenario-id="4"]:enabled').click();
  const storage=page.locator('[data-warehouse-usage="15"]');
  await page.waitForFunction(()=>document.querySelector('[data-warehouse-usage="15"]')?.value==='STOCK_ONLY'&&!document.querySelector('#lps-as-save').disabled);
  assert.equal(await page.locator('[data-warehouse-usage="5"]').inputValue(),'FULL');
  await page.screenshot({path:require('path').join(require('os').tmpdir(),`warehouse-usage-${width}.png`),fullPage:true});
  await storage.selectOption('FULL');await page.locator('#lps-as-save').click();
  await page.waitForFunction(()=>!document.querySelector('#lps-as-save').disabled);
  assert.deepEqual(saved.calculation.stockOnlyWarehouseIds,[]);
  await storage.selectOption('STOCK_ONLY');await page.locator('#lps-as-save').click();
  await page.waitForFunction(()=>!document.querySelector('#lps-as-save').disabled);
  assert.deepEqual(saved.calculation.stockOnlyWarehouseIds,[15]);
  await page.locator('#lps-as-new').click();assert.equal(await page.locator('[data-warehouse-usage]').count(),0);
  await page.locator('[data-scenario-id="4"]').click();
  await page.waitForFunction(()=>document.querySelector('[data-warehouse-usage="15"]')?.value==='STOCK_ONLY'&&!document.querySelector('#lps-as-save').disabled);
  await page.locator('#lps-as-warehouses').selectOption(['5']);
  await page.waitForFunction(()=>!document.querySelector('#lps-as-save').disabled);
  assert.equal(await page.locator('[data-warehouse-usage="15"]').count(),0);
  await page.locator('#lps-as-save').click();await page.waitForFunction(()=>!document.querySelector('#lps-as-save').disabled);
  assert.deepEqual(saved.calculation.stockOnlyWarehouseIds,[]);
  assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth));
 }
 assert.deepEqual(errors,[]);console.log('PASS: warehouse modes load/save/reopen/reset/scope changes, desktop/mobile');
 } finally {await browser.close();}
})().catch(e=>{console.error(e);process.exit(1)});
