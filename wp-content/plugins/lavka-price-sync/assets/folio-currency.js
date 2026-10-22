(() => {
    'use strict';
    const form = document.getElementById('lps-currency-form');
    if (!form) return;
    const status = document.getElementById('lps-currency-status'), result = document.getElementById('lps-currency-result');
    const apply = document.getElementById('lps-currency-apply'), check = document.getElementById('lps-currency-check'), confirm = document.getElementById('lps-currency-confirm');
    let job = '', preview = false, busy = false;
    function buttons() { apply.disabled = busy || !preview || !confirm.checked; confirm.disabled = busy || !preview; check.disabled = busy || !job; }
    function render(data) {
        job = data.job; preview = data.status === 'PREVIEW' && data.before.length > 0;
        confirm.checked = false; result.replaceChildren();
        status.textContent = (lpsCurrency.states[data.status] || data.status) + ' — ' + data.filename;
        const history = document.getElementById('lps-currency-history');
        let operation = Array.from(history.querySelectorAll('button')).find(b => b.dataset.job === data.job);
        if (!operation) { const li = document.createElement('li'); operation = document.createElement('button'); operation.type = 'button'; operation.className = 'button-link lps-currency-history'; operation.dataset.job = data.job; li.append(operation); history.prepend(li); }
        operation.textContent = data.created + ' — ' + data.filename + ' — ' + (lpsCurrency.states[data.status] || data.status);
        for (const [label, value] of [[lpsCurrency.cards, data.cardRows], [lpsCurrency.retail, data.retailRows], [lpsCurrency.missingCards, (data.missingCards || []).join(', ')], [lpsCurrency.missingRetail, (data.missingRetail || []).join(', ')]]) {
            const p = document.createElement('p'); p.textContent = label + ': ' + value; result.append(p);
        }
        const box = document.createElement('div'); box.style.overflow = 'auto'; box.style.maxHeight = '65vh';
        const table = document.createElement('table'); table.className = 'widefat striped';
        const head = table.createTHead().insertRow();
        lpsCurrency.columns.forEach(label => { const th = document.createElement('th'); th.textContent = label; head.append(th); });
        const body = table.createTBody();
        data.before.forEach(row => {
            const input = data.input[row.input], tr = body.insertRow();
            [row.table, row.sku, row.warehouse, row.id, row.price, input.price, row.currency, row.table === 'SCL_ARTC' ? input.currency : null].forEach(value => { tr.insertCell().textContent = value === null ? '—' : String(value); });
        });
        box.append(table); result.append(box); buttons();
    }
    async function request(operation, data) {
        if (busy) return; busy = true; buttons(); form.querySelector('button').disabled = true;
        status.textContent = lpsCurrency.busy;
        data.set('action', 'lps_currency'); data.set('operation', operation); data.set('_wpnonce', form.querySelector('[name="_wpnonce"]').value);
        try {
            const response = await fetch(lpsCurrency.url, { method: 'POST', body: data, credentials: 'same-origin' });
            const payload = await response.json();
            if (!response.ok || !payload.success) throw new Error(payload.data?.message || lpsCurrency.error);
            render(payload.data);
        } catch (error) { status.textContent = error.message || lpsCurrency.error; if (operation === 'apply') preview = false; }
        finally { busy = false; form.querySelector('button').disabled = false; buttons(); }
    }
    form.addEventListener('submit', e => { e.preventDefault(); preview = false; confirm.checked = false; request('preview', new FormData(form)); });
    form.querySelector('[type="file"]').addEventListener('change', () => { preview = false; confirm.checked = false; buttons(); });
    confirm.addEventListener('change', buttons);
    apply.addEventListener('click', () => { const data = new FormData(); data.set('job', job); data.set('confirmed', '1'); request('apply', data); });
    check.addEventListener('click', () => { const data = new FormData(); data.set('job', job); request('status', data); });
    document.getElementById('lps-currency-history').addEventListener('click', event => { const button = event.target.closest('button[data-job]'); if (!button || busy) return; const data = new FormData(); data.set('job', button.dataset.job); request('status', data); });
})();
