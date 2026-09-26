// Usage: PLAYWRIGHT_MODULE=/path/to/playwright node point-card-ui.cjs /private/tmp/fixture-dir
// All requests mocked. The HTML fixture contains the real PHP fields, translations and JS/CSS.
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const assert = require('node:assert/strict');
const path = require('node:path');
const { pathToFileURL } = require('node:url');
const dir = process.argv[2];
if (!dir) throw new Error('Provide fixture directory');
const point = {
 ref: '00000000-0000-0000-0000-000000000051', label: 'Відділення №51: Тестова адреса', shortAddress: 'Тестова адреса, 51', kind: 'branch', selectable: true,
 placeWeight: 30, totalWeight: null, declaredValue: 29000,
 receivingDimensions: [120, 70, 70], sendingDimensions: [60, 40, 30],
 hours: Object.fromEntries(['Schedule', 'Reception', 'Delivery'].map(k => [k, Object.fromEntries(['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'].map(d => [d, '08:00-20:00']))]))
};
(async () => {
 const browser = await chromium.launch({ headless: true, channel: 'chrome' });
 let checks = 0;
 try {
  for (const width of [1280, 390]) {
   const page = await browser.newPage({ viewport: { width, height: 1000 } });
   const errors = []; page.on('pageerror', e => errors.push(e.message));
   let fail = true; const calls = [];
   await page.route('https://pnpm-test.invalid/**', async route => {
    const u = new URL(route.request().url()); const q = u.searchParams.get('query') || '';
    const action = u.searchParams.get('action'); const number = Number(u.searchParams.get('page') || 1);
    calls.push({ action, q, number });
    const send = data => route.fulfill({ contentType: 'application/json', headers: { 'Access-Control-Allow-Origin': '*' }, body: JSON.stringify({ success: true, data }) });
    if (action === 'pnpm_recipient_point') return send({ item: point });
    if (action === 'pnpm_search_recipient_cities') return send({ items: [{ ref: 'city', label: 'Київ' }] });
    if (q === 'slow') await new Promise(r => setTimeout(r, 800));
    if (q === 'error' && fail) { fail = false; return route.fulfill({ status: 503, headers: { 'Access-Control-Allow-Origin': '*' }, body: 'unavailable' }); }
    if (q === 'none') return send({ items: [], nextPage: null });
    if (q === '' && number === 1) return send({ items: [], nextPage: 2 });
    const item = { ...point, label: q === 'slow' ? 'STALE' : point.label };
    if (q === 'closed') item.selectable = false;
    if (q === 'unknown') { item.placeWeight = null; item.declaredValue = null; item.receivingDimensions = [null,null,null]; item.hours = {}; }
    if (q === 'xss') item.label = '<img src=x onerror="window.BAD=1">';
    if (u.searchParams.get('kind') === 'parcel_locker') { item.kind = 'postomat'; if (q !== 'xss' && q !== 'slow') item.label = 'Поштомат №51'; }
    return send({ items: [item], nextPage: null });
   });
   await page.goto(pathToFileURL(path.resolve(dir, 'point-card.html')).href);
   await page.locator('#pnpm_city_label').fill('Ки');
   await page.locator('#pnpm-city-results button').click();
   assert.equal(await page.locator('#pnpm_city_ref').inputValue(), 'city'); checks++;
   await page.locator('#pnpm_point_label').focus();
   await page.locator('.pnpm-directory-more').click();
   await page.locator('#pnpm-point-results .pnpm-directory-option').click();
   assert(calls.some(c => c.number === 2)); checks++;
   const card = page.locator('#pnpm-point-card');
   assert((await card.textContent()).includes('30 кг')); checks++;
   assert((await card.textContent()).includes('120 × 70 × 70 см')); checks++;
   assert((await card.textContent()).includes('НП не вказала')); checks++;
   assert.equal(await card.locator('tbody tr').count(), 7); checks++;
   assert((await card.locator('a').last().getAttribute('href')).endsWith('#np-point-limits')); checks++;
   await page.screenshot({ path: path.join(dir, `point-card-${width}.png`), fullPage: true });
   assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)); checks++;
   await page.evaluate(() => jQuery(document.body).trigger('updated_checkout'));
   assert(await card.isVisible()); checks++;
   await page.locator('#pnpm_delivery_type').selectOption('address');
   assert(!(await card.isVisible())); assert.equal(await page.locator('#pnpm_point_ref').inputValue(), ''); checks++;
   await page.locator('#pnpm_delivery_type').selectOption('parcel_locker');
   await page.locator('#pnpm_point_label').fill('51');
   await page.locator('#pnpm-point-results .pnpm-directory-option').click();
   assert((await card.textContent()).includes('Поштомат')); checks++;
   await page.locator('[name="shipping_method[0]"]').selectOption('pnpm_customer_ttn');
   assert(!(await card.isVisible())); checks++;
   await page.locator('[name="shipping_method[0]"]').selectOption('pnpm_nova_poshta:1');
   await page.locator('#pnpm_point_label').fill('closed');
   await page.locator('#pnpm-point-results .pnpm-directory-option').click();
   assert.equal(await page.locator('#pnpm_point_ref').inputValue(), ''); assert((await card.textContent()).includes('Недоступне')); checks++;
   await page.locator('#pnpm_point_label').fill('unknown');
   await page.locator('#pnpm-point-results .pnpm-directory-option').click();
   assert((await card.textContent()).includes('Не вказано')); assert(!(await card.textContent()).includes('0 кг')); checks++;
   await page.locator('#pnpm_point_label').fill('error');
   await page.locator('.pnpm-directory-more').click();
   await page.locator('#pnpm-point-results .pnpm-directory-option').waitFor(); checks++;
   await page.locator('#pnpm_point_label').fill('slow');
   await page.waitForTimeout(400);
   await page.locator('#pnpm_point_label').fill('new');
   await page.waitForTimeout(1000);
   assert(!(await page.locator('#pnpm-point-results').textContent()).includes('STALE')); checks++;
   await page.locator('#pnpm_point_label').fill('xss');
   await page.locator('#pnpm-point-results .pnpm-directory-option').filter({ hasText: '<img' }).click();
   assert.equal(await card.locator('img').count(), 0); assert.equal(await page.evaluate(() => window.BAD), undefined); checks++;
   await page.locator('#pnpm_city_label').fill('Ль');
   assert(!(await card.isVisible())); assert.equal(await page.locator('#pnpm_point_ref').inputValue(), ''); checks++;
   // Restored selection is fetched by Ref, not inferred from the input label.
   await page.evaluate(ref => { jQuery('#pnpm_city_ref').val('city'); jQuery('#pnpm_point_ref').val(ref); jQuery('#pnpm_delivery_type').val('branch'); jQuery(document.body).trigger('updated_checkout'); }, point.ref);
   await card.locator('dl').waitFor();
   assert(calls.some(c => c.action === 'pnpm_recipient_point')); checks++;
   assert.deepEqual(errors, []); checks++;
   await page.close();
  }
  console.log(`Point card UI: ${checks} checks passed; desktop/mobile, mocked API only.`);
 } finally { await browser.close(); }
})().catch(e => { console.error(e); process.exitCode = 1; });
