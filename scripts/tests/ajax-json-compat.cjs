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
const cartBase = '/shop/?wc-ajax=';
const cartUrls = ['add_to_cart','remove_from_cart','get_refreshed_fragments'].map(action => cartBase + action);
const jqueryPath = process.env.WP_JQUERY || path.resolve(__dirname, '../../wp-includes/js/jquery/jquery.js');
const jquery = readFileSync(jqueryPath);
const wooJs = path.resolve(path.dirname(jqueryPath), '../../../wp-content/plugins/woocommerce/assets/js');
const assets = {
    '/woo-cart.js': readFileSync(path.join(wooJs, 'frontend/add-to-cart.js')),
    '/blockui.js': readFileSync(path.join(wooJs, 'jquery-blockui/jquery.blockUI.js')),
    '/catalog.js': readFileSync(path.resolve(__dirname, '../../wp-content/plugins/paint-core/assets/js/catalog-qty.js')),
};
const adapter = readFileSync(path.resolve(__dirname, '../../wp-content/mu-plugins/lavka-ajax-json-compat/client.js'));
const checkout = readFileSync(path.join(wooJs, 'frontend/checkout.js'));
const placeButton = readFileSync(path.join(wooJs, 'frontend/utils/custom-place-order-button.js'));
const mobile = readFileSync(path.resolve(__dirname, '../../wp-content/plugins/paint-shop-ux/assets/mobile-cart.js'));
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
        check((await get('/wp-json/')).ok, 'same-site REST JSON');
        check((await get('/custom?problem')).ok, 'JSON subtype');
        await rejects(get(ajax.replace('127.0.0.1', 'localhost')), 'fetch foreign origin unaffected');
        check(JSON.parse(await fetch('/custom').then(r => r.text())).ok, 'manual reader of labelled JSON');
        check((await fetch(ajax + '?invalid').then(r => r.text())).includes('gps-license-footer'), 'preserve malformed text for diagnostics');
        check((await fetch(ajax + '?html').then(r => r.text())).includes('gps-license-footer'), 'HTML body unchanged');
        check(new TextDecoder().decode(await fetch(ajax).then(r => r.arrayBuffer())).includes('gps-license-footer'), 'raw bytes unchanged');
        check((await get(ajax + '?embedded')).text.includes('gps-license-footer'), 'marker inside JSON preserved');
        check((await jQuery.post(ajax, {action:'example'})).ok, 'implicit jQuery JSON');
        check((await jQuery.ajax({url:ajax, dataType:'json'})).ok, 'explicit jQuery JSON');
        check((await jQuery.ajax({url:ajax, dataType:'text'})).includes('gps-license-footer'), 'jQuery text unchanged');
        await rejects(jQuery.getJSON(ajax + '?invalid'), 'jQuery invalid JSON');
        check((await jQuery.getJSON('/wp-json/')).ok, 'same-site jQuery REST JSON');
        await rejects(jQuery.getJSON('/external-redirect'), 'jQuery foreign final response');
        await rejects(get('/external-redirect'), 'fetch foreign final response');
        await rejects(jQuery.getJSON(ajax + '?html'), 'jQuery HTML not accepted');
        check((await jQuery.ajax({url:ajax, dataType:'json', converters:{'text json':v => v}})).includes('gps-license-footer'), 'custom converter untouched');
        await new Promise(resolve => jQuery(resolve));
        const button = document.querySelector('.ajax_add_to_cart');
        const added = new Promise(resolve => jQuery(document.body).one('added_to_cart', () => resolve()));
        button.click();
        check(button.classList.contains('loading'), 'real Woo button starts loading');
        await added;
        check(!button.classList.contains('loading') && button.textContent === '(1)', 'catalog count and spinner after one click');
        check(document.querySelector('#mini-count').textContent === '1', 'mini-cart updated');
        const wc = '/shop/?wc-ajax=';
        check((await get(wc + 'get_refreshed_fragments')).cart_hash === '1', 'cart refresh fetch');
        check((await jQuery.post(wc + 'remove_from_cart')).cart_hash === '0', 'remove cart item');
        check((await get('/other/?wc-ajax=example')).ok, 'new same-origin endpoint needs no allowlist');
        result.textContent = 'PASS';
    } catch (e) { result.textContent = 'FAIL: ' + e; }
}

test('WordPress JSON readers, catalog, classic checkout and mobile cart', {timeout:120000}, async () => {
    let qty = 0, adds = 0, submissions = 0;
    const reviewed = [];
    const server = http.createServer(async (req, res) => {
        const url = new URL(req.url, 'http://127.0.0.1');
        if (url.pathname === '/' && url.search === '?checkout') {
            res.setHeader('Content-Type', 'text/html');
            return res.end('<output>WAIT</output><form class="checkout"><select class="shipping_method" name="shipping_method[0]" data-index="0"><option>pickup</option><option>delivery</option></select><div class="woocommerce-checkout-review-order-table"></div><div class="woocommerce-checkout-payment"></div></form>' +
                '<script>window.addEventListener("error",e=>document.querySelector("output").textContent="FAIL: "+e.message);window.wc_checkout_params={is_checkout:"1",wc_ajax_url:"/shop/?wc-ajax=%%endpoint%%",update_order_review_nonce:"fixture"}</script>' +
                '<script src="/jquery.js"></script><script src="/adapter.js"></script><script src="/blockui.js"></script>' +
                '<script>jQuery.blockUI.defaults.fadeOut=0;const totals=()=>document.querySelector(".woocommerce-checkout-review-order-table").textContent;' +
                'function verify(){if(totals()==="delivery" && !document.querySelector(".blockOverlay"))document.querySelector("output").textContent="PASS";}' +
                'new MutationObserver(verify).observe(document.querySelector("form.checkout"),{subtree:true,childList:true});' +
                'jQuery(document.body).on("updated_checkout",function(){if(totals()==="pickup"){jQuery("select.shipping_method").val("delivery").trigger("change");}else verify();});</script>' +
                '<script src="/place-button.js"></script><script src="/checkout.js"></script>');
        }
        if (url.pathname === '/' && url.search === '?mobile') {
            res.setHeader('Content-Type','text/html');
            res.setHeader('Set-Cookie','woocommerce_items_in_cart=1; Path=/');
            return res.end('<meta name="viewport" content="width=device-width"><output>WAIT</output><div id="psu-mobile-cart" data-count="0" hidden></div>' +
                '<script>window.psuMobileCart={url:"/shop/?wc-ajax=psu_mobile_cart"}</script>' +
                '<script src="/adapter.js"></script><script src="/jquery.js"></script>' +
                '<script>new MutationObserver(()=>{const bar=document.querySelector("#psu-mobile-cart");if(!bar.hidden && bar.dataset.count==="1" && bar.querySelector("a").pathname==="/cart")document.querySelector("output").setAttribute("data-ready","yes");}).observe(document.body,{subtree:true,childList:true});</script>' +
                '<script src="/mobile.js"></script>');
        }
        if (url.pathname === '/') {
            res.setHeader('Content-Type', 'text/html; charset=utf-8');
            const scripts = url.search === '?early' ? ['/jquery.js','/adapter.js'] : ['/adapter.js','/jquery.js'];
            return res.end('<output>WAIT</output><div class="products"><div class="product"><button class="add_to_cart_button ajax_add_to_cart" data-product_id="22502" data-quantity="1"></button></div></div><span id="mini-count">0</span>' +
                '<script>window.wc_add_to_cart_params={wc_ajax_url:"/shop/?wc-ajax=%%endpoint%%",cart_url:"/cart",i18n_view_cart:"Cart"}</script>' +
                scripts.map(src => '<script src="' + src + '"></script>' + (src === '/jquery.js' ? '<script>if(window.lavkaAttachAjaxJson)lavkaAttachAjaxJson(jQuery)</script>' : '')).join('') +
                Object.keys(assets).map(src => '<script src="' + src + '"></script>').join('') +
                '<script>(' + browserTest + ')(location.origin+' + JSON.stringify(ajax) + ')</script>');
        }
        if (['/mobile.js','/checkout.js','/place-button.js'].includes(url.pathname)) {
            res.setHeader('Content-Type','text/javascript');
            return res.end({'/mobile.js':mobile,'/checkout.js':checkout,'/place-button.js':placeButton}[url.pathname]);
        }
        if (url.pathname === '/external-redirect') {
            res.writeHead(302, {Location:'http://localhost:'+server.address().port+'/redirected'});
            return res.end();
        }
        if (Object.hasOwn(assets, url.pathname)) {
            res.setHeader('Content-Type','text/javascript');
            return res.end(assets[url.pathname]);
        }
        if (['/jquery.js','/adapter.js'].includes(url.pathname)) {
            res.setHeader('Content-Type', 'text/javascript');
            return res.end(url.pathname === '/adapter.js' ? adapter : jquery);
        }
        res.setHeader('Access-Control-Allow-Origin','*');
        res.setHeader('Content-Type',url.search === '?html' ? 'text/html' : url.search === '?problem' ? 'application/problem+json' : 'application/json; charset=utf-8');
        if (url.searchParams.get('wc-ajax') === 'checkout') submissions++;
        if (url.searchParams.get('wc-ajax') === 'update_order_review') {
            let body = '';
            for await (const part of req) body += part;
            const method = new URLSearchParams(body).get('shipping_method[0]');
            reviewed.push(method);
            const fragments = {'.woocommerce-checkout-review-order-table':'<div class="woocommerce-checkout-review-order-table">'+method+'</div>',
                '.woocommerce-checkout-payment':'<div class="woocommerce-checkout-payment"></div>'};
            return res.end(JSON.stringify({result:'success',fragments})+'\n'+notice);
        }
        if (url.searchParams.get('wc-ajax') === 'psu_mobile_cart') {
            return res.end(JSON.stringify({html:'<div id="psu-mobile-cart" data-count="1" hidden><a href="/cart">Cart</a></div>'})+'\n'+notice);
        }
        if (cartUrls.includes(url.pathname + url.search)) {
            if (req.method === 'POST' && url.searchParams.get('wc-ajax') === 'add_to_cart') { qty++; adds++; }
            if (req.method === 'POST' && url.searchParams.get('wc-ajax') === 'remove_from_cart') qty = 0;
            return res.end(JSON.stringify({cart_hash:String(qty),fragments:{'#mini-count':'<span id="mini-count">'+qty+'</span>'}})+'\n'+notice+'\n');
        }
        if (url.search === '?denied') res.statusCode = 403;
        res.end(({
            '?clean':'{"ok":true}', '?invalid':'{broken}\n'+notice, '?other':'{}\n<div>other</div>',
            '?extra':dirty+'warning', '?double':dirty+notice, '?embedded':JSON.stringify({text:notice}),
            '?denied':'{"success":false}\n'+notice,
        })[url.search] || dirty);
    });
    await new Promise(resolve => server.listen(0,'127.0.0.1',resolve));
    try {
        for (const order of ['early','late','checkout','mobile']) {
            adds = 0;
            const profile = mkdtempSync(path.join(tmpdir(),'lavka-json-'));
            try {
                const {stdout} = await promisify(execFile)(process.env.CHROME_BIN || 'chromium', [
                    '--headless','--disable-gpu','--disable-background-networking','--no-first-run',
                    '--no-proxy-server','--user-data-dir='+profile,'--dump-dom','--virtual-time-budget=20000',
                    '--window-size='+(order === 'mobile' ? '390,844' : '1280,800'),
                    'http://127.0.0.1:'+server.address().port+'/?'+order,
                ], {timeout:25000});
                if (order === 'mobile') assert.match(stdout, /<output data-ready="yes">/);
                else assert.equal(stdout.match(/<output>(.*?)<\/output>/s)?.[1], 'PASS',
                    order + ' ' + JSON.stringify(reviewed) + ' ' + (stdout.match(/<form [\s\S]+?<\/form>/)?.[0] || ''));
                assert.equal(adds, ['early','late'].includes(order) ? 1 : 0, 'no added request without click');
                assert.equal(qty, 0, 'cart empty after removal');
            } finally { rmSync(profile,{recursive:true,force:true,maxRetries:3}); }
        }
        assert.deepEqual(reviewed, ['pickup','delivery']);
        assert.equal(submissions, 0, 'no checkout submitted');
    } finally { server.close(); }
});
