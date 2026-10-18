(() => {
    'use strict';
    const form = document.getElementById('lps-card-form');
    if (!form) return;
    const button = document.getElementById('lps-card-preview');
    const status = document.getElementById('lps-card-status');
    const result = document.getElementById('lps-card-result');
    const t = window.lpsCards;
    const node = (tag, text) => { const el = document.createElement(tag); el.textContent = text ?? ''; return el; };
    button.addEventListener('click', async () => {
        const body = new FormData(form); body.set('action', 'lps_folio_product_cards');
        const controls = Array.from(form.querySelectorAll('button,textarea,select'));
        controls.forEach(el => { el.disabled = true; });
        status.textContent = t.reading; result.replaceChildren();
        try {
            const response = await fetch(t.url, {method: 'POST', body, credentials: 'same-origin'});
            const payload = await response.json();
            if (!response.ok || !payload.success) throw new Error(payload.data?.message || t.error);
            const d = payload.data;
            status.textContent = `${t.rows} ${d.rowCount}. ${d.identical === true ? t.match : d.identical === false ? t.difference : ''}`;
            if (d.missingSkus.length) result.append(node('p', `${t.missing} ${d.missingSkus.join(', ')}`));
            result.append(node('p', t.preview));
            const table = node('table', ''); table.className = 'widefat striped'; table.style.width = 'max-content';
            const head = node('thead', ''); const tr = node('tr', '');
            d.headers.forEach(label => { const th = node('th', label); th.style.whiteSpace = 'pre-line'; th.style.minWidth = '130px'; tr.append(th); });
            head.append(tr); table.append(head); const tbody = node('tbody', '');
            d.data.rows.forEach(row => { const r = node('tr', ''); row.forEach(value => r.append(node('td', value))); tbody.append(r); });
            table.append(tbody); result.append(table);
        } catch (error) { status.textContent = error instanceof SyntaxError || error instanceof TypeError ? t.error : error.message; }
        finally { controls.forEach(el => { el.disabled = false; }); }
    });
    form.addEventListener('submit', () => { status.textContent = t.download; });
})();
