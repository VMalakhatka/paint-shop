const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const {chromium} = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const plugin = path.resolve(__dirname, '..');
(async () => {
    const browser = await chromium.launch({headless:true, executablePath:'/Applications/Google Chrome.app/Contents/MacOS/Google Chrome'});
    try {
        const page = await browser.newPage({viewport:{width:390, height:844}});
        await page.route('http://paint.local/psu-cart-state-test', route => route.fulfill({contentType:'text/html', body:'<!doctype html><body><div id="psu-mobile-cart" hidden data-count="0"></div><div class="psu-mobile-cart-space"></div></body>'}));
        await page.goto('http://paint.local/psu-cart-state-test');
        await page.addStyleTag({path:path.join(plugin, 'assets/mobile-cart.css')});
        await page.addScriptTag({path:path.resolve(plugin, '../../../wp-includes/js/jquery/jquery.min.js')});
        await page.evaluate(() => {
            document.cookie = 'woocommerce_items_in_cart=1;path=/';
            window.psuMobileCart = {url:'/unused-summary', added:'Товар додано'};
            window.fetch = () => new Promise(resolve => { window.releaseSummary = resolve; });
            window.fragment = count => '<div id="psu-mobile-cart" class="psu-mobile-cart" data-count="' + count + '" hidden><span role="status"></span><a href="/cart/">Перейти до кошика</a></div>';
        });
        await page.addScriptTag({path:path.join(plugin, 'assets/mobile-cart.js')});
        await page.waitForFunction(() => typeof window.releaseSummary === 'function');
        await page.evaluate(() => {
            // A later Woo listener also replaces the same fragment with hidden markup.
            jQuery(document.body).on('added_to_cart.test', (event, fragments) => jQuery('#psu-mobile-cart').replaceWith(fragments['#psu-mobile-cart']));
            jQuery(document.body).trigger('added_to_cart', [{'#psu-mobile-cart':window.fragment(3)}]);
        });
        await page.locator('#psu-mobile-cart:not([hidden])').waitFor();
        assert.equal(await page.locator('#psu-mobile-cart [role=status]').textContent(), 'Товар додано', 'Announcement survives later Woo listener');
        await page.evaluate(() => window.releaseSummary({ok:true, json:async () => ({html:window.fragment(2)})}));
        await page.waitForTimeout(100);
        assert.equal(await page.locator('#psu-mobile-cart').getAttribute('data-count'), '3', 'Stale initial response cannot overwrite successful add');
        await page.evaluate(() => jQuery(document.body).trigger('removed_from_cart', [{'#psu-mobile-cart':window.fragment(0)}]));
        await page.waitForTimeout(30);
        assert.equal(await page.locator('#psu-mobile-cart').isVisible(), false, 'Removal hides bar');
        assert.equal(await page.locator('body').evaluate(el => el.classList.contains('psu-mobile-cart-visible')), false, 'Removal clears reserved space');
        await page.evaluate(() => {
            document.body.classList.add('pcoe-draft-mode');
            jQuery(document.body).trigger('added_to_cart', [{'#psu-mobile-cart':window.fragment(5)}]);
        });
        await page.waitForTimeout(30);
        assert.equal(await page.locator('#psu-mobile-cart').isVisible(), false, 'Draft guard survives event');
        console.log('Mobile cart stale-response, listener ordering, removal and draft-state checks passed.');
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
