/* Offline real form markup from approval-delivery.php; all AJAX is intercepted. */
const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict');
const {chromium}=require(process.env.PLAYWRIGHT_MODULE||'playwright');
const fixtureFile=path.resolve(process.argv[2]),fixture=JSON.parse(fs.readFileSync(fixtureFile,'utf8'));
const root=path.resolve(__dirname,'../../../..'),base=path.resolve(__dirname,'..'),np=path.resolve(base,'../paint-nova-poshta-multishipping');
const read=p=>fs.readFileSync(p,'utf8');
const html='<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><style>'+
 'body{font:16px/1.5 system-ui;margin:0;padding:16px}main{max-width:780px;margin:auto}*{box-sizing:border-box}input,select,textarea{padding:10px;font:inherit}button{font:inherit;max-width:100%;white-space:normal}table{width:100%;border-collapse:collapse}td,th{border:1px solid #ddd;padding:6px}'+
 read(path.join(np,'assets/checkout.css'))+read(path.join(base,'assets/approval.css'))+'</style><main>'+fixture.html+'</main><script>'+read(path.join(root,'wp-includes/js/jquery/jquery.min.js'))+'</script><script>'+fixture.pnpm+fixture.approval+'</script>'+
 ['approval.js','approval-delivery.js'].map(f=>'<script>'+read(path.join(base,'assets',f))+'</script>').join('')+'<script>'+read(path.join(np,'assets/checkout.js'))+'</script>';
(async()=>{
 const browser=await chromium.launch({headless:true,channel:'chrome'});let checks=0;
 try {
  for(const width of [1100,390]){
   const page=await browser.newPage({viewport:{width,height:1000}}),errors=[];let mode='ok',quotes=0;
   page.on('pageerror',e=>errors.push(e.message));
   await page.route('**/*',async route=>{
    const url=new URL(route.request().url());
    if(url.pathname==='/__approval_fixture')return route.fulfill({contentType:'text/html',body:html});
    if(url.hostname!=='paint.local'||url.pathname!=='/wp-admin/admin-ajax.php')return route.abort();
    const q=route.request().method()==='POST'?new URLSearchParams(route.request().postData()):url.searchParams;
    const action=q.get('action');let data;
    if(action==='pnpm_search_recipient_cities')data={items:[{ref:fixture.city,label:'Тестове місто'}]};
    else if(action==='pnpm_search_recipient_points'||action==='pnpm_recipient_point'){
     const item={ref:fixture.point,label:q.get('kind')==='parcel_locker'?'Поштомат №7':'Відділення №7',kind:q.get('kind')==='parcel_locker'?'postomat':'branch',selectable:true,placeWeight:30,totalWeight:100,declaredValue:10000,receivingDimensions:[120,70,70],sendingDimensions:[120,70,70],hours:{}};
     data=action==='pnpm_recipient_point'?{item}:{items:[item],nextPage:null};
    }else if(action==='pcoe_approval_quote'){
     quotes++;
     if(mode==='slow')await new Promise(r=>setTimeout(r,650));
     if(mode==='error')return route.fulfill({status:409,contentType:'application/json',body:JSON.stringify({success:false,data:{message:'Тест: НП недоступна'}})});
     data={...fixture.quote,expires:Math.floor(Date.now()/1000)+600,cod_allowed:false,
      recipient:{...fixture.quote.recipient,delivery_type:q.get('pnpm_delivery_type'),address:q.get('pnpm_address')||''}};
    }else throw new Error('Unexpected action '+action);
    return route.fulfill({contentType:'application/json',body:JSON.stringify({success:true,data})});
   });
   await page.goto('http://paint.local/__approval_fixture');
   const form=page.locator('.pcoe-approval-form'),submit=form.locator('button[type=submit]'),quote=form.locator('[data-quote-button]'),result=form.locator('[data-quote-result]');
   await form.locator('[name=delivery]').selectOption(fixture.method);
   assert(await page.locator('#pnpm-checkout-fields').isVisible());assert(await submit.isDisabled());checks++;
   await page.locator('#pnpm_city_label').fill('Тест');await page.locator('#pnpm-city-results button').click();
   await page.locator('#pnpm_point_label').fill('7');await page.locator('#pnpm-point-results button').click();
   assert(await page.locator('#pnpm-point-card').isVisible());assert(await form.locator('[name=destination]').isHidden());checks++;
   await quote.click();await page.waitForFunction(()=>document.querySelector('[name=delivery_quote_token]').value!=='');
   assert(!(await submit.isDisabled()));assert((await result.textContent()).includes('80'));assert.equal(quotes,1);checks++;
   if(await form.locator('[name=payment] option[value=cod]').count())assert(await form.locator('[name=payment] option[value=cod]').isDisabled());
   await form.locator('[name=recipient]').fill('Тестовий отримувач');await form.locator('[name=phone]').fill('+380500001122');
   await form.screenshot({path:path.join(path.dirname(fixtureFile),`approval-np-${width}.png`)});
   assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'No horizontal overflow');checks++;
   await page.locator('#pnpm_point_label').fill('8');assert(await submit.isDisabled());assert.equal(await form.locator('[name=delivery_quote_token]').inputValue(),'');checks++;
   await page.locator('#pnpm-point-results button').click();mode='error';await quote.click();await result.getByText('Тест: НП недоступна').waitFor();assert(await submit.isDisabled());assert(!(await quote.isDisabled()));checks++;
   mode='slow';await quote.click();await page.locator('#pnpm_city_label').fill('Нове місто');await page.waitForTimeout(800);
   assert.equal(await form.locator('[name=delivery_quote_token]').inputValue(),'');assert(await submit.isDisabled());checks++;
   mode='ok';await page.locator('#pnpm-city-results button').click();await page.locator('#pnpm_delivery_type').selectOption('address');
   assert(await page.locator('[name=pnpm_address]').isVisible());assert(!(await page.locator('#pnpm-point-fields').isVisible()));
   await page.locator('[name=pnpm_address]').fill('Тестова, 12');await quote.click();await page.waitForFunction(()=>document.querySelector('[name=delivery_quote_token]').value!=='');
   assert((await form.locator('[name=destination]').inputValue()).includes('Тестова, 12'));checks++;
   await page.locator('[name=pnpm_address]').fill('Тестова, 13');assert(await submit.isDisabled());checks++;
   const other=await form.locator('[name=delivery] option').evaluateAll(es=>es.find(e=>e.value&&!e.value.startsWith('pnpm_nova_poshta:'))?.value);
   assert(other);await form.locator('[name=delivery]').selectOption(other);assert(!(await page.locator('#pnpm-checkout-fields').isVisible()));assert(await form.locator('[name=destination]').isVisible());assert(!(await submit.isDisabled()));checks++;
   assert.deepEqual(errors,[]);await page.close();
  }
  console.log(`PASS: ${checks} approval NP browser checks (desktop/mobile, quote/errors/stale responses, destination changes, normal delivery); all requests intercepted, no confirmation submitted.`);
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
