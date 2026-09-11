const {chromium}=require('playwright');
const assert=require('assert');
(async()=>{
 const browser=await chromium.launch({headless:true,...(process.env.CHROME_BINARY?{executablePath:process.env.CHROME_BINARY}:{})});
 const page=await browser.newPage();let errors=[];page.on('pageerror',e=>errors.push(e.message));
 await page.goto('http://127.0.0.1:8879/');await page.locator('[name=supplier]').fill('Kreul');await page.locator('[name=database]').selectOption('Paint_Rus');
 await page.locator('[type=file]').setInputFiles(process.env.LPS_SP_TEST_XLSX);
 await Promise.all([page.waitForURL(/version=/),page.locator('button').filter({hasText:'Upload XLSX'}).click()]);
 await page.locator('[name="config[validFrom]"]').fill('2026-05-01');await page.locator('[name="config[full]"]').check();
 await Promise.all([page.waitForNavigation(),page.getByRole('button',{name:'Check import',exact:true}).click()]);
 assert(await page.getByText('Discontinued products',{exact:true}).count());
 await page.locator('[name=sku]').fill('17003');await Promise.all([page.waitForNavigation(),page.getByRole('button',{name:'Search',exact:true}).click()]);
 assert.equal(await page.locator('tbody tr').count(),2);assert.equal(await page.getByRole('cell',{name:'4.3485',exact:true}).count(),2);
 for(const width of [1440,390]){await page.setViewportSize({width,height:1100});assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth));await page.screenshot({path:`${process.env.UI_OUTPUT_DIR || require("os").tmpdir()}/supplier-import-${width}.png`,fullPage:true});}
 await page.locator('form').filter({has:page.locator('[name=operation][value=activate]')}).locator('[type=checkbox]').check();
 await Promise.all([page.waitForNavigation(),page.getByRole('button',{name:'Activate this version',exact:true}).click()]);
 assert.equal(await page.getByRole('button',{name:'Activate this version',exact:true}).count(),0);assert((await page.locator('h2').innerText()).includes('Active'));
 const download=page.waitForEvent('download');await page.getByRole('button',{name:'Download original',exact:true}).click();const file=await download;assert(file.suggestedFilename().endsWith('.xlsx'));
 const denied=await page.request.post('http://127.0.0.1:8879/',{headers:{'X-Test-Deny':'1'},form:{operation:'activate',_wpnonce:'test-only'}});assert.equal(denied.status(),403);
 const nonce=await page.request.post('http://127.0.0.1:8879/',{form:{operation:'activate'}});assert.equal(nonce.status(),403);
 assert.deepEqual(errors,[]);await browser.close();console.log('PASS: upload, mapping, variants, price, activation, download, mobile layout, capability and nonce');
})().catch(e=>{console.error(e);process.exit(1)});
