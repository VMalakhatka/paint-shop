(function () {
  'use strict';

  const root = document.getElementById('lps-accounting-prices');
  const config = window.LPS_ACCOUNTING_PRICES || {};
  if (!root || !config.ajaxUrl) return;

  const t = config.i18n || {};
  const locale = document.documentElement.lang || 'uk-UA';
  const numberFormatter = new Intl.NumberFormat(locale, { maximumFractionDigits: 4 });
  const integerFormatter = new Intl.NumberFormat(locale, { maximumFractionDigits: 0 });

  const elements = {
    warehouse: document.getElementById('lps-ap-warehouse'),
    warehouseManual: document.getElementById('lps-ap-warehouse-manual'),
    warehouseStatus: document.getElementById('lps-ap-warehouse-status'),
    sku: document.getElementById('lps-ap-sku'),
    singlePreview: document.getElementById('lps-ap-single-preview'),
    singleConfirm: document.getElementById('lps-ap-single-confirm'),
    singleApply: document.getElementById('lps-ap-single-apply'),
    singleNotice: document.getElementById('lps-ap-single-notice'),
    singleResult: document.getElementById('lps-ap-single-result'),
    continueNegative: document.getElementById('lps-ap-continue-negative'),
    fullPreview: document.getElementById('lps-ap-full-preview'),
    fullConfirm: document.getElementById('lps-ap-full-confirm'),
    fullApply: document.getElementById('lps-ap-full-apply'),
    fullNotice: document.getElementById('lps-ap-full-notice'),
    fullSummary: document.getElementById('lps-ap-full-summary'),
    progress: document.getElementById('lps-ap-progress'),
    progressTrack: document.querySelector('#lps-ap-progress .lps-ap-progress-track'),
    progressBar: document.querySelector('#lps-ap-progress .lps-ap-progress-track span'),
    progressLabel: document.getElementById('lps-ap-progress-label'),
    warnings: document.getElementById('lps-ap-warnings'),
    warningsTable: document.getElementById('lps-ap-warnings-table'),
    truncated: document.getElementById('lps-ap-truncated'),
    copySkus: document.getElementById('lps-ap-copy-skus'),
    exportCsv: document.getElementById('lps-ap-export-csv'),
    exportJson: document.getElementById('lps-ap-export-json')
  };

  const state = {
    lastPreviewKey: '',
    warnings: [],
    warningsTruncated: false,
    pollTimer: null,
    fullRunning: false,
    lastFullPreviewWarehouse: 0
  };

  function text(value) {
    return value === null || value === undefined || value === '' ? '—' : String(value);
  }

  function formatNumber(value) {
    const numeric = Number(value);
    return Number.isFinite(numeric) ? numberFormatter.format(numeric) : text(value);
  }

  function formatInteger(value) {
    const numeric = Number(value);
    return Number.isFinite(numeric) ? integerFormatter.format(numeric) : text(value);
  }

  function formatDate(value) {
    if (!value) return '—';
    const date = new Date(value);
    return Number.isNaN(date.getTime()) ? String(value) : new Intl.DateTimeFormat(locale).format(date);
  }

  function make(tag, className, content) {
    const node = document.createElement(tag);
    if (className) node.className = className;
    if (content !== undefined) node.textContent = text(content);
    return node;
  }

  async function request(operation, payload) {
    const form = new FormData();
    form.append('action', 'lps_accounting_prices');
    form.append('_wpnonce', config.nonce || '');
    form.append('operation', operation);
    Object.entries(payload || {}).forEach(([key, value]) => {
      form.append(key, value === true ? '1' : value === false ? '0' : String(value));
    });

    const response = await fetch(config.ajaxUrl, {
      method: 'POST',
      credentials: 'same-origin',
      body: form
    });
    const contentType = (response.headers.get('content-type') || '').toLowerCase();
    if (!contentType.includes('application/json')) {
      const raw = await response.text();
      throw new Error(`${t.networkError || 'Request failed'} HTTP ${response.status}: ${raw.slice(0, 300)}`);
    }

    const envelope = await response.json();
    if (!envelope || envelope.success !== true) {
      throw new Error(envelope?.data?.message || t.networkError || 'Request failed');
    }
    return envelope.data;
  }

  function javaError(result) {
    const body = result?.body || {};
    const parts = [];
    if (body.title) parts.push(body.title);
    if (body.message) parts.push(body.message);
    if (body.error && body.error !== body.message) parts.push(body.error);
    if (body.details && typeof body.details === 'string') parts.push(body.details);
    if (!parts.length) parts.push(t.unknownError || 'Unknown error');
    if (body.reqId) parts.push(`${t.requestId || 'Request ID'}: ${body.reqId}`);
    return `${t.httpError || 'Java API returned HTTP'} ${result?.httpStatus || 0}. ${parts.join(' ')}`;
  }

  function setNotice(element, kind, message, body) {
    if (!element) return;
    element.hidden = false;
    element.className = `lps-ap-result-notice is-${kind}`;
    element.replaceChildren(make('p', '', message));

    if (body && typeof body === 'object') {
      const details = make('details', 'lps-ap-raw');
      const summary = make('summary', '', t.rawResponse || 'Raw Java response');
      const pre = make('pre', '', JSON.stringify(body, null, 2));
      details.append(summary, pre);
      element.appendChild(details);
    }
  }

  function hideNotice(element) {
    if (!element) return;
    element.hidden = true;
    element.replaceChildren();
  }

  function selectedWarehouseId() {
    if (!elements.warehouseManual.hidden) {
      return Number.parseInt(elements.warehouseManual.value, 10) || 0;
    }
    return Number.parseInt(elements.warehouse.value, 10) || 0;
  }

  function warehouseLabel(id) {
    const option = Array.from(elements.warehouse.options || []).find((item) => Number(item.value) === Number(id));
    return option ? option.textContent : String(id || '—');
  }

  function statusLabel(status) {
    return t.statusLabels?.[status] || status || '—';
  }

  async function loadWarehouses() {
    try {
      elements.warehouseStatus.textContent = t.loading || 'Loading...';
      const result = await request('warehouses');
      if (result.httpStatus < 200 || result.httpStatus >= 300) throw new Error(javaError(result));
      const items = Array.isArray(result.body?.items) ? result.body.items : [];
      if (!items.length) throw new Error(t.noWarehouses || 'No warehouses returned');

      const previous = window.localStorage.getItem('lpsAccountingPriceWarehouse') || '';
      elements.warehouse.replaceChildren();
      elements.warehouse.appendChild(make('option', '', t.selectWarehouse || 'Select warehouse'));
      elements.warehouse.firstChild.value = '';
      items.forEach((warehouse) => {
        const option = make('option', '', `${warehouse.id} — ${warehouse.name}`);
        option.value = String(warehouse.id);
        elements.warehouse.appendChild(option);
      });
      elements.warehouse.disabled = false;
      if (previous && items.some((item) => String(item.id) === previous)) elements.warehouse.value = previous;
      elements.warehouseStatus.textContent = '';
    } catch (error) {
      elements.warehouse.hidden = true;
      elements.warehouseManual.hidden = false;
      elements.warehouseStatus.textContent = `${t.warehouseLoadFailed || 'Warehouse directory is unavailable'} ${error.message || error}`;
    }
  }

  function invalidateSinglePreview() {
    state.lastPreviewKey = '';
    elements.singleConfirm.checked = false;
    elements.singleConfirm.disabled = true;
    elements.singleApply.disabled = true;
  }

  function singleKey() {
    return `${(elements.sku.value || '').trim()}|${selectedWarehouseId()}`;
  }

  function setSingleEligible(eligible) {
    state.lastPreviewKey = eligible ? singleKey() : '';
    elements.singleConfirm.checked = false;
    elements.singleConfirm.disabled = !eligible;
    elements.singleApply.disabled = true;
  }

  function collectWarnings(body) {
    const warnings = Array.isArray(body?.warnings) ? body.warnings : [];
    const errors = Array.isArray(body?.errors) ? body.errors : [];
    return [...warnings, ...errors].map((entry) => {
      if (entry && typeof entry === 'object') {
        const details = entry.details && typeof entry.details === 'object' ? entry.details : {};
        return {
          ...entry,
          details: body?.sku && !details.sku ? { ...details, sku: body.sku } : details
        };
      }
      return { code: 'ERROR', message: String(entry || t.unknownError || 'Unknown error'), details: {} };
    });
  }

  function stateTable(title, rows) {
    const section = make('section', 'lps-ap-state-section');
    section.appendChild(make('h3', '', title));
    if (!Array.isArray(rows) || !rows.length) {
      section.appendChild(make('p', 'description', t.noState || 'No state data was returned.'));
      return section;
    }

    const tableWrap = make('div', 'lps-ap-table-scroll');
    const table = make('table', 'widefat striped lps-ap-state-table');
    const headers = [
      t.warehouseName || 'Warehouse',
      t.physicalQuantity || 'Physical quantity',
      t.availableQuantity || 'Available quantity',
      t.accountingQuantity || 'Accounting quantity',
      t.accountingPrice || 'Accounting price',
      t.initialQuantity || 'Initial quantity',
      t.accountingAmount || 'Accounting amount',
      t.movementCount || 'Accounted movements'
    ];
    const thead = make('thead');
    const headRow = make('tr');
    headers.forEach((header) => headRow.appendChild(make('th', '', header)));
    thead.appendChild(headRow);
    table.appendChild(thead);

    const tbody = make('tbody');
    rows.forEach((row) => {
      const tr = make('tr');
      const values = [
        `${row.warehouseId ?? '—'}${row.warehouseName ? ` — ${row.warehouseName}` : ''}`,
        formatNumber(row.physicalQuantity),
        formatNumber(row.availableQuantity),
        formatNumber(row.accountingQuantity),
        formatNumber(row.accountingPrice),
        formatNumber(row.initialQuantity),
        formatNumber(row.accountingAmount),
        formatInteger(row.accountedMovementCount)
      ];
      values.forEach((value) => tr.appendChild(make('td', '', value)));
      tbody.appendChild(tr);
    });
    table.appendChild(tbody);
    tableWrap.appendChild(table);
    section.appendChild(tableWrap);
    return section;
  }

  function renderSingle(body, isPreview) {
    elements.singleResult.replaceChildren();
    const status = body?.status || '';
    const header = make('div', 'lps-ap-result-header');
    header.appendChild(make('span', `lps-ap-status lps-ap-status-${String(status).toLowerCase()}`, statusLabel(status)));
    if (body?.sku) header.appendChild(make('code', '', body.sku));
    if (body?.requestedWarehouseId) header.appendChild(make('span', '', warehouseLabel(body.requestedWarehouseId)));
    elements.singleResult.appendChild(header);
    elements.singleResult.appendChild(stateTable(t.before || 'Before', body?.before));
    if (!isPreview || (Array.isArray(body?.after) && body.after.length)) {
      elements.singleResult.appendChild(stateTable(t.after || 'After', body?.after));
    }

    const warnings = collectWarnings(body);
    renderWarnings(warnings, Boolean(body?.warningsTruncated));
    const eligible = isPreview && body?.eligibleToApply === true && status === 'PREVIEW_READY' && warnings.length === 0;
    setSingleEligible(eligible);

    if (status === 'PREVIEW_READY') {
      setNotice(elements.singleNotice, 'success', t.previewReady || 'Preview ready');
    } else if (status === 'PREVIEW_BLOCKED' || status === 'BLOCKED') {
      setNotice(elements.singleNotice, 'warning', t.previewBlocked || 'Preview blocked', body);
    } else if (status === 'RECALCULATED') {
      setNotice(
        elements.singleNotice,
        'success',
        body?.priceChanged === false ? (t.notChanged || 'Price unchanged') : (t.recalculated || 'Recalculated')
      );
    }
  }

  function counter(label, value, emphasis) {
    const box = make('div', `lps-ap-counter${emphasis ? ` is-${emphasis}` : ''}`);
    box.append(make('span', '', label), make('strong', '', formatInteger(value || 0)));
    return box;
  }

  function renderFull(body) {
    elements.fullSummary.replaceChildren();
    const status = String(body?.status || 'IDLE');
    const running = body?.running === true;
    state.fullRunning = running;
    if (!running && body?.request?.previewOnly === true && ['COMPLETED', 'COMPLETED_WITH_WARNINGS'].includes(status)) {
      state.lastFullPreviewWarehouse = Number(body.request.warehouseId || 0);
    } else if (!running && body?.request?.previewOnly === false && ['COMPLETED', 'COMPLETED_WITH_WARNINGS', 'FAILED', 'FAILED_PARTIAL'].includes(status)) {
      state.lastFullPreviewWarehouse = 0;
    }
    elements.fullPreview.disabled = running;
    elements.fullApply.disabled = running
      || !elements.fullConfirm.checked
      || state.lastFullPreviewWarehouse !== selectedWarehouseId();

    const total = Number(body?.totalProducts || 0);
    const processed = Number(body?.processedProducts || 0);
    const percent = total > 0 ? Math.min(100, Math.round((processed / total) * 100)) : 0;
    elements.progress.hidden = !running && total === 0;
    elements.progressTrack?.setAttribute('aria-valuenow', String(percent));
    if (elements.progressBar) elements.progressBar.style.width = `${percent}%`;
    if (elements.progressLabel) {
      const current = body?.currentSku ? ` · ${t.currentSku || 'Current SKU'}: ${body.currentSku}` : '';
      elements.progressLabel.textContent = `${formatInteger(processed)} / ${formatInteger(total)} (${percent}%)${current}`;
    }

    const header = make('div', 'lps-ap-result-header');
    header.appendChild(make('span', `lps-ap-status lps-ap-status-${status.toLowerCase()}`, statusLabel(status)));
    if (body?.jobId) header.appendChild(make('code', '', body.jobId));
    if (body?.request?.warehouseId) header.appendChild(make('span', '', warehouseLabel(body.request.warehouseId)));
    elements.fullSummary.appendChild(header);

    const counters = make('div', 'lps-ap-counters');
    counters.append(
      counter(t.totalProducts || 'Total products', body?.totalProducts),
      counter(t.processedProducts || 'Processed', body?.processedProducts),
      counter(t.eligibleProducts || 'Eligible', body?.eligibleProducts),
      counter(t.recalculatedProducts || 'Recalculated', body?.recalculatedProducts, 'success'),
      counter(t.priceChangedProducts || 'Prices changed', body?.priceChangedProducts, 'success'),
      counter(t.skippedProducts || 'Skipped', body?.skippedProducts, Number(body?.skippedProducts) > 0 ? 'warning' : ''),
      counter(t.warningCount || 'Warnings', body?.warningCount, Number(body?.warningCount) > 0 ? 'warning' : '')
    );
    elements.fullSummary.appendChild(counters);

    const warnings = collectWarnings(body);
    renderWarnings(warnings, Boolean(body?.warningsTruncated));

    if (body?.error) {
      setNotice(elements.fullNotice, 'error', `${t.jobFailed || 'Task failed'} ${body.error}`, body);
    } else if (running) {
      setNotice(elements.fullNotice, 'info', t.jobRunning || 'Task running');
    } else if (status === 'COMPLETED') {
      setNotice(elements.fullNotice, 'success', t.jobCompleted || 'Task completed');
    } else if (status === 'COMPLETED_WITH_WARNINGS') {
      setNotice(elements.fullNotice, 'warning', t.jobWarnings || 'Task completed with warnings');
    } else if (status === 'STOPPED_ON_NEGATIVE_STOCK') {
      setNotice(elements.fullNotice, 'warning', t.jobStopped || 'Task stopped');
    } else if (status === 'FAILED' || status === 'FAILED_PARTIAL' || status === 'BUSY') {
      setNotice(elements.fullNotice, 'error', body?.error || t.jobFailed || 'Task failed', body);
    } else if (status === 'IDLE') {
      setNotice(elements.fullNotice, 'info', t.idle || 'No task started');
    }

    if (body?.jobId) window.localStorage.setItem(config.storageKey, body.jobId);
    if (!running && status !== 'QUEUED') window.localStorage.removeItem(config.storageKey);
  }

  function operationText(operation) {
    const kind = String(operation?.kind || '').toUpperCase();
    const label = kind === 'EXPENSE' ? (t.expense || 'expense')
      : kind === 'RECEIPT' ? (t.receipt || 'receipt')
        : (t.unknownOperation || 'operation');
    return `${label} ${formatNumber(operation?.quantity)}`;
  }

  function renderWarnings(warnings, truncated) {
    state.warnings = Array.isArray(warnings) ? warnings : [];
    state.warningsTruncated = truncated;
    elements.warnings.hidden = state.warnings.length === 0 && !truncated;
    elements.truncated.hidden = !truncated;
    if (truncated) elements.truncated.querySelector('p').textContent = t.warningsTruncated || 'Warnings truncated';
    elements.warningsTable.replaceChildren();
    if (!state.warnings.length) {
      if (truncated) elements.warningsTable.appendChild(make('p', 'description', t.noWarnings || 'No warnings returned'));
      return;
    }

    const tableWrap = make('div', 'lps-ap-table-scroll');
    const table = make('table', 'widefat striped lps-ap-warning-table');
    const headers = [
      'SKU',
      t.warehouse || 'Warehouse',
      t.document || 'Document',
      t.date || 'Date',
      t.quantityBefore || 'Before operation',
      t.operation || 'Operation',
      t.quantityAfter || 'After operation',
      t.shortage || 'Shortage',
      t.reason || 'Reason'
    ];
    const thead = make('thead');
    const head = make('tr');
    headers.forEach((label) => head.appendChild(make('th', '', label)));
    thead.appendChild(head);
    table.appendChild(thead);
    const tbody = make('tbody');

    state.warnings.forEach((warning) => {
      const details = warning?.details || {};
      const operation = details.operation || {};
      const row = make('tr');
      const reason = make('td', 'lps-ap-warning-reason');
      const warningCode = warning?.code || 'WARNING';
      const warningLabel = t.warningLabels?.[warningCode];
      if (warningLabel) reason.appendChild(make('strong', 'lps-ap-warning-label', warningLabel));
      reason.append(make('code', '', warningCode));
      if (warning?.message) reason.appendChild(make('p', '', warning.message));
      const technical = make('details');
      technical.append(
        make('summary', '', t.details || 'Technical details'),
        make('pre', '', JSON.stringify(details, null, 2))
      );
      reason.appendChild(technical);

      const values = [
        details.sku || '—',
        details.warehouseId || operation.warehouseId || '—',
        operation.documentNumber || operation.documentId || '—',
        formatDate(operation.documentDate),
        formatNumber(details.quantityBefore),
        operationText(operation),
        formatNumber(details.quantityAfter),
        formatNumber(details.shortageQuantity)
      ];
      values.forEach((value, index) => row.appendChild(make('td', index === 0 ? 'lps-ap-sku-cell' : '', value)));
      row.appendChild(reason);
      tbody.appendChild(row);
    });

    table.appendChild(tbody);
    tableWrap.appendChild(table);
    elements.warningsTable.appendChild(tableWrap);
  }

  function csvValue(value) {
    const string = value === null || value === undefined ? '' : String(value);
    return `"${string.replace(/"/g, '""')}"`;
  }

  function warningRows() {
    return state.warnings.map((warning) => {
      const details = warning?.details || {};
      const operation = details.operation || {};
      const current = details.currentState || {};
      return {
        sku: details.sku || '',
        warehouseId: details.warehouseId || operation.warehouseId || '',
        code: warning?.code || '',
        message: warning?.message || '',
        documentType: operation.documentType || '',
        documentNumber: operation.documentNumber || '',
        documentId: operation.documentId || '',
        recno: operation.recno || '',
        documentDate: operation.documentDate || '',
        operationKind: operation.kind || '',
        operationQuantity: operation.quantity ?? '',
        initialQuantity: details.initialQuantity ?? '',
        quantityBefore: details.quantityBefore ?? '',
        quantityAfter: details.quantityAfter ?? '',
        shortageQuantity: details.shortageQuantity ?? '',
        movementPosition: details.movementPosition ?? '',
        movementCount: details.movementCount ?? '',
        currentPhysicalQuantity: current.physicalQuantity ?? '',
        currentAvailableQuantity: current.availableQuantity ?? '',
        currentAccountingQuantity: current.accountingQuantity ?? '',
        currentAccountingPrice: current.accountingPrice ?? ''
      };
    });
  }

  function download(content, mime, filename) {
    const blob = new Blob([content], { type: mime });
    const url = URL.createObjectURL(blob);
    const anchor = document.createElement('a');
    anchor.href = url;
    anchor.download = filename;
    document.body.appendChild(anchor);
    anchor.click();
    anchor.remove();
    URL.revokeObjectURL(url);
  }

  async function runSingle(previewOnly) {
    const sku = (elements.sku.value || '').trim();
    const warehouseId = selectedWarehouseId();
    if (!sku) return setNotice(elements.singleNotice, 'error', t.skuRequired || 'SKU required');
    if (!warehouseId) return setNotice(elements.singleNotice, 'error', t.warehouseRequired || 'Warehouse required');
    if (!previewOnly && state.lastPreviewKey !== singleKey()) {
      return setNotice(elements.singleNotice, 'error', t.previewRequired || 'Preview required');
    }
    if (!previewOnly && !window.confirm(t.confirmSingleApply || 'Apply?')) return;

    hideNotice(elements.singleNotice);
    elements.singlePreview.disabled = true;
    elements.singleApply.disabled = true;
    setNotice(
      elements.singleNotice,
      'info',
      previewOnly ? (t.previewRunning || 'Checking...') : (t.applyRunning || 'Applying...')
    );
    try {
      const result = await request('single', {
        sku,
        warehouseId,
        previewOnly,
        confirmApply: previewOnly ? 0 : 1
      });
      renderSingle(result.body || {}, previewOnly);
      if (result.httpStatus < 200 || result.httpStatus >= 300) {
        setNotice(elements.singleNotice, 'error', javaError(result), result.body);
        setSingleEligible(false);
      }
    } catch (error) {
      setNotice(elements.singleNotice, 'error', error.message || t.networkError || 'Request failed');
      setSingleEligible(false);
    } finally {
      elements.singlePreview.disabled = false;
    }
  }

  async function pollFullStatus(delay) {
    window.clearTimeout(state.pollTimer);
    state.pollTimer = window.setTimeout(async () => {
      try {
        const result = await request('full_status');
        if (result.httpStatus < 200 || result.httpStatus >= 300) {
          setNotice(elements.fullNotice, 'error', javaError(result), result.body);
          return;
        }
        renderFull(result.body || {});
        if (result.body?.running === true || result.body?.status === 'QUEUED') pollFullStatus(config.pollInterval || 3000);
      } catch (error) {
        setNotice(elements.fullNotice, 'error', error.message || t.networkError || 'Request failed');
        if (state.fullRunning) pollFullStatus(Math.max(5000, config.pollInterval || 3000));
      }
    }, delay ?? (config.pollInterval || 3000));
  }

  async function startFull(previewOnly) {
    const warehouseId = selectedWarehouseId();
    if (!warehouseId) return setNotice(elements.fullNotice, 'error', t.warehouseRequired || 'Warehouse required');
    if (!previewOnly && state.lastFullPreviewWarehouse !== warehouseId) {
      return setNotice(elements.fullNotice, 'error', t.fullPreviewRequired || 'Full preview required');
    }
    if (!previewOnly && !window.confirm(t.confirmFullApply || 'Apply full recalculation?')) return;

    hideNotice(elements.fullNotice);
    elements.fullPreview.disabled = true;
    elements.fullApply.disabled = true;
    setNotice(
      elements.fullNotice,
      'info',
      previewOnly ? (t.fullPreviewStarting || 'Starting preview...') : (t.fullApplyStarting || 'Starting recalculation...')
    );
    try {
      const result = await request('full_start', {
        warehouseId,
        previewOnly,
        continueOnNegativeStock: elements.continueNegative.checked,
        confirmApply: previewOnly ? 0 : 1
      });
      renderFull(result.body || {});
      if (result.httpStatus < 200 || result.httpStatus >= 300) {
        setNotice(elements.fullNotice, 'error', javaError(result), result.body);
        if (result.body?.running === true && result.body?.jobId) {
          pollFullStatus(1000);
        } else {
          state.fullRunning = false;
          elements.fullPreview.disabled = false;
          elements.fullApply.disabled = !elements.fullConfirm.checked
            || state.lastFullPreviewWarehouse !== selectedWarehouseId();
        }
        return;
      }
      setNotice(elements.fullNotice, 'info', t.requestAccepted || 'Task accepted');
      pollFullStatus(500);
    } catch (error) {
      setNotice(elements.fullNotice, 'error', error.message || t.networkError || 'Request failed');
    } finally {
      if (!state.fullRunning) {
        elements.fullPreview.disabled = false;
        elements.fullApply.disabled = !elements.fullConfirm.checked
          || state.lastFullPreviewWarehouse !== selectedWarehouseId();
      }
    }
  }

  document.querySelectorAll('[data-lps-ap-tab]').forEach((tab) => {
    tab.addEventListener('click', () => {
      const selected = tab.dataset.lpsApTab;
      document.querySelectorAll('[data-lps-ap-tab]').forEach((item) => {
        const active = item.dataset.lpsApTab === selected;
        item.classList.toggle('nav-tab-active', active);
        item.setAttribute('aria-selected', active ? 'true' : 'false');
      });
      document.querySelectorAll('[data-lps-ap-panel]').forEach((panel) => {
        panel.hidden = panel.dataset.lpsApPanel !== selected;
      });
    });
  });

  elements.warehouse.addEventListener('change', () => {
    if (elements.warehouse.value) window.localStorage.setItem('lpsAccountingPriceWarehouse', elements.warehouse.value);
    invalidateSinglePreview();
    state.lastFullPreviewWarehouse = 0;
    elements.fullApply.disabled = true;
  });
  elements.warehouseManual.addEventListener('input', () => {
    invalidateSinglePreview();
    state.lastFullPreviewWarehouse = 0;
    elements.fullApply.disabled = true;
  });
  elements.sku.addEventListener('input', invalidateSinglePreview);
  elements.singlePreview.addEventListener('click', () => runSingle(true));
  elements.singleApply.addEventListener('click', () => runSingle(false));
  elements.singleConfirm.addEventListener('change', () => {
    elements.singleApply.disabled = !elements.singleConfirm.checked || state.lastPreviewKey !== singleKey();
  });
  elements.fullPreview.addEventListener('click', () => startFull(true));
  elements.fullApply.addEventListener('click', () => startFull(false));
  elements.fullConfirm.addEventListener('change', () => {
    elements.fullApply.disabled = !elements.fullConfirm.checked
      || state.fullRunning
      || state.lastFullPreviewWarehouse !== selectedWarehouseId();
  });

  elements.copySkus.addEventListener('click', async () => {
    const skus = Array.from(new Set(warningRows().map((row) => row.sku).filter(Boolean)));
    if (!skus.length) return window.alert(t.exportEmpty || 'Nothing to copy');
    await navigator.clipboard.writeText(skus.join('\n'));
    window.alert(t.copyDone || 'Copied');
  });
  elements.exportJson.addEventListener('click', () => {
    if (!state.warnings.length) return window.alert(t.exportEmpty || 'Nothing to export');
    download(JSON.stringify({ warningsTruncated: state.warningsTruncated, warnings: state.warnings }, null, 2), 'application/json;charset=utf-8', 'folio-accounting-price-warnings.json');
  });
  elements.exportCsv.addEventListener('click', () => {
    const rows = warningRows();
    if (!rows.length) return window.alert(t.exportEmpty || 'Nothing to export');
    const headers = Object.keys(rows[0]);
    const csv = [headers.map(csvValue).join(';')]
      .concat(rows.map((row) => headers.map((header) => csvValue(row[header])).join(';')))
      .join('\r\n');
    download(`\ufeff${csv}`, 'text/csv;charset=utf-8', 'folio-accounting-price-warnings.csv');
  });

  loadWarehouses();
  pollFullStatus(0);
})();
