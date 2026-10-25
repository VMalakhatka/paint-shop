/* Read-only Folio selectors. Every selection still uses the registry preview/confirm flow. */
(() => {
    'use strict';
    const cfg = window.pnpmRegistry;
    if (!cfg?.picker) return;
    const text = cfg.picker;
    const request = async (command, values = {}) => {
        const body = new URLSearchParams({action: 'pnpm_registry', nonce: cfg.nonce, command, ...values});
        const response = await fetch(cfg.url, {method: 'POST', body, credentials: 'same-origin', cache: 'no-store'});
        const result = await response.json();
        if (!response.ok || !result.success) throw new Error(result.data?.message || cfg.failure);
        return result.data;
    };
    const changed = form => form.dispatchEvent(new Event('input', {bubbles: true}));
    const classification = document.querySelector('[data-command="classify"]');
    const initial = classification ? ['partner_code', 'direction', 'purpose'].map(key => classification.elements[key].value) : [];
    const classificationDirty = () => classification && (classification.dataset.pickerBlocked === '1' || ['partner_code', 'direction', 'purpose'].some((key, i) => classification.elements[key].value !== initial[i]));
    document.querySelectorAll('.pnpm-partner-picker').forEach(box => {
        const form = box.closest('form'), search = box.querySelector('input[type="search"]');
        const results = box.querySelector('.pnpm-partner-results'), current = box.querySelector('strong');
        let version = 0, timer;
        const pick = item => {
            ++version; clearTimeout(timer); search.value = ''; results.replaceChildren();
            form.elements.partner_code.value = item?.code || '';
            current.textContent = item ? `${item.code} — ${item.name}` : '—';
            delete form.dataset.pickerBlocked; form.querySelector('[type="submit"]').disabled = false;
            changed(form);
        };
        search.addEventListener('input', () => {
            const mine = ++version, q = search.value.trim(); clearTimeout(timer); results.replaceChildren();
            form.dataset.pickerBlocked = q ? '1' : '0'; form.querySelector('[type="submit"]').disabled = !!q;
            if (!q) return;
            results.textContent = text.selectPartner;
            if (q.length < 2) return;
            timer = setTimeout(async () => {
                results.textContent = cfg.loading;
                try {
                    const data = await request('partners', {q});
                    if (mine !== version || form.dataset.busy) return;
                    results.replaceChildren();
                    if (!data.items.length) results.textContent = text.empty;
                    data.items.forEach(item => {
                        const button = document.createElement('button'); button.type = 'button'; button.className = 'button';
                        button.textContent = `${item.code} — ${item.name}`;
                        button.addEventListener('click', () => pick(item)); results.append(button);
                    });
                } catch (error) { if (mine === version) results.textContent = error.message || cfg.failure; }
            }, 300);
        });
        box.querySelector('.pnpm-partner-clear').addEventListener('click', () => pick(null));
    });
    const form = document.querySelector('[data-command="link_preview"]');
    if (!form) return;
    const field = name => form.elements[name];
    const picker = form.querySelector('.pnpm-document-picker'), manual = form.querySelector('.pnpm-manual-fields');
    const select = form.querySelector('.pnpm-document-select'), status = form.querySelector('.pnpm-picker-message');
    const selected = form.querySelector('.pnpm-document-selected'), more = form.querySelector('.pnpm-documents-more');
    const submit = form.querySelector('[type="submit"]'), load = form.querySelector('.pnpm-documents-load');
    const warehouseButton = form.querySelector('.pnpm-warehouses');
    const requirement = form.querySelector('.pnpm-link-requirement');
    let version = 0, warehouseVersion = 0, cursor = '', items = [], selectedItem = null, reading = false;
    const apiMode = () => field('verification').value === 'api';
    const block = () => {
        const needsAccount = field('role').value === 'sale_expense';
        field('account_link_id').closest('label').hidden = !needsAccount;
        requirement.textContent = classificationDirty() || !field('partner').value ? text.savedPartner : (needsAccount && !field('account_link_id').value ? text.account : '');
        const blocked = classificationDirty() || !field('partner').value || reading || (apiMode() && !selectedItem)
            || (needsAccount && !field('account_link_id').value);
        form.dataset.pickerBlocked = blocked ? '1' : '0'; submit.disabled = !!blocked;
    };
    const clearSelection = () => {
        selectedItem = null; select.value = ''; selected.textContent = '';
        ['document_id', 'number', 'date', 'warehouse'].forEach(key => { field(key).value = ''; });
        changed(form); block();
    };
    const reset = () => {
        ++version; reading = false; cursor = ''; items = []; select.replaceChildren(); more.hidden = true;
        clearSelection(); load.disabled = false; more.disabled = false;
        status.textContent = text.changed;
    };
    const mode = () => {
        reset(); picker.hidden = !apiMode(); manual.open = !apiMode();
        ['document_id', 'number', 'date', 'warehouse'].forEach(key => { field(key).readOnly = apiMode(); });
        if (!apiMode()) selected.textContent = text.manual;
        block();
    };
    const documentLabel = item => `${item.number} · ${item.date}${item.amount !== '' ? ` · ${Number(item.amount).toLocaleString(document.documentElement.lang || 'uk', {minimumFractionDigits: 2, maximumFractionDigits: 2})} UAH` : ''}`;
    select.addEventListener('change', () => {
        selectedItem = items.find(item => item.id === select.value) || null;
        if (!selectedItem) { clearSelection(); return; }
        ['document_id', 'number', 'date', 'warehouse'].forEach(key => { field(key).value = selectedItem[key === 'document_id' ? 'id' : key]; });
        selected.textContent = `${text.selected} ${documentLabel(selectedItem)}`;
        changed(form); block();
    });
    async function documents(append = false) {
        if (form.dataset.busy || reading || classificationDirty() || !field('partner').value || !apiMode()) {
            if (classificationDirty() || !field('partner').value) status.textContent = text.savedPartner;
            return;
        }
        const nextCursor = append ? cursor : '';
        if (!append) reset(); else clearSelection();
        const mine = ++version; reading = true; block(); load.disabled = true; more.disabled = true; status.textContent = cfg.loading;
        try {
            const data = await request('documents', {id: field('id').value, revision: field('revision').value, type: field('type').value,
                warehouse: field('picker_warehouse').value, date_from: field('date_from').value, date_to: field('date_to').value, cursor: nextCursor});
            if (mine !== version || form.dataset.busy) return;
            for (const item of data.items) if (!items.some(old => old.id === item.id)) { items.push(item); select.add(new Option(documentLabel(item), item.id)); }
            select.value = ''; cursor = data.cursor || ''; more.hidden = !cursor;
            status.textContent = [!data.items.length ? text.empty : '', cursor ? text.more : '', data.receipt ? text.receipt : '', data.read_date || ''].filter(Boolean).join(' ');
        } catch (error) { if (mine === version) status.textContent = error.message || cfg.failure; }
        finally { if (mine === version) { reading = false; load.disabled = false; more.disabled = false; block(); } }
    }
    async function warehouses() {
        const mine = ++warehouseVersion; reset(); field('picker_warehouse').replaceChildren(new Option('—', ''));
        warehouseButton.disabled = true; status.textContent = cfg.loading;
        try {
            const data = await request('warehouses');
            if (mine !== warehouseVersion || form.dataset.busy) return;
            for (const item of data.items) field('picker_warehouse').add(new Option(`${item.name} (${item.code})`, item.code));
            status.textContent = data.items.length ? '' : text.empty;
        } catch (error) { if (mine === warehouseVersion) status.textContent = error.message || cfg.failure; }
        finally { if (mine === warehouseVersion) warehouseButton.disabled = false; }
    }
    warehouseButton.addEventListener('click', warehouses);
    load.addEventListener('click', () => documents()); more.addEventListener('click', () => documents(true));
    ['picker_warehouse', 'date_from', 'date_to'].forEach(key => field(key).addEventListener('input', reset));
    field('type').addEventListener('change', () => {
        const type = field('type').value;
        field('role').value = {ACCOUNT: 'sale_account', EXPENSE: 'sale_expense', RECEIPT: 'purchase', RETURN: classification?.elements.purpose.value || 'customer_return'}[type];
        if (!field('role').value) field('role').value = 'other';
        field('verification').value = type === 'RETURN' ? 'manual' : 'api'; mode();
    });
    field('verification').addEventListener('change', mode);
    field('role').addEventListener('change', () => { changed(form); block(); });
    field('account_link_id').addEventListener('change', () => { changed(form); block(); });
    classification?.addEventListener('input', () => { if (classificationDirty()) { reset(); status.textContent = text.savedPartner; } else block(); });
    if (field('type').value === 'RETURN') field('verification').value = 'manual';
    mode(); warehouses();
})();
