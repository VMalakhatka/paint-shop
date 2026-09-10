// Only synthetic document data. No production requests or credentials.
const {test}=require('node:test'), assert=require('node:assert/strict'), {chromium}=require('playwright');
const fs=require('node:fs'),path=require('node:path'),{execFileSync}=require('node:child_process');
const base=path.resolve(__dirname,'..'),output=process.env.AUDIT_TEST_OUTPUT || '/tmp/folio-document-audit/test-output';fs.mkdirSync(output,{recursive:true});
const html=execFileSync(process.env.PHP_BIN || 'php',[path.join(__dirname,'document-audit-page.php')],{encoding:'utf8'});
const item=(id,status='VALID')=>({document:{paymentId:id,documentNumber:id===1?'=HYPERLINK("synthetic")':String(id),documentDate:'2026-07-02',warehouseId:5,bank:false,direction:'OUTGOING',amount:'3128.00',currencyCode:'UAH',organizationCode:'TEST',organizationName:'Synthetic',purposeCode:'TEST',operationType:'TEST',sourceInfo:status==='ERROR'?'':'зп',note:'2026 07 <img src=x onerror=alert(1)>',resolvedPeriod:'2026-07',amountCurrencyStatus:'NOT_CONFIRMED_FROM_COD_VALUT'},category:{code:'SALARY',label:'Salary',recognition:'RECOGNIZED'},status,ruleIds:['SALARY'],findings:status==='ERROR'?[{code:'SOURCE_INFO_REQUIRED',severity:'ERROR',field:'sourceInfo',actual:'',expected:'зп',recommendation:'Check source',ruleId:'SALARY'}]:[]});
function body(after=0){return {ok:true,status:'PAGE_READY',rulesVersion:'test-1',calculatedAt:'2026-09-09T12:00:00Z',dateFrom:'2026-07-01',dateTo:'2026-07-31',coverage:{source:'SCL_PLAT',dateBasis:'DOCUMENT_DATE_INCLUSIVE',consistency:'LIVE_NOT_SNAPSHOT',allWarehouses:true,directions:['INCOMING','OUTGOING','UNKNOWN'],registers:['CASH','BANK'],pageComplete:true,rulesComplete:false,unsupported:['Invoices not checked'],warnings:[]},page:{pageSize:200,afterPaymentId:after,upperPaymentId:2,nextAfterPaymentId:after?null:1,hasMore:!after,totalDocuments:2},summary:{scope:'PAGE',examined:1},items:[item(after?2:1,after?'ERROR':'VALID')],rules:[{id:'SALARY',label:'Salary',sourceSheet:'расход',sourceRows:[10,11],requiredSourceInfo:'зп',periodRequirement:'REQUIRED',limitations:['Partial coverage']}]};}
let browser;
test.before(async()=>{browser=await chromium.launch({headless:true,...(process.env.CHROME_BIN?{executablePath:process.env.CHROME_BIN}:{})});});
test.after(async()=>{await browser.close();});
async function setup(mode){
 const page=await browser.newPage({viewport:{width:1440,height:1000}}), requests=[],errors=[];page.on('pageerror',e=>errors.push(e.message));
 await page.route('https://audit.test/**',async route=>{
  if(!route.request().url().endsWith('/ajax')) return route.fulfill({contentType:'text/html',body:html});
  const q=new URLSearchParams(route.request().postData());requests.push(Object.fromEntries(q));const after=Number(q.get('afterPaymentId')||0);let b=body(after);
  if(mode==='java') {
   b=JSON.parse(fs.readFileSync(path.join(__dirname,'fixtures/document-audit-java.json'),'utf8'));
   // Java fixture is the first of two pages; derive a synthetic final page.
   if(after){ b.page.afterPaymentId=after; b.page.hasMore=false; b.page.nextAfterPaymentId=null; b.items[0].document.paymentId=2; b.items[0].document.documentNumber='2'; }
  }
  if(mode==='disabled') return route.fulfill({contentType:'application/json',body:JSON.stringify({success:true,data:{httpStatus:400,bodyRaw:JSON.stringify({status:400,code:'DOCUMENT_AUDIT_DISABLED',message:'Disabled'})}})});
  if(mode==='failure' && after) return route.fulfill({status:200,body:JSON.stringify({success:true,data:{httpStatus:503,bodyRaw:JSON.stringify({ok:false,errorCode:'SOURCE_UNAVAILABLE'})}})});
  if(mode==='duplicate' && after) b.items=[item(1)];
  if(mode==='version' && after) b.rulesVersion='test-2';
  if(mode==='unknown') b.items[0].status='FUTURE_STATUS';
  if(mode==='empty'){b.items=[];b.summary.examined=0;b.page.totalDocuments=0;b.page.hasMore=false;b.page.nextAfterPaymentId=null;}
  if(mode==='hold' && after) await new Promise(r=>setTimeout(r,400));
  return route.fulfill({contentType:'application/json',body:JSON.stringify({success:true,data:{httpStatus:200,bodyRaw:JSON.stringify(b)}})});
 });
 await page.goto('https://audit.test/');await page.addStyleTag({content:'body{font-family:Arial;margin:20px}*{box-sizing:border-box}button{padding:8px}'});
 await page.addStyleTag({path:path.join(base,'document-audit.css')});await page.addScriptTag({path:path.join(base,'profit-xlsx.js')});await page.addScriptTag({path:path.join(base,'document-audit.js')});
 await page.locator('#lda-from').fill(mode==='java'?'2026-08-01':'2026-07-01');await page.locator('#lda-to').fill(mode==='java'?'2026-08-31':'2026-07-31');await page.locator('#lda-run').click();
 return {page,requests,errors};
}
async function finish(page){await page.waitForFunction(()=>!document.getElementById('lda-run').disabled);}
test('all pages, salary error retained, safe text, filters and full export',async()=>{
 const {page,requests,errors}=await setup();await finish(page);
 assert.equal(await page.locator('#lda-registry tbody tr').count(),2);assert.equal(requests[1].upperPaymentId,'2');assert.equal(requests[1].expectedRulesVersion,'test-1');
 assert.match(await page.locator('#lda-registry').innerText(),/SOURCE_INFO_REQUIRED/);assert.equal(await page.locator('#lda-registry img').count(),0);
 await page.locator('#lda-status').selectOption('ERROR');assert.equal(await page.locator('#lda-registry tbody tr').count(),1);
 const d=page.waitForEvent('download');await page.locator('#lda-export').click();await(await d).saveAs(path.join(output,'audit.xlsx'));
 await page.screenshot({path:path.join(output,'desktop.png'),fullPage:true});await page.setViewportSize({width:390,height:844});await page.screenshot({path:path.join(output,'mobile.png'),fullPage:true});
 assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),true);
 await page.locator('#lda-to').fill('2026-08-31');assert.equal(await page.locator('#lda-export').isDisabled(),true);assert.deepEqual(errors,[]);await page.close();
});
for(const mode of ['failure','duplicate','version'])test(mode+' preserves earlier rows and marks incomplete',async()=>{
 const {page}=await setup(mode);await finish(page);assert.equal(await page.locator('#lda-registry tbody tr').count(),1);assert.match(await page.locator('#lda-state').innerText(),/Incomplete/);assert.equal(await page.locator('#lda-export').isEnabled(),true);await page.close();
});
test('unknown status remains visible under rule review',async()=>{const {page}=await setup('unknown');await finish(page);await page.locator('#lda-status').selectOption('RULE_REVIEW');assert.equal(await page.locator('#lda-registry tbody tr').count(),2);assert.match(await page.locator('#lda-registry').innerText(),/FUTURE_STATUS/);await page.close();});
test('empty complete audit and date change during request',async()=>{let {page}=await setup('empty');await finish(page);assert.match(await page.locator('#lda-state').innerText(),/finished/);await page.close();({page}=await setup('hold'));await page.waitForFunction(()=>document.querySelectorAll('#lda-registry tbody tr').length===1);await page.locator('#lda-to').fill('2026-08-31');await finish(page);assert.equal(await page.locator('#lda-export').isDisabled(),true);assert.match(await page.locator('#lda-state').innerText(),/Dates changed/);await page.close();});

test('Java-generated DTO fixture matches consumer and preserves salary ERROR',async()=>{const {page,errors}=await setup('java');await finish(page);assert.match(await page.locator('#lda-state').innerText(),/finished/);assert.equal(await page.locator('#lda-registry tbody tr').count(),2);assert.match(await page.locator('#lda-registry').innerText(),/SOURCE_INFO_REQUIRED/);assert.deepEqual(errors,[]);await page.close();});
test('actual disabled validation envelope explains activation instead of false zero',async()=>{const {page}=await setup('disabled');await finish(page);assert.match(await page.locator('#lda-error').innerText(),/administrator must restrict API access/);assert.equal(await page.locator('#lda-result').isHidden(),true);assert.equal(await page.locator('#lda-export').isDisabled(),true);await page.close();});
