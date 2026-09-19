const {chromium} = require('playwright');
const assert = require('node:assert/strict');
const fs = require('node:fs');

(async () => {
    const base = 'http://paint.local';
    const output = '/tmp/psu-catalog-units';
    const eligible = new Set(JSON.parse(fs.readFileSync('/tmp/psu-catalog-units-fixture.json','utf8')).ids);
    fs.mkdirSync(output, {recursive:true});
    const browser = await chromium.launch({executablePath:'/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',headless:true});
    const results = [];
    try {
        for (const width of [1440,1024,768,390,320]) {
            const context = await browser.newContext({viewport:{width,height:1000}});
            await context.route('**/*', route => {
                const request = route.request(), url = new URL(request.url());
                const local = url.origin === base && request.method() === 'GET' && !url.pathname.endsWith('/wp-cron.php') && !url.searchParams.has('add-to-cart');
                const image = request.resourceType() === 'image' && url.hostname === 'kreul-media.s3.gra.io.cloud.ovh.net';
                return local || image ? route.continue() : route.abort();
            });
            const page = await context.newPage();
            const errors = []; page.on('pageerror', error => errors.push(error.message));
            page.setDefaultTimeout(30000); page.setDefaultNavigationTimeout(60000);
            await page.goto(base+'/category/farbi-akrilovi/?pp=12&in_stock=1&orderby=price');
            const form = page.locator('.psu-catalog-filters');
            const units = form.getByRole('combobox', {name:'Одиниця виміру',exact:true});
            await units.waitFor();
            assert((await units.locator('option').allTextContents()).includes('80мл'));
            // An unrelated unit exists globally but must not leak into this category's list.
            assert(!(await units.locator('option').allTextContents()).includes('1000мл'));
            await units.selectOption({label:'80мл'});
            const unit = await units.inputValue();
            await form.getByRole('button',{name:'Застосувати фільтри',exact:true}).click();
            await page.waitForLoadState('load');
            const url = new URL(page.url());
            assert.equal(url.pathname,'/category/farbi-akrilovi/');
            assert.equal(url.searchParams.get('unit'),unit);
            for(const [key,value] of Object.entries({pp:'12',in_stock:'1',orderby:'price'})) assert.equal(url.searchParams.get(key),value);
            assert.equal(await units.inputValue(),unit);
            const first = await page.locator('li.type-product').evaluateAll(nodes=>nodes.map(n=>n.className.match(/post-(\d+)/)[1]));
            assert.equal(first.length,12);
            assert(first.every(id=>eligible.has(id)),'Actual HTTP results have selected unit');
            await form.scrollIntoViewIfNeeded();
            assert(await form.evaluate(el=>el.getBoundingClientRect().left>=0 && el.getBoundingClientRect().right<=innerWidth));
            assert(await units.evaluate(el=>el.scrollWidth<=el.clientWidth+1));
            await page.screenshot({path:`${output}/filter-${width}.png`});
            if (width===390) await form.screenshot({path:`${output}/filter-mobile-help.png`});
            await page.getByRole('link',{name:'Сторінка 2',exact:true}).first().click();
            await page.waitForLoadState('load');
            assert.equal(new URL(page.url()).searchParams.get('unit'),unit);
            const second = await page.locator('li.type-product').evaluateAll(nodes=>nodes.map(n=>n.className.match(/post-(\d+)/)[1]));
            assert(second.length && second.every(id=>!first.includes(id)));
            await page.locator('.psu-per-page').getByRole('link',{name:'24',exact:true}).click();
            await page.waitForLoadState('load');
            assert.equal(new URL(page.url()).pathname,'/category/farbi-akrilovi/');
            assert.equal(new URL(page.url()).searchParams.get('unit'),unit);
            assert.equal(await page.locator('li.type-product').count(),24);
            await form.getByRole('searchbox').fill('фарба');
            await form.getByRole('button',{name:'Застосувати фільтри',exact:true}).click();
            await page.waitForLoadState('load');
            const searchResults=await page.locator('li.type-product').evaluateAll(nodes=>nodes.map(n=>n.className.match(/post-(\d+)/)[1]));
            assert(searchResults.length && searchResults.every(id=>eligible.has(id)),'Word search cannot escape unit/category');
            assert.equal(new URL(page.url()).searchParams.get('unit'),unit);
            await form.getByRole('link',{name:'Скинути',exact:true}).click();
            await page.waitForLoadState('load');
            assert.equal(new URL(page.url()).pathname,'/category/farbi-akrilovi/');
            assert.equal(new URL(page.url()).searchParams.get('unit'),null);
            assert.equal(await units.inputValue(),'');
            await page.goto(base+'/category/farbi-akrilovi/?unit=psu-unit-does-not-exist');
            assert.equal(await page.locator('li.type-product').count(),0);
            assert.equal(await units.inputValue(),'psu-unit-does-not-exist');
            assert(await form.getByRole('link',{name:'Скинути',exact:true}).isVisible());

            await page.goto(base+'/category/laki/');
            const varnishOptions = await units.locator('option').allTextContents();
            assert(varnishOptions.includes('250мл') && varnishOptions.includes('125мл'));
            // These Java-synced cards have no taxonomy unit and no stored sale price.
            const darwi = page.locator('li.type-product').filter({hasText:'DR-DA3000100002'}).first();
            assert.equal(await darwi.locator('.onsale').count(),0);
            await units.selectOption({label:'250мл'});
            await form.getByRole('button',{name:'Застосувати фільтри',exact:true}).click();
            await page.waitForLoadState('load');
            assert.equal(new URL(page.url()).searchParams.get('unit'),'250мл');
            assert.equal(await page.locator('li.type-product').filter({hasText:'KR-79406'}).count(),1);
            await form.scrollIntoViewIfNeeded();
            assert(await form.evaluate(el=>el.getBoundingClientRect().left>=0 && el.getBoundingClientRect().right<=innerWidth));
            await page.screenshot({path:`${output}/varnishes-${width}.png`});
            if (width===390) await form.screenshot({path:`${output}/filter-mobile-help.png`});
            await form.getByRole('searchbox').fill('лак');
            await form.getByRole('button',{name:'Застосувати фільтри',exact:true}).click();
            await page.waitForLoadState('load');
            const varnishTitles = await page.locator('li.type-product h2').allTextContents();
            assert(varnishTitles.length && varnishTitles.every(title=>title.includes('250мл')),'Relevanssi keeps Java-only unit restriction');
            await form.getByRole('searchbox').fill('KR-79406');
            await form.getByRole('button',{name:'Застосувати фільтри',exact:true}).click();
            await page.waitForLoadState('load');
            assert.equal(await page.locator('li.type-product').count(),1);
            assert.equal(await page.locator('.psu-loop-sku').innerText(),'KR-79406');
            assert.deepEqual(errors,[]);
            results.push({width,passed:true});
            await context.close();
        }
    } finally {
        await browser.close();
        fs.writeFileSync(`${output}/results.json`,JSON.stringify(results,null,2));
    }
    console.log(JSON.stringify(results));
})().catch(error=>{console.error(error);process.exitCode=1;});
