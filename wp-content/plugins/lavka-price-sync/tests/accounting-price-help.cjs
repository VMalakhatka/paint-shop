const {chromium}=require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const fs=require('fs'), path=require('path'), assert=require('assert');
const {execFileSync}=require('child_process');
const root=path.join(__dirname,'..');
const css=fs.readFileSync(path.join(root,'assets/accounting-price-help.css'),'utf8');
const js=fs.readFileSync(path.join(root,'assets/accounting-price-help.js'),'utf8');
const guide=execFileSync('php',[path.join(__dirname,'accounting-price-help.php'),'--render'],{encoding:'utf8'});
(async()=>{
 const browser=await chromium.launch({headless:true,channel:'chrome'});
 try {
  const page=await browser.newPage(); const errors=[]; page.on('pageerror',e=>errors.push(e.message));
  await page.route('**/*',r=>r.abort());
  for(const width of [390,1100]){
   await page.setViewportSize({width,height:900});
   await page.setContent(`<meta charset="utf-8"><style>body{font:14px sans-serif;background:#f0f0f1}button{padding:10px}.nav-tab{float:left} ${css}</style><div class="lps-ap" id="lps-accounting-prices"><h1>Облікові ціни ФОЛІО</h1><nav class="nav-tab-wrapper"><button data-lps-ap-tab="single">Один товар</button><button data-lps-ap-tab="full">Весь склад</button><button data-lps-ap-tab="campaign">Кампанія SKU та розклад</button></nav><input id="lps-ap-sku" value="EXAMPLE"><button id="lps-ap-single-preview">Перевірити без змін</button><h3 id="lps-ap-warehouse-overview-heading">Стан обробки всіх складів ФОЛІО</h3><div id="dynamic"></div></div>`);
   await page.evaluate(()=>{window.lpsAccountingHelp={url:'https://example.invalid/wp-admin/admin.php?page=lps-accounting-prices&view=help#',label:'? Як це працює',title:'Довідка'};});
   await page.addScriptTag({content:js});
   assert.equal(await page.locator('.lps-ap-help-tab').count(),3);
   assert((await page.locator('#lps-ap-single-preview + a').getAttribute('href')).endsWith('#single'));
   for(let i=0;i<2;i++){
    await page.locator('#dynamic').evaluate(n=>{n.innerHTML='<section class="lps-ap-persistent-diagnostics lps-ap-state-section"><h3>Постійна діагностика</h3><a href="/admin-post.php?action=lps_accounting_price_diagnostic_export_xlsx">Експорт діагностики XLSX</a></section>';});
    await page.waitForFunction(()=>document.querySelectorAll('#dynamic .lps-ap-help-link').length===2);
    assert((await page.locator('#dynamic h3 + a').getAttribute('href')).endsWith('#excel'));
   }
   assert.equal(await page.locator('#lps-ap-sku').inputValue(),'EXAMPLE');
   await page.locator('#dynamic').evaluate(n=>{n.innerHTML='<details class="lps-ap-state-section lps-ap-collapsible-report"><summary>Пропущені товари та діагностика (2)</summary><p>Rows</p></details><details class="lps-ap-snapshot-report lps-ap-collapsible-report"><summary>Звіт станів знімка</summary><p>Products</p></details>';});
   await page.waitForFunction(()=>document.querySelectorAll('#dynamic summary .lps-ap-help-link').length===2);
   assert.equal(await page.locator('#dynamic details[open]').count(),0,'Help does not open collapsed tables');
   assert((await page.locator('#dynamic summary a').first().getAttribute('href')).endsWith('#negative'));
   assert((await page.locator('#dynamic summary a').last().getAttribute('href')).endsWith('#excel'));
   assert(await page.locator('#dynamic summary a').first().isVisible(),'Help is visible while table is collapsed');
   await page.screenshot({path:`/tmp/accounting-help-context-${width}.png`,fullPage:true});
   await page.setContent(`<meta charset="utf-8"><style>body{background:#f0f0f1;font-family:sans-serif}${css}</style>${guide}`);
   assert.equal(await page.locator('section').count(),10);
   assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'No overflow');
   await page.locator('#negative').scrollIntoViewIfNeeded();
   await page.screenshot({path:`/tmp/accounting-help-guide-${width}.png`});
  }
  assert.deepEqual(errors,[]); console.log('PASS: desktop/mobile guide, context anchors, AJAX replacement, no duplicate links or changed fields');
 } finally {await browser.close();}
})().catch(e=>{console.error(e);process.exit(1);});
