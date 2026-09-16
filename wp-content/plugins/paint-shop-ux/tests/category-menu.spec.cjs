const {chromium} = require('playwright');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

(async () => {
    const base = process.env.PSU_TEST_URL || 'http://paint.local';
    assert.equal(new URL(base).hostname, 'paint.local', 'Local site only');
    const out = process.env.PSU_TEST_OUTPUT || '/tmp/psu-category-menu';
    fs.mkdirSync(out, {recursive: true});
    const browser = await chromium.launch({executablePath: process.env.CHROME_BINARY || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome', headless: true});
    const results = [];
    const allow = route => {
        const url = new URL(route.request().url());
        const image = route.request().resourceType() === 'image' && url.hostname === 'kreul-media.s3.gra.io.cloud.ovh.net';
        return url.hostname === 'paint.local' || image ? route.continue() : route.abort();
    };
    try {
        for (const width of [1440, 390]) {
            const context = await browser.newContext({viewport: {width, height: 1000}});
            await context.route('**/*', allow);
            const page = await context.newPage();
            const errors = []; page.on('pageerror', e => errors.push(e.message));
            await page.goto(base, {waitUntil: 'domcontentloaded'});
            const menu = page.locator('.psu-category-menu');
            await menu.waitFor();
            assert(await menu.locator('a').count() <= 60);
            assert.equal(await page.locator('.wpb_category_n_menu_accordion_list').count(), 0, 'Old tree never rendered');
            assert.equal(await page.locator('script[src*="accordion-init"], script[src*="jquery.navgoco"], script[src*="jquery.cookie.js"]').count(), 0, 'Unused legacy scripts removed');
            let requests = 0;
            await page.route('**/paint-shop-ux/v1/categories?**', async route => {
                requests++;
                await new Promise(resolve => setTimeout(resolve, 180));
                await route.continue();
            });
            const toggle = menu.locator('.psu-category-menu__toggle').first();
            const listId = await toggle.getAttribute('aria-controls');
            await toggle.evaluate(button => {button.click(); button.click(); button.click();});
            await page.waitForFunction(id => !document.getElementById(id).hasAttribute('data-unloaded'), listId);
            assert.equal(requests, 1, 'Rapid repeated clicks share one request');
            await toggle.click(); await toggle.click();
            assert.equal(requests, 1, 'Loaded branch reused');
            assert.equal(await toggle.getAttribute('aria-expanded'), 'true');
            const loaded = page.locator(`[id="${listId}"]`);
            assert(await loaded.locator(':scope > li[data-category]').count() > 0);
            const initial = await menu.locator(':scope > ul > li[data-category]').count();
            const more = menu.locator(':scope > ul > .psu-category-menu__more button');
            if (await more.count()) {
                await more.click();
                await page.waitForFunction(count => document.querySelectorAll('.psu-category-menu > ul > li[data-category]').length > count, initial);
                const ids = await menu.locator(':scope > ul > li[data-category]').evaluateAll(nodes => nodes.map(n => n.dataset.category));
                assert.equal(ids.length, new Set(ids).size);
            }
            // Failure remains retryable; navigation links are still usable.
            const unopened = menu.locator('.psu-category-menu__toggle[aria-expanded="false"]').first();
            const failId = await unopened.getAttribute('aria-controls');
            await page.unroute('**/paint-shop-ux/v1/categories?**');
            await page.route('**/paint-shop-ux/v1/categories?**', route => route.fulfill({status:503,contentType:'application/json',body:'{}'}));
            await unopened.focus(); await page.keyboard.press('Enter');
            const failed = page.locator(`[id="${failId}"]`);
            await failed.locator('.psu-category-menu__error button').waitFor();
            await page.unroute('**/paint-shop-ux/v1/categories?**');
            await failed.getByRole('button', {name:'Повторити',exact:true}).click();
            await page.waitForFunction(id => !document.getElementById(id).hasAttribute('data-unloaded'), failId);
            assert.equal(await failed.locator('.psu-category-menu__error').count(), 0);

            await page.goto(base + '/category/farbi-akrilovi/', {waitUntil:'domcontentloaded'});
            const current = menu.locator('[aria-current="page"]');
            await current.waitFor();
            assert.equal(await current.count(), 1);
            await current.scrollIntoViewIfNeeded();
            assert.equal(await current.isVisible(), true);
            const overflow = await menu.evaluate(nav => [...nav.querySelectorAll('a,button')].filter(el => el.getClientRects().length && el.scrollWidth > el.clientWidth + 2).map(el => el.textContent));
            assert.deepEqual(overflow, [], 'Menu labels fit');
            await page.screenshot({path:path.join(out,`catalog-${width}.png`)});
            const deep = '/category/osnovi-dla-dekoru-2/derevina-ta-mdf/rami/bez-skla/500h700mm/rami-derevo-91/bez-vstavki-10/';
            await page.goto(base+deep, {waitUntil:'domcontentloaded'});
            const deepCurrent = menu.locator('[aria-current="page"]');
            await deepCurrent.waitFor(); await deepCurrent.scrollIntoViewIfNeeded();
            assert(await menu.locator('.psu-category-menu__toggle[aria-expanded="true"]').count() >= 6);
            assert(await menu.locator('a').count() <= 70);
            assert(await menu.evaluate(nav => new TextEncoder().encode(nav.outerHTML).length) <= 50000);
            const deepOverflow = await menu.evaluate(nav => [...nav.querySelectorAll('a,button')].filter(el => el.getClientRects().length && el.scrollWidth > el.clientWidth + 2).map(el => el.textContent));
            assert.deepEqual(deepOverflow, [], 'Deep menu labels fit');
            await page.screenshot({path:path.join(out,`deep-${width}.png`)});
            if (width === 1440) {
                const widget = await menu.getAttribute('data-widget');
                const url = base+'/wp-json/paint-shop-ux/v1/categories?widget='+encodeURIComponent(widget)+'&parent=0&locale=uk';
                const firstPage = await (await context.request.get(url)).json();
                const nextPage = await (await context.request.get(url+'&offset=30')).json();
                if (nextPage.items.length > 1) {
                    await page.goto(nextPage.items.at(-1).url,{waitUntil:'domcontentloaded'});
                    assert.equal(await menu.locator(':scope > ul > li[data-pinned]').count(),1);
                    await menu.locator(':scope > ul > .psu-category-menu__more button').click();
                    await page.waitForFunction(() => !document.querySelector('.psu-category-menu > ul > li[data-pinned]'));
                    const actual = await menu.locator(':scope > ul > li[data-category]').evaluateAll(nodes=>nodes.map(n=>Number(n.dataset.category)));
                    assert.deepEqual(actual, [...firstPage.items,...nextPage.items].map(n=>n.id), 'Pinned active branch returns to sorted position');
                }
            }
            results.push({width,links:await menu.locator('a').count(),errors});
            await context.close();
        }
        const context = await browser.newContext({javaScriptEnabled:false,viewport:{width:390,height:844}});
        await context.route('**/*', allow);
        const page = await context.newPage();
        await page.goto(base, {waitUntil:'domcontentloaded'});
        const link = page.locator('.psu-category-menu a').filter({hasText:'Фарби акрилові'});
        assert.equal(await page.locator('.psu-category-menu button:visible').count(), 0, 'No dead controls without JS');
        await link.click();
        assert(page.url().includes('/category/farbi-akrilovi/'), 'No-JS link opens parent category');
        assert(await page.locator('.product-category').count() > 0, 'Category pages provide child navigation');
        await page.goto(base+'/my-account/', {waitUntil:'domcontentloaded'});
        assert.equal(await page.locator('.psu-category-menu').count(), 0);
        assert.equal(await page.locator('.psu-category-menu__catalog').count(), 1);
        assert.equal(await page.locator('script[src*="category-menu.js"]').count(), 0);
        for (const route of ['/cart_lh_klassick/', '/checkout/']) {
            await page.goto(base+route, {waitUntil:'domcontentloaded'});
            assert.equal(await page.locator('.psu-category-menu').count(), 0);
            assert.equal(await page.locator('.psu-category-menu__catalog').count(), 1);
        }
        await context.close();
        if (fs.existsSync('/tmp/psu-category-menu-ru.html')) {
            const ruContext = await browser.newContext({viewport:{width:390,height:1000}});
            await ruContext.route('**/*', allow);
            const ru = await ruContext.newPage(); await ru.goto(base+'/my-account/',{waitUntil:'domcontentloaded'});
            // Use a real local document origin; synthetic route documents trigger Chrome's loopback CORS guard.
            await ru.evaluate(html => {
                const doc = new DOMParser().parseFromString(html, 'text/html');
                document.querySelector('.widget_wpb_wmca_accordion_widget').replaceWith(doc.querySelector('.widget_wpb_wmca_accordion_widget'));
            }, fs.readFileSync('/tmp/psu-category-menu-ru.html','utf8'));
            await ru.addScriptTag({url:base+'/wp-content/plugins/paint-shop-ux/assets/category-menu.js'});
            const toggle = ru.locator('.psu-category-menu__toggle').first();
            assert((await toggle.getAttribute('aria-label')).startsWith('Развернуть'));
            const branchId = await toggle.getAttribute('aria-controls');
            await toggle.click();
            await ru.waitForFunction(id => !document.getElementById(id).hasAttribute('data-unloaded'),branchId);
            await toggle.scrollIntoViewIfNeeded();
            await ru.screenshot({path:path.join(out,'ru-390.png')});
            const widget = await ru.locator('.psu-category-menu').getAttribute('data-widget');
            const endpoint = '/wp-json/paint-shop-ux/v1/categories?widget='+encodeURIComponent(widget);
            for (const [params,status] of [['&parent=-1',400],['&parent=999999999',404],['&offset=1.5',400],['&locale=invalid',400],['&offset=100000',200]]) {
                const res = await ruContext.request.get(base+endpoint+params); assert.equal(res.status(),status,params);
                if (params==='&offset=100000') assert.deepEqual((await res.json()).items,[]);
            }
            await ruContext.close();
        }
        console.log(JSON.stringify({passed:true,results},null,2));
    } finally { await browser.close(); }
})().catch(error => {console.error(error);process.exit(1);});
