/* Offline check of real PHP-rendered manager form. All network blocked. */
const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict');
const {chromium}=require(process.env.PLAYWRIGHT_MODULE||'playwright');
const base=path.resolve(__dirname,'..'),root=path.resolve(base,'../../..');
(async()=>{
 const browser=await chromium.launch({channel:'chrome',headless:true});
 try {
  const page=await browser.newPage();await page.route('**/*',r=>r.abort());
  const fixture=JSON.parse(fs.readFileSync(process.argv[2],'utf8'));
  await page.setContent('<html lang="uk"><body class="wp-core-ui" style="margin:16px;font:14px Arial"><main class="pcoe-manager"><div id="pcoe-manager-status"></div>'+fixture.html+'</main></body></html>');
  for(const file of ['wp-admin/css/common.css','wp-admin/css/forms.css','wp-includes/css/buttons.css'])await page.addStyleTag({content:fs.readFileSync(path.join(root,file),'utf8')});
  await page.addStyleTag({content:fs.readFileSync(path.join(base,'assets/manager.css'),'utf8')});
  await page.evaluate(()=>{
   window.pcoeManager={collapseSection:'Згорнути',expandSection:'Розгорнути',collapseAll:'Згорнути всі розділи',expandAll:'Розгорнути всі розділи',url:'https://example.invalid/',nonce:'test',busy:'Обробка…',error:'Помилка',saveFirst:'Збережіть зміни'};window.requests=[];
   window.fetch=async(url,options)=>{window.requests.push([...options.body]);return {ok:true,json:async()=>({success:true,data:options.body.get('operation')==='quick_preview'?{message:'Перегляд',quick_preview_html:'<p>Очікує переміщення</p>'}:{message:'Тест: додано до черги'}})};};
  });
  await page.addScriptTag({content:fs.readFileSync(path.join(base,'assets/manager.js'),'utf8')});
  const recovery=process.argv[3]==='recovery';
  if (recovery) {
   assert.equal(await page.locator('[value="quick_order"]').count(),0);
   const form=page.locator('form').filter({has:page.locator('[value="quick_settings"]')});
   await form.locator('button').click();await page.waitForFunction(()=>requests.length===1);
   const request=Object.fromEntries(await page.evaluate(()=>requests[0]));
   assert.equal(request.operation,'quick_settings');assert(request.operation_key);assert(request.revision);
   assert(!('send_confirmation' in request));
  } else {
  const email=page.locator('[name="send_confirmation"]');assert(await email.isChecked());
  const form=page.locator('form').filter({has:page.locator('[value="quick_order"]')});
  const mode=form.locator('[name="warehouse_mode"]'),warehouse=form.locator('[name="warehouse_id"]');
  assert(await warehouse.isDisabled());
  await mode.selectOption('manual');assert(await warehouse.isEnabled());
  const target=await warehouse.locator('[data-primary="901"]').getAttribute('value');
  await warehouse.selectOption(target);
  await page.locator('details[data-default-primary]').evaluate(el=>el.open=true);
  assert(await page.locator('[name="auxiliary[]"][value="901"]').isDisabled());
  await page.locator('[name="auxiliary[]"][value="903"]').check();
  await form.locator('[data-quick-preview]').click();await page.waitForFunction(()=>requests.length===1);
  let request=Object.fromEntries(await page.evaluate(()=>requests[0]));assert.equal(request.operation,'quick_preview');
  assert(await page.locator('[data-quick-preview-result]').isVisible());
  await mode.selectOption('single');assert(await page.locator('[data-quick-preview-result]').isHidden());
  await form.locator('button.button-primary').click();await page.waitForFunction(()=>requests.length===2);
  request=Object.fromEntries(await page.evaluate(()=>requests[1]));assert.equal(request.operation,'quick_order');assert.equal(request.send_confirmation,'1');assert.equal(request['auxiliary[]'],'903');assert.equal(request.warehouse_mode,'single');assert.equal(request.warehouse_id,target);assert(!('pool_target' in request));
  await email.uncheck();await mode.selectOption('auto');assert(await warehouse.isDisabled());
  await form.locator('button.button-primary').click();await page.waitForFunction(()=>requests.length===3);
  request=Object.fromEntries(await page.evaluate(()=>requests[2]));assert(!('send_confirmation' in request));assert.equal(request.warehouse_mode,'auto');
  }
  for(const width of [1100,390]){
   await page.setViewportSize({width,height:800});
   await page.evaluate(()=>{scrollTo({top:0,behavior:'instant'});return new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve)));});
   assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'No horizontal overflow');
   await page.screenshot({path:path.join(path.dirname(process.argv[2]),`pcoe-quick-${recovery?"recovery-":""}${width}.png`),fullPage:true});
  }
  console.log(recovery?'PASS: explicit settings recovery, no creation or mail, desktop/mobile; no network.':'PASS: three warehouse modes, preview without creation, checked/unchecked confirmation, auxiliary selection, target exclusion, desktop/mobile; no network.');
 } finally {await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
