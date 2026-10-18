// Isolated rendered fixture; every request intercepted, no WP/customer/carrier writes.
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const assert = require('node:assert/strict');
const path = require('node:path');
const { pathToFileURL } = require('node:url');
const dir = process.argv[2];
if (!dir) throw new Error('Fixture directory required');
(async () => {
 const browser = await chromium.launch({headless:true,channel:'chrome'});
 let checks=0;
 try {
  for (const width of [1280,390]) {
   const page=await browser.newPage({viewport:{width,height:1000}}), errors=[], commands=[];
   page.on('pageerror',e=>errors.push(e.message));
   let fail=false;
   await page.route('**/*', async route=>{
    const request=route.request(), url=new URL(request.url());
    if(url.protocol==='file:') return route.continue();
    if(url.hostname!=='pnpm-test.invalid') return route.abort();
    const params=new URLSearchParams(request.postData() || url.search);
    const send=(data,status=200)=>route.fulfill({status,contentType:'application/json',headers:{'Access-Control-Allow-Origin':'*'},body:JSON.stringify(data)});
    if(params.get('action')!=='pnpm_manager_preparation') return send({success:false,data:{message:'Directory offline'}});
    const payload=JSON.parse(params.get('payload')), command=params.get('command');commands.push({command,payload});
    if(fail){fail=false;return send({success:false,data:{message:'Stale revision'}},409);}
    if(command==='save') {await new Promise(r=>setTimeout(r,200));return send({success:true,data:{revision:'saved-revision',message:'Saved preparation only'}});}
    if(command==='confirmation') return send({success:true,data:{recipient:{name:'<img src=x onerror=alert(1)>',phone:'380000000000',city_label:'Test',city_ref:'00000000-0000-0000-0000-000000000001',delivery_type:'address',address:'Test address'},message:'Imported only'}});
    return send({success:true,data:{preview:{recipient:payload.recipient,groups:Object.fromEntries(Object.entries(payload.groups).map(([key,value])=>[key,{...value,cod:0,location_name:'Synthetic warehouse'}]))},blockers:['Carrier contract not verified'],message:'Preview only'}});
   });
   await page.goto(pathToFileURL(path.join(dir,'manager-preparation.html')).href);
   const form=page.locator('#pnpm-manager-form');
   assert.equal(await form.count(),1);checks++;
   assert.equal(await form.locator('form').count(),0);checks++;
   assert.equal(await form.locator('fieldset[data-location]').count(),2);checks++;
   assert(await form.locator('#pnpm-checkout-fields').isVisible());checks++;
   assert((await page.locator('h2').first().textContent()).includes('Підготувати'));checks++;
   const initialBoxes=await form.locator('.pnpm-boxes .pnpm-box').count();
   await form.locator('.pnpm-add-box').first().click();
   assert.equal(await form.locator('.pnpm-boxes .pnpm-box').count(),initialBoxes+1);checks++;
   await form.locator('.pnpm-boxes').first().locator('.pnpm-remove-box').last().click();
   assert.equal(await form.locator('.pnpm-boxes .pnpm-box').count(),initialBoxes);checks++;
   await form.locator('[name=agreed]').check();
   await form.locator('[data-box=weight]').first().fill('3.25');
   assert(!(await form.locator('[name=agreed]').isChecked()));checks++;
   await form.locator('[name=agreed]').check();
   await form.locator('[data-pnpm-command=preview]').click();
   await page.locator('#pnpm-manager-preview .pnpm-blockers').waitFor();
   await page.screenshot({path:path.join(dir,`manager-preview-${width}.png`),fullPage:true});
   assert.equal(commands.at(-1).payload.groups[Object.keys(commands.at(-1).payload.groups)[0]].boxes[0].weight,'3.25');checks++;
   assert.equal(await form.locator('button:disabled').count(),1);checks++;
   await form.locator('[data-pnpm-command=save]').click();
   assert(await form.locator('[name=name]').isDisabled());checks++;
   await page.waitForFunction(()=>document.querySelector('#pnpm-manager-form').dataset.revision==='saved-revision');
   assert.equal(commands.filter(c=>c.command==='save').length,1);checks++;
   assert.equal(await form.locator('button:disabled').count(),1);checks++;
   await form.locator('[data-pnpm-command=confirmation]').click();
   await page.waitForFunction(()=>document.querySelector('[name=name]').value.startsWith('<img'));
   assert.equal(await form.locator('img').count(),0);checks++;
   assert.equal(await form.locator('#pnpm_address').inputValue(),'Test address');checks++;
   assert(!(await form.locator('[name=agreed]').isChecked()));checks++;
   fail=true;
   await form.locator('[name=agreed]').check();
   await form.locator('[data-pnpm-command=save]').click();
   await page.waitForFunction(()=>document.querySelector('#pnpm-manager-status').textContent==='Stale revision');
   assert.equal(commands.filter(c=>c.command==='save').length,2);checks++;
   assert.equal(await form.locator('button:disabled').count(),1);checks++;
   assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth));checks++;
   assert.deepEqual(errors,[]);checks++;
   await page.screenshot({path:path.join(dir,`manager-preparation-${width}.png`),fullPage:true});
   await page.close();
  }
  console.log(`Manager preparation UI: ${checks} checks passed, desktop/mobile, mocked requests only.`);
 } finally {await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
