(function () {
    'use strict';

    const config = window.LPS_ANALYTICS_SCENARIOS || {};
    const i18n = config.i18n || {};
    const analyticsI18n = config.analyticsI18n || {};
    const root = document.getElementById('lps-analytics-scenarios');
    if (!root || root.dataset.analyticsSchema !== '4') return;

    const productSelections = [
        'groups', 'groupLevel1', 'groupLevel2', 'groupLevel3', 'groupLevel4',
        'groupLevel5', 'groupLevel6', 'departments', 'productTypes', 'units',
        'currentSuppliers', 'supplierStates'
    ];
    const movementSelections = [
        'operationKinds', 'movementClasses', 'demandModes', 'documentTypes',
        'stockDirections', 'paymentTerms', 'customerSegments', 'counterparties',
        'organizationTypes'
    ];
    const dictionaryKeys = { groups: 'productGroups' };
    const state = {
        scenarios: [],
        warehouses: [],
        capabilities: null,
        compatible: null,
        selectedId: 0,
        busy: false,
        pendingProfile: null,
        capabilitiesRequestId: 0
    };
    const el = (id) => document.getElementById(id);
    const warehouses = el('lps-as-warehouses');
    const form = el('lps-as-form');

    function escapeHtml(value) {
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
    }

    function label(key, fallback) { return i18n[key] || analyticsI18n[key] || fallback || key; }

    function setBusy(busy) {
        state.busy = busy;
        el('lps-as-spinner').classList.toggle('is-active', busy);
        const save = el('lps-as-save');
        const create = el('lps-as-new');
        const duplicate = el('lps-as-duplicate');
        const archive = el('lps-as-archive');
        warehouses.disabled = busy || !warehouses.options.length;
        root.querySelectorAll('.lps-as-list-item').forEach((button) => { button.disabled = busy; });
        if (save) save.disabled = busy || state.compatible === false;
        if (create) create.disabled = busy;
        if (duplicate) duplicate.disabled = busy || !state.selectedId;
        if (archive) archive.disabled = busy || !state.selectedId;
    }

    function setMessage(message, type) {
        const node = el('lps-as-message');
        node.hidden = !message;
        node.className = 'lps-as-message' + (type ? ' is-' + type : '');
        node.textContent = message || '';
    }

    function errorMessage(error) {
        const data = error && error.data ? error.data : {};
        const body = data.body || {};
        const code = data.code || body.code || '';
        const message = data.message || body.message || error.message || i18n.loadFailed || 'Request failed.';
        return code ? code + ': ' + message : message;
    }

    async function api(operation, fields) {
        const request = new URLSearchParams();
        request.set('action', 'lps_analytics_scenarios');
        request.set('_ajax_nonce', config.nonce || '');
        request.set('operation', operation);
        Object.entries(fields || {}).forEach((entry) => {
            const value = typeof entry[1] === 'object' ? JSON.stringify(entry[1]) : String(entry[1]);
            request.set(entry[0], value);
        });
        const response = await fetch(config.ajaxUrl, {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            body: request.toString()
        });
        let json;
        try { json = await response.json(); } catch (error) { throw new Error(i18n.loadFailed || 'Request failed.'); }
        if (!response.ok || !json.success) {
            const failure = new Error((json.data && json.data.message) || i18n.loadFailed || 'Request failed.');
            failure.data = json.data || { httpStatus: response.status };
            throw failure;
        }
        return json.data || {};
    }

    function selectedWarehouseIds() {
        return Array.from(warehouses.selectedOptions).map((option) => Number(option.value)).filter((value) => value > 0).sort((a, b) => a - b);
    }

    function renderWarehouseOptions(items) {
        state.warehouses = Array.isArray(items) ? items : [];
        warehouses.innerHTML = state.warehouses.map((item) => '<option value="' + Number(item.id) + '">' + escapeHtml(item.id + ' — ' + item.name) + '</option>').join('');
        warehouses.disabled = !state.warehouses.length;
    }

    function renderList() {
        const list = el('lps-as-list');
        if (!state.scenarios.length) {
            list.innerHTML = '<p class="description">' + escapeHtml(i18n.noScenarios || 'No scenarios have been created yet.') + '</p>';
            return;
        }
        list.innerHTML = state.scenarios.map((scenario) =>
            '<button type="button" class="lps-as-list-item' + (Number(scenario.id) === state.selectedId ? ' is-selected' : '') + '" data-scenario-id="' + Number(scenario.id) + '"' + (state.busy ? ' disabled' : '') + '>' +
            '<strong>' + escapeHtml(scenario.name) + '</strong><span>' + escapeHtml((scenario.status === 'archived' ? i18n.archivedStatus : i18n.active) || scenario.status) +
            ' · v' + Number(scenario.version || 1) + ' · analytics v' + Number(scenario.schemaVersion || 1) + '</span></button>'
        ).join('');
        list.querySelectorAll('[data-scenario-id]').forEach((button) => button.addEventListener('click', () => selectScenario(Number(button.dataset.scenarioId))));
    }

    function capability(name) { return state.capabilities && state.capabilities.filters ? state.capabilities.filters[name] : null; }
    function dictionary(name) { return state.capabilities && state.capabilities.dictionaries ? (state.capabilities.dictionaries[dictionaryKeys[name] || name] || []) : []; }

    function modeOptions(modes) {
        const captions = { ANY: i18n.any || 'Any', INCLUDE: i18n.include || 'Include', EXCLUDE: i18n.exclude || 'Exclude' };
        return (Array.isArray(modes) && modes.length ? modes : ['ANY', 'INCLUDE', 'EXCLUDE'])
            .map((mode) => '<option value="' + escapeHtml(mode) + '">' + escapeHtml(captions[mode] || mode) + '</option>').join('');
    }

    function renderDictionaryFilter(name, section) {
        const cap = capability(name);
        if (!cap || !cap.supported) return '';
        const caption = (analyticsI18n.filterLabels && analyticsI18n.filterLabels[name]) || name;
        const options = dictionary(name).map((item) => {
            const value = item.code == null ? (item.value == null ? item.id : item.value) : item.code;
            if (value == null) return '';
            return '<option value="' + escapeHtml(value) + '">' + escapeHtml((item.name || item.label || value) + (item.count == null ? '' : ' (' + item.count + ')')) + '</option>';
        }).join('');
        return '<div class="lps-as-selection" data-lps-as-selection="' + escapeHtml(name) + '" data-lps-as-section="' + section + '">' +
            '<label><span>' + escapeHtml(caption) + '</span><select class="lps-as-values" multiple size="6">' + options + '</select></label>' +
            '<select class="lps-as-mode">' + modeOptions(cap.modes) + '</select></div>';
    }

    function bindSelectionModes() {
        root.querySelectorAll('.lps-as-selection').forEach((container) => {
            const mode = container.querySelector('.lps-as-mode');
            const values = container.querySelector('.lps-as-values, textarea');
            const sync = () => { if (values) values.disabled = !mode || mode.value === 'ANY'; };
            if (mode) mode.addEventListener('change', sync);
            sync();
        });
    }

    function renderFilters() {
        const productGrid = el('lps-as-product-filter-grid');
        const movementGrid = el('lps-as-movement-filter-grid');
        productGrid.querySelectorAll('.lps-as-selection:not(.lps-as-text-selection)').forEach((node) => node.remove());
        productGrid.querySelectorAll('.lps-as-text-selection').forEach((node) => {
            const cap = capability(node.dataset.lpsAsSelection);
            node.hidden = !cap || !cap.supported;
        });
        movementGrid.innerHTML = '';
        productSelections.forEach((name) => productGrid.insertAdjacentHTML('beforeend', renderDictionaryFilter(name, 'product')));
        movementSelections.forEach((name) => movementGrid.insertAdjacentHTML('beforeend', renderDictionaryFilter(name, 'movement')));
        bindSelectionModes();
    }

    async function loadCapabilities() {
        const requestId = ++state.capabilitiesRequestId;
        const warehouseIds = selectedWarehouseIds();
        if (!warehouseIds.length) {
            state.capabilities = null;
            state.compatible = null;
            renderFilters();
            el('lps-as-scope-meta').textContent = i18n.selectWarehouses || 'Select warehouses.';
            setBusy(false);
            return;
        }
        setBusy(true);
        el('lps-as-scope-meta').textContent = i18n.capabilitiesLoading || 'Loading filters...';
        try {
            const data = await api('v4_capabilities', { payloadJson: {
                sourceDatabase: el('lps-as-source').value || 'Paint_Ua', warehouseIds: warehouseIds
            } });
            if (requestId !== state.capabilitiesRequestId) return;
            state.capabilities = data;
            state.compatible = Number(data.analyticsSchemaVersion) >= 4 && data.compatibleGeneration === true;
            renderFilters();
            const snapshots = (data.warehouses || []).map((item) => item.name + ' · v' + (item.analyticsSchemaVersion || '—') + ' · ' + (item.asOf || item.status || '')).join('; ');
            el('lps-as-scope-meta').textContent = snapshots;
            if (!state.compatible) {
                setMessage(analyticsI18n.schemaV4Required || 'Analytics schema v4 is required.', 'error');
            } else {
                setMessage('', '');
            }
            if (state.pendingProfile) {
                applyProfileFields(state.pendingProfile);
                state.pendingProfile = null;
            }
        } catch (error) {
            if (requestId !== state.capabilitiesRequestId) return;
            state.capabilities = null;
            state.compatible = false;
            setMessage(errorMessage(error), 'error');
            el('lps-as-scope-meta').textContent = i18n.capabilitiesFailed || 'Filters could not be loaded.';
        } finally {
            if (requestId === state.capabilitiesRequestId) setBusy(false);
        }
    }

    function parseTextValues(text) {
        return Array.from(new Set(String(text || '').split(/[\r\n;,|]+/).map((value) => value.trim()).filter(Boolean)));
    }

    function collectSelection(container) {
        const mode = container.querySelector('.lps-as-mode');
        const select = container.querySelector('.lps-as-values');
        const textarea = container.querySelector('textarea');
        const values = select ? Array.from(select.selectedOptions).map((option) => option.value) : parseTextValues(textarea ? textarea.value : '');
        return { mode: mode && values.length ? mode.value : 'ANY', values: values };
    }

    function collectFilters(section) {
        const result = {};
        root.querySelectorAll('[data-lps-as-section="' + section + '"]').forEach((container) => {
            const name = container.dataset.lpsAsSelection;
            const cap = capability(name);
            if (!cap || !cap.supported) return;
            const selection = collectSelection(container);
            if (selection.mode !== 'ANY' && selection.values.length) result[name] = selection;
        });
        return result;
    }

    function buildProfile() {
        const productFilters = collectFilters('product');
        const search = el('lps-as-search').value.trim();
        if (search) productFilters.search = search;
        return {
            schemaVersion: 4,
            context: { sourceDatabase: el('lps-as-source').value || 'Paint_Ua', warehouseIds: selectedWarehouseIds() },
            period: { from: el('lps-as-period-from').value, to: el('lps-as-period-to').value },
            productFilters: productFilters,
            movementFilters: collectFilters('movement'),
            calculation: { abcBasis: el('lps-as-abc-basis').value, includeReturns: el('lps-as-include-returns').checked },
            page: { size: Number(el('lps-as-page-size').value || 50) },
            sort: [{ field: el('lps-as-sort-field').value, direction: el('lps-as-sort-direction').value }],
            presentation: { activeTab: el('lps-as-active-tab').value }
        };
    }

    function clearSelections() {
        el('lps-as-search').value = '';
        root.querySelectorAll('.lps-as-mode').forEach((node) => { node.value = 'ANY'; node.dispatchEvent(new Event('change')); });
        root.querySelectorAll('.lps-as-values').forEach((node) => Array.from(node.options).forEach((option) => { option.selected = false; }));
        root.querySelectorAll('.lps-as-text-selection textarea').forEach((node) => { node.value = ''; });
    }

    function setSelection(section, name, selection) {
        const container = root.querySelector('[data-lps-as-section="' + section + '"][data-lps-as-selection="' + name + '"]');
        if (!container || !selection) return;
        const mode = container.querySelector('.lps-as-mode');
        const values = Array.isArray(selection.values) ? selection.values.map(String) : [];
        if (mode) mode.value = ['INCLUDE', 'EXCLUDE'].includes(selection.mode) ? selection.mode : 'ANY';
        const select = container.querySelector('.lps-as-values');
        const textarea = container.querySelector('textarea');
        if (select) {
            const existing = new Set(Array.from(select.options).map((option) => option.value));
            values.forEach((value) => {
                if (existing.has(value)) return;
                const option = new Option(value + ' · ' + label('savedUnavailableValue', 'Saved value is not available in the current snapshot'), value);
                option.dataset.unavailable = 'true';
                select.appendChild(option);
            });
            Array.from(select.options).forEach((option) => { option.selected = values.includes(option.value); });
        }
        if (textarea) textarea.value = values.join('\n');
        if (mode) mode.dispatchEvent(new Event('change'));
    }

    function legacyProfile(profile) {
        const products = profile.products || {};
        const movements = profile.movements || {};
        const productFilters = {};
        if (products.search) productFilters.search = products.search;
        if (products.supplierMode && products.supplierMode !== 'ANY' && Array.isArray(products.supplierValues)) productFilters.currentSuppliers = { mode: products.supplierMode, values: products.supplierValues };
        const movementFilters = {};
        const names = { operationKind: 'operationKinds', movementClass: 'movementClasses', demandMode: 'demandModes', documentType: 'documentTypes', stockDirection: 'stockDirections', paymentTerms: 'paymentTerms', customerSegment: 'customerSegments', counterparty: 'counterparties' };
        Object.entries(names).forEach((entry) => { if (movements[entry[0]]) movementFilters[entry[1]] = { mode: 'INCLUDE', values: [movements[entry[0]]] }; });
        return {
            schemaVersion: 4, context: profile.context || {},
            period: { from: movements.documentDateFrom || el('lps-as-period-from').value, to: movements.documentDateTo || el('lps-as-period-to').value },
            productFilters: productFilters, movementFilters: movementFilters,
            calculation: { abcBasis: 'GROSS_PROFIT', includeReturns: true }, page: { size: Number(products.perPage || 50) },
            sort: [{ field: 'grossProfit', direction: products.direction === 'ASC' ? 'ASC' : 'DESC' }], presentation: profile.presentation || { activeTab: 'products' }
        };
    }

    function applyProfileFields(profile) {
        clearSelections();
        const normalized = Number(profile.schemaVersion || 1) >= 4 ? profile : legacyProfile(profile);
        const productFilters = normalized.productFilters || {};
        el('lps-as-search').value = productFilters.search || '';
        Object.entries(productFilters).forEach((entry) => { if (entry[0] !== 'search') setSelection('product', entry[0], entry[1]); });
        Object.entries(normalized.movementFilters || {}).forEach((entry) => setSelection('movement', entry[0], entry[1]));
        if (normalized.period) {
            if (normalized.period.from) el('lps-as-period-from').value = normalized.period.from;
            if (normalized.period.to) el('lps-as-period-to').value = normalized.period.to;
        }
        const calculation = normalized.calculation || {};
        el('lps-as-abc-basis').value = calculation.abcBasis || 'GROSS_PROFIT';
        el('lps-as-include-returns').checked = calculation.includeReturns !== false;
        el('lps-as-page-size').value = String((normalized.page || {}).size || 50);
        const sort = Array.isArray(normalized.sort) && normalized.sort[0] ? normalized.sort[0] : {};
        el('lps-as-sort-field').value = sort.field || 'grossProfit';
        el('lps-as-sort-direction').value = sort.direction === 'ASC' ? 'ASC' : 'DESC';
        el('lps-as-active-tab').value = (normalized.presentation || {}).activeTab === 'movements' ? 'movements' : 'products';
    }

    async function loadScenarioProfile(scenario) {
        const profile = Number(scenario.schemaVersion) >= 4 ? scenario.profile : legacyProfile(scenario.profile || {});
        const selected = (profile.context && profile.context.warehouseIds || []).map(Number);
        Array.from(warehouses.options).forEach((option) => { option.selected = selected.includes(Number(option.value)); });
        state.pendingProfile = profile;
        setMessage((i18n.capabilitiesLoading || 'Loading supported filters and dictionaries...') + ' ' + scenario.name, 'info');
        await loadCapabilities();
    }

    function resetEditor() {
        state.selectedId = 0;
        state.capabilities = null;
        state.compatible = null;
        state.pendingProfile = null;
        Array.from(warehouses.options).forEach((option) => { option.selected = false; });
        el('lps-as-id').value = '';
        el('lps-as-version').value = '0';
        el('lps-as-name').value = '';
        el('lps-as-description').value = '';
        el('lps-as-visibility').value = 'shared';
        el('lps-as-status').value = 'active';
        el('lps-as-active-tab').value = 'products';
        el('lps-as-editor-meta').textContent = i18n.newScenario || 'New scenario';
        el('lps-as-revisions').hidden = true;
        clearSelections();
        renderFilters();
        el('lps-as-scope-meta').textContent = i18n.selectWarehouses || 'Select warehouses.';
        renderList();
        setBusy(false);
    }

    async function selectScenario(id) {
        const scenario = state.scenarios.find((item) => Number(item.id) === id);
        if (!scenario) return;
        state.selectedId = id;
        el('lps-as-id').value = String(id);
        el('lps-as-version').value = String(scenario.version || 1);
        el('lps-as-name').value = scenario.name || '';
        el('lps-as-description').value = scenario.description || '';
        el('lps-as-visibility').value = scenario.visibility === 'personal' ? 'personal' : 'shared';
        el('lps-as-status').value = scenario.status === 'archived' ? 'archived' : 'active';
        el('lps-as-editor-meta').textContent = (i18n.version || 'Version') + ' ' + (scenario.version || 1) + ' · ' + (scenario.owner || '');
        renderList();
        await loadScenarioProfile(scenario);
        loadRevisions(id);
    }

    async function loadRevisions(id) {
        try {
            const data = await api('revisions', { scenarioId: id });
            const items = data.items || [];
            el('lps-as-revisions').hidden = !items.length;
            el('lps-as-revision-list').innerHTML = items.map((item) => '<p><strong>v' + Number(item.version) + '</strong> · ' + escapeHtml(item.changedBy) + ' · ' + escapeHtml(item.changedAt) + '</p>').join('');
        } catch (error) { el('lps-as-revisions').hidden = true; }
    }

    async function saveScenario(event) {
        event.preventDefault();
        if (!selectedWarehouseIds().length) { setMessage(i18n.selectWarehouses || 'Select warehouses.', 'error'); return; }
        if (state.compatible !== true) { setMessage(analyticsI18n.schemaV4Required || 'Analytics schema v4 is required.', 'error'); return; }
        setBusy(true);
        try {
            const data = await api('save', {
                scenarioId: state.selectedId || 0,
                expectedVersion: Number(el('lps-as-version').value || 0),
                name: el('lps-as-name').value,
                description: el('lps-as-description').value,
                visibility: el('lps-as-visibility').value,
                status: el('lps-as-status').value,
                profileJson: buildProfile()
            });
            state.scenarios = data.items || [];
            setMessage(i18n.saved || 'Scenario saved.', 'success');
            await selectScenario(Number(data.selectedId));
        } catch (error) { setMessage(errorMessage(error), 'error'); }
        finally { setBusy(false); }
    }

    async function duplicateScenario() {
        if (!state.selectedId) return;
        setBusy(true);
        try {
            const data = await api('duplicate', { scenarioId: state.selectedId });
            state.scenarios = data.items || [];
            setMessage(i18n.saved || 'Scenario saved.', 'success');
            await selectScenario(Number(data.selectedId));
        } catch (error) { setMessage(errorMessage(error), 'error'); }
        finally { setBusy(false); }
    }

    async function archiveScenario() {
        if (!state.selectedId || !window.confirm(i18n.confirmArchive || 'Archive this scenario?')) return;
        setBusy(true);
        try {
            const data = await api('archive', { scenarioId: state.selectedId, expectedVersion: Number(el('lps-as-version').value || 0) });
            state.scenarios = data.items || [];
            setMessage(i18n.archived || 'Scenario archived.', 'success');
            resetEditor();
        } catch (error) { setMessage(errorMessage(error), 'error'); }
        finally { setBusy(false); }
    }

    async function bootstrap() {
        setBusy(true);
        setMessage(i18n.loading || 'Loading scenarios...', 'info');
        try {
            const data = await api('bootstrap');
            state.scenarios = data.items || [];
            el('lps-as-source').value = data.sourceDatabase || 'Paint_Ua';
            renderWarehouseOptions(data.warehouses || []);
            if (!data.warehouseDirectoryReady) throw new Error(data.warehouseDirectoryMessage || i18n.noScope || 'Warehouses are unavailable.');
            renderList();
            resetEditor();
            setMessage('', '');
        } catch (error) { setMessage(errorMessage(error), 'error'); }
        finally { setBusy(false); }
    }

    warehouses.addEventListener('change', () => { state.pendingProfile = null; loadCapabilities(); });
    form.addEventListener('submit', saveScenario);
    el('lps-as-new').addEventListener('click', resetEditor);
    el('lps-as-duplicate').addEventListener('click', duplicateScenario);
    el('lps-as-archive').addEventListener('click', archiveScenario);
    bootstrap();
}());
