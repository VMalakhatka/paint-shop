/* Offline UI regression using markup exported by the local integration test.
 * PCOE_ORDER_FLOW_UI_FIXTURE=/tmp/pcoe-flow.json wp eval-file .../tests/manager-workspace.php --skip-themes
 * PLAYWRIGHT_MODULE=/path/to/playwright node .../tests/manager-order-flow-ui.cjs /tmp/pcoe-flow.json
 * All browser network is blocked; fixture contains only temporary test orders.
 */
const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict');
const {chromium}=require(process.env.PLAYWRIGHT_MODULE||'playwright');
const base=path.resolve(__dirname,'..'),root=path.resolve(base,'../../..');
const fixture=JSON.parse(fs.readFileSync(process.argv[2],'utf8'));
(async()=>{
 const browser=await chromium.launch({channel:'chrome',headless:true});
 try {
  const page=await browser.newPage({viewport:{width:1100,height:900}});
  await page.route('**/*',r=>r.abort());
  await page.setContent('<html lang="uk"><body class="wp-core-ui" style="margin:20px;font:14px Arial">'+fixture.html+'</body></html>');
  for(const file of ['wp-admin/css/common.css','wp-admin/css/forms.css','wp-includes/css/buttons.css'])await page.addStyleTag({content:fs.readFileSync(path.join(root,file),'utf8')});
  await page.addStyleTag({content:fs.readFileSync(path.join(base,'assets/manager.css'),'utf8')});
  await page.evaluate(responses=>{
   window.pcoeManager={url:'https://example.invalid/',nonce:'offline',busy:'Розрахунок…',error:'Помилка',saveFirst:'Збережіть чернетку'};
   window.requests=[];window.failNext=false;window.zeroStock=false;
   window.fetch=async(url,options)=>{
    const request=Object.fromEntries(options.body);window.requests.push(request);
    if(request.operation!=='preview')throw new Error('Offline fixture: mutation blocked');
    await new Promise(resolve=>setTimeout(resolve,120));
    if(window.failNext){window.failNext=false;throw new Error('Тестова помилка розрахунку');}
    const data={...responses[request.mode]};if(window.zeroStock)data.can_apply=false;
    return {ok:true,json:async()=>({success:true,data})};
   };
  },fixture.responses);
  await page.addScriptTag({content:fs.readFileSync(path.join(base,'assets/manager.js'),'utf8')});
  const primary=page.locator('form[data-preview-form]').filter({has:page.locator('input[value="accounts"]')});
  const nonaccount=page.locator('form[data-preview-form]').filter({has:page.locator('input[value="non_accounting"]')});
  const apply=page.locator('[data-apply-form]'),group=primary.locator('[name=warehouse_id]');
  const waitPreview=()=>page.waitForFunction(()=>document.querySelector('#pcoe-manager-status').classList.contains('notice-success'));
  assert(await group.isDisabled());assert(!(await page.locator('details').getAttribute('open')));
  await primary.locator('[name=warehouse_mode]').selectOption('single');assert(await group.isEnabled());
  await primary.locator('button').click();assert.equal(await page.evaluate(()=>window.requests.length),0,'A warehouse group is required');
  await group.selectOption(String(fixture.group_id));
  const mapped=await group.locator('option:checked').getAttribute('data-folio-warehouses');
  assert.equal(await page.locator('[data-warehouse-description]').textContent(),mapped);
  await primary.locator('button').click();await waitPreview();assert(await apply.isVisible());
  assert.equal(await apply.locator('[name=mode]').inputValue(),'accounts');
  assert.equal(await apply.locator('button').textContent(),fixture.responses.accounts.apply_label);
  await apply.locator('[name=confirmation]').check();
  // Selecting another group invalidates a confirmed preview immediately.
  await primary.locator('[name=warehouse_mode]').selectOption('auto');
  assert(await group.isDisabled());assert(await apply.isHidden());assert.equal(await apply.locator('[name=token]').inputValue(),'');
  await primary.locator('button').click();await waitPreview();
  // A failed alternate preview must never leave the earlier action executable.
  await page.locator('details summary').click();await page.evaluate(()=>window.failNext=true);
  await nonaccount.locator('button').click();
  await page.waitForFunction(()=>document.querySelector('#pcoe-manager-status').classList.contains('notice-error'));
  assert(await apply.isHidden());assert.equal(await apply.locator('[name=token]').inputValue(),'');
  await nonaccount.locator('button').click();await waitPreview();
  assert.equal(await apply.locator('[name=mode]').inputValue(),'non_accounting');
  assert.equal(await apply.locator('button').textContent(),fixture.responses.non_accounting.apply_label);
  assert(!(await apply.locator('[name=confirmation]').isChecked()));
  assert.equal(await page.evaluate(()=>window.requests.at(-1).warehouse_id),'0');
  await page.evaluate(()=>window.zeroStock=true);await primary.locator('button').click();await waitPreview();assert(await apply.isHidden());
  await page.evaluate(()=>window.zeroStock=false);await primary.locator('button').click();await waitPreview();
  await page.locator('details summary').click();
  for(const width of [1100,390]){
   await page.setViewportSize({width,height:1000});
   assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'Form overflow');
   await page.evaluate(()=>window.scrollTo({top:0,behavior:'instant'}));
   await page.screenshot({path:path.join(path.dirname(process.argv[2]),`pcoe-order-flow-${width}.png`),fullPage:true});
  }
  await page.locator('.pcoe-manager').evaluate((e,html)=>e.innerHTML='<h1>Створені замовлення — тест</h1>'+html,fixture.result);
  assert.equal(await page.locator('a[href*="page=pcoe-approvals"]').count(),2);
  assert.equal(await page.locator('tbody tr').count(),3);
  for(const width of [1100,390]){
   await page.setViewportSize({width,height:800});
   assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'Result overflow');
   await page.evaluate(()=>window.scrollTo({top:0,behavior:'instant'}));
   await page.screenshot({path:path.join(path.dirname(process.argv[2]),`pcoe-order-results-${width}.png`),fullPage:true});
  }
  assert((await page.evaluate(()=>window.requests)).every(r=>r.operation==='preview'),'No real apply');
  console.log('PASS: warehouse groups, mode-specific actions, stale/error preview invalidation, zero stock, reserved order links, desktop/mobile; network blocked.');
 } finally {await browser.close();}
})().catch(error=>{console.error(error);process.exitCode=1;});
