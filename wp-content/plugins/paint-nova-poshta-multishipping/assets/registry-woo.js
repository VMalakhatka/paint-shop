/* Independent Woo associations: no order writes and no automatic partner matching. */
(() => {
    'use strict';
    const cfg = window.pnpmRegistry, form = document.querySelector('[data-command="woo_preview"]');
    if (!cfg || !form) return;
    const field = name => form.elements[name];
    const select = form.querySelector('.pnpm-woo-select'), message = form.querySelector('.pnpm-woo-message');
    const selected = form.querySelector('.pnpm-woo-selected'), load = form.querySelector('.pnpm-woo-load');
    const more = form.querySelector('.pnpm-woo-more'), submit = form.querySelector('[type="submit"]');
    let version = 0, nextPage = null, items = [];
    const clearSelection = () => {
        field('woo_order_id').value = ''; select.value = ''; selected.textContent = '';
        form.dataset.pickerBlocked = '1'; submit.disabled = true;
        form.dispatchEvent(new Event('input', {bubbles: true}));
    };
    const reset = () => {
        ++version; nextPage = null; items = []; select.replaceChildren(); more.hidden = true;
        clearSelection(); load.disabled = false; more.disabled = false; message.textContent = cfg.picker.changed;
    };
    const label = item => `#${item.order_number} (ID ${item.order_id}) · ${item.order_date} · ${item.customer_name || '—'} · ${item.order_total} ${item.order_currency} · ${cfg.labels[item.order_status] || item.order_status}`;
    async function search(append = false) {
        if (form.dataset.busy || !field('woo_email').reportValidity()) return;
        const page = append ? nextPage : 1;
        if (!page) return;
        if (!append) reset(); else clearSelection();
        const mine = ++version; load.disabled = true; more.disabled = true; message.textContent = cfg.loading;
        const body = new URLSearchParams({action: 'pnpm_registry', nonce: cfg.nonce, command: 'woo_orders', woo_page: page});
        ['woo_id','woo_email','woo_from','woo_to'].forEach(key => body.set(key, field(key).value));
        try {
            const response = await fetch(cfg.url, {method: 'POST', body, credentials: 'same-origin', cache: 'no-store'});
            const result = await response.json();
            if (!response.ok || !result.success) throw new Error(result.data?.message || cfg.failure);
            if (mine !== version || form.dataset.busy) return;
            for (const item of result.data.items) if (!items.some(old => old.order_id === item.order_id)) {
                items.push(item); select.add(new Option(label(item), String(item.order_id)));
            }
            select.value = ''; nextPage = result.data.next_page; more.hidden = !nextPage;
            message.textContent = !result.data.items.length ? cfg.picker.empty : (nextPage ? cfg.picker.more : '');
        } catch (error) { if (mine === version) message.textContent = error.message || cfg.failure; }
        finally { if (mine === version) { load.disabled = false; more.disabled = false; } }
    }
    select.addEventListener('change', () => {
        const item = items.find(item => String(item.order_id) === select.value);
        clearSelection();
        if (!item) return;
        select.value = String(item.order_id); field('woo_order_id').value = String(item.order_id);
        selected.textContent = `${cfg.picker.wooSelected} ${label(item)}`;
        form.dataset.pickerBlocked = '0'; submit.disabled = false;
    });
    ['woo_id','woo_email','woo_from','woo_to'].forEach(key => field(key).addEventListener('input', reset));
    document.querySelector('[data-command="classify"]')?.addEventListener('pnpm:classification-saving', reset);
    load.addEventListener('click', () => search()); more.addEventListener('click', () => search(true));
    reset(); message.textContent = '';
})();
