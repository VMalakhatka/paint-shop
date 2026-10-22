const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const { chromium } = require('playwright');
const root = path.resolve(__dirname, '..');
const php = fs.readFileSync(path.join(root, 'wp-content/mu-plugins/pc-cart-guard.php'), 'utf8');
const script = php.match(/<<<'JS'\n([\s\S]*?)\nJS/)[1];
(async () => {
  const browser = await chromium.launch({headless: true, ...(process.env.CHROME_PATH ? {executablePath: process.env.CHROME_PATH} : {})});
  try {
    for (const width of [390, 1280]) {
      const page = await browser.newPage({viewport: {width, height: 850}});
      page.on('pageerror', error => console.error('Browser error:', error.message));
      await page.setContent(`<form class="woocommerce-cart-form"><table><tr class="cart_item"><td class="product-name"><a>Product</a><dl class="variation"><dt>Allocation</dt><dd>Kyiv - 1</dd><dt>Color</dt><dd>Red</dd></dl></td><td><input class="qty pc-cart-qty" name="cart[test][qty]" value="1" min="0" max="10"></td><td class="product-subtotal">10</td></tr></table></form><div class="cart_totals">10</div>`);
      await page.addScriptTag({path: path.join(root, 'wp-includes/js/jquery/jquery.min.js')});
      await page.evaluate(() => {
        window.pcCartGuardUpdate = {ajaxUrl: '/unused', nonce: 'test', updating: 'Updating'};
        window.requests = 0;
        jQuery.ajax = ({data}) => {
          window.requests++;
          const qty = window.clampQty ?? Number(data.quantity);
          const metadata = window.emptyMetadata ? '' : `<dl class="variation"><dt>Allocation</dt><dd>${qty > 2 ? 'Kyiv - 2, Odesa - ' + (qty - 2) : 'Kyiv - ' + qty}</dd><dt>Color</dt><dd>Red</dd></dl>`;
          const deferred = jQuery.Deferred();
          setTimeout(() => deferred.resolve({success: true, data: {quantity: qty, allowed_max: 10, subtotal_html: String(qty * 10), item_data_html: metadata, totals_html: `<div class="cart_totals">${qty * 10}</div>`, cart_empty: false}}), 10);
          return deferred.promise();
        };
      });
      await page.addScriptTag({content: script});
      await page.evaluate(() => new Promise(resolve => jQuery(resolve)));
      const change = async (qty, expected) => {
        const previous = await page.evaluate(() => window.requests);
        await page.locator('input.qty').fill(String(qty));
        await page.locator('input.qty').dispatchEvent('change');
        await page.waitForFunction(p => window.requests > p && !document.querySelector('.pc-cart-item-updating'), previous);
        assert.equal(await page.locator('.product-subtotal').textContent(), String(expected * 10));
        assert.equal(await page.locator('input.qty').inputValue(), String(expected));
      };
      await change(3, 3);
      assert.match(await page.locator('.product-name').textContent(), /Kyiv - 2, Odesa - 1/);
      await change(1, 1);
      assert.equal(await page.locator('dl.variation').count(), 1);
      assert.match(await page.locator('.product-name').textContent(), /Kyiv - 1/);
      assert.match(await page.locator('.product-name').textContent(), /ColorRed/);
      assert.equal(await page.locator('.product-name a').textContent(), 'Product');
      await page.evaluate(() => { window.clampQty = 2; });
      await change(5, 2);
      assert.match(await page.locator('.product-name').textContent(), /Kyiv - 2/);
      await page.screenshot({path: `/tmp/cart-allocation-${width}.png`});
      await page.evaluate(() => { window.emptyMetadata = true; });
      await change(2, 2);
      assert.equal(await page.locator('dl.variation').count(), 0);
      await page.close();
      console.log(`Cart allocation DOM ${width}px: OK`);
    }
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
