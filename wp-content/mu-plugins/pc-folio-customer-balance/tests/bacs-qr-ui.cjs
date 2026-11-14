/* Requires Playwright, Chrome/Chromium and Python with Pillow + zxing-cpp.
 * Optional: CHROME_BIN, PHP_BIN, PYTHON_BIN. Synthetic data and file:// only.
 */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const {pathToFileURL} = require('node:url');
const {execFileSync} = require('node:child_process');
const {chromium} = require('playwright');

(async () => {
    const output = fs.mkdtempSync(path.join(os.tmpdir(), 'lavka-bank-qr-'));
    const browser = await chromium.launch({headless: true, ...(process.env.CHROME_BIN ? {executablePath: process.env.CHROME_BIN} : {})});
    try {
        const page = await browser.newPage();
        const errors = [], requests = [], images = [];
        page.on('pageerror', error => errors.push(error.message));
        await page.route(/^https?:/, route => { requests.push(route.request().url()); return route.abort(); });
        for (const variant of ['normal', 'max']) {
            const html = path.join(output, variant + '.html');
            fs.writeFileSync(html, execFileSync(process.env.PHP_BIN || 'php', [path.join(__dirname, 'bacs-qr.php'), variant === 'max' ? '--render-max' : '--render']));
            for (const [label, width] of [['desktop', 1280], ['mobile', 390], ['small', 320]]) {
                await page.setViewportSize({width, height: 1100});
                await page.goto(pathToFileURL(html).href);
                await page.locator('[data-payment-qr] svg').waitFor();
                assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth), false, 'No horizontal overflow');
                const url = await page.locator('[data-payment-qr]').getAttribute('data-payment-qr');
                const png = path.join(output, variant + '-' + label + '.png');
                await page.locator('[data-payment-qr]').screenshot({path: png});
                images.push({png, url});
            }
        }
        const noJs = await browser.newPage({javaScriptEnabled: false});
        await noJs.goto(pathToFileURL(path.join(output, 'normal.html')).href);
        assert.equal(await noJs.locator('.pc-bacs-qr__fallback').isVisible(), true);
        assert.equal(await noJs.locator('.pc-bacs-qr__open').isVisible(), true);
        assert.deepEqual(errors, []);
        assert.deepEqual(requests, []);
        fs.writeFileSync(path.join(output, 'images.json'), JSON.stringify(images));
        execFileSync(process.env.PYTHON_BIN || 'python3', ['-c', [
            'import sys,json,zxingcpp',
            'from PIL import Image',
            'for row in json.load(open(sys.argv[1])):',
            ' result=zxingcpp.read_barcode(Image.open(row["png"]))',
            ' assert result is not None and result.text == row["url"], row["png"]',
        ].join('\n'), path.join(output, 'images.json')], {stdio: 'inherit'});
        console.log('PASS: six QR image round trips including maximum payload; desktop/390px/320px, no network/errors/overflow, no-JS fallback.');
        console.log('Synthetic screenshots: ' + output);
    } finally {
        await browser.close();
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
