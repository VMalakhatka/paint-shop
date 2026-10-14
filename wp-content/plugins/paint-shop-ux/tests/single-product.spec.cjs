const {chromium} = require('playwright');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

(async () => {
    const base = process.env.PSU_PRODUCT_BASE || 'http://127.0.0.1:8088';
    assert(['127.0.0.1', 'paint.local'].includes(new URL(base).hostname), 'Local only');
    const fixture = JSON.parse(fs.readFileSync('/tmp/psu-product-browser-session.json', 'utf8'));
    const localURL = url => base + new URL(url).pathname + new URL(url).search + new URL(url).hash;
    const output = process.env.PSU_PRODUCT_OUTPUT || '/tmp/psu-single-product-acceptance';
    fs.mkdirSync(output, {recursive: true});
    const browser = await chromium.launch({executablePath: '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome', headless: true});
    const errors = [], results = [];
    let requestCount = 0, reply = null;
    const openContext = async (role, javaScriptEnabled = true) => {
        const context = await browser.newContext({viewport: {width: 1440, height: 1000}, javaScriptEnabled});
        await context.route('**/*', async route => {
            const request = route.request(), url = new URL(request.url());
            if (url.origin === new URL(base).origin && request.method() === 'POST' && url.searchParams.get('wc-ajax') === 'add_to_cart') {
                requestCount++;
                assert(reply, 'No actual cart writes allowed');
                return reply(route, new URLSearchParams(request.postData()));
            }
            const localRead = url.origin === new URL(base).origin && ['GET', 'HEAD'].includes(request.method())
                && !url.pathname.endsWith('/wp-cron.php') && !url.searchParams.has('add-to-cart');
            const image = request.resourceType() === 'image' && url.hostname === 'kreul-media.s3.gra.io.cloud.ovh.net';
            return localRead || image ? route.continue() : route.abort();
        });
        if (role) await context.addCookies(fixture.sessions[role].cookies.map(cookie => ({...cookie, domain: new URL(base).hostname})));
        return context;
    };
    const openProduct = async context => {
        const page = await context.newPage();
        page.on('pageerror', error => errors.push(error.message));
        assert.equal((await page.goto(localURL(fixture.url), {waitUntil: 'networkidle', timeout: 60000})).status(), 200);
        await page.locator('.psu-product__hero').waitFor();
        return page;
    };
    try {
        for (const role of [null, 'wholesale', ...Object.keys(fixture.sessions).filter(role => role !== 'wholesale')]) {
            const context = await openContext(role), page = await openProduct(context);
            assert.equal(await page.locator('.product_title').textContent(), fixture.title);
            const price = (await page.locator('.psu-product__purchase > .price').textContent()).trim();
            assert.equal(price, (role ? fixture.sessions[role].price : fixture.guest_price).trim(), 'Existing role price');
            assert((await page.locator('.product_meta').textContent()).includes(fixture.sku));
            assert.equal(await page.locator('.psu-product__purchase .slu-stock-box').count(), 1);
            assert.equal(await page.locator('.inside-right-sidebar, .inside-left-sidebar').count(), 0);
            assert.equal(await page.locator('script[src*="single-ajax-add-to-cart.js"]').count(), 0, 'Legacy handler absent');
            assert.equal(await page.locator('script[src*="single-product.js"]').count(), 1, 'One page handler');
            assert.equal(await page.locator('.pc-wholesale-help-link').count(), role === 'wholesale' ? 1 : 0);
            for (const width of [1440, 768, 390, 320]) {
                await page.setViewportSize({width, height: 1000});
                const geometry = await page.evaluate(() => {
                    const gallery = document.querySelector('.psu-product__gallery').getBoundingClientRect();
                    const purchase = document.querySelector('.psu-product__purchase').getBoundingClientRect();
                    return {overflow: document.documentElement.scrollWidth > innerWidth, gallery: {x: gallery.x, y: gallery.y, width: gallery.width, bottom: gallery.bottom},
                        purchase: {x: purchase.x, y: purchase.y, width: purchase.width}, font: getComputedStyle(document.querySelector('.product_title')).fontSize};
                });
                assert(!geometry.overflow, 'No overflow: ' + role + '/' + width);
                if (width > 768) {
                    assert(Math.abs(geometry.gallery.width - geometry.purchase.width) < 2, 'Full right column');
                    assert(Math.abs(geometry.gallery.y - geometry.purchase.y) < 2, 'Desktop columns aligned');
                } else assert(geometry.purchase.y >= geometry.gallery.bottom, 'Mobile stacked');
                assert(parseFloat(geometry.font) >= 24, 'Theme mobile title override neutralized');
            }
            await page.locator('#tab-title-additional_information a').click();
            await page.locator('#tab-additional_information').waitFor({state: 'visible'});
            await page.locator('#tab-title-description a').click();
            await page.locator('#tab-description').waitFor({state: 'visible'});
            const related = page.locator('.related li.product');
            if (await related.count()) {
                assert.equal(await related.first().locator('.woocommerce-loop-product__title').count(), 1);
                assert.equal(await related.first().locator('.psu-loop-sku').count(), 1);
                assert.equal(await related.first().locator('.loop-buy-row').count(), 1, 'Related purchase controls initialized');
            }
            if (role === 'wholesale') {
                await page.goto(localURL(fixture.help_url), {waitUntil: 'networkidle'});
                await page.locator('#katalog').waitFor();
                assert((await page.locator('#katalog').textContent()).includes('Сторінка товару на телефоні'));
                assert.equal(await page.locator('#katalog a[href*="product-page-mobile-2026-10-14.jpg"]').count(), 1);
            }
            results.push({role: role || 'guest', widths: [1440, 768, 390, 320], price: 'matched'});
            await context.close();
        }

        const context = await openContext(null), page = await openProduct(context);
        await page.locator('.woocommerce-product-gallery__trigger').click();
        await page.locator('.pswp.pswp--open').waitFor({state: 'visible'});
        await page.locator('.pswp__button--close').click();
        await page.locator('.pswp.pswp--open').waitFor({state: 'hidden'});
        const qty = page.locator('.psu-product__purchase form.cart input.qty'), button = page.locator('.psu-product__purchase .single_add_to_cart_button');
        const originalMax = Number(await qty.getAttribute('max'));
        assert(originalMax >= 2, 'Representative item has local stock');
        await qty.fill('2');
        let finish;
        reply = async (route, data) => {
            assert.equal(data.get('quantity'), '2');
            assert(Number(data.get('product_id')) > 0);
            await new Promise(resolve => { finish = resolve; });
            await route.fulfill({status: 200, contentType: 'application/json', body: JSON.stringify({fragments: {}, cart_hash: 'local-test'})});
        };
        await button.click();
        await page.waitForFunction(() => document.querySelector('.single_add_to_cart_button').getAttribute('aria-busy') === 'true');
        assert(await button.isDisabled());
        await page.evaluate(() => jQuery('.psu-product__purchase form.cart').trigger('submit'));
        await page.waitForFunction(() => true);
        assert.equal(requestCount, 1, 'Repeated submit does not create a second request');
        while (!finish) await page.waitForTimeout(20);
        finish();
        await page.locator('.psu-product-feedback').waitFor({state: 'visible'});
        assert.equal(Number(await qty.getAttribute('max')), originalMax - 2);
        assert((await page.locator('.psu-product-feedback').textContent()).includes('Товар додано в кошик'));

        const remaining = Number(await qty.getAttribute('max'));
        reply = route => route.fulfill({status: 200, contentType: 'application/json', body: JSON.stringify({error: true})});
        await button.click();
        await page.waitForFunction(() => !jQuery('.psu-product__purchase form.cart').data('psu-pending'));
        assert.equal(Number(await qty.getAttribute('max')), remaining, 'Rejected quantity leaves stock unchanged');
        assert(await page.locator('.psu-product-feedback').evaluate(node => node.classList.contains('is-error')));
        assert(await button.isEnabled());

        reply = route => route.fulfill({status: 503, body: 'local simulated interruption'});
        await button.click();
        await page.waitForFunction(() => !jQuery('.psu-product__purchase form.cart').data('psu-pending'));
        assert.equal(requestCount, 3, 'HTTP failure is not retried');
        assert.equal(Number(await qty.getAttribute('max')), remaining);
        assert((await page.locator('.psu-product-feedback').textContent()).includes('Перевірте кошик перед повторною спробою'));
        assert.equal(await page.locator('.psu-product-feedback a').count(), 1);

        reply = route => route.fulfill({status: 200, body: 'not JSON'});
        await button.click();
        await page.waitForFunction(() => !jQuery('.psu-product__purchase form.cart').data('psu-pending'));
        assert.equal(requestCount, 4, 'Malformed response is not retried');
        await qty.evaluate(node => {node.max = '2'; node.value = '2';});
        reply = route => route.fulfill({status: 200, contentType: 'application/json', body: JSON.stringify({fragments: {}, cart_hash: 'local-test-2'})});
        await button.click();
        await page.waitForFunction(() => !jQuery('.psu-product__purchase form.cart').data('psu-pending'));
        assert.equal(await qty.getAttribute('max'), '0');
        assert(await qty.isDisabled()); assert(await button.isDisabled(), 'Exhausted stock stays disabled');

        // Independent rendering: theme CSS can be removed without changing our geometry.
        await page.reload({waitUntil: 'networkidle'});
        for (const type of ['variable', 'grouped', 'external']) {
            const nativeSubmit = await page.evaluate(type => {
                const product = document.querySelector('.psu-product');
                product.dataset.productType = type;
                const form = product.querySelector('.psu-product__purchase form.cart');
                let native = false;
                const stop = event => {native = !event.defaultPrevented; event.preventDefault();};
                document.addEventListener('submit', stop, {once: true});
                form.requestSubmit(form.querySelector('.single_add_to_cart_button'));
                product.dataset.productType = 'simple';
                return native;
            }, type);
            assert(nativeSubmit, type + ': native form is not intercepted');
        }
        await page.setViewportSize({width: 1440, height: 1000});
        const measure = () => page.locator('.psu-product__purchase').evaluate(node => ({width: node.getBoundingClientRect().width, font: getComputedStyle(node.querySelector('.product_title')).fontSize}));
        const before = await measure();
        await page.evaluate(() => document.querySelectorAll('link[href*="/themes/generatepress/"], style[id^="generate-"]').forEach(node => node.remove()));
        assert.deepEqual(await measure(), before, 'Product columns/title independent of parent styles');
        await page.reload({waitUntil: 'networkidle'});
        for (const width of [1440, 390]) {
            await page.setViewportSize({width, height: 1000});
            await page.locator('.psu-product-content').screenshot({path: path.join(output, `product-${width}.png`)});
        }
        await page.goto(localURL(fixture.shop_url), {waitUntil: 'networkidle'});
        assert.equal(await page.locator('.psu-product-main').count(), 0);
        assert.equal(await page.locator('link[href*="single-product.css"], script[src*="single-product.js"]').count(), 0, 'Catalogue retains its own assets');
        await context.close();

        const noJS = await openContext(null, false), native = await openProduct(noJS);
        assert(await native.locator('.product_title').isVisible());
        assert(await native.locator('.single_add_to_cart_button').isVisible());
        assert.notEqual(await native.locator('.woocommerce-product-gallery').evaluate(node => getComputedStyle(node).opacity), '0', 'Gallery visible without JavaScript');
        assert.equal((await native.locator('form.cart').getAttribute('method')).toLowerCase(), 'post');
        await noJS.close();
        assert.deepEqual(errors, [], 'No page JavaScript errors');
        fs.writeFileSync(path.join(output, 'report.json'), JSON.stringify({passed: true, roles: results, simulatedPurchaseRequests: requestCount, actualCartWrites: 0, errors}, null, 2));
        console.log(JSON.stringify({passed: true, roles: results, simulatedPurchaseRequests: requestCount, actualCartWrites: 0, errors}));
    } finally { await browser.close(); }
})().catch(error => {console.error(error); process.exit(1);});
