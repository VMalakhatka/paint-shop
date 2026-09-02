(function () {
    'use strict';

    const config = window.LPS_PRODUCT_ANALYTICS || {};
    const i18n = config.i18n || {};
    const root = document.getElementById('lps-product-analytics');
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
        sourceDatabase: 'Paint_Ua',
        warehouseIds: [],
        capabilities: null,
        rows: [],
        response: null,
        scenarios: [],
        activeTab: 'products',
        currentCursor: null,
        nextCursor: null,
        cursorHistory: [],
        busy: false,
        pendingScenario: null,
        applyingScenario: false
    };

    const el = (id) => document.getElementById(id);
    const warehouseSelect = el('lps-pa-warehouses');
    const sourceInput = el('lps-pa-source');
    const form = el('lps-pa-v4-filters');
    const productFilterGrid = el('lps-pa-product-filter-grid');
    const movementFilterGrid = el('lps-pa-movement-filter-grid');
    const scenarioSelect = el('lps-pa-scenario-select');
    const numberFormat = new Intl.NumberFormat(config.locale || undefined, { maximumFractionDigits: 2 });
    const moneyFormat = new Intl.NumberFormat(config.locale || undefined, {
        style: 'currency', currency: config.currency || 'UAH', maximumFractionDigits: 2
    });

    function escapeHtml(value) {
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
    }

    function label(key, fallback) {
        return i18n[key] || fallback || key;
    }

    function statusLabel(value) {
        return (i18n.statusLabels && i18n.statusLabels[value]) || value || label('unknownValue', 'Not confirmed');
    }

    function number(value) {
        return value == null || value === '' ? '—' : numberFormat.format(Number(value));
    }

    function money(value) {
        return value == null || value === '' ? '—' : moneyFormat.format(Number(value));
    }

    function setBusy(busy, message, activity) {
        state.busy = busy;
        const spinner = el('lps-pa-spinner');
        if (spinner) spinner.classList.toggle('is-active', busy);
        const buildButton = el('lps-pa-build');
        const buildSpinner = el('lps-pa-build-spinner');
        const buildStatus = el('lps-pa-build-status');
        const queryBusy = busy && activity === 'query';
        [buildButton, el('lps-pa-reload')].forEach((button) => {
            if (button) button.disabled = busy;
        });
        if (buildButton) {
            if (!buildButton.dataset.idleLabel) buildButton.dataset.idleLabel = buildButton.textContent;
            buildButton.textContent = queryBusy ? message : buildButton.dataset.idleLabel;
            buildButton.setAttribute('aria-busy', queryBusy ? 'true' : 'false');
        }
        if (buildSpinner) buildSpinner.classList.toggle('is-active', queryBusy);
        if (buildStatus) buildStatus.textContent = queryBusy ? message : '';
        if (form) form.setAttribute('aria-busy', queryBusy ? 'true' : 'false');
        if (message) setMessage(message, 'info');
    }

    function setMessage(message, type) {
        const node = el('lps-pa-message');
        if (!node) return;
        node.hidden = !message;
        node.className = 'lps-pa-message' + (type ? ' is-' + type : '');
        node.textContent = message || '';
    }

    function errorMessage(error) {
        const data = error && error.data ? error.data : {};
        const body = data.body || {};
        const code = data.code || body.code || '';
        const message = data.message || body.message || error.message || label('loadFailed', 'Request failed.');
        return code ? code + ': ' + message : message;
    }

    async function api(operation, payload) {
        const request = new URLSearchParams();
        request.set('action', 'lps_product_analytics');
        request.set('_ajax_nonce', config.nonce || '');
        request.set('operation', operation);
        if (payload !== undefined) request.set('payloadJson', JSON.stringify(payload));
        let response;
        try {
            response = await fetch(config.ajaxUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                body: request.toString()
            });
        } catch (networkError) {
            const error = new Error(networkError.message || label('loadFailed', 'Request failed.'));
            error.data = { httpStatus: 0 };
            throw error;
        }
        let json;
        try {
            json = await response.json();
        } catch (parseError) {
            const error = new Error(label('loadFailed', 'Request failed.'));
            error.data = { httpStatus: response.status };
            throw error;
        }
        if (!response.ok || !json.success) {
            const error = new Error((json.data && json.data.message) || label('loadFailed', 'Request failed.'));
            error.data = json.data || { httpStatus: response.status };
            throw error;
        }
        return json.data || {};
    }

    function selectedWarehouseIds() {
        return Array.from(warehouseSelect.selectedOptions)
            .map((option) => Number(option.value))
            .filter((value) => value > 0)
            .sort((left, right) => left - right);
    }

    function rememberWarehouses() {
        try { window.localStorage.setItem('lpsProductAnalyticsWarehouses', JSON.stringify(state.warehouseIds)); } catch (error) { /* no-op */ }
    }

    function rememberedWarehouses() {
        try {
            const values = JSON.parse(window.localStorage.getItem('lpsProductAnalyticsWarehouses') || '[]');
            return Array.isArray(values) ? values.map(Number).filter((value) => value > 0) : [];
        } catch (error) { return []; }
    }

    function populateWarehouses(items) {
        warehouseSelect.innerHTML = '';
        const remembered = rememberedWarehouses();
        (items || []).forEach((warehouse) => {
            const option = document.createElement('option');
            option.value = String(warehouse.id);
            option.textContent = warehouse.id + ' — ' + warehouse.name;
            option.selected = remembered.includes(Number(warehouse.id));
            warehouseSelect.appendChild(option);
        });
        if (!warehouseSelect.selectedOptions.length && warehouseSelect.options.length) {
            warehouseSelect.options[0].selected = true;
        }
        warehouseSelect.disabled = !warehouseSelect.options.length;
        state.warehouseIds = selectedWarehouseIds();
    }

    function populateScenarios(items) {
        state.scenarios = Array.isArray(items) ? items : [];
        const first = scenarioSelect.options[0];
        scenarioSelect.innerHTML = '';
        scenarioSelect.appendChild(first || new Option(label('selectScenario', 'Use temporary filters without a scenario'), ''));
        state.scenarios.forEach((scenario) => {
            const option = document.createElement('option');
            option.value = String(scenario.id);
            option.textContent = scenario.name + (scenario.schemaVersion >= 4 ? '' : ' · ' + label('legacyScenario', 'legacy scenario'));
            scenarioSelect.appendChild(option);
        });
    }

    async function bootstrap() {
        setBusy(true, label('loading', 'Loading product analytics...'));
        try {
            const data = await api('v4_bootstrap');
            state.sourceDatabase = data.sourceDatabase || 'Paint_Ua';
            sourceInput.value = state.sourceDatabase;
            populateWarehouses(data.warehouses || []);
            populateScenarios(data.scenarios || []);
            if (!data.warehouseDirectoryReady) throw new Error(data.warehouseDirectoryMessage || label('noSnapshots', 'No warehouses are available.'));
            if (!state.warehouseIds.length) throw new Error(label('selectWarehouses', 'Select warehouses.'));
            await loadCapabilities();
        } catch (error) {
            setMessage(errorMessage(error), 'error');
        } finally {
            setBusy(false);
        }
    }

    function capability(name) {
        return state.capabilities && state.capabilities.filters ? state.capabilities.filters[name] : null;
    }

    function dictionary(name) {
        if (!state.capabilities || !state.capabilities.dictionaries) return [];
        return state.capabilities.dictionaries[dictionaryKeys[name] || name] || [];
    }

    function modeOptions(modes) {
        const allowed = Array.isArray(modes) && modes.length ? modes : ['ANY', 'INCLUDE', 'EXCLUDE'];
        const labels = {
            ANY: label('any', 'Do not apply this condition'),
            INCLUDE: label('include', 'Include selected values'),
            EXCLUDE: label('exclude', 'Exclude selected values')
        };
        return allowed.map((mode) => '<option value="' + escapeHtml(mode) + '">' + escapeHtml(labels[mode] || mode) + '</option>').join('');
    }

    function renderDictionaryFilter(name, section) {
        const cap = capability(name);
        if (!cap || !cap.supported) return '';
        const options = dictionary(name).map((item) => {
            const caption = (item.name || item.code) + (item.count == null ? '' : ' (' + number(item.count) + ')');
            return '<option value="' + escapeHtml(item.code) + '">' + escapeHtml(caption) + '</option>';
        }).join('');
        const caption = (i18n.filterLabels && i18n.filterLabels[name]) || name;
        return '<div class="lps-pa-selection" data-lps-selection="' + escapeHtml(name) + '" data-lps-section="' + section + '">' +
            '<label><span>' + escapeHtml(caption) + '</span><select class="lps-pa-values" multiple size="6">' + options + '</select></label>' +
            '<select class="lps-pa-mode">' + modeOptions(cap.modes) + '</select></div>';
    }

    function renderCapabilityWarnings() {
        const warnings = (state.capabilities && state.capabilities.warnings) || [];
        renderWarnings(el('lps-pa-capability-warnings'), warnings, 'capabilities');
    }

    function renderFilters() {
        productFilterGrid.querySelectorAll('.lps-pa-selection').forEach((node) => node.remove());
        movementFilterGrid.innerHTML = '';
        productFilterGrid.querySelectorAll('.lps-pa-text-selection').forEach((node) => {
            const cap = capability(node.dataset.lpsSelection);
            node.hidden = !cap || !cap.supported;
        });
        productSelections.forEach((name) => productFilterGrid.insertAdjacentHTML('beforeend', renderDictionaryFilter(name, 'product')));
        movementSelections.forEach((name) => movementFilterGrid.insertAdjacentHTML('beforeend', renderDictionaryFilter(name, 'movement')));
        root.querySelectorAll('.lps-pa-selection, .lps-pa-text-selection').forEach((container) => {
            const mode = container.querySelector('.lps-pa-mode');
            const values = container.querySelector('.lps-pa-values, textarea');
            const sync = () => { if (values) values.disabled = !mode || mode.value === 'ANY'; };
            if (mode) mode.addEventListener('change', sync);
            sync();
        });
    }

    function renderSnapshotContext() {
        const node = el('lps-pa-snapshot');
        const warehouses = (state.capabilities && state.capabilities.warehouses) || [];
        if (!node) return;
        node.innerHTML = warehouses.map((warehouse) =>
            '<div><strong>' + escapeHtml(warehouse.name || warehouse.id) + '</strong> · v' + escapeHtml(warehouse.analyticsSchemaVersion || '—') +
            '<br><small>' + escapeHtml(warehouse.asOf || warehouse.completedAt || warehouse.status || '') + '</small></div>'
        ).join('');
    }

    async function loadCapabilities() {
        state.warehouseIds = selectedWarehouseIds();
        rememberWarehouses();
        if (!state.warehouseIds.length) {
            state.capabilities = null;
            setMessage(label('selectWarehouses', 'Select warehouses.'), 'warning');
            return;
        }
        setBusy(true, label('capabilitiesLoading', 'Loading supported filters...'));
        try {
            const data = await api('v4_capabilities', {
                sourceDatabase: state.sourceDatabase,
                warehouseIds: state.warehouseIds
            });
            state.capabilities = data;
            renderSnapshotContext();
            renderCapabilityWarnings();
            renderFilters();
            if (Number(data.analyticsSchemaVersion) < 4 || !data.compatibleGeneration) {
                setMessage(label('schemaV4Required', 'Analytics schema v4 is required.'), 'warning');
                return;
            }
            setMessage('', '');
            if (state.pendingScenario) {
                applyScenarioFields(state.pendingScenario);
                state.pendingScenario = null;
            }
        } catch (error) {
            state.capabilities = null;
            setMessage(errorMessage(error), 'error');
        } finally {
            setBusy(false);
        }
    }

    function parseTextValues(text) {
        return Array.from(new Set(String(text || '').split(/[\r\n;,|]+/).map((value) => value.trim()).filter(Boolean)));
    }

    function collectSelection(container) {
        const mode = container.querySelector('.lps-pa-mode');
        const valuesNode = container.querySelector('.lps-pa-values');
        const textarea = container.querySelector('textarea');
        const selected = valuesNode
            ? Array.from(valuesNode.selectedOptions).map((option) => option.value)
            : parseTextValues(textarea ? textarea.value : '');
        return { mode: mode && selected.length ? mode.value : 'ANY', values: selected };
    }

    function collectFilters(section) {
        const output = {};
        root.querySelectorAll('[data-lps-section="' + section + '"]').forEach((container) => {
            const name = container.dataset.lpsSelection;
            const cap = capability(name);
            if (!cap || !cap.supported) return;
            const selection = collectSelection(container);
            if (selection.mode !== 'ANY' && selection.values.length) output[name] = selection;
        });
        return output;
    }

    function currentRequest(cursor) {
        const productFilters = collectFilters('product');
        const search = el('lps-pa-search').value.trim();
        const scenario = state.scenarios.find((item) => String(item.id) === scenarioSelect.value);
        if (search) productFilters.search = search;
        return {
            sourceDatabase: state.sourceDatabase,
            warehouseIds: state.warehouseIds,
            scenario: scenario ? { id: Number(scenario.id), version: Number(scenario.version) } : null,
            period: { from: el('lps-pa-period-from').value, to: el('lps-pa-period-to').value },
            productFilters: productFilters,
            movementFilters: collectFilters('movement'),
            calculation: {
                abcBasis: el('lps-pa-abc-basis').value,
                includeReturns: el('lps-pa-include-returns').checked
            },
            page: { size: Number(el('lps-pa-page-size').value || 50), cursor: cursor || null },
            sort: [{ field: el('lps-pa-sort-field').value, direction: el('lps-pa-sort-direction').value }]
        };
    }

    async function runQuery(cursor, fromHistory) {
        if (!state.capabilities || Number(state.capabilities.analyticsSchemaVersion) < 4 || !state.capabilities.compatibleGeneration) {
            setMessage(label('schemaV4Required', 'Analytics schema v4 is required.'), 'warning');
            return;
        }
        setBusy(true, label('queryRunning', 'Building report...'), 'query');
        try {
            const request = currentRequest(cursor);
            const data = await api('v4_query', request);
            state.response = data;
            state.rows = Array.isArray(data.rows) ? data.rows : [];
            state.currentCursor = cursor || null;
            state.nextCursor = data.nextCursor || null;
            if (!fromHistory && cursor == null) state.cursorHistory = [];
            renderQuery(data);
            setMessage('', '');
        } catch (error) {
            setMessage(errorMessage(error), 'error');
        } finally {
            setBusy(false);
        }
    }

    function metricCards(metrics, totals) {
        const cards = [
            [label('productCount', 'Products found'), number(totals.productCount), ''],
            [label('availableQuantity', 'Available quantity'), number(metrics.availableQuantity), ''],
            [label('inventoryValue', 'Capital in stock'), money(metrics.inventoryValue), 'is-capital'],
            [label('soldUnits', 'Sold units'), number(metrics.soldUnits), ''],
            [label('salesRevenue', 'Sales revenue'), money(metrics.salesRevenue), ''],
            [label('grossProfit', 'Gross profit'), money(metrics.grossProfit), 'is-profit'],
            [label('averageInventoryValue', 'Average inventory value'), money(metrics.averageInventoryValue), 'is-capital'],
            [label('turns', 'Inventory turns'), number(metrics.inventoryTurns), ''],
            [label('gmroi', 'GMROI'), number(metrics.gmroi), ''],
            [label('coverage', 'Coverage, days'), number(metrics.coverageDays), '']
        ];
        return cards.map((card) => '<article class="lps-pa-card ' + card[2] + '"><span>' + escapeHtml(card[0]) + '</span><strong>' + escapeHtml(card[1]) + '</strong></article>').join('');
    }

    function renderWarnings(node, warnings, kind) {
        if (!node) return;
        const items = Array.isArray(warnings) ? warnings.filter(Boolean) : [];
        node.hidden = !items.length;
        node.innerHTML = items.map((warning) => {
            const code = warning.code || '';
            const message = warning.message || warning.reason || '';
            return '<div class="notice notice-warning inline"><p><strong>' + escapeHtml(code) + '</strong>' + (message ? ': ' + escapeHtml(message) : '') + '</p></div>';
        }).join('');
        node.dataset.warningKind = kind || '';
    }

    function transitHtml(transit) {
        if (!transit) return '<span class="lps-pa-state is-unknown">' + escapeHtml(label('unknownValue', 'Not confirmed')) + '</span>';
        const captions = i18n.transitLabels || {};
        const confirmed = transit.status === 'CONFIRMED_SUPPLIER_ORIGIN' && transit.supplierOriginConfirmed === true && transit.availableForPlanningQuantity != null;
        const neutral = transit.status === 'NO_IN_TRANSIT_STOCK';
        const css = confirmed ? 'is-good' : (neutral ? 'is-neutral' : 'is-warning');
        const amount = confirmed || neutral ? '<strong>' + number(transit.availableForPlanningQuantity || 0) + '</strong>' : '';
        return '<span class="lps-pa-state ' + css + '">' + escapeHtml(captions[transit.status] || transit.status || label('unknownValue', 'Not confirmed')) + '</span>' + amount;
    }

    function networkHtml(policy) {
        if (!policy || policy.orderAllowed == null) return '<span class="lps-pa-state is-unknown">' + escapeHtml(label('unknownValue', 'Not confirmed')) + '</span>';
        if (policy.orderAllowed === false) return '<span class="lps-pa-state is-danger">' + escapeHtml(label('blocked', 'Ordering is blocked')) + '</span>';
        return '<span class="lps-pa-state is-good">' + escapeHtml(label('allowed', 'Ordering is allowed')) + '</span>';
    }

    function productHeaders() {
        return [label('abcClass', 'ABC'), label('sku', 'SKU') + ' / ' + label('gtin', 'GTIN'), label('product', 'Product'),
            label('physicalQuantity', 'Physical'), label('reservedQuantity', 'Reserved'), label('availableQuantity', 'Available'),
            label('inventoryValue', 'Capital'), label('networkPolicy', 'Network policy'), label('transitStock', 'Stock in transit'), label('details', 'Details')];
    }

    function movementHeaders() {
        return [label('abcClass', 'ABC'), label('sku', 'SKU'), label('product', 'Product'), label('soldUnits', 'Sold units'),
            label('regularSales', 'Regular sales'), label('oneOffSales', 'One-off sales'), label('returns', 'Returns'),
            label('salesRevenue', 'Revenue'), label('salesCogs', 'Cost'), label('grossProfit', 'Gross profit'),
            label('grossMarginPercent', 'Margin, %'), label('turns', 'Turns'), label('gmroi', 'GMROI'), label('coverage', 'Coverage'), label('details', 'Details')];
    }

    function productRow(row, index) {
        const metrics = row.metrics || {};
        const dimensions = row.dimensions || {};
        const suppliers = Array.isArray(dimensions.currentSuppliers) ? dimensions.currentSuppliers.join(', ') : '';
        return '<tr>' +
            '<td><strong>' + escapeHtml(row.abcClass || '—') + '</strong></td>' +
            '<td><code>' + escapeHtml(row.sku || '') + '</code><br><small>' + escapeHtml(dimensions.primaryBarcode || '—') + '</small></td>' +
            '<td><strong>' + escapeHtml(row.productName || '') + '</strong>' + (suppliers ? '<br><small>' + escapeHtml(label('supplier', 'Supplier')) + ': ' + escapeHtml(suppliers) + '</small>' : '') + '</td>' +
            '<td class="num">' + number(metrics.physicalQuantity) + '</td><td class="num">' + number(metrics.reservedQuantity) + '</td><td class="num">' + number(metrics.availableQuantity) + '</td>' +
            '<td class="num">' + money(metrics.inventoryValue) + '</td><td>' + networkHtml(row.networkOrderPolicy) + '</td><td>' + transitHtml(row.inTransitStock) + '</td>' +
            '<td><button type="button" class="button button-small" data-lps-pa-detail-index="' + index + '">' + escapeHtml(label('details', 'Details')) + '</button></td></tr>';
    }

    function movementRow(row, index) {
        const metrics = row.metrics || {};
        return '<tr><td><strong>' + escapeHtml(row.abcClass || '—') + '</strong></td><td><code>' + escapeHtml(row.sku || '') + '</code></td><td>' + escapeHtml(row.productName || '') + '</td>' +
            '<td class="num">' + number(metrics.soldUnits) + '</td><td class="num">' + number(metrics.regularSoldUnits) + '</td><td class="num">' + number(metrics.oneOffSoldUnits) + '</td><td class="num">' + number(metrics.returnQuantity) + '</td>' +
            '<td class="num">' + money(metrics.salesRevenue) + '</td><td class="num">' + money(metrics.salesCogs) + '</td><td class="num">' + money(metrics.grossProfit) + '</td>' +
            '<td class="num">' + number(metrics.grossMarginPercent) + '</td><td class="num">' + number(metrics.inventoryTurns) + '</td><td class="num">' + number(metrics.gmroi) + '</td><td class="num">' + number(metrics.coverageDays) + '</td>' +
            '<td><button type="button" class="button button-small" data-lps-pa-detail-index="' + index + '">' + escapeHtml(label('details', 'Details')) + '</button></td></tr>';
    }

    function renderTable(tableId, headId, rowsHtml, headers) {
        const head = el(headId);
        const table = el(tableId);
        if (!head || !table) return;
        head.innerHTML = headers.map((caption) => '<th>' + escapeHtml(caption) + '</th>').join('');
        table.querySelector('tbody').innerHTML = rowsHtml || '<tr><td colspan="' + headers.length + '">' + escapeHtml(label('noProducts', 'No products match the selected filters.')) + '</td></tr>';
    }

    function renderPagination(node) {
        if (!node) return;
        node.innerHTML = '<button type="button" class="button" data-lps-page="previous"' + (state.cursorHistory.length ? '' : ' disabled') + '>' + escapeHtml(label('previousPage', 'Previous page')) + '</button>' +
            '<span>' + escapeHtml(label('page', 'Page')) + ' ' + (state.cursorHistory.length + 1) + '</span>' +
            '<button type="button" class="button" data-lps-page="next"' + (state.nextCursor ? '' : ' disabled') + '>' + escapeHtml(label('nextPage', 'Next page')) + '</button>';
    }

    function renderQuery(data) {
        const totals = data.totals || {};
        renderReportScenario(data.scenarioContext || null);
        el('lps-pa-summary').innerHTML = metricCards(totals.metrics || {}, totals);
        renderWarnings(el('lps-pa-query-warnings'), data.warnings || [], 'query');
        const countText = label('productCount', 'Products found') + ': ' + number(totals.productCount || 0) + ' · ' + label('warehouseRows', 'Warehouse rows') + ': ' + number(totals.warehouseRowCount || 0);
        el('lps-pa-table-meta').textContent = countText;
        el('lps-pa-movements-meta').textContent = countText;
        renderTable('lps-pa-products', 'lps-pa-products-head', state.rows.map(productRow).join(''), productHeaders());
        renderTable('lps-pa-movements', 'lps-pa-movements-head', state.rows.map(movementRow).join(''), movementHeaders());
        renderPagination(el('lps-pa-pagination'));
        renderPagination(el('lps-pa-movements-pagination'));
        root.querySelectorAll('[data-lps-pa-detail-index]').forEach((button) => button.addEventListener('click', () => openDetail(Number(button.dataset.lpsPaDetailIndex))));
    }

    function renderReportScenario(context) {
        const node = el('lps-pa-scenario-status');
        if (!node) return;
        node.classList.remove('is-modified');
        if (!context) {
            node.textContent = label('temporaryReport', 'The report uses temporary filters without a saved scenario.');
            return;
        }
        const name = context.scenarioName || ('#' + Number(context.scenarioId || 0));
        const version = Number(context.scenarioVersion || 0);
        const prefix = label('reportScenario', 'Report scenario') + ': ' + name + ' · ' + label('version', 'Version') + ' ' + version;
        if (context.applicationMode === 'MODIFIED') {
            node.textContent = prefix + ' · ' + label('reportScenarioModified', 'temporary changes were applied');
            node.classList.add('is-modified');
            return;
        }
        if (context.applicationMode === 'LEGACY_CONVERTED') {
            node.textContent = prefix + ' · ' + label('reportScenarioLegacy', 'converted to analytics schema v4');
            node.classList.add('is-modified');
            return;
        }
        node.textContent = prefix;
    }

    function policyDescription(policy) {
        if (!policy) return label('unknownValue', 'Not confirmed');
        const modes = {
            DO_NOT_ORDER: label('doNotOrder', 'Do not order for this warehouse'),
            FORECAST_ONLY: label('forecastOnly', 'Order by forecast only'),
            FORECAST_PLUS_MINIMUM_STOCK: label('forecastPlusMinimum', 'Forecast plus minimum reserve'),
            UNKNOWN: label('unknownValue', 'Not confirmed')
        };
        let description = modes[policy.replenishmentMode] || policy.replenishmentMode || label('unknownValue', 'Not confirmed');
        if (policy.maximumStockLimited === true) description += ' · ' + label('maximumLimit', 'Maximum future stock') + ': ' + number(policy.maximumStockLimit);
        if (policy.maximumStockLimited === false) description += ' · ' + label('unlimitedMaximum', 'No maximum stock limit');
        if (policy.validationState && policy.validationState !== 'VALID') description += ' · ' + policy.validationState;
        return description;
    }

    function metricDetails(metrics) {
        const items = [
            ['physicalQuantity', label('physicalQuantity', 'Physical quantity'), false], ['reservedQuantity', label('reservedQuantity', 'Reserved quantity'), false],
            ['availableQuantity', label('availableQuantity', 'Available quantity'), false], ['inventoryValue', label('inventoryValue', 'Capital in stock'), true],
            ['soldUnits', label('soldUnits', 'Sold units'), false], ['salesRevenue', label('salesRevenue', 'Sales revenue'), true],
            ['salesCogs', label('salesCogs', 'Cost of sales'), true], ['grossProfit', label('grossProfit', 'Gross profit'), true],
            ['regularSoldUnits', label('regularSales', 'Regular sales'), false], ['oneOffSoldUnits', label('oneOffSales', 'One-off sales'), false],
            ['returnQuantity', label('returns', 'Returns'), false], ['averageInventoryValue', label('averageInventoryValue', 'Average inventory value'), true],
            ['inventoryTurns', label('turns', 'Inventory turns'), false], ['gmroi', label('gmroi', 'GMROI'), false],
            ['grossMarginPercent', label('grossMarginPercent', 'Gross margin, %'), false], ['coverageDays', label('coverage', 'Coverage, days'), false]
        ];
        return '<dl class="lps-pa-detail-metrics">' + items.map((item) => '<div><dt>' + escapeHtml(item[1]) + '</dt><dd>' + escapeHtml(item[2] ? money(metrics[item[0]]) : number(metrics[item[0]])) + '</dd></div>').join('') + '</dl>';
    }

    function openDetail(index) {
        const row = state.rows[index];
        if (!row) return;
        const dimensions = row.dimensions || {};
        const groups = [1, 2, 3, 4, 5, 6].map((level) => dimensions['groupLevel' + level + 'Name']).filter(Boolean);
        const breakdown = Array.isArray(row.warehouseBreakdown) ? row.warehouseBreakdown : [];
        const transit = row.inTransitStock || null;
        const transitSuppliers = transit && Array.isArray(transit.suppliers) ? transit.suppliers : [];
        const content = '<header class="lps-pa-detail-header"><div><h2 id="lps-pa-detail-title">' + escapeHtml(row.sku) + ' · ' + escapeHtml(row.productName) + '</h2><p>' + escapeHtml(label('abcClass', 'ABC class')) + ': <strong>' + escapeHtml(row.abcClass || '—') + '</strong></p></div></header>' +
            '<section><h3>' + escapeHtml(label('currentState', 'Current state')) + '</h3>' + metricDetails(row.metrics || {}) + '</section>' +
            '<section><h3>' + escapeHtml(label('product', 'Product')) + '</h3><dl class="lps-pa-detail-list">' +
            '<div><dt>' + escapeHtml(label('gtin', 'Primary GTIN')) + '</dt><dd>' + escapeHtml(dimensions.primaryBarcode || '—') + '</dd></div>' +
            '<div><dt>' + escapeHtml((i18n.filterLabels && i18n.filterLabels.groups) || 'Groups') + '</dt><dd>' + escapeHtml(groups.join(' → ') || '—') + '</dd></div>' +
            '<div><dt>' + escapeHtml((i18n.filterLabels && i18n.filterLabels.departments) || 'Department') + '</dt><dd>' + escapeHtml(dimensions.departmentName || '—') + '</dd></div>' +
            '<div><dt>' + escapeHtml((i18n.filterLabels && i18n.filterLabels.productTypes) || 'Product type') + '</dt><dd>' + escapeHtml(dimensions.productTypeName || '—') + '</dd></div>' +
            '<div><dt>' + escapeHtml(label('supplier', 'Current supplier')) + '</dt><dd>' + escapeHtml((dimensions.currentSuppliers || []).join(', ') || '—') + '</dd></div>' +
            '<div><dt>' + escapeHtml(label('minimumOrderAndPackage', 'Minimum order quantity / package quantity')) + '</dt><dd>' + escapeHtml(number(dimensions.minimumOrderQuantity)) + ' / ' + escapeHtml(number(dimensions.packageQuantity)) + '</dd></div></dl></section>' +
            '<section><h3>' + escapeHtml(label('warehouses', 'Warehouses')) + '</h3><div class="lps-pa-table-scroll"><table class="widefat striped"><thead><tr><th>' + escapeHtml(label('warehouse', 'Warehouse')) + '</th><th>' + escapeHtml(label('supplier', 'Supplier')) + '</th><th>' + escapeHtml(label('availableQuantity', 'Available')) + '</th><th>' + escapeHtml(label('minimumAndMaximumStock', 'Minimum / maximum stock')) + '</th><th>' + escapeHtml(label('localOrderPolicy', 'Order policy')) + '</th></tr></thead><tbody>' +
            breakdown.map((item) => '<tr><td>' + escapeHtml(item.warehouseName || item.warehouseId) + '</td><td>' + escapeHtml(item.currentSupplier || '—') + '</td><td class="num">' + number((item.metrics || {}).availableQuantity) + '</td><td>' + number((item.orderPolicy || {}).minimumStock) + ' / ' + number((item.orderPolicy || {}).maximumStock) + '</td><td>' + escapeHtml(policyDescription(item.orderPolicy)) + '</td></tr>').join('') +
            '</tbody></table></div></section>' +
            '<section><h3>' + escapeHtml(label('networkPolicy', 'Network order policy')) + '</h3><p>' + networkHtml(row.networkOrderPolicy) + '</p>' + (row.networkOrderPolicy && row.networkOrderPolicy.policy ? '<p>' + escapeHtml(policyDescription(row.networkOrderPolicy.policy)) + '</p>' : '') + '</section>' +
            '<section><h3>' + escapeHtml(label('transitStock', 'Stock in transit')) + '</h3><p>' + transitHtml(transit) + '</p>' +
            (transit ? '<p>' + escapeHtml(label('supplierOriginConfirmed', 'Supplier origin confirmed')) + ': <strong>' + escapeHtml(transit.supplierOriginConfirmed === true ? label('yes', 'Yes') : label('no', 'No')) + '</strong></p>' : '') +
            (transitSuppliers.length ? '<h4>' + escapeHtml(label('transitSuppliers', 'Confirmed inbound suppliers')) + '</h4><ul>' + transitSuppliers.map((supplier) => '<li>' + escapeHtml(supplier.name || supplier.code) + ' · ' + number(supplier.receiptQuantityInHorizon) + ' · ' + escapeHtml(supplier.lastReceiptDate || '') + '</li>').join('') + '</ul>' : '') + '</section>';
        el('lps-pa-detail-content').innerHTML = content;
        el('lps-pa-detail').hidden = false;
        document.body.classList.add('lps-pa-detail-open');
    }

    function closeDetail() {
        el('lps-pa-detail').hidden = true;
        document.body.classList.remove('lps-pa-detail-open');
    }

    function switchTab(tab) {
        state.activeTab = tab === 'movements' ? 'movements' : 'products';
        root.querySelectorAll('[data-lps-pa-tab]').forEach((button) => button.classList.toggle('nav-tab-active', button.dataset.lpsPaTab === state.activeTab));
        root.querySelectorAll('[data-lps-pa-panel]').forEach((panel) => { panel.hidden = panel.dataset.lpsPaPanel !== state.activeTab; });
    }

    function resetSelections(preserveScenario) {
        el('lps-pa-search').value = '';
        root.querySelectorAll('.lps-pa-mode').forEach((node) => { node.value = 'ANY'; node.dispatchEvent(new Event('change')); });
        root.querySelectorAll('.lps-pa-values').forEach((node) => Array.from(node.options).forEach((option) => { option.selected = false; }));
        root.querySelectorAll('.lps-pa-text-selection textarea').forEach((node) => { node.value = ''; });
        el('lps-pa-abc-basis').value = 'GROSS_PROFIT';
        el('lps-pa-include-returns').checked = true;
        el('lps-pa-sort-field').value = 'grossProfit';
        el('lps-pa-sort-direction').value = 'DESC';
        el('lps-pa-page-size').value = '50';
        if (!preserveScenario) scenarioSelect.value = '';
        el('lps-pa-scenario-status').textContent = '';
        el('lps-pa-scenario-status').classList.remove('is-modified');
    }

    function setSelection(section, name, selection) {
        if (!selection || typeof selection !== 'object') return;
        const container = root.querySelector('[data-lps-section="' + section + '"][data-lps-selection="' + name + '"]');
        if (!container) return;
        const mode = container.querySelector('.lps-pa-mode');
        if (mode) mode.value = ['INCLUDE', 'EXCLUDE'].includes(selection.mode) ? selection.mode : 'ANY';
        const values = Array.isArray(selection.values) ? selection.values.map(String) : [];
        const select = container.querySelector('.lps-pa-values');
        const textarea = container.querySelector('textarea');
        if (select) Array.from(select.options).forEach((option) => { option.selected = values.includes(option.value); });
        if (textarea) textarea.value = values.join('\n');
        if (mode) mode.dispatchEvent(new Event('change'));
    }

    function normalizeScenarioProfile(scenario) {
        const profile = scenario && scenario.profile ? scenario.profile : {};
        if (Number(scenario && scenario.schemaVersion || profile.schemaVersion) >= 4) return profile;
        const products = profile.products || {};
        const movements = profile.movements || {};
        const productFilters = {};
        if (products.search) productFilters.search = products.search;
        if (products.supplierMode && products.supplierMode !== 'ANY' && Array.isArray(products.supplierValues)) {
            productFilters.currentSuppliers = { mode: products.supplierMode, values: products.supplierValues };
        }
        const movementFilters = {};
        ['operationKind', 'movementClass', 'demandMode', 'documentType', 'stockDirection', 'paymentTerms', 'customerSegment', 'counterparty'].forEach((legacyKey) => {
            if (!movements[legacyKey]) return;
            const v4Names = { operationKind: 'operationKinds', movementClass: 'movementClasses', demandMode: 'demandModes', documentType: 'documentTypes', stockDirection: 'stockDirections', paymentTerms: 'paymentTerms', customerSegment: 'customerSegments', counterparty: 'counterparties' };
            movementFilters[v4Names[legacyKey]] = { mode: 'INCLUDE', values: [movements[legacyKey]] };
        });
        return {
            schemaVersion: 4,
            context: profile.context || {},
            period: { from: movements.documentDateFrom || el('lps-pa-period-from').value, to: movements.documentDateTo || el('lps-pa-period-to').value },
            productFilters: productFilters,
            movementFilters: movementFilters,
            calculation: { abcBasis: 'GROSS_PROFIT', includeReturns: true },
            page: { size: Number(products.perPage || 50) },
            sort: [{ field: 'grossProfit', direction: products.direction === 'ASC' ? 'ASC' : 'DESC' }],
            presentation: profile.presentation || { activeTab: 'products' }
        };
    }

    function applyScenarioFields(profile) {
        state.applyingScenario = true;
        resetSelections(true);
        const productFilters = profile.productFilters || {};
        const movementFilters = profile.movementFilters || {};
        el('lps-pa-search').value = productFilters.search || '';
        Object.entries(productFilters).forEach((entry) => { if (entry[0] !== 'search') setSelection('product', entry[0], entry[1]); });
        Object.entries(movementFilters).forEach((entry) => setSelection('movement', entry[0], entry[1]));
        if (profile.period) {
            if (profile.period.from) el('lps-pa-period-from').value = profile.period.from;
            if (profile.period.to) el('lps-pa-period-to').value = profile.period.to;
        }
        if (profile.calculation) {
            if (['REVENUE', 'GROSS_PROFIT', 'SOLD_UNITS'].includes(profile.calculation.abcBasis)) el('lps-pa-abc-basis').value = profile.calculation.abcBasis;
            el('lps-pa-include-returns').checked = profile.calculation.includeReturns !== false;
        }
        if (profile.page && profile.page.size) el('lps-pa-page-size').value = String(profile.page.size);
        if (Array.isArray(profile.sort) && profile.sort[0]) {
            el('lps-pa-sort-field').value = profile.sort[0].field || 'grossProfit';
            el('lps-pa-sort-direction').value = profile.sort[0].direction === 'ASC' ? 'ASC' : 'DESC';
        }
        switchTab(profile.presentation && profile.presentation.activeTab);
        el('lps-pa-scenario-status').textContent = label('scenarioApplied', 'The analytics scenario has been applied.');
        el('lps-pa-scenario-status').classList.remove('is-modified');
        state.applyingScenario = false;
    }

    function markScenarioModified() {
        if (state.applyingScenario || !scenarioSelect.value) return;
        const node = el('lps-pa-scenario-status');
        node.textContent = label('scenarioModified', 'Temporary changes are applied. The saved scenario has not been changed.');
        node.classList.add('is-modified');
    }

    async function applyScenario() {
        const scenario = state.scenarios.find((item) => String(item.id) === scenarioSelect.value);
        if (!scenario) { resetSelections(); return; }
        const profile = normalizeScenarioProfile(scenario);
        const context = profile.context || {};
        if (context.sourceDatabase) state.sourceDatabase = context.sourceDatabase;
        const available = Array.from(warehouseSelect.options).map((option) => Number(option.value));
        const requested = (context.warehouseIds || []).map(Number).filter((value) => available.includes(value));
        if (!requested.length) {
            setMessage(label('scenarioUnavailable', 'The scenario warehouses are not available.'), 'warning');
            return;
        }
        Array.from(warehouseSelect.options).forEach((option) => { option.selected = requested.includes(Number(option.value)); });
        state.pendingScenario = profile;
        await loadCapabilities();
    }

    warehouseSelect.addEventListener('change', () => { markScenarioModified(); state.pendingScenario = null; loadCapabilities(); });
    el('lps-pa-reload').addEventListener('click', loadCapabilities);
    form.addEventListener('submit', (event) => { event.preventDefault(); state.currentCursor = null; state.cursorHistory = []; runQuery(null, false); });
    form.addEventListener('input', markScenarioModified);
    form.addEventListener('change', markScenarioModified);
    el('lps-pa-reset').addEventListener('click', () => resetSelections(false));
    scenarioSelect.addEventListener('change', applyScenario);
    root.querySelectorAll('[data-lps-pa-tab]').forEach((button) => button.addEventListener('click', () => switchTab(button.dataset.lpsPaTab)));
    root.querySelectorAll('[data-lps-pa-close]').forEach((button) => button.addEventListener('click', closeDetail));
    root.addEventListener('click', (event) => {
        const button = event.target.closest('[data-lps-page]');
        if (!button || button.disabled || state.busy) return;
        if (button.dataset.lpsPage === 'next' && state.nextCursor) {
            state.cursorHistory.push(state.currentCursor);
            runQuery(state.nextCursor, true);
        } else if (button.dataset.lpsPage === 'previous' && state.cursorHistory.length) {
            runQuery(state.cursorHistory.pop(), true);
        }
    });
    document.addEventListener('keydown', (event) => { if (event.key === 'Escape' && !el('lps-pa-detail').hidden) closeDetail(); });

    bootstrap();
}());
