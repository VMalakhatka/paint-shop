// Offline browser regression: actual admin.js/CSS, mocked AJAX, no site or storage writes.
const { chromium } = require('playwright');
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const { execFileSync } = require('node:child_process');
const plugin = path.resolve(__dirname, '..');
const php = fs.readFileSync(path.join(plugin, 'inc/class-plugin.php'), 'utf8');
const translations = JSON.parse(execFileSync('python3', ['-c',
    'import gettext,json,sys; t=gettext.GNUTranslations(open(sys.argv[1],"rb")); print(json.dumps(t._catalog))',
    path.join(plugin, 'languages/lavka-product-media-upload-uk.mo')], { encoding: 'utf8' }));
const strings = {};
for (const m of php.matchAll(/'([^']+)' => __\('([^']+)', 'lavka-product-media-upload'\)/g)) {
    strings[m[1]] = translations[m[2]] || m[2];
}
(async () => {
    const browser = await chromium.launch({ headless: true, channel: process.env.BROWSER_CHANNEL || undefined });
    try {
        for (const width of [1440, 390]) {
            const page = await browser.newPage({ viewport: { width, height: 1000 } });
            const errors = [];
            page.on('pageerror', e => errors.push(e.message));
            await page.route('**/*', route => route.abort());
            await page.setContent(`<html lang="uk"><meta charset="utf-8"><style>body{font:14px Arial;padding:16px;color:#233}td,th{padding:10px;border-bottom:1px solid #ddd;vertical-align:top}button{padding:10px;margin:10px}</style>
                <input type="file" id="lpmu-registry"><input type="file" id="lpmu-images" multiple>
                <input type="checkbox" id="lpmu-legacy-main"><input type="checkbox" id="lpmu-generate-names">
                <div id="lpmu-drop-zone"></div><span id="lpmu-file-count"></span>
                <button id="lpmu-check">Перевірити без запису</button><button id="lpmu-upload">Завантажити</button>
                <div id="lpmu-upload-state"></div><a id="lpmu-report-link"></a><span id="lpmu-spinner"></span>
                <div id="lpmu-summary"></div><div id="lpmu-results"></div></html>`);
            await page.addStyleTag({ path: path.join(plugin, 'assets/admin.css') });
            await page.evaluate(strings => {
                window.LPMU_DATA = { strings, ajaxUrl: '/mock', nonce: 'fixture' };
                window.calls = []; window.alerts = []; window.failUpload = false;
                window.alert = text => window.alerts.push(text);
                window.confirm = () => true;
                window.fetch = async (url, options) => {
                    const action = options.body.get('action');
                    window.calls.push({ action, choices: options.body.get('main_choices') });
                    if (window.failUpload && action === 'lpmu_upload_batch') {
                        return { status: 400, text: async () => JSON.stringify({ success: false, data: { message: 'Fixture refusal' } }) };
                    }
                    const rows = [true, false].map((canKeep, i) => ({
                        row_number: i + 2, sku: 'EXAMPLE-' + i, role: 'main', product_id: i + 1,
                        product_name: i ? 'Варіація товару' : 'Фарба олійна', source_file: 'new.png', canonical_file: 'new.png',
                        valid: true, status: 'READY', errors: [], warnings: [],
                        main_replacement: { attachment_id: i + 12, can_keep: canKeep, url: '' }
                    }));
                    return { status: 200, text: async () => JSON.stringify({ success: true, data: {
                        batch_token: 'checked', can_upload: true, summary: { total: 2, ready: 2 }, rows,
                        capabilities: { s3_index_check: true }
                    } }) };
                };
            }, strings);
            await page.addScriptTag({ path: path.join(plugin, 'assets/admin.js') });
            await page.locator('#lpmu-registry').setInputFiles({ name: 'fixture.xlsx', mimeType: 'application/octet-stream', buffer: Buffer.from('fixture') });
            await page.locator('#lpmu-images').setInputFiles({ name: 'new.png', mimeType: 'image/png', buffer: Buffer.from('fixture') });
            await page.locator('#lpmu-check').click();
            await page.waitForSelector('.lpmu-main-choice');
            const selects = page.locator('.lpmu-main-choice');
            assert.equal(await selects.nth(0).inputValue(), '');
            assert.equal(await selects.nth(0).locator('option').count(), 3);
            assert.equal(await selects.nth(1).locator('option').count(), 2);
            await page.locator('#lpmu-upload').click();
            assert.equal(await page.evaluate(() => window.calls.length), 1, 'Missing choice must prevent upload request');
            await selects.nth(0).selectOption('gallery');
            await page.locator('#lpmu-upload').click();
            assert.equal(await page.evaluate(() => window.calls.length), 1, 'Every replacement needs its own choice');
            await selects.nth(1).selectOption('remove');
            await page.screenshot({ path: '/private/tmp/main-photo-choice-' + width + '.png', fullPage: true });
            await page.evaluate(() => { window.failUpload = true; });
            await page.locator('#lpmu-upload').click();
            await page.waitForFunction(() => document.getElementById('lpmu-summary').textContent.includes('Fixture refusal'));
            const submitted = await page.evaluate(() => window.calls.at(-1));
            assert.deepEqual(JSON.parse(submitted.choices), { '2': 'gallery', '3': 'remove' });
            assert.equal(await selects.nth(0).inputValue(), 'gallery', 'Refusal keeps operator selection');
            await page.locator('#lpmu-check').click();
            await page.waitForFunction(() => document.querySelector('.lpmu-main-choice').value === '');
            assert.deepEqual(errors, []);
            await page.close();
        }
        console.log('PASS: desktop/mobile explicit decisions, variation restriction, request payload, error and recheck states');
    } finally { await browser.close(); }
})().catch(e => { console.error(e); process.exitCode = 1; });
