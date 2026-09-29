/* Use the temporary owner HTML from customer-approval-http.php.
 * PLAYWRIGHT_MODULE=/path/to/playwright node tests/approval-contacts-ui.cjs /tmp/approval.html
 * All network is blocked; the fixture contains only disposable test records.
 */
const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict');
const {chromium}=require(process.env.PLAYWRIGHT_MODULE||'playwright');
const root=path.resolve(__dirname,'../../../..'),fixture=path.resolve(process.argv[2]);
(async()=>{
 const browser=await chromium.launch({headless:true,channel:'chrome'});
 try {
  const page=await browser.newPage(),errors=[];page.on('pageerror',error=>errors.push(error.message));
  await page.route('**/*',r=>r.abort());
  await page.setContent(fs.readFileSync(fixture,'utf8').replace(/<script\b[^>]*>[\s\S]*?<\/script>/gi,''));
  const sheets=await page.locator('link[rel=stylesheet]').evaluateAll(es=>es.map(e=>e.href));
  for(const href of sheets){try{const u=new URL(href),file=path.resolve(root,'.'+decodeURIComponent(u.pathname));
   if(u.hostname==='paint.local' && file.startsWith(root+'/') && fs.existsSync(file))await page.addStyleTag({content:fs.readFileSync(file,'utf8')});
  }catch{}}
  await page.addScriptTag({content:fs.readFileSync(path.resolve(__dirname,'../assets/approval.js'),'utf8')});
  const form=page.locator('.pcoe-approval-form'),saved=form.locator('[data-saved-contact]');
  assert.equal(await form.count(),1);
  const contacts=await saved.locator('option[data-contact]').evaluateAll(es=>es.map(e=>({id:e.value,data:JSON.parse(e.dataset.contact)})));
  assert(contacts.length>=2,'Shipping and billing contacts are available');
  for(const contact of contacts){
   await saved.selectOption(contact.id);
   for(const name of ['recipient','phone','destination'])assert.equal(await form.locator(`[name=${name}]`).inputValue(),contact.data[name]);
  }
  await form.locator('[name=phone]').fill('+380501234567');
  assert.equal(await form.locator('[name=phone]').inputValue(),'+380501234567','Saved values remain editable');
  await saved.selectOption('');
  for(const name of ['recipient','phone','destination','payment','delivery'])assert.equal(await form.locator(`[name=${name}]`).inputValue(),'');
  assert(!(await form.evaluate(e=>e.checkValidity())),'Empty form cannot be submitted');
  // A method disabled since an old order must not be selected or posted.
  await saved.locator('option[data-contact]').first().evaluate(e=>{const d=JSON.parse(e.dataset.contact);d.payment='disabled-method';d.delivery='disabled-method';e.dataset.contact=JSON.stringify(d);});
  await saved.selectOption(contacts[0].id);
  for(const name of ['payment','delivery']){
   assert.equal(await form.locator(`[name=${name}]`).inputValue(),'');
   const value=await form.locator(`[name=${name}] option`).evaluateAll(es=>es.find(e=>e.value)?.value);
   assert(value,'An enabled method exists');await form.locator(`[name=${name}]`).selectOption(value);
  }
  assert.equal(await form.locator('[name=phone]').getAttribute('type'),'tel');
  assert.equal(await form.locator('[name=phone]').getAttribute('autocomplete'),'shipping tel');
  assert(!(await form.evaluate(e=>e.checkValidity())),'Consent is required');
  await form.locator('[name=consent]').check();assert(await form.evaluate(e=>e.checkValidity()));
  await form.locator('[name=consent]').uncheck();
  for(const width of [1100,390]){
   await page.setViewportSize({width,height:950});
   assert(!(await form.evaluate(e=>{const r=e.getBoundingClientRect();return r.right>innerWidth+1 || r.left < -1;})),'Form fits viewport');
   assert(!(await form.evaluate(e=>e.scrollWidth>e.clientWidth+1)),'No overflowing fields');
   await form.screenshot({path:path.join(path.dirname(fixture),`approval-contacts-${width}.png`)});
  }
  assert.deepEqual(errors,[]);
  console.log('PASS: saved contacts, editable values, manual entry, disabled methods, consent validation, desktop/mobile; no network or submission.');
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
