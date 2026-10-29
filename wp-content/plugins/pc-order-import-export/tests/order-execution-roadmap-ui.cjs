/* Offline interaction checks of the PHP-rendered execution card; no live customer data. */
const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict');
const {chromium}=require(process.env.PLAYWRIGHT_MODULE||'playwright');
const base=path.resolve(__dirname,'..'),root=path.resolve(base,'../../..');
(async()=>{
 const fixture=JSON.parse(fs.readFileSync(process.argv[2],'utf8'));
 const browser=await chromium.launch({channel:'chrome',headless:true});
 try {
  const page=await browser.newPage();await page.route('**/*',r=>r.abort());
  page.on('pageerror',error=>{throw error;});
  const render=async view=>{
   await page.setContent('<html lang="uk"><meta name="viewport" content="width=device-width,initial-scale=1"><body class="wp-core-ui" style="margin:16px;font:14px Arial"><main class="pcoe-manager">'+fixture[view]+'</main></body></html>');
   for(const file of ['wp-admin/css/common.css','wp-admin/css/forms.css','wp-includes/css/buttons.css'])await page.addStyleTag({content:fs.readFileSync(path.join(root,file),'utf8')});
   await page.addStyleTag({content:fs.readFileSync(path.join(base,'assets/manager.css'),'utf8')});
   await page.addScriptTag({content:fs.readFileSync(path.join(base,'assets/execution.js'),'utf8')});
   await page.evaluate(()=>dispatchEvent(new Event('pageshow')));
  };
  await render('html');
  const plan=page.locator('form:has(input[name="operation"][value="save"])');
  const record=page.locator('form:has(input[name="operation"][value="record_operation"])');
  const configure=page.locator('form:has(input[name="operation"][value="configure"])');
  const stage=plan.locator('[data-roadmap-stage]'),choice=record.locator('[data-operation-choice]'),result=record.locator('[data-operation-status]');
  assert.equal(await stage.locator('option').count(),fixture.stages.length+1);
  assert.equal(await stage.inputValue(),'return');assert.equal(await choice.inputValue(),'planned:return');
  assert.equal(await plan.locator('select[name="stage_status"]').count(),0,'Planning cannot submit a completion selection');
  assert.equal(await plan.locator('input[name="stage_status"]').inputValue(),'in_progress');
  assert(await record.locator('[data-operation-scenario="return"]').isVisible());
  assert(await plan.locator('[data-roadmap-scenario="return"]').isVisible());
  assert.equal(await record.locator('[data-operation-title]').isVisible(),false);
  assert.equal(await record.locator('[name="operation_title"]').isDisabled(),true);

  const planned_before=await plan.evaluate(f=>Object.fromEntries(new FormData(f)));
  await choice.selectOption('planned:wait_stock');
  assert(await record.locator('[data-operation-plan="planned:wait_stock"]').isVisible(),'Original plan is visible before recording its result');
  assert((await record.locator('[data-operation-plan="planned:wait_stock"]').textContent()).includes('Expected receipt in warehouse A'));
  await record.locator('[name="operation_result"]').fill('Перевірено надходження товару');
  assert.equal(await choice.locator('option[value="planned:intake"]').count(),0,'Finished task is not listed as outstanding');
  assert.equal(await result.locator('[value="skipped"]').evaluate(o=>o.disabled),false);
  assert.deepEqual(await plan.evaluate(f=>Object.fromEntries(new FormData(f))),planned_before,'Choosing a prior operation leaves the planned future task untouched');
  assert.equal(await record.getAttribute('aria-busy'),null,'Choosing an operation does not submit it');
  await choice.selectOption('stage:intake');
  assert(await result.locator('[value="skipped"]').evaluate(o=>o.disabled));
  assert(await record.locator('[data-operation-scenario="intake"]').isVisible());
  assert.equal(await record.locator('[data-operation-scenario]:visible').count(),1);
  assert(await plan.locator('[data-roadmap-scenario="return"]').isVisible(),'Operation guidance does not replace the next-plan guidance');

  await stage.selectOption('box_passports');
  assert.equal(await plan.locator('[name="stage_detail"]').inputValue(),'');
  assert.equal(await plan.locator('[name="due"]').inputValue(),'','A new plan needs its own deadline');
  assert.equal(await choice.inputValue(),'stage:intake');
  assert.equal(await record.locator('[name="operation_result"]').inputValue(),'Перевірено надходження товару');
  await page.locator('.pcoe-roadmap > summary').click();
  await page.locator('[data-roadmap-select="wait_stock"]').click();
  assert.equal(await stage.inputValue(),'wait_stock');assert.equal(await choice.inputValue(),'stage:intake');
  await page.locator('.pcoe-roadmap > summary').click();

  await choice.selectOption('planned:wait_stock');await result.selectOption('skipped');
  await choice.selectOption('custom');
  assert.equal(await record.locator('[data-operation-plan]:visible').count(),0);
  assert.equal(await result.inputValue(),'completed','Custom operation cannot accidentally skip a stage');
  assert(await result.locator('[value="in_progress"]').evaluate(o=>o.disabled));
  assert(await result.locator('[value="skipped"]').evaluate(o=>o.disabled));
  assert(await record.locator('[data-operation-title]').isVisible());
  assert(await record.locator('[name="operation_title"]').evaluate(input=>input.required && !input.disabled));
  assert.equal(await record.evaluate(f=>f.checkValidity()),false,'Empty custom operation title is rejected natively');
  await record.locator('[name="operation_title"]').fill('Звірка товару телефоном');
  await record.locator('[name="operation_result"]').fill('Узгоджено кількість товару та дату доставки.');
  assert(await record.evaluate(f=>f.checkValidity()));
  let data=await record.evaluate(f=>Object.fromEntries(new FormData(f)));
  assert.equal(data.operation,'record_operation');assert.equal(data.operation_choice,'custom');
  assert.equal(data.operation_title,'Звірка товару телефоном');assert.equal(data.operation_status,'completed');
  assert(data.performed_at && data.revision && data._wpnonce);
  for(const key of ['stage','stage_status','stage_detail','due','reason','active'])assert(!(key in data),'Operation does not submit plan/config fields: '+key);
  await plan.locator('[name="reason"]').fill('Узгоджено наступну перевірку складу');
  data=await plan.evaluate(f=>Object.fromEntries(new FormData(f)));
  assert.equal(data.operation,'save');assert.equal(data.stage,'wait_stock');assert.equal(data.stage_status,'in_progress');
  for(const key of ['operation_choice','operation_title','operation_result','performed_at'])assert(!(key in data),'Plan does not submit recorded fact fields: '+key);
  data=await configure.evaluate(f=>Object.fromEntries(new FormData(f)));
  assert.equal(data.operation,'configure');assert(data.revision && data._wpnonce);assert(!('stage' in data) && !('operation_result' in data));
  assert.equal(await page.locator('.pcoe-operation-latest').count(),1,'Recent recorded operation is available separately');

  for(const width of [1100,390]) {
   await page.setViewportSize({width,height:900});
   await page.evaluate(()=>{scrollTo({top:0,behavior:'instant'});return new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve)));});
   assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'No horizontal overflow');
   await page.screenshot({path:path.join(path.dirname(process.argv[2]),`roadmap-${width}.png`),fullPage:true});
   await record.screenshot({path:path.join(path.dirname(process.argv[2]),`operation-form-${width}.png`)});
  }
  await choice.selectOption('stage:intake');
  data=await record.evaluate(f=>Object.fromEntries(new FormData(f)));
  assert(!('operation_title' in data),'Hidden custom title cannot leak into a named-stage request');
  const submissions=await record.evaluate(f=>[
   f.dispatchEvent(new Event('submit',{bubbles:true,cancelable:true})),
   f.dispatchEvent(new Event('submit',{bubbles:true,cancelable:true}))
  ]);
  assert.deepEqual(submissions,[true,false],'Repeated submit is blocked locally before a second request');
  assert.equal(await record.getAttribute('aria-busy'),'true');
  await page.evaluate(()=>dispatchEvent(new Event('pageshow')));
  assert.equal(await record.getAttribute('aria-busy'),null);

  await render('legacy_html');
  const legacy=page.locator('form:has(input[name="operation"][value="record_operation"])');
  assert.equal(await page.locator('[data-roadmap-stage]').inputValue(),'');
  assert.equal(await legacy.locator('[data-operation-choice]').inputValue(),'planned:legacy');
  assert(await legacy.locator('[data-operation-custom]').isVisible());
  assert.equal(await legacy.locator('[data-operation-title]').isVisible(),false);
  assert(await legacy.locator('[data-operation-status] [value="in_progress"]').evaluate(o=>o.disabled));
  assert((await page.locator('.pcoe-stage-legacy').textContent()).includes('Wait for warehouse receipt on Thursday'));
  console.log('PASS: separate operation/plan/config forms, prior/custom/legacy operations, required results, stage rules, preserved next task, scoped scenarios, no auto-submit, duplicate protection, desktop/mobile; no network.');
 } finally {await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
