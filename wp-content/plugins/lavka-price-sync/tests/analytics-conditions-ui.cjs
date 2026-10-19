const {chromium}=require('playwright'),fs=require('fs'),path=require('path'),assert=require('assert');
(async()=>{
 const browser=await chromium.launch({headless:true,executablePath:process.env.CHROME_BINARY||'/Applications/Google Chrome.app/Contents/MacOS/Google Chrome'});
 for(const prefix of ['lps-pa','lps-as'])for(const width of [1440,390]){
  const page=await browser.newPage({viewport:{width,height:1000}}),errors=[];page.on('pageerror',e=>errors.push(e.message));
  const data=prefix==='lps-as'?'data-lps-as':'data-lps';
  const card=(key,section,values)=>`<div class="${prefix}-selection" ${data}-selection="${key}" ${data}-section="${section}"><label>${key}<select multiple class="${prefix}-values">${values.map(v=>`<option>${v}</option>`).join('')}</select></label><select class="${prefix}-mode"><option>INCLUDE</option><option>EXCLUDE</option></select></div>`;
  await page.setContent(`<style>body{font:14px Arial}button,select,input{font:inherit}select{display:block}button{margin:5px}</style><form id="root"><details open><summary>Product conditions</summary><div id="${prefix}-product-filter-grid">${card('groupLevel1','product',['Textile','Paper'])+card('groupLevel2','product',['Opaque','Semi'])+card('currentSuppliers','product',['Kreul','Pebeo'])}</div></details><details open><summary>Movement conditions</summary>${card('operationKinds','movement',['*РОЗНИЦА','*РАЗОВАЯ'])}</details></form>`);
  await page.addStyleTag({content:fs.readFileSync(path.join(__dirname,'../assets/analytics-conditions.css'),'utf8')});
  // Load the real page styles last: display:grid previously defeated [hidden].
  await page.addStyleTag({content:fs.readFileSync(path.join(__dirname,'../assets/'+(prefix==='lps-as'?'analytics-scenarios.css':'product-analytics.css')),'utf8')});
  await page.addScriptTag({content:fs.readFileSync(path.join(__dirname,'../assets/analytics-conditions.js'),'utf8')});
  await page.evaluate(prefix=>{
   window.editor=LPS_CONDITIONS(document.querySelector('#root'),prefix,{currentSuppliers:'Supplier'});
   window.cap={features:{groupHierarchy:{supported:true,version:1,paths:[['Textile','Opaque','Kreul'],['Textile','Semi','Kreul'],['Paper','Opaque','Kreul']]}}};
   editor.refresh(cap);editor.apply({groupLevel1:{mode:'INCLUDE',values:['Textile']},groupLevel2:{mode:'INCLUDE',values:['Opaque']}},{});
  },prefix);
  assert.deepEqual(await page.evaluate(()=>editor.read('product')),{groupBranches:[{mode:'INCLUDE',path:['Textile','Opaque']}]});
  assert(await page.locator(`[${data}-selection="groupLevel1"]`).isHidden());
  assert(await page.locator(`[${data}-selection="groupLevel2"]`).isHidden());
  assert(await page.locator(`[${data}-selection="currentSuppliers"]`).isVisible());
  await page.locator('.lps-tree-search').fill('Textile / Opaque');
  const branch=page.locator('.lps-group-tree summary').filter({has:page.locator('span',{hasText:/^Kreul$/})});
  await branch.getByRole('button',{name:'exclude',exact:true}).click();
  await page.locator('.lps-tree-search').fill('Paper');
  await page.locator('.lps-group-tree summary').filter({has:page.locator('span',{hasText:/^Paper$/})}).getByRole('button',{name:'include',exact:true}).click();
  assert.deepEqual((await page.evaluate(()=>editor.read('product'))).groupBranches,[{mode:'INCLUDE',path:['Textile','Opaque']},{mode:'EXCLUDE',path:['Textile','Opaque','Kreul']},{mode:'INCLUDE',path:['Paper']}]);
  const supplier=page.locator(`[${data}-selection="currentSuppliers"]`);
  await supplier.locator('select').first().selectOption(['Kreul','Pebeo']);
  assert(!(await page.evaluate(()=>editor.read('product'))).currentSuppliers,'staged values not applied before Add');
  await supplier.locator('[data-add-condition]').click();
  const row=page.locator('.lps-condition').filter({hasText:'Supplier: Pebeo'});
  await row.getByRole('button',{name:'remove',exact:true}).click();
  assert.deepEqual((await page.evaluate(()=>editor.read('product'))).currentSuppliers.values,['Kreul']);
  const saved=await page.evaluate(()=>({product:editor.read('product'),movement:editor.read('movement')}));
  await page.evaluate(saved=>{editor.clear();editor.apply(saved.product,saved.movement)},saved);
  assert.deepEqual(await page.evaluate(()=>editor.read('product')),saved.product,'save/open roundtrip');
  await page.evaluate(()=>editor.refresh({}));
  assert(await page.evaluate(()=>{try{editor.read('product');return false}catch(e){return true}}),'old Java must not silently drop branches');
  await page.evaluate(()=>editor.refresh(cap));
  await page.evaluate(()=>editor.apply({groupLevel1:{mode:'INCLUDE',values:['Textile','Paper']}},{}));
  assert(await page.locator(`[${data}-selection="groupLevel1"]`).isHidden(),'legacy filters stay in the summary');
  await page.locator('.lps-condition').filter({hasText:'groupLevel1: Textile'}).getByRole('button',{name:'edit',exact:true}).click();
  assert(await page.locator(`[${data}-selection="groupLevel1"]`).isVisible(),'explicit legacy editing remains available');
  assert.deepEqual((await page.evaluate(()=>editor.read('product'))).groupLevel1.values,['Textile','Paper']);
  await page.evaluate(saved=>{editor.refresh(cap);editor.apply(saved.product,saved.movement)},saved);
  await page.screenshot({path:path.join(require('os').tmpdir(),`conditions-${prefix}-${width}.png`),fullPage:true});
  assert.deepEqual(errors,[]);await page.close();
 }
 await browser.close();console.log('PASS: tree paths, OR includes / exclusions, set editing, staged filters, legacy migration, unsupported backend, both screens desktop/mobile');
})().catch(e=>{console.error(e);process.exit(1)});
