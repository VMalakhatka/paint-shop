(function () {
    'use strict';

    const root = document.getElementById('lavr-profit-report');
    if (!root || typeof LavkaProfitReport === 'undefined') return;

    const config = LavkaProfitReport;
    const labels = config.i18n || {};
    const state = {
        report: null,
        audit: null,
        loading: false,
        auditLoading: false,
        expenseFilter: 'ALL',
        lastOperation: 'summary',
        reportParams: null,
        salaryOverride: false,
        kyivSalaryOverride: false,
        revision: 0,
        dirty: false,
        exporting: false,
        displayWarnings: [],
        savedMetadata: null,
    };

    const nodes = {
        kyivSalary: document.getElementById("lavr-profit-kyiv-salary"),
        kyivSalarySource: document.getElementById("lavr-profit-kyiv-salary-source"),
        exportXlsx: document.getElementById("lavr-profit-export-xlsx"),
        sections: document.getElementById('lavr-profit-sections'),
        resultPeriod: document.getElementById("lavr-profit-result-period"),
        expenseNote: document.getElementById("lavr-profit-expense-note"),
        policy: document.getElementById("lavr-profit-policy"),
        inventory: document.getElementById("lavr-profit-inventory"),
        diagnostics: document.getElementById("lavr-profit-period-diagnostics"),
        month: document.getElementById('lavr-profit-month'),
        calculate: document.getElementById('lavr-profit-calculate'),
        recalculate: document.getElementById('lavr-profit-recalculate'),
        runState: document.getElementById('lavr-profit-run-state'),
        error: document.getElementById('lavr-profit-error'),
        result: document.getElementById('lavr-profit-result'),
        completeness: document.getElementById('lavr-profit-completeness'),
        calculatedAt: document.getElementById('lavr-profit-calculated-at'),
        cities: document.getElementById('lavr-profit-cities'),
        warningsSection: document.getElementById('lavr-profit-warnings-section'),
        warnings: document.getElementById('lavr-profit-warnings'),
        expenseFilter: document.getElementById('lavr-profit-expense-filter'),
        expensesBody: document.querySelector('#lavr-profit-expenses-table tbody'),
        controlsContent: document.getElementById('lavr-profit-controls-content'),
        loadAudit: document.getElementById('lavr-profit-load-audit'),
        auditContent: document.getElementById('lavr-profit-audit-content'),
        auditNote: document.getElementById('lavr-profit-audit-note'),
        auditCity: document.getElementById('lavr-profit-audit-city'),
        auditCategory: document.getElementById('lavr-profit-audit-category'),
        auditTreatment: document.getElementById('lavr-profit-audit-treatment'),
        auditUnclassified: document.getElementById('lavr-profit-audit-unclassified'),
        auditBody: document.querySelector('#lavr-profit-audit-table tbody'),
        exportAudit: document.getElementById('lavr-profit-export-audit'),
        taxShare: document.getElementById('lavr-profit-tax-share'),
        taxShareHelp: document.getElementById('lavr-profit-tax-share-help'),
        rubRate: document.getElementById('lavr-profit-rub-rate'),
        masterClass: document.getElementById('lavr-profit-master-class'),
        masterAudit: document.getElementById('lavr-profit-master-audit'),
        salarySource: document.getElementById('lavr-profit-salary-source'),
        additionalSalary: document.getElementById('lavr-profit-additional-salary'),
    };

    const treatmentLabels = {
        OPERATING_EXPENSE: labels.operatingTreatment,
        CAPITALIZED_IN_INVENTORY: labels.capitalizedTreatment,
        EXCLUDED: labels.excludedTreatment,
        UNCLASSIFIED: labels.unclassifiedTreatment,
    };
    const warningGroups = {
        PROFIT_REPORT_SECTION_UNAVAILABLE: 'action',
        CLIENT_SECTION_UNAVAILABLE: 'action',
        UNCLASSIFIED_DOCUMENTS: 'action',
        UNKNOWN_TAX_POOL: 'action',
        AMBIGUOUS_EXPLICIT_PERIOD: 'action',
        MASTER_CLASS_ARTICLE_NOT_FOUND: 'action',
        MASTER_CLASS_DUPLICATE_MOVEMENT_ROWS: 'action',
        MASTER_CLASS_NEGATIVE_SOURCE_AMOUNT: 'action',
        MASTER_CLASS_LINES_IGNORED: 'info',
        MASTER_CLASS_LEGACY_PARAMETERS_IGNORED: 'action',
        IMPORT_TRANSPORT_CAPITALIZED: 'info',
        LEGACY_FLOAT_ROUNDING: 'info',
        NOLOCK_READ: 'info',
    };

    function previousMonth() {
        const date = new Date();
        date.setDate(1);
        date.setMonth(date.getMonth() - 1);
        return date.getFullYear() + '-' + String(date.getMonth() + 1).padStart(2, '0');
    }

    function textElement(tag, className, text) {
        const element = document.createElement(tag);
        if (className) element.className = className;
        element.textContent = text == null ? '' : String(text);
        return element;
    }

    function clear(node) {
        while (node.firstChild) node.removeChild(node.firstChild);
    }

    function decimalParts(value) {
        const raw = String(value == null || value === '' ? '0' : value).trim().replace(',', '.');
        const match = raw.match(/^(-?)(\d+)(?:\.(\d+))?$/);
        if (!match) return null;
        return {
            negative: match[1] === '-',
            integer: match[2].replace(/^0+(?=\d)/, ''),
            fraction: (match[3] || '').padEnd(2, '0').slice(0, 2),
        };
    }

    function formatMoney(value, currency) {
        if (value == null || value === "") return "—";
        const parts = decimalParts(value);
        if (!parts) return String(value == null ? '' : value);
        let grouped;
        try {
            grouped = new Intl.NumberFormat('uk-UA', { maximumFractionDigits: 0 }).format(BigInt(parts.integer || '0'));
        } catch (error) {
            grouped = parts.integer.replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
        }
        const suffix = currency === 'RUB' ? labels.rub : (!currency || currency === 'UAH' ? labels.uah : currency);
        return (parts.negative ? '-' : '') + grouped + ',' + parts.fraction + ' ' + suffix;
    }

    function compareDecimalToZero(value) {
        const raw = String(value == null || value === '' ? '0' : value).trim().replace(',', '.');
        const match = raw.match(/^(-?)(\d+)(?:\.(\d+))?$/);
        if (!match) return 0;
        const nonZero = /[1-9]/.test(match[2] + (match[3] || ''));
        if (!nonZero) return 0;
        return match[1] === '-' ? -1 : 1;
    }

    function shiftDecimal(value, places) {
        const raw = String(value == null ? '' : value).trim().replace(',', '.');
        if (!/^\d+(?:\.\d+)?$/.test(raw)) return raw;
        const pieces = raw.split('.');
        const digits = pieces[0] + (pieces[1] || '');
        const originalPoint = pieces[0].length;
        const targetPoint = originalPoint + places;
        let result;
        if (targetPoint <= 0) {
            result = '0.' + '0'.repeat(Math.abs(targetPoint)) + digits;
        } else if (targetPoint >= digits.length) {
            result = digits + '0'.repeat(targetPoint - digits.length);
        } else {
            result = digits.slice(0, targetPoint) + '.' + digits.slice(targetPoint);
        }
        result = result.replace(/^0+(?=\d)/, '').replace(/(\.\d*?)0+$/, '$1').replace(/\.$/, '');
        return result || '0';
    }

    function formatDate(value) {
        const match = String(value || '').match(/^(\d{4})-(\d{2})-(\d{2})/);
        return match ? match[3] + '.' + match[2] + '.' + match[1] : String(value || '');
    }

    function formatDateTime(value) {
        if (!value) return '';
        const numeric = typeof value === "number" || /^\d+(\.\d+)?$/.test(String(value));
        const timestamp = numeric ? Number(value) : value;
        const date = new Date(numeric && timestamp < 100000000000 ? timestamp * 1000 : timestamp);
        if (Number.isNaN(date.getTime())) return String(value);
        return new Intl.DateTimeFormat('uk-UA', {
            timeZone: 'Europe/Kyiv', day: '2-digit', month: '2-digit', year: 'numeric',
            hour: '2-digit', minute: '2-digit', second: '2-digit',
        }).format(date);
    }

    function cityLabel(city) {
        const value = String(city || '').toUpperCase();
        if (value === 'KYIV' || value === 'KIEV') return labels.kyiv;
        if (value === 'ODESA' || value === 'ODESSA') return labels.odesa;
        if (!value || ['UNALLOCATED', 'NONE', 'SHARED'].includes(value)) return labels.unallocated;
        return city;
    }

    function treatmentLabel(treatment) {
        return treatmentLabels[treatment] || treatment || labels.unclassifiedTreatment;
    }

    function requestParams() {
        const params = { month: nodes.month.value };
        const fields = [nodes.rubRate];
        if (state.salaryOverride) fields.push(nodes.additionalSalary);
        if (state.kyivSalaryOverride && !nodes.kyivSalary.disabled) fields.push(nodes.kyivSalary);
        fields.forEach((field) => {
            const value = field.value.trim();
            if (value !== '') params[field.dataset.param] = value.replace(',', '.');
        });
        const taxPercent = nodes.taxShare.value.trim().replace(',', '.');
        if (taxPercent !== '') params.odesaTaxShare = shiftDecimal(taxPercent, -2);
        return params;
    }

    function showRunState(message, kind) {
        nodes.runState.hidden = !message;
        nodes.runState.className = 'lavr-profit-run-state' + (kind ? ' is-' + kind : '');
        nodes.runState.textContent = message || '';
    }

    function setBusy(operation, busy) {
        if (operation === 'audit') {
            state.auditLoading = busy;
            nodes.exportXlsx.disabled = busy || state.exporting || state.dirty;
            nodes.exportAudit.disabled = busy || state.dirty;
            nodes.loadAudit.disabled = busy;
            nodes.calculate.disabled = busy;
            nodes.recalculate.disabled = busy;
            nodes.loadAudit.textContent = busy ? labels.loadingAudit : nodes.loadAudit.dataset.defaultLabel;
            root.setAttribute('aria-busy', busy ? 'true' : 'false');
            if (busy) showRunState(labels.loadingAudit, 'loading');
            return;
        }
        state.loading = busy;
        nodes.exportXlsx.disabled = busy || state.exporting || state.dirty;
        nodes.calculate.disabled = busy;
        nodes.recalculate.disabled = busy;
        nodes.loadAudit.disabled = busy;
        root.setAttribute('aria-busy', busy ? 'true' : 'false');
        if (busy) showRunState(state.report ? labels.recalculating : labels.loading, 'loading');
        nodes.result.classList.toggle('is-stale', busy && !!state.report);
    }

    function clearFieldErrors() {
        root.querySelectorAll('.lavr-profit-field-error').forEach((node) => node.remove());
        root.querySelectorAll('[aria-invalid="true"]').forEach((node) => node.removeAttribute('aria-invalid'));
    }

    function showFieldError(fieldName, message) {
        const field = fieldName === 'month'
            ? nodes.month
            : root.querySelector('[data-param="' + CSS.escape(fieldName) + '"]');
        if (!field) return false;
        field.setAttribute('aria-invalid', 'true');
        const error = textElement('p', 'lavr-profit-field-error', message);
        field.closest('.lavr-profit-field').appendChild(error);
        return true;
    }

    function hideError() {
        nodes.error.hidden = true;
        clear(nodes.error);
        clearFieldErrors();
    }

    function showError(message, retryOperation, field) {
        nodes.error.hidden = false;
        clear(nodes.error);
        const paragraph = textElement('p', '', message || labels.generalError);
        nodes.error.appendChild(paragraph);
        if (field && showFieldError(field, message)) return;
        if (retryOperation) {
            const button = textElement('button', 'button', labels.retry);
            button.type = 'button';
            button.addEventListener('click', () => loadReport(retryOperation));
            nodes.error.appendChild(button);
        }
    }

    async function proxyRequest(operation, params) {
        const formData = new FormData();
        formData.append('action', config.action);
        formData.append('nonce', config.nonce);
        formData.append('operation', operation);
        Object.keys(params).forEach((key) => formData.append(key, params[key]));

        const response = await fetch(config.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            body: formData,
        });
        const wrapper = await response.json().catch(() => null);
        if ([401, 403].includes(response.status)) throw { status: response.status, message: (wrapper && wrapper.data && wrapper.data.message) || labels.accessExpired, errorCode: wrapper && wrapper.data && wrapper.data.code };
        if (!wrapper) throw { status: response.status, message: labels.invalidResponse };
        if (!wrapper.success) {
            const data = wrapper.data || {};
            throw { status: response.status, message: data.message || data.err || labels.generalError, field: data.field || '' };
        }

        const httpStatus = Number(wrapper.data.httpStatus || 0);
        let body;
        try {
            body = JSON.parse(wrapper.data.bodyRaw || '{}');
        } catch (error) {
            throw { status: httpStatus, message: labels.invalidResponse };
        }
        if (httpStatus < 200 || httpStatus >= 300 || body.ok === false) {
            const violation = (Array.isArray(body.violations) && body.violations[0])
                || (Array.isArray(body.errors) && body.errors[0])
                || null;
            const field = body.field
                || (body.details && body.details.field)
                || (violation && (violation.field || violation.property))
                || '';
            const message = (violation && violation.message)
                || body.message
                || body.title
                || labels.generalError;
            throw { status: httpStatus, message, field, errorCode: body.code || body.errorCode, errorId: body.errorId || body.requestId };
        }
        return body;
    }

    async function loadReport(operation) {
        if (config.savedMode) {
            if (operation === 'audit') { if (state.audit && !state.dirty) renderAudit(); return !!state.audit; }
            return window.LavkaProfitHistory ? window.LavkaProfitHistory.calculateMonth(requestParams()) : false;
        }
        if (state.loading || state.auditLoading || (operation === 'audit' && state.dirty)) return false;
        const revision = state.revision;
        if (!nodes.month.value) {
            showError(labels.monthRequired, null, 'month');
            return;
        }
        hideError();
        if (operation === 'summary') state.dirty = true;
        state.lastOperation = operation;
        setBusy(operation, true);
        const params = operation === 'audit' && state.reportParams
            ? state.reportParams
            : requestParams();
        try {
            const data = await proxyRequest(operation, params);
            if (revision !== state.revision) return false;
            if (data.month !== params.month || !Array.isArray(data.cities)) throw { message: labels.invalidResponse };
            state.dirty = false;
            state.report = data;
            state.reportParams = Object.assign({}, params);
            if (operation === 'audit') {
                state.audit = data;
                renderReport();
                renderAudit();
                showRunState(hasUnavailableSections(data) ? labels.partialReady : labels.auditReady, hasUnavailableSections(data) ? 'warning' : 'success');
            } else {
                state.report = data;
                state.reportParams = Object.assign({}, params);
                state.audit = null;
                renderReport();
                showRunState(hasUnavailableSections(data) ? labels.partialReady : labels.reportReady, hasUnavailableSections(data) ? 'warning' : 'success');
            }
            return true;
        } catch (error) {
            if (revision !== state.revision) return false;
            const retry = Number(error.status || 0) >= 500 || !error.status ? operation : null;
            showRunState(labels.requestFailed, 'error');
            showError(error.message || labels.generalError, retry, error.field || '');
            const diagnostic = [error.status ? 'HTTP ' + error.status : '', error.errorCode, error.errorId].filter(Boolean).join(' · ');
            if (diagnostic) nodes.error.appendChild(textElement('p', 'description', diagnostic));
            if (state.report) nodes.error.appendChild(textElement('p', '', labels.previousSnapshot));
        } finally {
            setBusy(operation, false);
            updateAvailability();
        }
    }

    function applyInitialInputs(inputs) {
        if (!inputs) return;
        if (nodes.taxShare.value === '' && inputs.odesaTaxShare != null) {
            nodes.taxShare.value = shiftDecimal(inputs.odesaTaxShare, 2);
        }
        if (nodes.rubRate.value === '' && inputs.rubToUahRate != null) {
            nodes.rubRate.value = String(inputs.rubToUahRate);
        }
        if (!state.salaryOverride) nodes.additionalSalary.value = inputs.odesaAdditionalSalary == null ? '' : String(inputs.odesaAdditionalSalary);
        const source = inputs.odesaAdditionalSalarySource === 'REQUEST_OVERRIDE' ? labels.salaryOverride : labels.salaryDefault;
        nodes.salarySource.textContent = labels.salaryApplied + ': ' + formatMoney(inputs.odesaAdditionalSalary, 'UAH') + ' · ' + source;
        nodes.kyivSalary.disabled = !Object.prototype.hasOwnProperty.call(inputs, 'kyivAdditionalSalary');
        if (!state.kyivSalaryOverride) nodes.kyivSalary.value = inputs.kyivAdditionalSalary == null ? '' : String(inputs.kyivAdditionalSalary);
        nodes.kyivSalarySource.textContent = nodes.kyivSalary.disabled ? labels.legacyKyiv : labels.salaryApplied + ': ' + formatMoney(inputs.kyivAdditionalSalary, 'UAH') + ' · ' + (inputs.kyivAdditionalSalarySource === 'REQUEST_OVERRIDE' ? labels.salaryOverride : labels.salaryDefault);
        updateTaxShareHelp();
    }

    function updateTaxShareHelp() {
        const value = parseFloat(nodes.taxShare.value.replace(',', '.'));
        const base = labels.threeOfSeven && Math.abs(value - (3 / 7 * 100)) < 0.02
            ? labels.threeOfSeven
            : '';
        nodes.taxShareHelp.textContent = base
            ? labels.shareHelp + ' ' + base + '.'
            : labels.shareHelp;
    }

    function metric(label, value, emphasized) {
        const item = document.createElement('div');
        item.className = 'lavr-profit-metric' + (emphasized ? ' is-emphasized' : '');
        item.appendChild(textElement('span', 'lavr-profit-metric-label', label));
        const amount = textElement('strong', 'lavr-profit-metric-value', formatMoney(value, 'UAH'));
        if (emphasized && value != null && value !== '') {
            const sign = compareDecimalToZero(value);
            amount.classList.add(sign < 0 ? 'is-negative' : 'is-positive');
        }
        item.appendChild(amount);
        return item;
    }

    function renderCities(cities) {
        clear(nodes.cities);
        if (!(cities || []).length) {
            nodes.cities.appendChild(textElement('p', 'lavr-profit-empty', labels.noData));
            return;
        }
        (cities || []).forEach((city) => {
            const section = document.createElement('article');
            section.className = 'lavr-profit-city';
            section.appendChild(textElement('h3', '', cityLabel(city.city)));
            const grid = document.createElement('div');
            grid.className = 'lavr-profit-metrics';
            grid.appendChild(metric(labels.baseGrossProfit, city.baseGrossProfit));
            grid.appendChild(metric(labels.manualGrossAdjustments, city.manualGrossAdjustments));
            grid.appendChild(metric(labels.grossProfit, city.grossProfit));
            grid.appendChild(metric(labels.operatingExpenses, city.operatingExpenses));
            grid.appendChild(metric(labels.profit, city.profit, true));
            section.appendChild(grid);
            nodes.cities.appendChild(section);
        });
    }

    function warningPriority(code) {
        const group = warningGroup(code);
        return group === 'action' ? 0 : group === 'manual' ? 1 : 2;
    }

    function renderWarnings(warnings) {
        clear(nodes.warnings);
        const items = (warnings || []).filter(warning => warning && typeof warning === 'object').sort((a, b) => warningPriority(a.code) - warningPriority(b.code));
        nodes.warningsSection.hidden = items.length === 0;
        items.forEach((warning) => {
            const group = warningGroup(warning.code);
            const item = document.createElement('div');
            item.className = 'lavr-profit-warning is-' + group;
            const title = group === 'action' ? labels.warningAction : group === 'manual' ? labels.warningManual : labels.warningInfo;
            const heading = document.createElement('div');
            heading.className = 'lavr-profit-warning-heading';
            heading.appendChild(textElement('strong', '', title));
            heading.appendChild(textElement('code', '', warning.code || 'WARNING'));
            item.appendChild(heading);
            item.appendChild(textElement('p', '', warning.message || warning.code || ''));
            if (warning.details && Object.keys(warning.details).length) {
                const details = document.createElement('details');
                details.appendChild(textElement('summary', '', labels.details));
                details.appendChild(textElement('pre', '', JSON.stringify(warning.details, null, 2)));
                item.appendChild(details);
            }
            nodes.warnings.appendChild(item);
        });
    }

    function createSegment(value, label) {
        const button = textElement('button', 'button lavr-profit-segment', label);
        button.type = 'button';
        button.dataset.value = value;
        button.classList.toggle('is-active', state.expenseFilter === value);
        button.addEventListener('click', () => {
            state.expenseFilter = value;
            renderExpenseFilters();
            safeRender(labels.allExpenseRows, document.getElementById('lavr-profit-expenses-table'), renderExpenses);
        });
        return button;
    }

    function renderExpenseFilters() {
        clear(nodes.expenseFilter);
        nodes.expenseFilter.appendChild(createSegment('ALL', labels.all));
        nodes.expenseFilter.appendChild(createSegment('KYIV', labels.kyiv));
        nodes.expenseFilter.appendChild(createSegment('ODESA', labels.odesa));
        nodes.expenseFilter.appendChild(createSegment('UNALLOCATED', labels.unallocated));
    }

    function matchesCity(value, filter) {
        const city = String(value || 'UNALLOCATED').toUpperCase();
        if (filter === 'ALL') return true;
        if (filter === 'KYIV') return city === 'KYIV' || city === 'KIEV';
        if (filter === 'ODESA') return city === 'ODESA' || city === 'ODESSA';
        return !value || ['UNALLOCATED', 'NONE', 'SHARED'].includes(city);
    }

    function tableCell(text, className) {
        const cell = textElement('td', className || '', text);
        return cell;
    }

    function renderExpenses() {
        const data = state.report || {};
        nodes.expenseNote.textContent = sectionUnavailable(data, 'EXPENSES') ? labels.unavailable + '. ' + labels.partialHelp : Array.isArray(data.expenseLines) ? labels.detailedRows : labels.legacyRows;
        renderGrid(document.getElementById('lavr-profit-expenses-table'), expenseColumns(), expenseRows(data).filter(item => matchesCity(item.city, state.expenseFilter)), sectionUnavailable(data, 'EXPENSES') ? labels.unavailable : null);
    }

    function controlItem(label, value, extra) {
        const item = document.createElement('div');
        item.className = 'lavr-profit-control-item';
        item.appendChild(textElement('span', '', label));
        item.appendChild(textElement('strong', '', value));
        if (extra) item.appendChild(textElement('small', '', extra));
        return item;
    }

    function renderControls(controls) {
        clear(nodes.controlsContent);
        const grid = document.createElement('div');
        grid.className = 'lavr-profit-control-grid';
        grid.appendChild(controlItem(labels.selectedDocuments, printable(controls.selectedDocumentCount)));
        grid.appendChild(controlItem(labels.selectedAmount, formatMoney(controls.selectedDocumentAmount, 'UAH')));
        grid.appendChild(controlItem(labels.operatingTotal, formatMoney(controls.operatingExpenseTotal, 'UAH')));
        grid.appendChild(controlItem(labels.capitalizedTotal, formatMoney(controls.capitalizedCostTotal, 'UAH')));
        grid.appendChild(controlItem(labels.excludedTotal, formatMoney(controls.excludedDocumentAmount, 'UAH')));
        grid.appendChild(controlItem(
            labels.unclassifiedTotal,
            formatMoney(controls.unclassifiedDocumentAmount, 'UAH'),
            printable(controls.unclassifiedDocumentCount) + ' ' + labels.documentsLower
        ));
        nodes.controlsContent.appendChild(grid);

        nodes.controlsContent.appendChild(textElement('p', 'description', labels.controlsHelp));
        const known = new Set(['selectedDocumentCount', 'selectedDocumentAmount', 'operatingExpenseTotal', 'capitalizedCostTotal', 'excludedDocumentAmount', 'unclassifiedDocumentAmount', 'unclassifiedDocumentCount', 'auditTruncated', 'taxPools']);
        const extra = Object.entries(controls).filter(([key]) => !known.has(key)).map(([key, value]) => ({ parameter: fieldLabel(key), value: printable(value) }));
        if (extra.length) appendGrid(nodes.controlsContent, ['parameter', 'value'].map(key => columnSpec(key)), extra);
        const pools = controls.taxPools || {};
        const keys = Object.keys(pools);
        if (keys.length) {
            nodes.controlsContent.appendChild(textElement('h3', '', labels.taxPools));
            const poolList = document.createElement('dl');
            poolList.className = 'lavr-profit-tax-pools';
            keys.forEach((key) => {
                poolList.appendChild(textElement('dt', '', key));
                poolList.appendChild(textElement('dd', '', formatMoney(pools[key], 'UAH')));
            });
            nodes.controlsContent.appendChild(poolList);
        }
        if (controls.auditTruncated) {
            nodes.controlsContent.appendChild(textElement('p', 'notice notice-warning inline lavr-profit-inline-notice', labels.auditTruncated));
        }
    }

    function renderReport() {
        const data = state.report || {};
        nodes.result.hidden = false;
        nodes.completeness.textContent = data.complete ? labels.complete : labels.incomplete;
        nodes.completeness.className = 'lavr-profit-badge ' + (data.complete ? 'is-complete' : 'is-incomplete');
        nodes.calculatedAt.textContent = data.calculatedAt
            ? labels.calculatedAt.replace('%s', formatDateTime(data.calculatedAt))
            : '';
        nodes.resultPeriod.textContent = labels.reportMonth + ": " + data.month;
        state.displayWarnings = [];
        safeRender(labels.appliedParameters, nodes.policy, () => renderSupplement(data));
        safeRender(labels.appliedParameters, null, () => applyInitialInputs(data.inputs || {}));
        safeRender(labels.profitByCity, nodes.cities, () => renderCities(data.cities || []));
        safeRender(labels.masterTitle, nodes.masterClass, () => renderMasterClass(data.masterClass));
        nodes.masterAudit.hidden = true;
        renderExpenseFilters();
        safeRender(labels.allExpenseRows, document.getElementById('lavr-profit-expenses-table'), renderExpenses);
        safeRender(labels.controlTotals, nodes.controlsContent, () => renderControls(data.controls || {}));
        renderSectionStatus(data);
        renderWarnings([...(Array.isArray(data.warnings) ? data.warnings : []), ...state.displayWarnings]);
        if (hasUnavailableSections(data)) {
            nodes.completeness.textContent = labels.partialReport;
            nodes.completeness.className = 'lavr-profit-badge is-incomplete';
        }
        nodes.auditContent.hidden = true;
        nodes.auditNote.hidden = true;
        nodes.loadAudit.disabled = false;
    }

    function selectOptions(select, values, allLabel, formatter) {
        const current = select.value;
        clear(select);
        const all = document.createElement('option');
        all.value = '';
        all.textContent = allLabel;
        select.appendChild(all);
        values.forEach((value) => {
            const option = document.createElement('option');
            option.value = value;
            option.textContent = formatter ? formatter(value) : value;
            select.appendChild(option);
        });
        if ([...select.options].some((option) => option.value === current)) select.value = current;
    }

    function uniqueValues(rows, key) {
        return [...new Set(rows.map((row) => row[key]).filter(Boolean))].sort();
    }

    function filteredAuditRows() {
        const source = state.audit && state.audit.documents;
        const rows = Array.isArray(source) ? source.slice() : [];
        return rows.filter((row) => {
            if (nodes.auditCity.value && row.city !== nodes.auditCity.value) return false;
            if (nodes.auditCategory.value && row.category !== nodes.auditCategory.value) return false;
            if (nodes.auditTreatment.value && row.accountingTreatment !== nodes.auditTreatment.value) return false;
            if (nodes.auditUnclassified.checked && row.accountingTreatment !== 'UNCLASSIFIED') return false;
            return true;
        });
    }

    function renderAuditRows() {
        const rows = filteredAuditRows();
        renderGrid(document.getElementById('lavr-profit-audit-table'), documentColumns(rows), rows, sectionUnavailable(state.audit, 'EXPENSES') ? labels.unavailable : null);
    }

    function renderMasterClass(data) {
        clear(nodes.masterClass);
        nodes.masterClass.appendChild(textElement('h2', '', labels.masterTitle));
        if (!data) {
            nodes.masterClass.appendChild(textElement('p', 'notice notice-error inline', labels.masterUnavailable));
            return;
        }
        if (!data.articleFound) nodes.masterClass.appendChild(textElement('p', 'notice notice-error inline', labels.masterMissing));
        nodes.masterClass.appendChild(textElement('p', '', labels.sku + ': ' + (data.sku || '') + ' · ' + labels.warehouse + ': ' + data.warehouseId));
        const grid = textElement('div', 'lavr-profit-metrics', '');
        [['masterIncome', 'income'], ['masterReturns', 'returns'], ['masterNet', 'netContribution'], ['masterBase', 'grossProfitAlreadyInBase'], ['masterAdjustment', 'grossAdjustmentApplied']].forEach(([label, key]) => grid.appendChild(metric(labels[label], data[key])));
        nodes.masterClass.appendChild(grid);
        [['incomeLines','incomeLineCount'], ['returnLines','returnLineCount'], ['ignoredLines','ignoredLineCount'], ['duplicateLines','duplicateLineCount'], ['source','source']].forEach(([label,key]) => nodes.masterClass.appendChild(textElement('p', 'description', labels[label] + ': ' + (data[key] == null ? '' : data[key]))));
        if (data.auditTruncated) nodes.masterClass.appendChild(textElement('p', 'notice notice-warning inline', labels.masterTruncated));
    }

    function renderMasterAudit() {
        const data = state.audit || {};
        clear(nodes.masterAudit);
        nodes.masterAudit.hidden = false;
        nodes.masterAudit.appendChild(textElement('h3', '', labels.masterAuditTitle));
        if (!data.masterClass || !Array.isArray(data.masterClassDocuments)) {
            nodes.masterAudit.appendChild(textElement('p', 'notice notice-error inline', labels.masterUnavailable));
            return;
        }
        if (data.masterClass.auditTruncated) nodes.masterAudit.appendChild(textElement('p', 'notice notice-warning inline', labels.masterTruncated));
        if (!data.masterClass.articleFound) nodes.masterAudit.appendChild(textElement('p', 'notice notice-error inline', labels.masterMissing));
        const wrap = textElement('div', 'lavr-profit-table-wrap', '');
        const table = textElement('table', 'widefat striped', '');
        const head = document.createElement('thead');
        const header = document.createElement('tr');
        ['csvDate','csvDocument','line','sku','warehouse','documentTypes','operationKind','returnFlag','accounted','quantity','unitPrice','csvSourceAmount','classification','masterIncluded','csvReason'].forEach(key => header.appendChild(textElement('th', '', labels[key])));
        head.appendChild(header); table.appendChild(head);
        const body = document.createElement('tbody');
        data.masterClassDocuments.forEach(line => {
            const row = document.createElement('tr');
            [formatDate(line.documentDate), [line.documentNumber, line.documentNumberSuffix].filter(Boolean).join(' ') + ' / ' + line.documentId,
                line.lineNumber + ' / ' + line.movementId, line.sku, line.warehouseId,
                [line.documentType,line.movementType].join(' / '), line.operationKind,
                line.returnDocument ? labels.yes : labels.no, line.accounted ? labels.yes : labels.no,
                line.quantity, formatMoney(line.unitPrice,line.currency), formatMoney(line.amount,line.currency) + ' · ' + (line.amountSource || ''),
                line.classification, line.includedInMasterClassContribution ? labels.yes : labels.no, line.reason].forEach(value => row.appendChild(tableCell(value)));
            body.appendChild(row);
        });
        table.appendChild(body); wrap.appendChild(table); nodes.masterAudit.appendChild(wrap);
        if (!data.masterClassDocuments.length) nodes.masterAudit.appendChild(textElement('p', '', labels.masterAuditEmpty));
    }

    function renderAudit() {
        safeRender(labels.masterAuditTitle, nodes.masterAudit, renderMasterAudit);
        const source = state.audit && state.audit.documents;
        const rows = Array.isArray(source) ? source : [];
        nodes.auditContent.hidden = false;
        selectOptions(nodes.auditCity, uniqueValues(rows, 'city'), labels.all, cityLabel);
        selectOptions(nodes.auditCategory, uniqueValues(rows, 'category'), labels.all);
        selectOptions(nodes.auditTreatment, uniqueValues(rows, 'accountingTreatment'), labels.all, treatmentLabel);
        const truncated = !!(state.audit && state.audit.controls && state.audit.controls.auditTruncated);
        nodes.auditNote.hidden = false;
        nodes.auditNote.textContent = sectionUnavailable(state.audit, 'EXPENSES') ? labels.unavailable + '. ' + labels.partialHelp : labels.auditCoverage + ' · ' + labels.fields.documentCount + ': ' + rows.length + (truncated ? ' · ' + labels.auditTruncated + ' ' + labels.exportLoaded : '');
        safeRender(labels.auditTitle, document.getElementById('lavr-profit-audit-table'), renderAuditRows);
        renderSectionStatus(state.audit);
        renderWarnings([...(state.audit.warnings || []), ...state.displayWarnings]);
    }

    function csvValue(value) {
        let string = String(value == null ? '' : value);
        if (/^[\s]*[=+@-]/.test(string) || /^[\t\r\n]/.test(string)) string = "'" + string;
        return '"' + string.replace(/"/g, '""') + '"';
    }

    function exportAudit() {
        if (state.dirty || state.loading || state.auditLoading) return;
        const rows = filteredAuditRows();
        const columns = documentColumns(rows);
        const lines = [columns.map(col => csvValue(col.title)).join(';')];
        rows.forEach(row => lines.push(columns.map(col => csvValue(printable(col.get(row)))).join(';')));
        const blob = new Blob(['\uFEFF' + lines.join('\r\n')], { type: 'text/csv;charset=utf-8' });
        const url = URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = url;
        link.download = 'folio-profit-audit-' + state.report.month + '.csv';
        document.body.appendChild(link);
        link.click();
        link.remove();
        URL.revokeObjectURL(url);
    }

    function sectionUnavailable(data, key) {
        return !!(data && data.sections && data.sections[key] && data.sections[key].status !== 'AVAILABLE');
    }
    function hasUnavailableSections(data) {
        return state.displayWarnings.length > 0 || Object.values(data.sections || {}).some(section => section && section.status !== 'AVAILABLE');
    }
    function safeRender(name, target, render) {
        try { render(); } catch (error) {
            state.displayWarnings.push({code: 'CLIENT_SECTION_UNAVAILABLE', message: labels.sectionDisplayFailed, details: {section: name}});
            if (target) {
                clear(target);
                if (target.tagName === 'TABLE') {
                    const body = document.createElement('tbody'), row = document.createElement('tr');
                    row.appendChild(tableCell(labels.sectionDisplayFailed)); body.appendChild(row); target.appendChild(body);
                } else target.appendChild(textElement('p', 'notice notice-warning inline', labels.sectionDisplayFailed));
            }
        }
    }
    function sectionRows(data) {
        return Object.entries(data.sections || {}).map(([key, value]) => ({section: fieldLabel(key), ...(value || {})}));
    }
    function renderSectionStatus(data) {
        clear(nodes.sections);
        const rows = sectionRows(data);
        nodes.sections.hidden = !rows.length && !state.displayWarnings.length;
        if (nodes.sections.hidden) return;
        nodes.sections.appendChild(textElement('h2', '', labels.sectionStatus));
        nodes.sections.appendChild(textElement('p', 'description', labels.partialHelp));
        if (rows.length) appendGrid(nodes.sections, dynamicColumns(rows, ['section', 'status', 'message', 'errorCode', 'errorId']), rows);
    }

    function warningGroup(code) {
        return warningGroups[code] || (/PERIOD|NEGATIVE_CLOSING|ZERO_VALUE_CLOSING/.test(code || '') ? 'action' : 'info');
    }

    function updateAvailability() {
        const busy = state.loading || state.auditLoading;
        nodes.exportXlsx.disabled = busy || state.exporting || state.dirty || !state.report;
        nodes.exportAudit.disabled = busy || state.dirty || !state.audit || sectionUnavailable(state.audit, 'EXPENSES');
        nodes.loadAudit.disabled = busy || state.dirty || !state.report;
        nodes.result.classList.toggle('is-stale', busy || state.dirty);
    }

    function invalidateResult() {
        state.revision++;
        state.dirty = true;
        showRunState(labels.parametersChanged, 'warning');
        updateAvailability();
    }

    function printable(value) {
        if (value == null) return '—';
        if (typeof value === 'boolean') return value ? labels.yes : labels.no;
        if (Array.isArray(value)) return value.map(printable).join(' · ');
        return typeof value === 'object' ? JSON.stringify(value) : String(value);
    }

    function fieldLabel(key) { return (labels.fields || {})[key] || key; }
    const moneyFields = new Set(['amount', 'profitImpact', 'sourceAmount', 'reportAmount', 'kyivAllocation', 'odesaAllocation', 'unitPrice',
        'openingAccountingValue', 'closingAccountingValue', 'accountingValueChange', 'baseGrossProfit', 'manualGrossAdjustments', 'grossProfit', 'operatingExpenses', 'profit',
        'selectedDocumentAmount', 'operatingExpenseTotal', 'capitalizedCostTotal', 'excludedDocumentAmount', 'unclassifiedDocumentAmount', 'provisionalDocumentAmount', 'provisionalOperatingExpenseTotal',
        'income', 'returns', 'netContribution', 'grossProfitAlreadyInBase', 'grossAdjustmentApplied']);
    const numberFields = new Set(['selectedDocumentCount', 'unclassifiedDocumentCount', 'periodDiagnosticCount', 'periodProblemCount', 'provisionalDocumentCount', 'documentCount', 'quantity', 'appliedRate', 'openingPositionCount', 'closingPositionCount', 'negativeClosingPositionCount', 'zeroValueClosingPositionCount']);
    function columnSpec(key, getter) { return { key, title: fieldLabel(key), get: getter || (row => row[key]), money: moneyFields.has(key), number: numberFields.has(key) }; }
    function dynamicColumns(rows, preferred) {
        const keys = [...new Set([...(preferred || []), ...rows.flatMap(row => Object.keys(row))])];
        return keys.map(key => columnSpec(key));
    }
    function expenseRows(data) {
        return Array.isArray(data.expenseLines) ? data.expenseLines.slice().sort((a, b) => (a.sortOrder || 0) - (b.sortOrder || 0)) : (data.expenses || []);
    }
    function warehouseSelection(filters, stream) {
        if (!filters) return '—';
        const mode = filters[stream + 'WarehouseMode'];
        const ids = filters[stream + 'WarehouseIds'];
        if (mode === 'ALL') return labels.allWarehouses;
        if (mode === 'NONE') return labels.notUsed;
        if (mode === 'INCLUDE') return labels.onlyWarehouses + ': ' + printable(ids);
        if (mode === 'EXCLUDE') return labels.exceptWarehouses + ': ' + printable(ids);
        return [mode, ids && ids.length ? printable(ids) : null].filter(Boolean).join(' · ') || '—';
    }
    function expenseColumns() {
        return [columnSpec('city'), columnSpec('label'), columnSpec('documentCount'), columnSpec('amount'), columnSpec('profitImpact'),
            columnSpec('expenseCodes', r => r.filters && r.filters.expenseCodes),
            columnSpec('operationTypes', r => r.filters && r.filters.operationTypes),
            columnSpec('operationRequired', r => r.filters && r.filters.operationRequired),
            columnSpec('purposeCodes', r => !r.filters ? null : r.filters.purposeCodes && r.filters.purposeCodes.length ? r.filters.purposeCodes : labels.anyPurpose),
            columnSpec('cashWarehouses', r => warehouseSelection(r.filters, 'cash')),
            columnSpec('bankWarehouses', r => warehouseSelection(r.filters, 'bank')),
            columnSpec('accountingTreatment'), columnSpec('source'), columnSpec('note', r => r.filters && r.filters.note), columnSpec('lineId'), columnSpec('category')];
    }
    function documentColumns(rows) {
        return dynamicColumns(rows, ['documentDate', 'documentNumber', 'paymentId', 'expenseCode', 'documentClass', 'purposeCode', 'stream', 'warehouseId',
            'sourceInfo', 'sourceAmount', 'sourceCurrency', 'appliedRate', 'reportAmount', 'city', 'category', 'profitImpact', 'kyivAllocation', 'odesaAllocation',
            'resolvedMonth', 'periodSource', 'periodNote', 'periodStatus', 'includedInProfit', 'accountingTreatment', 'reason', 'warnings', 'expenseLineId', 'expenseLineIds']);
    }
    function displayValue(col, row) {
        const value = col.get(row);
        if (col.money) return formatMoney(value, col.key === 'sourceAmount' ? row.sourceCurrency : col.key === 'amount' || col.key === 'unitPrice' ? row.currency : 'UAH');
        if (col.key === 'status' && ['AVAILABLE', 'UNAVAILABLE'].includes(value)) return value === 'AVAILABLE' ? labels.available : labels.unavailable;
        if (col.key === 'city') return cityLabel(value);
        if (col.key === 'accountingTreatment') return treatmentLabel(value);
        return printable(value);
    }
    function renderGrid(table, columns, rows, emptyMessage) {
        clear(table);
        const head = document.createElement('thead'), header = document.createElement('tr');
        columns.forEach(col => header.appendChild(textElement('th', '', col.title)));
        head.appendChild(header); table.appendChild(head);
        const body = document.createElement('tbody');
        rows.forEach(item => {
            const row = document.createElement('tr');
            columns.forEach(col => row.appendChild(tableCell(displayValue(col, item), col.money || col.number ? 'num' : '')));
            body.appendChild(row);
        });
        if (!rows.length) {
            const row = document.createElement('tr'), cell = tableCell(emptyMessage || labels.noData, 'lavr-profit-empty');
            cell.colSpan = columns.length; row.appendChild(cell); body.appendChild(row);
        }
        table.appendChild(body);
    }
    function appendGrid(container, columns, rows) {
        const wrap = textElement('div', 'lavr-profit-table-wrap', '');
        const table = textElement('table', 'widefat striped', '');
        renderGrid(table, columns, rows); wrap.appendChild(table); container.appendChild(wrap);
    }
    function inventoryRows(data) {
        return (data.inventory || []).flatMap(city => {
            const { warehouses, ...total } = city;
            return [{ ...total, warehouseName: labels.cityTotal }, ...(warehouses || []).map(row => ({ city: city.city, ...row }))];
        });
    }
    function diagnosticRows(data) {
        return (data.periodDiagnostics || []).map(item => {
            const { document: payment, ...diagnostic } = item;
            return { ...(payment || {}), documentReason: payment && payment.reason, ...diagnostic };
        });
    }
    function parameterRows(data) {
        const saved = state.savedMetadata && data === state.report ? Object.entries(state.savedMetadata).map(([key,value]) => ['saved.' + key,value]) : [];
        return [['month', data.month], ['calculatedAt', formatDateTime(data.calculatedAt)], ['ruleVersion', data.ruleVersion], ['complete', data.complete],
            ...Object.entries(data.inputs || {}), ...Object.entries(data.periodPolicy || {}), ...saved].map(([key, value]) => ({ parameter: fieldLabel(key), value: printable(value) }));
    }
    function renderSupplement(data) {
        safeRender(labels.appliedParameters, nodes.policy, () => {
        clear(nodes.policy);
        if (!data.periodPolicy) nodes.policy.appendChild(textElement('p', '', labels.legacyPeriod));
        appendGrid(nodes.policy, ['parameter', 'value'].map(key => columnSpec(key)), parameterRows(data));
        });
        safeRender(labels.inventoryTitle, nodes.inventory, () => {
        clear(nodes.inventory);
        nodes.inventory.appendChild(textElement('p', 'description', labels.inventoryHelp));
        const inventory = inventoryRows(data);
        appendGrid(nodes.inventory, dynamicColumns(inventory, ['city', 'warehouseId', 'warehouseName', 'openingAccountingValue', 'closingAccountingValue', 'accountingValueChange']), inventory);
        });
        safeRender(labels.periodDiagnostics, nodes.diagnostics, () => {
        clear(nodes.diagnostics);
        const rows = diagnosticRows(data);
        nodes.diagnostics.hidden = !rows.length && !data.periodDiagnosticsTruncated;
        if (!nodes.diagnostics.hidden) {
            nodes.diagnostics.appendChild(textElement('h2', '', labels.periodDiagnostics));
            nodes.diagnostics.appendChild(textElement('p', 'notice notice-warning inline lavr-profit-inline-notice', labels.diagnosticsHelp));
            if (data.periodDiagnosticsTruncated) nodes.diagnostics.appendChild(textElement('p', '', labels.diagnosticsTruncated));
            appendGrid(nodes.diagnostics, documentColumns(rows), rows);
        }
        });
    }

    function downloadBlob(blob, filename) {
        const url = URL.createObjectURL(blob), link = document.createElement('a');
        link.href = url; link.download = filename;
        document.body.appendChild(link); link.click(); link.remove();
        setTimeout(() => URL.revokeObjectURL(url), 1000);
    }
    function workbookRows(columns, rows) {
        return [columns.map(col => col.title), ...rows.map(row => columns.map(col => {
            const value = col.get(row);
            if ((col.money || col.number) && value != null && value !== '') return { type: 'number', value, money: col.money };
            return printable(value);
        }))];
    }
    function buildWorkbookSheets(data) {
        const sheets = [];
        const add = (name, columns, rows, note) => {
            sheets.push({ name, rows: [[labels.reportMonth, data.month, labels.snapshot, formatDateTime(data.calculatedAt)],
                [note || labels.exportSnapshot], ...workbookRows(columns, rows)], headerRows: [1, 3], freezeRows: 3, mergeRows: [2],
                widths: columns.map(col => ['label', 'reason', 'note', 'message', 'value', 'parameter'].includes(col.key) ? 46 : col.money ? 20 : 26) });
        };
        ['KYIV', 'ODESA'].forEach(city => {
            const rows = expenseRows(data).filter(row => matchesCity(row.city, city));
            add(cityLabel(city), expenseColumns(), rows, sectionUnavailable(data, 'EXPENSES') ? labels.unavailable : Array.isArray(data.expenseLines) ? labels.detailedRows : labels.legacyRows);
        });
        add(labels.profitByCity, dynamicColumns(data.cities || [], ['city', 'baseGrossProfit', 'manualGrossAdjustments', 'grossProfit', 'operatingExpenses', 'profit']), data.cities || []);
        add(labels.appliedParameters, ['parameter', 'value'].map(key => columnSpec(key)), parameterRows(data));
        const controls = data.controls ? [data.controls] : [];
        add(labels.controlTotals, dynamicColumns(controls), controls, data.controls ? labels.controlsHelp : labels.unavailable + '. ' + labels.partialHelp);
        add(labels.warningsTitle, dynamicColumns(data.warnings || [], ['code', 'message', 'details']), data.warnings || [], data.complete ? labels.complete : labels.incomplete);
        const inventory = inventoryRows(data);
        add(labels.inventoryTitle, dynamicColumns(inventory), inventory, labels.inventoryHelp);
        if (data.sections) add(labels.sectionStatus, dynamicColumns(sectionRows(data), ['section', 'status', 'message', 'errorCode', 'errorId']), sectionRows(data), labels.partialHelp);
        const documents = data.documents || [];
        add(labels.auditTitle, documentColumns(documents), documents, sectionUnavailable(data, 'EXPENSES') ? labels.unavailable + '. ' + labels.partialHelp : data.controls && data.controls.auditTruncated ? labels.auditTruncated : labels.auditCoverage);
        const diagnostics = diagnosticRows(data);
        add(labels.periodDiagnostics, documentColumns(diagnostics), diagnostics, labels.diagnosticsHelp + (data.periodDiagnosticsTruncated ? ' ' + labels.diagnosticsTruncated : ''));
        add(labels.masterTitle, dynamicColumns(data.masterClass ? [data.masterClass] : []), data.masterClass ? [data.masterClass] : [], data.masterClass ? labels.exportSnapshot : labels.masterUnavailable);
        add(labels.masterAuditTitle, dynamicColumns(data.masterClassDocuments || [], ['documentDate', 'documentNumber', 'documentId', 'lineNumber', 'movementId', 'sku', 'amount']), data.masterClassDocuments || [], data.masterClass && data.masterClass.auditTruncated ? labels.masterTruncated : labels.exportSnapshot);
        // Preserve unallocated lines and additional fields without inventing city allocations.
        add(labels.allExpenseRows, dynamicColumns(expenseRows(data)), expenseRows(data));
        return sheets;
    }
    async function exportWorkbook() {
        if (state.exporting || state.loading || state.auditLoading || state.dirty || !state.report) return;
        state.exporting = true;
        updateAvailability();
        try {
            if (!state.audit && !(await loadReport('audit'))) return;
            if (state.dirty || !state.audit) return;
            const data = state.audit;
            if (!Array.isArray(data.documents) && !(data.sections && data.sections.EXPENSES && data.sections.EXPENSES.status === 'UNAVAILABLE')) throw new Error(labels.invalidResponse);
            const exportData = { ...data, complete: data.complete && !hasUnavailableSections(data), warnings: [...(data.warnings || []), ...state.displayWarnings] };
            const sheets = buildWorkbookSheets(exportData);
            if (state.savedMetadata) sheets.push({name:'Saved revision',rows:Object.entries(state.savedMetadata).map(([k,v])=>[k,printable(v)]),widths:[30,65]});
            const bytes = LavkaProfitXlsx.build(sheets);
            downloadBlob(new Blob([bytes], { type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' }), 'folio-profit-' + data.month + (state.savedMetadata ? '-r' + state.savedMetadata.revisionId : '') + '.xlsx');
            showRunState(labels.exportReady, 'success');
        } catch (error) {
            showError(labels.exportFailed, null);
        } finally {
            state.exporting = false;
            updateAvailability();
        }
    }

    nodes.kyivSalary.addEventListener('input', () => { state.kyivSalaryOverride = nodes.kyivSalary.value.trim() !== ''; });
    nodes.additionalSalary.addEventListener('input', () => { state.salaryOverride = nodes.additionalSalary.value.trim() !== ''; });
    nodes.month.addEventListener('change', () => {
        state.salaryOverride = false;
        state.kyivSalaryOverride = false;
        nodes.kyivSalary.value = "";
        nodes.kyivSalarySource.textContent = "";
        nodes.additionalSalary.value = '';
        nodes.salarySource.textContent = '';
    });

    nodes.loadAudit.dataset.defaultLabel = nodes.loadAudit.textContent.trim();
    nodes.exportXlsx.addEventListener('click', exportWorkbook);
    [nodes.month, nodes.additionalSalary, nodes.kyivSalary, nodes.taxShare, nodes.rubRate].forEach(field => {
        field.addEventListener('input', invalidateResult);
        field.addEventListener('change', invalidateResult);
    });
    nodes.month.value = previousMonth();
    nodes.calculate.addEventListener('click', () => loadReport('summary'));
    nodes.recalculate.addEventListener('click', () => loadReport('summary'));
    nodes.loadAudit.addEventListener('click', () => loadReport('audit'));
    nodes.exportAudit.addEventListener('click', exportAudit);
    nodes.taxShare.addEventListener('input', updateTaxShareHelp);
    [nodes.auditCity, nodes.auditCategory, nodes.auditTreatment].forEach((select) => select.addEventListener('change', renderAuditRows));
    nodes.auditUnclassified.addEventListener('change', renderAuditRows);

    if (config.savedMode) {
        window.LavkaProfitViewer = {
            params: requestParams,
            version: () => state.revision,
            showSaved(wrapper) {
                const data = wrapper.report;
                if (!data || !Array.isArray(data.cities) || data.month !== wrapper.month) throw new Error(labels.invalidResponse);
                state.revision++; state.dirty = false;
                state.report = data; state.audit = data; state.reportParams = {month: data.month};
                state.savedMetadata = {revisionId: wrapper.revisionId, requestId: wrapper.requestId, status: wrapper.status, auditComplete: wrapper.auditComplete, published: wrapper.published};
                nodes.month.value = data.month;
                state.salaryOverride = false; state.kyivSalaryOverride = false;
                [nodes.taxShare,nodes.rubRate,nodes.additionalSalary,nodes.kyivSalary].forEach(n => { n.value = ''; });
                hideError(); renderReport(); renderAudit();
                state.salaryOverride = data.inputs && data.inputs.odesaAdditionalSalarySource === 'REQUEST_OVERRIDE';
                state.kyivSalaryOverride = data.inputs && data.inputs.kyivAdditionalSalarySource === 'REQUEST_OVERRIDE';
                showRunState(labels.reportMonth + ': ' + data.month + ' · ' + wrapper.status + ' · ' + wrapper.revisionId, 'success');
                updateAvailability();
            },
            clear() { invalidateResult(); nodes.result.hidden = true; },
            sheets(wrapper) {
                const sheets = buildWorkbookSheets(wrapper.report);
                sheets.push({name:'Saved revision',rows:Object.entries({month:wrapper.month,revisionId:wrapper.revisionId,requestId:wrapper.requestId,status:wrapper.status,auditComplete:wrapper.auditComplete,published:wrapper.published,createdAt:wrapper.createdAt,completedAt:wrapper.completedAt}).map(([k,v])=>[k,printable(v)]),widths:[30,65]});
                return sheets;
            }
        };
        updateAvailability();
    } else loadReport('summary');
})();
