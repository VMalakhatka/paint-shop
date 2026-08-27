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
    dashboard: document.getElementById('lps-ap-campaign-dashboard')
  };
  if (!elements.start || !elements.dashboard) return;

  let pollTimer = null;
  let campaignActive = false;
  let selectedSnapshotState = '';
  let snapshotReportRequest = 0;

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
    previous.addEventListener('click', () => loadSnapshotReport(target, report.state, Number(report.page) - 1));
    const pageText = node('span', '', interpolate(t.pageOf || 'Page %1$d of %2$d', [report.page, report.pages]));
    const next = node('button', 'button', t.nextPage || 'Next');
    next.type = 'button';
    next.disabled = Number(report.page || 1) >= Number(report.pages || 1);
    next.addEventListener('click', () => loadSnapshotReport(target, report.state, Number(report.page) + 1));
    pagination.append(previous, pageText, next);
    target.append(pagination);
  }

  async function loadSnapshotReport(target, verificationState, page) {
    const requestId = ++snapshotReportRequest;
    target.replaceChildren(node('p', 'description', t.loading || 'Loading...'));
    try {
      const report = await request('campaign_snapshot_items', {
        verificationState,
        page: Math.max(1, Number(page || 1)),
        perPage: 50
      });
      if (requestId !== snapshotReportRequest || selectedSnapshotState !== verificationState) return;
      renderSnapshotItems(target, report);
    } catch (error) {
      if (requestId !== snapshotReportRequest) return;
      target.replaceChildren(node('div', 'notice notice-error inline', error.message || t.requestFailed || 'Request failed'));
    }
  }

  function renderSnapshotReportControls(counts) {
    const states = ['NEW', 'DIRTY', 'FAILED', 'REMOVED'];
    const available = states.filter((state) => Number(counts?.[state] || 0) > 0);
    const section = node('section', 'lps-ap-state-section lps-ap-snapshot-report');
    section.append(node('h3', '', t.stateReport || 'Snapshot state report'));
    section.append(node('p', 'description', t.stateReportDescription || 'Open a state to view its products.'));

    const actions = node('div', 'lps-ap-snapshot-report-actions');
    const results = node('div', 'lps-ap-snapshot-report-results');
    const exportLink = node('a', 'button', t.exportState || 'Export CSV');
    exportLink.hidden = true;

    const activate = (state) => {
      selectedSnapshotState = state;
      actions.querySelectorAll('button[data-state]').forEach((button) => {
        button.classList.toggle('button-primary', button.dataset.state === state);
      });
      if (config.snapshotReportExportUrl) {
        const url = new URL(config.snapshotReportExportUrl, window.location.href);
        url.searchParams.set('verification_state', state);
        exportLink.href = url.toString();
        exportLink.hidden = false;
      }
      loadSnapshotReport(results, state, 1);
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
    section.append(actions, results);

    const initial = available.includes(selectedSnapshotState) ? selectedSnapshotState : available[0];
    if (initial) window.setTimeout(() => activate(initial), 0);
    else results.append(node('p', 'description', t.noStateItems || 'No products'));
    return section;
  }

  function renderReports(reports) {
    const section = node('section', 'lps-ap-state-section');
    section.append(node('h3', '', t.reports || 'Reports'));
    if (!Array.isArray(reports) || !reports.length) {
      section.append(node('p', 'description', t.noReports || 'No reports'));
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
        node('td', '', report.error || '—'),
        details
      );
      body.append(row);
    });
    table.append(head, body);
    wrap.append(table);
    section.append(wrap);
    return section;
  }

  function renderWarnings(warnings, truncated) {
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
    [t.sku, t.reason, t.message, t.details].forEach((label) => header.append(node('th', '', label)));
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
      if (String(warning?.code || '').toUpperCase() === 'NEGATIVE_CHRONOLOGICAL_STOCK') {
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
      }
      row.append(
        node('td', '', sku || '—'),
        node('td', '', warning?.code || '—'),
        messageCell,
        detailCell
      );
      body.append(row);
    });
    table.append(head, body);
    wrap.append(table);
    section.append(wrap);
    return section;
  }

  function render(state) {
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
    const overview = node('div', 'lps-ap-campaign-overview');
    overview.append(
      card(t.status || 'Status', statusLabel(state.status), state.status === 'COMPLETED' ? 'success' : ''),
      card(t.phase || 'Phase', phaseLabel(visiblePhase)),
      card(t.warehouse || 'Warehouse', state.currentWarehouseId || '—'),
      card(t.processed || 'Processed', integer.format(Number(state.processedSkus || 0))),
      card(t.batches || 'Batches', integer.format(Number(state.successfulBatches || 0))),
      card(t.warnings || 'Warnings', integer.format(Number(state.warningCount || 0)), Number(state.warningCount || 0) ? 'warning' : ''),
      card(t.errors || 'Errors', integer.format(Number(state.errorCount || 0)), Number(state.errorCount || 0) ? 'error' : ''),
      card(t.failedWarehouses || 'Failed warehouses', integer.format(Number(state.failedWarehouses || 0)), Number(state.failedWarehouses || 0) ? 'error' : '')
    );
    elements.dashboard.append(overview);

    if (state.message || state.error) {
      const message = node('div', `lps-ap-result-notice is-${state.error ? 'error' : 'info'}`);
      message.append(node('p', '', state.error || state.message));
      elements.dashboard.append(message);
    }

    const rangeProgress = Number(state.range?.progressPercent);
    if (campaignActive && Number.isFinite(rangeProgress)) {
      const progress = node('div', 'lps-ap-progress-track');
      progress.setAttribute('role', 'progressbar');
      progress.setAttribute('aria-valuemin', '0');
      progress.setAttribute('aria-valuemax', '100');
      progress.setAttribute('aria-valuenow', String(Math.max(0, Math.min(100, rangeProgress))));
      const bar = node('span');
      bar.style.width = `${Math.max(0, Math.min(100, rangeProgress))}%`;
      progress.append(bar);
      elements.dashboard.append(progress);
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
  loadStatus();
}());
