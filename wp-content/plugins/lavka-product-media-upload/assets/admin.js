(function () {
    'use strict';

    var data = window.LPMU_DATA || {};
    var strings = data.strings || {};
    var registryInput = document.getElementById('lpmu-registry');
    var imagesInput = document.getElementById('lpmu-images');
    var folderInput = document.getElementById('lpmu-folder');
    var folderButton = document.getElementById('lpmu-folder-button');
    var dropZone = document.getElementById('lpmu-drop-zone');
    var fileCount = document.getElementById('lpmu-file-count');
    var legacyMain = document.getElementById('lpmu-legacy-main');
    var generateNames = document.getElementById('lpmu-generate-names');
    var checkButton = document.getElementById('lpmu-check');
    var uploadButton = document.getElementById('lpmu-upload');
    var uploadState = document.getElementById('lpmu-upload-state');
    var reportLink = document.getElementById('lpmu-report-link');
    var spinner = document.getElementById('lpmu-spinner');
    var summary = document.getElementById('lpmu-summary');
    var results = document.getElementById('lpmu-results');
    var selectedImages = [];
    var batchToken = '';
    var uploadAllowed = false;

    if (!registryInput || !imagesInput || !dropZone || !checkButton) {
        return;
    }

    function escapeHtml(value) {
        return String(value === null || value === undefined ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function format(template, number) {
        return String(template || '').replace('%d', String(number));
    }

    function formatBytes(bytes) {
        var units = ['B', 'KiB', 'MiB', 'GiB'];
        var value = Math.max(0, Number(bytes) || 0);
        var unit = 0;
        while (value >= 1024 && unit < units.length - 1) {
            value /= 1024;
            unit += 1;
        }
        return value.toFixed(unit === 0 ? 0 : 1) + ' ' + units[unit];
    }

    function setFiles(files) {
        selectedImages = Array.prototype.slice.call(files || []);
        fileCount.textContent = selectedImages.length
            ? format(strings.filesSelected, selectedImages.length)
            : '';
        resetApproval();
    }

    function resetApproval() {
        batchToken = '';
        uploadAllowed = false;
        if (uploadButton) {
            uploadButton.disabled = true;
        }
        if (uploadState) {
            uploadState.textContent = strings.uploadLocked || strings.dryRunRequired;
            uploadState.classList.remove('is-ready', 'is-blocked', 'is-complete');
        }
        reportLink.classList.add('lpmu-hidden');
        reportLink.removeAttribute('href');
    }

    function setBusy(active, message) {
        checkButton.disabled = active;
        if (uploadButton) {
            uploadButton.disabled = active || !uploadAllowed;
        }
        spinner.classList.toggle('is-active', active);
        if (active && message) {
            summary.innerHTML = '<div class="notice notice-info inline"><p>' + escapeHtml(message) + '</p></div>';
        }
    }

    function buildForm(action) {
        var form = new FormData();
        form.append('action', action);
        form.append('nonce', data.nonce || '');
        form.append('legacy_main_confirm', legacyMain.checked ? '1' : '');
        form.append('generate_names', generateNames && generateNames.checked ? '1' : '');
        form.append('registry', registryInput.files[0]);
        selectedImages.forEach(function (file) {
            form.append('images[]', file, file.name);
        });
        if (batchToken) {
            form.append('batch_token', batchToken);
        }
        return form;
    }

    function validateSelection() {
        if (!registryInput.files.length) {
            window.alert(strings.selectRegistry);
            return false;
        }
        if (!selectedImages.length) {
            window.alert(strings.selectImages);
            return false;
        }
        if (data.maxFiles && selectedImages.length > data.maxFiles) {
            window.alert(format(strings.tooManyImages, data.maxFiles));
            return false;
        }
        var requestBytes = Number(registryInput.files[0].size || 0);
        selectedImages.forEach(function (file) {
            requestBytes += Number(file.size || 0);
        });
        if (data.maxRequestBytes && requestBytes > data.maxRequestBytes) {
            window.alert(String(strings.requestTooLarge || '')
                .replace('%1$s', formatBytes(requestBytes))
                .replace('%2$s', formatBytes(data.maxRequestBytes)));
            return false;
        }
        return true;
    }

    function requestError(message, technical) {
        var error = new Error(message || strings.requestFailed);
        error.technical = technical || '';
        return error;
    }

    function request(action, busyMessage) {
        setBusy(true, busyMessage);
        return window.fetch(data.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            body: buildForm(action)
        }).then(function (response) {
            return response.text().then(function (rawBody) {
                var payload;
                try {
                    payload = JSON.parse(rawBody);
                } catch (ignore) {
                    throw requestError(
                        strings.requestFailed + ' HTTP ' + response.status,
                        rawBody.slice(0, 3000)
                    );
                }
                payload._httpStatus = response.status;
                return payload;
            });
        }).then(function (payload) {
            if (!payload || !payload.success) {
                var message = payload && payload.data && payload.data.message
                    ? payload.data.message
                    : strings.requestFailed;
                var technical = payload && payload.data && payload.data.technical
                    ? payload.data.technical
                    : '';
                throw requestError(
                    message + (payload && payload._httpStatus ? ' (HTTP ' + payload._httpStatus + ')' : ''),
                    technical
                );
            }
            return payload.data;
        }).finally(function () {
            setBusy(false);
        });
    }

    function statusLabel(status) {
        return (strings.statusLabels && strings.statusLabels[status]) || status || '';
    }

    function mappedLabel(mapName, value) {
        var labels = strings[mapName] || {};
        return labels[value] || value || '';
    }

    function listBlock(title, entries, type) {
        if (!entries || !entries.length) {
            return '';
        }
        return '<div class="lpmu-message-list lpmu-' + type + '">'
            + '<strong>' + escapeHtml(title) + '</strong>'
            + '<ul>' + entries.map(function (entry) {
                return '<li>' + escapeHtml(entry) + '</li>';
            }).join('') + '</ul></div>';
    }

    function renderRows(rows) {
        if (!rows || !rows.length) {
            results.innerHTML = '';
            return;
        }

        var body = rows.map(function (row) {
            var stateClass = row.status === 'SUCCESS'
                ? 'is-success'
                : (row.valid ? 'is-ready' : (row.errors && row.errors.length ? 'is-error' : 'is-warning'));
            var technical = row.technical
                ? '<details><summary>' + escapeHtml(strings.details) + '</summary><code>' + escapeHtml(row.technical) + '</code></details>'
                : '';
            var identifiers = [];
            if (row.sku) {
                identifiers.push('<strong>' + escapeHtml(strings.skuLabel) + ':</strong> <code>' + escapeHtml(row.sku) + '</code>');
            }
            if (row.barcode) {
                identifiers.push('<strong>' + escapeHtml(strings.gtinLabel) + ':</strong> <code>' + escapeHtml(row.barcode) + '</code>');
            }
            var product = row.product_id
                ? '<strong>#' + escapeHtml(row.product_id) + '</strong><br>' + escapeHtml(row.product_name || '')
                : '';
            var imageInfo = [];
            if (row.format) {
                imageInfo.push(escapeHtml(row.format) + ' / ' + escapeHtml(row.mime || ''));
            }
            if (row.width && row.height) {
                imageInfo.push(escapeHtml(row.width) + ' × ' + escapeHtml(row.height) + ' px');
            }
            if (row.color_space) {
                imageInfo.push(escapeHtml(strings.colorSpace) + ': ' + escapeHtml(row.color_space));
            }
            if (row.file_size) {
                imageInfo.push(escapeHtml(Math.round(row.file_size / 1024)) + ' KiB');
            }
            if (row.sha256) {
                imageInfo.push('<span title="' + escapeHtml(row.sha256) + '">' + escapeHtml(strings.sha256) + ': <code>' + escapeHtml(row.sha256.slice(0, 12)) + '…</code></span>');
            }
            var assignment = [];
            if (row.role) {
                assignment.push('<strong>' + escapeHtml(strings.role) + ':</strong> ' + escapeHtml(row.role));
            }
            if (row.position) {
                assignment.push('<strong>' + escapeHtml(strings.position) + ':</strong> ' + escapeHtml(row.position));
            }
            if (row.attachment_id) {
                assignment.push('<strong>' + escapeHtml(strings.attachment) + ':</strong> #' + escapeHtml(row.attachment_id));
            }
            if (row.workflow_stage) {
                assignment.push('<strong>' + escapeHtml(strings.workflow) + ':</strong> ' + escapeHtml(mappedLabel('workflowStageLabels', row.workflow_stage)));
            }
            if (row.folio_operation) {
                assignment.push('<strong>' + escapeHtml(strings.folioOperation) + ':</strong> ' + escapeHtml(mappedLabel('folioOperationLabels', row.folio_operation)));
            }
            if (row.folio_status) {
                assignment.push('<strong>' + escapeHtml(strings.folioStatus) + ':</strong> ' + escapeHtml(mappedLabel('folioStatusLabels', row.folio_status)));
            }
            if (row.s3_key) {
                assignment.push('<strong>' + escapeHtml(strings.s3Key) + ':</strong> <code>' + escapeHtml(row.s3_key) + '</code>');
            }

            return '<tr class="' + stateClass + '">'
                + '<td>' + escapeHtml(row.row_number || '—') + '</td>'
                + '<td>' + identifiers.join('<br>') + '</td>'
                + '<td><code>' + escapeHtml(row.source_file || '') + '</code></td>'
                + '<td><code>' + escapeHtml(row.canonical_file || '—') + '</code></td>'
                + '<td>' + imageInfo.join('<br>') + '</td>'
                + '<td>' + product + (product && assignment.length ? '<br>' : '') + assignment.join('<br>') + '</td>'
                + '<td><span class="lpmu-status">' + escapeHtml(statusLabel(row.status)) + '</span>'
                + listBlock(strings.errors, row.errors, 'errors')
                + listBlock(strings.warnings, row.warnings, 'warnings')
                + technical + '</td>'
                + '</tr>';
        }).join('');

        results.innerHTML = '<div class="lpmu-table-scroll"><table class="widefat striped lpmu-table">'
            + '<thead><tr>'
            + '<th>' + escapeHtml(strings.row) + '</th>'
            + '<th>' + escapeHtml(strings.identifiers) + '</th>'
            + '<th>' + escapeHtml(strings.source) + '</th>'
            + '<th>' + escapeHtml(strings.canonical) + '</th>'
            + '<th>' + escapeHtml(strings.image) + '</th>'
            + '<th>' + escapeHtml(strings.assignment) + '</th>'
            + '<th>' + escapeHtml(strings.result) + '</th>'
            + '</tr></thead><tbody>' + body + '</tbody></table></div>';
    }

    function renderSummary(result, completed) {
        var counts = result.summary || {};
        var requiresRetry = completed && ((counts.partial || 0) > 0 || (counts.errors || 0) > 0);
        var cards = [
            [strings.total, counts.total || 0],
            [completed ? strings.successful : strings.approved, completed ? (counts.success || 0) : (counts.ready || 0)],
            [strings.partial, completed ? (counts.partial || 0) : 0],
            [strings.errors, counts.errors || 0],
            [strings.warnings, counts.warnings || 0]
        ];
        summary.innerHTML = '<div class="lpmu-summary-grid">' + cards.map(function (card) {
            return '<div><strong>' + escapeHtml(card[1]) + '</strong><span>' + escapeHtml(card[0]) + '</span></div>';
        }).join('') + '</div>'
            + '<div class="notice ' + (requiresRetry ? 'notice-warning' : 'notice-success') + ' inline"><p>'
            + escapeHtml(requiresRetry ? strings.reportPartial : strings.reportReady) + '</p></div>'
            + (result.capabilities && !result.capabilities.s3_index_check
                ? '<div class="notice notice-warning inline"><p>' + escapeHtml(strings.s3Unavailable) + '</p></div>'
                : '');
    }

    function showError(error) {
        var technical = error && error.technical
            ? '<details><summary>' + escapeHtml(strings.details) + '</summary><pre>' + escapeHtml(error.technical) + '</pre></details>'
            : '';
        summary.innerHTML = '<div class="notice notice-error inline"><p>'
            + escapeHtml(error.message || strings.requestFailed)
            + '</p>' + technical + '</div>';
    }

    registryInput.addEventListener('change', resetApproval);
    imagesInput.addEventListener('change', function () {
        if (folderInput) {
            folderInput.value = '';
        }
        setFiles(imagesInput.files);
    });
    if (folderInput) {
        folderInput.addEventListener('change', function () {
            imagesInput.value = '';
            setFiles(folderInput.files);
        });
    }
    if (folderButton && folderInput) {
        folderButton.addEventListener('click', function () {
            folderInput.click();
        });
    }
    legacyMain.addEventListener('change', resetApproval);
    if (generateNames) {
        generateNames.addEventListener('change', resetApproval);
    }

    dropZone.addEventListener('click', function () {
        imagesInput.click();
    });
    dropZone.addEventListener('keydown', function (event) {
        if (event.key === 'Enter' || event.key === ' ') {
            event.preventDefault();
            imagesInput.click();
        }
    });
    ['dragenter', 'dragover'].forEach(function (eventName) {
        dropZone.addEventListener(eventName, function (event) {
            event.preventDefault();
            dropZone.classList.add('is-dragging');
        });
    });
    ['dragleave', 'drop'].forEach(function (eventName) {
        dropZone.addEventListener(eventName, function (event) {
            event.preventDefault();
            dropZone.classList.remove('is-dragging');
        });
    });
    dropZone.addEventListener('drop', function (event) {
        setFiles(event.dataTransfer.files);
    });

    checkButton.addEventListener('click', function () {
        if (!validateSelection()) {
            return;
        }
        resetApproval();
        request('lpmu_dry_run', strings.checking).then(function (result) {
            batchToken = result.batch_token || '';
            renderSummary(result, false);
            renderRows(result.rows || []);
            if (result.report_url) {
                reportLink.href = result.report_url;
                reportLink.classList.remove('lpmu-hidden');
            }
            uploadAllowed = Boolean(result.can_upload && batchToken);
            if (uploadButton) {
                uploadButton.disabled = !uploadAllowed;
            }
            if (uploadState) {
                var ready = Number(result.summary && result.summary.ready || 0);
                uploadState.textContent = uploadAllowed
                    ? format(strings.uploadReady, ready)
                    : strings.uploadBlocked;
                uploadState.classList.toggle('is-ready', uploadAllowed);
                uploadState.classList.toggle('is-blocked', !uploadAllowed);
            }
        }).catch(showError);
    });

    if (uploadButton) {
        uploadButton.addEventListener('click', function () {
            if (!batchToken) {
                window.alert(strings.dryRunRequired);
                return;
            }
            if (!window.confirm(strings.confirmUpload)) {
                return;
            }
            request('lpmu_upload_batch', strings.uploading).then(function (result) {
                renderSummary(result, true);
                renderRows(result.rows || []);
                uploadAllowed = false;
                uploadButton.disabled = true;
                batchToken = '';
                if (uploadState) {
                    uploadState.textContent = strings.uploadCompleted;
                    uploadState.classList.remove('is-ready', 'is-blocked');
                    uploadState.classList.add('is-complete');
                }
                if (result.report_url) {
                    reportLink.href = result.report_url;
                    reportLink.classList.remove('lpmu-hidden');
                }
            }).catch(showError);
        });
    }
}());
