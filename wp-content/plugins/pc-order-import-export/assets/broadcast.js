(() => {
    const kind=document.querySelector('#pcoe-broadcast-kind');if(!kind)return;
    const box=document.querySelector('#pcoe-broadcast-receipt'),warehouse=document.querySelector('#pcoe-broadcast-warehouse'),date=document.querySelector('#pcoe-broadcast-date'),doc=document.querySelector('#pcoe-broadcast-document'),more=document.querySelector('#pcoe-broadcast-more'),status=document.querySelector('#pcoe-broadcast-source-status');
    let cursor=0,version=0,loaded=false;
    const request=async(kind,params={})=>{const r=await fetch(pcoeBroadcast.url,{method:'POST',credentials:'same-origin',body:new URLSearchParams({action:'pcoe_broadcast_receipts',nonce:pcoeBroadcast.nonce,kind,...params})});const j=await r.json();if(!r.ok||!j.success)throw Error(j.data?.message||pcoeBroadcast.error);return j.data;};
    const clear=()=>{doc.replaceChildren(new Option('—',''));cursor=0;more.hidden=true;};
    const documents=async(append=false)=>{
        const current=++version;if(!append)clear();if(!warehouse.value||!date.value){doc.disabled=false;more.disabled=false;status.textContent='';return;}
        doc.disabled=true;more.disabled=true;status.textContent=pcoeBroadcast.loading;
        try{const data=await request('documents',{warehouse:warehouse.value,date:date.value,after:append?cursor:0});if(current!==version)return;
            for(const row of data.documents||[])doc.add(new Option(`${row.number} · ${Array.isArray(row.date)?row.date.join('-'):row.date}`,String(row.id)));
            cursor=Number(data.nextAfterId)||0;more.hidden=!data.hasMore;status.textContent=doc.options.length>1?'':pcoeBroadcast.empty;
        }catch(e){if(current===version){clear();status.textContent=e.message;}}
        finally{if(current===version){doc.disabled=false;more.disabled=false;}}
    };
    kind.addEventListener('change',async()=>{
        box.hidden=kind.value!=='arrival';for(const el of [warehouse,date,doc])el.required=!box.hidden;
        if(box.hidden||loaded)return;warehouse.disabled=true;status.textContent=pcoeBroadcast.loading;
        try{const data=await request('warehouses');for(const row of data.warehouses||[])warehouse.add(new Option(row.name,String(row.code)));loaded=true;status.textContent='';}
        catch(e){status.textContent=e.message;}finally{warehouse.disabled=false;}
    });
    warehouse.addEventListener('change',()=>documents());date.addEventListener('change',()=>documents());more.addEventListener('click',()=>documents(true));
})();
