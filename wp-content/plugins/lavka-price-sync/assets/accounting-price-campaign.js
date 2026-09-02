(function () {
  'use strict';

  const root = document.getElementById('lps-accounting-prices');
  const config = window.LPS_ACCOUNTING_PRICE_CAMPAIGN || {};
  if (!root || !config.ajaxUrl) return;

  const t = config.i18n || {};
  const locale = document.documentElement.lang || 'uk-UA';
  const integer = new Intl.NumberFormat(locale, { maximumFractionDigits: 0 });
  const elements = {
    warehouse: document.getElementById('lps-ap-warehouse'),
    warehouseManual: document.getElementById('lps-ap-warehouse-manual'),
    confirm: document.getElementById('lps-ap-campaign-confirm'),
    start: document.getElementById('lps-ap-campaign-start'),
    stop: document.getElementById('lps-ap-campaign-stop'),
    notice: document.getElementById('lps-ap-campaign-notice'),
    dashboard: document.getElementById('lps-ap-campaign-dashboard'),
    overviewRefresh: document.getElementById('lps-ap-warehouse-overview-refresh'),
    overviewNotice: document.getElementById('lps-ap-warehouse-overview-notice'),
    overviewSummary: document.getElementById('lps-ap-warehouse-overview-summary'),
    overviewTable: document.getElementById('lps-ap-warehouse-overview-table'),
    overviewDetails: document.getElementById('lps-ap-warehouse-overview-details')
  };
  if (!elements.start || !elements.dashboard) return;

  let pollTimer = null;
  let campaignActive = false;
  let selectedSnapshotState = '';
  let selectedSnapshotScope = '';
  let snapshotReportRequest = 0;
  const openSnapshotReports = new Set();
  let batchReportOpen = false;

  function node(tag, className, value) {
    const item = document.createElement(tag);
    if (className) item.className = className;
    if (value !== undefined && value !== null) item.textContent = String(value);
    return item;
  }

  function selectedWarehouseId() {
    if (elements.warehouseManual && !elements.warehouseManual.hidden) {
      return Number.parseInt(elements.warehouseManual.value, 10) || 0;
    }
    return Number.parseInt(elements.warehouse?.value || '', 10) || 0;
  }

  function statusLabel(status) {
    return t.statusLabels?.[status] || status || '—';
  }

  function phaseLabel(phase) {
    return t.phaseLabels?.[phase] || phase || '—';
  }

  function showNotice(kind, message) {
    elements.notice.hidden = false;
    elements.notice.className = `lps-ap-result-notice is-${kind}`;
    elements.notice.replaceChildren(node('p', '', message));
  }

  function hideNotice() {
    elements.notice.hidden = true;
    elements.notice.replaceChildren();
  }

  async function request(operation, payload) {
    const form = new FormData();
    form.append('action', 'lps_accounting_prices');
    form.append('_wpnonce', config.nonce || '');
    form.append('operation', operation);
    Object.entries(payload || {}).forEach(([key, value]) => form.append(key, String(value)));

    const response = await fetch(config.ajaxUrl, {
      method: 'POST',
      credentials: 'same-origin',
      body: form
    });
    const contentType = (response.headers.get('content-type') || '').toLowerCase();
    if (!contentType.includes('application/json')) {
      const raw = await response.text();
      throw new Error(`${t.requestFailed || 'Request failed'} HTTP ${response.status}: ${raw.slice(0, 240)}`);
    }
    const envelope = await response.json();
    if (!envelope?.success) throw new Error(envelope?.data?.message || t.requestFailed || 'Request failed');
    return envelope.data || {};
  }

  function card(label, value, tone) {
    const item = node('div', `lps-ap-campaign-card${tone ? ` is-${tone}` : ''}`);
    item.append(node('span', '', label), node('strong', '', value));
    return item;
  }

  function renderCounts(title, counts) {
    const section = node('section', 'lps-ap-state-section');
    section.append(node('h3', '', title));
    const grid = node('div', 'lps-ap-counters');
    ['UNVERIFIED', 'NEW', 'DIRTY', 'FAILED', 'VERIFIED', 'REMOVED'].forEach((state) => {
      grid.append(card(state, integer.format(Number(counts?.[state] || 0)), state.toLowerCase()));
    });
    section.append(grid);
    return section;
  }

  function interpolate(template, values) {
    return String(template || '').replace(/%(\d+)\$d/g, (_, position) => String(values[Number(position) - 1] ?? ''));
  }

  function display(value) {
    return value === 0 || value ? String(value) : '—';
  }

  function formatDateTime(value) {
    if (!value) return '—';
    const parsed = new Date(String(value).replace(' ', 'T'));
    return Number.isNaN(parsed.getTime())
      ? String(value)
      : new Intl.DateTimeFormat(locale, { dateStyle: 'short', timeStyle: 'short' }).format(parsed);
  }

  function renderSnapshotItems(target, report) {
    target.replaceChildren();
    if (!report?.ok) {
      target.append(node('div', 'notice notice-error inline', report?.message || t.requestFailed || 'Request failed'));
      return;
    }
    if (!Array.isArray(report.items) || !report.items.length) {
      target.append(node('p', 'description', t.noStateItems || 'No products'));
      return;
    }

    const wrap = node('div', 'lps-ap-table-scroll');
    const table = node('table', 'widefat striped lps-ap-snapshot-report-table');
    const head = node('thead');
    const header = node('tr');
    [t.sku, t.product, t.state, t.stateReason, t.lastError, t.movements, t.movementPeriod, t.lastObserved, t.lastRecalculated, t.latestChange]
      .forEach((label) => header.append(node('th', '', label)));
    head.append(header);
    const body = node('tbody');
    report.items.forEach((item) => {
      const change = item?.latest_change && typeof item.latest_change === 'object' ? item.latest_change : {};
      const movementPeriod = [item?.first_movement_date, item?.last_movement_date].filter(Boolean).join(' – ') || '—';
      const changeText = [change.change_type, change.detected_at].filter(Boolean).join(' · ') || '—';
      const reason = t.stateReasons?.[item?.verification_state] || item?.verification_state || '—';
      const row = node('tr');
      row.append(
        node('td', 'lps-ap-snapshot-sku', display(item?.sku)),
        node('td', '', display(item?.product_name)),
        node('td', '', display(item?.verification_state)),
        node('td', '', reason),
        node('td', item?.last_error ? 'is-error-text' : '', display(item?.last_error)),
        node('td', '', integer.format(Number(item?.movement_count || 0))),
        node('td', '', movementPeriod),
        node('td', '', display(item?.last_observed_at)),
        node('td', '', display(item?.applied_at)),
        node('td', '', changeText)
      );
      body.append(row);
    });
    table.append(head, body);
    wrap.append(table);
    target.append(wrap);

    const pagination = node('div', 'tablenav bottom lps-ap-snapshot-pagination');
    const previous = node('button', 'button', t.previousPage || 'Previous');
    previous.type = 'button';
    previous.disabled = Number(report.page || 1) <= 1;
    const scope = { warehouseId: report.warehouseId || 0, sourceDatabase: report.sourceDatabase || '' };
    previous.addEventListener('click', () => loadSnapshotReport(target, report.state, Number(report.page) - 1, scope));
    const pageText = node('span', '', interpolate(t.pageOf || 'Page %1$d of %2$d', [report.page, report.pages]));
    const next = node('button', 'button', t.nextPage || 'Next');
    next.type = 'button';
    next.disabled = Number(report.page || 1) >= Number(report.pages || 1);
    next.addEventListener('click', () => loadSnapshotReport(target, report.state, Number(report.page) + 1, scope));
    pagination.append(previous, pageText, next);
    target.append(pagination);
  }

  async function loadSnapshotReport(target, verificationState, page, scope) {
    scope = scope || {};
    const scopeKey = `${Number(scope.warehouseId || 0)}|${verificationState}`;
    const requestId = ++snapshotReportRequest;
    target.replaceChildren(node('p', 'description', t.loading || 'Loading...'));
    try {
      const report = await request('campaign_snapshot_items', {
        verificationState,
        page: Math.max(1, Number(page || 1)),
        perPage: 50,
        warehouseId: Number(scope.warehouseId || 0),
        sourceDatabase: scope.sourceDatabase || ''
      });
      if (requestId !== snapshotReportRequest || selectedSnapshotScope !== scopeKey) return;
      renderSnapshotItems(target, report);
    } catch (error) {
      if (requestId !== snapshotReportRequest) return;
      target.replaceChildren(node('div', 'notice notice-error inline', error.message || t.requestFailed || 'Request failed'));
    }
  }

  function renderSnapshotReportControls(counts, scope, initialState) {
    scope = scope || {};
    const reportKey = Number(scope.warehouseId || 0) > 0
      ? `warehouse:${Number(scope.warehouseId)}`
      : 'campaign';
    const states = ['UNVERIFIED', 'NEW', 'DIRTY', 'FAILED', 'REMOVED'];
    const available = states.filter((state) => Number(counts?.[state] || 0) > 0);
    const section = node('details', 'lps-ap-state-section lps-ap-snapshot-report lps-ap-collapsible-report');
    const content = node('div', 'lps-ap-collapsible-report-content');
    section.append(node('summary', '', t.stateReport || 'Snapshot state report'));
    content.append(node('p', 'description', t.stateReportDescription || 'Open a state to view its products.'));

    const actions = node('div', 'lps-ap-snapshot-report-actions');
    const results = node('div', 'lps-ap-snapshot-report-results');
    const exportLink = node('a', 'button', t.exportState || 'Export CSV');
    exportLink.hidden = true;

    const activate = (state) => {
      selectedSnapshotState = state;
      selectedSnapshotScope = `${Number(scope.warehouseId || 0)}|${state}`;
      actions.querySelectorAll('button[data-state]').forEach((button) => {
        button.classList.toggle('button-primary', button.dataset.state === state);
      });
      if (config.snapshotReportExportUrl) {
        const url = new URL(config.snapshotReportExportUrl, window.location.href);
        url.searchParams.set('verification_state', state);
        if (scope.warehouseId) url.searchParams.set('warehouse_id', String(scope.warehouseId));
        if (scope.sourceDatabase) url.searchParams.set('source_database', String(scope.sourceDatabase));
        exportLink.href = url.toString();
        exportLink.hidden = false;
      }
      loadSnapshotReport(results, state, 1, scope);
    };

    states.forEach((state) => {
      const count = Number(counts?.[state] || 0);
      const button = node('button', 'button', `${t.viewState || 'View'} ${state} (${integer.format(count)})`);
      button.type = 'button';
      button.dataset.state = state;
      button.addEventListener('click', () => activate(state));
      actions.append(button);
    });
    actions.append(exportLink);
    content.append(actions, results);
    section.append(content);

    const initial = states.includes(initialState)
      ? initialState
      : (available.includes(selectedSnapshotState) ? selectedSnapshotState : available[0]);
    let initialized = false;
    const initialize = () => {
      if (initialized) return;
      initialized = true;
      if (initial) activate(initial);
      else results.append(node('p', 'description', t.noStateItems || 'No products'));
    };
    section.addEventListener('toggle', () => {
      if (section.open) {
        openSnapshotReports.add(reportKey);
        initialize();
      } else {
        openSnapshotReports.delete(reportKey);
      }
    });
    if (states.includes(initialState)) {
      openSnapshotReports.add(reportKey);
    }
    if (openSnapshotReports.has(reportKey)) {
      section.open = true;
      window.setTimeout(initialize, 0);
    }
    return section;
  }

  function renderReports(reports) {
    const section = node('details', 'lps-ap-state-section lps-ap-collapsible-report');
    const content = node('div', 'lps-ap-collapsible-report-content');
    section.append(node('summary', '', t.reports || 'Reports'));
    section.open = batchReportOpen;
    section.addEventListener('toggle', () => {
      batchReportOpen = section.open;
    });
    if (!Array.isArray(reports) || !reports.length) {
      content.append(node('p', 'description', t.noReports || 'No reports'));
      section.append(content);
      return section;
    }

    const wrap = node('div', 'lps-ap-table-scroll');
    const table = node('table', 'widefat striped');
    const head = node('thead');
    const header = node('tr');
    [t.when, t.warehouse, t.result, t.skuCount, t.duration, t.reason, t.details].forEach((label) => header.append(node('th', '', label)));
    head.append(header);
    const body = node('tbody');
    reports.slice().reverse().forEach((report) => {
      const row = node('tr');
      const details = node('td');
      const payload = report.failed_chunk || report;
      const disclosure = node('details');
      disclosure.append(node('summary', '', t.details || 'Details'), node('pre', '', JSON.stringify(payload, null, 2)));
      details.append(disclosure);
      row.append(
        node('td', '', report.completed_at || '—'),
        node('td', '', report.warehouse_id || '—'),
        node('td', '', statusLabel(String(report.status || '').toUpperCase())),
        node('td', '', integer.format(Number(report.sku_count || 0))),
        node('td', '', report.duration_seconds ? `${integer.format(Number(report.duration_seconds))} ${t.seconds || 'sec.'}` : '—'),
        node('td', '', report.error || report.message || '—'),
        details
      );
      body.append(row);
    });
    table.append(head, body);
    wrap.append(table);
    content.append(wrap);
    section.append(content);
    return section;
  }

  function renderWarnings(warnings, truncated, showWarehouse) {
    const section = node('section', 'lps-ap-state-section');
    section.append(node('h3', '', t.warningReport || 'Warnings'));
    if (!Array.isArray(warnings) || !warnings.length) {
      section.append(node('p', 'description', t.noWarnings || 'No warnings'));
      return section;
    }
    if (truncated) section.append(node('p', 'notice notice-warning inline', t.warningsTruncated || 'Only part of the warnings is shown.'));

    const wrap = node('div', 'lps-ap-table-scroll');
    const table = node('table', 'widefat striped');
    const head = node('thead');
    const header = node('tr');
    const headings = showWarehouse
      ? [t.warehouse, t.sku, t.reason, t.message, t.recordedAt, t.details]
      : [t.sku, t.reason, t.message, t.details];
    headings.forEach((label) => header.append(node('th', '', label)));
    head.append(header);
    const body = node('tbody');
    warnings.forEach((warning) => {
      const details = warning?.details && typeof warning.details === 'object' ? warning.details : {};
      const sku = warning?.sku || details.sku || details.art || details.inputArt || '';
      const detailCell = node('td');
      const disclosure = node('details');
      disclosure.append(node('summary', '', t.details || 'Details'), node('pre', '', JSON.stringify(details, null, 2)));
      detailCell.append(disclosure);
      const severity = String(warning?.severity || '').toLowerCase();
      const rowClass = severity === 'error' ? 'is-error' : (details.skipped ? 'is-warning' : '');
      const row = node('tr', rowClass ? `lps-ap-batch-row ${rowClass}` : '');
      const messageCell = node('td');
      messageCell.append(node('p', '', warning?.message || '—'));
      const warningCode = String(warning?.code || '').toUpperCase();
      if (warningCode === 'NEGATIVE_CHRONOLOGICAL_STOCK') {
        const operation = details.operation && typeof details.operation === 'object' ? details.operation : {};
        const currentState = details.currentState && typeof details.currentState === 'object' ? details.currentState : {};
        const documentNumber = operation.documentNumber || operation.documentId || '';
        const document = [operation.documentType, documentNumber].filter(Boolean).join(' · ') || '—';
        const operationKind = String(operation.kind || '').toUpperCase();
        const operationLabel = operationKind === 'RECEIPT'
          ? t.receipt
          : (operationKind === 'EXPENSE' ? t.expense : (operation.kind || t.unknownOperation));
        const operationValue = [operationLabel, operation.quantity].filter((value) => value === 0 || value).join(' · ') || '—';
        const movementPosition = details.movementPosition || details.movementCount
          ? `${display(details.movementPosition)} / ${display(details.movementCount)}`
          : '—';
        const explanation = node('div', 'lps-ap-negative-diagnostic');
        explanation.append(node('p', 'lps-ap-negative-explanation', t.negativeStockExplanation || 'Chronological stock became negative.'));
        const grid = node('dl', 'lps-ap-negative-grid');
        [
          [t.document, document],
          [t.problemDate, operation.documentDate || details.problemDate],
          [t.warehouse, operation.warehouseId || details.warehouseId],
          [t.initialQuantity, details.initialQuantity],
          [t.movementRecord, operation.recno],
          [t.beforeOperation, details.quantityBefore],
          [t.operationQuantity, operationValue],
          [t.afterOperation, details.quantityAfter],
          [t.shortage, details.shortageQuantity],
          [t.movementPosition, movementPosition],
          [t.currentPhysicalQuantity, currentState.physicalQuantity],
          [t.currentAccountingQuantity, currentState.accountingQuantity]
        ].forEach(([label, value]) => {
          const item = node('div');
          item.append(node('dt', '', label || '—'), node('dd', '', display(value)));
          grid.append(item);
        });
        explanation.append(grid);
        messageCell.append(explanation);
      } else if (['ZERO_ACCOUNTING_DENOMINATOR', 'ZERO_ACCOUNTING_QUANTITY_DENOMINATOR'].includes(warningCode)) {
        const operation = details.operation && typeof details.operation === 'object' ? details.operation : {};
        const documentNumber = operation.documentNumber || details.documentNumber || operation.documentId || details.documentId || '';
        const documentType = operation.documentType || details.documentType || '';
        const document = [documentType, documentNumber].filter(Boolean).join(' · ') || '—';
        const explanation = node('div', 'lps-ap-negative-diagnostic');
        explanation.append(node('p', 'lps-ap-negative-explanation', t.zeroDenominatorExplanation || 'The accounting formula denominator is zero. This SKU was rolled back and skipped.'));
        const grid = node('dl', 'lps-ap-negative-grid');
        [
          [t.document, document],
          [t.problemDate, details.operationDate || operation.documentDate || details.problemDate],
          [t.movementRecord, details.recno || details.RECNO || operation.recno || operation.RECNO],
          [t.formula, details.formula],
          [t.numerator, details.numerator],
          [t.denominator, details.denominator],
          [t.beforeOperation, details.quantityBefore || operation.quantityBefore],
          [t.operationQuantity, details.movementQuantity || details.operationQuantity || operation.quantity]
        ].forEach(([label, value]) => {
          const item = node('div');
          item.append(node('dt', '', label || '—'), node('dd', '', display(value)));
          grid.append(item);
        });
        explanation.append(grid);
        messageCell.append(explanation);
      }
      if (showWarehouse) {
        row.append(node('td', '', warning?.warehouseName || warning?.warehouseId || '—'));
      }
      row.append(node('td', '', sku || '—'), node('td', '', warning?.code || '—'), messageCell);
      if (showWarehouse) row.append(node('td', '', formatDateTime(warning?.recordedAt)));
      row.append(detailCell);
      body.append(row);
    });
    table.append(head, body);
    wrap.append(table);
    section.append(wrap);
    return section;
  }

  function showWarehouseState(row, verificationState) {
    elements.overviewDetails.replaceChildren(
      renderSnapshotReportControls(
        row.counts || {},
        { warehouseId: row.warehouseId, sourceDatabase: row.sourceDatabase || '' },
        verificationState
      )
    );
    elements.overviewDetails.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
  }

  function renderWarehouseDiagnostics(report) {
    elements.overviewDetails.replaceChildren();
    const section = node('section', 'lps-ap-state-section lps-ap-warehouse-diagnostics');
    section.append(node('h3', '', t.warehouseDiagnostics || 'Latest warehouse diagnostics'));
    if (!report?.ok) {
      section.append(node('div', 'notice notice-error inline', report?.message || t.requestFailed || 'Request failed'));
    } else if (!Array.isArray(report.items) || !report.items.length) {
      section.append(node('p', 'description', t.noWarehouseDiagnostics || 'No diagnostics were recorded.'));
    } else {
      section.append(renderWarnings(report.items, Boolean(report.truncated), true));
      const pagination = node('div', 'tablenav bottom lps-ap-snapshot-pagination');
      const previous = node('button', 'button', t.previousPage || 'Previous');
      previous.type = 'button';
      previous.disabled = Number(report.page || 1) <= 1;
      previous.addEventListener('click', () => loadWarehouseDiagnostics(report.warehouseId, report.kind, Number(report.page) - 1));
      const pageText = node('span', '', interpolate(t.pageOf || 'Page %1$d of %2$d', [report.page, report.pages]));
      const next = node('button', 'button', t.nextPage || 'Next');
      next.type = 'button';
      next.disabled = Number(report.page || 1) >= Number(report.pages || 1);
      next.addEventListener('click', () => loadWarehouseDiagnostics(report.warehouseId, report.kind, Number(report.page) + 1));
      pagination.append(previous, pageText, next);
      section.append(pagination);
    }
    elements.overviewDetails.append(section);
  }

  async function loadWarehouseDiagnostics(warehouseId, kind, page) {
    if (!elements.overviewDetails) return;
    elements.overviewDetails.replaceChildren(node('p', 'description', t.loading || 'Loading...'));
    try {
      const report = await request('campaign_warehouse_diagnostics', {
        warehouseId: Number(warehouseId || 0),
        kind: kind || 'all',
        page: Math.max(1, Number(page || 1)),
        perPage: 50
      });
      renderWarehouseDiagnostics(report);
    } catch (error) {
      elements.overviewDetails.replaceChildren(node('div', 'notice notice-error inline', error.message || t.requestFailed || 'Request failed'));
    }
  }

  function overviewStateButton(row, state) {
    const count = Number(row?.counts?.[state] || 0);
    if (!['UNVERIFIED', 'NEW', 'DIRTY', 'FAILED', 'REMOVED'].includes(state) || count < 1) {
      return node('span', '', integer.format(count));
    }
    const button = node('button', 'button-link lps-ap-count-link', integer.format(count));
    button.type = 'button';
    button.title = `${t.viewProducts || 'View products'}: ${state}`;
    button.addEventListener('click', () => showWarehouseState(row, state));
    return button;
  }

  function renderWarehouseOverview(overview) {
    if (!elements.overviewSummary || !elements.overviewTable) return;
    elements.overviewSummary.replaceChildren();
    elements.overviewTable.replaceChildren();
    if (!overview?.ok) {
      elements.overviewTable.append(node('div', 'notice notice-error inline', overview?.message || t.requestFailed || 'Request failed'));
      return;
    }

    const summary = overview.summary || {};
    elements.overviewSummary.append(
      card(t.warehousesTotal || 'Warehouses', integer.format(Number(summary.warehouses || 0))),
      card(t.notProcessed || 'Never processed', integer.format(Number(summary.notProcessed || 0)), Number(summary.notProcessed || 0) ? 'warning' : ''),
      card(t.warehousesWithErrors || 'Warehouses with errors', integer.format(Number(summary.withErrors || 0)), Number(summary.withErrors || 0) ? 'error' : ''),
      card(t.negativeStockItems || 'Negative stock cases', integer.format(Number(summary.negativeStock || 0)), Number(summary.negativeStock || 0) ? 'error' : ''),
      card(t.warnings || 'Warnings', integer.format(Number(summary.warnings || 0)), Number(summary.warnings || 0) ? 'warning' : '')
    );

    if (elements.overviewNotice) {
      if (!overview.directoryAvailable) {
        elements.overviewNotice.hidden = false;
        elements.overviewNotice.className = 'lps-ap-result-notice is-warning';
        elements.overviewNotice.replaceChildren(node('p', '', t.warehouseDirectoryUnavailable || overview.directoryMessage || 'Warehouse directory unavailable'));
      } else {
        elements.overviewNotice.hidden = true;
        elements.overviewNotice.replaceChildren();
      }
    }

    const actions = node('div', 'lps-ap-warehouse-overview-actions');
    const negativeButton = node('button', 'button', t.allNegativeStock || 'View all negative stock');
    negativeButton.type = 'button';
    negativeButton.addEventListener('click', () => loadWarehouseDiagnostics(0, 'negative', 1));
    const errorsButton = node('button', 'button', t.allErrors || 'View all errors');
    errorsButton.type = 'button';
    errorsButton.addEventListener('click', () => loadWarehouseDiagnostics(0, 'errors', 1));
    const warningsButton = node('button', 'button', t.allWarnings || 'View all warnings');
    warningsButton.type = 'button';
    warningsButton.addEventListener('click', () => loadWarehouseDiagnostics(0, 'warnings', 1));
    actions.append(negativeButton, warningsButton, errorsButton);
    elements.overviewTable.append(actions);

    const wrap = node('div', 'lps-ap-table-scroll');
    const table = node('table', 'widefat striped lps-ap-warehouse-overview-table');
    const head = node('thead');
    const header = node('tr');
    [
      t.warehouse, t.processingState, t.lastProcessing, t.lastSnapshot,
      'UNVERIFIED', 'NEW', 'DIRTY', 'FAILED', 'VERIFIED', 'REMOVED',
      t.negativeStock, t.warnings, t.errors, t.actions
    ].forEach((label) => header.append(node('th', '', label)));
    head.append(header);
    const body = node('tbody');

    (overview.rows || []).forEach((row) => {
      const tr = node('tr', row.hasEverProcessed ? '' : 'lps-ap-warehouse-not-processed');
      const warehouse = node('td', 'lps-ap-warehouse-name');
      warehouse.append(node('strong', '', `${row.warehouseId} — ${row.warehouseName}`));
      if (row.lastError) warehouse.append(node('small', 'is-error-text', row.lastError));
      const status = node('td');
      status.append(node('span', `lps-ap-status lps-ap-status-${String(row.status || '').toLowerCase()}`, statusLabel(row.status)));
      const negative = node('td');
      if (Number(row.negativeCount || 0) > 0) {
        const button = node('button', 'button-link is-error-text', integer.format(Number(row.negativeCount)));
        button.type = 'button';
        button.addEventListener('click', () => loadWarehouseDiagnostics(row.warehouseId, 'negative', 1));
        negative.append(button);
      } else negative.append('0');
      const errors = node('td');
      if (Number(row.errorCount || 0) > 0) {
        const button = node('button', 'button-link is-error-text', integer.format(Number(row.errorCount)));
        button.type = 'button';
        button.addEventListener('click', () => loadWarehouseDiagnostics(row.warehouseId, 'errors', 1));
        errors.append(button);
      } else errors.append('0');
      const warnings = node('td');
      if (Number(row.warningCount || 0) > 0) {
        const button = node('button', 'button-link', integer.format(Number(row.warningCount)));
        button.type = 'button';
        button.addEventListener('click', () => loadWarehouseDiagnostics(row.warehouseId, 'warnings', 1));
        warnings.append(button);
      } else warnings.append('0');
      const rowActions = node('td', 'lps-ap-row-actions');
      if (Number(row.errorCount || 0) > 0) {
        const button = node('button', 'button button-small', t.viewErrors || 'View errors');
        button.type = 'button';
        button.addEventListener('click', () => loadWarehouseDiagnostics(row.warehouseId, 'errors', 1));
        rowActions.append(button);
      }
      if (Number(row.negativeCount || 0) > 0) {
        const button = node('button', 'button button-small', t.viewNegativeStock || 'View negative stock');
        button.type = 'button';
        button.addEventListener('click', () => loadWarehouseDiagnostics(row.warehouseId, 'negative', 1));
        rowActions.append(button);
      }
      if (Number(row.warningCount || 0) > 0) {
        const button = node('button', 'button button-small', t.viewWarnings || 'View warnings');
        button.type = 'button';
        button.addEventListener('click', () => loadWarehouseDiagnostics(row.warehouseId, 'warnings', 1));
        rowActions.append(button);
      }
      tr.append(
        warehouse,
        status,
        node('td', '', formatDateTime(row.lastProcessedAt || row.lastAppliedAt)),
        node('td', '', formatDateTime(row.activeSnapshot?.completed_at || row.latestAttempt?.completed_at)),
        ...['UNVERIFIED', 'NEW', 'DIRTY', 'FAILED', 'VERIFIED', 'REMOVED'].map((state) => {
          const cell = node('td', `lps-ap-state-count is-${state.toLowerCase()}`);
          cell.append(overviewStateButton(row, state));
          return cell;
        }),
        negative,
        warnings,
        errors,
        rowActions
      );
      body.append(tr);
    });
    table.append(head, body);
    wrap.append(table);
    elements.overviewTable.append(wrap, node('p', 'description', t.legacyDiagnosticsNotice || 'Older detailed diagnostics may be unavailable.'));
  }

  async function loadWarehouseOverview() {
    if (!elements.overviewTable) return;
    if (elements.overviewRefresh) elements.overviewRefresh.disabled = true;
    elements.overviewTable.replaceChildren(node('p', 'description', t.warehouseOverviewLoading || t.loading || 'Loading...'));
    try {
      renderWarehouseOverview(await request('campaign_warehouse_overview'));
    } catch (error) {
      elements.overviewTable.replaceChildren(node('div', 'notice notice-error inline', error.message || t.requestFailed || 'Request failed'));
    } finally {
      if (elements.overviewRefresh) elements.overviewRefresh.disabled = false;
    }
  }

  function render(state) {
    const wasActive = campaignActive;
    campaignActive = Boolean(state?.active);
    elements.start.disabled = campaignActive || !elements.confirm.checked || selectedWarehouseId() < 1;
    elements.stop.disabled = !campaignActive || Boolean(state?.stopRequested);
    elements.confirm.disabled = campaignActive;
    elements.dashboard.replaceChildren();

    if (!state?.campaignId && !campaignActive) {
      elements.dashboard.append(node('p', 'description', t.idle || 'Not started'));
      return;
    }

    const rangePhase = state.range?.running ? String(state.range?.phase || '').toUpperCase() : '';
    const snapshotPhase = state.snapshot?.running ? String(state.snapshot?.phase || '').toUpperCase() : '';
    const visiblePhase = rangePhase || snapshotPhase || state.phase;
    const rangeProcessed = Number(state.range?.skuProgressUnits || 0);
    const rangeTotal = Number(state.range?.skuTotalUnits || 0);
    const rangeCommitted = Number(state.range?.committedChunks || 0);
    const rangeWarnings = Number(state.range?.warningCount || 0);
    const rangePercent = Number(state.range?.skuProgressPercent || 0);
    const overview = node('div', 'lps-ap-campaign-overview');
    overview.append(
      card(t.status || 'Status', statusLabel(state.status), state.status === 'COMPLETED' ? 'success' : ''),
      card(t.phase || 'Phase', phaseLabel(visiblePhase)),
      card(t.warehouse || 'Warehouse', state.currentWarehouseId || '—'),
      card(t.processed || 'Processed', integer.format(Number(state.processedSkus || 0))),
      card(t.batches || 'Batches', integer.format(Number(state.successfulBatches || 0))),
      card(t.warnings || 'Warnings', integer.format(Number(state.warningCount || 0)), Number(state.warningCount || 0) ? 'warning' : ''),
      card(t.errors || 'Errors', integer.format(Number(state.errorCount || 0)), Number(state.errorCount || 0) ? 'error' : ''),
      card(t.failedWarehouses || 'Failed warehouses', integer.format(Number(state.failedWarehouses || 0)), Number(state.failedWarehouses || 0) ? 'error' : ''),
      card(t.skippedWarehouses || 'Skipped warehouses', integer.format(Number(state.skippedWarehouses || 0)), Number(state.skippedWarehouses || 0) ? 'warning' : '')
    );
    if (rangeTotal > 0) {
      overview.append(
        card(t.batchProgress || 'Current batch progress', `${integer.format(rangeProcessed)} / ${integer.format(rangeTotal)}`),
        card(t.currentSku || 'Current SKU', display(state.range?.currentArt)),
        card(t.committedSku || 'Successfully committed SKU', integer.format(rangeCommitted), rangeCommitted > 0 ? 'success' : ''),
        card(t.batchWarnings || 'Warnings in current batch', integer.format(rangeWarnings), rangeWarnings > 0 ? 'warning' : '')
      );
    }
    elements.dashboard.append(overview);

    if (state.message || state.error) {
      const message = node('div', `lps-ap-result-notice is-${state.error ? 'error' : 'info'}`);
      message.append(node('p', '', state.error || state.message));
      elements.dashboard.append(message);
    }

    if (campaignActive && rangeTotal > 0 && Number.isFinite(rangePercent)) {
      const progress = node('div', 'lps-ap-progress-track');
      progress.setAttribute('role', 'progressbar');
      progress.setAttribute('aria-valuemin', '0');
      progress.setAttribute('aria-valuemax', '100');
      progress.setAttribute('aria-valuenow', String(Math.max(0, Math.min(100, rangePercent))));
      const bar = node('span');
      bar.style.width = `${Math.max(0, Math.min(100, rangePercent))}%`;
      progress.append(bar);
      elements.dashboard.append(
        progress,
        node('p', 'description', `${integer.format(rangeProcessed)} / ${integer.format(rangeTotal)} · ${rangePercent.toLocaleString(locale, { maximumFractionDigits: 1 })}%`)
      );
    }

    if (Array.isArray(state.currentSkus) && state.currentSkus.length) {
      const batch = node('details', 'lps-ap-current-batch');
      batch.append(node('summary', '', `${t.currentBatch || 'Current batch'}: ${state.currentSkus.length}`));
      batch.append(node('pre', '', state.currentSkus.join('\n')));
      elements.dashboard.append(batch);
    }

    if (state.countsBefore && Object.keys(state.countsBefore).length) {
      elements.dashboard.append(renderCounts(t.statesBefore || 'States before', state.countsBefore));
    }
    if (state.countsAfter && Object.keys(state.countsAfter).length) {
      elements.dashboard.append(renderCounts(t.statesAfter || 'States after', state.countsAfter));
      elements.dashboard.append(renderSnapshotReportControls(state.countsAfter));
    }
    elements.dashboard.append(renderReports(state.reports));
    elements.dashboard.append(renderWarnings(state.warnings, state.warningsTruncated));
    if (wasActive && !campaignActive) loadWarehouseOverview();
  }

  function schedulePoll(delay) {
    window.clearTimeout(pollTimer);
    pollTimer = window.setTimeout(loadStatus, delay);
  }

  async function loadStatus() {
    try {
      const state = await request('campaign_status');
      render(state);
      hideNotice();
      if (state.active) schedulePoll(Number(config.pollInterval || 5000));
    } catch (error) {
      showNotice('error', error.message || t.requestFailed || 'Request failed');
      if (campaignActive) schedulePoll(Math.max(10000, Number(config.pollInterval || 5000)));
    }
  }

  async function startCampaign() {
    const warehouseId = selectedWarehouseId();
    if (!warehouseId) return showNotice('error', t.warehouseRequired || 'Select warehouse');
    if (!elements.confirm.checked) return showNotice('error', t.confirmationRequired || 'Confirmation required');
    if (!window.confirm(t.startConfirm || 'Start campaign?')) return;

    elements.start.disabled = true;
    hideNotice();
    try {
      const result = await request('campaign_start', { warehouseId, confirmApply: 1 });
      if (!result.ok) throw new Error(result.message || t.requestFailed || 'Request failed');
      render(result.state || {});
      schedulePoll(1000);
    } catch (error) {
      showNotice('error', error.message || t.requestFailed || 'Request failed');
      elements.start.disabled = !elements.confirm.checked;
    }
  }

  async function stopCampaign() {
    if (!window.confirm(t.stopConfirm || 'Stop safely?')) return;
    elements.stop.disabled = true;
    try {
      const state = await request('campaign_stop');
      render(state);
      showNotice('warning', t.stopRequested || 'Safe stop requested');
      schedulePoll(1000);
    } catch (error) {
      showNotice('error', error.message || t.requestFailed || 'Request failed');
      elements.stop.disabled = false;
    }
  }

  elements.confirm.addEventListener('change', () => {
    elements.start.disabled = campaignActive || !elements.confirm.checked || selectedWarehouseId() < 1;
  });
  elements.warehouse?.addEventListener('change', () => {
    elements.start.disabled = campaignActive || !elements.confirm.checked || selectedWarehouseId() < 1;
  });
  elements.warehouseManual?.addEventListener('input', () => {
    elements.start.disabled = campaignActive || !elements.confirm.checked || selectedWarehouseId() < 1;
  });
  elements.start.addEventListener('click', startCampaign);
  elements.stop.addEventListener('click', stopCampaign);
  elements.overviewRefresh?.addEventListener('click', loadWarehouseOverview);
  loadWarehouseOverview();
  loadStatus();
}());
