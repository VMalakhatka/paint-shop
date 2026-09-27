const {chromium} = require('playwright');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const target = /\/wp-social\/assets\/(css\/(frontend|font-icon)\.css|js\/(front-main|social-front)\.js)|\/assets\/pcoe\.js/;
(async () => {
    const browser = await chromium.launch({headless:true, executablePath:process.env.CHROME_BINARY || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome'});
    try {
        for (const wholesale of [false, true]) {
            const context = await browser.newContext();
            await context.route('**/*', r => ['paint.local','kreul-media.s3.gra.io.cloud.ovh.net'].includes(new URL(r.request().url()).hostname) ? r.continue() : r.abort());
            if (wholesale) await context.addCookies(JSON.parse(fs.readFileSync('/tmp/psu-search-session.json','utf8')).cookies);
            const page = await context.newPage();
            let requests = [], errors = [];
            page.on('request', r => { if (target.test(r.url())) requests.push(r.url()); });
            page.on('pageerror', e => errors.push(e.message));
            for (const width of [1280,390]) {
                await page.setViewportSize({width,height:900});
                for (const path of ['/', '/category/laki/']) {
                    requests = []; errors = [];
                    await page.goto('http://paint.local'+path, {waitUntil:'networkidle'});
                    assert.equal(requests.length,0,`${path} no five extra asset requests`);
                    assert.equal(await page.locator('#pcoe-inline-inline-css, #pcoe-js-extra').count(),0);
                    assert.equal(await page.locator('#psu-catalog-search').count(),1,'Catalog remains usable');
                    assert.deepEqual(errors,[],'No page errors');
                    assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth+1),'No horizontal overflow');
                    if (!wholesale && path !== '/') await page.screenshot({path:`/tmp/conditional-assets-${width}.png`});
                }
                const cart = new URL(await page.locator('a').filter({hasText:/Кошик\(класик\)/}).first().getAttribute('href'),page.url()).href;
                requests = []; errors = [];
                await page.goto(cart,{waitUntil:'networkidle'});
                assert.equal(requests.filter(u => /pcoe\.js/.test(u)).length,1,'Cart loads import once');
                assert.equal(await page.locator('#pcoe-import-form').count(),1,'Empty cart has import form');
                assert(await page.evaluate(() => !!window.pcoeVars?.i18n?.importing),'Localized script data exists');
                assert.deepEqual(errors,[],'Cart has no JS errors');
                // Exercise event binding without creating orders or touching Folio.
                let seenImport = false;
                await page.route('**/wp-admin/admin-ajax.php', async r => {
                    if ((r.request().postData() || '').includes('pcoe_import_')) {
                        seenImport = true;
                        await r.fulfill({json:{success:false,data:{message:'Asset test response'}}});
                    } else await r.continue();
                });
                await page.locator('#pcoe-import-form input[type=file]').setInputFiles({name:'asset-test.csv',mimeType:'text/csv',buffer:Buffer.from('sku,qty\nTEST,1')});
                await page.locator('#pcoe-import-form').evaluate(f => window.jQuery(f).trigger('submit'));
                await page.waitForFunction(() => !window.jQuery('#pcoe-import-form').data('pending'));
                assert(seenImport,'Cart AJAX handler is bound');
                await page.unroute('**/wp-admin/admin-ajax.php');
                requests=[]; errors=[];
                await page.goto('http://paint.local/my-account/'+(wholesale?'orders/':''),{waitUntil:'networkidle'});
                if (wholesale) {
                    assert.equal(requests.filter(u => /pcoe\.js/.test(u)).length,1);
                    assert.equal(await page.locator('#pcoe-import-draft-form').count(),1);
                    let draftRequest = false;
                    await page.route('**/wp-admin/admin-ajax.php', async r => {
                        if ((r.request().postData() || '').includes('pcoe_import_order_draft')) {
                            draftRequest=true;
                            await r.fulfill({json:{success:false,data:{message:'Asset test response'}}});
                        } else await r.continue();
                    });
                    await page.locator('#pcoe-import-draft-form input[type=file]').setInputFiles({name:'asset-test.csv',mimeType:'text/csv',buffer:Buffer.from('sku,qty\nTEST,1')});
                    await page.locator('#pcoe-import-draft-form').evaluate(f => window.jQuery(f).trigger('submit'));
                    await page.waitForFunction(() => !window.jQuery('#pcoe-import-draft-form').data('pending'));
                    assert(draftRequest,'Draft import AJAX handler is bound');
                    await page.unroute('**/wp-admin/admin-ajax.php');
                } else {
                    assert.equal(requests.filter(u => /wp-social/.test(u)).length,4,'Guest account retains social assets');
                    assert.equal(requests.filter(u => /pcoe\.js/.test(u)).length,0,'Guest account needs no import');
                    assert(await page.locator('.xs_social_share_widget, #xs-social-login-container').count(),'Social markup exists');
                    assert(await page.evaluate(() => typeof window.xs_social_sharer === 'function'));
                }
                assert.deepEqual(errors,[],'Account has no JS errors');
                console.log(`PASS ${wholesale?'wholesale':'guest'} ${width}px home/category/cart/account assets and handlers`);
            }
            await context.close();
        }
    } finally { await browser.close(); }
})().catch(e => {console.error(e);process.exitCode=1;});
