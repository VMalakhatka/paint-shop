(function () {
    'use strict';
    const root = document.getElementById('lps-purchase');
    if (!root) return;
    const config = window.LPS_PURCHASE || {};
    const t = config.i18n || {};
    const el = (name) => document.getElementById('lps-purchase-' + name);
    const number = new Intl.NumberFormat(config.locale || 'uk', { maximumFractionDigits: 3 });
    const state = { token: '', rows: [], page: 0, busy: false, complete: false, controller: null, requestId: 0 };
    const escape = (value) => String(value == null ? '' : value).replace(/[&<>"']/g, (character) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[character]);
    const display = (value) => value == null ? '—' : number.format(value);
    function message(text, error) {
        el('message').textContent = text;
        el('message').className = 'notice inline ' + (error ? 'notice-error' : 'notice-info');
    }
    function busy(value) {
        state.busy = value;
        root.setAttribute('aria-busy', String(value));
        el('spinner').classList.toggle('is-active', value);
        el('start').disabled = value;
        el('scenario').disabled = value;
        el('cancel').disabled = !value;
        root.querySelectorAll('[data-export]').forEach((button) => { button.disabled = value || !state.complete; });
        root.querySelectorAll('[data-edit-form] button').forEach((button) => { button.disabled = value || !state.complete; });
    }
    async function api(operation, payload) {
        const body = new URLSearchParams({ action: 'lps_purchase_planning', _ajax_nonce: config.nonce, operation: operation, payload: JSON.stringify(payload) });
        const response = await fetch(config.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body, signal: state.controller.signal });
        let json;
        try { json = await response.json(); } catch (error) { throw new Error(t.error); }
        if (!response.ok || !json.success) throw new Error(json.data && json.data.message || t.error);
        return json.data;
    }
    function input(field, value, label, type) {
        return '<label><span>' + escape(label) + '</span><input data-field="' + field + '" type="' + (type || 'number') + '"' + (type === 'text' ? ' maxlength="500"' : ' min="' + (field === 'pack' ? '0.000001' : '0') + '" step="any"') + ' value="' + escape(value) + '"></label>';
    }
    function render() {
        const pages = Math.max(1, Math.ceil(state.rows.length / 25));
        state.page = Math.min(state.page, pages - 1);
        el('page').textContent = (state.page + 1) + ' / ' + pages;
        el('prev').disabled = state.page === 0;
        el('next').disabled = state.page >= pages - 1;
        const headings = ['group', 'physical', 'available', 'sales', 'returns', 'coverage', 'target', 'need', 'transfer', 'purchase', 'final'];
        el('results').innerHTML = state.rows.slice(state.page * 25, state.page * 25 + 25).map((row, offset) => {
            const index = state.page * 25 + offset;
            return '<section class="lps-purchase-sku"><h2>' + escape(row.sku) + ' · ' + escape(row.productName) + '</h2><p>' + escape(row.supplier.join(', ')) + ' · ' + escape(t.transitPool) + ': ' + display(row.transitPool) + '</p>' +
                '<div class="lps-purchase-scroll"><table class="widefat striped"><thead><tr>' + headings.map((key) => '<th>' + escape(t[key]) + '</th>').join('') + '</tr></thead><tbody>' +
                row.groups.map((group) => '<tr><th>' + escape(group.groupName) + '<small>' + escape(t.receivingWarehouse) + ': ' + group.receivingWarehouseId + '</small></th>' +
                    ['physical', 'available', 'regularSales', 'returns', 'coverageDays', 'target', 'needBeforeReceipts'].map((key) => '<td>' + display(group[key]) + '</td>').join('') +
                    '<td>' + group.transfers.map((transfer) => escape(transfer.fromName) + ': ' + display(transfer.quantity)).join('<br>') + (group.transferOut > 0 ? '<br>−' + display(group.transferOut) : '') + '</td><td>' + display(group.recommendedQuantity) + '</td><td>' + (group.finalQuantity == null ? '<span class="lps-purchase-review">' + escape(t.review) + '</span>' : display(group.finalQuantity)) + '</td></tr>').join('') +
                '</tbody></table></div><details><summary>' + escape(t.details) + '</summary><form data-edit-form="' + index + '">' + row.groups.map((group) =>
                    '<fieldset data-group="' + escape(group.groupCode) + '"><legend>' + escape(group.groupName) + '</legend><div class="lps-purchase-inputs">' +
                    ['inTransit', 'openOrders', 'pack', 'moq'].map((key) => input(key, group.inputs[key], t[key])).join('') +
                    input('quantity', group.managerQuantity, t.quantity) + input('reason', group.managerReason, t.reason, 'text') + '</div><ul class="lps-purchase-review">' +
                    group.issues.map((issue) => '<li>' + escape(t.issues[issue] || issue) + '</li>').join('') + '</ul></fieldset>').join('') +
                '<button class="button" type="submit"' + (!state.complete || state.busy ? ' disabled' : '') + '>' + escape(t.apply) + '</button></form></details></section>';
        }).join('');
        root.querySelectorAll('[data-edit-form]').forEach((form) => form.addEventListener('submit', async (event) => {
            event.preventDefault();
            if (state.busy || !state.complete) return;
            const row = state.rows[Number(form.dataset.editForm)];
            const groups = {};
            form.querySelectorAll('[data-group]').forEach((fieldset) => {
                const values = {};
                fieldset.querySelectorAll('[data-field]').forEach((field) => { values[field.dataset.field] = field.type === 'text' ? field.value : (field.value === '' ? null : Number(field.value)); });
                groups[fieldset.dataset.group] = values;
            });
            busy(true);
            state.controller = new AbortController();
            try {
                const data = await api('adjust', { token: state.token, sku: row.sku, groups: groups });
                state.rows[Number(form.dataset.editForm)] = data.item;
                render();
                message(t.complete);
            } catch (error) {
                state.complete = false;
                message((error.name === 'AbortError' ? '' : (error.message || t.error) + ' ') + t.incomplete, true);
            }
            finally { busy(false); }
        }));
    }
    el('form').addEventListener('submit', async (event) => {
        event.preventDefault();
        if (state.busy) return;
        const option = el('scenario').selectedOptions[0];
        if (!option || !option.value) return;
        const requestId = ++state.requestId;
        state.rows = []; state.page = 0; state.complete = false; state.token = '';
        state.controller = new AbortController();
        el('warnings').hidden = true;
        el('context').textContent = '';
        busy(true); render(); message(t.loading);
        try {
            const started = await api('start', { scenarioId: Number(option.value), version: Number(option.dataset.version) });
            if (requestId !== state.requestId) return;
            state.token = started.token;
            const parameters = { scenario: started.scenario, groups: started.groups, warehouseGroupsRevision: started.groupsRevision, query: started.query };
            el('parameters').textContent = JSON.stringify(parameters, null, 2);
            el('context').textContent = started.scenario.name + ' · v' + started.scenario.version + ' · ' + started.query.period.from + ' — ' + started.query.period.to + ' · ' + started.groups.map((group) => group.name + ' [' + group.warehouseIds.join(', ') + ']').join('; ');
            let page = 0;
            do {
                const data = await api('page', { token: state.token, page: page });
                if (requestId !== state.requestId) return;
                state.rows.push(...data.items); state.complete = data.complete; page = data.page;
                parameters.snapshotContext = data.context;
                el('parameters').textContent = JSON.stringify(parameters, null, 2);
                message(t.loading + ' ' + t.loaded + ': ' + data.loaded + ' / ' + data.total);
                if (data.warnings.length) {
                    el('warnings').hidden = false;
                    el('warnings').querySelector('pre').textContent = JSON.stringify(data.warnings, null, 2);
                }
                render();
            } while (!state.complete);
            message(t.complete);
        } catch (error) {
            state.complete = false;
            message((error.name === 'AbortError' ? '' : error.message + ' ') + t.incomplete, true);
        } finally { if (requestId === state.requestId) busy(false); }
    });
    el('cancel').addEventListener('click', () => { if (state.controller) state.controller.abort(); });
    el('prev').addEventListener('click', () => { if (state.page > 0) { state.page--; render(); } });
    el('next').addEventListener('click', () => { state.page++; render(); });
    el('scenario').addEventListener('change', () => {
        state.rows = []; state.token = ''; state.complete = false; state.page = 0;
        el('context').textContent = ''; el('message').textContent = ''; el('warnings').hidden = true;
        el('parameters').textContent = '';
        render(); busy(false);
    });
    root.querySelectorAll('[data-export]').forEach((button) => button.addEventListener('click', () => {
        if (!state.complete || state.busy) return;
        const form = document.createElement('form');
        form.method = 'POST'; form.action = config.exportUrl; form.hidden = true;
        Object.entries({ action: 'lps_purchase_export', _wpnonce: config.nonce, token: state.token, format: button.dataset.export }).forEach(([name, value]) => {
            const field = document.createElement('input'); field.name = name; field.value = value; form.appendChild(field);
        });
        document.body.appendChild(form); form.submit(); form.remove();
    }));
}());
