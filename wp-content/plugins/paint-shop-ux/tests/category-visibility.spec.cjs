const {chromium} = require('playwright');
const assert = require('node:assert/strict');
const fs = require('node:fs');

(async () => {
    const fixture = JSON.parse(fs.readFileSync('/tmp/psu-category-visibility-session.json', 'utf8'));
    const browser = await chromium.launch({executablePath: process.env.CHROME_BINARY || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome', headless:true});
    try {
        const context = await browser.newContext({viewport:{width:1280,height:900}});
        await context.route('**/*', route => new URL(route.request().url()).hostname === 'paint.local' ? route.continue() : route.abort());
        await context.addCookies(fixture.cookies);
        const page = await context.newPage();
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        const admin = 'http://paint.local/wp-admin/themes.php?page=psu-category-menu';
        await page.goto(admin, {waitUntil:'domcontentloaded'});
        const select = page.locator('#psu-category-excluded');
        await page.locator('.select2-container').waitFor();
        const denied = await context.request.post('http://paint.local/wp-admin/admin-post.php', {form:{action:'psu_category_visibility_save'}});
        assert.equal(denied.status(),403,'Missing nonce must deny save');
        const badSearch = await context.request.get('http://paint.local/wp-admin/admin-ajax.php?action=psu_category_visibility_search&security=invalid');
        assert.equal(badSearch.status(),403,'Missing search nonce denied');
        await page.locator('.select2-search__field').fill('PSU Visibility');
        await page.locator('.select2-results__option').filter({hasText:'PSU Visibility Empty'}).waitFor();
        // Empty terms are selectable; selecting a populated parent also hides descendants.
        await page.locator('.select2-results__option').filter({hasText:'PSU Visibility Root'}).filter({hasNotText:' / '}).click();
        assert((await select.inputValue()) !== '', 'Selected root');
        const form = page.locator('form').filter({has:select});
        await form.getByRole('button', {name:/Зберегти зміни|Сохранить изменения|Save changes/}).click();
        await page.waitForURL(/visibility_saved=1/);
        assert((await page.locator('#psu-category-excluded option:checked').evaluateAll(nodes => nodes.map(n => Number(n.value)))).includes(fixture.root));
        await page.locator('#wpbody-content > .wrap').screenshot({path:'/tmp/psu-category-visibility-admin-desktop.png'});
        await page.setViewportSize({width:390,height:844});
        await page.screenshot({path:'/tmp/psu-category-visibility-admin-mobile.png',fullPage:true});
        assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), 'Admin has no horizontal overflow');
        assert(await page.locator('.select2-selection__choice').first().evaluate(node => node.scrollWidth <= node.clientWidth + 1), 'Long category name wraps inside selection');
        // Test the guest catalogue independently from the admin session.
        const guest = await browser.newContext();
        await guest.route('**/*', route => new URL(route.request().url()).hostname === 'paint.local' ? route.continue() : route.abort());
        const catalog = await guest.newPage();
        for (const width of [1280,390]) {
            await catalog.setViewportSize({width,height:900});
            for (const kind of ['empty','child']) {
                await catalog.goto(fixture[kind+'_url'], {waitUntil:'domcontentloaded'});
                const menu = catalog.locator('.psu-category-menu').first();
                await menu.waitFor();
                assert.equal(await menu.locator('[data-category="'+fixture.empty+'"]').count(),0,'Current empty category absent');
                assert.equal(await menu.locator('[data-category="'+fixture.root+'"]').count(),0,'Excluded active ancestor absent');
                const widget = await menu.getAttribute('data-widget');
                const endpoint = await menu.getAttribute('data-endpoint');
                const locale = await menu.getAttribute('data-locale');
                const response = await guest.request.get(endpoint, {params:{widget,locale,parent:fixture.child,offset:0}});
                assert.equal(response.status(),404,'Direct request cannot expose excluded descendant');
                await catalog.screenshot({path:'/tmp/psu-category-visibility-'+kind+'-'+width+'.png',fullPage:true});
            }
        }
        await page.goto(admin,{waitUntil:'domcontentloaded'});
        await page.evaluate(root => {
            const select = document.querySelector('#psu-category-excluded');
            for (const option of select.options) if (Number(option.value) === root) option.selected = false;
            window.jQuery(select).trigger('change');
        },fixture.root);
        await page.locator('form').filter({has:page.locator('#psu-category-excluded')}).getByRole('button',{name:/Зберегти зміни|Сохранить изменения|Save changes/}).click();
        await page.waitForURL(/visibility_saved=1/);
        await catalog.goto(fixture.child_url,{waitUntil:'domcontentloaded'});
        assert.equal(await catalog.locator('.psu-category-menu [data-category="'+fixture.root+'"]').count(),1,'Clearing exclusion restores parent');
        assert.equal(errors.length,0,errors.join('\n'));
        await guest.close();
        await context.close();
        console.log(JSON.stringify({passed:true,nonceProtection:true,searchIncludesEmpty:true,saveAndRestore:true,guestDesktopAndMobile:true}));
    } finally { await browser.close(); }
})().catch(error => {console.error(error);process.exit(1);});
