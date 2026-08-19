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
    };

    const nodes = {
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
        masterIncome: document.getElementById('lavr-profit-master-income'),
        masterReturn: document.getElementById('lavr-profit-master-return'),
        additionalSalary: document.getElementById('lavr-profit-additional-salary'),
    };

    const treatmentLabels = {
        OPERATING_EXPENSE: labels.operatingTreatment,
        CAPITALIZED_IN_INVENTORY: labels.capitalizedTreatment,
        EXCLUDED: labels.excludedTreatment,
        UNCLASSIFIED: labels.unclassifiedTreatment,
    };
    const warningGroups = {
        UNCLASSIFIED_DOCUMENTS: 'action',
        UNKNOWN_TAX_POOL: 'action',
        AMBIGUOUS_EXPLICIT_PERIOD: 'action',
        MASTER_CLASS_MANUAL_INPUT_REQUIRED: 'manual',
        ODESA_ADDITIONAL_WORKS_UNCONFIRMED: 'manual',
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
        const date = new Date(value);
        if (Number.isNaN(date.getTime())) return String(value);
        return new Intl.DateTimeFormat('uk-UA', {
            day: '2-digit', month: '2-digit', year: 'numeric',
            hour: '2-digit', minute: '2-digit', second: '2-digit',
        }).format(date);
    }

    function cityLabel(city) {
        const value = String(city || '').toUpperCase();
        if (value === 'KYIV' || value === 'KIEV') return labels.kyiv;
        if (value === 'ODESA' || value === 'ODESSA') return labels.odesa;
        if (!value || value === 'UNALLOCATED') return labels.unallocated;
        return city;
    }

    function treatmentLabel(treatment) {
        return treatmentLabels[treatment] || treatment || labels.unclassifiedTreatment;
    }

    function requestParams() {
        const params = { month: nodes.month.value };
        const fields = [nodes.masterIncome, nodes.masterReturn, nodes.additionalSalary, nodes.rubRate];
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
            nodes.loadAudit.disabled = busy;
            nodes.calculate.disabled = busy;
            nodes.recalculate.disabled = busy;
            nodes.loadAudit.textContent = busy ? labels.loadingAudit : nodes.loadAudit.dataset.defaultLabel;
            root.setAttribute('aria-busy', busy ? 'true' : 'false');
            if (busy) showRunState(labels.loadingAudit, 'loading');
            return;
        }
        state.loading = busy;
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
            throw { status: httpStatus, message, field };
        }
        return body;
    }

    async function loadReport(operation) {
        if (!nodes.month.value) {
            showError(labels.monthRequired, null, 'month');
            return;
        }
        hideError();
        state.lastOperation = operation;
        setBusy(operation, true);
        const params = operation === 'audit' && state.reportParams
            ? state.reportParams
            : requestParams();
        try {
            const data = await proxyRequest(operation, params);
            if (operation === 'audit') {
                state.audit = data;
                renderAudit();
                showRunState(labels.auditReady, 'success');
            } else {
                state.report = data;
                state.reportParams = Object.assign({}, params);
                state.audit = null;
                renderReport();
                showRunState(labels.reportReady, 'success');
            }
        } catch (error) {
            const retry = Number(error.status || 0) >= 500 || !error.status ? operation : null;
            showRunState(labels.requestFailed, 'error');
            showError(error.message || labels.generalError, retry, error.field || '');
        } finally {
            setBusy(operation, false);
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
        [
            [nodes.masterIncome, inputs.odesaMasterClassIncome],
            [nodes.masterReturn, inputs.odesaMasterClassReturn],
            [nodes.additionalSalary, inputs.odesaAdditionalSalary],
        ].forEach(([field, value]) => {
            if (field.value === '' && compareDecimalToZero(value) !== 0) field.value = String(value);
        });
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
        if (emphasized) {
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
        const group = warningGroups[code] || 'info';
        return group === 'action' ? 0 : group === 'manual' ? 1 : 2;
    }

    function renderWarnings(warnings) {
        clear(nodes.warnings);
        const items = (warnings || []).slice().sort((a, b) => warningPriority(a.code) - warningPriority(b.code));
        nodes.warningsSection.hidden = items.length === 0;
        items.forEach((warning) => {
            const group = warningGroups[warning.code] || 'info';
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
            renderExpenses();
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
        return !value || city === 'UNALLOCATED';
    }

    function tableCell(text, className) {
        const cell = textElement('td', className || '', text);
        return cell;
    }

    function renderExpenses() {
        clear(nodes.expensesBody);
        const expenses = ((state.report && state.report.expenses) || []).filter((item) => matchesCity(item.city, state.expenseFilter));
        if (!expenses.length) {
            const row = document.createElement('tr');
            const cell = tableCell(labels.noExpenses, 'lavr-profit-empty');
            cell.colSpan = 6;
            row.appendChild(cell);
            nodes.expensesBody.appendChild(row);
            return;
        }
        expenses.forEach((expense) => {
            const row = document.createElement('tr');
            row.appendChild(tableCell(cityLabel(expense.city)));
            const labelCell = tableCell(expense.label || expense.category || '');
            if (expense.category) labelCell.appendChild(textElement('code', 'lavr-profit-category', expense.category));
            row.appendChild(labelCell);
            row.appendChild(tableCell(expense.documentCount == null ? '0' : expense.documentCount, 'num'));
            row.appendChild(tableCell(formatMoney(expense.amount, 'UAH'), 'num'));
            row.appendChild(tableCell(formatMoney(expense.profitImpact, 'UAH'), 'num'));
            const treatment = tableCell(treatmentLabel(expense.accountingTreatment));
            treatment.appendChild(textElement('code', 'lavr-profit-treatment-code', expense.accountingTreatment || ''));
            if (expense.accountingTreatment === 'CAPITALIZED_IN_INVENTORY') {
                treatment.title = labels.capitalizedHelp;
            }
            row.appendChild(treatment);
            nodes.expensesBody.appendChild(row);
        });
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
        grid.appendChild(controlItem(labels.selectedDocuments, String(controls.selectedDocumentCount || 0)));
        grid.appendChild(controlItem(labels.selectedAmount, formatMoney(controls.selectedDocumentAmount, 'UAH')));
        grid.appendChild(controlItem(labels.operatingTotal, formatMoney(controls.operatingExpenseTotal, 'UAH')));
        grid.appendChild(controlItem(labels.capitalizedTotal, formatMoney(controls.capitalizedCostTotal, 'UAH')));
        grid.appendChild(controlItem(labels.excludedTotal, formatMoney(controls.excludedDocumentAmount, 'UAH')));
        grid.appendChild(controlItem(
            labels.unclassifiedTotal,
            formatMoney(controls.unclassifiedDocumentAmount, 'UAH'),
            String(controls.unclassifiedDocumentCount || 0) + ' ' + labels.documentsLower
        ));
        nodes.controlsContent.appendChild(grid);

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
        applyInitialInputs(data.inputs || {});
        renderCities(data.cities || []);
        renderWarnings(data.warnings || []);
        renderExpenseFilters();
        renderExpenses();
        renderControls(data.controls || {});
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
        const rows = ((state.audit && state.audit.documents) || []).slice();
        return rows.filter((row) => {
            if (nodes.auditCity.value && row.city !== nodes.auditCity.value) return false;
            if (nodes.auditCategory.value && row.category !== nodes.auditCategory.value) return false;
            if (nodes.auditTreatment.value && row.accountingTreatment !== nodes.auditTreatment.value) return false;
            if (nodes.auditUnclassified.checked && row.accountingTreatment !== 'UNCLASSIFIED') return false;
            return true;
        });
    }

    function renderAuditRows() {
        clear(nodes.auditBody);
        const rows = filteredAuditRows();
        if (!rows.length) {
            const row = document.createElement('tr');
            const cell = tableCell(labels.noAudit, 'lavr-profit-empty');
            cell.colSpan = 10;
            row.appendChild(cell);
            nodes.auditBody.appendChild(row);
            return;
        }
        rows.forEach((documentLine) => {
            const row = document.createElement('tr');
            const documentCell = tableCell(formatDate(documentLine.documentDate));
            documentCell.appendChild(textElement('strong', 'lavr-profit-document-number', documentLine.documentNumber || ''));
            row.appendChild(documentCell);
            row.appendChild(tableCell(documentLine.stream === 'BANK' ? labels.bank : documentLine.stream === 'CASH' ? labels.cash : documentLine.stream || ''));
            row.appendChild(tableCell(documentLine.warehouseId == null ? '' : documentLine.warehouseId));
            const codes = [documentLine.purposeCode, documentLine.expenseCode, documentLine.name, documentLine.documentClass].filter(Boolean);
            row.appendChild(tableCell(codes.join(' · ')));
            row.appendChild(tableCell(formatMoney(documentLine.sourceAmount, documentLine.sourceCurrency), 'num'));
            row.appendChild(tableCell(formatMoney(documentLine.reportAmount, 'UAH'), 'num'));
            row.appendChild(tableCell([cityLabel(documentLine.city), documentLine.category].filter(Boolean).join(' · ')));
            row.appendChild(tableCell(treatmentLabel(documentLine.accountingTreatment)));
            row.appendChild(tableCell(documentLine.includedInProfit ? labels.yes : labels.no));
            row.appendChild(tableCell(documentLine.reason || ''));
            nodes.auditBody.appendChild(row);
        });
    }

    function renderAudit() {
        const rows = (state.audit && state.audit.documents) || [];
        nodes.auditContent.hidden = false;
        selectOptions(nodes.auditCity, uniqueValues(rows, 'city'), labels.all, cityLabel);
        selectOptions(nodes.auditCategory, uniqueValues(rows, 'category'), labels.all);
        selectOptions(nodes.auditTreatment, uniqueValues(rows, 'accountingTreatment'), labels.all, treatmentLabel);
        const truncated = !!(state.audit && state.audit.controls && state.audit.controls.auditTruncated);
        nodes.auditNote.hidden = !truncated;
        nodes.auditNote.textContent = truncated ? labels.auditTruncated + ' ' + labels.exportLoaded : '';
        renderAuditRows();
    }

    function csvValue(value) {
        const string = String(value == null ? '' : value);
        return '"' + string.replace(/"/g, '""') + '"';
    }

    function exportAudit() {
        const rows = filteredAuditRows();
        const headings = [
            labels.csvDate, labels.csvDocument, labels.csvFlow, labels.csvWarehouse,
            labels.csvPurpose, labels.csvExpenseCode, labels.csvName, labels.csvClass,
            labels.csvSourceAmount, labels.csvSourceCurrency, labels.csvReportAmount,
            labels.csvCity, labels.csvCategory, labels.csvTreatment, labels.csvIncluded, labels.csvReason,
        ];
        const lines = [headings.map(csvValue).join(';')];
        rows.forEach((row) => {
            lines.push([
                row.documentDate, row.documentNumber, row.stream, row.warehouseId,
                row.purposeCode, row.expenseCode, row.name, row.documentClass,
                row.sourceAmount, row.sourceCurrency, row.reportAmount,
                row.city, row.category, row.accountingTreatment,
                row.includedInProfit ? labels.yes : labels.no, row.reason,
            ].map(csvValue).join(';'));
        });
        const blob = new Blob(['\uFEFF' + lines.join('\r\n')], { type: 'text/csv;charset=utf-8' });
        const url = URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = url;
        link.download = 'folio-profit-audit-' + nodes.month.value + '.csv';
        document.body.appendChild(link);
        link.click();
        link.remove();
        URL.revokeObjectURL(url);
    }

    nodes.loadAudit.dataset.defaultLabel = nodes.loadAudit.textContent.trim();
    nodes.month.value = previousMonth();
    nodes.calculate.addEventListener('click', () => loadReport('summary'));
    nodes.recalculate.addEventListener('click', () => loadReport('summary'));
    nodes.loadAudit.addEventListener('click', () => loadReport('audit'));
    nodes.exportAudit.addEventListener('click', exportAudit);
    nodes.taxShare.addEventListener('input', updateTaxShareHelp);
    [nodes.auditCity, nodes.auditCategory, nodes.auditTreatment].forEach((select) => select.addEventListener('change', renderAuditRows));
    nodes.auditUnclassified.addEventListener('change', renderAuditRows);

    loadReport('summary');
})();
