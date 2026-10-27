/* Offline check of real PHP-rendered manager form. All network blocked. */
const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict');
const {chromium}=require(process.env.PLAYWRIGHT_MODULE||'playwright');
const base=path.resolve(__dirname,'..'),root=path.resolve(base,'../../..');
(async()=>{
 const browser=await chromium.launch({channel:'chrome',headless:true});
 try {
  const page=await browser.newPage();await page.route('**/*',r=>r.abort());
  const fixture=JSON.parse(fs.readFileSync(process.argv[2],'utf8'));
  await page.setContent('<html lang="uk"><body style="margin:16px;font:14px Arial"><main class="pcoe-manager"><div id="pcoe-manager-status"></div>'+fixture.html+'</main></body></html>');
  for(const file of ['wp-admin/css/common.css','wp-admin/css/forms.css','wp-includes/css/buttons.css'])await page.addStyleTag({content:fs.readFileSync(path.join(root,file),'utf8')});
  await page.addStyleTag({content:fs.readFileSync(path.join(base,'assets/manager.css'),'utf8')});
  await page.evaluate(()=>{
   window.pcoeManager={collapseSection:'Згорнути',expandSection:'Розгорнути',collapseAll:'Згорнути всі розділи',expandAll:'Розгорнути всі розділи',url:'https://example.invalid/',nonce:'test',busy:'Обробка…',error:'Помилка',saveFirst:'Збережіть зміни'};window.requests=[];
   window.fetch=async(url,options)=>{window.requests.push([...options.body]);return {ok:true,json:async()=>({success:true,data:{message:'Тест: додано до черги'}})};};
  });
  await page.addScriptTag({content:fs.readFileSync(path.join(base,'assets/manager.js'),'utf8')});
  const email=page.locator('[name="send_confirmation"]');assert(await email.isChecked());
  await page.locator('details').evaluate(el=>el.open=true);
  await page.locator('[name="pool_target"]').selectOption('901');
  assert(await page.locator('[name="auxiliary[]"][value="901"]').isDisabled());
  await page.locator('[name="auxiliary[]"][value="903"]').check();
  await page.locator('button.button-primary').click();await page.waitForFunction(()=>requests.length===1);
  let request=Object.fromEntries(await page.evaluate(()=>requests[0]));assert.equal(request.send_confirmation,'1');assert.equal(request['auxiliary[]'],'903');assert.equal(request.pool_target,'901');
  await email.uncheck();await page.locator('button.button-primary').click();await page.waitForFunction(()=>requests.length===2);
  request=Object.fromEntries(await page.evaluate(()=>requests[1]));assert(!('send_confirmation' in request));
  for(const width of [1100,390]){
   await page.setViewportSize({width,height:800});
   assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'No horizontal overflow');
   await page.screenshot({path:path.join(path.dirname(process.argv[2]),`pcoe-quick-${width}.png`),fullPage:true});
  }
  console.log('PASS: checked/unchecked confirmation, auxiliary selection, target exclusion, desktop/mobile; no network.');
 } finally {await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
