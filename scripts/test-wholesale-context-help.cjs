/* Synthetic browser test: no account, network, orders or payments. */
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const root = path.resolve(__dirname, '..');
const asset = path.join(root, 'wp-content/mu-plugins/pc-wholesale-help/assets');
const source = fs.readFileSync(path.join(asset, 'context-help.js'), 'utf8');
const php = fs.readFileSync(path.join(root, 'wp-content/mu-plugins/pc-wholesale-help.php'), 'utf8') +
    fs.readFileSync(path.join(root, 'wp-content/mu-plugins/pc-wholesale-help/messages-guide.php'), 'utf8');
for (const match of source.matchAll(/, '([a-z-]+)'\]/g)) {
    assert(php.includes('id="' + match[1] + '"'), 'Missing anchor: ' + match[1]);
}
(async () => {
    const browser = await chromium.launch({headless:true, channel:process.env.CHROME_CHANNEL || 'chrome'});
    try {
        const page = await browser.newPage();
        await page.route('**/*', route => route.abort());
        await page.setContent('<html lang="uk"><body><main style="max-width:900px;margin:auto;font:16px Arial"><h2 id="pc-folio-documents-title">Документи ФОЛІО</h2><form data-pc-documents-form><button>Показати документи</button></form><div id="dynamic"></div></main></body></html>');
        await page.addStyleTag({content:fs.readFileSync(path.join(asset,'wholesale-help.css'),'utf8')});
        await page.addStyleTag({content:fs.readFileSync(path.join(root,'wp-content/mu-plugins/pc-folio-customer-balance/assets/customer-documents.css'),'utf8')});
        await page.evaluate(() => {window.pcWholesaleContextHelp={url:'https://example.invalid/help/',label:'? Як це працює',newTab:'Відкриває інструкцію в новій вкладці'};});
        await page.addScriptTag({content:source});
        assert.equal(await page.locator('.pc-context-help').count(), 2);
        await page.evaluate(() => {document.querySelector('#dynamic').innerHTML='<section class="pc-folio-documents__invoice"><h4>Рахунок на оплату</h4><form class="pc-folio-documents__invoice-form"><button type="button">Рахунок XLSX</button><input type="email" value="example@example.invalid"><button type="submit">Надіслати на email</button></form></section>';});
        await page.waitForFunction(() => document.querySelectorAll('.pc-context-help').length === 5);
        assert.equal(await page.locator('button[type=submit] + a').getAttribute('href'),'https://example.invalid/help/#folio-invoice-email');
        await page.evaluate(() => {document.querySelector('#dynamic').appendChild(document.createElement('p'));});
        await page.waitForTimeout(80);
        assert.equal(await page.locator('.pc-context-help').count(),5,'No duplicate links');
        for (const width of [1100,390]) {
            await page.setViewportSize({width,height:700});
            assert(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth),'No horizontal overflow');
            await page.screenshot({path:`/tmp/wholesale-context-help-${width}.png`,fullPage:true});
        }
        assert.equal(await page.locator('.pc-context-help:not([target="_blank"])').count(),0);
        await page.evaluate(() => {
            document.querySelector('#dynamic').innerHTML='<div class="pcoe-chat"><h2>Повідомлення</h2><section class="pcoe-chat-card"><h3>Telegram</h3><form><input type="hidden" name="action" value="pcoe_telegram"><input type="hidden" name="operation" value="link"><button>Створити одноразове посилання Telegram</button></form></section></div>';
        });
        await page.waitForFunction(() => document.querySelector('.pcoe-chat button + a'));
        assert.equal(await page.locator('.pcoe-chat button + a').getAttribute('href'),'https://example.invalid/help/#telegram-connect');
        await page.evaluate(() => {document.querySelector('.pcoe-chat input[name=operation]').value='unlink'; document.querySelector('.pcoe-chat button + a').remove();});
        await page.waitForFunction(() => document.querySelector('.pcoe-chat button + a')?.hash === '#telegram-disconnect');
        assert.equal(await page.locator('.pcoe-chat button').count(),1,'Help must not replace the action');
        await page.setContent('<article class="pc-wholesale-help"><h2 id="pc-folio-documents-title">Help</h2></article>');
        await page.addScriptTag({content:source});
        assert.equal(await page.locator('.pc-context-help').count(),0,'No links injected into help itself');
        console.log('PASS: anchors, dynamic blocks, deduplication, new-tab links, mobile/desktop overflow and help-page exclusion');
    } finally {await browser.close();}
})().catch(error => {console.error(error);process.exitCode=1;});
