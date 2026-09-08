// Run with Playwright available in NODE_PATH. Uses only synthetic local responses.
const { test } = require('node:test');
const assert = require('node:assert/strict');
const { chromium } = require('playwright');
const { execFileSync } = require('node:child_process');
const fs = require('node:fs');
const path = require('node:path');
const os = require('node:os');
const base = path.resolve(__dirname, '..');
const output = process.env.PROFIT_TEST_OUTPUT || fs.mkdtempSync(path.join(os.tmpdir(), 'profit-report-'));
fs.mkdirSync(output, { recursive: true });
const html = execFileSync(process.env.PHP_BIN || 'php', [path.join(__dirname, 'profit-page.php')], { encoding: 'utf8' });
const row = (city, id, amount, source = 'FOLIO') => ({city, lineId:id, sortOrder:1, category:'SALARY', label:id, documentCount:source === 'FOLIO' ? 1 : 0,
 amount, profitImpact:amount, accountingTreatment:'OPERATING_EXPENSE', source,
 filters:{expenseCodes:['З/ПЛАТА'],operationTypes:['РАСХОДЫ КИЕВА'],operationRequired:false,purposeCodes:[],cashWarehouseMode:'ALL',cashWarehouseIds:[],bankWarehouseMode:'EXCLUDE',bankWarehouseIds:[2],note:'Synthetic selection'}});
function fixture(month, legacy = false) {
 const city = city => ({city,baseGrossProfit:'100.00',manualGrossAdjustments:'0.00',grossProfit:'100.00',operatingExpenses:'20.00',profit:'80.00'});
 const data = {ok:true,month,calculatedAt:1788880000,ruleVersion:'synthetic-v2',complete:false,
 inputs:{odesaAdditionalSalary:'5000',odesaAdditionalSalarySource:'DEFAULT',kyivAdditionalSalary:'0',kyivAdditionalSalarySource:'DEFAULT',odesaTaxShare:'0.428571',rubToUahRate:'0.41'},
 cities:[city('KYIV'),city('ODESA')],expenses:[row('KYIV','LEGACY','20.00')],expenseLines:[row('KYIV','KYIV_SALARY','20.00'),row('KYIV','KYIV_MANUAL','0.00','MANUAL'),row('ODESA','ODESA_MANUAL','5000.00','MANUAL')],
 periodPolicy:{candidateFrom:'2026-06-01',candidateToExclusive:'2026-09-01',explicitPeriodPriority:true,description:'M−1, M, M+1'},
 inventory:[{city:'KYIV',openingAccountingValue:'200',closingAccountingValue:'210',accountingValueChange:'10',warehouses:[{warehouseId:1,warehouseName:'Synthetic',openingAccountingValue:'200',closingAccountingValue:'210',accountingValueChange:'10'}]}],
 controls:{selectedDocumentCount:2,selectedDocumentAmount:'20.00',operatingExpenseTotal:'20.00',capitalizedCostTotal:'0',excludedDocumentAmount:'0',unclassifiedDocumentAmount:'0',unclassifiedDocumentCount:0,auditTruncated:true,periodProblemCount:1},
 warnings:[{code:'MIXED_EXPLICIT_PERIOD',message:'Synthetic review',details:{count:1}}],
 masterClass:{sku:'Мастер-Класс июль',warehouseId:5,articleFound:true,income:'10',returns:'1',netContribution:'9',grossProfitAlreadyInBase:'0',grossAdjustmentApplied:'9',auditTruncated:true},
 documents:[{paymentId:'9007199254740993',documentNumber:'=HYPERLINK("invalid")',documentDate:month+'-03',expenseCode:'З/ПЛАТА',stream:'BANK',warehouseId:1,sourceAmount:'20.00',sourceCurrency:'UAH',reportAmount:'20.00',city:'KYIV',category:'SALARY',accountingTreatment:'OPERATING_EXPENSE',includedInProfit:true,profitImpact:'20.00',resolvedMonth:month,periodSource:'NOTE',periodNote:month.replace('-',' '),periodStatus:'VALID',expenseLineId:'KYIV_SALARY',reason:'Synthetic'}],
 masterClassDocuments:[{documentDate:month+'-01',documentNumber:'01',documentId:'fixture',lineNumber:1,movementId:'123',sku:'Мастер-Класс июль',warehouseId:5,amount:'10.00',currency:'UAH',unitPrice:'1.00',quantity:'10',classification:'INCOME'}],
 periodDiagnostics:[{document:{paymentId:'9',documentNumber:'009',periodNote:'2026 07-08',profitImpact:'5',sourceAmount:'5',reportAmount:'5',city:'KYIV'},status:'MIXED_EXPLICIT_PERIOD',reason:'Synthetic mixed period',includedInTotals:true,amountTreatment:'LEGACY_PROVISIONAL_INCLUDED'}],periodDiagnosticsTruncated:true};
 if (legacy) {delete data.expenseLines;delete data.periodPolicy;delete data.inputs.kyivAdditionalSalary;delete data.inputs.kyivAdditionalSalarySource;delete data.periodDiagnostics;}
 return data;
}
let browser;
test.before(async () => {browser=await chromium.launch({headless:true, ...(process.env.CHROME_BIN ? {executablePath:process.env.CHROME_BIN} : {})});});
test.after(async () => {if(browser)await browser.close();});
async function setup(options={}) {
 const page=await browser.newPage({viewport:{width:1440,height:1000}});const errors=[];page.on('pageerror',e=>errors.push(e.message));
 const requests=[];let held=null;
 await page.route('https://profit.test/**',async route=>{
  if(route.request().url().endsWith('/ajax')){
   const post=route.request().postData()||'';
   // Multipart FormData: extract only test parameters.
   const field=name=>{const m=post.match(new RegExp('name="'+name+'"\\r\\n\\r\\n([^\\r]*)'));return m&&m[1];};
   const month=field('month'),operation=field('operation');requests.push({month,operation,kyiv:field('kyivAdditionalSalary'),odesa:field('odesaAdditionalSalary')});
   const data=options.backend ? {...JSON.parse(fs.readFileSync(path.join(__dirname,'fixtures/profit-report.json'),'utf8')), month} : fixture(month,options.legacy);
   if(operation==='audit') data.cities[0].profit='81.00';
   const reply=()=>route.fulfill({status:200,contentType:'application/json',body:JSON.stringify({success:true,data:{httpStatus:200,bodyRaw:JSON.stringify(data)}})});
   if(options.hold && requests.length>1){held=reply;return;}await reply();
  }else await route.fulfill({status:200,contentType:'text/html',body:html});
 });
 await page.goto('https://profit.test/');
 await page.addStyleTag({content:'body{font-family:Arial;margin:20px}*{box-sizing:border-box} .widefat{border-collapse:collapse;width:100%}th,td{padding:8px;border-bottom:1px solid #ddd;text-align:left}button{padding:8px}'});
 await page.addStyleTag({path:path.join(base,'profit-report.css')});
 await page.addScriptTag({path:path.join(base,'profit-xlsx.js')});
 await page.addScriptTag({path:path.join(base,'profit-report.js')});
 await page.waitForFunction(()=>!document.getElementById('lavr-profit-result').hidden);
 return {page,errors,requests,release:async()=>{assert(held);await held();}};
}
test('new API: detailed selections, zero manual row, full XLSX, coherent audit snapshot and parameter invalidation',async()=>{
 const {page,errors,requests}=await setup();
 assert.equal(await page.locator('#lavr-profit-expenses-table tbody tr').count(),3);
 assert.match(await page.locator('#lavr-profit-expenses-table').innerText(),/All warehouses/);
 assert.doesNotMatch(await page.locator('#lavr-profit-calculated-at').innerText(),/1970/);
 assert.equal(await page.locator('#lavr-profit-kyiv-salary').inputValue(),'0');
 assert.equal(await page.locator('#lavr-profit-period-diagnostics tbody tr').count(),1);
 await page.locator('#lavr-profit-expense-filter button[data-value="KYIV"]').click();
 assert.equal(await page.locator('#lavr-profit-expenses-table tbody tr').count(),2);
 const downloadPromise=page.waitForEvent('download');
 await page.locator('#lavr-profit-export-xlsx').click();const download=await downloadPromise;
 await download.saveAs(path.join(output,'profit.xlsx'));
 assert.equal(requests.length,2);assert.equal(requests[1].operation,'audit');
 assert.match(await page.locator('#lavr-profit-cities').innerText(),/81,00/);
 assert.equal(await page.locator('#lavr-profit-audit-table tbody tr').count(),1);
 assert.match(await page.locator('#lavr-profit-audit-note').innerText(),/truncat|500/i);
 assert.match(await page.locator('#lavr-profit-controls-content').textContent(),/Problematic periods/);
 await page.locator('#lavr-profit-manual').evaluate(el=>el.open=true);
 await page.locator('#lavr-profit-kyiv-salary').fill('0');
 assert.equal(await page.locator('#lavr-profit-export-xlsx').isDisabled(),true);
 await page.locator('#lavr-profit-calculate').click();await page.waitForFunction(()=>!document.getElementById('lavr-profit-export-xlsx').disabled);
 assert.equal(requests.at(-1).kyiv,'0');
 await page.screenshot({path:path.join(output,'desktop.png'),fullPage:true});
 await page.setViewportSize({width:390,height:844});
 await page.screenshot({path:path.join(output,'mobile.png'),fullPage:true});
 assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=window.innerWidth),true);
 assert.deepEqual(errors,[]);await page.close();
});
test('legacy API explicitly shows missing detail and disables unsupported Kyiv override',async()=>{
 const {page,errors}=await setup({legacy:true});
 assert.match(await page.locator('#lavr-profit-expense-note').innerText(),/updated Java API/);
 assert.equal(await page.locator('#lavr-profit-kyiv-salary').isDisabled(),true);
 assert.match(await page.locator('#lavr-profit-policy').textContent(),/did not supply/);
 assert.deepEqual(errors,[]);await page.close();
});
test('late response after month edit cannot replace the displayed snapshot or enable export',async()=>{
 const {page,errors,release,requests}=await setup({hold:true});
 const initial=await page.locator('#lavr-profit-result-period').innerText();
 await page.locator('#lavr-profit-calculate').click();
 await page.waitForTimeout(60);
 await page.locator('#lavr-profit-month').fill('2026-07');
 await release();await page.waitForTimeout(100);
 assert.equal(await page.locator('#lavr-profit-result-period').innerText(),initial);
 assert.equal(await page.locator('#lavr-profit-export-xlsx').isDisabled(),true);
 assert.equal(await page.locator('#lavr-profit-load-audit').isDisabled(),true);
 assert.equal(requests.length,2);assert.deepEqual(errors,[]);await page.close();
});
console.log('Test artifacts:',output);

test('real Java DTO fixture: 32 stable rows, separate diagnostics, numeric strings and XLSX export',async()=>{
 const {page,errors}=await setup({backend:true});
 assert.equal(await page.locator('#lavr-profit-expenses-table tbody tr').count(),32);
 assert.equal(await page.locator('#lavr-profit-period-diagnostics tbody tr').count(),2);
 const downloadPromise=page.waitForEvent('download');await page.locator('#lavr-profit-export-xlsx').click();
 const download=await downloadPromise;await download.saveAs(path.join(output,'java-contract.xlsx'));
 await page.screenshot({path:path.join(output,'java-contract.png'),fullPage:true});
 assert.match(await page.locator('#lavr-profit-audit-table').innerText(),/SHARED_TAX_MALAFOP/);
 assert.deepEqual(errors,[]);await page.close();
});
