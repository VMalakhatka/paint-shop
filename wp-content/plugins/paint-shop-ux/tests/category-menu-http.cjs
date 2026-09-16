const {chromium} = require('playwright');
const fs = require('fs');
(async()=>{
 const stage=process.argv[2]||'before';
 const browser=await chromium.launch({executablePath:'/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',headless:true});
 const context=await browser.newContext({viewport:{width:1440,height:1000}});
 await context.route('**/*',r=>new URL(r.request().url()).hostname==='paint.local'?r.continue():r.abort());
 const page=await context.newPage();
 const rows=[];
 for(const path of ['/', '/category/farbi-akrilovi/', '/my-account/']) {
  for(let i=0;i<7;i++) {
   const start=performance.now(); const res=await context.request.get('http://paint.local'+path); const html=await res.text();
   const elapsed=(performance.now()-start)/1000;
   await page.setContent(html,{waitUntil:'domcontentloaded'});
   const menu=await page.locator('.widget_wpb_wmca_accordion_widget').evaluateAll(nodes=>nodes.map(n=>({bytes:new TextEncoder().encode(n.outerHTML).length,links:n.querySelectorAll('a').length,elements:n.querySelectorAll('*').length})));
   rows.push({path,run:i+1,seconds:+elapsed.toFixed(4),status:res.status(),bytes:Buffer.byteLength(html),menu});
   if(i===0) fs.writeFileSync('/tmp/paint-category-'+stage+'-'+(path==='/'?'home':path.split('/')[1])+'.html',html);
  }
 }
 const branch=[];
 const widgetId=await page.locator('.widget_wpb_wmca_accordion_widget').getAttribute('id');
 for(let i=0;i<7;i++) {
  const start=performance.now();
  const response=await context.request.get('http://paint.local/wp-json/paint-shop-ux/v1/categories?widget='+encodeURIComponent(widgetId)+'&parent=0&locale=uk');
  const body=await response.text(); const data=JSON.parse(body);
  branch.push({run:i+1,seconds:+((performance.now()-start)/1000).toFixed(4),status:response.status(),bytes:Buffer.byteLength(body),items:data.items?.length});
 }
 fs.writeFileSync('/tmp/paint-category-'+stage+'-branch-http.json',JSON.stringify(branch,null,2));
 fs.writeFileSync('/tmp/paint-category-'+stage+'-http.json',JSON.stringify(rows,null,2));
 console.log(JSON.stringify(rows)); await browser.close();
})().catch(e=>{console.error(e);process.exit(1)});
