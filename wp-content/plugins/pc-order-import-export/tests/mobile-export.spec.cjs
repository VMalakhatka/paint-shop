const {chromium} = require('playwright');
const assert = require('node:assert/strict');

(async () => {
    const browser = await chromium.launch({headless:true, executablePath:process.env.CHROME_BINARY || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome'});
    const context = await browser.newContext({viewport:{width:390,height:900}});
    const page = await context.newPage();
    const errors = [];
    let cartUrl;
    page.on('pageerror', e => errors.push(e.message));
    await context.route('**/*', r => ['paint.local','kreul-media.s3.gra.io.cloud.ovh.net'].includes(new URL(r.request().url()).hostname) ? r.continue() : r.abort());
    try {
        await page.goto('http://paint.local/category/laki/',{waitUntil:'networkidle'});
        cartUrl = new URL(await page.locator('a').filter({hasText:/Кошик\(класик\)/}).first().getAttribute('href'), page.url()).href;
        // Only this fresh, anonymous local browser cart is changed, never an order.
        const add = page.locator('li.product').filter({has:page.locator('.loop-qty-plus:not([disabled])')}).locator('a.ajax_add_to_cart').first();
        const addUrl = new URL(await add.getAttribute('href'),page.url()).href;
        await page.goto(addUrl,{waitUntil:'networkidle'});
        await page.goto(cartUrl,{waitUntil:'networkidle'});
        assert(await page.locator('.cart_item').count(),'Test product added to fresh cart');
        const split = page.locator('.pcoe-export select.pcoe-split');
        assert.equal(await split.count(),1);
        for (const width of process.env.PCOE_BEFORE ? [390] : [320,360,390,768,1280]) {
            await page.setViewportSize({width,height:900});
            for (const mode of ['agg','per_loc']) {
                await split.selectOption(mode);
                await split.scrollIntoViewIfNeeded();
                const bounds = await split.evaluate(el => {
                    const select = el.getBoundingClientRect(), label = el.closest('label').getBoundingClientRect(), panel = el.closest('.pcoe-export').getBoundingClientRect();
                    return {viewport:innerWidth,page:document.documentElement.scrollWidth,selectLeft:select.left,selectRight:select.right,labelRight:label.right,panelRight:panel.right,selectWidth:select.width};
                });
                console.log(JSON.stringify({width,mode,...bounds}));
                if (!process.env.PCOE_BEFORE) {
                    assert(bounds.page <= width+1,'No page horizontal scroll');
                    assert(bounds.selectLeft >= 0 && bounds.selectRight <= bounds.panelRight+1 && bounds.labelRight <= width+1,'Selector and label fit panel');
                    assert(bounds.selectWidth > 180,'Selector remains usable');
                    assert.equal(await split.inputValue(),mode);
                    assert.equal(await page.evaluate(() => localStorage.getItem('pcoeSplit')), mode === 'agg' ? '{"cart":"agg"}' : '{"cart":"per_loc"}');
                }
                if (width === 390 && mode === 'agg') {
                    await page.screenshot({path:`/tmp/pcoe-mobile-export-${process.env.PCOE_BEFORE?'before':'after'}.png`});
                    if (!process.env.PCOE_BEFORE) await page.locator('.pcoe-export').screenshot({path:'/tmp/cart-export-mobile.png'});
                }
            }
        }
        if (!process.env.PCOE_BEFORE) {
            await page.reload({waitUntil:'networkidle'});
            assert.equal(await split.inputValue(),'per_loc','Selected export mode survives reload');
            assert.deepEqual(errors,[],'No JS errors');
            console.log('PASS mobile and desktop export layout and mode persistence');
        }
    } finally {
        try {
            if (cartUrl) {
                await page.goto(cartUrl,{waitUntil:'networkidle'});
                while (await page.locator('a.remove').count()) {
                    const remove = await page.locator('a.remove').first().getAttribute('href');
                    await page.goto(new URL(remove,page.url()).href,{waitUntil:'networkidle'});
                }
                assert.equal(await page.locator('.cart_item').count(),0,'Temporary cart emptied');
                console.log('Temporary local cart emptied');
            }
        } finally { await browser.close(); }
    }
})().catch(e => {console.error(e);process.exitCode=1;});
