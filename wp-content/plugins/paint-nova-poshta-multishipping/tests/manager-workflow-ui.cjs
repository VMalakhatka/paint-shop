// Synthetic PHP-rendered fixture only. No production, carrier or mail traffic.
const {chromium}=require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const {pathToFileURL}=require('node:url');
const dir=process.argv[2];
if(!dir) throw new Error('Fixture directory required');
(async()=>{
 const browser=await chromium.launch({headless:true,channel:'chrome'});
 let checks=0;
 try {
  for(const width of [1280,390]) {
   const page=await browser.newPage({viewport:{width,height:1000}}), errors=[], calls=[];
   let revision=0, failSave=false, changedRecipient=false, ttl=300;
   const panel=fs.readFileSync(path.join(dir,'lifecycle-panel.html'),'utf8');
   page.on('pageerror',e=>errors.push(e.message));
   await page.route('**/*',async route=>{
    const req=route.request(),url=new URL(req.url());
    if(url.protocol==='file:')return route.continue();
    if(url.hostname!=='pnpm-test.invalid')return route.abort();
    const p=new URLSearchParams(req.postData()||url.search), action=p.get('action'), command=p.get('command');
    calls.push({action,command,payload:p.get('payload')?JSON.parse(p.get('payload')):null});
    const send=(data,status=200)=>route.fulfill({status,contentType:'application/json',headers:{'Access-Control-Allow-Origin':'*'},body:JSON.stringify(data)});
    if(action==='pnpm_manager_preparation' && command==='save') {
     await new Promise(r=>setTimeout(r,150));
     if(failSave){failSave=false;return send({success:false,data:{message:'Synthetic save failure'}},409);}
     revision++;
     return send({success:true,data:{revision:'revision-'+revision,message:'Saved; panel updated',lifecycle_html:changedRecipient?panel.replace(/data-recipient="[^"]+"/g,'data-recipient="different-recipient"'):panel,audit_html:'<div id="pnpm-preparation-audit">Saved '+revision+'</div>'}});
    }
    if(action==='pnpm_shipment_command' && command==='preview') {
     await new Promise(r=>setTimeout(r,100));
     return send({success:true,data:{preview:{token:'a'.repeat(48),expires_in:ttl,enabled:true,recipient:'Тест Приклад',phone:'380000000000',origin:'Відділення відправника',destination:'Відділення одержувача',estimate:120,payload:{Cost:215,PayerType:'Recipient',OptionsSeat:[{weight:0.1,volumetricLength:9,volumetricWidth:6,volumetricHeight:6}]},cod_summary:['Сума до отримання: 215.00 грн.']}}});
    }
    if(action==='pnpm_shipment_command' && command==='create')return route.abort();
    return send({success:false,data:{message:'Synthetic directory unavailable'}},409);
   });
   await page.goto(pathToFileURL(path.join(dir,'manager-workflow.html')).href);
   const form=page.locator('#pnpm-manager-form'), cards=page.locator('.pnpm-shipment');
   const save=form.locator('[data-pnpm-command=save]');
   const check=(value)=>{assert(value);checks++;};
   const saveOnce=async()=>{
    await form.locator('[name=agreed]').check();await save.click();
    await page.waitForFunction(n=>document.querySelector('#pnpm-manager-form').dataset.revision==='revision-'+n,revision+1);
   };
   check(await cards.count()===0);
   await page.locator('#other-unsaved').fill('Unrelated unsaved edit');
   await form.locator('[name=agreed]').check();
   await save.evaluate(el=>{el.click();el.click();});
   await page.waitForFunction(()=>document.querySelector('#pnpm-manager-form').dataset.revision==='revision-1');
   check(calls.filter(c=>c.command==='save').length===1);
   check(await cards.count()===2);
   check(await cards.first().locator('[data-shipment-command=preview]').isEnabled());
   check(await cards.first().locator('[data-shipment-command=create]').isDisabled());
   check(!calls.some(c=>c.action==='pnpm_shipment_command'));
   check(await page.locator('#other-unsaved').inputValue()==='Unrelated unsaved edit');
   check(await page.locator('#pnpm-preparation-audit').textContent()==='Saved 1');
   const card=cards.first(), preview=card.locator('[data-shipment-command=preview]'), create=card.locator('[data-shipment-command=create]'), agreed=card.locator('.pnpm-create-agreed');
   await card.locator('[data-person=FirstName]').fill('Тест');
   await card.locator('[data-person=LastName]').fill('Приклад');
   await preview.click();check(await save.isDisabled());
   await agreed.waitFor({state:'visible'});
   await page.waitForFunction(()=>!document.querySelector('.pnpm-create-agreed').disabled);
   await agreed.check();check(await create.isEnabled());
   await form.locator('[data-box=weight]').first().fill('0.2');
   check(await preview.isDisabled() && await create.isDisabled() && !(await agreed.isChecked()));
   await saveOnce();
   check(await preview.isEnabled() && await create.isDisabled());
   check(await card.locator('[data-person=FirstName]').inputValue()==='Тест');
   check(await card.locator('[data-person=LastName]').inputValue()==='Приклад');
   check(calls.filter(c=>c.command==='save').at(-1).payload.revision==='revision-1');
   ttl=1;await preview.click();
   await page.waitForFunction(()=>!document.querySelector('.pnpm-create-agreed').disabled);
   await agreed.check();
   await page.waitForFunction(()=>document.querySelector('.pnpm-create-agreed').disabled);
   check(await create.isDisabled() && await preview.isEnabled());
   check((await card.locator('.pnpm-lifecycle-status').textContent()).includes('минув'));
   check(!calls.some(c=>c.command==='create'));
   ttl=300;await preview.click();
   await page.waitForFunction(()=>!document.querySelector('.pnpm-create-agreed').disabled);
   await agreed.check();failSave=true;
   await save.click();
   await page.waitForFunction(()=>document.querySelector('#pnpm-manager-status').textContent==='Synthetic save failure');
   check(await create.isDisabled() && await preview.isDisabled());
   check(await form.getAttribute('data-revision')==='revision-2');
   check(await form.locator('[data-box=weight]').first().inputValue()==='0.2');
   await saveOnce();check(await preview.isEnabled());
   changedRecipient=true;await form.locator('[name=name]').fill('Інший одержувач');
   await saveOnce();check(await card.locator('[data-person=FirstName]').inputValue()==='');
   await card.locator('[data-person=FirstName]').fill('Тест');await card.locator('[data-person=LastName]').fill('Приклад');
   await preview.click();await page.waitForFunction(()=>!document.querySelector('.pnpm-create-agreed').disabled);
   await page.screenshot({path:path.join(dir,'workflow-'+width+'.png'),fullPage:true});
   await agreed.check();await create.click();
   await page.waitForFunction(()=>!document.querySelector('[data-pnpm-command=save]').disabled);
   check(await create.isDisabled() && await agreed.isDisabled());
   check(calls.filter(c=>c.command==='create').length===1);
   check(await page.locator('#other-unsaved').inputValue()==='Unrelated unsaved edit');
   check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth));
   assert.deepEqual(errors,[]);checks++;
   await page.close();
  }
  console.log(`Manager workflow UI: ${checks} checks passed on desktop/mobile; all network mocked.`);
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
