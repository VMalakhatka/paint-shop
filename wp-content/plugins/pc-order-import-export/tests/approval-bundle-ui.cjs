// DOM contract test of the real document-list script; no browser or network.
const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict'),path=require('node:path');
class Node {
 constructor(tag='div'){this.tagName=tag;this.children=[];this.dataset={};this.attrs={};this.handlers={};this.classList={add(){},remove(){}};}
 append(...nodes){this.children.push(...nodes);} appendChild(n){this.append(n);} replaceChildren(...nodes){this.children=nodes;}
 setAttribute(k,v){this.attrs[k]=v;} removeAttribute(k){delete this.attrs[k];} before(n){this.beforeNode=n;}
 addEventListener(k,fn){this.handlers[k]=fn;} querySelector(s){return this.map?.[s]||null;}
 querySelectorAll(s){return all(this).filter(n=>s==='[data-bundle-select]' && 'data-bundle-select' in n.attrs);}
 dispatch(type){this.handlers[type]?.({preventDefault(){},target:this});}
}
function all(n){return [n,...n.children.flatMap(all)];}
async function run(manager){
 const root=new Node(),form=new Node('form');root.map={};
 for(const key of ['status','warning','table-wrap','rows','pagination','prev','next','page'])root.map[`[data-pc-documents-${key}]`]=new Node();
 for(const key of ['detail','detail-title','detail-content','close'])root.map[`[data-pc-document-${key}]`]=new Node();
 root.map['[data-pc-documents-form]']=form;form.map={'[type="submit"]':new Node('button')};form.elements={date_from:{value:'2026-10-01'},date_to:{value:'2026-10-11'},limit:{value:50}};
 form.querySelectorAll=()=>[{value:'ACCOUNT'}];
 const doc={documentType:'ACCOUNT',documentId:41,documentNumber:'41',documentDate:'2026-10-11',totalAmount:10,accounted:true,warehouseLabel:'Test',lineCount:1};
 let page=0,url='';
 const pages=[[doc,{...doc,documentId:99,accounted:false},{...doc,documentId:98,documentType:'PAYMENT'}],[{...doc,documentId:42}]];
 const document={querySelector:()=>root,createElement:t=>new Node(t),createTextNode:t=>{const n=new Node('#text');n.textContent=t;return n;}};
 const labels={types:{ACCOUNT:'Invoice',PAYMENT:'Payment'},fields:{documentNumber:'Number'},currency:'UAH',accounted:'Yes',notAccounted:'No'};
 const sandbox={document,pcFolioDocuments:{managerCustomerId:manager?7:0,approvalUrl:'https://example.invalid/admin.php?page=pcoe-approvals&customer_id=7',bundleLabel:'Combine',bundleClear:'Clear',bundleSelect:'Select',bundleHint:'Hint',labels},window:{location:{href:'https://example.invalid/',assign:v=>url=v}},Intl,URL,URLSearchParams,AbortController,Set,console,fetch:async()=>({ok:true,json:async()=>({success:true,data:{result:{documents:pages[page],hasMore:page===0,nextCursor:'next'}}})})};
 vm.runInNewContext(fs.readFileSync(path.resolve(__dirname,'../../../mu-plugins/pc-folio-customer-balance/assets/customer-documents.js'),'utf8'),sandbox);
 const tick=()=>new Promise(r=>setImmediate(r));await tick();
 const rows=root.map['[data-pc-documents-rows]'],wrap=root.map['[data-pc-documents-table-wrap]'];
 if(!manager){assert(!wrap.beforeNode);assert.equal(rows.querySelectorAll('[data-bundle-select]').length,0);return;}
 const panel=wrap.beforeNode,button=panel.children[1],clear=panel.children[3];assert(button.disabled);
 let boxes=rows.querySelectorAll('[data-bundle-select]');assert.equal(boxes.length,1,'Only accounted invoices selectable: '+root.map['[data-pc-documents-status]'].textContent);
 boxes[0].checked=true;boxes[0].dispatch('change');assert(!button.disabled);assert.equal(panel.children[2].textContent,' (1/20) ');
 page=1;root.map['[data-pc-documents-next]'].dispatch('click');await tick();boxes=rows.querySelectorAll('[data-bundle-select]');boxes[0].checked=true;boxes[0].dispatch('change');
 button.dispatch('click');assert.equal(new URL(url).searchParams.get('document_ids'),'41,42');assert.equal(new URL(url).searchParams.get('customer_id'),'7');
 page=0;root.map['[data-pc-documents-prev]'].dispatch('click');await tick();assert(rows.querySelectorAll('[data-bundle-select]')[0].checked,'Selection restored across pages');
 clear.dispatch('click');assert(button.disabled);assert(!rows.querySelectorAll('[data-bundle-select]')[0].checked);
}
(async()=>{await run(true);await run(false);console.log('PASS: bundle selection, pagination, clear, preview URL and customer isolation');})().catch(e=>{console.error(e);process.exit(1)});
