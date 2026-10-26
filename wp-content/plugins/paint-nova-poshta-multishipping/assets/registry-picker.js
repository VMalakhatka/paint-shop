/* Folio selectors are read-only. Classification saves are explicit; links still use preview/confirm. */
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
    const classificationKeys = ['partner_code', 'direction', 'purpose', 'related_ttn', 'note'];
    let initial = classification ? classificationKeys.map(key => classification.elements[key].value) : [];
    const classificationDirty = () => classification && (classification.dataset.busy === '1' || classification.dataset.pickerBlocked === '1' || classificationKeys.some((key, i) => classification.elements[key].value !== initial[i]));
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
    const rows = form.querySelector('.pnpm-document-rows'), status = form.querySelector('.pnpm-picker-message');
    const selected = form.querySelector('.pnpm-document-selected'), more = form.querySelector('.pnpm-documents-more');
    const submit = form.querySelector('[type="submit"]'), load = form.querySelector('.pnpm-documents-load');
    const warehouseButton = form.querySelector('.pnpm-warehouses');
    const requirement = form.querySelector('.pnpm-link-requirement');
    const continueBox = form.querySelector('.pnpm-classification-continue'), saveContinue = form.querySelector('.pnpm-save-continue');
    const partnerHelp = document.querySelector('.pnpm-partner-save-help');
    let version = 0, warehouseVersion = 0, cursor = '', items = [], reading = false;
    const selectedIds = new Set(), warehouseNames = new Map();
    const warehouseOptions = form.querySelector('.pnpm-warehouse-options');
    const apiMode = () => field('verification').value === 'api';
    const block = () => {
        field('partner').value = classification?.elements.partner_code.value || '';
        const dirty = classificationDirty();
        picker.disabled = classification?.dataset.busy === '1';
        continueBox.hidden = !dirty;
        saveContinue.disabled = !classification || classification.dataset.busy === '1' || classification.dataset.pickerBlocked === '1' || !field('partner').value;
        if (partnerHelp) partnerHelp.hidden = !!field('partner').value;
        load.disabled = reading || dirty || !field('partner').value;
        const needsAccount = field('role').value === 'sale_expense';
        field('account_link_id').closest('label').hidden = !needsAccount;
        requirement.textContent = classificationDirty() || !field('partner').value ? text.savedPartner : '';
        const blocked = classificationDirty() || !field('partner').value || reading || (apiMode() && (!selectedIds.size || selectedIds.size > 20));
        form.dataset.pickerBlocked = blocked ? '1' : '0'; submit.disabled = !!blocked;
    };
    const syncSelection = () => {
        const chosen = items.filter(item => selectedIds.has(item.id));
        field('selected_documents').value = JSON.stringify(chosen.map(({id, number, date, warehouse}) => ({id, number, date, warehouse})));
        ['document_id', 'number', 'date', 'warehouse'].forEach(key => { field(key).value = chosen[0]?.[key === 'document_id' ? 'id' : key] || ''; });
        selected.textContent = chosen.length ? `${text.selectedCount} ${chosen.length} — ${chosen.map(item => item.number).join(', ')}` : '';
        changed(form); block();
    };
    const reset = () => {
        ++version; reading = false; cursor = ''; items = []; selectedIds.clear(); rows.replaceChildren(); more.hidden = true;
        syncSelection(); more.disabled = false;
        status.textContent = text.changed;
    };
    const mode = () => {
        reset(); picker.hidden = !apiMode(); manual.open = !apiMode();
        ['document_id', 'number', 'date', 'warehouse'].forEach(key => { field(key).readOnly = apiMode(); });
        if (!apiMode()) selected.textContent = text.manual;
        block();
    };
    const renderRows = () => {
        rows.replaceChildren();
        items.forEach(item => {
            const row = document.createElement('tr'), cell = document.createElement('td'), checkbox = document.createElement('input');
            checkbox.type = 'checkbox'; checkbox.checked = selectedIds.has(item.id);
            checkbox.setAttribute('aria-label', `${text.selectDocument} ${item.number}`);
            checkbox.addEventListener('change', () => {
                if (checkbox.checked && selectedIds.size >= 20) { checkbox.checked = false; status.textContent = text.limit; return; }
                if (checkbox.checked) selectedIds.add(item.id); else selectedIds.delete(item.id);
                syncSelection();
            });
            cell.append(checkbox); row.append(cell);
            const amount = item.amount !== '' ? `${Number(item.amount).toLocaleString(document.documentElement.lang || 'uk', {minimumFractionDigits: 2, maximumFractionDigits: 2})} UAH` : '—';
            [item.number, item.date, amount, [item.partner, item.partner_name].filter(Boolean).join(' — '),
                `${warehouseNames.get(item.warehouse) || '—'} (${item.warehouse})`, item.operation, item.source_info, item.additional_info].forEach(value => {
                const td = document.createElement('td'); td.textContent = value || '—'; row.append(td);
            });
            rows.append(row);
        });
    };
    async function documents(append = false) {
        if (form.dataset.busy || reading || classificationDirty() || !field('partner').value || !apiMode()) {
            if (classificationDirty() || !field('partner').value) status.textContent = text.savedPartner;
            return;
        }
        const nextCursor = append ? cursor : '';
        if (!append) reset(); else changed(form);
        const mine = ++version; reading = true; block(); load.disabled = true; more.disabled = true; status.textContent = cfg.loading;
        try {
            const data = await request('documents', {id: field('id').value, revision: field('revision').value, type: field('type').value,
                warehouses: field('picker_warehouse').value, operation: field('operation').value, date_from: field('date_from').value, date_to: field('date_to').value, cursor: nextCursor});
            if (mine !== version || form.dataset.busy) return;
            for (const item of data.items) if (!items.some(old => old.id === item.id)) items.push(item);
            renderRows(); cursor = data.cursor || ''; more.hidden = !cursor;
            status.textContent = [!data.items.length ? text.empty : '', cursor ? text.more : '', data.receipt ? text.receipt : '', data.read_date || ''].filter(Boolean).join(' ');
        } catch (error) { if (mine === version) status.textContent = error.message || cfg.failure; }
        finally { if (mine === version) { reading = false; load.disabled = false; more.disabled = false; block(); } }
    }
    async function warehouses() {
        const keep = new Set(field('picker_warehouse').value.split(','));
        const mine = ++warehouseVersion; reset(); warehouseOptions.replaceChildren(); field('picker_warehouse').value = ''; warehouseNames.clear();
        warehouseButton.disabled = true; status.textContent = cfg.loading;
        try {
            const data = await request('warehouses');
            if (mine !== warehouseVersion || form.dataset.busy) return;
            const syncWarehouses = () => {
                field('picker_warehouse').value = [...warehouseOptions.querySelectorAll('input:checked')].map(input => input.value).join(',');
                reset();
            };
            for (const item of data.items) {
                warehouseNames.set(item.code, item.name);
                const label = document.createElement('label'), checkbox = document.createElement('input');
                checkbox.type = 'checkbox'; checkbox.value = item.code; checkbox.checked = keep.has(item.code);
                checkbox.addEventListener('change', syncWarehouses);
                label.append(checkbox, document.createTextNode(` ${item.name} (${item.code})`)); warehouseOptions.append(label);
            }
            syncWarehouses(); status.textContent = data.items.length ? '' : text.empty;
        } catch (error) { if (mine === warehouseVersion) status.textContent = error.message || cfg.failure; }
        finally { if (mine === warehouseVersion) warehouseButton.disabled = false; }
    }
    warehouseButton.addEventListener('click', warehouses);
    saveContinue.addEventListener('click', () => {
        if (!saveContinue.disabled) classification.requestSubmit();
    });
    classification?.addEventListener('pnpm:classification-saving', () => { reset(); block(); });
    classification?.addEventListener('pnpm:classification-saved', event => {
        const saved = event.detail.classification;
        initial = classificationKeys.map(key => String(saved[key] ?? ''));
        // The server owns the accepted snapshot and revision. Refresh every form for this record.
        classificationKeys.forEach((key, i) => { classification.elements[key].value = initial[i]; });
        document.querySelectorAll('.pnpm-registry-form').forEach(target => {
            if (target.elements.id?.value === String(event.detail.id) && target.elements.revision) {
                target.elements.revision.value = String(saved.revision);
                changed(target);
            }
        });
        field('partner').value = saved.partner_code;
        if (saved.direction === 'internal') {
            field('type').value = 'EXPENSE'; field('role').value = 'other'; field('verification').value = 'api'; mode();
        } else reset();
    });
    classification?.addEventListener('pnpm:classification-finished', () => {
        block(); status.textContent = classificationDirty() ? (classification.querySelector('.pnpm-message').textContent || text.savedPartner) : text.changed;
    });
    load.addEventListener('click', () => documents()); more.addEventListener('click', () => documents(true));
    ['operation', 'date_from', 'date_to'].forEach(key => field(key).addEventListener('input', reset));
    field('type').addEventListener('change', () => {
        const type = field('type').value;
        field('role').value = {ACCOUNT: 'sale_account', EXPENSE: 'sale_expense', RECEIPT: 'purchase', RETURN: classification?.elements.purpose.value || 'customer_return'}[type];
        if (!field('role').value || classification?.elements.direction.value === 'internal') field('role').value = 'other';
        field('verification').value = type === 'RETURN' ? 'manual' : 'api'; mode();
    });
    field('verification').addEventListener('change', mode);
    field('role').addEventListener('change', () => { changed(form); block(); });
    field('account_link_id').addEventListener('change', () => { changed(form); block(); });
    classification?.addEventListener('input', () => { if (classificationDirty()) { reset(); status.textContent = text.savedPartner; } else block(); });
    if (field('type').value === 'RETURN') field('verification').value = 'manual';
    mode(); warehouses();
})();
