/* Offline layout and link check of the PHP-rendered direct-order result. */
const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict');
const {chromium}=require(process.env.PLAYWRIGHT_MODULE||'playwright');
const base=path.resolve(__dirname,'..'),root=path.resolve(base,'../../..');
(async()=>{
 const fixture=JSON.parse(fs.readFileSync(process.argv[2],'utf8'));
 const browser=await chromium.launch({channel:'chrome',headless:true});
 try {
  const page=await browser.newPage();await page.route('**/*',r=>r.abort());
  for(const view of ['html','customer_html']) {
   await page.setContent('<html lang="uk"><body class="wp-core-ui" style="margin:16px;font:14px Arial"><main class="pcoe-manager">'+fixture[view]+'</main></body></html>');
   for(const file of ['wp-admin/css/common.css','wp-admin/css/forms.css','wp-includes/css/buttons.css'])await page.addStyleTag({content:fs.readFileSync(path.join(root,file),'utf8')});
   await page.addStyleTag({content:fs.readFileSync(path.join(base,'assets/manager.css'),'utf8')});
   assert.equal(await page.locator('[value="quick_order"], [value="quick_settings"]').count(),0);
   const links=await page.locator('a').evaluateAll(links=>links.map(a=>({text:a.textContent,url:a.href})));
   for(const id of fixture.children) assert(links.some(a=>a.text.includes('#'+id)),`Child ${id} is visible`);
   if(view==='customer_html') assert(links.every(a=>!a.url.includes('wp-admin')),'Customer links stay in the account');
   for(const width of [1100,390]) {
    await page.setViewportSize({width,height:800});
    await page.evaluate(()=>{scrollTo({top:0,behavior:'instant'});return new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve)));});
    assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'No horizontal overflow');
    await page.screenshot({path:path.join(path.dirname(process.argv[2]),`pcoe-direct-${view}-${width}.png`),fullPage:true});
   }
  }
  console.log('PASS: direct child links in manager/customer results, no extra creation form, no customer admin URLs, desktop/mobile; no network.');
 } finally {await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
