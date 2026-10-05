(() => {
    const kind=document.querySelector('#pcoe-broadcast-kind');if(!kind)return;
    const box=document.querySelector('#pcoe-broadcast-receipt'),warehouse=document.querySelector('#pcoe-broadcast-warehouse'),date=document.querySelector('#pcoe-broadcast-date'),type=document.querySelector('#pcoe-broadcast-document-type'),doc=document.querySelector('#pcoe-broadcast-document'),more=document.querySelector('#pcoe-broadcast-more'),status=document.querySelector('#pcoe-broadcast-source-status'),reload=document.querySelector('#pcoe-broadcast-reload');
    let cursor=0,version=0,loaded=false,loadingWarehouses=false;
    const request=async(kind,params={})=>{const r=await fetch(pcoeBroadcast.url,{method:'POST',credentials:'same-origin',body:new URLSearchParams({action:'pcoe_broadcast_receipts',nonce:pcoeBroadcast.nonce,kind,...params})});let j;try{j=await r.json();}catch(e){throw Error(pcoeBroadcast.error);}if(!r.ok||!j.success)throw Error(j.data?.message||pcoeBroadcast.error);return j.data;};
    const clear=()=>{doc.replaceChildren(new Option('—',''));cursor=0;more.hidden=true;};
    const documents=async(append=false)=>{
        const current=++version;if(!append)clear();if(box.hidden||!warehouse.value||!date.value){doc.disabled=false;more.disabled=false;status.textContent='';return;}
        doc.disabled=true;more.disabled=true;status.textContent=pcoeBroadcast.loading;
        try{const data=await request('documents',{warehouse:warehouse.value,date:date.value,document_type:type.value,after:append?cursor:0});if(current!==version)return;
            for(const row of data.documents||[]){
                const label=row.type==='invoice'?pcoeBroadcast.invoice:pcoeBroadcast.receipt;
                const state=row.accounted===false?` · ${pcoeBroadcast.nonAccounting}`:'';
                doc.add(new Option(`${label} ${row.number} · ${Array.isArray(row.date)?row.date.join('-'):row.date}${state}`,String(row.id)));
            }
            cursor=Number(data.nextAfterId)||0;more.hidden=!data.hasMore;status.textContent=doc.options.length>1?'':pcoeBroadcast.empty;
        }catch(e){if(current===version){clear();status.textContent=e.message;}}
        finally{if(current===version){doc.disabled=false;more.disabled=false;}}
    };
    const loadWarehouses=async()=>{
        if(loadingWarehouses)return;
        loadingWarehouses=true;warehouse.disabled=true;reload.disabled=true;status.textContent=pcoeBroadcast.loadingWarehouses;
        ++version;clear();
        try{const data=await request('warehouses');const selected=warehouse.value;warehouse.replaceChildren(new Option('—',''));
            for(const row of data.warehouses||[])warehouse.add(new Option(row.name,String(row.code)));
            loaded=warehouse.options.length>1;
            warehouse.value=selected;status.textContent=loaded?'':pcoeBroadcast.emptyWarehouses;
            if(loaded&&warehouse.value)await documents();
        }catch(e){loaded=false;warehouse.replaceChildren(new Option('—',''));status.textContent=e.message;}
        finally{loadingWarehouses=false;warehouse.disabled=false;reload.disabled=false;doc.disabled=false;more.disabled=false;}
    };
    const changeKind=()=>{
        box.hidden=kind.value!=='arrival';for(const el of [warehouse,date,type,doc])el.required=!box.hidden;
        ++version;doc.disabled=false;more.disabled=false;
        if(box.hidden){status.textContent='';return;}
        if(!loaded)loadWarehouses();else documents();
    };
    kind.addEventListener('change',changeKind);
    warehouse.addEventListener('change',()=>documents());date.addEventListener('change',()=>documents());type.addEventListener('change',()=>documents());more.addEventListener('click',()=>documents(true));reload.addEventListener('click',loadWarehouses);
    changeKind();
})();
