const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const {chromium} = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const fixture = JSON.parse(fs.readFileSync(process.argv[2], 'utf8'));
if (new URL(fixture.shop).hostname !== 'paint.local') throw new Error('Local fixture only');
const assets = path.resolve(__dirname, '../assets/draft-guide');
fs.mkdirSync(assets, {recursive:true});
(async () => {
    const browser = await chromium.launch({headless:true, executablePath:process.env.CHROME_PATH});
    try {
        for (const width of [390, 1280]) {
            const context = await browser.newContext({viewport:{width, height:900}, deviceScaleFactor:1});
            await context.addCookies([{name:fixture.cookie_name, value:fixture.cookie_value, domain:'paint.local', path:'/', httpOnly:true}]);
            const page = await context.newPage();
            const errors = [];
            page.on('pageerror', error => errors.push(error.message));
            // No write request is needed for help or screenshots.
            await page.route('**/*', route => {
                const request = route.request();
                if (request.method() !== 'GET' && request.method() !== 'HEAD') return route.abort();
                return route.continue();
            });
            await page.goto(fixture.category_url);
            const help = page.locator('.pcoe-catalog-toolbar .pcoe-draft-help');
            await help.locator(':scope > summary').focus();
            await page.keyboard.press('Enter');
            assert.equal(await help.getAttribute('open'), '', 'Keyboard opens context menu');
            for (const topic of await help.locator('.pcoe-draft-help__topic').all()) {
                await topic.locator(':scope > summary').click();
                assert.ok(await topic.locator('ol').isVisible(), 'Topic opens inline');
                assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 2), 'Open help fits viewport');
                await topic.locator(':scope > summary').click();
            }
            await page.screenshot({path:`/tmp/draft-help-${width}.png`, fullPage:true});
            await help.locator(':scope > summary').click();
            if (width === 1280) {
                await page.locator('.pcoe-new-draft summary').click();
                await page.locator('[data-pcoe-create] input').fill('Фарби для майстерні');
                await page.locator('.pcoe-catalog-toolbar').screenshot({path:path.join(assets, 'collect.png')});
            }
            await page.goto(fixture.order);
            const process = page.locator('#pcoe-draft-folio .pcoe-draft-help');
            assert.equal(await process.count(), 1, 'Processing help available even if configuration missing');
            await process.locator(':scope > summary').click();
            assert.ok((await process.locator('.pcoe-draft-help__topic > summary').first().textContent()).includes('ФОЛІО'));
            assert.equal(errors.length, 0, errors.join('\n'));
            console.log(`Draft help keyboard and layout ${width}px passed.`);
            await context.close();
        }
        const page = await browser.newPage({viewport:{width:850, height:1200}, deviceScaleFactor:1.5});
        for (const name of ['edit', 'folio', 'preview']) {
            await page.goto('file:///tmp/draft-guide-' + name + '.html');
            assert.equal(await page.locator('input:not(:disabled), button:not(:disabled), select:not(:disabled)').count(), 0, 'Examples cannot submit');
            await page.locator('main').screenshot({path:path.join(assets, name + '.png')});
        }
        await page.close();
        const guest = await browser.newPage();
        await guest.goto(fixture.simple);
        assert.equal(await guest.locator('.pcoe-draft-help').count(), 0, 'Guests have no draft menu');
        await guest.close();
    } finally { await browser.close(); }
})().catch(error => {console.error(error); process.exitCode = 1;});
