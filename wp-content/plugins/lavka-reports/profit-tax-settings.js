/* Persistent Java-owned settings. Never replace a saved report with current rules. */
(function () {
    'use strict';
    const cfg=window.LavkaProfitTaxSettingsConfig, root=document.getElementById('lavr-profit-tax-settings');
    if(!cfg || !root)return;
    const t=cfg.i18n, $=id=>document.getElementById('lpt-'+id);
    let version=null, dirty=false, busy=false, reportBusy=false, uncertain=false;
    const fields=['retailFirmCodes','wholesaleFirmCodes'];
    const node=(tag,text)=>{const n=document.createElement(tag);if(text!=null)n.textContent=text;return n;};
    function error(text){$('error').textContent=text||'';$('error').hidden=!text;}
    function controls(){
        $('groups').querySelectorAll('input,button').forEach(n=>n.disabled=busy||reportBusy||uncertain);
        $('save').disabled=busy||reportBusy||uncertain||version===null||!dirty;
        $('reload').disabled=busy||reportBusy;
    }
    function changed(){dirty=true;$('state').textContent=t.dirty;error('');controls();}
    function row(key,value=''){
        const tr=node('tr'),td=node('td'),input=node('input');input.type='text';input.maxLength=64;input.value=value;input.autocomplete='off';input.setAttribute('aria-label',(key===fields[0]?t.retail:t.wholesale)+' — '+t.code);input.addEventListener('input',changed);td.append(input);tr.append(td);
        const actions=node('td'),remove=node('button',t.remove);remove.type='button';remove.className='button';remove.addEventListener('click',()=>{tr.remove();changed();});actions.append(remove);tr.append(actions);$('groups').querySelector('[data-group="'+key+'"] tbody').append(tr);return input;
    }
    function render(data){
        $('groups').replaceChildren();
        fields.forEach((key,i)=>{
            const section=node('section');section.dataset.group=key;section.append(node('h3',i===0?t.retail:t.wholesale));
            const table=node('table');table.className='widefat';const head=node('thead'),tr=node('tr');tr.append(node('th',t.code),node('th',t.remove));head.append(tr);table.append(head,node('tbody'));section.append(table);
            const add=node('button',t.add);add.type='button';add.className='button';add.addEventListener('click',()=>{const input=row(key);changed();input.focus();});section.append(add);$('groups').append(section);data[key].forEach(value=>row(key,value));
        });
        version=data.version;$('version').textContent=t.version+': '+version;dirty=false;uncertain=false;
    }
    function values(){
        const body={version},seen=new Set();
        fields.forEach(key=>{body[key]=[...$('groups').querySelectorAll('[data-group="'+key+'"] input')].map(n=>n.value.trim().toUpperCase());if(body[key].length>100)throw new Error(t.invalid);body[key].forEach(code=>{if(!code||!/^[\p{L}\p{N}_-]{1,64}$/u.test(code)||seen.has(code))throw new Error(t.invalid);seen.add(code);});});return body;
    }
    function valid(data){return data&&Number.isSafeInteger(data.version)&&data.version>=0&&fields.every(k=>Array.isArray(data[k])&&data[k].every(c=>typeof c==='string'));}
    async function request(operation,settings){
        const controller=new AbortController(),timer=setTimeout(()=>controller.abort(),45000);
        try{
            const form=new URLSearchParams({action:cfg.action,nonce:cfg.nonce,operation});if(settings)form.set('settings',JSON.stringify(settings));
            const response=await fetch(cfg.ajaxUrl,{method:'POST',credentials:'same-origin',body:form,signal:controller.signal});const envelope=await response.json();
            if(!envelope.success)throw new Error(operation==='save'?t.uncertain:t.failed);
            if(envelope.data.httpStatus===409)throw new Error(t.conflict);
            if(envelope.data.httpStatus===400){const issue=new Error(t.invalid);issue.validation=true;throw issue;}
            if(envelope.data.httpStatus<200||envelope.data.httpStatus>=300)throw new Error(operation==='save'?t.uncertain:t.failed);
            const data=JSON.parse(envelope.data.bodyRaw);if(!valid(data))throw new Error(operation==='save'?t.uncertain:t.failed);return data;
        }finally{clearTimeout(timer);}
    }
    async function load(){
        if(busy||reportBusy)return;if(dirty&&!window.confirm(t.discard))return;
        busy=true;controls();error('');$('state').textContent=t.loading;
        try{render(await request('get'));$('state').textContent='';}catch(e){error(e.message||t.failed);$('state').textContent='';}finally{busy=false;controls();}
    }
    async function save(){
        if(busy||reportBusy||uncertain||version===null||!dirty)return;
        let data;try{data=values();}catch(e){error(e.message);return;}
        busy=true;controls();error('');$('state').textContent=t.saving;
        try{render(await request('save',data));$('state').textContent=t.saved;document.dispatchEvent(new Event('lavr-tax-settings-saved'));}
        catch(e){uncertain=!e.validation;error(e.message||t.uncertain);$('state').textContent='';}
        finally{busy=false;controls();}
    }
    root.addEventListener('toggle',()=>{if(root.open&&version===null&&!busy)load();});
    $('reload').addEventListener('click',load);$('save').addEventListener('click',save);
    window.LavkaProfitTaxSettings={async calculationVersion(){const data=await request('get');return data.version;},assertReady(){if(busy||reportBusy)throw new Error(t.busy);if(dirty||uncertain)throw new Error(t.dirty);},setReportBusy(value){reportBusy=value;controls();}};
})();
