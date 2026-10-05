/** Rendered disposable WP fixtures; all receipt HTTP mocked, no send actions. */
const {chromium}=require('playwright');
const fs=require('node:fs');
const path=require('node:path');
const assert=require('node:assert/strict');
(async()=>{
    const dir=process.env.PCOE_BROADCAST_PREVIEW;
    assert(dir,'Run broadcasts.php with PCOE_BROADCAST_PREVIEW first');
    const root=path.resolve(__dirname,'../../../..');
    const styles=['wp-includes/css/buttons.css','wp-admin/css/common.css','wp-admin/css/forms.css','wp-admin/css/list-tables.css','wp-content/plugins/pc-order-import-export/assets/manager.css'].map(p=>fs.readFileSync(path.join(root,p),'utf8')).join('\n');
    const browser=await chromium.launch({headless:true,executablePath:process.env.CHROME_BINARY||'/Applications/Google Chrome.app/Contents/MacOS/Google Chrome'});
    const page=await browser.newPage({viewport:{width:1280,height:960}});const errors=[];page.on('pageerror',e=>errors.push(e.message));
    const render=async name=>page.setContent('<meta charset="utf-8"><style>'+styles+'body{padding:20px;margin:0;box-sizing:border-box}.pcoe-manager{max-width:1100px;margin:auto}</style><body class="wp-core-ui"><main class="pcoe-manager">'+fs.readFileSync(path.join(dir,name+'.html'),'utf8')+'</main></body>');
    try{
        await page.route('**/*',async route=>{
            if(!route.request().url().includes('receipt-fixture'))return route.abort();
            const params=new URLSearchParams(route.request().postData());let data;
            if(params.get('kind')==='warehouses')data={warehouses:[{code:'7',name:'Київ — тестовий склад'},{code:'8',name:'Одеса — тестовий склад'}]};
            else if(params.get('date')==='2026-10-03'){await new Promise(r=>setTimeout(r,160));data={documents:[{id:999,number:'STALE',date:'2026-10-03'}]};}
            else if(params.get('date')==='2026-10-02')return route.fulfill({status:409,contentType:'application/json',body:JSON.stringify({success:false,data:{message:'Test backend unavailable'}})});
            else data={documents:[{id:params.get('after')==='9001'?9002:9001,number:params.get('after')==='9001'?'502':'501',date:'2026-10-05'}],hasMore:params.get('after')!=='9001',nextAfterId:9001};
            await route.fulfill({contentType:'application/json',body:JSON.stringify({success:true,data})});
        });
        await render('compose');
        await page.evaluate(()=>{window.pcoeBroadcast={url:'https://fixture.invalid/receipt-fixture',nonce:'test',loading:'Завантажуємо…',empty:'Немає документів',error:'Помилка'};});
        await page.addScriptTag({content:fs.readFileSync(path.join(root,'wp-content/plugins/pc-order-import-export/assets/broadcast.js'),'utf8')});
        const receipt=page.locator('#pcoe-broadcast-receipt'),kind=page.locator('#pcoe-broadcast-kind'),warehouse=page.locator('#pcoe-broadcast-warehouse'),date=page.locator('#pcoe-broadcast-date'),doc=page.locator('#pcoe-broadcast-document');
        assert(!await receipt.isVisible());await kind.selectOption('arrival');await warehouse.locator('option[value="7"]').waitFor({state:'attached'});
        await date.fill('2026-10-05');await warehouse.selectOption('7');await doc.locator('option[value="9001"]').waitFor({state:'attached'});await doc.selectOption('9001');
        await page.locator('#pcoe-broadcast-more').click();await doc.locator('option[value="9002"]').waitFor({state:'attached'});assert.equal(await doc.inputValue(),'9001');assert(!await page.locator('#pcoe-broadcast-more').isVisible());
        await date.fill('2026-10-03');await date.fill('');await page.waitForTimeout(250);assert.equal(await doc.locator('option').count(),1);assert(!await doc.isDisabled());
        await date.fill('2026-10-02');await page.getByText('Test backend unavailable').waitFor();assert.equal(await doc.locator('option').count(),1);
        await date.fill('2026-10-05');await doc.locator('option[value="9001"]').waitFor({state:'attached'});await doc.selectOption('9001');
        await page.locator('input[name="subject"]').fill('Новий прихід: пропозиція для клієнтів');await page.locator('textarea[name="message"]').fill('Вітаємо! Перегляньте новинки у вашому прайсі.');
        for(const width of [390,1280]){
            await page.setViewportSize({width,height:960});const pageWidth=await page.evaluate(()=>document.documentElement.scrollWidth);assert(pageWidth<=width+1,`Composer fits ${width}, got ${pageWidth}`);
            await page.screenshot({path:path.join(dir,'compose-'+width+'.png'),fullPage:true});
        }
        await kind.selectOption('text');assert(!await receipt.isVisible());assert(!await doc.evaluate(el=>el.required));
        await render('review');const confirm=page.locator('input[name="confirm"]');assert.equal(await confirm.count(),1);assert(!await confirm.isChecked());assert(!await confirm.evaluate(el=>el.form.checkValidity()));
        await confirm.check();assert(await confirm.evaluate(el=>el.form.checkValidity()));
        for(const width of [390,1280]){await page.setViewportSize({width,height:960});assert(await page.evaluate(()=>document.documentElement.scrollWidth)<=width+1,`Review fits ${width}`);await page.screenshot({path:path.join(dir,'review-'+width+'.png'),fullPage:true});}
        assert.deepEqual(errors,[]);console.log('PASS: mailing preview UI, arrival picker pagination/errors/stale response, explicit confirmation and 390/1280 layouts; no mail sent.');
    }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
