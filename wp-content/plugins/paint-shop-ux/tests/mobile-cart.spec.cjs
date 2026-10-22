const fs = require('node:fs');
const assert = require('node:assert/strict');
const {chromium} = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const fixture = JSON.parse(fs.readFileSync('/tmp/psu-mobile-cart-fixture.json', 'utf8'));
assert.equal(new URL(fixture.url).hostname, 'paint.local');
(async () => {
    const browser = await chromium.launch({headless:true, executablePath:'/Applications/Google Chrome.app/Contents/MacOS/Google Chrome'});
    try {
        for (const role of ['guest', 'retail', 'wholesale']) {
            const context = await browser.newContext({viewport:{width:390, height:844}});
            if (fixture.users[role]) await context.addCookies(fixture.users[role].cookies);
            const page = await context.newPage();
            let summaryRequests = 0;
            const errors = [];
            page.on('pageerror', error => errors.push(error.message));
            page.on('request', request => { if (request.url().includes('wc-ajax=psu_mobile_cart')) summaryRequests++; });
            await page.goto(fixture.url);
            await page.waitForSelector('.psu-product__purchase');
            assert.equal(await page.locator('#psu-mobile-cart').isVisible(), false, 'Empty cart hidden');
            assert.equal(summaryRequests, 0, 'Empty sessions need no extra request');
            const consent = page.locator('.lca-consent');
            if (await consent.isVisible()) {
                const reject = consent.locator('button').filter({hasText:/необхід|Необхід|відхил|Відхил|Reject|necessary/}).first();
                if (await reject.count()) await reject.click();
            }
            const qty = page.locator('.psu-product__purchase input.qty');
            await qty.fill('2');
            const added = page.waitForResponse(r => r.url().includes('wc-ajax=add_to_cart'));
            await page.locator('.psu-product__purchase .single_add_to_cart_button').click();
            const response = await (await added).json();
            assert.ok(response.fragments?.['#psu-mobile-cart'], 'Woo response carries live summary');
            await page.locator('#psu-mobile-cart:not([hidden])').waitFor();
            assert.equal(await page.locator('#psu-mobile-cart').getAttribute('data-count'), '2');
            assert.ok((await page.locator('.psu-mobile-cart__notice').textContent()).includes('додано'));
            assert.equal(summaryRequests, 0, 'Add response reused without second request');
            const subtotal = (await page.locator('.psu-mobile-cart__amount').textContent()).trim();
            assert.ok(subtotal.length > 8, 'Subtotal present');
            await page.addStyleTag({path:require('node:path').resolve(__dirname, '../../lavka-customer-analytics/assets/consent.css')});
            await page.evaluate(() => {
                if (!document.querySelector('.lca-preferences')) {
                    const preferences = document.createElement('button');
                    preferences.className = 'lca-preferences';
                    preferences.textContent = 'Налаштування аналітики';
                    document.body.append(preferences);
                }
            });
            if (role === 'guest') {
                // Rejected add must not increase the last confirmed count.
                await page.route('**/*wc-ajax=add_to_cart*', route => route.fulfill({status:200, contentType:'application/json', body:JSON.stringify({error:true})}));
                const failed = page.waitForResponse(r => r.url().includes('wc-ajax=add_to_cart'));
                await page.locator('.psu-product__purchase .single_add_to_cart_button').click();
                await failed;
                await page.waitForTimeout(150);
                assert.equal(await page.locator('#psu-mobile-cart').getAttribute('data-count'), '2', 'Failed add leaves count unchanged');
                await page.unroute('**/*wc-ajax=add_to_cart*');
                await qty.focus();
                assert.equal(await page.locator('#psu-mobile-cart').isVisible(), false, 'Input focus gives keyboard room');
                await qty.blur();
                await page.locator('#psu-mobile-cart:not([hidden])').waitFor();
            }
            for (const width of [320, 390, 768, 1280]) {
                await page.setViewportSize({width, height:844});
                if (width <= 768) {
                    await page.locator('#psu-mobile-cart:not([hidden])').waitFor();
                    await page.waitForTimeout(100);
                    assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 2), 'No horizontal overflow');
                    const fits = await page.evaluate(() => {
                        const bar = document.querySelector('#psu-mobile-cart').getBoundingClientRect();
                        const preferences = document.querySelector('.lca-preferences')?.getBoundingClientRect();
                        const link = document.querySelector('.psu-mobile-cart__link').getBoundingClientRect();
                        return link.height >= 44 && link.right <= innerWidth && bar.bottom <= innerHeight + 1
                            && (!preferences || preferences.bottom <= bar.top);
                    });
                    assert.ok(fits, 'Tap target and consent control do not overlap');
                    if (width === 390) await page.screenshot({path:'/tmp/mobile-cart-' + role + '.png', fullPage:false});
                } else {
                    await page.waitForTimeout(180);
                    assert.equal(await page.locator('#psu-mobile-cart').isVisible(), false, 'No desktop bar');
                }
            }
            await page.setViewportSize({width:390, height:844});
            await page.goto(fixture.category);
            await page.locator('#psu-mobile-cart:not([hidden])').waitFor();
            assert.equal(await page.locator('#psu-mobile-cart').getAttribute('data-count'), '2', 'Restored after navigation');
            await page.screenshot({path:'/tmp/mobile-cart-catalogue-' + role + '.png', fullPage:false});
            if (role === 'guest') {
                const card = page.locator('.products .product').first();
                if (await card.count()) await card.evaluate(el => el.scrollIntoView({block:'start'}));
                await page.screenshot({path:require('node:path').resolve(__dirname, '../../../mu-plugins/pc-wholesale-help/assets/images/mobile-cart-bar.jpg'), type:'jpeg', quality:85});
            }
            await page.locator('.psu-mobile-cart__link').click();
            await page.waitForURL(url => url.pathname === new URL(fixture.cart).pathname);
            assert.equal(await page.locator('#psu-mobile-cart').count(), 0, 'No bar inside cart');
            const cartSubtotal = (await page.locator('.cart-subtotal .amount').first().textContent()).trim();
            assert.ok(subtotal.includes(cartSubtotal), 'Panel matches real cart subtotal for this role');
            await page.goto(fixture.checkout);
            assert.equal(await page.locator('#psu-mobile-cart').count(), 0, 'No checkout bar');
            await page.goto(fixture.cart);
            const remove = page.locator('.woocommerce-cart-form a.remove');
            if (await remove.count()) {
                await remove.first().click();
                await page.locator('.cart-empty').waitFor();
            }
            await page.goto(fixture.category);
            assert.equal(await page.locator('#psu-mobile-cart').isVisible(), false, 'Hidden after emptying cart');
            assert.deepEqual(errors, []);
            console.log('Mobile cart live add/navigation/cleanup passed: ' + role);
            await context.close();
        }
        const context = await browser.newContext({viewport:{width:390, height:844}});
        await context.addCookies(fixture.users.draft.cookies);
        const page = await context.newPage();
        await page.goto(fixture.url);
        assert.equal(await page.locator('#psu-mobile-cart').count(), 0, 'Draft mode has no purchase CTA');
        assert.equal(await page.locator('script[src*="mobile-cart.js"]').count(), 0, 'No draft assets');
        await context.close();
    } finally { await browser.close(); }
})().catch(error => {console.error(error); process.exitCode = 1;});
