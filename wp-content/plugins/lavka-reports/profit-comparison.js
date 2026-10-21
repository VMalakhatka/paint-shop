/* Read-only analytics of explicit saved revisions. Never recalculates profit. */
(function (scope) {
    'use strict';
    const SCALE=100000000n;
    function decimal(value) {
        if(value==null || !/^-?\d+(?:\.\d{1,8})?$/.test(String(value)))return null;
        const negative=String(value).startsWith('-'), [whole,fraction='']=String(value).replace(/^-/, '').split('.');
        const n=BigInt(whole)*SCALE+BigInt(fraction.padEnd(8,'0'));return negative?-n:n;
    }
    function rounded(n,d,places=2) {
        const sign=n<0n?'-':'',a=n<0n?-n:n,factor=10n**BigInt(places);
        const q=(a*factor*2n+d)/(2n*d),s=q.toString().padStart(places+1,'0');
        return (q?sign:'')+s.slice(0,-places)+'.'+s.slice(-places);
    }
    function average(values) {
        const valid=values.map(decimal).filter(v=>v!==null);
        return {sum:valid.reduce((a,b)=>a+b,0n),count:valid.length,total:values.length,complete:values.length>0&&valid.length===values.length};
    }
    function compare(value,mean) {
        const n=decimal(value);
        if(!mean.complete)return {average:null,delta:null,percent:null};
        if(n===null)return {average:rounded(mean.sum,SCALE*BigInt(mean.count)),delta:null,percent:null};
        const count=BigInt(mean.count),difference=n*count-mean.sum;
        return {average:rounded(mean.sum,SCALE*count),delta:rounded(difference,SCALE*count),
            percent:mean.sum===0n?(n===0n?'0.00':null):rounded(difference*100n,mean.sum<0n?-mean.sum:mean.sum)};
    }
    function rows(report,t) {
        const found=new Map(),add=(id,city,label,value)=>{
            // Duplicate keys are ambiguous; do not silently choose one financial value.
            found.set(id,{id,city,label,value:found.has(id)?null:value});
        };
        const field=k=>t.fields?.[k]||k;
        for(const city of report.cities||[])for(const key of ['baseGrossProfit','manualGrossAdjustments','grossProfit','operatingExpenses','profit'])
            add(city.city+':total:'+key,city.city,field(key),city[key]);
        const detailed=Array.isArray(report.expenseLines);
        for(const line of detailed?report.expenseLines:(report.expenses||[])){
            const id=line.city+':'+(detailed?'expense:'+line.lineId:'legacy:'+line.category);
            add(id,line.city,line.label||line.category||line.lineId,line.amount);
            if(line.accountingTreatment!=='OPERATING_EXPENSE')add(id+':impact',line.city,(line.label||line.category)+' — '+field('profitImpact'),line.profitImpact);
        }
        for(const line of report.grossProfitLines||[])add(line.city+':gross:'+line.lineId,line.city,field('baseGrossProfit')+' — '+line.label,line.amount);
        const master=report.masterClassesByCity||{ODESA:report.masterClass};
        for(const [city,mk] of Object.entries(master))for(const [key,label] of [['income',t.masterIncome],['returns',t.masterReturns],['netContribution',t.masterNet]])
            add(city+':master:'+key,city,label,mk?.[key]);
        for(const city of report.inventory||[])for(const key of ['openingAccountingValue','closingAccountingValue','accountingValueChange'])
            add(city.city+':inventory:'+key,city.city,field(key),city[key]);
        return found;
    }
    const reference=w=>({month:w.month,revisionId:String(w.revisionId)});
    const validRefs=refs=>Array.isArray(refs)&&refs.length<=24&&new Set(refs.map(r=>r.month)).size===refs.length&&refs.every(r=>/^\d{4}-(0[1-9]|1[0-2])$/.test(r.month)&&/^[1-9]\d{0,14}$/.test(String(r.revisionId)));
    function mount({target,labels,reportLabels,request,runRead,storageKey}) {
        let selected=[],baseline=[],results=[],locked=false,storageFailed=false;
        const t=k=>labels[k]||reportLabels[k]||k;
        const el=(tag,text)=>{const n=document.createElement(tag);if(text!=null)n.textContent=text;return n;};
        const refs=list=>list.map(reference);
        const stamp=w=>`${w.month} / #${w.revisionId}`;
        const status=w=>w.status==='COMPLETED'?t('completed'):t('draft');
        const metadata=w=>`${stamp(w)} · ${status(w)} · ${w.report.ruleVersion||'—'}`;
        const amount=v=>{const n=decimal(v);return n===null?'—':rounded(n,SCALE).replace('.',',').replace(/\B(?=(\d{3})+(?!\d))/g,' ');};
        const percent=v=>v==null?'—':(Number(v)>0?'+':'')+amount(v)+'%';
        const city=c=>c==='KYIV'?t('kyiv'):c==='ODESA'?t('odesa'):c;
        function persist(){try{localStorage.setItem(storageKey,JSON.stringify({version:1,selected:refs(selected),baseline:refs(baseline)}));storageFailed=false;}catch(_){storageFailed=true;}}
        function action(label,fn,id){const b=el('button',t(label));b.type='button';b.className='button';b.disabled=locked;b.onclick=fn;if(id)b.id=id;return b;}
        function grid(headers,body){const wrap=el('div');wrap.className='lavr-profit-table-wrap';const table=el('table');table.className='widefat striped';const thead=el('thead'),h=el('tr');headers.forEach(v=>{const th=el('th',v);th.scope='col';h.append(th);});thead.append(h);table.append(thead);const tbody=el('tbody');body.forEach(row=>{const tr=el('tr');row.forEach(v=>tr.append(el('td',v)));tbody.append(tr);});table.append(tbody);wrap.append(table);return wrap;}
        function model(list){const maps=list.map(w=>rows(w.report,reportLabels)),all=new Map();maps.forEach(map=>map.forEach((row,id)=>all.set(id,row)));return [...all.values()].map(row=>{const values=maps.map(map=>map.get(row.id)?.value??null);return {...row,values,mean:average(values)};});}
        function comparisonRows(){return model(selected).map(r=>[city(r.city),r.label,r.mean.complete?amount(rounded(r.mean.sum,SCALE*BigInt(r.mean.count))):'—',`${r.mean.count}/${r.mean.total}`,...r.values.flatMap(v=>[amount(v),percent(compare(v,r.mean).percent)])]);}
        function comparisonHeaders(){return [t('city'),t('article'),t('mean'),t('coverage'),...selected.flatMap(w=>[stamp(w)+' · '+t('amount'),stamp(w)+' · %'])];}
        function resultRows(){
            const base=model(baseline),map=new Map(base.map(r=>[r.id,r]));const output=[];
            results.forEach(w=>{const current=rows(w.report,reportLabels),all=new Map(map);current.forEach((r,id)=>{if(!all.has(id))all.set(id,{...r,mean:average(baseline.map(()=>null))});});
                all.forEach(r=>{const v=current.get(r.id)?.value??null,c=compare(v,r.mean);output.push([stamp(w),city(r.city),r.label,c.average==null?'—':amount(c.average),amount(v),amount(c.delta),percent(c.percent),`${r.mean.count}/${r.mean.total}`]);});});return output;
        }
        const resultHeaders=()=>[t('revision'),t('city'),t('article'),t('baselineMean'),t('newValue'),t('delta'),t('percent'),t('coverage')];
        function exportCells(row,indices){return row.map((v,i)=>{if(!indices.includes(i)||v==='—')return v;const n=v.replace(/[\s%+]/g,'').replace(',','.');return /^-?\d+\.\d+$/.test(n)?{type:'number',value:n}:v;});}
        function exportComparison(){
            const sheets=[{name:t('comparison'),rows:[comparisonHeaders(),...comparisonRows().map(r=>exportCells(r,[2,...r.map((_,i)=>i).filter(i=>i>=4)]))],headerRows:[1],freezeRows:1},
                {name:t('baseline'),rows:[[t('meanHelp')],[t('baselineHelp')],[t('percentHelp')],[t('missingHelp')],...[...selected.map(w=>[t('comparison'),metadata(w),w.report.calculatedAt,JSON.stringify(w.report.inputs||{})]),...baseline.map(w=>[t('baseline'),metadata(w),w.report.calculatedAt,JSON.stringify(w.report.inputs||{})]),...results.map(w=>[t('newValue'),metadata(w),w.report.calculatedAt,JSON.stringify(w.report.inputs||{})])]]}];
            if(results.length&&baseline.length)sheets.push({name:t('deviations'),rows:[resultHeaders(),...resultRows().map(r=>exportCells(r,[3,4,5,6]))],headerRows:[1],freezeRows:1});
            // Export displayed precision as numbers; percentage columns contain percentage points.
            const url=URL.createObjectURL(new Blob([scope.LavkaProfitXlsx.build(sheets)],{type:'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'}));
            const a=el('a');a.href=url;a.download='lavka-profit-comparison.xlsx';document.body.append(a);a.click();a.remove();setTimeout(()=>URL.revokeObjectURL(url),1000);
        }
        function render(){
            target.replaceChildren(el('h2',t('comparison')),el('p',t('comparisonHelp')),el('p',t('meanHelp')));
            const list=el('ul');selected.forEach(w=>{const li=el('li',metadata(w)+' ');li.append(action('remove',()=>{selected=selected.filter(x=>x.month!==w.month);persist();render();}));list.append(li);});target.append(list);
            if(!selected.length)target.append(el('p',t('chooseComparison')));
            const tools=el('div');tools.className='lavr-profit-template-tools';
            const pin=action('freezeBaseline',()=>{baseline=selected.slice();results=[];persist();render();},'lpc-freeze');pin.disabled=locked||!selected.length;
            const clear=action('clearBaseline',()=>{baseline=[];results=[];persist();render();},'lpc-clear');clear.disabled=locked||!baseline.length;
            const download=action('comparisonExport',exportComparison,'lpc-export');download.disabled=locked||!selected.length;
            tools.append(pin,clear,download);target.append(tools);
            const base=el('div');base.id='lpc-baseline';base.append(el('h3',t('baseline')),el('p',t('baselineHelp')),el('p',baseline.length?baseline.map(metadata).join(' · '):t('noBaseline')));target.append(base);
            const combined=[...selected,...baseline,...results];
            if(combined.some(w=>w.status!=='COMPLETED'||!w.report.complete)||new Set(combined.map(w=>JSON.stringify([w.report.ruleVersion,w.report.inputs,w.report.taxDetails?.settings]))).size>1)
                target.append(el('p',t('comparisonWarning')));
            target.append(el('p',t('missingHelp')),el('p',t('percentHelp')));
            if(storageFailed)target.append(el('p',t('storageWarning')));
            if(selected.length){const box=grid(comparisonHeaders(),comparisonRows());box.id='lpc-table';target.append(box);}
            if(baseline.length&&results.length){target.append(el('h3',t('deviations')));const box=grid(resultHeaders(),resultRows());box.id='lpc-deviations';target.append(box);}
        }
        async function fetchRefs(list){
            if(!validRefs(list))throw new Error(t('comparisonLimit'));
            const loaded=[];for(const r of list){const w=await request('month',r);
                if(w.month!==r.month||String(w.revisionId)!==String(r.revisionId)||w.report?.month!==r.month||!Array.isArray(w.report.cities)||!['COMPLETED','PROVISIONAL'].includes(w.status))throw new Error(t('invalid'));
                loaded.push(w);
            }return loaded;
        }
        function add(list){return runRead(async()=>{
            const merged=new Map(refs(selected).map(r=>[r.month,r]));list.forEach(r=>merged.set(r.month,r));const newRefs=[...merged.values()].sort((a,b)=>a.month.localeCompare(b.month));
            const loaded=await fetchRefs(newRefs);selected=loaded;persist();render();
        });}
        async function restore(){let saved;try{saved=JSON.parse(localStorage.getItem(storageKey));}catch(_){storageFailed=true;render();return;}
            if(!saved)return;if(saved.version!==1||!validRefs(saved.selected)||!validRefs(saved.baseline))throw new Error(t('comparisonLimit'));
            const a=await fetchRefs(saved.selected),b=await fetchRefs(saved.baseline);selected=a;baseline=b;render();
        }
        render();return {add,restore,lock(value){locked=value;target.querySelectorAll('button').forEach(b=>b.disabled=value);if(!value)render();},
            observe(w){if(!baseline.length)return;results=results.filter(x=>x.month!==w.month);results.push(w);render();}};
    }
    const api={decimal,average,compare,rows,validRefs,mount};
    if(typeof module!=='undefined'&&module.exports)module.exports=api;else scope.LavkaProfitComparison=api;
})(typeof window!=='undefined'?window:globalThis);
