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
    var snapshotRoot = root.querySelector('[data-pc-debtors-snapshot]');
    var snapshotState = root.querySelector('[data-pc-snapshot-state]');
    var snapshotDate = root.querySelector('[data-pc-snapshot-date]');
    var snapshotCompleted = root.querySelector('[data-pc-snapshot-completed]');
    var snapshotTotal = root.querySelector('[data-pc-snapshot-total]');
    var snapshotMessage = root.querySelector('[data-pc-snapshot-message]');
    var refreshButton = root.querySelector('[data-pc-snapshot-refresh]');
    var offset = 0;
    var reportController = null;
    var snapshotController = null;
    var snapshotPollTimer = null;
    var currentSnapshot = null;
    var loadAfterReady = false;
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

    function dateTimeText(value) {
        if (!value) return '';
        var parsed = new Date(String(value));
        return Number.isNaN(parsed.getTime()) ? String(value) : parsed.toLocaleString('uk-UA');
    }

    function setStatus(message, kind) {
        statusBox.textContent = message || '';
        statusBox.className = 'pc-folio-debtors__status' + (kind ? ' is-' + kind : '');
    }

    function setSnapshotMessage(message, kind) {
        snapshotMessage.textContent = message || '';
        snapshotMessage.className = 'pc-folio-debtors__snapshot-message' + (kind ? ' is-' + kind : '');
    }

    function snapshotLabel(status) {
        var labels = pcFolioDebtors.labels.snapshot;
        return {
            ACTIVE: labels.active,
            BUILDING: labels.building,
            NOT_READY: labels.notReady,
            FAILED: labels.failed,
            SUPERSEDED: labels.superseded
        }[status] || labels.unknown;
    }

    function snapshotIsReady(snapshot) {
        return snapshot
            && snapshot.status === 'ACTIVE'
            && snapshot.running !== true
            && number(snapshot.totalClients) > 0;
    }

    function setReportAvailability(enabled) {
        submitButton.disabled = !enabled;
        if (!enabled) {
            prevButton.disabled = true;
            nextButton.disabled = true;
        }
    }

    function stopSnapshotPolling() {
        if (snapshotPollTimer) window.clearTimeout(snapshotPollTimer);
        snapshotPollTimer = null;
    }

    function scheduleSnapshotCheck() {
        stopSnapshotPolling();
        snapshotPollTimer = window.setTimeout(function () {
            checkSnapshot(true);
        }, number(pcFolioDebtors.pollInterval) || 5000);
    }

    function renderSnapshot(snapshot) {
        var labels = pcFolioDebtors.labels.snapshot;
        var status = String(snapshot.status || '');
        var building = status === 'BUILDING' || snapshot.running === true;
        var ready = snapshotIsReady(snapshot);
        currentSnapshot = snapshot;

        snapshotRoot.dataset.status = building ? 'building' : status.toLowerCase();
        snapshotState.textContent = building ? labels.building : snapshotLabel(status);
        snapshotDate.textContent = dateText(snapshot.asOfDate) || '\u2014';
        snapshotCompleted.textContent = dateTimeText(snapshot.completedAt) || '\u2014';
        snapshotTotal.textContent = String(number(snapshot.totalClients));
        refreshButton.disabled = building;
        setReportAvailability(ready);

        if (ready) {
            stopSnapshotPolling();
            if (snapshot.asOfDate && String(snapshot.asOfDate).substring(0, 10) < pcFolioDebtors.today) {
                setSnapshotMessage(labels.staleMessage.replace('%s', dateText(snapshot.asOfDate)), 'warning');
            } else {
                setSnapshotMessage(labels.readyMessage, 'success');
            }
            if (loadAfterReady) {
                loadAfterReady = false;
                loadReport();
            }
            return;
        }

        if (building) {
            setSnapshotMessage(labels.buildingMessage, 'loading');
            scheduleSnapshotCheck();
        } else if (status === 'NOT_READY') {
            setSnapshotMessage(labels.notReadyMessage, 'warning');
        } else if (status === 'FAILED') {
            var failure = labels.failedMessage;
            if (snapshot.error) failure += ' ' + String(snapshot.error);
            setSnapshotMessage(failure, 'error');
        } else if (status === 'ACTIVE') {
            setSnapshotMessage(labels.emptyMessage, 'warning');
        } else {
            setSnapshotMessage(snapshotLabel(status), 'warning');
            if (status === 'SUPERSEDED') scheduleSnapshotCheck();
        }
    }

    function ajaxRequest(action, signal) {
        var body = new URLSearchParams();
        body.set('action', action);
        body.set('_ajax_nonce', pcFolioDebtors.nonce);
        return fetch(pcFolioDebtors.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            body: body.toString(),
            signal: signal
        }).then(function (response) {
            return response.json().catch(function () { return {}; }).then(function (payload) {
                if (!response.ok || !payload.success) {
                    var message = payload.data && payload.data.message ? payload.data.message : pcFolioDebtors.labels.snapshot.statusFailed;
                    if (payload.data && payload.data.reqId) {
                        message += ' ' + pcFolioDebtors.labels.requestId.replace('%s', payload.data.reqId);
                    }
                    throw new Error(message);
                }
                return payload.data.snapshot;
            });
        });
    }

    function checkSnapshot(silent) {
        if (snapshotController) snapshotController.abort();
        snapshotController = new AbortController();
        setReportAvailability(false);
        if (!silent) setSnapshotMessage(pcFolioDebtors.labels.snapshot.checking, 'loading');

        return ajaxRequest('pc_folio_customer_debtors_snapshot_status', snapshotController.signal)
            .then(renderSnapshot)
            .catch(function (error) {
                if (error.name === 'AbortError') return;
                stopSnapshotPolling();
                currentSnapshot = null;
                refreshButton.disabled = false;
                setReportAvailability(false);
                setSnapshotMessage(error.message || pcFolioDebtors.labels.snapshot.statusFailed, 'error');
            });
    }

    function refreshSnapshot() {
        if (snapshotController) snapshotController.abort();
        snapshotController = new AbortController();
        stopSnapshotPolling();
        currentSnapshot = null;
        if (reportController) reportController.abort();
        metaBox.hidden = true;
        summaryBox.hidden = true;
        tableWrap.hidden = true;
        pagination.hidden = true;
        setStatus('', '');
        refreshButton.disabled = true;
        setReportAvailability(false);
        setSnapshotMessage(pcFolioDebtors.labels.snapshot.refreshing, 'loading');

        ajaxRequest('pc_folio_customer_debtors_snapshot_refresh', snapshotController.signal)
            .then(function (result) {
                var message = result && result.refreshAccepted === false
                    ? pcFolioDebtors.labels.snapshot.running
                    : pcFolioDebtors.labels.snapshot.accepted;
                setSnapshotMessage(message, 'loading');
                scheduleSnapshotCheck();
            })
            .catch(function (error) {
                if (error.name === 'AbortError') return;
                refreshButton.disabled = false;
                setSnapshotMessage(error.message || pcFolioDebtors.labels.snapshot.refreshFailed, 'error');
            });
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
        if (!snapshotIsReady(currentSnapshot)) {
            loadAfterReady = true;
            checkSnapshot(false);
            return;
        }
        if (reportController) reportController.abort();
        reportController = new AbortController();
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
            signal: reportController.signal
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
                setReportAvailability(snapshotIsReady(currentSnapshot));
            });
    }

    form.addEventListener('submit', function (event) {
        event.preventDefault();
        offset = 0;
        loadAfterReady = true;
        checkSnapshot(false);
    });
    prevButton.addEventListener('click', function () {
        offset = Math.max(0, offset - number(form.elements.limit.value));
        loadReport();
    });
    nextButton.addEventListener('click', function () {
        offset += number(form.elements.limit.value);
        loadReport();
    });
    refreshButton.addEventListener('click', refreshSnapshot);

    setReportAvailability(false);
    checkSnapshot(false);
}());
