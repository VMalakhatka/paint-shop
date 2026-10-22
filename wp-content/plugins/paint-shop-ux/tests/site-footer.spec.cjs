// Local read-only browser acceptance; uses the existing short-lived product sessions.
const { chromium } = require('playwright');
const assert = require('node:assert/strict');
const fs = require('node:fs');
(async () => {
    const base = process.env.PSU_FOOTER_BASE || 'http://127.0.0.1:8088';
    assert(['127.0.0.1', 'paint.local'].includes(new URL(base).hostname), 'Local only');
    const fixture = JSON.parse(fs.readFileSync('/tmp/psu-product-browser-session.json', 'utf8'));
    const output = process.env.PSU_FOOTER_OUTPUT || '/tmp/psu-footer-acceptance';
    fs.mkdirSync(output, { recursive: true });
    const local = url => base + new URL(url).pathname + new URL(url).search;
    const browser = await chromium.launch({ executablePath: '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome', headless: true });
    const results = [];
    const contextFor = async (role, javaScriptEnabled = true) => {
        const context = await browser.newContext({ viewport: { width: 1440, height: 1000 }, javaScriptEnabled });
        await context.route('**/*', route => {
            const request = route.request(), url = new URL(request.url());
            const read = ['GET', 'HEAD'].includes(request.method());
            const own = url.origin === new URL(base).origin && !url.pathname.endsWith('/wp-cron.php') && !url.searchParams.has('add-to-cart');
            const image = request.resourceType() === 'image' && ['kreul-media.s3.gra.io.cloud.ovh.net', 'wfpstorage.s3-eu-west-1.amazonaws.com'].includes(url.hostname);
            return read && (own || image) ? route.continue() : route.abort();
        });
        if (role) await context.addCookies(fixture.sessions[role].cookies.map(cookie => ({ ...cookie, domain: new URL(base).hostname })));
        return context;
    };
    try {
        for (const role of [null, 'wholesale', 'customer']) {
            assert(!role || fixture.sessions[role], 'Existing role fixture available');
            const context = await contextFor(role);
            const page = await context.newPage();
            for (const width of [1440, 768, 390, 320]) {
                await page.setViewportSize({ width, height: 1000 });
                await page.goto(local(fixture.url), { waitUntil: 'domcontentloaded' });
                const footer = page.locator('.psu-site-footer');
                await footer.scrollIntoViewIfNeeded();
                assert.equal(await footer.count(), 1, 'One footer');
                assert.equal(await page.locator('.pc-legal-footer').count(), 1, 'One legal block');
                assert.equal(await page.locator('.copyright-bar').count(), 0, 'No native theme credit');
                assert.equal(await footer.locator('.pc-legal-footer__links a').count(), 4, 'All policy links present');
                const helpPath = new URL(fixture.help_url).pathname;
                assert.equal(await footer.locator(`a[href*="${helpPath}"]`).count(), role === 'wholesale' ? 1 : 0, 'Help link respects role');
                const geometry = await footer.evaluate(element => ({ width: element.clientWidth, scroll: element.scrollWidth, left: element.getBoundingClientRect().left,
                    columns: getComputedStyle(element.querySelector('.pc-legal-footer__inner')).gridTemplateColumns.split(' ').length }));
                assert(geometry.scroll <= geometry.width + 1 && geometry.left >= -1, 'Footer has no horizontal overflow');
                assert.equal(geometry.columns, width <= 768 ? 1 : 3, 'Responsive columns');
                const targets = await footer.locator('nav a, .pc-legal-footer__contacts > a:not(.pc-legal-footer__payments)').evaluateAll(links => links.map(link => link.getBoundingClientRect().height));
                assert(targets.every(height => height >= 44), 'Touch targets are at least 44px high');
                await footer.locator('.pc-legal-footer__payments img').evaluate(image => image.decode());
                assert(await footer.locator('.pc-legal-footer__payments img').evaluate(image => image.naturalWidth > 1), 'Payment mark loads without a JS placeholder');
                if (!role && [1440, 390].includes(width)) await footer.screenshot({ path: `${output}/footer-${width}.png` });
                results.push({ role: role || 'guest', width, ok: true });
            }
            if (!role) {
                await page.setViewportSize({ width: 1440, height: 1000 });
                const productURL = local(fixture.url);
                await page.goto(productURL + '?psu_footer_legacy=1', { waitUntil: 'domcontentloaded' });
                assert.equal(await page.locator('.psu-site-footer').count(), 0, 'Rollback restores the old footer');
                assert.equal(await page.locator('.pc-legal-footer').count(), 1, 'Rollback retains legal content');
                const oldLinks = await page.locator('.pc-legal-footer a').evaluateAll(links => links.map(link => link.getAttribute('href')));
                const oldHeader = await page.locator('.lavka-topbar').boundingBox();
                await page.goto(productURL, { waitUntil: 'domcontentloaded' });
                assert.deepEqual(await page.locator('.pc-legal-footer a').evaluateAll(links => links.map(link => link.getAttribute('href'))), oldLinks, 'All existing legal/contact/payment destinations preserved');
                assert.deepEqual(await page.locator('.lavka-topbar').boundingBox(), oldHeader, 'Header geometry unchanged');
                await page.locator('.psu-site-footer__brand').focus();
                await page.keyboard.press('Tab');
                assert(await page.locator('.psu-site-footer__navigation a').first().evaluate(link => link === document.activeElement), 'Keyboard navigation reaches catalogue');
                await page.evaluate(() => document.querySelectorAll('link[href*="/themes/"], style[id^="generate-"]').forEach(style => style.remove()));
                assert.equal(await page.locator('.pc-legal-footer__inner').evaluate(el => getComputedStyle(el).gridTemplateColumns.split(' ').length), 3, 'Footer layout survives removal of theme CSS');
                await page.goto(productURL + '?psu_footer_fallback=1', { waitUntil: 'domcontentloaded' });
                assert.equal(await page.locator('.psu-site-footer').count(), 1, 'wp_footer renders without the GP placement');
                for (const url of [fixture.shop_url, fixture.cart_url, fixture.checkout_url, fixture.account_url]) {
                    await page.goto(local(url), { waitUntil: 'domcontentloaded' });
                    assert.equal(await page.locator('.psu-site-footer').count(), 1, 'Footer present on storefront routes');
                }
            }
            await page.goto(local(fixture.account_url), { waitUntil: 'domcontentloaded' });
            assert.equal(await page.locator('.psu-site-footer').count(), 1, 'Footer present on the role account screen');
            if (role === 'wholesale') {
                await page.goto(local(fixture.help_url), { waitUntil: 'domcontentloaded' });
                assert.equal(await page.locator('.pc-help-footer').count(), 1, 'Wholesale guide remains accessible');
                assert.equal(await page.locator('.psu-site-footer').count(), 1, 'Own footer present on the guide');
            }
            await context.close();
        }
        const context = await contextFor(null, false);
        const page = await context.newPage();
        await page.setViewportSize({ width: 390, height: 1000 });
        await page.goto(local(fixture.url), { waitUntil: 'domcontentloaded' });
        assert.equal(await page.locator('.psu-site-footer .pc-legal-footer__links a').count(), 4, 'Footer works without JavaScript');
        const payment = page.locator('.psu-site-footer .pc-legal-footer__payments img');
        await payment.scrollIntoViewIfNeeded();
        await payment.evaluate(image => image.decode());
        assert(await payment.evaluate(image => image.naturalWidth > 1), 'Payment mark visible without JavaScript');
        await context.close();
        fs.writeFileSync(`${output}/results.json`, JSON.stringify(results, null, 2));
        console.log(JSON.stringify({ passed: results.length, extra: 'rollback, legal links, header, keyboard, no theme CSS, fallback, storefront routes, no JS' }));
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exit(1); });
