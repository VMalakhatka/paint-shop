// Saved-report integration tests: synthetic data, no Folio or application DB.
const {test}=require('node:test'),assert=require('node:assert/strict'),{chromium}=require('playwright');
const fs=require('node:fs'),path=require('node:path'),{execFileSync}=require('node:child_process');
const base=path.resolve(__dirname,'..'),out='/tmp/folio-profit-history/test-output';fs.mkdirSync(out,{recursive:true});
const html=execFileSync(process.env.PHP_BIN||'php',[path.join(__dirname,'profit-history-page.php')],{encoding:'utf8'});
function report(month){return {ok:true,month,ruleVersion:'test-1',calculatedAt:'2026-09-09T12:00:00Z',complete:true,inputs:{odesaAdditionalSalary:'5000',odesaAdditionalSalarySource:'DEFAULT',kyivAdditionalSalary:'0',kyivAdditionalSalarySource:'DEFAULT',rubToUahRate:'0.41',odesaTaxShare:'0.428571'},cities:['KYIV','ODESA'].map(city=>({city,baseGrossProfit:'100',manualGrossAdjustments:'0',grossProfit:'100',operatingExpenses:'20',profit:'80'})),expenseLines:[],expenses:[],inventory:[],documents:[],masterClassDocuments:[],periodDiagnostics:[],warnings:[],controls:{auditTruncated:false},periodPolicy:{description:'Synthetic'}};}
function wrapper(month,id=1){return {ok:true,status:'COMPLETED',month,revisionId:id,publishedRevisionId:1,latestRevisionId:1,latestStatus:'COMPLETED',published:id===1,auditComplete:true,createdAt:'2026-09-09T12:00:00Z',completedAt:'2026-09-09T12:00:00Z',requestId:'synthetic',report:report(month)};}
function list(from,to,missing=false){const months=[];let m=from;while(m<=to){months.push(m);let[y,n]=m.split('-').map(Number);n++;if(n>12){n=1;y++;}m=`${y}-${String(n).padStart(2,'0')}`;}
return {ok:true,fromMonth:from,toMonth:to,sourceDatabase:'SYNTHETIC',complete:!missing,missingMonths:missing?[months.at(-1)]:[],months:months.map((m,i)=>missing&&i===months.length-1?{month:m,status:'MISSING',revisionId:null}:{month:m,revisionId:i+1,status:'COMPLETED',latestRevisionId:i+1,latestStatus:'COMPLETED',ruleVersion:'test-1',cities:report(m).cities}),totals:[{city:'KYIV',grossProfit:missing?null:'200',operatingExpenses:missing?null:'40',profit:missing?null:'160',openingAccountingValue:'1000',closingAccountingValue:'1200',accountingValueChange:'200',complete:!missing}],warnings:missing?['Missing month']:[]};}
let browser;
test.before(async()=>{browser=await chromium.launch({headless:true,...(process.env.CHROME_BIN?{executablePath:process.env.CHROME_BIN}:{})});});test.after(async()=>{await browser.close();});
async function setup(mode='normal'){
 const page=await browser.newPage({viewport:{width:1440,height:1100}}),requests=[],errors=[];page.on('pageerror',e=>errors.push(e.message));
 await page.route('https://profit.test/**',async route=>{
  if(route.request().url().endsWith('/history')){
   const q=Object.fromEntries(new URLSearchParams(route.request().postData()));requests.push(q);let body;
   if(q.operation==='range')body=list(q.fromMonth,q.toMonth,mode==='missing');
   else if(q.operation==='month'){body=wrapper(q.month,Number(q.revisionId));if(mode==='failedRevision')body={...body,ok:false,status:'FAILED',report:null,errorCode:'SOURCE_FAILED'};}
   else if(q.operation==='revisions')body={ok:true,month:q.month,revisions:[{...wrapper(q.month,9),status:'PROVISIONAL',report:null}],hasMore:false};
   else if(q.operation==='calculate'){
    if(mode==='timeout')return route.fulfill({status:502,contentType:'application/json',body:JSON.stringify({success:false,data:{message:'Synthetic lost response'}})});
    if(mode==='slow')await new Promise(r=>setTimeout(r,500));
    body=wrapper(q.month,9);body.requestId=q.requestId;
    if(q.odesaAdditionalSalary!=null){body.report.inputs.odesaAdditionalSalary=q.odesaAdditionalSalary;body.report.inputs.odesaAdditionalSalarySource='REQUEST_OVERRIDE';}
   }
   if(mode==='slowOpen' && q.operation==='month')await new Promise(r=>setTimeout(r,500));
   if(mode==='java' && q.operation==='month')body=JSON.parse(fs.readFileSync(path.join(__dirname,'fixtures/profit-saved-java.json'),'utf8'));
   if(mode==='wrongRevision'&&q.operation==='month')body.revisionId=777;
   return route.fulfill({contentType:'application/json',body:JSON.stringify({success:true,data:{httpStatus:200,bodyRaw:JSON.stringify(body)}})});
  }
  if(route.request().url().endsWith('/ajax'))throw new Error('Forbidden live profit endpoint');
  return route.fulfill({contentType:'text/html',body:html});
 });
 await page.goto('https://profit.test/');await page.addStyleTag({content:'body{font-family:Arial;margin:20px}*{box-sizing:border-box}button{padding:7px}'});await page.addStyleTag({path:path.join(base,'profit-report.css')});
 for(const file of ['profit-xlsx.js','profit-report.js','profit-history.js'])await page.addScriptTag({path:path.join(base,file)});
 await page.waitForFunction(()=>!document.getElementById('lph-view').disabled);
 return {page,requests,errors};
}
async function range(page){await page.locator('#lph-from').fill('2025-07');await page.locator('#lph-to').fill('2025-08');await page.locator('#lph-view').click();await page.waitForFunction(()=>!document.getElementById('lph-view').disabled);}
test('default saved view, monthly audit and exports never calculate Folio',async()=>{
 const {page,requests,errors}=await setup();await range(page);assert(requests.every(r=>r.operation==='range'));
 await page.locator('#lph-months button').first().click();await page.waitForFunction(()=>!document.getElementById('lph-view').disabled);assert.equal(await page.locator('#lavr-profit-result').isVisible(),true);
 await page.locator('#lavr-profit-load-audit').click();
 const d=page.waitForEvent('download');await page.locator('#lavr-profit-export-xlsx').click();await(await d).saveAs(path.join(out,'month.xlsx'));
 const e=page.waitForEvent('download');await page.locator('#lph-export').click();await(await e).saveAs(path.join(out,'range.xlsx'));
 assert(requests.every(r=>['range','month'].includes(r.operation)));assert.deepEqual(errors,[]);
 await page.screenshot({path:path.join(out,'desktop.png'),fullPage:true});await page.setViewportSize({width:390,height:844});await page.screenshot({path:path.join(out,'mobile.png'),fullPage:true});assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),true);await page.close();
});
test('missing month remains visible and does not become zero',async()=>{const{page,requests}=await setup('missing');await range(page);assert.match(await page.locator('#lph-months').innerText(),/No saved report/);assert.match(await page.locator('#lph-totals').innerText(),/—/);assert(requests.every(r=>r.operation==='range'));await page.close();});
test('explicit range saves each month with zero override and distinct request IDs',async()=>{const{page,requests,errors}=await setup();await range(page);await page.locator('#lavr-profit-manual').evaluate(n=>n.open=true);await page.locator('#lavr-profit-additional-salary').fill('0');await page.locator('#lph-calculate').click();await page.waitForFunction(()=>!document.getElementById('lph-view').disabled);const c=requests.filter(r=>r.operation==='calculate');assert.deepEqual(c.map(r=>r.month),['2025-07','2025-08']);assert(c.every(r=>r.odesaAdditionalSalary==='0'));assert.notEqual(c[0].requestId,c[1].requestId);assert.deepEqual(errors,[]);await page.close();});
test('stop waits for current month then prevents following commands',async()=>{const{page,requests}=await setup('slow');await range(page);await page.locator('#lph-calculate').click();await page.locator('#lph-stop').click();await page.waitForFunction(()=>!document.getElementById('lph-view').disabled);assert.equal(requests.filter(r=>r.operation==='calculate').length,1);assert.match(await page.locator('#lph-state').innerText(),/Stopped/);await page.close();});
test('uncertain save stops campaign, retains request ID and does not auto-retry',async()=>{const{page,requests}=await setup('timeout');await range(page);await page.locator('#lph-calculate').click();await page.waitForFunction(()=>!document.getElementById('lph-view').disabled);assert.equal(requests.filter(r=>r.operation==='calculate').length,1);assert.match(await page.locator('#lph-error').innerText(),/not confirmed/);assert(await page.evaluate(()=>sessionStorage.getItem('lavkaProfitSaveAttempt')));await page.close();});
test('revision mismatch and failed revision cannot masquerade as a saved report',async()=>{for(const mode of ['wrongRevision','failedRevision']){const{page}=await setup(mode);await page.locator('#lph-months button').first().click();await page.waitForFunction(()=>!document.getElementById('lph-view').disabled);assert.equal(await page.locator('#lavr-profit-result').isHidden(),true);assert.equal(await page.locator('#lph-error').isVisible(),true);await page.close();}});

test('Java saved DTO opens full stored report and exports without live audit',async()=>{const{page,requests,errors}=await setup('java');await range(page);await page.locator('#lph-months button').first().click();await page.waitForFunction(()=>!document.getElementById('lph-view').disabled);assert.equal(await page.locator('#lph-error').isHidden(),true);assert.equal(await page.locator('#lavr-profit-result').isVisible(),true);const d=page.waitForEvent('download');await page.locator('#lavr-profit-export-xlsx').click();await(await d).saveAs(path.join(out,'java-month.xlsx'));assert(requests.every(r=>['range','month'].includes(r.operation)));assert.deepEqual(errors,[]);await page.close();});

test('late saved detail cannot overwrite an edited month',async()=>{const{page,errors}=await setup('slowOpen');await range(page);await page.locator('#lph-months button').first().click();await page.locator('#lavr-profit-month').fill('2025-09');await page.waitForFunction(()=>!document.getElementById('lph-view').disabled);assert.equal(await page.locator('#lavr-profit-month').inputValue(),'2025-09');assert.equal(await page.locator('#lavr-profit-result').isHidden(),true);assert.deepEqual(errors,[]);await page.close();});
