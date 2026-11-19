/* Manager status and expedite regression using Chromium and a fully mocked local order. */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {chromium} = require(process.env.PLAYWRIGHT_MODULE || 'playwright');

(async () => {
    const browser = await chromium.launch({channel: 'chrome', headless: true});
    try {
        const page = await browser.newPage();
        const journal = [];
        const slug = 'http://paint.test/order?order_id=42';
        const script = fs.readFileSync(path.join(__dirname, '../assets/manager.js'), 'utf8');
        const queued = step => '<div data-quick-active><button data-quick-run="' + step + '">Run now</button></div>';
        const form = '<form data-pcoe-manager><input name="operation" value="quick_order"><select name="warehouse_mode"><option>auto</option></select><select name="warehouse_id"></select><button>Create</button></form>';
        const section = html => '<div data-quick-progress data-order="42" data-customer="17" data-key="test-key">' + html + '</div><p data-quick-feedback></p>';
        let state = 'draft';
        let loseResponse = false;
        let loads = 0;
        await page.route('**/*', async route => {
            const req = route.request();
            if (req.isNavigationRequest()) {
                loads++;
                return route.fulfill({contentType:'text/html', body: '<main class="pcoe-manager"><div id="pcoe-manager-status"></div>' +
                    (state === 'draft' ? form : section(queued(state))) + '</main><script>window.pcoeManager={url:"/ajax",nonce:"test",error:"Check status"};</script><script>' + script + '</script>'});
            }
            if (!req.url().endsWith('/ajax')) return route.abort();
            const data = req.postData() || '';
            if (data.includes('quick_order') && data.includes('Content-Disposition')) {
                journal.push('create');
                state = 'queued';
                return route.fulfill({json:{success:true,data:{url:slug + '#pcoe-quick-order'}}});
            }
            const body = new URLSearchParams(data);
            assert.equal(body.get('order_id'),'42');
            assert.equal(body.get('customer_id'),'17');
            assert.equal(body.get('operation_key'),'test-key');
            const op = body.get('operation');
            journal.push(op);
            if (op === 'quick_run') {
                assert.equal(body.get('step'),state);
                state = state === 'queued' ? 'approvals-0' : 'complete';
                if (loseResponse) return route.abort();
            } else assert.equal(op,'quick_status');
            return route.fulfill({json:{success:true,data:{html:state === 'complete' ? '<p>Invoices ready</p>' : queued(state),processed:op === 'quick_run'}}});
        });
        await page.goto(slug + '#pcoe-quick-order');
        await page.getByRole('button',{name:'Create'}).click();
        await page.getByRole('button',{name:'Run now'}).waitFor();
        assert.equal(loads,2,'Same-page create reloads the journal instead of leaving the draft form open');
        await page.waitForFunction(() => document.querySelector('[data-quick-run]')?.dataset.quickRun === 'queued');
        await page.waitForResponse(r=>r.url().endsWith('/ajax') && r.request().postData().includes('quick_status'));
        assert.deepEqual(journal,['create','quick_status'],'Automatic refresh is read-only');
        await page.getByRole('button',{name:'Run now'}).click();
        await page.getByText('Invoices ready').waitFor();
        assert.equal(journal.filter(x=>x==='quick_run').length,2,'One click handles the selected document and mail steps');
        state='queued'; loseResponse=true;
        await page.reload();
        await page.getByRole('button',{name:'Run now'}).click();
        await page.waitForFunction(()=>document.querySelector('[data-quick-run]')?.dataset.quickRun === 'approvals-0');
        assert.equal(journal.filter(x=>x==='quick_run').length,3,'Lost response triggers only a status read');
        console.log('PASS: same-page create, read-only polling, expedited steps and recovery after lost response');
    } finally {
        await browser.close();
    }
})().catch(error => {console.error(error); process.exitCode=1;});
