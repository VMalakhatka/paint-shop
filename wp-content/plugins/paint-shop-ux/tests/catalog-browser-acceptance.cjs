const {chromium} = require('playwright');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {execFileSync} = require('node:child_process');

(async () => {
    const base = 'http://paint.local';
    const php = process.env.PSU_TEST_PHP;
    assert(php, 'PSU_TEST_PHP is required for local-only fixture namespace changes');
    const fixtureFile = '/tmp/psu-category-browser-session.json';
    const fixture = JSON.parse(fs.readFileSync(fixtureFile, 'utf8'));
    assert.equal(new URL(fixture.quick_url).origin, base);
    const output = '/tmp/psu-catalog-browser-acceptance';
    fs.mkdirSync(output, {recursive:true,mode:0o700});
    const report = {passed:false, network:[], contexts:[], unavailableRoles:['wholesale','customer','manager'].filter(r=>!fixture.sessions[r])};
    const browser = await chromium.launch({executablePath:'/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',headless:true});
    const openContext = async (width, role) => {
        const context = await browser.newContext({viewport:{width,height:1000}});
        context.setDefaultTimeout(30000); context.setDefaultNavigationTimeout(60000);
        await context.route('**/*', route => {
            const request = route.request(); const url = new URL(request.url());
            const image = request.resourceType() === 'image' && url.hostname === 'kreul-media.s3.gra.io.cloud.ovh.net';
            const localRead = url.origin === base && ['GET','HEAD'].includes(request.method())
                && !url.pathname.endsWith('/wp-cron.php') && !url.searchParams.has('add-to-cart');
            return localRead || image ? route.continue() : route.abort();
        });
        if (role) await context.addCookies(fixture.sessions[role].cookies);
        return context;
    };
    const checkLinks = async (page, quick) => {
        await page.waitForLoadState('domcontentloaded');
        const menu = page.locator('.psu-category-menu');
        await menu.locator('li[data-category] > div > a').first().waitFor({state:'attached'});
        const hrefs = await menu.locator('li[data-category] > div > a').evaluateAll(nodes=>nodes.map(n=>n.href));
        assert(hrefs.length > 0);
        for (const href of hrefs) {
            const url = new URL(href);
            assert.equal(url.origin, base);
            if (quick) {
                assert.equal(url.pathname, new URL(fixture.quick_url).pathname);
                assert(url.searchParams.get('cat'));
            } else {
                assert(url.pathname.startsWith('/category/'), href);
                assert(!url.searchParams.has('cat'), href);
            }
        }
        const ids = await menu.locator('li[data-category]').evaluateAll(nodes=>nodes.map(n=>n.dataset.category));
        assert.equal(ids.length,new Set(ids).size,'No duplicate category rows');
    };
    const expandAndMore = async (page, quick) => {
        const menu = page.locator('.psu-category-menu');
        const toggle = menu.locator('.psu-category-menu__toggle[aria-expanded="false"]').first();
        const id = await toggle.getAttribute('aria-controls');
        const response = page.waitForResponse(r=>r.url().includes('/paint-shop-ux/v1/categories?'));
        await toggle.click();
        const reply = await response; assert.equal(reply.status(),200);
        assert.equal(new URL(reply.url()).searchParams.get('quick_order_page'), String(quick ? fixture.quick_id : 0));
        assert((await reply.json()).items.length <= 30);
        await page.waitForFunction(id=>!document.getElementById(id).hasAttribute('data-unloaded'),id);
        const more = menu.locator(':scope > ul > .psu-category-menu__more button');
        if (await more.count()) {
            const before = await menu.locator(':scope > ul > li[data-category]').count();
            await more.click();
            await page.waitForFunction(n=>document.querySelectorAll('.psu-category-menu > ul > li[data-category]').length>n,before);
        }
        await checkLinks(page,quick);
    };
    try {
        for (const width of [1440,390]) {
            const context = await openContext(width); const page = await context.newPage();
            let phase = 'initial'; const start = Date.now(); const requests = []; const records = new Map();
            page.on('request', request => {
                if (request.resourceType() !== 'document') return;
                const record = {phase,ms:Date.now()-start,url:request.url(),method:request.method(),mainFrame:request.frame()===page.mainFrame(),redirectedFrom:request.redirectedFrom()?.url() || null};
                requests.push(record); records.set(request,record);
            });
            page.on('response', response => { const record=records.get(response.request()); if(record) record.status=response.status(); });
            page.on('requestfailed', request => {const record=records.get(request); if(record) record.failure=request.failure()?.errorText;});
            report.network.push({width,requests});
            const errors=[]; page.on('pageerror',error=>errors.push(error.message));
            const settle = async () => {await page.waitForLoadState('load'); await page.waitForTimeout(1800);};
            const count = name => requests.filter(r=>r.phase===name&&r.mainFrame).length;
            const category = base+'/category/farbi-akrilovi/farbi-akrilovi-kreul-solo-goya-80-ml/';
            await page.goto(category+'?pp=12&in_stock=1&orderby=price'); await settle();
            assert.equal(count('initial'),1,'Exactly one first document, no automatic reload');
            assert.equal(await page.locator('li.type-product').count(),12);
            phase='resize';
            await page.setViewportSize({width:width===390?1440:390,height:1000}); await page.waitForTimeout(2000);
            await page.setViewportSize({width,height:1000}); await page.waitForTimeout(2000);
            assert.equal(count('resize'),0,'No document requests on either resize');
            for (const size of [24,48,12]) {
                if (size===48) {
                    phase='page2'; await page.getByRole('link',{name:'Сторінка 2',exact:true}).first().click(); await settle();
                    assert.equal(count('page2'),1);
                }
                phase='size'+size;
                await page.locator('.psu-per-page').getByRole('link',{name:String(size),exact:true}).click(); await settle();
                assert.equal(count(phase),1,'One document per size selection');
                assert.equal(await page.locator('li.type-product').count(),size);
                const url=new URL(page.url());
                assert.equal(url.pathname,new URL(category).pathname);
                assert.equal(url.searchParams.get('in_stock'),'1'); assert.equal(url.searchParams.get('orderby'),'price');
                assert.equal(url.searchParams.get('pp'),String(size));
            }
            assert.deepEqual(errors,[]);
            await page.locator('.psu-per-page').scrollIntoViewIfNeeded();
            await page.screenshot({path:path.join(output,`pagination-${width}.png`)});
            await context.close();
        }
        for (const quickFirst of [true,false]) {
            execFileSync(php,['/usr/local/bin/wp','--exec=define("DISABLE_WP_CRON",true);define("WP_HTTP_BLOCK_EXTERNAL",true);','eval-file','wp-content/plugins/paint-shop-ux/tests/category-browser-fixture-local.php','bump'],{stdio:'pipe'});
            const guest=await openContext(390); const wholesale=await openContext(390,'wholesale');
            const g=await guest.newPage(); const q=await wholesale.newPage();
            const catalogue = async()=>{
                await g.goto(base); await checkLinks(g,false);
                assert(await g.locator('.psu-category-menu').evaluate(n=>new TextEncoder().encode(n.outerHTML).length)<=50000);
                await expandAndMore(g,false);
                const reply=await guest.request.get(base+'/wp-json/paint-shop-ux/v1/categories?widget='+fixture.widget);
                assert.equal(reply.status(),200); const dto=await reply.json();
                assert(dto.items.length<=30); assert(dto.items.every(n=>new URL(n.url).pathname.startsWith('/category/')));
            };
            const quick = async()=>{
                await q.goto(fixture.quick_url);
                assert(await q.locator('body').evaluate(n=>n.classList.contains('logged-in')),'Real authenticated local session');
                assert(await q.locator('.pc-qo-table tbody tr').count()>0,'Authorized quick-order rows rendered');
                await checkLinks(q,true); await expandAndMore(q,true);
                const link=q.locator('.psu-category-menu li[data-category] > div > a').first();
                await link.click(); await checkLinks(q,true);
                assert(new URL(q.url()).searchParams.get('cat'));
                assert.equal(await q.locator('.psu-category-menu [aria-current="page"]').count(),1);
                const latest = JSON.parse(fs.readFileSync(fixtureFile,'utf8'));
                assert(latest.deep_slug,'Deep fixture category required');
                const deep=new URL(fixture.quick_url); deep.searchParams.set('cat',latest.deep_slug);
                await q.goto(deep.href); await checkLinks(q,true);
                assert.equal(await q.locator('.psu-category-menu [aria-current="page"]').count(),1);
                assert(await q.locator('.psu-category-menu .psu-category-menu__toggle[aria-expanded="true"]').count()>=6);
                assert(await q.locator('.psu-category-menu').evaluate(n=>new TextEncoder().encode(n.outerHTML).length)<=50000);
            };
            if(quickFirst){await quick();await catalogue();}else{await catalogue();await quick();}
            await catalogue();
            report.contexts.push({order:quickFirst?'quick-first':'catalogue-first',passed:true});
            await guest.close();await wholesale.close();
        }
        for(const role of [null,...['customer','manager'].filter(r=>fixture.sessions[r])]){
            const context=await openContext(390,role);const page=await context.newPage();
            await page.goto(fixture.quick_url);
            assert.equal(await page.locator('.pc-qo-table').count(),0,'Unauthorized role cannot see quick-order table');
            await checkLinks(page,false);await expandAndMore(page,false);
            report.contexts.push({role:role||'guest',deniedQuickOrder:true,canonicalNavigation:true});
            await context.close();
        }
        report.passed=true;
    } finally {
        await browser.close();
        fs.writeFileSync(path.join(output,'network-and-results.json'),JSON.stringify(report,null,2),{mode:0o600});
        console.log(JSON.stringify({passed:report.passed,documents:report.network.map(n=>({width:n.width,counts:Object.fromEntries(['initial','resize','size24','page2','size48','size12'].map(phase=>[phase,n.requests.filter(r=>r.phase===phase&&r.mainFrame).length]))})),contexts:report.contexts,unavailableRoles:report.unavailableRoles},null,2));
    }
})().catch(error=>{console.error(error);process.exitCode=1;});
