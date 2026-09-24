const {chromium} = require('playwright');
const assert = require('node:assert/strict');
const fs = require('node:fs');
(async () => {
    const browser = await chromium.launch({executablePath:'/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',headless:true});
    const fixture = JSON.parse(fs.readFileSync('/tmp/psu-search-session.json','utf8'));
    try {
        const context = await browser.newContext();
        await context.addCookies(fixture.cookies);
        await context.route('**/*', route => ['paint.local','kreul-media.s3.gra.io.cloud.ovh.net'].includes(new URL(route.request().url()).hostname) ? route.continue() : route.abort());
        const page = await context.newPage();
        const errors = []; page.on('pageerror', e => errors.push(e.message));
        await page.goto('http://paint.local/category/laki/',{waitUntil:'domcontentloaded'});
        const locations = await page.locator('.psu-catalog-filters [name="location[]"]').evaluateAll(nodes => nodes.map(n=>n.value));
        assert(locations.length >= 2);
        const params = new URLSearchParams({unit:'250мл',catalog_search:'лак',in_stock:'1',min_price:'0',max_price:'10000',pp:'12',orderby:'price',psu_filters:'1'});
        locations.slice(0,2).forEach(slug=>params.append('location[]',slug));
        const url = 'http://paint.local/category/laki/?'+params;
        for (const width of [1280,390,320]) {
            await page.setViewportSize({width,height:1000});
            await page.goto(url,{waitUntil:'domcontentloaded'});
            const panel = page.locator('.psu-active-filters');
            await panel.waitFor();
            assert.equal(await page.locator('.psu-catalog-filters__advanced').getAttribute('open'),null);
            assert.equal(await panel.locator('li').count(),7);
            assert.match(await panel.innerText(),/250 мл/);
            assert(!((await panel.innerText()).includes('&#')),'Currency is decoded');
            const unit = page.locator('.psu-catalog-filters [name="unit"]');
            assert.equal(await unit.inputValue(),'250 мл','Legacy URL selects canonical display');
            const values = await unit.locator('option').allTextContents();
            assert.equal(values.length,new Set(values).size,'No duplicate labels');
            await panel.evaluate(el => window.scrollTo(0,window.scrollY+el.getBoundingClientRect().top-230));
            await page.screenshot({path:'/tmp/psu-clean-filters-'+width+'.png'});
            if (width === 390) {
                await page.locator('.psu-catalog-filters').evaluate(el => window.scrollTo(0, window.scrollY+el.getBoundingClientRect().top-16));
                const clip = await page.evaluate(() => {
                    const form = document.querySelector('.psu-catalog-filters').getBoundingClientRect();
                    const chips = document.querySelector('.psu-active-filters').getBoundingClientRect();
                    return {x:form.x,y:form.y,width:form.width,height:chips.bottom-form.y};
                });
                await page.screenshot({path:'/tmp/psu-clean-filters-help.png',clip});
            }
            assert(await page.evaluate(()=>document.documentElement.scrollWidth <= innerWidth+1),'Page fits mobile');
            assert(await panel.locator('li a').evaluateAll(nodes=>nodes.every(n=>n.scrollWidth<=n.clientWidth+1)),'Chip labels fit');
            await panel.locator('[data-filter="location"]').first().click();
            await page.waitForLoadState('domcontentloaded');
            const current = new URL(page.url());
            assert.equal([...current.searchParams.keys()].filter(k=>k.startsWith('location')).length,1,'Only one warehouse removed');
            for(const key of ['unit','in_stock','catalog_search','min_price','max_price','pp','orderby']) assert.equal(current.searchParams.get(key),params.get(key));
            await panel.locator('[data-filter="unit"]').click();
            await page.waitForLoadState('domcontentloaded');
            assert.equal(new URL(page.url()).searchParams.get('unit'),null);
            assert.equal(await panel.locator('[data-filter="location"]').count(),1);
            await panel.locator('.psu-active-filters__clear').click();
            await page.waitForLoadState('domcontentloaded');
            assert.match(new URL(page.url()).pathname,/\/category\/(?:.*\/)?laki\/$/);
            assert.deepEqual([...new URL(page.url()).searchParams.keys()].sort(),['orderby','pp']);
            assert.equal(await panel.count(),0,'All restrictions cleared');
            assert(await page.locator('li.type-product').count(),'Category products restored');
        }
        const guest = await browser.newContext({javaScriptEnabled:false,viewport:{width:390,height:900}});
        const plain = await guest.newPage();
        await plain.goto('http://paint.local/category/laki/?unit=not-a-unit&in_stock=1',{waitUntil:'domcontentloaded'});
        assert.equal(await plain.locator('li.type-product').count(),0);
        await plain.locator('.psu-active-filters [data-filter="unit"]').click();
        await plain.waitForLoadState('domcontentloaded');
        assert(await plain.locator('li.type-product').count(),'Empty result can be recovered without JS');
        assert.equal(new URL(plain.url()).searchParams.get('in_stock'),'1');
        assert.equal(errors.length,0,errors.join('\n'));
        await guest.close(); await context.close();
        console.log(JSON.stringify({passed:true,widths:[1280,390,320],wholesale:true,guestNoJS:true,individualRemoval:true,clearAll:true,legacyURL:true}));
    } finally { await browser.close(); }
})().catch(error => {console.error(error);process.exit(1);});
