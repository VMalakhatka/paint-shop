// Offline rendering of the actual PHP panel and assets; AJAX is explicitly mocked.
const { chromium } = require('playwright');
const fs = require('node:fs');
const path = require('node:path');
const { execFileSync } = require('node:child_process');
const assert = require('node:assert/strict');
const plugin = path.resolve(__dirname, '..');
const panel = execFileSync('php', [path.join(__dirname, 'full-sync-jobs.php'), '--render-panel'], {encoding: 'utf8'});
const css = fs.readFileSync(path.join(plugin, 'assets/full-sync-jobs.css'), 'utf8');
const js = fs.readFileSync(path.join(plugin, 'assets/full-sync-jobs.js'), 'utf8');
const wpRoot = process.env.WP_TEST_ROOT || path.resolve(plugin, '../../..');
const jquery = fs.readFileSync(path.join(wpRoot, 'wp-includes/js/jquery/jquery.min.js'), 'utf8');
(async () => {
    const browser = await chromium.launch({headless: true, channel: process.env.PLAYWRIGHT_CHANNEL || undefined});
    try {
        for (const width of [1440, 390, 320]) {
            const page = await browser.newPage({viewport: {width, height: 1000}});
            const errors = [];
            page.on('pageerror', error => errors.push(error.message));
            await page.setContent('<!doctype html><meta charset="utf-8"><style>body{font:14px Arial;margin:20px;background:#f0f0f1;color:#1d2327}h2{font-size:20px}'+css+'</style>'+panel);
            await page.addScriptTag({content: jquery});
            await page.evaluate(() => {
                window.LTS_ADMIN = {ajaxUrl: '/synthetic', nonce: 'test'};
                window.LTS_FULL_JOB = {unavailable: 'Прогрес тимчасово недоступний; завдання не запускається повторно.', job: {
                    jobId: '00000000-0000-4000-8000-000000000001', status: 'RUNNING', label: 'Виконується',
                    chunks: 381, processed: 37736, created: 1, updated: 36800, drafted: 1,
                    errorCount: 0, nextAfter: 'P-141515', safeToRelease: false
                }};
                window.jQuery.ajax = () => window.jQuery.Deferred().resolve({success: true, data: {job: LTS_FULL_JOB.job}}).promise();
            });
            await page.addScriptTag({content: js});
            assert.equal(await page.locator('[data-job-field="updated"]').textContent(), '36800');
            assert.equal(await page.locator('details').getAttribute('open'), null);
            assert.equal(await page.locator('#lts-full-job').isVisible(), true);
            assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
            await page.screenshot({path: '/private/tmp/lts-job-progress-'+width+'.png', fullPage: true});
            await page.evaluate(() => {
                jQuery(document).trigger('lts:job', [{...LTS_FULL_JOB.job, status: 'OUTCOME_UNKNOWN',
                    label: 'Результат запису потребує перевірки', poll_error: 'UNAVAILABLE', nextAfter: 'X'.repeat(191)}]);
            });
            await page.locator('summary').click();
            assert.equal(await page.locator('.lts-job-connection').isVisible(), true);
            assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
            assert.deepEqual(errors, []);
            await page.close();
        }
        process.stdout.write('PASS: actual progress panel at 1440, 390, 320px; updates, diagnostics and connection state\n');
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
