(function () {
    'use strict';
    const cfg = window.LavkaDocumentAudit, root = document.getElementById('lavr-document-audit');
    if (!cfg || !root) return;
    const t = key => cfg.i18n[key] || key;
    const $ = id => document.getElementById('lda-' + id);
    const str = v => v == null ? '' : Array.isArray(v) ? v.join(', ') : typeof v === 'object' ? JSON.stringify(v) : String(v);
    const el = (tag, text, className) => { const n = document.createElement(tag); if (text != null) n.textContent = str(text); if (className) n.className = className; return n; };
    const statuses = ['VALID', 'ERROR', 'RULE_REVIEW'];
    const status = item => statuses.includes(item.status) ? item.status : 'RULE_REVIEW';
    let items = [], metadata = null, busy = false, complete = false, stale = false, stopped = false, pageIndex = 0, controller = null;
    let dates = null, upper = null, version = null, cursor = 0, seen = new Set();
    const today = new Date(), iso = d => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
    $('from').value = iso(new Date(today.getFullYear(), today.getMonth(), 1)); $('to').value = iso(today);
    const sourceColumns = ['status','category','date','number','id','register','direction','warehouse','amount','currency','amountCurrencyStatus','org','organizationName','purpose','operation','sourceInfo','note','period','periodEvidence','masked','recognition','profitTreatment','ruleIds','findings'];
    const columns = ['status','category','findings',...sourceColumns.filter(k => !['status','category','findings'].includes(k))];
    function findingText(f) {
        return [f.code, `${t('field')}: ${str(f.field)}`, `${t('actual')}: ${str(f.actual)}`, `${t('expected')}: ${str(f.expected)}`, `${t('recommendation')}: ${str(f.recommendation)}`, f.ruleId].filter(Boolean).join('\n');
    }
    function cells(item) {
        const d = item.document, c = item.category;
        const values = [t(status(item)) + (statuses.includes(item.status) ? '' : ` (${str(item.status)})`), `${str(c.label)} [${str(c.code)}]`, d.documentDate, d.documentNumber, str(d.paymentId), d.bank === true ? t('bank') : d.bank === false ? t('cash') : t('unknown'), t(d.direction), d.warehouseId, d.amount, d.currencyCode, d.amountCurrencyStatus, d.organizationCode, d.organizationName, d.purposeCode, d.operationType, d.sourceInfo, d.note == null ? d.periodEvidence : d.note, d.resolvedPeriod, d.periodEvidence, d.sensitiveValuesMasked ? t('yes') : t('no'), c.recognition, c.profitTreatment, str(item.ruleIds), (item.findings || []).map(findingText).join('\n\n')];
        return columns.map(k => values[sourceColumns.indexOf(k)]);
    }
    function controls() {
        $('run').disabled = busy; $('stop').disabled = !busy || stopped;
        $('export').disabled = busy || stale || !metadata;
    }
    function pair(container, label, value) {
        const p = el('p'); p.append(el('strong', label + ': '), document.createTextNode(str(value))); container.append(p);
    }
    function renderCoverage() {
        $('result').hidden = !metadata;
        if (!metadata) return;
        const counts = $('counts'); counts.replaceChildren();
        const byStatus = Object.fromEntries(statuses.map(s => [s, items.filter(i => status(i) === s).length]));
        [[t('loaded'), items.length], [t('total'), metadata.page.totalDocuments], ...statuses.map(s => [t(s), byStatus[s]])].forEach(([k,v]) => {
            const card = el('div', null, 'lda-count'); card.append(el('span', k), el('strong', v)); counts.append(card);
        });
        const box = $('coverage'); box.replaceChildren();
        pair(box, t('from'), dates.dateFrom); pair(box, t('to'), dates.dateTo);
        pair(box, t('version'), version); pair(box, t('calculated'), metadata.calculatedAt);
        pair(box, t('complete'), complete ? t('yes') : t('no'));
        pair(box, t('unsupported'), metadata.coverage.unsupported);
        pair(box, t('warnings'), metadata.coverage.warnings);
        const details = el('details'); details.append(el('summary', t('manifest'))); box.append(details);
        for (const k of ['source','dateBasis','consistency','allWarehouses','directions','registers','rulesComplete','pageComplete']) {
            const value = metadata.coverage[k];
            pair(details, t(k), typeof value === 'boolean' ? t(value ? 'yes' : 'no') : value);
        }
        pair(box, t('amountCurrencyStatus'), t('amountHelp'));
    }
    function table(headers, rows) {
        const tab = el('table', null, 'widefat striped'); const head = el('thead'), hr = el('tr');
        headers.forEach(h => { const th = el('th', h); th.scope = 'col'; hr.append(th); }); head.append(hr); tab.append(head);
        const body = el('tbody'); rows.forEach(row => { const tr = el('tr'); row.forEach(v => tr.append(el('td', v))); body.append(tr); }); tab.append(body); return tab;
    }
    function renderRegistry() {
        if (!metadata) return;
        const cats = $('category'), previous = cats.value;
        const categories = new Map(items.map(i => [i.category.code, i.category.label]));
        cats.replaceChildren(new Option(t('all'), ''));
        [...categories.entries()].sort((a,b) => str(a[1]).localeCompare(str(b[1]))).forEach(([k,v]) => cats.add(new Option(`${v} [${k}]`, k)));
        if (categories.has(previous)) cats.value = previous;
        const search = $('search').value.trim().toLocaleLowerCase();
        const filtered = items.filter(i => (!$('status').value || status(i) === $('status').value) && (!cats.value || i.category.code === cats.value) && (!search || cells(i).map(str).join(' ').toLocaleLowerCase().includes(search)));
        pageIndex = Math.min(pageIndex, Math.max(0, Math.ceil(filtered.length / 50) - 1));
        const start = pageIndex * 50, slice = filtered.slice(start, start + 50);
        $('registry').replaceChildren(slice.length ? table(columns.map(t), slice.map(cells)) : el('p', t('empty')));
        $('page').textContent = `${t('shown')}: ${slice.length ? start + 1 : 0}–${start + slice.length} / ${filtered.length}`;
        $('prev').disabled = pageIndex === 0; $('next').disabled = start + 50 >= filtered.length;
    }
    function renderRules() {
        const box = $('rules'); box.replaceChildren();
        for (const rule of metadata.rules || []) {
            const detail = el('details'); detail.append(el('summary', `${str(rule.label)} [${str(rule.id)}]`));
            pair(detail, t('sheet'), `${str(rule.sourceSheet)}: ${str(rule.sourceRows)}`);
            for (const k of ['categoryCode','evidence','requiredSourceInfo','periodRequirement','expectedOperationTypes','organizationCodes','limitations']) pair(detail, t(k), rule[k]);
            box.append(detail);
        }
    }
    function validPage(body) {
        const p = body.page;
        if (!body.ok || body.status !== 'PAGE_READY' || !p || !body.coverage || !body.coverage.pageComplete || !Array.isArray(body.items) || !Array.isArray(body.rules) || !body.rulesVersion || body.dateFrom !== dates.dateFrom || body.dateTo !== dates.dateTo) return false;
        if (![p.upperPaymentId, p.afterPaymentId, p.totalDocuments].every(v => Number.isSafeInteger(v) && v >= 0) || p.afterPaymentId !== cursor || typeof p.hasMore !== 'boolean') return false;
        if (upper !== null && (p.upperPaymentId !== upper || body.rulesVersion !== version || body.coverage.source !== metadata.coverage.source || body.coverage.consistency !== metadata.coverage.consistency)) return false;
        let last = cursor;
        for (const item of body.items) {
            const id = item.document && item.document.paymentId;
            if (!Number.isSafeInteger(id) || id <= last || id > p.upperPaymentId || seen.has(id) || !item.category || !item.category.code || !Array.isArray(item.findings) || !Array.isArray(item.ruleIds)) return false;
            last = id;
        }
        if (p.hasMore && (body.items.length === 0 || p.nextAfterPaymentId !== last)) return false;
        if (!body.summary || body.summary.scope !== 'PAGE' || body.summary.examined !== body.items.length) return false;
        return true;
    }
    async function loadPage() {
        const params = new URLSearchParams({action:cfg.action, nonce:cfg.nonce, ...dates});
        if (upper !== null) { params.set('afterPaymentId', String(cursor)); params.set('upperPaymentId', String(upper)); params.set('expectedRulesVersion', version); }
        controller = new AbortController();
        const timer = setTimeout(() => controller.abort(), 175000);
        try {
            const response = await fetch(cfg.ajaxUrl, {method:'POST',credentials:'same-origin',body:params,signal:controller.signal});
            const envelope = await response.json();
            if (!envelope.success) throw new Error(envelope.data && envelope.data.message || t('error'));
            const body = JSON.parse(envelope.data.bodyRaw);
            const errorCode = body.errorCode || body.code;
            if (errorCode === 'DOCUMENT_AUDIT_DISABLED') throw new Error(t('disabled'));
            if (envelope.data.httpStatus !== 200 || !body.ok) throw new Error(`${t('error')} ${str(errorCode)} ${str(body.errorId)}`.trim());
            if (!validPage(body)) throw new Error(t('invalid'));
            return body;
        } finally { clearTimeout(timer); }
    }
    $('form').addEventListener('submit', async event => {
        event.preventDefault(); if (busy) return;
        const from = $('from').value, to = $('to').value, span = (Date.parse(to) - Date.parse(from)) / 86400000;
        if (!from || !to || !Number.isFinite(span) || span < 0 || span > 365) { $('error').textContent = t('dates'); $('error').hidden = false; return; }
        busy = true; stopped = false; complete = false; stale = false; items = []; metadata = null; upper = null; version = null; cursor = 0; seen = new Set(); pageIndex = 0;
        dates = {dateFrom:from, dateTo:to}; $('result').hidden = true; $('error').hidden = true; $('state').textContent = t('loading'); controls();
        try {
            while (!stopped) {
                const body = await loadPage();
                metadata = body; upper = body.page.upperPaymentId; version = body.rulesVersion;
                body.items.forEach(item => { seen.add(item.document.paymentId); items.push(item); });
                renderCoverage(); renderRegistry(); renderRules();
                $('state').textContent = `${t('loading')} ${items.length} / ${body.page.totalDocuments}`;
                if (!body.page.hasMore) { complete = items.length === body.page.totalDocuments; break; }
                cursor = body.page.nextAfterPaymentId;
            }
        } catch (error) {
            $('error').hidden = false; $('error').textContent = stopped ? t('partial') : (error.name === 'AbortError' ? t('error') : error.message);
        } finally {
            busy = false; controller = null;
            stale = $('from').value !== dates.dateFrom || $('to').value !== dates.dateTo;
            $('state').textContent = stale ? t('stale') : complete ? t('ready') : t('partial');
            renderCoverage(); controls();
        }
    });
    $('stop').addEventListener('click', () => { stopped = true; if (controller) controller.abort(); controls(); });
    ['from','to'].forEach(k => $(k).addEventListener('input', () => { if (metadata || busy) { stale = true; if (busy) { stopped = true; if (controller) controller.abort(); } $('state').textContent = t('stale'); controls(); } }));
    ['status','category','search'].forEach(k => $(k).addEventListener(k === 'search' ? 'input' : 'change', () => { pageIndex = 0; renderRegistry(); }));
    $('prev').addEventListener('click', () => { pageIndex--; renderRegistry(); });
    $('next').addEventListener('click', () => { pageIndex++; renderRegistry(); });
    $('export').addEventListener('click', () => {
        if (busy || stale || !metadata) return;
        try {
            const manifest = [[t('manifest'), t('title')], [t('from'),dates.dateFrom],[t('to'),dates.dateTo],[t('version'),version],[t('calculated'),metadata.calculatedAt],[t('complete'),complete ? t('yes') : t('no')],[t('loaded'),items.length],[t('total'),metadata.page.totalDocuments],[t('consistency'),t('live')],[t('validHelp'),t('validHelp')],[t('amountCurrencyStatus'),t('amountHelp')]];
            Object.entries(metadata.coverage).forEach(([k,v]) => manifest.push([t(k),str(v)]));
            manifest.push(['upperPaymentId',str(upper)]);
            const rows = items.map(i => cells(i).map((v,n) => n === columns.indexOf('amount') && /^-?\d+(\.\d+)?$/.test(str(v)) ? {type:'number',value:str(v),money:true} : str(v)));
            const findings = items.flatMap(i => i.findings.map(f => [str(i.document.paymentId),i.document.documentNumber,i.status,f.code,f.severity,f.field,f.actual,f.expected,f.recommendation,f.ruleId]));
            const ruleKeys = ['id','categoryCode','label','sourceSheet','sourceRows','evidence','requiredSourceInfo','periodRequirement','expectedOperationTypes','organizationCodes','limitations'];
            const bytes = window.LavkaProfitXlsx.build([
                {name:t('manifest'),rows:manifest,widths:[38,110],headerRows:[1],freezeRows:1},
                {name:t('registry'),rows:[columns.map(t),...rows],widths:columns.map(k => ['note','findings'].includes(k) ? 65 : 24),headerRows:[1],freezeRows:1},
                {name:t('findings'),rows:[[t('id'),t('number'),t('status'),t('code'),t('status'),t('field'),t('actual'),t('expected'),t('recommendation'),t('ruleIds')],...findings],widths:[18,18,22,32,20,22,45,45,60,30],headerRows:[1],freezeRows:1},
                {name:t('rules'),rows:[ruleKeys.map(t),...(metadata.rules || []).map(r => ruleKeys.map(k => str(r[k])))],widths:ruleKeys.map(() => 40),headerRows:[1],freezeRows:1}
            ]);
            const url = URL.createObjectURL(new Blob([bytes],{type:'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'}));
            const a = el('a'); a.href = url; a.download = `folio-document-audit-${dates.dateFrom}-${dates.dateTo}${complete ? '' : '-partial'}.xlsx`; document.body.append(a); a.click(); a.remove(); setTimeout(() => URL.revokeObjectURL(url),1000);
        } catch (error) { $('error').hidden = false; $('error').textContent = t('error') + ' ' + error.message; }
    });
})();
