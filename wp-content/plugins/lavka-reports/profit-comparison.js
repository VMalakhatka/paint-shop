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
    function previousMonths(month) {
        const [year,m]=month.split('-').map(Number),index=year*12+m-1;
        return [4,3,2,1].map(offset=>{const n=index-offset;return `${String(Math.floor(n/12)).padStart(4,'0')}-${String(n%12+1).padStart(2,'0')}`;}).filter(m=>/^(?!0000)\d{4}-(0[1-9]|1[0-2])$/.test(m));
    }
    function rowBaseline(id,month,history,manual,labels={}) {
        const months=previousMonths(month),values=months.map(m=>history.find(w=>w.month===m)).filter(Boolean)
            .map(w=>rows(w.report,labels).get(id)?.value??null);
        const mean=average(values);mean.complete=mean.count>0;mean.total=4;
        if(Object.hasOwn(manual,id)&&decimal(manual[id])!==null)
            return {mean:average([manual[id]]),source:'manual',count:mean.count,months};
        return {mean,source:mean.complete?'automatic':'required',count:mean.count,months};
    }
    function mount({target,labels,reportLabels,request,runRead,storageKey}) {
        let selected=[],results=[],manual={},windows={},locked=false,storageFailed=false,error='';
        const t=k=>labels[k]||reportLabels[k]||k;
        const el=(tag,text)=>{const n=document.createElement(tag);if(text!=null)n.textContent=text;return n;};
        const refs=list=>list.map(reference);
        const stamp=w=>`${w.month} / #${w.revisionId}`;
        const status=w=>w.status==='COMPLETED'?t('completed'):t('draft');
        const metadata=w=>`${stamp(w)} · ${status(w)} · ${w.report.ruleVersion||'—'}`;
        const amount=v=>{const n=decimal(v);return n===null?'—':rounded(n,SCALE).replace('.',',').replace(/\B(?=(\d{3})+(?!\d))/g,' ');};
        const percent=v=>v==null?'—':(Number(v)>0?'+':'')+amount(v)+'%';
        const city=c=>c==='KYIV'?t('kyiv'):c==='ODESA'?t('odesa'):c;
        const allTargets=()=>[...new Map([...selected,...results].map(w=>[w.month,w])).values()];
        function persist(){try{
            const savedWindows=Object.fromEntries(allTargets().filter(w=>windows[w.month]).map(w=>[w.month,refs(windows[w.month])]));
            localStorage.setItem(storageKey,JSON.stringify({version:2,selected:refs(selected),manual,windows:savedWindows}));storageFailed=false;
        }catch(_){storageFailed=true;}}
        function action(label,fn,id){const b=el('button',t(label));b.type='button';b.className='button';b.disabled=locked;b.onclick=fn;if(id)b.id=id;return b;}
        function grid(headers,body,layout){const wrap=el('div');wrap.className='lavr-profit-table-wrap';const table=el('table');table.className='widefat striped';const thead=el('thead'),h=el('tr');headers.forEach(v=>{const th=el('th',v);th.scope='col';h.append(th);});thead.append(h);table.append(thead);const tbody=el('tbody');body.forEach((row,i)=>{const tr=el('tr');tr.dataset.rowId=layout[i].id;if(layout[i].heading)tr.className='lavr-profit-template-heading';row.forEach(v=>{const td=el('td');if(v instanceof Node)td.append(v);else td.textContent=v;tr.append(td);});tbody.append(tr);});table.append(tbody);wrap.append(table);return wrap;}
        function layoutFor(list){
            if(!list.length)return [];
            const reference=list.slice().sort((a,b)=>b.month.localeCompare(a.month))[0];
            const layout=scope.LavkaProfitManager.comparisonLayout(reference.report,reportLabels),used=new Set(layout.map(r=>r.id)),extra=new Map();
            list.forEach(w=>rows(w.report,reportLabels).forEach((r,id)=>{
                if(!used.has(id)&&/:(expense|legacy|gross):/.test(id)&&!id.endsWith(':impact')&&(decimal(r.value)===null||decimal(r.value)!==0n))extra.set(id,{...r,number:'—'});
            }));
            return ['KYIV','ODESA',...new Set([...extra.values()].map(r=>r.city).filter(c=>!['KYIV','ODESA'].includes(c)))].flatMap(c=>[...layout.filter(r=>r.city===c),...extra.values()].filter(r=>r.city===c));
        }
        function manualInput(row){const input=el('input');input.type='text';input.inputMode='decimal';input.className='lpc-manual';input.value=manual[row.id]??'';input.disabled=locked;
            input.setAttribute('aria-label',t('manualBaseline')+' · '+city(row.city)+' · '+row.label);input.dataset.baselineId=row.id;
            input.placeholder=t('automaticBaseline');input.addEventListener('change',()=>{
                const value=input.value.trim().replace(/\s/g,'').replace(',','.').replace(/^\+/,'');
                if(value!==''&&(!/^-?\d{1,18}(?:\.\d{1,8})?$/.test(value)||decimal(value)===null)){input.setCustomValidity(t('invalidBaseline'));input.reportValidity();return;}
                input.setCustomValidity('');if(value==='')delete manual[row.id];else manual[row.id]=value;persist();render();
            });return input;}
        function tableHeaders(list){return ['№',t('article'),t('manualBaseline'),...list.flatMap(w=>[stamp(w)+' · '+t('amount'),stamp(w)+' · '+t('usedBaseline'),stamp(w)+' · '+t('baselineSource'),stamp(w)+' · '+t('delta'),stamp(w)+' · %'])];}
        function tableRows(list,layout,editable=false){
            const maps=list.map(w=>rows(w.report,reportLabels));
            return layout.map(r=>[r.number,r.label,editable?manualInput(r):amount(manual[r.id]),...list.flatMap((w,i)=>{
                const value=maps[i].get(r.id)?.value??null,b=rowBaseline(r.id,w.month,windows[w.month]||[],manual,reportLabels),c=compare(value,b.mean);
                if(b.source!=='manual'&&!Object.hasOwn(windows,w.month))b.source='unavailable';
                const source=b.source==='unavailable'?t('historyUnavailable'):b.source==='manual'?t('manualBaseline'):b.source==='required'?t('requiredBaseline'):t('automaticBaseline')+` (${b.count}/4)`;
                return [amount(value),c.average==null?(b.source==='unavailable'?t('historyUnavailable'):t('requiredBaseline')):amount(c.average),source,amount(c.delta),percent(c.percent)];
            })]);
        }
        function exportCells(row){return row.map((v,i)=>{
            if(i===0&&typeof v==='number')return {type:'number',value:String(v)};if(i<2||v==='—')return v;const n=String(v).replace(/[\s%+]/g,'').replace(',','.');return /^-?\d+\.\d+$/.test(n)?{type:'number',value:n}:v;
        });}
        function exportComparison(){
            const sheets=[],add=(list,prefix)=>{const layout=layoutFor(list);['KYIV','ODESA'].forEach(c=>{const group=layout.filter(r=>r.city===c);if(group.length)sheets.push({name:city(c)+' '+prefix,rows:[tableHeaders(list),...tableRows(list,group).map(exportCells)],headerRows:[1],freezeRows:1});});};
            if(selected.length)add(selected,t('comparison'));if(results.length)add(results,t('deviations'));
            sheets.push({name:t('baseline'),rows:[[t('meanHelp')],[t('baselineHelp')],[t('percentHelp')],[t('missingHelp')],
                ...selected.map(w=>[t('comparison'),metadata(w),w.report.calculatedAt,JSON.stringify(w.report.inputs||{})]),
                ...results.map(w=>[t('newValue'),metadata(w),w.report.calculatedAt,JSON.stringify(w.report.inputs||{})]),
                ...allTargets().flatMap(w=>[...[['target',stamp(w),'window',previousMonths(w.month).join(', ')]],...(windows[w.month]||[]).map(v=>[w.month,t('baseline'),metadata(v),v.report.calculatedAt,JSON.stringify(v.report.inputs||{})])]),
                ...Object.entries(manual).map(([id,value])=>[t('manualBaseline'),id,{type:'number',value}]) ]});
            const url=URL.createObjectURL(new Blob([scope.LavkaProfitXlsx.build(sheets)],{type:'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'}));
            const a=el('a');a.href=url;a.download='lavka-profit-comparison.xlsx';document.body.append(a);a.click();a.remove();setTimeout(()=>URL.revokeObjectURL(url),1000);
        }
        function renderTables(list,id,editable){const box=el('div');box.id=id;const layout=layoutFor(list);
            for(const c of new Set(layout.map(r=>r.city))){const group=layout.filter(r=>r.city===c);box.append(el('h3',city(c)),grid(tableHeaders(list),tableRows(list,group,editable),group));}target.append(box);}
        function render(){
            target.replaceChildren(el('h2',t('comparison')),el('p',t('comparisonHelp')),el('p',t('meanHelp')));
            const list=el('ul');selected.forEach(w=>{const li=el('li',metadata(w)+' ');li.append(action('remove',()=>{selected=selected.filter(x=>x.month!==w.month);persist();render();}));list.append(li);});target.append(list);
            if(!selected.length)target.append(el('p',t('chooseComparison')));
            const tools=el('div');tools.className='lavr-profit-template-tools';
            const refresh=action('refreshBaseline',()=>runRead(async()=>{const next=await fetchWindows(allTargets(),true);windows=next;error='';persist();render();}),'lpc-refresh');refresh.disabled=locked||!allTargets().length;
            const download=action('comparisonExport',exportComparison,'lpc-export');download.disabled=locked||!allTargets().length;
            tools.append(refresh,download);target.append(tools);
            const base=el('details');base.id='lpc-baseline';base.append(el('summary',t('baseline')),el('p',t('baselineHelp')));
            allTargets().forEach(w=>base.append(el('p',w.month+' ← '+previousMonths(w.month).join(', ')+' · '+((windows[w.month]||[]).map(metadata).join(' · ')||t('requiredBaseline')))));target.append(base);
            const combined=[...selected,...Object.values(windows).flat(),...results];
            if(combined.some(w=>w.status!=='COMPLETED'||!w.report.complete)||new Set(combined.map(w=>JSON.stringify([w.report.ruleVersion,w.report.inputs,w.report.taxDetails?.settings]))).size>1)target.append(el('p',t('comparisonWarning')));
            target.append(el('p',t('missingHelp')),el('p',t('percentHelp')));
            if(storageFailed)target.append(el('p',t('storageWarning')));if(error){const p=el('p',t('historyUnavailable')+' '+error);p.setAttribute('role','alert');target.append(p);}
            if(selected.length)renderTables(selected,'lpc-table',true);
            if(results.length){target.append(el('h3',t('deviations')));renderTables(results,'lpc-deviations',true);}
        }
        async function fetchRefs(list){
            if(!validRefs(list))throw new Error(t('comparisonLimit'));
            const loaded=[];for(const r of list){const w=await request('month',r);
                if(w.month!==r.month||String(w.revisionId)!==String(r.revisionId)||w.report?.month!==r.month||!Array.isArray(w.report.cities)||!['COMPLETED','PROVISIONAL'].includes(w.status))throw new Error(t('invalid'));
                loaded.push(w);
            }return loaded;
        }
        async function fetchWindows(targets,force=false){
            const next={...windows},cache=new Map();
            for(const w of targets){if(!force&&Object.hasOwn(next,w.month))continue;
                const months=previousMonths(w.month);if(!months.length){next[w.month]=[];continue;}
                const range=await request('range',{fromMonth:months[0],toMonth:months.at(-1)});
                if(range.fromMonth!==months[0]||range.toMonth!==months.at(-1)||!Array.isArray(range.months)||range.months.length!==months.length||new Set(range.months.map(r=>r.month)).size!==months.length||!months.every(m=>range.months.some(r=>r.month===m)))throw new Error(t('invalid'));
                const list=[];for(const m of range.months){
                    const id=['COMPLETED','PROVISIONAL'].includes(m.latestStatus)&&m.latestRevisionId?m.latestRevisionId:m.revisionId;
                    if(!id)continue;const ref={month:m.month,revisionId:String(id)},key=ref.month+':'+ref.revisionId;
                    if(!cache.has(key))cache.set(key,(await fetchRefs([ref]))[0]);list.push(cache.get(key));
                }next[w.month]=list;
            }return next;
        }
        function add(list){return runRead(async()=>{
            const merged=new Map(refs(selected).map(r=>[r.month,r]));list.forEach(r=>merged.set(r.month,r));const newRefs=[...merged.values()].sort((a,b)=>a.month.localeCompare(b.month));
            const loaded=await fetchRefs(newRefs),next=await fetchWindows(loaded);selected=loaded;windows=next;error='';persist();render();
        });}
        async function restore(){let saved;try{saved=JSON.parse(localStorage.getItem(storageKey));}catch(_){storageFailed=true;render();return;}
            if(!saved)return;if(![1,2].includes(saved.version)||!validRefs(saved.selected))throw new Error(t('comparisonLimit'));
            const loaded=await fetchRefs(saved.selected),restored={};
            if(saved.version===2){
                if(saved.manual&&typeof saved.manual==='object'&&!Array.isArray(saved.manual))for(const [id,value] of Object.entries(saved.manual))
                    if(id.length<250&&typeof value==='string'&&/^-?\d{1,18}(?:\.\d{1,8})?$/.test(value))manual[id]=value;
                for(const w of loaded){const ids=saved.windows?.[w.month];if(ids&&validRefs(ids)&&ids.every(r=>previousMonths(w.month).includes(r.month)))restored[w.month]=await fetchRefs(ids);}
            }
            windows=restored;windows=await fetchWindows(loaded);selected=loaded;persist();render();
        }
        render();return {add,restore,lock(value){locked=value;target.querySelectorAll('button,input').forEach(b=>b.disabled=value);if(!value)render();},
            async observe(w){
                results=results.filter(x=>x.month!==w.month);results.push(w);if(results.length>24)results.shift();
                try{windows=await fetchWindows([w]);error='';}catch(e){error=e.message||t('error');}
                persist();render();
            }};
    }
    const api={decimal,average,compare,rows,previousMonths,rowBaseline,validRefs,mount};
    if(typeof module!=='undefined'&&module.exports)module.exports=api;else scope.LavkaProfitComparison=api;
})(typeof window!=='undefined'?window:globalThis);
