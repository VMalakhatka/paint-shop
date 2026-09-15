const {chromium} = require('playwright');
const {execFileSync} = require('child_process');
const assert = require('assert');
const path = require('path');
const html = execFileSync(process.env.PHP_BINARY || 'php', [path.join(__dirname, 'accounting-price-schedule-ui.php')], {encoding:'utf8'});
(async () => {
  const browser = await chromium.launch({headless:true, ...(process.env.CHROME_BINARY ? {executablePath:process.env.CHROME_BINARY} : {})});
  try {
    const page = await browser.newPage();
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    let failDirectory = false;
    await page.route('https://fixture.local/**', async route => {
      if (!route.request().url().endsWith('/api')) return route.fulfill({contentType:'text/html',body:html});
      const form = await new Response(route.request().postDataBuffer(), {headers:{'content-type':route.request().headers()['content-type']}}).formData();
      assert.equal(form.get('operation'), 'warehouses');
      await route.fulfill({contentType:'application/json',body:JSON.stringify({success:true,data:failDirectory ? {httpStatus:503,body:{message:'Unavailable'}} : {httpStatus:200,body:{items:[{id:1,name:'Киев'},{id:5,name:'Одесса'},{id:7,name:'Киев ОПТ'},{id:15,name:'Одесса Хранение'}]}}})});
    });
    for (const width of [1440, 390]) {
      await page.setViewportSize({width,height:1000});
      await page.goto('https://fixture.local/');
      await page.waitForFunction(() => document.querySelectorAll('.lps-ap-warehouse-row').length === 5);
      const selected = () => page.locator('[name="warehouse_ids[]"]:checked').evaluateAll(nodes => nodes.map(n => Number(n.value)));
      assert.deepEqual(await selected(), [7,1,99]);
      assert.equal(await page.locator('[name="warehouse_positions[7]"]').inputValue(), '1');
      assert.equal(await page.locator('[name="warehouse_positions[99]"]').inputValue(), '3');
      assert(await page.locator('[name="warehouse_positions[5]"]').isDisabled());
      await page.locator('[name="warehouse_ids[]"][value="5"]').check();
      await page.locator('[name="warehouse_positions[5]"]').fill('1');
      await page.locator('[name="warehouse_positions[7]"]').fill('2');
      await page.locator('[name="warehouse_positions[1]"]').fill('3');
      await page.locator('[name="warehouse_ids[]"][value="99"]').uncheck();
      await page.locator('#lps-ap-every-day').click();
      assert.equal(await page.locator('[name="weekdays[]"]:checked').count(), 7);
      await page.locator('[name="weekdays[]"][value="sun"]').uncheck();
      assert(await page.locator('#lps-ap-every-day').evaluate(n => n.indeterminate));
      const form = await page.locator('#schedule-form').evaluate(n => Array.from(new FormData(n).entries()));
      assert(!form.some(([k]) => k === 'warehouse_positions[99]'));
      assert.equal(form.find(([k]) => k === 'warehouse_positions[5]')[1], '1');
      assert.deepEqual(form.filter(([k]) => k === 'weekdays[]').map(([,v]) => v), ['mon','tue','wed','thu','fri','sat']);
      await page.screenshot({path:path.join(require('os').tmpdir(),`accounting-schedule-${width}.png`),fullPage:true});
      assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), 'No horizontal page overflow');
    }
    failDirectory = true;
    await page.goto('https://fixture.local/');
    await page.waitForFunction(() => document.querySelector('#lps-ap-warehouse').hidden === true);
    assert.equal(await page.locator('.lps-ap-warehouse-row').count(), 3);
    assert.equal(await page.locator('[name="warehouse_positions[7]"]').inputValue(), '1');
    assert.deepEqual(errors, []);
    console.log('PASS: actual schedule controls, preserved queue, missing IDs, order inputs, daily/weekdays, API failure, desktop/mobile');
  } finally { await browser.close(); }
})().catch(error => {console.error(error);process.exit(1);});
