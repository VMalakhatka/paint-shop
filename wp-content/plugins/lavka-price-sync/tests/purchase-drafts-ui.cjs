const {chromium}=require('playwright');
const {execFileSync}=require('child_process');
const assert=require('assert');
const fixture=require('path').join(__dirname,'purchase-planning-ui.php');
const php=process.env.PHP_BINARY||'php';
const token='a'.repeat(32);
(async()=>{
 const browser=await chromium.launch({headless:true,...(process.env.CHROME_BINARY?{executablePath:process.env.CHROME_BINARY}:{})});
 for(const width of [1440,390]) {
  const page=await browser.newPage({viewport:{width,height:1000}}); const errors=[];page.on('pageerror',e=>errors.push(e.message));
  let items=[JSON.parse(execFileSync(php,[fixture,'calculate'],{encoding:'utf8'}))],revision=1, starts=0;
  const meta={token,scenario:{name:'Saved Kreul',version:2},groups:[{name:'Kyiv',warehouseIds:[1]}],query:{period:{from:'2026-08-01',to:'2026-08-30'}}};
  await page.route('https://fixture.local/order*',r=>r.fulfill({contentType:'text/html',body:execFileSync(php,[fixture],{encoding:'utf8'})}));
  await page.route('https://fixture.local/api', async r=>{
   const body=new URLSearchParams(r.request().postData()),op=body.get('operation'),payload=JSON.parse(body.get('payload'));
   let data;
   if(op==='start'){starts++;data=meta;}
   else if(op==='page')data={items,complete:true,replaceItems:true,page:1,loaded:1,total:1,warnings:[],draftRevision:revision};
   else if(op==='drafts')data={items:[{token,title:'Saved Kreul',created_at:'2026-10-15 12:00:00'}]};
   else if(op==='open')data={...meta,items,revision,createdAt:'2026-10-15',currentStockCapture:{capturedAt:'2026-10-15T12:00:00Z'}};
   else if(op==='adjust'){
    assert.equal(payload.revision,revision);
    items=[JSON.parse(execFileSync(php,[fixture,'calculate',JSON.stringify(payload.groups)],{encoding:'utf8'}))];
    data={items,draftRevision:++revision};
   }else throw Error(op);
   await r.fulfill({contentType:'application/json',body:JSON.stringify({success:true,data})});
  });
  await page.goto('https://fixture.local/order');
  await page.locator('#lps-purchase-scenario').selectOption('1');await page.locator('#lps-purchase-start').click();
  await page.waitForURL('**purchase_draft=*');
  await page.locator('[data-edit-form="0"]').locator('..').locator('summary').click();
  const form=page.locator('[data-edit-form="0"]');
  await form.locator('[data-field="packRounding"]').selectOption('NONE');
  await form.locator('[data-field="quantity"]').fill('2');await form.locator('[data-field="reason"]').fill('Saved manual quantity');
  await form.locator('button').click();
  await page.waitForFunction(()=>document.querySelector('#lps-purchase-message').textContent.includes('Draft saved'));
  await page.reload();
  await page.waitForFunction(()=>document.querySelector('#lps-purchase-message').textContent.includes('Saved draft opened'));
  assert.equal(starts,1,'Reload opens saved draft without recalculation');
  assert.equal(await page.locator('[data-field="quantity"]').inputValue(),'2');
  assert.equal(await page.locator('[data-field="reason"]').inputValue(),'Saved manual quantity');
  assert.equal(await page.locator('[data-export="xlsx"]').isDisabled(),false);
  assert.deepEqual(errors,[]);
  await page.screenshot({path:require('path').join(require('os').tmpdir(),`purchase-drafts-${width}.png`),fullPage:true});
  await page.close();
 }
 await browser.close(); console.log('PASS: saved draft reload, manual edits, revision submission and export desktop/mobile');
})().catch(e=>{console.error(e);process.exit(1)});
