// Run with CHROME_BIN and WP_JQUERY pointing to Chromium and WordPress jquery.js.
const { readFileSync, mkdtempSync, rmSync } = require('node:fs');
const { tmpdir } = require('node:os');
const path = require('node:path');
const http = require('node:http');
const { execFile } = require('node:child_process');
const { promisify } = require('node:util');
const assert = require('node:assert/strict');
const test = require('node:test');

const ajax = '/shop/wp-admin/admin-ajax.php';
const jquery = readFileSync(process.env.WP_JQUERY || path.resolve(__dirname, '../../wp-includes/js/jquery/jquery.js'));
const adapter = readFileSync(path.resolve(__dirname, '../../wp-content/mu-plugins/lavka-ajax-json-compat/client.js'));
const notice = '<!-- GetPageSpeed unlicensed notice -->\n<div id="gps-license-footer" style="position:fixed;bottom:0;left:0;right:0;z-index:2147483647;box-sizing:border-box;width:100%;background:#1f2937;color:#e5e7eb;font:13px/1.5 Tahoma,Verdana,Arial,sans-serif;text-align:center;padding:6px 12px;opacity:.96">Patched PHP for CentOS 7 by GetPageSpeed - free for personal and evaluation use. <a href="https://www.getpagespeed.com/repo-subscribe#ultra-annual" style="color:#7dd3fc;text-decoration:underline">Commercial use requires a subscription</a></div>';
const dirty = '{"ok":true}\n' + notice + '\n';

async function browserTest(ajax) {
    const result = document.querySelector('output');
    const check = (ok, name) => { if (!ok) throw Error(name); };
    const get = url => fetch(url).then(response => response.json());
    async function rejects(operation, name) {
        let failed = false;
        try { await operation; } catch (_) { failed = true; }
        check(failed, name);
    }
    try {
        check((await get(ajax)).ok && (await get(ajax + '?clean')).ok, 'fetch dirty/clean');
        const error = await fetch(ajax + '?denied');
        check(error.status === 403 && (await error.json()).success === false, 'HTTP error status and envelope');
        await rejects(error.json(), 'body cannot be read twice');
        for (const query of ['?other', '?extra', '?invalid', '?html', '?double']) {
            await rejects(get(ajax + query), 'must not accept ' + query);
        }
        await rejects(get('/wp-json/'), 'fetch REST unaffected');
        await rejects(get(ajax.replace('127.0.0.1', 'localhost')), 'fetch foreign origin unaffected');
        check((await fetch(ajax).then(r => r.text())).includes('gps-license-footer'), 'raw body remains intact');
        check((await get(ajax + '?embedded')).text.includes('gps-license-footer'), 'marker inside JSON preserved');
        check((await jQuery.post(ajax, {action:'example'})).ok, 'implicit jQuery JSON');
        check((await jQuery.ajax({url:ajax, dataType:'json'})).ok, 'explicit jQuery JSON');
        check((await jQuery.ajax({url:ajax, dataType:'text'})).includes('gps-license-footer'), 'jQuery text unchanged');
        await rejects(jQuery.getJSON(ajax + '?invalid'), 'jQuery invalid JSON');
        await rejects(jQuery.getJSON('/wp-json/'), 'jQuery REST unaffected');
        await rejects(jQuery.getJSON(ajax + '?html'), 'jQuery HTML not accepted');
        check((await jQuery.ajax({url:ajax, dataType:'json', converters:{'text json':v => v}})).includes('gps-license-footer'), 'custom converter untouched');
        result.textContent = 'PASS';
    } catch (e) { result.textContent = 'FAIL: ' + e; }
}

test('WordPress jQuery and native Fetch, in both script loading orders', {timeout:60000}, async () => {
    const server = http.createServer((req, res) => {
        const url = new URL(req.url, 'http://127.0.0.1');
        if (url.pathname === '/') {
            res.setHeader('Content-Type', 'text/html; charset=utf-8');
            const scripts = url.search === '?early' ? ['/jquery.js','/adapter.js'] : ['/adapter.js','/jquery.js'];
            return res.end('<output>WAIT</output><script>window.lavkaAjaxJsonEndpoint=' + JSON.stringify(ajax) + '</script>' +
                scripts.map(src => '<script src="' + src + '"></script>' + (src === '/jquery.js' ? '<script>if(window.lavkaAttachAjaxJson)lavkaAttachAjaxJson(jQuery)</script>' : '')).join('') +
                '<script>(' + browserTest + ')(location.origin+' + JSON.stringify(ajax) + ')</script>');
        }
        if (['/jquery.js','/adapter.js'].includes(url.pathname)) {
            res.setHeader('Content-Type', 'text/javascript');
            return res.end(url.pathname === '/adapter.js' ? adapter : jquery);
        }
        res.setHeader('Access-Control-Allow-Origin','*');
        res.setHeader('Content-Type',url.search === '?html' ? 'text/html' : 'application/json; charset=utf-8');
        if (url.search === '?denied') res.statusCode = 403;
        res.end(({
            '?clean':'{"ok":true}', '?invalid':'{broken}\n'+notice, '?other':'{}\n<div>other</div>',
            '?extra':dirty+'warning', '?double':dirty+notice, '?embedded':JSON.stringify({text:notice}),
            '?denied':'{"success":false}\n'+notice,
        })[url.search] || dirty);
    });
    await new Promise(resolve => server.listen(0,'127.0.0.1',resolve));
    try {
        for (const order of ['early','late']) {
            const profile = mkdtempSync(path.join(tmpdir(),'lavka-json-'));
            try {
                const {stdout} = await promisify(execFile)(process.env.CHROME_BIN || 'chromium', [
                    '--headless','--disable-gpu','--disable-background-networking','--no-first-run',
                    '--no-proxy-server','--user-data-dir='+profile,'--dump-dom','--virtual-time-budget=20000',
                    'http://127.0.0.1:'+server.address().port+'/?'+order,
                ], {timeout:25000});
                assert.equal(stdout.match(/<output>(.*?)<\/output>/s)?.[1], 'PASS', order);
            } finally { rmSync(profile,{recursive:true,force:true,maxRetries:3}); }
        }
    } finally { server.close(); }
});
