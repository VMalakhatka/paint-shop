/* Synthetic browser assertions: Google requests blocked; no real orders. */
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const {chromium} = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const base = path.resolve(__dirname, '..');
(async () => {
    const browser = await chromium.launch({headless:true, channel:'chrome'});
    let tests = 0;
    try {
        for (const width of [1440, 390]) {
            const context = await browser.newContext({viewport:{width,height:800}});
            const page = await context.newPage();
            let google = 0, flushes = 0;
            await page.route('**/*', async route => {
                const url = route.request().url();
                if (url.includes('googletagmanager.com')) { google++; return route.fulfill({body:''}); }
                if (url.includes('admin-ajax')) {
                    flushes++;
                    return route.fulfill({contentType:'application/json', body:JSON.stringify({success:true,data:{events:[
                        {id:'one',name:'add_to_cart',params:{currency:'UAH',value:20,items:[{item_id:'SKU',price:10,quantity:2}]}}
                    ]}})});
                }
                return route.fulfill({contentType:'text/html',body:'<html><head></head><body><form class="checkout"></form><li class="product"><a class="woocommerce-LoopProduct-link" href="/product/a/">Item</a><span hidden data-lca-item=\'{"item_id":"SKU","price":10,"quantity":1}\'></span></li></body></html>'});
            });
            await page.goto('https://store.invalid/shop/?s=person@example.com&key=secret&utm_source=google&utm_medium=organic');
            await page.addStyleTag({content:fs.readFileSync(path.join(base,'assets/consent.css'),'utf8')});
            await page.evaluate(() => {
                window.dataLayer = [];
                window.lavkaAnalyticsConfig = {id:'G-TEST1234',segment:'retail',url:'/admin-ajax.php',nonce:'test',page:'https://store.invalid/shop/',
                    events:[{name:'purchase',params:{currency:'UAH',value:20,transaction_id:'woo-1',items:[{item_id:'SKU',price:10,quantity:2}]}}],
                    cart:{currency:'UAH',value:20,items:[]}, currency:'UAH', labels:{settings:'Preferences',message:'Allow analytics?',allow:'Allow',deny:'Refuse',privacy:'Privacy'}};
            });
            await page.addScriptTag({content:fs.readFileSync(path.join(base,'assets/storefront.js'),'utf8')});
            assert.equal(google,0,'No Google before consent'); tests++;
            assert.equal(flushes,0,'No behavioural fetch before consent'); tests++;
            assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth),true,'No mobile overflow'); tests++;
            await page.getByRole('button',{name:'Refuse',exact:true}).click();
            assert.equal(google,0,'No Google after refusal'); tests++;
            await page.getByRole('button',{name:'Preferences',exact:true}).click();
            await page.getByRole('button',{name:'Allow',exact:true}).click();
            await page.waitForFunction(() => window.dataLayer.some(a => a[0]==='event' && a[1]==='add_to_cart'));
            assert.equal(google,1,'Single Google script'); tests++;
            const events = await page.evaluate(() => window.dataLayer.filter(a => a[0]==='event').map(a => [a[1],a[2]]));
            assert.equal(events.filter(e=>e[0]==='purchase').length,1); tests++;
            assert.equal(events.filter(e=>e[0]==='add_to_cart').length,1); tests++;
            assert(!JSON.stringify(events).includes('person@example.com') && !JSON.stringify(events).includes('secret'),'No sensitive URL/query'); tests++;
            assert.equal(await page.locator('input[name="lca_attribution"]').count(),1); tests++;
            await page.evaluate(() => window.lavkaAnalyticsConsent('denied'));
            await page.evaluate(() => window.lavkaAnalyticsConsent('granted'));
            const names = await page.evaluate(() => window.dataLayer.filter(a=>a[0]==='event').map(a=>a[1]));
            assert.equal(names.filter(n=>n==='purchase').length,1,'No duplicate receipt on regrant'); tests++;
            assert.equal(google,1,'No duplicate library on regrant'); tests++;
            await page.screenshot({path:`/tmp/lca-consent-${width}.png`});
            await context.close();
        }
        console.log(`PASS: ${tests} browser assertions (desktop + mobile)`);
    } finally { await browser.close(); }
})().catch(error => {console.error(error);process.exitCode=1;});
