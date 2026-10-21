// Read-only local acceptance. Run product browser session prepare/cleanup around
// this test; the development-server prepend supplies the in-memory cart fixture.
const { chromium } = require('playwright');
const assert = require('node:assert/strict');
const fs = require('node:fs');
(async () => {
    const base = 'http://127.0.0.1:8088';
    const fixture = JSON.parse(fs.readFileSync('/tmp/psu-product-browser-session.json', 'utf8'));
    const output = process.env.PSU_STYLES_OUTPUT || '/tmp/psu-style-acceptance';
    fs.mkdirSync(output, { recursive: true });
    const local = url => base + new URL(url).pathname + new URL(url).search;
    const browser = await chromium.launch({ executablePath: '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome', headless: true });
    const results = [];
    const snapshot = async page => page.evaluate(() => {
        const selectors = ['#content', '#primary', '#main', '.inside-article', 'h1',
            'ul.products', '.product_title', '.woocommerce-MyAccount-navigation',
            '#main input[type="search"]', '#username', '#main .button'];
        return Object.fromEntries(selectors.map(selector => {
            const el = document.querySelector(selector);
            if (!el) return [selector, null];
            const s = getComputedStyle(el);
            return [selector, { width: Math.round(el.getBoundingClientRect().width),
                font: s.font, color: s.color, background: s.backgroundColor,
                padding: s.padding, borderRadius: s.borderRadius, columns: s.gridTemplateColumns }];
        }));
    });
    try {
        for (const role of ['guest', 'wholesale', 'customer']) {
            const context = await browser.newContext();
            await context.route('**/*', route => {
                const req = route.request(), url = new URL(req.url());
                const own = url.origin === base && !url.pathname.endsWith('/wp-cron.php') && !url.searchParams.has('add-to-cart');
                const image = req.resourceType() === 'image' && ['kreul-media.s3.gra.io.cloud.ovh.net', 'wfpstorage.s3-eu-west-1.amazonaws.com'].includes(url.hostname);
                return ['GET', 'HEAD'].includes(req.method()) && (own || image) ? route.continue() : route.abort();
            });
            if (role !== 'guest') await context.addCookies(fixture.sessions[role].cookies.map(c => ({ ...c, domain: '127.0.0.1' })));
            const page = await context.newPage();
            const pages = role === 'guest' ? {
                catalog: local(fixture.shop_url), category: local(fixture.category_url), search: local(fixture.shop_url) + '?s=Kreul&post_type=product',
                product: local(fixture.url), cart: local(fixture.cart_url),
                filledCart: local(fixture.cart_url) + '?psu_style_fixture=cart',
                checkout: local(fixture.checkout_url) + '?psu_style_fixture=cart', account: local(fixture.account_url),
            } : role === 'wholesale' ? {
                catalog: local(fixture.shop_url), account: local(fixture.account_url),
                help: local(fixture.help_url), quick: local(fixture.quick_url),
            } : { account: local(fixture.account_url) };
            for (const width of role === 'guest' ? [1440, 1024, 768, 390, 320] : [1440, 390]) {
                await page.setViewportSize({ width, height: 1000 });
                for (const [name, url] of Object.entries(pages)) {
                    await page.goto(url, { waitUntil: 'networkidle' });
                    assert.equal(await page.locator('body.psu-storefront').count(), 1, `${role}/${name}: page scope`);
                    assert.equal(await page.locator('#psu-storefront-css').count(), 1, 'One foundation stylesheet');
                    assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), `${role}/${name}/${width}: no page overflow`);
                    if (name === 'filledCart') assert.equal(await page.locator('tr.cart_item').count(), 1, 'Filled in-memory cart renders');
                    if (name === 'checkout') assert.equal(await page.locator('form.checkout').count(), 1, 'Actual checkout form renders');
                    if (name === 'quick') assert(await page.locator('.pc-qo-table').count(), 'Actual quick-order table renders');
                    if (['catalog', 'category'].includes(name) && width <= 768) {
                        assert.equal(await page.locator('ul.products').first().evaluate(el => getComputedStyle(el).gridTemplateColumns.split(' ').length), 3, 'Three compact mobile columns');
                    }
                    if (name === 'product') {
                        assert.equal(await page.locator('.product_title').evaluate(el => getComputedStyle(el).fontWeight), '400', 'Light product title retained');
                        const image = page.locator('.woocommerce-product-gallery__image > a > img').first();
                        assert((await image.boundingBox()).height <= (width <= 768 ? 340 : 560) + 1, 'Photo stays bounded');
                    }
                    if (role === 'guest' && [1440, 390].includes(width)) await page.screenshot({ path: `${output}/${name}-${width}.png` });
                    const before = await snapshot(page);
                    await page.evaluate(() => document.querySelectorAll('link[href*="/themes/"], style[id^="generate-"]').forEach(el => el.remove()));
                    const after = await snapshot(page);
                    assert.deepEqual(after, before, `${role}/${name}/${width}: content geometry and typography survive removal of theme CSS`);
                    if (name === 'account' && role !== 'guest') {
                        assert.equal(await page.locator('.woocommerce-MyAccount-navigation ul').evaluate(el => getComputedStyle(el).display), 'flex', 'Account tabs remain horizontal and wrap');
                    }
                    const focusable = page.locator('#main a[href], #main input:not([type="hidden"])').filter({ visible: true }).first();
                    if (await focusable.count()) {
                        await focusable.focus();
                        assert(await focusable.evaluate(el => getComputedStyle(el).outlineStyle !== 'none'), 'Visible keyboard focus without theme');
                    }
                    results.push({ role, page: name, width, ok: true });
                }
            }
            await context.close();
        }
        fs.writeFileSync(`${output}/results.json`, JSON.stringify(results, null, 2));
        console.log(JSON.stringify({ passed: results.length, checks: 'scope, layout, no overflow, theme CSS removed, keyboard, native cart/checkout, role pages' }));
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exit(1); });
