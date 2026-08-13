(function () {
    'use strict';

    var root = document.querySelector('.pc-folio-balance');
    if (!root || typeof pcFolioBalance === 'undefined') return;

    var form = root.querySelector('[data-pc-folio-form]');
    var dateInput = form.querySelector('[name="date_from"]');
    var allButton = root.querySelector('[data-pc-folio-all]');
    var printButton = root.querySelector('[data-pc-folio-print]');
    var statusBox = root.querySelector('[data-pc-folio-status]');
    var summaryBox = root.querySelector('[data-pc-folio-summary]');
    var noticeBox = root.querySelector('[data-pc-folio-notice]');
    var tableWrap = root.querySelector('[data-pc-folio-table-wrap]');
    var rowsBox = root.querySelector('[data-pc-folio-rows]');
    var submitButton = form.querySelector('[type="submit"]');
    var controller = null;
    var money = new Intl.NumberFormat('uk-UA', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    var dateFormatter = new Intl.DateTimeFormat('uk-UA');

    var summaryFields = [
        'openingBalance',
        'expenseTotal',
        'receiptTotal',
        'bankPaymentTotal',
        'cashPaymentTotal',
        'commonDebt',
        'deferredAmount',
        'overdueDeferredAmount',
        'prepaymentAmount',
        'payableNow'
    ];

    function text(value) {
        return value == null ? '' : String(value);
    }

    function number(value) {
        var parsed = Number(value);
        return Number.isFinite(parsed) ? parsed : 0;
    }

    function moneyText(value, blankZero) {
        var amount = number(value);
        return blankZero && amount === 0 ? '' : money.format(amount);
    }

    function dateText(value) {
        if (!value) return '';
        var parts = String(value).substring(0, 10).split('-');
        if (parts.length !== 3) return text(value);
        var parsed = new Date(Number(parts[0]), Number(parts[1]) - 1, Number(parts[2]));
        return Number.isNaN(parsed.getTime()) ? text(value) : dateFormatter.format(parsed);
    }

    function setStatus(message, kind) {
        statusBox.textContent = message || '';
        statusBox.className = 'pc-folio-balance__status' + (kind ? ' is-' + kind : '');
    }

    function cell(row, key, type, blankZero) {
        var td = document.createElement('td');
        var value = row[key];
        td.textContent = type === 'money' ? moneyText(value, blankZero) : (type === 'date' ? dateText(value) : text(value));
        return td;
    }

    function renderSummary(summary) {
        summaryBox.replaceChildren();
        summaryFields.forEach(function (key) {
            var item = document.createElement('div');
            item.className = 'pc-folio-balance__metric pc-folio-balance__metric--' + key;
            var label = document.createElement('span');
            label.textContent = pcFolioBalance.labels.summary[key] || key;
            var value = document.createElement('strong');
            value.textContent = moneyText(summary[key], false) + ' ' + pcFolioBalance.labels.currency;
            item.append(label, value);
            summaryBox.appendChild(item);
        });
        summaryBox.hidden = false;
    }

    function renderRows(rows) {
        rowsBox.replaceChildren();
        rows.forEach(function (row) {
            var tr = document.createElement('tr');
            if (row.openingBalanceRow) tr.classList.add('is-opening');
            if (row.prepayment) tr.classList.add('is-prepayment');
            if (row.deferred) tr.classList.add('is-deferred');
            if (row.overdueDeferred) tr.classList.add('is-overdue');

            var technical = [];
            if (row.warehouseName || row.warehouseId) {
                technical.push(pcFolioBalance.labels.warehouse + ': ' + (row.warehouseName || row.warehouseId));
            }
            if (row.documentId) technical.push(pcFolioBalance.labels.documentId + ': ' + row.documentId);
            if (technical.length) tr.title = technical.join('\n');

            tr.append(
                cell(row, 'controlDate', 'date'),
                cell(row, 'sequence'),
                cell(row, 'documentType'),
                cell(row, 'documentNumber'),
                cell(row, 'documentDate', 'date'),
                cell(row, 'basis'),
                cell(row, 'balanceBefore', 'money'),
                cell(row, 'expenseAmount', 'money', true),
                cell(row, 'receiptAmount', 'money', true),
                cell(row, 'bankPayment', 'money', true),
                cell(row, 'cashPayment', 'money', true),
                cell(row, 'balanceAfter', 'money'),
                cell(row, 'note'),
                cell(row, 'invoiceDate', 'date')
            );
            rowsBox.appendChild(tr);
        });
        tableWrap.hidden = rows.length === 0;
    }

    function render(report) {
        renderSummary(report.summary || {});
        renderRows(Array.isArray(report.rows) ? report.rows : []);
        var asOfDate = report.filters && (report.filters.asOfDate || report.filters.dateTo);
        noticeBox.textContent = asOfDate
            ? pcFolioBalance.labels.asOf.replace('%s', dateText(asOfDate))
            : '';
        noticeBox.hidden = !noticeBox.textContent;
        printButton.disabled = false;
        setStatus((report.rows || []).length ? '' : pcFolioBalance.labels.empty, 'info');
    }

    function loadReport() {
        if (controller) controller.abort();
        controller = new AbortController();
        submitButton.disabled = true;
        allButton.disabled = true;
        printButton.disabled = true;
        setStatus(pcFolioBalance.labels.loading, 'loading');

        var body = new URLSearchParams();
        body.set('action', 'pc_folio_customer_balance');
        body.set('_ajax_nonce', pcFolioBalance.nonce);
        if (dateInput.value) body.set('date_from', dateInput.value);

        fetch(pcFolioBalance.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            body: body.toString(),
            signal: controller.signal
        })
            .then(function (response) {
                return response.json().catch(function () { return {}; }).then(function (payload) {
                    if (!response.ok || !payload.success) {
                        var data = payload && payload.data ? payload.data : {};
                        var message = data.message || pcFolioBalance.labels.requestFailed;
                        if (data.reqId) message += ' ' + pcFolioBalance.labels.requestId.replace('%s', data.reqId);
                        throw new Error(message);
                    }
                    return payload.data.report;
                });
            })
            .then(render)
            .catch(function (error) {
                if (error.name !== 'AbortError') setStatus(error.message || pcFolioBalance.labels.requestFailed, 'error');
            })
            .finally(function () {
                submitButton.disabled = false;
                allButton.disabled = false;
            });
    }

    form.addEventListener('submit', function (event) {
        event.preventDefault();
        loadReport();
    });
    allButton.addEventListener('click', function () {
        dateInput.value = '';
        loadReport();
    });
    printButton.addEventListener('click', function () { window.print(); });

    loadReport();
}());
