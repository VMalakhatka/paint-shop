/* Offline UI regression: no authentication, customer data or network. */
const fs=require('node:fs'), path=require('node:path'), assert=require('node:assert/strict');
const {execFileSync}=require('node:child_process');
const {chromium}=require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const root=path.resolve(__dirname,'..'), base=path.join(root,'wp-content/plugins/pc-order-import-export');
const js=fs.readFileSync(path.join(base,'assets/manager-help.js'),'utf8');
const help=execFileSync('php',[path.join(base,'tests/manager-help.php'),'--render','uk'],{encoding:'utf8'});
const ids=new Set([...help.matchAll(/id="([a-z-]+)"/g)].map(m=>m[1]));
(async()=>{
 const browser=await chromium.launch({headless:true,channel:'chrome'});
 try {
  const page=await browser.newPage(); await page.route('**/*',r=>r.abort());
  async function setup(html, screen='pcoe-customers') {
   await page.setContent('<html lang="uk"><body style="margin:16px;font:14px Arial"><div id="wpbody-content">'+html+'</div></body></html>');
   // Exercise the real WordPress float rules, not only plugin styles.
   await page.addStyleTag({content:fs.readFileSync(path.join(root,'wp-admin/css/common.css'),'utf8')});
   for(const file of ['manager.css','manager-help.css']) await page.addStyleTag({content:fs.readFileSync(path.join(base,'assets',file),'utf8')});
   await page.evaluate(screen=>{window.pcoeManagerHelp={page:screen,url:'https://example.invalid/wp-admin/admin.php?page=pcoe-customers&view=help#',label:'? Як це працює',title:'Відкрити довідку в новій вкладці'};},screen);
   await page.addScriptTag({content:js});
  }
  const forms=['new','import','save','copy','preview','apply'].map(op=>`<section class="pcoe-card"><h2>${op}</h2><form data-pcoe-manager><input type="hidden" name="operation" value="${op}"><input name="title" value="Test draft"><button>${op}</button></form></section>`).join('');
  await setup('<div class="wrap pcoe-manager"><h1>Робота з клієнтами</h1>'+forms+'<div class="pcoe-chat"><h2>Повідомлення</h2><section><h3>Мій Telegram</h3><form><input name="action" value="pcoe_telegram" type="hidden"><input name="operation" value="link" type="hidden"><button>Підключити</button></form></section></div><div id="dynamic"></div></div>');
  const count=await page.locator('.pcoe-help-link').count(); assert(count>=14);
  await page.evaluate(()=>document.querySelector('#dynamic').innerHTML='<form class="pcoe-chat-assignment"><input name="action" value="pcoe_chat"><input name="operation" value="assign"><button>Зберегти</button></form><button data-document-view>Перегляд документа</button>');
  await page.waitForFunction(()=>document.querySelector('[data-document-view] + .pcoe-help-link'));
  const after=await page.locator('.pcoe-help-link').count();
  await page.evaluate(()=>document.querySelector('#dynamic').append(document.createElement('p')));
  await page.waitForTimeout(100); assert.equal(await page.locator('.pcoe-help-link').count(),after);
  assert.equal(await page.locator('.pcoe-chat button + a').getAttribute('href'),'https://example.invalid/wp-admin/admin.php?page=pcoe-customers&view=help#telegram-connect');
  for(const href of await page.locator('.pcoe-help-link').evaluateAll(es=>es.map(e=>e.hash.slice(1)))) assert(ids.has(href),'Unknown target '+href);
  assert.equal(await page.locator('.pcoe-help-link:not([target="_blank"])').count(),0);
  assert.equal(await page.locator('input[name=title]').first().inputValue(),'Test draft');
  for(const width of [1100,390]) {
   await page.setViewportSize({width,height:800});
   assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'UI overflow');
   await page.screenshot({path:`/tmp/manager-context-${width}.png`,fullPage:true});
  }
  for(const [screen,anchor] of [['pcoe-approvals','approval'],['pc-folio-customer-balance','balance-export'],['pc-folio-customer-debtors','debtors']]) {
   await setup('<div class="wrap"><h1>Report</h1><button>Action</button></div>',screen);
   assert.equal(await page.locator('.pcoe-help-link').count(),2);
   assert((await page.locator('button + a').getAttribute('href')).endsWith('#'+anchor));
  }
  const tabs='<div class="wrap pcoe-manager"><h1>Робота з клієнтами</h1><nav class="nav-tab-wrapper"><a class="nav-tab nav-tab-active" href="?page=pcoe-customers">Клієнти</a><a class="nav-tab" href="?page=pcoe-customers&view=orders">Замовлення</a><a class="nav-tab" href="?page=pcoe-customers&view=messages">Звернення</a></nav><div id="dynamic"></div></div>';
  await setup(tabs);
  assert.equal(await page.locator('.pcoe-help-tab').count(),3);
  assert.equal(await page.locator('.nav-tab-wrapper > .pcoe-help-link').count(),0);
  assert.deepEqual(await page.locator('.pcoe-help-tab').evaluateAll(groups=>groups.map(g=>[g.querySelector('.nav-tab').textContent,g.querySelector('.pcoe-help-link').hash])),[['Клієнти','#customers'],['Замовлення','#orders'],['Звернення','#queue']]);
  await page.evaluate(()=>document.querySelector('#dynamic').append(document.createElement('p')));
  await page.waitForTimeout(80);
  assert.equal(await page.locator('.pcoe-help-tab').count(),3);
  for(const width of [1100,768,390,320]) {
   await page.setViewportSize({width,height:600});
   const boxes=await page.locator('.pcoe-help-tab').evaluateAll(groups=>groups.map(g=>{
    const t=g.querySelector('.nav-tab').getBoundingClientRect(), h=g.querySelector('.pcoe-help-link').getBoundingClientRect();
    return {top:t.top,bottom:t.bottom,helpTop:h.top,left:t.left,helpLeft:h.left};
   }));
   assert(boxes.every(b=>b.helpTop>=b.bottom && Math.abs(b.left-b.helpLeft)<1),'Help stays below its own tab');
   if(width>=768) assert(boxes.every(b=>Math.abs(b.top-boxes[0].top)<1),'Tabs share one baseline');
   assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'Tab overflow');
   await page.screenshot({path:`/tmp/manager-tabs-${width}.png`,fullPage:false});
  }
  await setup(help);
  assert.equal(await page.locator('.pcoe-help-link').count(),0,'No recursive help links');
  for (const id of ['customer-import','customer-import-select','customer-import-fields','customer-import-preview','customer-import-apply','customer-import-results','customer-import-recovery']) {
   assert.equal(await page.locator('#'+id).count(),1,'Import section '+id);
   assert.equal(await page.locator('a[href="#'+id+'"]').count(),1,'Import contents link '+id);
  }
  for(const width of [1100,390]) {
   await page.setViewportSize({width,height:900});
   assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'Guide overflow');
   await page.screenshot({path:`/tmp/manager-guide-${width}.png`,fullPage:false});
   await page.locator('#telegram-connect').screenshot({path:`/tmp/manager-telegram-${width}.png`});
   await page.locator('#register-wholesale').scrollIntoViewIfNeeded();
   await page.screenshot({path:`/tmp/manager-registration-${width}.png`,fullPage:false});
   await page.locator('#customer-import-preview').scrollIntoViewIfNeeded();
   await page.screenshot({path:`/tmp/manager-customer-import-${width}.png`,fullPage:false});
  }
  console.log('PASS: exact anchors, AJAX replacement, deduplication, preserved inputs, supporting screens, desktop/mobile layout');
 } finally { await browser.close(); }
})().catch(e=>{console.error(e);process.exit(1);});
