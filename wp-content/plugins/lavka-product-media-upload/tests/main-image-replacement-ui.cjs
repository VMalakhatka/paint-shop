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
                window.wp = { media: () => {
                    let selected;
                    return {
                        on: (event, cb) => { if (event === 'select') selected = cb; },
                        open: () => selected(),
                        state: () => ({ get: () => ({ first: () => ({ toJSON: () => ({
                            id: 77, filename: 'library-scaled.jpg', url: 'https://example.org/library-scaled.jpg'
                        }) }) }) })
                    };
                } };
                window.fetch = async (url, options) => {
                    const action = options.body.get('action');
                    window.calls.push({ action, choices: options.body.get('main_choices'), rowActions: options.body.get('row_actions') });
                    if (window.failUpload && action === 'lpmu_upload_batch') {
                        return { status: 400, text: async () => JSON.stringify({ success: false, data: { message: 'Fixture refusal' } }) };
                    }
                    const rows = [true, false].map((canKeep, i) => ({
                        row_number: i + 2, sku: 'EXAMPLE-' + i, role: 'main', product_id: i + 1,
                        product_name: i ? 'Варіація товару' : 'Фарба олійна', source_file: 'new.png', canonical_file: 'new.png',
                        library_candidates: [{ id: 21, name: 'existing.jpg', url: 'https://example.org/existing.jpg' }],
                        valid: true, status: 'READY', errors: [], warnings: [],
                        main_replacement: { attachment_id: i + 12, can_keep: canKeep, url: '' }
                    }));
                    if (action === 'lpmu_upload_batch') {
                        rows[0].status = 'SUCCESS';
                        rows[1].status = 'NAME_CONFLICT_S3';
                        rows[1].valid = false;
                        delete rows[0].main_replacement;
                        delete rows[1].main_replacement;
                    }
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
            const tools = page.locator('.lpmu-row-tools');
            const first = tools.nth(0), second = tools.nth(1);
            const preview = first.locator('a').first();
            assert.equal(await preview.getAttribute('target'), '_blank');
            assert.equal(await preview.getAttribute('href'), 'https://example.org/existing.jpg');
            await first.locator('summary').click();
            await first.locator('[data-filename]').fill('kr-16416-front.png');
            assert.ok(await page.locator('#lpmu-upload').isDisabled());
            await second.locator('[data-choose]').click();
            assert.match(await second.locator('[data-choice]').innerText(), /#21/);
            await second.locator('[data-library]').click();
            assert.match(await second.locator('[data-choice]').innerText(), /#77/);
            await second.locator('[data-recheck]').click();
            const choices = await page.evaluate(() => JSON.parse(window.calls.at(-1).rowActions));
            assert.deepEqual(choices, { 2: { filename: 'kr-16416-front.png' }, 3: { attachment: 77 } });
            assert.ok(await page.locator('#lpmu-upload').isEnabled());
            await first.locator('[data-reset]').click();
            await first.locator('[data-recheck]').click();
            assert.deepEqual(await page.evaluate(() => JSON.parse(window.calls.at(-1).rowActions)), { 3: { attachment: 77 } });
            await page.locator('#lpmu-registry').setInputFiles({ name: 'other.xlsx', mimeType: 'application/octet-stream', buffer: Buffer.from('other') });
            await page.locator('#lpmu-check').click();
            assert.deepEqual(await page.evaluate(() => JSON.parse(window.calls.at(-1).rowActions)), {});
            await page.evaluate(() => { window.failUpload = false; });
            await selects.nth(0).selectOption('gallery');
            await selects.nth(1).selectOption('remove');
            await page.locator('#lpmu-upload').click();
            await page.waitForFunction(() => document.querySelector('#lpmu-upload-state').classList.contains('is-complete'));
            assert.equal(await first.locator('[data-filename]').count(), 0);
            assert.ok(await second.locator('[data-filename]').isVisible());
            await second.locator('[data-filename]').fill('retry-front.png');
            await second.locator('[data-recheck]').click();
            assert.deepEqual(await page.evaluate(() => JSON.parse(window.calls.at(-1).rowActions)), { 3: { filename: 'retry-front.png' } });
            assert.deepEqual(errors, []);
            await page.close();
        }
        console.log('PASS: desktop/mobile explicit decisions, variation restriction, request payload, error and recheck states');
    } finally { await browser.close(); }
})().catch(e => { console.error(e); process.exitCode = 1; });
