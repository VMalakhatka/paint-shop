(function () {
    'use strict';

    var root = document.querySelector('[data-pc-folio-documents]');
    if (!root || typeof pcFolioDocuments === 'undefined') return;

    var form = root.querySelector('[data-pc-documents-form]');
    var statusBox = root.querySelector('[data-pc-documents-status]');
    var warningBox = root.querySelector('[data-pc-documents-warning]');
    var tableWrap = root.querySelector('[data-pc-documents-table-wrap]');
    var rowsBox = root.querySelector('[data-pc-documents-rows]');
    var pagination = root.querySelector('[data-pc-documents-pagination]');
    var prevButton = root.querySelector('[data-pc-documents-prev]');
    var nextButton = root.querySelector('[data-pc-documents-next]');
    var pageLabel = root.querySelector('[data-pc-documents-page]');
    var detailBox = root.querySelector('[data-pc-document-detail]');
    var detailTitle = root.querySelector('[data-pc-document-detail-title]');
    var detailContent = root.querySelector('[data-pc-document-detail-content]');
    var closeDetailButton = root.querySelector('[data-pc-document-close]');
    var submitButton = form.querySelector('[type="submit"]');
    var listController = null;
    var detailController = null;
    var cursors = [''];
    var pageIndex = 0;
    var nextCursor = '';
    var hasMore = false;
    var labels = pcFolioDocuments.labels;
    var money = new Intl.NumberFormat('uk-UA', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    var dateFormatter = new Intl.DateTimeFormat('uk-UA');

    function text(value) {
        return value == null ? '' : String(value);
    }

    function number(value) {
        var parsed = Number(value);
        return Number.isFinite(parsed) ? parsed : 0;
    }

    function moneyText(value, currency) {
        return money.format(number(value)) + ' ' + (currency || labels.currency);
    }

    function dateText(value, includeTime) {
        if (!value) return '';
        if (Array.isArray(value) && value.length >= 3) {
            var arrayDate = new Date(Number(value[0]), Number(value[1]) - 1, Number(value[2]), Number(value[3] || 0), Number(value[4] || 0));
            return includeTime ? arrayDate.toLocaleString('uk-UA') : dateFormatter.format(arrayDate);
        }
        var raw = String(value);
        var parsed = new Date(raw);
        if (Number.isNaN(parsed.getTime())) return raw;
        return includeTime ? parsed.toLocaleString('uk-UA') : dateFormatter.format(parsed);
    }

    function setStatus(message, kind) {
        statusBox.textContent = message || '';
        statusBox.className = 'pc-folio-documents__status' + (kind ? ' is-' + kind : '');
    }

    function request(action, params, signal) {
        var body = new URLSearchParams();
        body.set('action', action);
        body.set('_ajax_nonce', pcFolioDocuments.nonce);
        Object.keys(params || {}).forEach(function (key) {
            if (params[key] !== '' && params[key] != null) body.set(key, String(params[key]));
        });
        return fetch(pcFolioDocuments.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            body: body.toString(),
            signal: signal
        }).then(function (response) {
            return response.json().catch(function () { return {}; }).then(function (payload) {
                if (!response.ok || !payload.success) {
                    var data = payload.data || {};
                    var message = data.message || labels.requestFailed;
                    if (data.reqId) message += ' ' + labels.requestId.replace('%s', data.reqId);
                    var error = new Error(message);
                    error.data = data;
                    throw error;
                }
                return payload.data.result;
            });
        });
    }

    function cell(row, value, className) {
        var td = document.createElement('td');
        td.textContent = value;
        if (className) td.className = className;
        row.appendChild(td);
        return td;
    }

    function documentNumber(document) {
        return text(document.documentNumber) + text(document.documentNumberSuffix);
    }

    function renderWarnings(warnings) {
        var activeOnly = (Array.isArray(warnings) ? warnings : []).some(function (warning) {
            return warning && warning.code === 'ACTIVE_LEDGER_ONLY';
        });
        warningBox.textContent = activeOnly ? labels.activeOnly : '';
        warningBox.hidden = !warningBox.textContent;
    }

    function renderList(result) {
        var documents = Array.isArray(result.documents) ? result.documents : [];
        rowsBox.replaceChildren();
        documents.forEach(function (document) {
            var row = documentNode('tr');
            cell(row, labels.types[document.documentType] || text(document.documentType));
            cell(row, documentNumber(document));
            cell(row, dateText(document.documentDate));
            cell(row, moneyText(document.totalAmount), 'is-money');
            cell(row, document.documentType === 'PAYMENT' ? '\u2014' : (document.warehouseLabel || '\u2014'));
            var accountingLabel = document.accounted === true
                ? labels.accounted
                : (document.accounted === false ? labels.notAccounted : '\u2014');
            cell(row, accountingLabel, document.accounted === false ? 'is-not-accounted' : '');
            cell(row, document.information || '\u2014');
            cell(row, document.lineCount == null ? '\u2014' : text(document.lineCount));
            var actions = cell(row, '');
            var button = documentNode('button', 'button button-small');
            button.type = 'button';
            button.textContent = document.documentType === 'PAYMENT' ? labels.types.PAYMENT : labels.fields.documentNumber;
            button.textContent = root.dataset.viewLabel || button.textContent;
            button.setAttribute('data-document-view', '');
            button.dataset.documentType = document.documentType;
            button.dataset.documentId = document.documentId;
            actions.appendChild(button);
            rowsBox.appendChild(row);
        });
        tableWrap.hidden = documents.length === 0;
        setStatus(documents.length ? '' : labels.empty, documents.length ? '' : 'info');
        renderWarnings(result.warnings);
        hasMore = result.hasMore === true && !!result.nextCursor;
        nextCursor = hasMore ? String(result.nextCursor) : '';
        prevButton.disabled = pageIndex === 0;
        nextButton.disabled = !hasMore;
        pageLabel.textContent = String(pageIndex + 1);
        pagination.hidden = pageIndex === 0 && !hasMore;
    }

    function documentNode(tag, className) {
        var node = document.createElement(tag);
        if (className) node.className = className;
        return node;
    }

    function selectedTypes() {
        return Array.prototype.slice.call(form.querySelectorAll('[name="types"]:checked')).map(function (input) {
            return input.value;
        });
    }

    function loadList(reset) {
        var types = selectedTypes();
        if (!types.length) {
            setStatus(root.dataset.typesRequired, 'error');
            return;
        }
        if (reset) {
            cursors = [''];
            pageIndex = 0;
            closeDetail();
        }
        if (listController) listController.abort();
        listController = new AbortController();
        submitButton.disabled = true;
        prevButton.disabled = true;
        nextButton.disabled = true;
        setStatus(labels.loading, 'loading');
        request('pc_folio_customer_documents', {
            date_from: form.elements.date_from.value,
            date_to: form.elements.date_to.value,
            types: types.join(','),
            limit: form.elements.limit.value,
            cursor: cursors[pageIndex] || ''
        }, listController.signal)
            .then(renderList)
            .catch(function (error) {
                if (error.name !== 'AbortError') setStatus(error.message || labels.requestFailed, 'error');
            })
            .finally(function () { submitButton.disabled = false; });
    }

    function valueText(value, key) {
        if (typeof value === 'boolean') return value ? labels.yes : labels.no;
        if (value == null || value === '') return labels.notSpecified;
        if (key === 'documentDate') return dateText(value, false);
        if (/date|created|updated/i.test(key)) return dateText(value, true);
        if (/amount|price/i.test(key) && Number.isFinite(Number(value))) return moneyText(value);
        if (Array.isArray(value) || typeof value === 'object') return JSON.stringify(value);
        return String(value);
    }

    function information(document) {
        return text(document.information || document.additionalInfo || document.additionalInformation || document.documentInfo || document.infoText || document.info);
    }

    function productLink(item, label) {
        var woo = item && item.woo ? item.woo : {};
        if (!woo.found || !woo.url) return document.createTextNode(label || text(item && item.sku));
        var link = documentNode('a');
        link.href = woo.url;
        link.target = '_blank';
        link.rel = 'noopener';
        link.textContent = label || text(item.sku);
        return link;
    }

    function stockText(item) {
        var woo = item && item.woo ? item.woo : {};
        if (!woo.found) return labels.productMissing;
        var locations = Array.isArray(woo.locations) ? woo.locations.filter(function (location) {
            return number(location.quantity) > 0;
        }) : [];
        if (locations.length) {
            return locations.map(function (location) {
                return text(location.name) + ' \u2014 ' + money.format(number(location.quantity));
            }).join(', ');
        }
        if (!woo.inStock) return labels.stockEmpty;
        return woo.stockTotal == null ? labels.notSpecified : labels.stockTotal.replace('%s', money.format(number(woo.stockTotal)));
    }

    function renderKeyValues(title, data, preferredKeys) {
        if (!data || typeof data !== 'object') return null;
        var keys = (preferredKeys || Object.keys(data)).filter(function (key) {
            return Object.prototype.hasOwnProperty.call(data, key) && !Array.isArray(data[key]) && typeof data[key] !== 'object';
        });
        if (!keys.length) return null;
        var section = documentNode('section', 'pc-folio-documents__detail-section');
        var heading = null;
        if (title) {
            heading = documentNode('h4');
            heading.textContent = title;
        }
        var list = documentNode('dl', 'pc-folio-documents__requisites');
        keys.forEach(function (key) {
            var wrapper = documentNode('div');
            var dt = documentNode('dt');
            var dd = documentNode('dd');
            dt.textContent = labels.fields[key] || key;
            dd.textContent = valueText(data[key], key);
            wrapper.append(dt, dd);
            list.appendChild(wrapper);
        });
        if (heading) section.appendChild(heading);
        section.appendChild(list);
        return section;
    }

    function renderDataTable(title, rows, columns) {
        rows = Array.isArray(rows) ? rows : [];
        if (!rows.length) return null;
        var section = documentNode('section', 'pc-folio-documents__detail-section');
        var heading = null;
        if (title) {
            heading = documentNode('h4');
            heading.textContent = title;
        }
        var wrap = documentNode('div', 'pc-folio-documents__detail-table-wrap');
        var table = documentNode('table', 'pc-folio-documents__detail-table');
        var head = documentNode('thead');
        var headRow = documentNode('tr');
        columns.forEach(function (column) {
            var th = documentNode('th');
            th.textContent = column.label;
            headRow.appendChild(th);
        });
        head.appendChild(headRow);
        var body = documentNode('tbody');
        rows.forEach(function (item, rowIndex) {
            var row = documentNode('tr');
            columns.forEach(function (column) {
                var td = documentNode('td', column.className || '');
                var value = item[column.key];
                if (column.render) {
                    var rendered = column.render(value, item, rowIndex);
                    if (rendered) td.appendChild(rendered);
                } else {
                    td.textContent = column.format ? column.format(value, item) : valueText(value, column.key);
                }
                row.appendChild(td);
            });
            body.appendChild(row);
        });
        table.append(head, body);
        wrap.appendChild(table);
        if (heading) section.appendChild(heading);
        section.appendChild(wrap);
        return section;
    }

    function renderDetail(result) {
        var document = result.document || {};
        detailContent.replaceChildren();
        detailTitle.textContent = (labels.types[document.documentType] || text(document.documentType)) + ' ' + documentNumber(document);

        var headerData = {
            documentTypeLabel: labels.types[document.documentType] || text(document.documentType),
            documentNumber: document.documentNumber,
            documentNumberSuffix: document.documentNumberSuffix,
            documentDate: document.documentDate,
            totalAmount: document.totalAmount,
            operationKind: document.operationKind,
            information: information(document)
        };
        if (document.documentType !== 'PAYMENT') {
            headerData.warehouseLabel = document.warehouseLabel;
            headerData.accounted = document.accounted;
            headerData.nonCash = document.nonCash;
            headerData.returnDocument = document.returnDocument;
        }
        var header = renderKeyValues(root.dataset.requisitesLabel, headerData, [
            'documentTypeLabel', 'documentNumber', 'documentNumberSuffix', 'documentDate', 'totalAmount',
            'warehouseLabel', 'operationKind', 'accounted', 'nonCash', 'returnDocument', 'information'
        ]);
        if (header) detailContent.appendChild(header);

        var requisites = document.documentRequisites || result.documentRequisites;
        var requisitesBlock = renderKeyValues(root.dataset.additionalLabel, requisites);
        if (requisitesBlock) detailContent.appendChild(requisitesBlock);

        var paymentRequisites = document.paymentRequisites || result.paymentRequisites;
        if (document.documentType === 'ACCOUNT' || document.documentType === 'PAYMENT') {
            var paymentBlock = renderKeyValues(root.dataset.paymentRequisitesLabel, paymentRequisites);
            if (paymentBlock) {
                detailContent.appendChild(paymentBlock);
            } else if (document.documentType === 'ACCOUNT') {
                var paymentEmpty = documentNode('section', 'pc-folio-documents__detail-section pc-folio-documents__payment-requisites');
                var paymentHeading = documentNode('h4');
                var paymentText = documentNode('p');
                paymentHeading.textContent = root.dataset.paymentRequisitesLabel;
                paymentText.textContent = root.dataset.paymentRequisitesEmpty;
                paymentEmpty.append(paymentHeading, paymentText);
                detailContent.appendChild(paymentEmpty);
            }
        }

        var items = renderDataTable(root.dataset.itemsLabel, document.items || result.items, [
            { key: 'lineNumber', label: root.dataset.lineLabel },
            { key: 'sku', label: root.dataset.skuLabel },
            { key: 'name', label: root.dataset.nameLabel, render: function (value, item) { return productLink(item, text(value)); } },
            { key: 'warehouseLabel', label: labels.fields.warehouseLabel },
            { key: 'requestedQuantity', label: root.dataset.requestedQuantityLabel, className: 'is-money' },
            { key: 'quantity', label: root.dataset.quantityLabel, className: 'is-money' },
            { key: 'price', label: root.dataset.priceLabel, className: 'is-money', format: function (value) { return moneyText(value); } },
            { key: 'amount', label: root.dataset.amountLabel, className: 'is-money', format: function (value) { return moneyText(value); } },
            { key: 'repeatable', label: root.dataset.repeatableLabel }
        ]);
        if (items) detailContent.appendChild(items);

        var linked = renderDataTable(root.dataset.paymentsLabel, document.linkedPayments || result.linkedPayments, [
            { key: 'documentNumber', label: labels.fields.documentNumber },
            { key: 'documentDate', label: labels.fields.documentDate, format: function (value) { return dateText(value); } },
            { key: 'amount', label: root.dataset.amountLabel, className: 'is-money', format: function (value) { return moneyText(value); } },
            { key: 'note', label: root.dataset.noteLabel }
        ]);
        if (linked) detailContent.appendChild(linked);

        var allocations = renderDataTable(root.dataset.allocationsLabel, document.allocations || result.allocations, [
            { key: 'documentNumber', label: labels.fields.documentNumber },
            { key: 'documentType', label: root.dataset.typeLabel, format: function (value) { return labels.types[value] || text(value); } },
            { key: 'amount', label: root.dataset.amountLabel, className: 'is-money', format: function (value) { return moneyText(value); } },
            { key: 'note', label: root.dataset.noteLabel }
        ]);
        if (allocations) detailContent.appendChild(allocations);

        var repeatOrder = document.repeatOrder || result.repeatOrder || {};
        var repeatSection = documentNode('section', 'pc-folio-documents__detail-section pc-folio-documents__repeat');
        if (repeatOrder.allowed === true) {
            var repeatHeading = documentNode('h4');
            repeatHeading.textContent = root.dataset.repeatItemsLabel;
            repeatSection.appendChild(repeatHeading);
            var repeatNotice = documentNode('p');
            repeatNotice.textContent = root.dataset.repeatNotice;
            repeatSection.appendChild(repeatNotice);
            var selectionHelp = documentNode('p');
            selectionHelp.textContent = labels.selectionHelp;
            repeatSection.appendChild(selectionHelp);
            var repeatItems = renderDataTable('', repeatOrder.items, [
                { key: '_selected', label: root.dataset.selectLabel, render: function (value, item, rowIndex) {
                    var checkbox = documentNode('input');
                    checkbox.type = 'checkbox';
                    checkbox.checked = !!(item.woo && item.woo.found);
                    checkbox.disabled = !checkbox.checked;
                    checkbox.setAttribute('data-repeat-index', String(rowIndex));
                    return checkbox;
                } },
                { key: 'sku', label: root.dataset.skuLabel },
                { key: 'name', label: root.dataset.productLabel, render: function (value, item) { return productLink(item, text(value)); } },
                { key: 'quantity', label: root.dataset.quantityLabel, className: 'is-money' },
                { key: 'historicalPrice', label: root.dataset.historicalPriceLabel, className: 'is-money', format: function (value) { return moneyText(value); } },
                { key: '_stock', label: root.dataset.currentStockLabel, format: function (value, item) { return stockText(item); } }
            ]);
            if (repeatItems) repeatSection.appendChild(repeatItems);
            var selectAllLabel = documentNode('label', 'pc-folio-documents__select-all');
            var selectAll = documentNode('input');
            selectAll.type = 'checkbox';
            selectAll.checked = !!repeatSection.querySelector('[data-repeat-index]:not(:disabled)');
            selectAll.disabled = !selectAll.checked;
            selectAll.setAttribute('data-repeat-select-all', '');
            selectAllLabel.append(selectAll, document.createTextNode(' ' + labels.selectAll));
            repeatSection.appendChild(selectAllLabel);
            var repeatActions = documentNode('div', 'pc-folio-documents__repeat-actions');
            var cartButton = documentNode('button', 'button alt');
            var draftButton = documentNode('button', 'button');
            cartButton.type = draftButton.type = 'button';
            cartButton.textContent = root.dataset.addCartLabel;
            draftButton.textContent = root.dataset.addDraftLabel;
            cartButton.dataset.repeatTarget = 'cart';
            draftButton.dataset.repeatTarget = 'draft';
            cartButton.dataset.documentType = draftButton.dataset.documentType = document.documentType;
            cartButton.dataset.documentId = draftButton.dataset.documentId = document.documentId;
            repeatActions.append(cartButton, draftButton);
            repeatSection.appendChild(repeatActions);
            repeatSection.appendChild(documentNode('div', 'pc-folio-documents__repeat-result'));
        } else {
            var reason = documentNode('p');
            reason.textContent = labels.repeatReasons[repeatOrder.reason] || text(repeatOrder.reason) || root.dataset.repeatUnavailable;
            repeatSection.appendChild(reason);
        }
        detailContent.appendChild(repeatSection);
        detailBox.hidden = false;
        detailBox.scrollIntoView({ behavior: 'smooth', block: 'start' });
        setStatus('', '');
    }

    function selectedRepeatIndexes(section) {
        return Array.prototype.slice.call(section.querySelectorAll('[data-repeat-index]:checked')).map(function (checkbox) {
            return Number(checkbox.getAttribute('data-repeat-index'));
        }).filter(Number.isInteger);
    }

    function repeatResult(section, result) {
        var box = section.querySelector('.pc-folio-documents__repeat-result');
        box.replaceChildren();
        var message = documentNode('p');
        message.textContent = result.target === 'draft' ? labels.draftSuccess : labels.cartSuccess;
        box.appendChild(message);
        if (Array.isArray(result.skipped) && result.skipped.length) {
            var warning = documentNode('p', 'is-warning');
            warning.textContent = labels.partialSuccess;
            var list = documentNode('ul');
            result.skipped.forEach(function (item) {
                var row = documentNode('li');
                row.textContent = (item.sku ? item.sku + ': ' : '') + text(item.message);
                list.appendChild(row);
            });
            box.append(warning, list);
        }
        if (result.url) {
            var link = documentNode('a', 'button');
            link.href = result.url;
            link.textContent = result.target === 'draft' ? root.dataset.openDraftLabel : root.dataset.openCartLabel;
            box.appendChild(link);
        }
    }

    function repeatError(section, error) {
        var box = section.querySelector('.pc-folio-documents__repeat-result');
        box.replaceChildren();
        var message = documentNode('p', 'is-warning');
        message.textContent = error.message || labels.requestFailed;
        box.appendChild(message);
        var skipped = error.data && Array.isArray(error.data.skipped) ? error.data.skipped : [];
        if (skipped.length) {
            var list = documentNode('ul');
            skipped.forEach(function (item) {
                var row = documentNode('li');
                row.textContent = (item.sku ? item.sku + ': ' : '') + text(item.message);
                list.appendChild(row);
            });
            box.appendChild(list);
        }
    }

    function runRepeatAction(button) {
        var section = button.closest('.pc-folio-documents__repeat');
        var indexes = selectedRepeatIndexes(section);
        if (!indexes.length) {
            setStatus(labels.selectItems, 'error');
            return;
        }
        var buttons = section.querySelectorAll('[data-repeat-target]');
        buttons.forEach(function (item) { item.disabled = true; });
        setStatus(labels.loading, 'loading');
        request('pc_folio_customer_document_repeat', {
            document_type: button.dataset.documentType,
            document_id: button.dataset.documentId,
            target: button.dataset.repeatTarget,
            selected_indexes: JSON.stringify(indexes)
        })
            .then(function (result) {
                repeatResult(section, result);
                setStatus('', '');
            })
            .catch(function (error) {
                repeatError(section, error);
                setStatus(error.message || labels.requestFailed, 'error');
            })
            .finally(function () {
                buttons.forEach(function (item) { item.disabled = false; });
            });
    }

    function loadDetail(type, id) {
        if (detailController) detailController.abort();
        detailController = new AbortController();
        detailBox.hidden = true;
        setStatus(labels.detailsLoading, 'loading');
        request('pc_folio_customer_document_detail', { document_type: type, document_id: id }, detailController.signal)
            .then(renderDetail)
            .catch(function (error) {
                if (error.name !== 'AbortError') setStatus(error.message || labels.requestFailed, 'error');
            });
    }

    function closeDetail() {
        if (detailController) detailController.abort();
        detailBox.hidden = true;
        detailContent.replaceChildren();
    }

    form.addEventListener('submit', function (event) {
        event.preventDefault();
        loadList(true);
    });
    rowsBox.addEventListener('click', function (event) {
        var button = event.target.closest('[data-document-view]');
        if (button) loadDetail(button.dataset.documentType, button.dataset.documentId);
    });
    prevButton.addEventListener('click', function () {
        if (pageIndex > 0) {
            pageIndex -= 1;
            loadList(false);
        }
    });
    nextButton.addEventListener('click', function () {
        if (!hasMore || !nextCursor) return;
        pageIndex += 1;
        cursors[pageIndex] = nextCursor;
        loadList(false);
    });
    closeDetailButton.addEventListener('click', closeDetail);
    detailContent.addEventListener('click', function (event) {
        var selectAll = event.target.closest('[data-repeat-select-all]');
        if (selectAll) {
            var repeatSection = selectAll.closest('.pc-folio-documents__repeat');
            repeatSection.querySelectorAll('[data-repeat-index]:not(:disabled)').forEach(function (checkbox) {
                checkbox.checked = selectAll.checked;
            });
            return;
        }
        var button = event.target.closest('[data-repeat-target]');
        if (button) runRepeatAction(button);
    });

    loadList(true);
}());
