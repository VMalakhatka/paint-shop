(() => {
    'use strict';
    const form = document.getElementById('lps-manager-form');
    if (!form) return;
    const el = id => document.getElementById('lps-manager-' + id);
    const kind = el('kind'), status = el('status'), result = el('result'), apply = el('apply'), check = el('check'), confirm = el('confirm'), download = el('download');
    let job = '', preview = false, busy = false;
    function buttons() {
        apply.disabled = busy || !preview || !confirm.checked;
        confirm.disabled = busy || !preview; check.disabled = busy || !job;
        for (const control of form.elements) control.disabled = busy;
    }
    function invalidate() { preview = false; confirm.checked = false; download.hidden = true; buttons(); }
    function operation() {
        invalidate(); result.replaceChildren(); status.textContent = '';
        el('columns').textContent = lpsManager.columns[kind.value].join(' | ');
        el('scope').textContent = lpsManager.scopes[kind.value];
    }
    function table(headers, rows) {
        const box = document.createElement('div'); box.style.overflow = 'auto'; box.style.maxHeight = '55vh'; box.style.marginBottom = '16px';
        const table = document.createElement('table'); table.className = 'widefat striped';
        const head = table.createTHead().insertRow();
        headers.forEach(label => { const th = document.createElement('th'); th.textContent = label; head.append(th); });
        const body = table.createTBody();
        rows.forEach(row => { const tr = body.insertRow(); row.forEach(value => { const td = tr.insertCell(); td.textContent = value === null ? lpsManager.clear : String(value); td.style.whiteSpace = 'pre-wrap'; }); });
        box.append(table); result.append(box);
    }
    function render(data) {
        kind.value = data.operation;
        el('columns').textContent = lpsManager.columns[kind.value].join(' | ');
        el('scope').textContent = lpsManager.scopes[kind.value];
        form.querySelector('[type="file"]').value = '';
        const columns = lpsManager.columns[data.operation];
        const quote = value => '"' + String(value ?? '').replace(/"/g, '""') + '"';
        el('paste').value = [columns, ...data.input.map(row => columns.map(c => row[c]))].map(row => row.map(quote).join('\t')).join('\n');
        job = data.job; preview = data.status === 'PREVIEW' && (data.before.length > 0 || data.operation === 'DESCRIPTION');
        confirm.checked = false; result.replaceChildren(); status.textContent = (lpsManager.states[data.status] || data.status) + ' — ' + data.filename;
        download.href = data.download; download.hidden = false;
        let button = Array.from(el('history').querySelectorAll('button')).find(b => b.dataset.job === job);
        if (!button) { const li = document.createElement('li'); button = document.createElement('button'); button.type = 'button'; button.className = 'button-link'; button.dataset.job = job; li.append(button); el('history').prepend(li); }
        button.textContent = data.created + ' — ' + data.filename + ' — ' + (lpsManager.states[data.status] || data.status);
        if (data.missing.length) { const p = document.createElement('p'); p.textContent = lpsManager.missing + ': ' + data.missing.join(', '); result.append(p); }
        for (const issue of data.issues) { const p = document.createElement('p'); p.textContent = lpsManager.issues[issue] || issue; result.append(p); }
        const changes = [];
        for (const row of data.before) for (const [field, old] of Object.entries(row.values)) changes.push([row.sku, row.warehouse, field, old, data.input[row.input][field]]);
        if (changes.length) table(lpsManager.headers, changes);
        if (data.operation === 'DESCRIPTION') {
            const existing = new Set(data.before.map(r => r.input));
            const inserts = data.input.filter((r, i) => !existing.has(i));
            if (inserts.length) table(['COD_ARTIC', 'DESCRIPTION'], inserts.map(r => [r.COD_ARTIC, r.DESCRIPTION]));
        }
        for (const [name, output] of Object.entries(data.outputs)) { const h = document.createElement('h3'); h.textContent = name; result.append(h); table(output.columns, output.rows); }
        buttons();
    }
    async function request(operation, data) {
        if (busy) return;
        data.set('_wpnonce', form.querySelector('[name="_wpnonce"]').value);
        busy = true; buttons(); status.textContent = lpsManager.busy;
        data.set('action', 'lps_manager'); data.set('operation', operation);
        try {
            const response = await fetch(lpsManager.url, {method: 'POST', body: data, credentials: 'same-origin'});
            const payload = await response.json();
            if (!response.ok || !payload.success) throw new Error(payload.data?.message || lpsManager.error);
            render(payload.data);
        } catch (error) { status.textContent = error.message || lpsManager.error; if (operation === 'apply') preview = false; }
        finally { busy = false; buttons(); }
    }
    form.addEventListener('submit', e => { e.preventDefault(); invalidate(); request('preview', new FormData(form)); });
    kind.addEventListener('change', operation);
    form.querySelector('[type="file"]').addEventListener('change', invalidate);
    el('paste').addEventListener('input', invalidate);
    confirm.addEventListener('change', buttons);
    apply.addEventListener('click', () => { const data = new FormData(); data.set('job', job); data.set('confirmed', '1'); request('apply', data); });
    check.addEventListener('click', () => { const data = new FormData(); data.set('job', job); request('status', data); });
    el('history').addEventListener('click', e => { const button = e.target.closest('button[data-job]'); if (!button || busy) return; const data = new FormData(); data.set('job', button.dataset.job); request('status', data); });
    operation();
})();
