(function () {
    'use strict';

    var root = document.querySelector('[data-pc-folio-debtors]');
    if (!root || typeof pcFolioDebtors === 'undefined') return;

    var form = root.querySelector('[data-pc-debtors-form]');
    var statusBox = root.querySelector('[data-pc-debtors-status]');
    var metaBox = root.querySelector('[data-pc-debtors-meta]');
    var summaryBox = root.querySelector('[data-pc-debtors-summary]');
    var tableWrap = root.querySelector('[data-pc-debtors-table-wrap]');
    var rowsBox = root.querySelector('[data-pc-debtors-rows]');
    var pagination = root.querySelector('[data-pc-debtors-pagination]');
    var pageLabel = root.querySelector('[data-pc-debtors-page]');
    var prevButton = root.querySelector('[data-pc-debtors-prev]');
    var nextButton = root.querySelector('[data-pc-debtors-next]');
    var submitButton = form.querySelector('[type="submit"]');
    var offset = 0;
    var controller = null;
    var money = new Intl.NumberFormat('uk-UA', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    var dateFormatter = new Intl.DateTimeFormat('uk-UA');

    function number(value) {
        var parsed = Number(value);
        return Number.isFinite(parsed) ? parsed : 0;
    }

    function moneyText(value) {
        return money.format(number(value)) + ' ' + pcFolioDebtors.labels.currency;
    }

    function dateText(value) {
        if (!value) return '';
        var parts = Array.isArray(value) ? value : String(value).substring(0, 10).split('-');
        if (parts.length < 3) return String(value);
        var parsed = new Date(Number(parts[0]), Number(parts[1]) - 1, Number(parts[2]));
        return Number.isNaN(parsed.getTime()) ? String(value) : dateFormatter.format(parsed);
    }

    function setStatus(message, kind) {
        statusBox.textContent = message || '';
        statusBox.className = 'pc-folio-debtors__status' + (kind ? ' is-' + kind : '');
    }

    function appendTextCell(row, value, className) {
        var cell = document.createElement('td');
        cell.textContent = value == null ? '' : String(value);
        if (className) cell.className = className;
        row.appendChild(cell);
        return cell;
    }

    function actionLink(url, label) {
        var link = document.createElement('a');
        link.className = 'button button-small';
        link.href = url;
        link.target = '_blank';
        link.rel = 'noopener';
        link.textContent = label;
        return link;
    }

    function renderSiteUsers(cell, users) {
        users = Array.isArray(users) ? users : [];
        if (!users.length) {
            cell.textContent = pcFolioDebtors.labels.notLinked;
            cell.classList.add('is-unlinked');
            return;
        }

        if (users.length > 1) {
            var warning = document.createElement('strong');
            warning.className = 'pc-folio-debtors__mapping-warning';
            warning.textContent = pcFolioDebtors.labels.multipleLinks;
            cell.appendChild(warning);
        }

        users.forEach(function (user) {
            var item = document.createElement('div');
            item.className = 'pc-folio-debtors__site-user';
            var name = document.createElement('span');
            name.textContent = user.displayName || ('#' + user.id);
            item.appendChild(name);
            if (user.profileUrl) item.appendChild(actionLink(user.profileUrl, pcFolioDebtors.labels.profile));
            if (user.balanceUrl) item.appendChild(actionLink(user.balanceUrl, pcFolioDebtors.labels.balance));
            cell.appendChild(item);
        });
    }

    function renderRows(debtors) {
        rowsBox.replaceChildren();
        debtors.forEach(function (debtor) {
            var partner = debtor.partner || {};
            var row = document.createElement('tr');
            var partnerCell = document.createElement('td');
            var partnerName = document.createElement('strong');
            var partnerShortName = document.createElement('code');
            partnerName.textContent = partner.name || partner.shortName || '';
            partnerShortName.textContent = partner.shortName || '';
            partnerCell.append(partnerName, partnerShortName);
            row.appendChild(partnerCell);

            appendTextCell(row, pcFolioDebtors.labels.types[partner.type] || partner.type || '');
            appendTextCell(row, moneyText(debtor.commonDebt), 'is-money');
            appendTextCell(row, moneyText(debtor.deferredAmount), 'is-money');
            appendTextCell(row, moneyText(debtor.overdueDeferredAmount), 'is-money is-overdue');
            appendTextCell(row, moneyText(debtor.prepaymentAmount), 'is-money');
            appendTextCell(row, moneyText(debtor.payableNow), 'is-money is-payable');
            var siteCell = appendTextCell(row, '');
            renderSiteUsers(siteCell, debtor.siteUsers);
            rowsBox.appendChild(row);
        });
        tableWrap.hidden = debtors.length === 0;
    }

    function renderSummary(summary) {
        var fields = [
            'matchedClients',
            'commonDebtTotal',
            'deferredAmountTotal',
            'overdueDeferredAmountTotal',
            'prepaymentAmountTotal',
            'payableNowTotal'
        ];
        summaryBox.replaceChildren();
        fields.forEach(function (key) {
            var item = document.createElement('div');
            item.className = 'pc-folio-debtors__metric pc-folio-debtors__metric--' + key;
            var label = document.createElement('span');
            var value = document.createElement('strong');
            label.textContent = pcFolioDebtors.labels.summary[key] || key;
            value.textContent = key === 'matchedClients' ? String(number(summary[key])) : moneyText(summary[key]);
            item.append(label, value);
            summaryBox.appendChild(item);
        });
        summaryBox.hidden = false;
    }

    function renderMeta(report) {
        var filters = report.filters || {};
        var summary = report.summary || {};
        metaBox.replaceChildren();
        [
            pcFolioDebtors.labels.asOf.replace('%s', dateText(report.asOfDate)),
            pcFolioDebtors.labels.threshold.replace('%s', money.format(number(filters.minPayable))),
            pcFolioDebtors.labels.found.replace('%s', String(number(summary.matchedClients)))
        ].forEach(function (text) {
            var item = document.createElement('span');
            item.textContent = text;
            metaBox.appendChild(item);
        });
        metaBox.hidden = false;
    }

    function renderPagination(summary, limit) {
        var matched = number(summary.matchedClients);
        var pages = Math.max(1, Math.ceil(matched / limit));
        var page = Math.floor(offset / limit) + 1;
        prevButton.disabled = offset <= 0;
        nextButton.disabled = offset + limit >= matched;
        pageLabel.textContent = pcFolioDebtors.labels.page
            .replace('%1$s', String(page))
            .replace('%2$s', String(pages));
        pagination.hidden = matched === 0;
    }

    function render(report) {
        var debtors = Array.isArray(report.debtors) ? report.debtors : [];
        var summary = report.summary || {};
        var limit = number((report.filters || {}).limit) || number(form.elements.limit.value);
        renderMeta(report);
        renderSummary(summary);
        renderRows(debtors);
        renderPagination(summary, limit);
        setStatus(debtors.length ? '' : pcFolioDebtors.labels.empty, debtors.length ? '' : 'info');
    }

    function loadReport() {
        if (controller) controller.abort();
        controller = new AbortController();
        submitButton.disabled = true;
        prevButton.disabled = true;
        nextButton.disabled = true;
        setStatus(pcFolioDebtors.labels.loading, 'loading');

        var body = new URLSearchParams();
        body.set('action', 'pc_folio_customer_debtors');
        body.set('_ajax_nonce', pcFolioDebtors.nonce);
        body.set('min_payable', form.elements.min_payable.value);
        body.set('q', form.elements.q.value);
        body.set('types', form.elements.types.value);
        body.set('limit', form.elements.limit.value);
        body.set('offset', String(offset));

        fetch(pcFolioDebtors.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            body: body.toString(),
            signal: controller.signal
        })
            .then(function (response) {
                return response.json().catch(function () { return {}; }).then(function (payload) {
                    if (!response.ok || !payload.success) {
                        var message = payload.data && payload.data.message ? payload.data.message : pcFolioDebtors.labels.requestFailed;
                        if (payload.data && payload.data.reqId) {
                            message += ' ' + pcFolioDebtors.labels.requestId.replace('%s', payload.data.reqId);
                        }
                        throw new Error(message);
                    }
                    return payload.data.report;
                });
            })
            .then(render)
            .catch(function (error) {
                if (error.name !== 'AbortError') setStatus(error.message || pcFolioDebtors.labels.requestFailed, 'error');
            })
            .finally(function () {
                submitButton.disabled = false;
            });
    }

    form.addEventListener('submit', function (event) {
        event.preventDefault();
        offset = 0;
        loadReport();
    });
    prevButton.addEventListener('click', function () {
        offset = Math.max(0, offset - number(form.elements.limit.value));
        loadReport();
    });
    nextButton.addEventListener('click', function () {
        offset += number(form.elements.limit.value);
        loadReport();
    });
}());
