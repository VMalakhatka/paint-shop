// Local read-only browser acceptance. Prepare/cleanup the existing product session
// fixture around this test. All POSTs are blocked or answered by in-memory mocks.
const { chromium } = require('playwright');
const assert = require('node:assert/strict');
const fs = require('node:fs');

(async () => {
    const base = process.env.PSU_HEADER_BASE || 'http://127.0.0.1:8088';
    assert.equal(new URL(base).hostname, '127.0.0.1', 'Loopback preview only');
    const fixture = JSON.parse(fs.readFileSync('/tmp/psu-product-browser-session.json', 'utf8'));
    const output = process.env.PSU_HEADER_OUTPUT || '/tmp/psu-site-header-acceptance';
    fs.mkdirSync(output, { recursive: true });
    const local = value => base + new URL(value).pathname + new URL(value).search;
    const query = (value, key, content) => { const url = new URL(local(value)); url.searchParams.set(key, content); return url.href; };
    const path = value => new URL(value, base).pathname + new URL(value, base).search;
    const browser = await chromium.launch({ executablePath: '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome', headless: true });
    const results = [], errors = [];
    let blockedPosts = 0, mockedPosts = 0;
    const menuFixture = `<ul id="psu-primary-menu" class="psu-site-header__menu">
        <li id="fixture-first"><a href="#first">First menu</a><ul class="sub-menu">
            <li id="fixture-nested"><a href="#nested">Nested menu</a><ul class="sub-menu"><li><a id="fixture-deep-link" href="#deep">Deep link</a></li></ul></li>
            <li><a href="#another">Another link</a></li></ul></li>
        <li><a href="#middle">Middle menu</a></li>
        <li id="fixture-last"><a href="#last">Last menu</a><ul class="sub-menu"><li><a href="#last-child">Last child</a></li></ul></li>
    </ul>`;

    const openContext = async (role, options = {}) => {
        const context = await browser.newContext({ viewport: { width: 1440, height: 1000 }, javaScriptEnabled: options.javaScriptEnabled !== false });
        await context.route('**/*', async route => {
            const request = route.request(), url = new URL(request.url());
            const own = url.origin === new URL(base).origin;
            if (request.method() === 'POST') {
                const data = new URLSearchParams(request.postData() || '');
                if (own && url.pathname.endsWith('/admin-ajax.php') && options.mockAllocation
                    && ['pc_set_alloc_pref', 'pc_recalc_alloc_plans'].includes(data.get('action'))) {
                    mockedPosts++;
                    options.mockAllocation.push({ action: data.get('action'), mode: data.get('mode'), term: data.get('term_id') });
                    return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ success: true,
                        data: data.get('action') === 'pc_set_alloc_pref' ? { mode: data.get('mode'), term_id: Number(data.get('term_id')) } : null }) });
                }
                blockedPosts++;
                return route.abort();
            }
            const safe = ['GET', 'HEAD'].includes(request.method()) && !url.pathname.endsWith('/wp-cron.php') && !url.searchParams.has('add-to-cart');
            const image = request.resourceType() === 'image' && ['kreul-media.s3.gra.io.cloud.ovh.net', 'wfpstorage.s3-eu-west-1.amazonaws.com'].includes(url.hostname);
            if (!safe || (!own && !image)) return route.abort();
            if (options.submenus && own && request.resourceType() === 'document') {
                const response = await route.fetch();
                const original = await response.text();
                const html = original.replace(/(<nav\b[^>]*\bid="psu-site-navigation"[^>]*>)[\s\S]*?(<\/nav>)/, '$1' + menuFixture + '$2');
                assert.notEqual(html, original, 'Synthetic submenus replace only the navigation in this browser response');
                return route.fulfill({ response, body: html });
            }
            return route.continue();
        });
        if (role !== 'guest') {
            assert(fixture.sessions[role], 'Existing temporary session required: ' + role);
            await context.addCookies(fixture.sessions[role].cookies.map(cookie => ({ ...cookie, domain: '127.0.0.1' })));
        }
        context.on('page', page => page.on('pageerror', error => errors.push(error.message)));
        return context;
    };
    const goto = async (page, url) => {
        assert.equal((await page.goto(url, { waitUntil: 'networkidle', timeout: 60000 })).status(), 200);
    };
    const links = async (page, selector) => page.locator(selector).evaluateAll(nodes => nodes.map(node => ({ href: new URL(node.href).pathname + new URL(node.href).search, text: node.textContent.trim() })));
    const snapshot = async page => page.locator('.psu-site-header').evaluate(header => {
        const selectors = ['', '.psu-site-header__identity', '.psu-site-header__brand-row', '.psu-site-header__logo', '.psu-site-header__contacts',
            '.psu-site-header__toolbar', '.pc-alloc', '#pc-slu-location', '.psu-site-header__nav', '.psu-site-header__menu', '.psu-site-header__menu > li > a'];
        return Object.fromEntries(selectors.map(selector => {
            const node = selector ? header.querySelector(selector) : header;
            if (!node) return [selector, null];
            const rect = node.getBoundingClientRect(), css = getComputedStyle(node);
            return [selector, { x: Math.round(rect.x), width: Math.round(rect.width), height: Math.round(rect.height),
                font: css.font, color: css.color, background: css.backgroundColor, padding: css.padding, border: css.border, display: css.display }];
        }));
    });
    const bounds = async (locator, label) => {
        const rect = await locator.boundingBox();
        assert(rect && rect.width > 0 && rect.height > 0, label + ': visible');
        const width = await locator.page().evaluate(() => innerWidth);
        assert(rect.x >= -1 && rect.x + rect.width <= width + 1, label + ': inside both viewport edges');
    };

    try {
        const baselines = {};
        for (const role of ['guest', 'wholesale', 'customer']) {
            const context = await openContext(role), page = await context.newPage();
            await goto(page, query(fixture.url, 'psu_header_legacy', '1'));
            assert.equal(await page.locator('.psu-site-header').count(), 0, 'Rollback omits own header');
            assert.equal(await page.locator('#masthead').count(), 1, 'Rollback restores theme header');
            assert.equal(await page.locator('#pc-slu-location').count(), 1, 'Rollback restores one warehouse selector');
            baselines[role] = await links(page, '#site-navigation .main-nav a');
            assert(baselines[role].length >= 3, 'Existing menu baseline is available');
            assert.equal(baselines[role].some(link => link.href === path(fixture.quick_url)), role === 'wholesale', 'Baseline follows the wholesale menu gate');
            for (const width of [1440, 1024, 768, 390, 320]) {
                await page.setViewportSize({ width, height: 1000 });
                await goto(page, local(fixture.url));
                const header = page.locator('.psu-site-header'), nav = page.locator('#psu-site-navigation'), toggle = page.locator('.psu-site-header__toggle');
                await header.locator('[aria-controls="psu-site-navigation"]').waitFor({ state: 'attached' });
                await page.waitForFunction(() => document.querySelector('.psu-site-header').dataset.enhanced === 'true');
                assert.equal(await header.count(), 1, 'One independent header');
                assert.equal(await page.locator('#masthead, #site-navigation, .lavka-topbar, #pc-alloc-mount').count(), 0, 'Theme header and hidden warehouse mount absent');
                for (const id of ['pc-slu-location', 'pc-slu-mode']) {
                    assert.equal(await page.locator('#' + id).count(), 1, id + ': exactly once');
                    assert.equal(await header.locator('#' + id).count(), 1, id + ': direct header placement');
                }
                assert.equal(await header.locator('select#pc-slu-mode').count(), role === 'wholesale' ? 1 : 0, 'Existing allocation role policy');
                assert.equal(await header.locator('.pc-alloc-warning').count(), role === 'wholesale' ? 1 : 0, 'Split warning follows the wholesale role gate');
                assert.deepEqual(await links(page, '#psu-primary-menu a'), baselines[role], 'Saved links, order and role visibility retained');
                assert.equal(await header.locator('a[href^="tel:"]').count(), 3, 'All three contact numbers retained');
                await bounds(header.locator('#pc-slu-location'), 'Warehouse selector');
                const locationBox = await header.locator('#pc-slu-location').boundingBox();
                assert(locationBox.height >= 44, 'Warehouse touch target');
                assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), `${role}/${width}: no page overflow`);
                if (width <= 768) {
                    assert(await nav.isHidden(), 'Mobile menu initially collapsed');
                    assert.equal(await toggle.getAttribute('aria-expanded'), 'false');
                    await toggle.click();
                    assert(await nav.isVisible(), 'Mobile menu opens');
                    assert.equal(await toggle.getAttribute('aria-expanded'), 'true');
                    for (const link of await nav.locator('a').all()) assert(await link.isVisible(), 'Every allowed mobile link is visible');
                    await nav.locator('a').first().focus();
                    await page.keyboard.press('Escape');
                    assert(await nav.isHidden(), 'Escape closes mobile menu');
                    assert(await toggle.evaluate(node => node === document.activeElement), 'Escape restores toggle focus');
                    await toggle.click();
                    await page.locator('.product_title').click();
                    assert(await nav.isHidden(), 'Outside click closes menu');
                    await toggle.click();
                } else {
                    assert(await nav.isVisible(), 'Desktop menu visible');
                    assert(await toggle.isHidden(), 'Desktop toggle hidden');
                }
                const before = await snapshot(page);
                await page.evaluate(() => document.querySelectorAll('link[href*="/themes/"], style[id^="generate-"]').forEach(node => node.remove()));
                assert.deepEqual(await snapshot(page), before, `${role}/${width}: header geometry and typography independent of theme CSS`);
                const first = nav.locator('a').first();
                await page.keyboard.press('Tab');
                await first.focus();
                assert.notEqual(await first.evaluate(node => getComputedStyle(node).outlineStyle), 'none', `${role}/${width}: visible keyboard focus without theme CSS`);
                if ([1440, 390].includes(width)) {
                    await page.mouse.move(width - 1, 999);
                    await header.screenshot({ path: `${output}/${role}-${width}.png` });
                }
                results.push({ role, width, menu: 'matched', warehouse: 'once', themeCSS: 'independent' });
            }
            // Crossing the breakpoint closes the menu and moves focus out of it.
            await page.setViewportSize({ width: 1440, height: 1000 });
            await page.locator('#psu-site-navigation a').first().focus();
            await page.setViewportSize({ width: 390, height: 1000 });
            await page.waitForFunction(() => document.querySelector('#psu-site-navigation').hidden);
            assert(await page.locator('.psu-site-header__toggle').evaluate(node => node === document.activeElement), 'Breakpoint change preserves usable focus');
            await context.close();
        }

        for (const role of ['guest', 'wholesale', 'customer']) {
            const context = await openContext(role, { javaScriptEnabled: false }), page = await context.newPage();
            await page.setViewportSize({ width: 390, height: 1000 });
            await goto(page, local(fixture.url));
            assert.deepEqual(await links(page, '#psu-primary-menu a'), baselines[role], 'No-JS retains the same role-filtered links');
            for (const link of await page.locator('#psu-primary-menu a').all()) assert(await link.isVisible(), 'No-JS mobile links remain visible');
            assert(await page.locator('.psu-site-header__toggle').isHidden(), 'No unusable menu toggle without JavaScript');
            assert.equal(await page.locator('.psu-site-header #pc-slu-location').count(), 1, 'No-JS keeps direct warehouse placement');
            results.push({ role, noJS: true, menu: 'visible' });
            await context.close();
        }

        const submenuContext = await openContext('guest', { submenus: true }), submenuPage = await submenuContext.newPage();
        for (const width of [1440, 1024, 769, 390, 320]) {
            await submenuPage.setViewportSize({ width, height: 1000 });
            await goto(submenuPage, local(fixture.url));
            await submenuPage.locator('#fixture-first > .psu-site-header__submenu-toggle').waitFor({ state: 'attached' });
            if (width <= 768) await submenuPage.locator('.psu-site-header__toggle').click();
            for (const id of ['fixture-first', 'fixture-last']) {
                const button = submenuPage.locator('#' + id + ' > .psu-site-header__submenu-toggle');
                await button.click();
                assert.equal(await button.getAttribute('aria-expanded'), 'true');
                await bounds(submenuPage.locator('#' + id + ' > .sub-menu'), `${width}/${id}`);
                await button.click();
                assert.equal(await button.getAttribute('aria-expanded'), 'false');
            }
            const parent = submenuPage.locator('#fixture-first > .psu-site-header__submenu-toggle');
            await parent.focus(); await submenuPage.keyboard.press('Enter');
            const nested = submenuPage.locator('#fixture-nested > .psu-site-header__submenu-toggle');
            await nested.focus(); await submenuPage.keyboard.press('Space');
            await submenuPage.locator('#fixture-deep-link').focus();
            await submenuPage.keyboard.press('Escape');
            assert(await submenuPage.locator('#fixture-nested > .sub-menu').isHidden(), 'Escape closes deepest submenu');
            assert(await nested.evaluate(node => node === document.activeElement), 'Escape focuses deepest disclosure');
            assert(await submenuPage.locator('#fixture-first > .sub-menu').isVisible(), 'Ancestor submenu remains open');
            await submenuPage.keyboard.press('Escape');
            assert(await submenuPage.locator('#fixture-first > .sub-menu').isHidden(), 'Next Escape closes parent submenu');
            assert(await parent.evaluate(node => node === document.activeElement), 'Escape focuses parent disclosure');
            results.push({ width, submenus: 'first/last in viewport, nested keyboard passed' });
        }
        await submenuPage.setViewportSize({ width: 1024, height: 1000 });
        await goto(submenuPage, local(fixture.url));
        await submenuPage.locator('#fixture-last > .psu-site-header__submenu-toggle').click();
        await submenuPage.setViewportSize({ width: 1440, height: 1000 });
        const resizedSubmenu = submenuPage.locator('#fixture-last > .sub-menu');
        // Either close disclosures or recompute their placement on desktop resize.
        if (await resizedSubmenu.isVisible()) await bounds(resizedSubmenu, 'Open submenu after desktop resize');
        await submenuContext.close();
        const plainSubmenu = await openContext('guest', { submenus: true, javaScriptEnabled: false }), plainPage = await plainSubmenu.newPage();
        await plainPage.setViewportSize({ width: 390, height: 1000 });
        await goto(plainPage, local(fixture.url));
        assert(await plainPage.locator('#fixture-deep-link').isVisible(), 'Nested menu links remain available without JavaScript');
        assert.equal(await plainPage.locator('.psu-site-header__submenu-toggle').count(), 0, 'No unusable submenu controls without JavaScript');
        await plainSubmenu.close();

        // Ordinary theme-rendered pages do not receive the storefront CSS reset.
        const ordinaryContext = await openContext('guest'), ordinaryPage = await ordinaryContext.newPage();
        for (const width of [1440, 390]) {
            await ordinaryPage.setViewportSize({ width, height: 1000 });
            const response = await ordinaryPage.goto(base + '/__psu_header_readonly_404__/', { waitUntil: 'networkidle' });
            assert.equal(response.status(), 404, 'A normal theme 404 exercises the non-storefront wrapper');
            assert.equal(await ordinaryPage.locator('body.psu-storefront').count(), 0, 'Ordinary page has no storefront foundation');
            await ordinaryPage.waitForFunction(() => document.querySelector('.psu-site-header').dataset.enhanced === 'true');
            if (width <= 768) await ordinaryPage.locator('.psu-site-header__toggle').click();
            const before = await snapshot(ordinaryPage);
            await ordinaryPage.evaluate(() => document.querySelectorAll('link[href*="/themes/"], style[id^="generate-"]').forEach(node => node.remove()));
            assert.deepEqual(await snapshot(ordinaryPage), before, `${width}: ordinary-page header independent of theme CSS`);
            results.push({ width, ordinaryPage: '404', themeCSS: 'independent' });
        }
        await ordinaryContext.close();

        const allocationCalls = [], allocationContext = await openContext('wholesale', { mockAllocation: allocationCalls }), allocationPage = await allocationContext.newPage();
        await goto(allocationPage, local(fixture.cart_url));
        assert.equal(await allocationPage.locator('tr.cart_item').count(), 0, 'Use the existing empty local wholesale cart for mocked allocation interactions');
        const mode = allocationPage.locator('select#pc-slu-mode'), location = allocationPage.locator('#pc-slu-location');
        for (const value of ['manual', 'single', 'auto']) {
            const before = allocationCalls.length;
            await mode.selectOption(value);
            await allocationPage.waitForFunction(value => document.querySelector('#pc-slu-mode').value === value, value);
            for (let attempt = 0; allocationCalls.length < before + 2 && attempt < 100; attempt++) await allocationPage.waitForTimeout(20);
            assert.equal(allocationCalls.length, before + 2, 'One preference request and one recalculation per selection');
            assert.equal(allocationCalls[before].action, 'pc_set_alloc_pref');
            assert.equal(allocationCalls[before].mode, value);
            assert.equal(allocationCalls[before + 1].action, 'pc_recalc_alloc_plans');
            assert.equal(await location.isDisabled(), value === 'auto', 'Warehouse disabled only in automatic mode');
        }
        await allocationContext.close();
        assert.deepEqual(errors, [], 'No JavaScript exceptions');
        fs.writeFileSync(`${output}/results.json`, JSON.stringify({ results, mockedPosts, realPostsSent: 0, blockedPosts }, null, 2));
        console.log(JSON.stringify({ passed: results.length, mockedPosts, realPostsSent: 0, javascriptErrors: errors.length,
            checks: 'saved links and role gates, 15 role/viewport cases, responsive keyboard, no-JS, theme CSS removed, submenu bounds, rollback, mocked allocation' }));
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exit(1); });
