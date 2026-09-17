const {chromium} = require('playwright');
const assert = require('node:assert/strict');
const fs = require('node:fs');

(async () => {
    // Explicit local admin fixture only, never read a real browser profile.
    const session = process.env.PSU_ADMIN_SESSION;
    assert(session, 'PSU_ADMIN_SESSION must point to a short-lived local test session');
    const browser = await chromium.launch({executablePath: process.env.CHROME_BINARY || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome', headless:true});
    try {
        const context = await browser.newContext({viewport:{width:1280,height:900}});
        await context.route('**/*', route => new URL(route.request().url()).hostname === 'paint.local' ? route.continue() : route.abort());
        await context.addCookies(JSON.parse(fs.readFileSync(session, 'utf8')).cookies);
        const page = await context.newPage();
        const url = 'http://paint.local/wp-admin/themes.php?page=psu-category-menu';
        await page.goto(url, {waitUntil:'domcontentloaded'});
        await page.getByRole('heading', {name:'Категорії Лавки',exact:true}).waitFor();
        const denied = await context.request.post(url, {form:{menu_action:'migrate',plan:'invalid'}});
        assert.equal(denied.status(),403, 'Missing nonce cannot migrate');
        const migrate = page.locator('form').filter({has:page.locator('input[name="menu_action"][value="migrate"]')});
        if (!process.env.PSU_VERIFY_ONLY) {
            assert.equal(await migrate.count(),1, 'Migration available before switch');
            await migrate.getByRole('button',{name:'Замінити віджети категорій',exact:true}).click();
            await page.getByText('Налаштування меню категорій збережено.',{exact:true}).waitFor();
        }
        assert.equal(await page.locator('input[name="menu_action"][value="migrate"]').count(),0);
        await page.reload({waitUntil:'domcontentloaded'});
        assert.equal(await page.locator('input[name="menu_action"][value="migrate"]').count(),0, 'Reload cannot repeat migration');
        await page.goto('http://paint.local/wp-admin/widgets.php',{waitUntil:'domcontentloaded'});
        const closeWelcome = page.getByRole('dialog').getByRole('button', {name:/Закрити|Close/});
        if (await closeWelcome.isVisible()) await closeWelcome.click();
        await page.locator('[data-widget-area-id="sidebar-1"], #sidebar-1').first().waitFor({state:'attached',timeout:60000});
        const blockArea = page.locator('.wp-block-widget-area').filter({has:page.locator('[data-widget-area-id="sidebar-1"]')});
        let active;
        if (await blockArea.count()) {
            const expand = blockArea.locator('.components-panel__body-toggle[aria-expanded="false"]').first();
            if (await expand.count()) await expand.click();
            active = blockArea.locator('.wp-block-legacy-widget').filter({has:page.locator('input[name^="widget-psu_category_menu"]')});
            await active.waitFor({timeout:60000});
            await active.click();
        } else {
            active = page.locator('#sidebar-1 .widget').filter({has:page.locator('input[name="id_base"][value="psu_category_menu"]')});
            await active.waitFor();
            await active.locator('.widget-top').click();
        }
        await active.locator('input[name$="[title]"]').waitFor({state:'visible'});
        assert.equal(await active.locator('input[name$="[title]"]').inputValue(),'Категория товаров','Existing title preserved');
        assert.equal(await active.locator('select[name$="[orderby]"]').inputValue(),'name');
        assert.equal(await active.locator('select[name$="[order]"]').inputValue(),'ASC');
        assert(await active.locator('input[name$="[hide_empty]"]').isChecked());
        assert(!(await active.locator('input[name$="[show_count]"]').isChecked()));
        await active.scrollIntoViewIfNeeded();
        await active.screenshot({path:'/tmp/psu-category-native-widget-settings.png'});
        await page.goto(url,{waitUntil:'domcontentloaded'});
        await page.locator('#wpbody-content > .wrap').screenshot({path:'/tmp/psu-category-native-migration.png'});
        console.log(JSON.stringify({passed:true,nonceProtection:true,migrated:true,settingsPreserved:true}));
        await context.close();
    } finally { await browser.close(); }
})().catch(error => {console.error(error);process.exit(1);});
