(function () {
    'use strict';
    const cfg = window.LavkaProfitHistoryConfig, viewer = window.LavkaProfitViewer;
    const root = document.getElementById('lavr-profit-history');
    if (!cfg || !viewer || !root) return;
    const labels = cfg.i18n, reportLabels = window.LavkaProfitReport.i18n || {};
    const t = k => labels[k] || reportLabels[k] || reportLabels.fields && reportLabels.fields[k] || k;
    const $ = k => document.getElementById('lph-' + k);
    const text = v => v == null ? '—' : Array.isArray(v) ? v.map(text).join(', ') : typeof v === 'object' ? JSON.stringify(v) : String(v);
    const node = (tag, value) => { const n = document.createElement(tag); if (value != null) n.textContent = text(value); return n; };
    const money = value => { if (value == null) return '—'; const m = String(value).match(/^(-?)(\d+)(?:\.(\d+))?$/); return m ? m[1]+m[2].replace(/\B(?=(\d{3})+(?!\d))/g,' ')+','+(m[3]||'').padEnd(2,'0')+' '+(reportLabels.uah || 'UAH') : text(value); };
    const moneyKeys = ['baseGrossProfit','manualGrossAdjustments','grossProfit','operatingExpenses','profit','openingAccountingValue','closingAccountingValue','accountingValueChange'];
    let saved = null, generation = 0, reading = false, running = false, exporting = false, stop = false, dirty = true;
    let requestNote = '', lockedFields = [];
    const formatTime = value => { if(value==null)return '—'; const n=Number(value), d=new Date(Number.isFinite(n)?(n<100000000000?n*1000:n):value); return Number.isNaN(d.getTime())?text(value):new Intl.DateTimeFormat('uk-UA',{timeZone:'Europe/Kyiv',dateStyle:'short',timeStyle:'short'}).format(d); };
    const activeStates = new Set(['COMPLETED','PROVISIONAL']);
    const terminalLabel = s => ({COMPLETED:t('completed'),RUNNING:t('runningStatus'),FAILED:t('failedStatus'),PROVISIONAL:t('draft'),MISSING:t('missing')})[s] || s;
    function months(from, to) {
        if (![from,to].every(v => /^\d{4}-(0[1-9]|1[0-2])$/.test(v))) throw new Error(t('dates'));
        const index = v => Number(v.slice(0,4))*12+Number(v.slice(5))-1;
        const a=index(from), b=index(to);
        if (a>b || b-a>23) throw new Error(t('dates')+' (24)');
        return Array.from({length:b-a+1},(_,n)=>`${String(Math.floor((a+n)/12)).padStart(4,'0')}-${String((a+n)%12+1).padStart(2,'0')}`);
    }
    function currentRange() { return {fromMonth:$('from').value,toMonth:$('to').value}; }
    function controls() {
        const busy = reading || running || exporting;
        $('view').disabled=busy; $('calculate').disabled=busy; $('stop').disabled=!running || stop;
        $('export').disabled=busy || dirty || !saved || !saved.months.some(m=>m.revisionId);
        root.querySelectorAll('tbody button').forEach(b=>{b.disabled=busy;});
        ['lavr-profit-calculate','lavr-profit-recalculate'].forEach(id=>{document.getElementById(id).disabled=busy;});
    }
    function setError(message) { $('error').textContent=message || requestNote; $('error').hidden=!$('error').textContent; }
    function remember(attempt) {
        requestNote = attempt ? `${t('uncertain')} ${attempt.month} · ${t('requestId')}: ${attempt.requestId}` : '';
        try { if (attempt) sessionStorage.setItem('lavkaProfitSaveAttempt',JSON.stringify(attempt)); else sessionStorage.removeItem('lavkaProfitSaveAttempt'); } catch (_) { /* Server history remains authoritative. */ }
    }
    async function request(operation, params) {
        const form=new URLSearchParams({action:cfg.action,nonce:cfg.nonce,operation,...params});
        const controller=new AbortController(), timeout=setTimeout(()=>controller.abort(),operation==='calculate'?175000:45000);
        try {
            const res=await fetch(cfg.ajaxUrl,{method:'POST',credentials:'same-origin',body:form,signal:controller.signal});
            const env=await res.json();
            if (!env.success) throw new Error(env.data && env.data.message || t('error'));
            const data=JSON.parse(env.data.bodyRaw), code=data.errorCode || data.code;
            if (env.data.httpStatus<200 || env.data.httpStatus>=300 || (data.ok===false && !(['month','calculate'].includes(operation) && ['RUNNING','FAILED','MISSING'].includes(data.status)))) throw new Error([data.message || t('error'),code,data.errorId].filter(Boolean).join(' · '));
            return data;
        } finally { clearTimeout(timeout); }
    }
    function button(label, action) { const b=node('button',label);b.type='button';b.className='button';b.addEventListener('click',action);return b; }
    function table(headers, rows) {
        const tab=node('table');tab.className='widefat striped';const head=node('thead'),tr=node('tr');headers.forEach(x=>{const th=node('th',x);th.scope='col';tr.append(th);});head.append(tr);tab.append(head);
        const body=node('tbody'); rows.forEach(row=>{const tr=node('tr');row.forEach(v=>{const td=node('td');td.append(v instanceof Node ? v : document.createTextNode(text(v)));tr.append(td);});body.append(tr);});tab.append(body);return tab;
    }
    function renderRange() {
        $('results').hidden=!saved; if (!saved) return;
        $('coverage').textContent=`${saved.fromMonth} — ${saved.toMonth} · ${saved.complete?t('ready'):t('partial')}`;
        (saved.warnings||[]).forEach(w=>$('coverage').append(node('p',typeof w==='string'?w:(w.message || text(w)))));
        const rows=saved.months.map(m=>{
            const actions=node('div');
            if(m.revisionId) actions.append(button(t('open'),()=>openMonth(m.month,m.revisionId)));
            actions.append(button(t('revisions'),()=>loadRevisions(m.month)));
            const city=c=>(m.cities||[]).find(row=>row.city===c);
            return [m.month,terminalLabel(m.status),m.revisionId,text(m.latestRevisionId)+' / '+terminalLabel(m.latestStatus),m.ruleVersion,formatTime(m.calculatedAt),money(city('KYIV')?.profit),money(city('ODESA')?.profit),actions];
        });
        $('months').replaceChildren(table([t('month'),t('status'),t('revision'),t('latest'),t('rulesVersion'),t('calculatedAt'),t('kyiv'),t('odesa'),t('open')],rows));
        const cols=['city',...moneyKeys,'complete'];
        $('totals').replaceChildren(table(cols.map(t),(saved.totals||[]).map(r=>cols.map(k=>moneyKeys.includes(k)?money(r[k]):k==='city'?t(r[k]==='KYIV'?'kyiv':'odesa'):typeof r[k]==='boolean'?t(r[k]?'yes':'no'):text(r[k])))));
        if (!saved.months.some(m=>m.revisionId)) $('coverage').append(node('p',t('empty')));
    }
    function validateRange(data, params) {
        const expected=months(params.fromMonth,params.toMonth);
        if (data.fromMonth!==params.fromMonth || data.toMonth!==params.toMonth || !Array.isArray(data.months) || !Array.isArray(data.totals) || data.months.length!==expected.length || new Set(data.months.map(m=>m.month)).size!==expected.length || !expected.every(m=>data.months.some(row=>row.month===m))) throw new Error(t('invalid'));
    }
    async function loadRange(quiet=false) {
        if (reading || exporting || (running && !quiet)) return;
        const params=currentRange(); try { months(params.fromMonth,params.toMonth); } catch(e){setError(e.message);return;}
        const token=++generation; reading=true; controls(); if(!quiet)$('state').textContent=t('loading');setError('');
        try {
            const data=await request('range',params);if(token!==generation)return;
            validateRange(data,params);saved=data;dirty=false;renderRange();
            if(!quiet)$('state').textContent=t('ready');
        } catch(e){if(token===generation){dirty=true;setError(e.message || t('error'));}}
        finally {reading=false;controls();}
    }
    async function openMonth(month, revisionId) {
        if(reading||running||exporting)return;
        const token=++generation, viewVersion=viewer.version();reading=true;controls();setError('');
        try {
            const data=await request('month',{month,revisionId});if(token!==generation)return;
            if(viewVersion!==viewer.version()){setError(t('stale'));return;}
            if(data.month!==month || String(data.revisionId)!==String(revisionId))throw new Error(t('invalid'));
            $('selected').textContent=`${t('readonly')}: ${month} · ${t('revision')}: ${revisionId} · ${terminalLabel(data.status)} · ${t('published')}: ${text(data.publishedRevisionId)}`;
            if (!data.report) {viewer.clear();setError([terminalLabel(data.status),data.errorCode,data.requestId].filter(Boolean).join(' · '));return;}
            viewer.showSaved(data);
        } catch(e){if(token===generation)setError(e.message || t('error'));}
        finally{reading=false;controls();}
    }
    async function loadRevisions(month, before='') {
        if(reading||running||exporting)return;
        const token=++generation;reading=true;controls();setError('');
        try{
            const data=await request('revisions',{month,...(before?{beforeRevisionId:String(before)}:{})});if(token!==generation)return;
            if(data.month!==month || !Array.isArray(data.revisions))throw new Error(t('invalid'));
            const box=$('revisions');box.hidden=false;
            if(!before)box.replaceChildren(node('h3',t('revisions')+': '+month));
            box.querySelector('[data-more]')?.remove();
            box.append(table([t('revision'),t('status'),t('savedAt'),t('requestId'),t('open')],data.revisions.map(r=>[r.revisionId,terminalLabel(r.status),r.completedAt||r.createdAt,r.requestId,button(t('open'),()=>openMonth(month,r.revisionId))])));
            if(data.hasMore && data.nextBeforeRevisionId){const b=button(t('more'),()=>loadRevisions(month,data.nextBeforeRevisionId));b.dataset.more='1';box.append(b);}
        }catch(e){if(token===generation)setError(e.message || t('error'));}
        finally{reading=false;controls();}
    }
    function lockInputs(lock) {
        if(lock){lockedFields=[...document.querySelectorAll('#lavr-profit-history input,#lavr-profit-month,#lavr-profit-manual input')].map(n=>[n,n.disabled]);lockedFields.forEach(([n])=>{n.disabled=true;});}
        else {lockedFields.forEach(([n,disabled])=>{n.disabled=disabled;});lockedFields=[];}
    }
    async function calculate(selectedMonths, params) {
        if(reading||running||exporting)return false;
        running=true;stop=false;++generation;lockInputs(true);controls();setError('');
        let failed=false;
        try{
            for(const month of selectedMonths){
                if(stop)break;
                const requestId=crypto.randomUUID();remember({month,requestId});
                $('state').textContent=`${t('running')}: ${month} (${selectedMonths.indexOf(month)+1}/${selectedMonths.length}) · ${requestId}`;
                const data=await request('calculate',{...params,month,requestId});
                if(data.month!==month || data.requestId!==requestId || !activeStates.has(data.status) || !data.report || !data.revisionId)throw new Error(t('uncertain')+' '+text(data.status));
                remember(null);viewer.showSaved(data);lockedFields.forEach(([n])=>{n.disabled=true;});controls();
                $('selected').textContent=`${t('readonly')}: ${month} · ${t('revision')}: ${data.revisionId} · ${terminalLabel(data.status)} · ${t('published')}: ${text(data.publishedRevisionId)}`;
            }
        }catch(e){failed=true;setError((e.message||t('error'))+'\n'+requestNote);}
        finally{
            running=false;lockInputs(false);controls();
            const previousError=$('error').hidden?'':$('error').textContent;
            await loadRange(true);
            if(previousError)setError(previousError);
            $('state').textContent=failed?t('uncertain'):stop?t('stopped'):t('finished');
        }
        return !failed;
    }
    async function exportRange() {
        if(reading||running||exporting||dirty||!saved)return;
        exporting=true;controls();setError('');const token=generation, data=saved;
        try{
            const cols=['city',...moneyKeys,'complete'];
            const sheets=[{name:t('range'),rows:[['period',data.fromMonth,data.toMonth],['complete',text(data.complete)],...[[...cols.map(t)],...(data.totals||[]).map(r=>cols.map(k=>moneyKeys.includes(k)&&r[k]!=null?{type:'number',value:r[k],money:true}:text(r[k])))]],widths:cols.map(()=>24),headerRows:[3],freezeRows:3},
                {name:t('month'),rows:[[t('month'),t('revision'),t('status'),t('rulesVersion')],...data.months.map(m=>[m.month,text(m.revisionId),m.status,text(m.ruleVersion)])],widths:[20,24,24,25],headerRows:[1]},
                {name:t('warningsTitle'),rows:[['warnings',text(data.warnings)],['missingMonths',text(data.missingMonths)],['sourceDatabase',text(data.sourceDatabase)]]}];
            for(const m of data.months.filter(m=>m.revisionId)){
                $('state').textContent=t('export')+': '+m.month;
                const wrapper=await request('month',{month:m.month,revisionId:String(m.revisionId)});
                if(token!==generation)return;
                if(wrapper.month!==m.month || String(wrapper.revisionId)!==String(m.revisionId) || !wrapper.report)throw new Error(t('invalid'));
                viewer.sheets(wrapper).forEach((s,i)=>sheets.push({...s,name:`${m.month} ${i+1} ${s.name}`.slice(0,31)}));
            }
            const bytes=window.LavkaProfitXlsx.build(sheets),url=URL.createObjectURL(new Blob([bytes],{type:'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'}));
            const a=node('a');a.href=url;a.download=`lavka-profit-saved-${data.fromMonth}-${data.toMonth}.xlsx`;document.body.append(a);a.click();a.remove();setTimeout(()=>URL.revokeObjectURL(url),1000);$('state').textContent=t('ready');
        }catch(e){setError(e.message||t('error'));}finally{exporting=false;controls();}
    }
    window.LavkaProfitHistory={calculateMonth(params){try{months(params.month,params.month);return calculate([params.month],params);}catch(e){setError(e.message);return false;}}};
    const initial=document.getElementById('lavr-profit-month').value;$('from').value=initial;$('to').value=initial;
    $('view').addEventListener('click',()=>loadRange());
    $('calculate').addEventListener('click',()=>{try{const r=currentRange();calculate(months(r.fromMonth,r.toMonth),viewer.params());}catch(e){setError(e.message);}});
    $('stop').addEventListener('click',()=>{stop=true;controls();});$('export').addEventListener('click',exportRange);
    ['from','to'].forEach(k=>$(k).addEventListener('input',()=>{++generation;dirty=true;saved=null;$('results').hidden=true;$('revisions').hidden=true;viewer.clear();$('selected').textContent='';$('state').textContent=t('stale');controls();}));
    const l=labels; document.getElementById('lavr-profit-calculate').textContent=l.single;document.getElementById('lavr-profit-recalculate').textContent=l.single;document.getElementById('lavr-profit-load-audit').textContent=l.savedAudit;
    const hint=document.querySelector('#lavr-profit-result > p.description');if(hint)hint.textContent=l.savedExportHelp;
    try{const pending=JSON.parse(sessionStorage.getItem('lavkaProfitSaveAttempt'));if(pending)remember(pending);}catch(_){}
    loadRange();
})();
