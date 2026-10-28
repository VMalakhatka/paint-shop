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
  const form=page.locator('.pcoe-execution-form').filter({has:page.locator('[data-roadmap-stage]')});
  const select=form.locator('[data-roadmap-stage]'),status=form.locator('[data-roadmap-status]');
  assert.equal(await select.locator('option').count(),fixture.stages.length+1);
  assert.equal(await select.inputValue(),'return');
  assert(await form.locator('[data-roadmap-scenario="return"]').isVisible());
  assert.equal(await status.locator('[value="skipped"]').evaluate(option=>option.disabled),false);
  await select.selectOption('intake');
  assert(await status.locator('[value="skipped"]').evaluate(option=>option.disabled));
  assert.equal(await status.inputValue(),'completed','Selecting a recorded stage shows its saved result');
  assert(await form.locator('[data-roadmap-scenario="intake"]').isVisible());
  assert.equal(await form.locator('[data-roadmap-scenario]:visible').count(),1);
  await select.selectOption('box_passports');
  assert.equal(await status.inputValue(),'in_progress');
  assert.equal(await form.locator('[name="stage_detail"]').inputValue(),'','A new stage does not inherit another stage details');
  assert.equal(await form.locator('[name="due"]').inputValue(),'','A new stage needs its own deadline');
  await page.locator('.pcoe-roadmap > summary').click();
  await page.locator('[data-roadmap-select="shipment"]').click();
  assert.equal(await select.inputValue(),'shipment');
  assert.equal(await status.inputValue(),'skipped');
  assert(await form.locator('[data-roadmap-scenario="shipment"]').isVisible());
  await select.selectOption('wait_stock');
  await status.selectOption('in_progress');
  await form.locator('[name="stage_detail"]').fill('Warehouse B receipt');
  await form.locator('[name="reason"]').fill('Arrival agreed with warehouse');
  const data=await form.evaluate(f=>Object.fromEntries(new FormData(f)));
  assert.equal(data.stage,'wait_stock');assert.equal(data.stage_detail,'Warehouse B receipt');
  assert.equal(data.stage_status,'in_progress');assert.equal(data.operation,'save');
  assert(data.revision && data._wpnonce);assert(!('next' in data));
  await page.locator('.pcoe-roadmap > summary').click();
  for(const width of [1100,390]) {
   await page.setViewportSize({width,height:900});
   await page.evaluate(()=>{scrollTo({top:0,behavior:'instant'});return new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve)));});
   assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'No horizontal overflow');
   await page.screenshot({path:path.join(path.dirname(process.argv[2]),`roadmap-${width}.png`),fullPage:true});
  }
  await render('legacy_html');
  assert.equal(await page.locator('[data-roadmap-stage]').inputValue(),'');
  assert(await page.locator('[data-roadmap-empty]').isVisible());
  assert((await page.locator('.pcoe-stage-legacy').textContent()).includes('Wait for warehouse receipt on Thursday'));
  assert.equal(await page.locator('[name="stage_detail"]').inputValue(),'Wait for warehouse receipt on Thursday');
  console.log('PASS: 19 stages, scenario selection, saved results, optional skip, independent details/deadlines, protected form fields, legacy note, desktop/mobile; no network.');
 } finally {await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
