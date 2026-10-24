/* Edit Java-owned settings; historical reports never use this editor's current values. */
(function () {
    'use strict';
    const cfg=window.LavkaProfitScopeSettingsConfig, root=document.getElementById('lavr-profit-scope-settings');
    if(!cfg || !root)return;
    const t=cfg.i18n, $=id=>document.getElementById('lps-'+id);
    const warehouseFields=['kyivWarehouseIds','odesaWarehouseIds','kyivStockWarehouseIds','odesaStockWarehouseIds'];
    const groupField='excludedInventoryGroupIds', subgroupField='excludedInventorySubgroupIds';
    const fields=[...warehouseFields,groupField,subgroupField];
    let version=null, catalog=null, catalogIndex={}, selection={}, dirty=false, busy=false, reportBusy=false, uncertain=false;
    const node=(tag,text)=>{const n=document.createElement(tag);if(text!=null)n.textContent=text;return n;};
    const idValid=id=>Number.isInteger(id)&&id>0&&id<=2147483647;
    const branchValid=id=>typeof id==='string'&&id.length<=13000&&/^g:[A-Za-z0-9_-]+$/.test(id);
    function valid(data){
        return data&&Number.isSafeInteger(data.version)&&data.version>=0&&fields.every(k=>Array.isArray(data[k])&&data[k].every(warehouseFields.includes(k)?idValid:branchValid)&&new Set(data[k]).size===data[k].length&&(warehouseFields.includes(k)?data[k].length>0&&data[k].length<=50:data[k].length<=100))
            &&data[groupField].length+data[subgroupField].length<=100
            &&!data.kyivWarehouseIds.some(id=>data.odesaWarehouseIds.includes(id))
            &&!data.kyivStockWarehouseIds.some(id=>data.odesaStockWarehouseIds.includes(id));
    }
    function validCatalog(data){
        if(!data||!['warehouses','groups','subgroups'].every(k=>Array.isArray(data[k])&&data[k].every(r=>r&&typeof r.name==='string'&&(k==='warehouses'?idValid(r.id):branchValid(r.id)&&Array.isArray(r.path)&&r.path.length>=1&&r.path.length<=6&&r.path.every(p=>typeof p==='string')))&&new Set(data[k].map(r=>r.id)).size===data[k].length))return false;
        const all=new Map([...data.groups,...data.subgroups].map(r=>[r.id,r]));
        return all.size===data.groups.length+data.subgroups.length&&data.groups.every(r=>r.path.length===1)&&data.subgroups.every(r=>r.level===r.path.length&&r.level>=2&&data.groups.some(g=>g.id===r.groupId)&&all.has(r.parentId)&&all.get(r.parentId).path.length===r.path.length-1);
    }
    const choiceId=(key,value)=>warehouseFields.includes(key)?Number(value):value;
    function inherited(entry){
        if(!entry)return false;
        if(selection[groupField].has(entry.groupId))return true;
        let parent=entry.parentId;
        for(let n=0;parent&&n<6;n++){
            if(selection[subgroupField].has(parent))return true;
            parent=catalogIndex[subgroupField].get(parent)?.parentId;
        }
        return false;
    }
    function entries(key){return warehouseFields.includes(key)?catalog.warehouses:key===groupField?catalog.groups:catalog.subgroups;}
    function error(message){$('error').textContent=message||'';$('error').hidden=!message;}
    function controls(){
        $('fields').querySelectorAll('input').forEach(n=>n.disabled=busy||reportBusy||uncertain||n.dataset.inherited==='true');
        $('save').disabled=busy||reportBusy||uncertain||version===null||!dirty;
        $('reload').disabled=busy||reportBusy;
    }
    function changed(){dirty=true;$('state').textContent=t.dirty;error('');refresh();}
    function refresh(){
        if(!catalog)return;
        fields.forEach(key=>{
            const section=$('fields').querySelector('[data-field="'+key+'"]');
            section.querySelector('[data-count]').textContent=t.selected+': '+selection[key].size;
            section.querySelectorAll('[data-choice]').forEach(label=>{
                const input=label.querySelector('input'),entry=catalogIndex[key].get(choiceId(key,input.value));
                const covered=key===subgroupField&&inherited(entry);
                input.checked=selection[key].has(choiceId(key,input.value))||Boolean(covered);
                input.dataset.inherited=String(Boolean(covered));
                label.querySelector('small[data-inherited]').textContent=covered?t.inherited:'';
            });
        });
        controls();
    }
    function render(data, nextCatalog){
        catalog=nextCatalog;catalogIndex=Object.fromEntries(fields.map(k=>[k,new Map(entries(k).map(r=>[r.id,r]))]));selection=Object.fromEntries(fields.map(k=>[k,new Set(data[k])]));
        $('fields').replaceChildren();
        fields.forEach(key=>{
            const section=node('section');section.dataset.field=key;section.append(node('h3',t[key]));
            const search=node('input');search.type='search';search.placeholder=t.search;search.setAttribute('aria-label',t[key]+' — '+t.search);section.append(search);
            const count=node('p');count.dataset.count='';count.setAttribute('aria-live','polite');section.append(count);
            const list=node('div');list.className='lps-choices';
            const available=entries(key), missing=data[key].filter(id=>!catalogIndex[key].has(id)).map(id=>({id,name:t.missing,missing:true}));
            [...missing,...available].forEach(entry=>{
                const label=node('label');label.dataset.choice='';
                const input=node('input');input.type='checkbox';input.value=String(entry.id);
                input.addEventListener('change',()=>{if(input.checked)selection[key].add(entry.id);else selection[key].delete(entry.id);changed();});
                const title=warehouseFields.includes(key)||entry.missing?entry.id+' · '+entry.name:entry.path.map(part=>part||t.unnamed).join(' / ');
                const span=node('span',title),note=node('small');note.dataset.inherited='';span.append(note);label.append(input,span);label.dataset.search=title.toLocaleLowerCase();
                if(entry.missing)label.classList.add('lps-missing');list.append(label);
            });
            const empty=node('p',t.empty);empty.hidden=Boolean(list.children.length);
            search.addEventListener('input',()=>{
                const term=search.value.trim().toLocaleLowerCase();let count=0;
                list.querySelectorAll('[data-choice]').forEach(label=>{label.hidden=!label.dataset.search.includes(term);if(!label.hidden)count++;});empty.hidden=count>0;
            });
            section.append(list,empty);$('fields').append(section);
        });
        version=data.version;dirty=false;uncertain=false;$('version').textContent=t.version+': '+version;refresh();
    }
    function values(){
        const body={version};
        fields.forEach(key=>{
            body[key]=[...selection[key]].sort(warehouseFields.includes(key)?((a,b)=>a-b):undefined);
            if(body[key].length>(warehouseFields.includes(key)?50:100)||body[key].some(id=>!catalogIndex[key].has(id))||(warehouseFields.includes(key)&&!body[key].length))throw new Error(t.invalid);
        });
        if(body[groupField].length+body[subgroupField].length>100)throw new Error(t.invalid);
        if(body.kyivWarehouseIds.some(id=>selection.odesaWarehouseIds.has(id))||body.kyivStockWarehouseIds.some(id=>selection.odesaStockWarehouseIds.has(id)))throw new Error(t.invalid);
        return body;
    }
    async function request(operation,settings){
        const controller=new AbortController(),timer=setTimeout(()=>controller.abort(),45000);
        try{
            const form=new URLSearchParams({action:cfg.action,nonce:cfg.nonce,operation});if(settings)form.set('settings',JSON.stringify(settings));
            const response=await fetch(cfg.ajaxUrl,{method:'POST',credentials:'same-origin',body:form,signal:controller.signal});const envelope=await response.json();
            if(!envelope.success)throw new Error(operation==='save'?t.uncertain:t.failed);
            if(envelope.data.httpStatus===409)throw new Error(t.conflict);
            if(envelope.data.httpStatus===400){const e=new Error(t.invalid);e.validation=true;throw e;}
            if(envelope.data.httpStatus<200||envelope.data.httpStatus>=300)throw new Error(operation==='save'?t.uncertain:t.failed);
            const data=JSON.parse(envelope.data.bodyRaw);
            if(!(operation==='catalog'?validCatalog(data):valid(data)))throw new Error(operation==='save'?t.uncertain:t.failed);
            return data;
        }finally{clearTimeout(timer);}
    }
    async function load(){
        if(busy||reportBusy)return;if(dirty&&!window.confirm(t.discard))return;
        busy=true;controls();error('');$('state').textContent=t.loading;
        try{const [data,nextCatalog]=await Promise.all([request('get'),request('catalog')]);render(data,nextCatalog);$('state').textContent='';}
        catch(e){error(e.message||t.failed);$('state').textContent='';}
        finally{busy=false;controls();}
    }
    async function save(){
        if(busy||reportBusy||uncertain||version===null||!dirty)return;
        let data;try{data=values();}catch(e){error(e.message);return;}
        busy=true;controls();error('');$('state').textContent=t.saving;
        try{render(await request('save',data),catalog);$('state').textContent=t.saved;}
        catch(e){uncertain=!e.validation;error(e.message||t.uncertain);$('state').textContent='';}
        finally{busy=false;controls();}
    }
    root.addEventListener('toggle',()=>{if(root.open&&version===null&&!busy)load();});
    $('reload').addEventListener('click',load);$('save').addEventListener('click',save);
    window.LavkaProfitScopeSettings={
        async calculationVersion(){
            const data=await request('get');
            // A manager who reviewed one version must not silently calculate with another.
            if(version!==null&&data.version!==version){uncertain=true;error(t.conflict);controls();throw new Error(t.conflict);}
            return data.version;
        },
        assertReady(){if(busy||reportBusy)throw new Error(t.busy);if(dirty||uncertain)throw new Error(t.dirty);},
        setReportBusy(value){reportBusy=value;controls();}
    };
})();
