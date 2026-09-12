(function () {
    'use strict';
    const root = document.getElementById('lps-purchase');
    if (!root) return;
    const config = window.LPS_PURCHASE || {};
    const t = config.i18n || {};
    const el = (name) => document.getElementById('lps-purchase-' + name);
    const number = new Intl.NumberFormat(config.locale || 'uk', { maximumFractionDigits: 3 });
    const state = { token: '', rows: [], page: 0, busy: false, complete: false, controller: null, requestId: 0,
        filters: { hideMinimumZero: false, hideNoSales: false, productGroup: '', productSubgroup: '', order: 'all' } };
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
    function options(select, items, value) {
        select.innerHTML = '<option value="">' + escape(t.allProducts) + '</option>' + items.map((item) => '<option value="' + escape(item.value) + '">' + escape(item.label) + '</option>').join('');
        select.value = items.some((item) => item.value === value) ? value : '';
        return select.value;
    }
    function updateFilterOptions() {
        const groups = new Map();
        state.rows.forEach((row) => {
            const group = (row.filterData || {}).group || {};
            if (group.value) groups.set(String(group.value), String(group.label || group.value));
        });
        const groupItems = Array.from(groups, ([value, label]) => ({ value, label })).sort((a, b) => a.label.localeCompare(b.label, config.locale || 'uk'));
        state.filters.productGroup = options(el('product-group'), groupItems, state.filters.productGroup);
        const subgroups = new Map();
        state.rows.forEach((row) => {
            const data = row.filterData || {};
            if (state.filters.productGroup && String((data.group || {}).value || '') !== state.filters.productGroup) return;
            (data.subgroups || []).forEach((item) => { if (item.value) subgroups.set(String(item.value), String(item.label || item.value)); });
        });
        const subgroupItems = Array.from(subgroups, ([value, label]) => ({ value, label })).sort((a, b) => a.label.localeCompare(b.label, config.locale || 'uk'));
        state.filters.productSubgroup = options(el('product-subgroup'), subgroupItems, state.filters.productSubgroup);
    }
    function hasSales(row) {
        const values = row.groups.map((group) => group.regularSales);
        if (values.some((value) => value != null && Number(value) > 0)) return true;
        return values.some((value) => value == null);
    }
    function visibleRows() {
        return state.rows.filter((row) => {
            const data = row.filterData || {};
            if (state.filters.hideMinimumZero && data.minimumStock != null && Number(data.minimumStock) === 0) return false;
            if (state.filters.hideNoSales && !hasSales(row)) return false;
            if (state.filters.productGroup && String((data.group || {}).value || '') !== state.filters.productGroup) return false;
            if (state.filters.productSubgroup && !(data.subgroups || []).some((item) => String(item.value) === state.filters.productSubgroup)) return false;
            if (state.filters.order === 'need' && !row.groups.some((group) => group.needBeforeReceipts != null && Number(group.needBeforeReceipts) > 0)) return false;
            if (state.filters.order === 'ready' && !row.groups.some((group) => group.recommendedQuantity != null && Number(group.recommendedQuantity) > 0)) return false;
            return true;
        });
    }
    function resetFilters() {
        state.filters = { hideMinimumZero: false, hideNoSales: false, productGroup: '', productSubgroup: '', order: 'all' };
        el('hide-minimum-zero').checked = false;
        el('hide-no-sales').checked = false;
        el('order-filter').value = 'all';
    }
    function render() {
        updateFilterOptions();
        const rows = visibleRows();
        const pages = Math.max(1, Math.ceil(rows.length / 25));
        state.page = Math.min(state.page, pages - 1);
        el('page').textContent = (state.page + 1) + ' / ' + pages;
        el('prev').disabled = rows.length === 0 || state.page === 0;
        el('next').disabled = rows.length === 0 || state.page >= pages - 1;
        el('filters').disabled = state.rows.length === 0;
        el('filter-count').textContent = String(t.filterCount || '%1$s / %2$s').replace('%1$s', number.format(rows.length)).replace('%2$s', number.format(state.rows.length));
        const headings = ['group', 'physical', 'available', 'sales', 'returns', 'coverage', 'target', 'need', 'transfer', 'purchase', 'final'];
        el('results').innerHTML = rows.length ? rows.slice(state.page * 25, state.page * 25 + 25).map((row) => {
            const index = state.rows.indexOf(row);
            const filterData = row.filterData || {};
            const group = filterData.group || {};
            const subgroup = (filterData.subgroups || []).length ? filterData.subgroups[filterData.subgroups.length - 1].label : '—';
            return '<section class="lps-purchase-sku"><h2>' + escape(row.sku) + ' · ' + escape(row.productName) + '</h2>' +
                '<p class="lps-purchase-filter-meta">' + escape(t.productGroup) + ': ' + escape(group.label || '—') + ' · ' + escape(t.productSubgroup) + ': ' + escape(subgroup) + ' · ' + escape(t.minimumStock) + ': ' + display(filterData.minimumStock) + '</p>' +
                '<p>' + escape(row.supplier.join(', ')) + ' · ' + escape(t.transitPool) + ': ' + display(row.transitPool) + '</p>' +
                (row.supplierPrices || []).map((price) => '<details><summary>' + escape(t.supplierPrices) + ' · ' + escape(price.supplier) + ' #' + price.versionId + ' · ' + escape((t.supplierPriceLabels || {})[price.status] || price.status) + '</summary>' +
                    price.offers.map((offer) => '<p>' + escape(offer.article) + ' · GTIN ' + escape(offer.originalGtin) + ' · VE ' + escape(offer.pack) + ' · ' + escape(offer.price == null ? '—' : offer.price) + ' ' + escape(offer.currency) + ' · ' + escape((t.supplierPriceLabels || {})[offer.priceBasis] || offer.priceBasis) + '</p>' + ['supplierStatus','invoiceQuantity','invoiceUnit','minimumOrder','boxQuantity','packGtin'].filter((key) => offer[key]).map((key) => '<p>' + escape((t.supplierPriceLabels || {})[key] || key) + ': ' + escape(offer[key]) + '</p>').join('') + '<p>' + escape(offer.issues.map((key) => (t.supplierPriceLabels || {})[key] || key).join('; ')) + '</p>').join('') + '</details>').join('') +
                '<p>' + escape(t.transitWarehouses) + ': ' + escape((row.transitWarehouseIds || []).join(', ') || '—') + ' · ' + escape(t.transitStatus) + ': ' + escape((t.transitLabels || {})[row.transitStatus] || row.transitStatus) + '</p>' +
                '<details><summary>' + escape(t.transitWarehouses) + '</summary>' +
                (row.transitConsistency ? '<p><strong>' + escape(t.networkConsistency) + ':</strong> ' + escape((t.transitLabels || {})[row.transitConsistency.status] || row.transitConsistency.status || '—') + '</p><p><strong>' + escape(t.networkRecommendation) + ':</strong> ' + escape(row.transitConsistency.recommendation || '—') + '</p>' : '') +
                '<p>' + escape(t.supplierTransit) + ': ' + display(row.supplierTransitQuantity) + '</p>' +
                (row.transitSources || []).map((source) => '<p><strong>' + escape(source.warehouseId) + ' · ' + escape(source.warehouseName || '') + '</strong>: ' + display(source.availableForNetworkPlanningQuantity) + ' · ' + escape((t.transitLabels || {})[source.status] || source.status) + ' · ' + escape(source.completedAt || source.asOf || '') + '</p><p>' + escape(t.supplierTransit) + ': ' + display(source.supplierInTransitAvailableQuantity) + '</p><pre>' + escape(JSON.stringify(source, null, 2)) + '</pre>').join('') +
                '<ul>' + (row.transitWarnings || []).map((warning) => '<li>' + escape(typeof warning === 'string' ? warning : (warning.message || warning.code || '')) + '</li>').join('') + '</ul></details>' +
                '<div class="lps-purchase-scroll"><table class="widefat striped"><thead><tr>' + headings.map((key) => '<th>' + escape(t[key]) + '</th>').join('') + '</tr></thead><tbody>' +
                row.groups.map((group) => '<tr><th>' + escape(group.groupName) + '<small>' + escape(t.receivingWarehouse) + ': ' + group.receivingWarehouseId + '</small></th>' +
                    ['physical', 'available', 'regularSales', 'returns', 'coverageDays', 'target', 'needBeforeReceipts'].map((key) => '<td>' + display(group[key]) + '</td>').join('') +
                    '<td>' + group.transfers.map((transfer) => escape(transfer.fromName) + ': ' + display(transfer.quantity)).join('<br>') + (group.transferOut > 0 ? '<br>−' + display(group.transferOut) : '') + '</td><td>' + display(group.recommendedQuantity) + '</td><td>' + (group.finalQuantity == null ? '<span class="lps-purchase-review">' + escape(t.review) + '</span>' : display(group.finalQuantity)) + '</td></tr>').join('') +
                '</tbody></table></div><details><summary>' + escape(t.details) + '</summary><form data-edit-form="' + index + '">' + row.groups.map((group) =>
                    '<fieldset data-group="' + escape(group.groupCode) + '"><legend>' + escape(group.groupName) + '</legend><div class="lps-purchase-inputs">' +
                    ['inTransit', 'openOrders', 'pack', 'moq'].map((key) => input(key, group.inputs[key], t[key])).join('') +
                    '<label><span>' + escape(t.respectPack) + '</span><input type="checkbox" data-field="respectPack"' + (group.respectPack ? ' checked' : '') + '></label>' +
                    input('quantity', group.managerQuantity, t.quantity) + input('reason', group.managerReason, t.reason, 'text') + '</div><p>' + escape(t.packHelp) + '</p><label><input type="checkbox" data-field="receiptsReviewed"' + (group.receiptsReviewed ? ' checked' : '') + '> ' + escape(t.receiptsReviewed) + '</label><ul class="lps-purchase-review">' +
                    group.issues.map((issue) => '<li>' + escape(t.issues[issue] || issue) + '</li>').join('') + '</ul></fieldset>').join('') +
                '<button class="button" type="submit"' + (!state.complete || state.busy ? ' disabled' : '') + '>' + escape(t.apply) + '</button></form></details></section>';
        }).join('') : (state.rows.length ? '<p class="notice notice-info inline">' + escape(t.noFilterResults) + '</p>' : '');
        root.querySelectorAll('[data-edit-form]').forEach((form) => form.addEventListener('submit', async (event) => {
            event.preventDefault();
            if (state.busy || !state.complete) return;
            const row = state.rows[Number(form.dataset.editForm)];
            const groups = {};
            form.querySelectorAll('[data-group]').forEach((fieldset) => {
                const values = {};
                fieldset.querySelectorAll('[data-field]').forEach((field) => { values[field.dataset.field] = field.type === 'checkbox' ? field.checked : (field.type === 'text' ? field.value : (field.value === '' ? null : Number(field.value))); });
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
        resetFilters();
        state.controller = new AbortController();
        el('warnings').hidden = true;
        el('context').textContent = '';
        busy(true); render(); message(t.loading);
        try {
            const started = await api('start', { scenarioId: Number(option.value), version: Number(option.dataset.version) });
            if (requestId !== state.requestId) return;
            state.token = started.token;
            const parameters = { scenario: started.scenario, groups: started.groups, warehouseGroupsRevision: started.groupsRevision, transitWarehouseIds: started.transitWarehouseIds, query: started.query };
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
        resetFilters();
        el('context').textContent = ''; el('message').textContent = ''; el('warnings').hidden = true;
        el('parameters').textContent = '';
        render(); busy(false);
    });
    ['hide-minimum-zero', 'hide-no-sales', 'product-group', 'product-subgroup', 'order-filter'].forEach((name) => el(name).addEventListener('change', () => {
        state.filters.hideMinimumZero = el('hide-minimum-zero').checked;
        state.filters.hideNoSales = el('hide-no-sales').checked;
        state.filters.productGroup = el('product-group').value;
        if (name === 'product-group') state.filters.productSubgroup = '';
        else state.filters.productSubgroup = el('product-subgroup').value;
        state.filters.order = el('order-filter').value;
        state.page = 0;
        render();
    }));
    el('reset-filters').addEventListener('click', () => { resetFilters(); state.page = 0; render(); });
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
