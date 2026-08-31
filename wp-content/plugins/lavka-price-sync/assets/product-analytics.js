(() => {
  'use strict';

  const config = window.LPS_PRODUCT_ANALYTICS || {};
  const t = config.i18n || {};
  const root = document.getElementById('lps-product-analytics');
  if (!root) return;

  const el = {
    scope: document.getElementById('lps-pa-scope'),
    reload: document.getElementById('lps-pa-reload'),
    spinner: document.getElementById('lps-pa-spinner'),
    message: document.getElementById('lps-pa-message'),
    snapshot: document.getElementById('lps-pa-snapshot'),
    summary: document.getElementById('lps-pa-summary'),
    tabs: Array.from(root.querySelectorAll('[data-lps-pa-tab]')),
    panels: Array.from(root.querySelectorAll('[data-lps-pa-panel]')),
    views: Array.from(root.querySelectorAll('[data-lps-pa-view]')),
    filters: document.getElementById('lps-pa-filters'),
    supplierMode: document.getElementById('lps-pa-supplier-mode'),
    suppliers: document.getElementById('lps-pa-suppliers'),
    supplierMeta: document.getElementById('lps-pa-supplier-meta'),
    documentType: document.getElementById('lps-pa-document-type'),
    scenarioSelect: document.getElementById('lps-pa-scenario-select'),
    scenarioSummary: document.getElementById('lps-pa-scenario-summary'),
    scenarioStatus: document.getElementById('lps-pa-scenario-status'),
    reset: document.getElementById('lps-pa-reset'),
    table: document.getElementById('lps-pa-products'),
    head: document.getElementById('lps-pa-products-head'),
    body: document.querySelector('#lps-pa-products tbody'),
    tableMeta: document.getElementById('lps-pa-table-meta'),
    pagination: document.getElementById('lps-pa-pagination'),
    movementFilters: document.getElementById('lps-pa-movement-filters'),
    movementReset: document.getElementById('lps-pa-movement-reset'),
    operationKind: document.getElementById('lps-pa-operation-kind'),
    movementsTable: document.getElementById('lps-pa-movements'),
    movementsHead: document.getElementById('lps-pa-movements-head'),
    movementsBody: document.querySelector('#lps-pa-movements tbody'),
    movementsMeta: document.getElementById('lps-pa-movements-meta'),
    movementsPagination: document.getElementById('lps-pa-movements-pagination'),
    detail: document.getElementById('lps-pa-detail'),
    detailContent: document.getElementById('lps-pa-detail-content')
  };

  const state = {
    scopes: [],
    sourceDatabase: '',
    warehouseId: 0,
    view: 'all',
    page: 1,
    sort: 'inventory_value',
    direction: 'DESC',
    scenarios: [],
    activeScenarioId: 0,
    applyingScenario: false,
    productsRequest: 0,
    movementPage: 1,
    movementRequest: 0,
    activeTab: 'products',
    analyticsSchemaVersion: 0
  };

  const columns = [
    ['sku', t.sku || 'SKU', 'sku'],
    ['product_name', t.product || 'Product', 'product_name'],
    ['current_supplier', t.supplier || 'Current supplier', 'current_supplier'],
    ['physical_quantity', t.physicalQuantity || 'Physical quantity', 'physical_quantity'],
    ['reserved_quantity', t.reservedQuantity || 'Reserved quantity', 'reserved_quantity'],
    ['available_quantity', t.availableQuantity || 'Available quantity', 'available_quantity'],
    ['accounting_price', t.accountingPrice || 'Accounting price', 'accounting_price'],
    ['inventory_value', t.inventoryValue || 'Capital in stock', 'inventory_value'],
    ['sold_units_90d', t.sales90 || 'Sales, 90 days', 'sold_units_90d'],
    ['sold_units_365d', t.sales365 || 'Sales, 365 days', 'sold_units_365d'],
    ['regular_sold_units_365d', t.regularSales365 || 'Regular demand, 12 months', 'regular_sold_units_365d'],
    ['one_off_sold_units_365d', t.oneOffSales365 || 'One-off sales, 12 months', 'one_off_sold_units_365d'],
    ['revenue_365d', t.revenue365 || 'Revenue, 365 days', 'revenue_365d'],
    ['gross_profit_365d', t.grossProfit365 || 'Gross profit, 365 days', 'gross_profit_365d'],
    ['inventory_turns_365d', t.turns || 'Inventory turns', 'inventory_turns_365d'],
    ['gmroi_365d', t.gmroi || 'GMROI', 'gmroi_365d'],
    ['coverage_days', t.coverage || 'Coverage, days', 'coverage_days'],
    ['last_sale_date', t.lastSale || 'Last sale', 'last_sale_date'],
    ['last_regular_sale_date', t.lastRegularSale || 'Last regular sale', 'last_regular_sale_date'],
    ['last_receipt_date', t.lastReceipt || 'Last receipt', 'last_receipt_date'],
    ['states', t.status || 'Status', 'health_status']
  ];

  const movementColumns = [
    ['document_date', t.documentDate || 'Document date'],
    ['document_number', t.documentNumber || 'Document number'],
    ['document_type', t.documentType || 'Document type'],
    ['sku', t.sku || 'SKU'],
    ['operation_kind', t.operationKind || 'Folio operation kind'],
    ['movement_class', t.movementClass || 'Movement class'],
    ['signed_quantity', t.signedQuantity || 'Signed quantity'],
    ['sale_amount', t.saleAmount || 'Sale amount'],
    ['accounting_value', t.accountingValue || 'Accounting value'],
    ['demand_mode', t.demandMode || 'Demand mode'],
    ['payment_terms', t.paymentTerms || 'Payment terms'],
    ['counterparty_name', t.counterparty || 'Counterparty'],
    ['current_supplier', t.supplier || 'Current supplier'],
    ['accounted', t.accounted || 'Included in accounting'],
    ['return_flag', t.returnFlag || 'Return document']
  ];

  function node(tag, className = '', text = '') {
    const item = document.createElement(tag);
    if (className) item.className = className;
    if (text !== '' && text !== null && text !== undefined) item.textContent = String(text);
    return item;
  }

  function setBusy(busy) {
    el.spinner.classList.toggle('is-active', busy);
    el.reload.disabled = busy;
    el.scope.disabled = busy || !state.scopes.length;
  }

  function showMessage(message = '', kind = 'info') {
    el.message.hidden = !message;
    el.message.className = `lps-pa-message is-${kind}`;
    el.message.textContent = message;
  }

  async function request(operation, data = {}) {
    const body = new URLSearchParams({
      action: 'lps_product_analytics',
      _ajax_nonce: config.nonce || '',
      operation,
      ...data
    });
    const response = await fetch(config.ajaxUrl, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
      body: body.toString()
    });
    let payload;
    try {
      payload = await response.json();
    } catch (error) {
      throw new Error(`${t.loadFailed || 'Product analytics could not be loaded.'} HTTP ${response.status}`);
    }
    if (!response.ok || !payload?.success) {
      throw new Error(payload?.data?.message || t.loadFailed || 'Product analytics could not be loaded.');
    }
    return payload.data;
  }

  async function scenarioRequest(operation, data = {}) {
    const body = new URLSearchParams({
      action: 'lps_analytics_scenarios',
      _ajax_nonce: config.scenarioNonce || '',
      operation,
      ...data
    });
    const response = await fetch(config.ajaxUrl, {
      method: 'POST', credentials: 'same-origin',
      headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'}, body: body.toString()
    });
    let payload;
    try { payload = await response.json(); } catch (error) { throw new Error(`${t.loadFailed || 'Product analytics could not be loaded.'} HTTP ${response.status}`); }
    if (!response.ok || !payload?.success) throw new Error(payload?.data?.message || t.loadFailed || 'Product analytics could not be loaded.');
    return payload.data;
  }

  function numeric(value) {
    if (value === null || value === undefined || value === '') return null;
    const number = Number(value);
    return Number.isFinite(number) ? number : null;
  }

  function formatNumber(value, maximumFractionDigits = 4) {
    const number = numeric(value);
    if (number === null) return '—';
    return new Intl.NumberFormat(config.locale || undefined, {maximumFractionDigits}).format(number);
  }

  function formatInteger(value) {
    return formatNumber(value, 0);
  }

  function formatMoney(value) {
    const number = numeric(value);
    if (number === null) return '—';
    try {
      return new Intl.NumberFormat(config.locale || undefined, {
        style: 'currency', currency: config.currency || 'UAH', maximumFractionDigits: 2
      }).format(number);
    } catch (error) {
      return `${formatNumber(number, 2)} ${config.currency || 'UAH'}`;
    }
  }

  function formatRatio(value) {
    return formatNumber(value, 2);
  }

  function formatDate(value, withTime = false) {
    if (!value) return '—';
    const normalized = String(value).replace(' ', 'T');
    const date = new Date(normalized);
    if (Number.isNaN(date.getTime())) return String(value);
    return new Intl.DateTimeFormat(config.locale || undefined, withTime
      ? {dateStyle: 'medium', timeStyle: 'short'}
      : {dateStyle: 'medium'}).format(date);
  }

  function label(code) {
    return t.statusLabels?.[code] || String(code || '—').replaceAll('_', ' ');
  }

  function badge(code, severity = '') {
    const value = String(code || '').toUpperCase();
    const item = node('span', `lps-pa-badge is-${value.toLowerCase()}${severity ? ` severity-${String(severity).toLowerCase()}` : ''}`, label(value));
    item.title = value;
    return item;
  }

  function scopePayload() {
    return {sourceDatabase: state.sourceDatabase, warehouseId: state.warehouseId};
  }

  async function loadScopes() {
    setBusy(true);
    showMessage();
    try {
      const data = await request('scopes');
      state.scopes = Array.isArray(data.items) ? data.items : [];
      el.scope.replaceChildren();
      if (!state.scopes.length) {
        el.scope.appendChild(new Option(t.noSnapshots || 'No active snapshots', ''));
        showMessage(t.noSnapshots || 'No active Folio product snapshots are available yet.', 'warning');
        return;
      }
      const stored = window.localStorage.getItem('lpsProductAnalyticsScope') || '';
      state.scopes.forEach((scope) => {
        const value = `${scope.source_database}|${scope.warehouse_id}`;
        const version = Number(scope.analytics_schema_version) || 1;
        const caption = `${t.warehouse || 'Warehouse'} #${scope.warehouse_id} · ${scope.source_database} · v${version}`;
        el.scope.appendChild(new Option(caption, value));
      });
      if (stored && state.scopes.some((scope) => `${scope.source_database}|${scope.warehouse_id}` === stored)) {
        el.scope.value = stored;
      }
      selectScope(el.scope.value);
      await loadFilterOptions();
      const applied = await loadScenarios();
      if (!applied) await loadReport();
    } catch (error) {
      showMessage(error.message || String(error), 'error');
    } finally {
      setBusy(false);
    }
  }

  function selectScope(value) {
    const [sourceDatabase = '', warehouse = '0'] = String(value || '').split('|');
    state.sourceDatabase = sourceDatabase;
    state.warehouseId = Number(warehouse) || 0;
    state.page = 1;
    const scope = state.scopes.find((item) => `${item.source_database}|${item.warehouse_id}` === String(value || ''));
    state.analyticsSchemaVersion = Number(scope?.analytics_schema_version) || 1;
    if (state.sourceDatabase && state.warehouseId) {
      window.localStorage.setItem('lpsProductAnalyticsScope', `${state.sourceDatabase}|${state.warehouseId}`);
    }
  }

  function syncSupplierFilter() {
    const enabled = el.supplierMode.value !== 'ANY' && el.suppliers.options.length > 0;
    el.suppliers.disabled = !enabled;
    if (!enabled) {
      Array.from(el.suppliers.options).forEach((option) => { option.selected = false; });
    }
  }

  async function loadFilterOptions() {
    el.suppliers.disabled = true;
    el.supplierMeta.className = 'lps-pa-supplier-meta is-loading';
    el.supplierMeta.textContent = t.loading || 'Loading...';
    try {
      const data = await request('filter_options', scopePayload());
      const schemaVersion = Number(data.analyticsSchemaVersion) || 1;
      state.analyticsSchemaVersion = schemaVersion;
      if (schemaVersion < 2) {
        el.suppliers.replaceChildren();
        el.supplierMode.value = 'ANY';
        el.supplierMeta.className = 'lps-pa-supplier-meta is-warning';
        el.supplierMeta.textContent = t.schemaUpgradeRequired || 'Rebuild this snapshot with analytics schema v2.';
        syncSupplierFilter();
        clearReportOutput();
        showMessage(t.schemaUpgradeRequired || 'Rebuild this snapshot with analytics schema v2.', 'warning');
        return;
      }

      const suppliers = Array.isArray(data.suppliers) ? data.suppliers : [];
      const options = suppliers.map((supplier) => {
        const item = typeof supplier === 'string' ? {value: supplier, products: null, state: 'CURRENT'} : supplier;
        const value = String(item?.value || '').trim();
        const count = numeric(item?.products);
        const review = item?.state === 'REVIEW' || value === '1';
        const caption = `${value}${count === null ? '' : ` (${formatInteger(count)})`}${review ? ` · ${t.supplierServiceCode || 'Service code / requires verification'}` : ''}`;
        const option = new Option(caption, value);
        option.dataset.state = String(item?.state || 'CURRENT');
        return option;
      }).filter((option) => option.value !== '');
      el.suppliers.replaceChildren(...options);
      if (!options.length) {
        const empty = new Option(t.noSuppliers || 'No suppliers are available for this warehouse.', '');
        empty.disabled = true;
        el.suppliers.append(empty);
        el.supplierMode.value = 'ANY';
      }
      const stats = data.supplierStats || {};
      const pattern = t.supplierStats || 'Assigned: %1$s · missing: %2$s · supplier values: %3$s';
      el.supplierMeta.className = 'lps-pa-supplier-meta';
      el.supplierMeta.textContent = pattern
        .replace('%1$s', formatInteger(stats.assignedProducts))
        .replace('%2$s', formatInteger(stats.missingProducts))
        .replace('%3$s', formatInteger(stats.distinctSuppliers));
      const operationKinds = Array.isArray(data.movementOptions?.operationKinds) ? data.movementOptions.operationKinds : [];
      const selectedOperation = el.operationKind.value;
      el.operationKind.replaceChildren(new Option(t.allOperationKinds || 'All operation kinds', ''));
      operationKinds.forEach((item) => {
        const value = String(item?.value || '').trim();
        if (value) el.operationKind.append(new Option(`${value} (${formatInteger(item.movements)})`, value));
      });
      if (Array.from(el.operationKind.options).some((option) => option.value === selectedOperation)) {
        el.operationKind.value = selectedOperation;
      }
      const documentTypes = Array.isArray(data.movementOptions?.documentTypes) ? data.movementOptions.documentTypes : [];
      const selectedDocumentType = el.documentType.value;
      el.documentType.replaceChildren(new Option(t.allDocumentTypes || 'All document types', ''));
      documentTypes.forEach((item) => {
        const value = String(item?.value || '').trim();
        if (value) el.documentType.append(new Option(`${value} (${formatInteger(item.movements)})`, value));
      });
      if (Array.from(el.documentType.options).some((option) => option.value === selectedDocumentType)) {
        el.documentType.value = selectedDocumentType;
      }
      syncSupplierFilter();
    } catch (error) {
      el.suppliers.replaceChildren();
      el.supplierMode.value = 'ANY';
      const failed = new Option(t.suppliersLoadFailed || 'Suppliers could not be loaded.', '');
      failed.disabled = true;
      el.suppliers.append(failed);
      el.supplierMeta.className = 'lps-pa-supplier-meta is-error';
      el.supplierMeta.textContent = `${t.suppliersLoadFailed || 'Suppliers could not be loaded.'} ${error.message || String(error)}`;
      syncSupplierFilter();
      throw error;
    }
  }

  function renderScenarios(selectedId = 0) {
    el.scenarioSelect.replaceChildren(new Option(t.selectScenario || 'Use temporary filters without a scenario', ''));
    state.scenarios.filter((scenario) => scenario.status === 'active').forEach((scenario) => {
      el.scenarioSelect.append(new Option(scenario.name, String(scenario.id)));
    });
    el.scenarioSelect.value = selectedId ? String(selectedId) : '';
  }

  function setFormValues(form, values = {}) {
    Object.entries(values).forEach(([name, value]) => {
      if (['supplierValues', 'view', 'sort', 'direction'].includes(name)) return;
      const field = form.elements[name];
      if (field) field.value = value ?? '';
    });
  }

  function activeConditions(values = {}, ignored = []) {
    return Object.entries(values).filter(([key, value]) => {
      if (ignored.includes(key) || value === '' || value === null || value === undefined) return false;
      if (['ANY', 'all', '50', 'DESC', '365'].includes(String(value))) return false;
      return !(Array.isArray(value) && value.length === 0);
    });
  }

  function scenarioConditionLabel(group, key) {
    const form = group === 'products' ? el.filters : el.movementFilters;
    const field = form.elements[key === 'supplierValues' ? 'supplierValues[]' : key];
    return field?.closest('label')?.querySelector('span')?.textContent?.trim() || key;
  }

  function preserveScenarioOption(select, value) {
    const normalized = String(value || '').trim();
    if (!normalized || Array.from(select.options).some((option) => option.value === normalized)) return;
    select.append(new Option(`${normalized} · ${t.scenarioSavedUnavailableValue || 'Saved value is not available in the current snapshot'}`, normalized));
  }

  function renderScenarioSummary(scenario) {
    el.scenarioSummary.replaceChildren();
    if (!scenario) { el.scenarioSummary.hidden = true; el.scenarioStatus.textContent = ''; return; }
    const products = activeConditions(scenario.profile?.products, ['view', 'sort', 'direction', 'perPage']);
    const movements = activeConditions(scenario.profile?.movements, ['movementPerPage']);
    const addGroup = (groupName, caption, entries) => {
      const group = node('div', 'lps-pa-scenario-group'); group.append(node('strong', '', caption));
      if (!entries.length) group.append(node('span', 'description', t.scenarioAllValues || 'No additional conditions'));
      entries.slice(0, 8).forEach(([key, value]) => group.append(node('span', 'lps-pa-scenario-chip', `${scenarioConditionLabel(groupName, key)}: ${Array.isArray(value) ? value.join(', ') : value}`)));
      if (entries.length > 8) group.append(node('span', 'lps-pa-scenario-chip', `+${entries.length - 8}`));
      el.scenarioSummary.append(group);
    };
    addGroup('products', t.scenarioProducts || 'Product conditions', products);
    addGroup('movements', t.scenarioMovements || 'Movement conditions', movements);
    el.scenarioSummary.hidden = false;
  }

  function markScenarioModified() {
    if (!state.activeScenarioId || state.applyingScenario) return;
    el.scenarioStatus.textContent = t.scenarioModified || 'Temporary changes are applied. The saved scenario has not been changed.';
    el.scenarioStatus.classList.add('is-modified');
  }

  async function applyScenario(scenario) {
    if (!scenario) {
      state.activeScenarioId = 0; window.localStorage.removeItem('lpsProductAnalyticsScenario');
      renderScenarioSummary(null); return false;
    }
    state.applyingScenario = true;
    try {
      const profile = scenario.profile || {};
      const warehouseId = Number(profile.context?.warehouseIds?.[0]) || 0;
      const scopeValue = `${profile.context?.sourceDatabase || ''}|${warehouseId}`;
      if (!state.scopes.some((scope) => `${scope.source_database}|${scope.warehouse_id}` === scopeValue)) {
        throw new Error(t.scenarioUnavailable || 'The saved warehouse for this scenario is not available.');
      }
      el.scope.value = scopeValue; selectScope(scopeValue);
      el.filters.reset(); el.movementFilters.reset();
      await loadFilterOptions();
      (profile.products?.supplierValues || []).forEach((value) => preserveScenarioOption(el.suppliers, value));
      preserveScenarioOption(el.documentType, profile.movements?.documentType);
      preserveScenarioOption(el.operationKind, profile.movements?.operationKind);
      setFormValues(el.filters, profile.products || {});
      setFormValues(el.movementFilters, profile.movements || {});
      el.supplierMode.value = ['INCLUDE', 'EXCLUDE'].includes(profile.products?.supplierMode) ? profile.products.supplierMode : 'ANY';
      const suppliers = new Set((profile.products?.supplierValues || []).map(String));
      Array.from(el.suppliers.options).forEach((option) => { option.selected = suppliers.has(option.value); });
      syncSupplierFilter();
      state.view = profile.products?.view || 'all'; state.sort = profile.products?.sort || 'inventory_value';
      state.direction = profile.products?.direction === 'ASC' ? 'ASC' : 'DESC'; state.page = 1; state.movementPage = 1;
      el.views.forEach((button) => button.classList.toggle('button-primary', button.dataset.lpsPaView === state.view));
      state.activeScenarioId = Number(scenario.id); el.scenarioSelect.value = String(scenario.id);
      window.localStorage.setItem('lpsProductAnalyticsScenario', String(scenario.id));
      renderScenarioSummary(scenario); el.scenarioStatus.classList.remove('is-modified');
      el.scenarioStatus.textContent = t.scenarioApplied || 'The analytics scenario has been applied to both registries.';
      selectTab(profile.presentation?.activeTab || 'products', false);
      await loadReport();
      if (state.activeTab === 'movements') await loadMovements();
      return true;
    } finally { state.applyingScenario = false; }
  }

  async function loadScenarios() {
    const data = await scenarioRequest('list');
    state.scenarios = Array.isArray(data.items) ? data.items : [];
    const requested = Number(new URLSearchParams(location.search).get('scenario')) || Number(window.localStorage.getItem('lpsProductAnalyticsScenario')) || 0;
    renderScenarios(requested);
    const scenario = state.scenarios.find((item) => item.id === requested && item.status === 'active');
    return scenario ? applyScenario(scenario) : false;
  }

  async function loadReport() {
    if (!state.sourceDatabase || !state.warehouseId) return;
    if (state.analyticsSchemaVersion < 2) {
      clearReportOutput();
      showMessage(t.schemaUpgradeRequired || 'Rebuild this snapshot with analytics schema v2.', 'warning');
      return;
    }
    setBusy(true);
    showMessage();
    try {
      await Promise.all([loadSummary(), loadProducts()]);
    } catch (error) {
      showMessage(error.message || String(error), 'error');
    } finally {
      setBusy(false);
    }
  }

  function clearReportOutput() {
    el.summary.replaceChildren();
    el.snapshot.replaceChildren();
    el.body.replaceChildren();
    el.tableMeta.textContent = '';
    el.pagination.replaceChildren();
    el.movementsBody.replaceChildren();
    el.movementsMeta.textContent = '';
    el.movementsPagination.replaceChildren();
  }

  function summaryCard(labelText, value, type = 'number', emphasis = '') {
    const card = node('article', `lps-pa-card${emphasis ? ` is-${emphasis}` : ''}`);
    card.append(node('span', '', labelText));
    const display = type === 'money' ? formatMoney(value) : formatNumber(value);
    card.append(node('strong', '', display));
    return card;
  }

  async function loadSummary() {
    const data = await request('summary', scopePayload());
    const totals = data.totals || {};
    const generation = data.generation || {};
    el.summary.replaceChildren(
      summaryCard(t.products || 'Products', totals.products, 'number'),
      summaryCard(t.physicalQuantity || 'Physical quantity', totals.physical_quantity, 'number'),
      summaryCard(t.reservedQuantity || 'Reserved quantity', totals.reserved_quantity, 'number'),
      summaryCard(t.availableQuantity || 'Available quantity', totals.available_quantity, 'number'),
      summaryCard(t.inventoryValue || 'Capital in stock', totals.inventory_value, 'money', 'capital'),
      summaryCard(t.revenue365 || 'Revenue, 365 days', totals.revenue_365d, 'money'),
      summaryCard(t.grossProfit365 || 'Gross profit, 365 days', totals.gross_profit_365d, 'money', numeric(totals.gross_profit_365d) < 0 ? 'danger' : 'profit'),
      summaryCard(t.capitalWithoutSales || 'Capital without sales', totals.capital_without_sales, 'money', 'warning'),
      summaryCard(t.riskCapital || 'Risk capital', totals.risk_capital, 'money', 'warning')
    );

    const meta = node('div', 'lps-pa-snapshot-content');
    meta.append(node('strong', '', t.dataAsOf || 'Active snapshot'));
    meta.append(node('span', '', formatDate(generation.completed_at, true)));
    if (generation.horizon_months) meta.append(node('span', '', `${generation.horizon_months} mo.`));
    meta.append(node('span', '', `schema v${Number(generation.analytics_schema_version) || 1}`));
    if (numeric(generation.movement_fact_rows) !== null) {
      meta.append(node('span', '', `${formatInteger(generation.movement_fact_rows)} ${t.movementRows || 'movement rows'}`));
    }
    el.snapshot.replaceChildren(meta);

    const strips = node('div', 'lps-pa-strips');
    const alertStrip = node('div', 'lps-pa-strip');
    alertStrip.append(node('strong', '', t.alerts || 'Alerts'));
    (data.alerts || []).forEach((row) => {
      const item = node('button', 'lps-pa-strip-item');
      item.type = 'button';
      item.dataset.alert = row.alert_code;
      item.append(badge(row.alert_code, row.severity), node('span', 'lps-pa-strip-count', formatInteger(row.products)));
      alertStrip.append(item);
    });
    if (!(data.alerts || []).length) alertStrip.append(node('span', 'description', '—'));

    const verificationStrip = node('div', 'lps-pa-strip');
    verificationStrip.append(node('strong', '', t.verification || 'Verification'));
    (data.verification || []).forEach((row) => {
      const item = node('button', 'lps-pa-strip-item');
      item.type = 'button';
      item.dataset.verification = row.verification_state;
      item.append(badge(row.verification_state), node('span', 'lps-pa-strip-count', formatInteger(row.products)));
      verificationStrip.append(item);
    });
    strips.append(alertStrip, verificationStrip);
    el.summary.append(strips);
  }

  function filterPayload() {
    const form = new FormData(el.filters);
    return {
      ...scopePayload(),
      search: form.get('search') || '',
      health: form.get('health') || '',
      verification: form.get('verification') || '',
      alertCode: form.get('alertCode') || '',
      alertStatus: form.get('alertStatus') || 'ANY',
      severity: form.get('severity') || '',
      sales: form.get('sales') || '',
      supplierMode: form.get('supplierMode') || 'ANY',
      supplierValues: form.getAll('supplierValues[]').join('\n'),
      supplierQuality: form.get('supplierQuality') || 'ANY',
      availableSign: form.get('availableSign') || 'ANY',
      accountingPriceMode: form.get('accountingPriceMode') || 'ANY',
      physicalMin: form.get('physicalMin') || '',
      physicalMax: form.get('physicalMax') || '',
      reservedMin: form.get('reservedMin') || '',
      reservedMax: form.get('reservedMax') || '',
      availableMin: form.get('availableMin') || '',
      availableMax: form.get('availableMax') || '',
      demandPeriod: form.get('demandPeriod') || '365',
      regularDemand: form.get('regularDemand') || 'ANY',
      oneOffDemand: form.get('oneOffDemand') || 'ANY',
      inventoryMin: form.get('inventoryMin') || '',
      inventoryMax: form.get('inventoryMax') || '',
      financePeriod: form.get('financePeriod') || '365',
      revenueMin: form.get('revenueMin') || '',
      revenueMax: form.get('revenueMax') || '',
      profitMin: form.get('profitMin') || '',
      profitMax: form.get('profitMax') || '',
      averageCapitalMin: form.get('averageCapitalMin') || '',
      averageCapitalMax: form.get('averageCapitalMax') || '',
      marginMin: form.get('marginMin') || '',
      marginMax: form.get('marginMax') || '',
      turnsMin: form.get('turnsMin') || '',
      turnsMax: form.get('turnsMax') || '',
      gmroiMin: form.get('gmroiMin') || '',
      gmroiMax: form.get('gmroiMax') || '',
      coverageMin: form.get('coverageMin') || '',
      coverageMax: form.get('coverageMax') || '',
      lastSaleFrom: form.get('lastSaleFrom') || '',
      lastSaleTo: form.get('lastSaleTo') || '',
      lastRegularSaleFrom: form.get('lastRegularSaleFrom') || '',
      lastRegularSaleTo: form.get('lastRegularSaleTo') || '',
      lastReceiptFrom: form.get('lastReceiptFrom') || '',
      lastReceiptTo: form.get('lastReceiptTo') || '',
      firstMovementFrom: form.get('firstMovementFrom') || '',
      firstMovementTo: form.get('firstMovementTo') || '',
      lastMovementFrom: form.get('lastMovementFrom') || '',
      lastMovementTo: form.get('lastMovementTo') || '',
      alertFirstSeenFrom: form.get('alertFirstSeenFrom') || '',
      alertFirstSeenTo: form.get('alertFirstSeenTo') || '',
      alertLastSeenFrom: form.get('alertLastSeenFrom') || '',
      alertLastSeenTo: form.get('alertLastSeenTo') || '',
      perPage: form.get('perPage') || 50,
      view: state.view,
      page: state.page,
      sort: state.sort,
      direction: state.direction
    };
  }

  function productCell(row, key) {
    const td = node('td');
    if (key === 'sku') {
      const button = node('button', 'button-link lps-pa-sku', row.sku);
      button.type = 'button';
      button.dataset.sku = row.sku;
      td.append(button);
    } else if (key === 'product_name') {
      td.textContent = row.product_name || '—';
    } else if (key === 'current_supplier') {
      td.textContent = row.current_supplier || '—';
      if (String(row.current_supplier || '').trim() === '1') {
        td.append(node('span', 'lps-pa-badge is-unverified', t.supplierServiceCode || 'Service code / requires verification'));
      }
    } else if (['accounting_price','inventory_value','revenue_365d','gross_profit_365d'].includes(key)) {
      td.textContent = formatMoney(row[key]);
      if (key === 'gross_profit_365d' && numeric(row[key]) < 0) td.classList.add('is-negative');
    } else if (['inventory_turns_365d','gmroi_365d'].includes(key)) {
      td.textContent = formatRatio(row[key]);
    } else if (key === 'coverage_days') {
      td.textContent = formatNumber(row[key], 0);
    } else if (['last_sale_date','last_regular_sale_date','last_receipt_date'].includes(key)) {
      td.textContent = formatDate(row[key]);
    } else if (key === 'states') {
      const list = node('div', 'lps-pa-badges');
      list.append(badge(row.health_status), badge(row.verification_state));
      String(row.active_alerts || '').split(',').filter(Boolean).forEach((value) => {
        const [code, severity] = value.split(':');
        if (code !== row.health_status) list.append(badge(code, severity));
      });
      td.append(list);
    } else {
      td.textContent = formatNumber(row[key]);
    }
    return td;
  }

  function renderHead(sort, direction) {
    el.head.replaceChildren();
    columns.forEach(([key, title, sortable]) => {
      const th = node('th');
      if (sortable) {
        const button = node('button', 'button-link lps-pa-sort', title);
        button.type = 'button';
        button.dataset.sort = sortable;
        if (sort === sortable) {
          button.classList.add('is-active');
          button.append(node('span', 'lps-pa-sort-arrow', direction === 'ASC' ? '↑' : '↓'));
        }
        th.append(button);
      } else {
        th.textContent = title;
      }
      el.head.append(th);
    });
  }

  async function loadProducts(extra = {}) {
    const requestId = ++state.productsRequest;
    const data = await request('products', {...filterPayload(), ...extra});
    if (requestId !== state.productsRequest) return;
    state.page = Number(data.page) || 1;
    state.sort = data.sort || state.sort;
    state.direction = data.direction || state.direction;
    renderHead(data.sort, data.direction);
    el.body.replaceChildren();
    (data.items || []).forEach((row) => {
      const tr = node('tr');
      tr.dataset.sku = row.sku;
      columns.forEach(([key]) => tr.append(productCell(row, key)));
      el.body.append(tr);
    });
    if (!(data.items || []).length) {
      const td = node('td', 'lps-pa-empty', t.noProducts || 'No products match the selected filters.');
      td.colSpan = columns.length;
      const tr = node('tr'); tr.append(td); el.body.append(tr);
    }
    el.tableMeta.textContent = `${formatInteger(data.total)} · ${t.page || 'Page'} ${data.page} ${t.of || 'of'} ${data.pages}`;
    renderPagination(data);
  }

  function renderPagination(data) {
    el.pagination.replaceChildren();
    const previous = node('button', 'button', '‹');
    previous.type = 'button'; previous.disabled = data.page <= 1;
    previous.addEventListener('click', () => { state.page -= 1; loadProducts(); });
    const next = node('button', 'button', '›');
    next.type = 'button'; next.disabled = data.page >= data.pages;
    next.addEventListener('click', () => { state.page += 1; loadProducts(); });
    el.pagination.append(previous, node('span', '', `${t.page || 'Page'} ${data.page} ${t.of || 'of'} ${data.pages}`), next);
  }

  function movementPayload() {
    const form = new FormData(el.movementFilters);
    const payload = {...scopePayload(), movementPage: state.movementPage};
    for (const [key, value] of form.entries()) payload[key] = value;
    return payload;
  }

  function movementCell(row, key) {
    const td = node('td');
    if (key === 'document_date') td.textContent = formatDate(row[key]);
    else if (key === 'document_number') td.textContent = row[key] === null || row[key] === undefined || row[key] === ''
      ? '—'
      : String(row[key]).replace(/\.0+$/, '');
    else if (['sale_amount','accounting_value'].includes(key)) td.textContent = formatMoney(row[key]);
    else if (key === 'signed_quantity') td.textContent = formatNumber(row[key]);
    else if (['accounted','return_flag'].includes(key)) td.textContent = Number(row[key]) === 1 ? (t.yes || 'Yes') : (t.no || 'No');
    else if (key === 'counterparty_name') td.textContent = row.counterparty_name || row.counterparty_short_name || '—';
    else if (['movement_class','demand_mode','payment_terms'].includes(key)) td.append(badge(row[key]));
    else td.textContent = row[key] || '—';
    return td;
  }

  async function loadMovements() {
    if (!state.sourceDatabase || !state.warehouseId) return;
    if (state.analyticsSchemaVersion < 2) {
      showMessage(t.schemaUpgradeRequired || 'Rebuild this snapshot with analytics schema v2.', 'warning');
      return;
    }
    const requestId = ++state.movementRequest;
    setBusy(true);
    try {
      const data = await request('movements', movementPayload());
      if (requestId !== state.movementRequest) return;
      state.movementPage = Number(data.page) || 1;
      el.movementsHead.replaceChildren(...movementColumns.map(([, caption]) => node('th', '', caption)));
      el.movementsBody.replaceChildren();
      (data.items || []).forEach((row) => {
        const tr = node('tr');
        movementColumns.forEach(([key]) => tr.append(movementCell(row, key)));
        el.movementsBody.append(tr);
      });
      if (!(data.items || []).length) {
        const td = node('td', 'lps-pa-empty', t.noMovements || 'No movements match the selected filters.');
        td.colSpan = movementColumns.length;
        const tr = node('tr'); tr.append(td); el.movementsBody.append(tr);
      }
      el.movementsMeta.textContent = `${formatInteger(data.total)} · ${t.page || 'Page'} ${data.page} ${t.of || 'of'} ${data.pages}`;
      el.movementsPagination.replaceChildren();
      const previous = node('button', 'button', '‹');
      previous.type = 'button'; previous.disabled = data.page <= 1;
      previous.addEventListener('click', () => { state.movementPage -= 1; loadMovements(); });
      const next = node('button', 'button', '›');
      next.type = 'button'; next.disabled = data.page >= data.pages;
      next.addEventListener('click', () => { state.movementPage += 1; loadMovements(); });
      el.movementsPagination.append(previous, node('span', '', `${t.page || 'Page'} ${data.page} ${t.of || 'of'} ${data.pages}`), next);
    } catch (error) {
      showMessage(error.message || String(error), 'error');
    } finally {
      setBusy(false);
    }
  }

  function selectTab(tab, load = true) {
    state.activeTab = tab === 'movements' ? 'movements' : 'products';
    el.tabs.forEach((button) => button.classList.toggle('nav-tab-active', button.dataset.lpsPaTab === state.activeTab));
    el.panels.forEach((panel) => { panel.hidden = panel.dataset.lpsPaPanel !== state.activeTab; });
    if (load && state.activeTab === 'movements') loadMovements();
  }

  function metricGrid(row) {
    const grid = node('dl', 'lps-pa-metric-grid');
    const values = [
      [t.physicalQuantity, formatNumber(row.physical_quantity)],
      [t.reservedQuantity, formatNumber(row.reserved_quantity)],
      [t.availableQuantity, formatNumber(row.available_quantity)],
      [t.inventoryValue, formatMoney(row.inventory_value)],
      [t.sales365, formatNumber(row.sold_units_365d)],
      [t.regularSales365, formatNumber(row.regular_sold_units_365d)],
      [t.oneOffSales365, formatNumber(row.one_off_sold_units_365d)],
      [t.revenue365, formatMoney(row.revenue_365d)],
      [t.grossProfit365, formatMoney(row.gross_profit_365d)],
      [t.turns, formatRatio(row.inventory_turns_365d)],
      [t.gmroi, formatRatio(row.gmroi_365d)],
      [t.coverage, formatNumber(row.coverage_days, 0)],
      [t.lastSale, formatDate(row.last_sale_date)],
      [t.lastRegularSale, formatDate(row.last_regular_sale_date)],
      [t.lastReceipt, formatDate(row.last_receipt_date)]
    ];
    values.forEach(([caption, value]) => grid.append(node('dt', '', caption || ''), node('dd', '', value)));
    return grid;
  }

  function miniChart(months, key, caption, format) {
    const section = node('div', 'lps-pa-mini-chart');
    section.append(node('strong', '', caption));
    const values = months.map((row) => numeric(row[key]) || 0);
    const min = Math.min(0, ...values);
    const max = Math.max(0, ...values);
    const range = max - min || 1;
    const bars = node('div', 'lps-pa-bars');
    months.forEach((row, index) => {
      const value = values[index];
      const bar = node('span', `lps-pa-bar${value < 0 ? ' is-negative' : ''}`);
      bar.style.height = `${Math.max(3, Math.abs(value) / range * 100)}%`;
      bar.title = `${formatDate(row.month_start)}: ${format(value)}`;
      bars.append(bar);
    });
    section.append(bars);
    return section;
  }

  function renderMonthly(months) {
    const section = node('section', 'lps-pa-detail-section');
    section.append(node('h3', '', t.monthlyHistory || 'Monthly history'));
    if (!months.length) {
      section.append(node('p', 'description', '—'));
      return section;
    }
    const charts = node('div', 'lps-pa-charts');
    charts.append(
      miniChart(months, 'sales_quantity', t.sales || 'Commercial sales total', formatNumber),
      miniChart(months, 'gross_profit', t.grossProfit || 'Gross profit', formatMoney),
      miniChart(months, 'average_inventory_value', t.averageCapital || 'Average capital', formatMoney)
    );
    section.append(charts);

    const scroll = node('div', 'lps-pa-table-scroll');
    const table = node('table', 'widefat striped');
    const headers = [
      t.month,t.openingStock,t.closingStock,t.openingInventoryValue || 'Opening inventory value',
      t.closingInventoryValue || 'Closing inventory value',t.receipts,t.receiptCost || 'Receipt cost',
      t.sales,t.revenue,t.cogs,t.grossProfit,t.returns,t.returnRevenue || 'Return revenue',
      t.regularSales,t.regularRevenue,t.regularCogs,t.regularGrossProfit,
      t.oneOffSales,t.oneOffRevenue,t.oneOffCogs,t.oneOffGrossProfit,
      t.averageCapital,t.turns,t.gmroi,t.sellThrough
    ];
    const keys = [
      'month_start','opening_quantity','closing_quantity','opening_inventory_value',
      'closing_inventory_value','receipt_quantity','receipt_cost','sales_quantity','sales_revenue',
      'sales_cogs','gross_profit','return_quantity','return_revenue',
      'regular_sales_quantity','regular_sales_revenue','regular_sales_cogs','regular_gross_profit',
      'one_off_sales_quantity','one_off_sales_revenue','one_off_sales_cogs','one_off_gross_profit',
      'average_inventory_value',
      'inventory_turns','gmroi','sell_through_percent'
    ];
    const trh = node('tr'); headers.forEach((caption) => trh.append(node('th', '', caption || '')));
    const thead = node('thead'); thead.append(trh); table.append(thead);
    const tbody = node('tbody');
    months.forEach((row) => {
      const tr = node('tr');
      keys.forEach((key) => {
        const money = [
          'opening_inventory_value','closing_inventory_value','receipt_cost','sales_revenue','sales_cogs',
          'gross_profit','return_revenue','regular_sales_revenue','regular_sales_cogs','regular_gross_profit',
          'one_off_sales_revenue','one_off_sales_cogs','one_off_gross_profit','average_inventory_value'
        ].includes(key);
        const value = key === 'month_start' ? formatDate(row[key]) : money ? formatMoney(row[key]) : formatNumber(row[key]);
        const td = node('td', key === 'gross_profit' && numeric(row[key]) < 0 ? 'is-negative' : '', value);
        tr.append(td);
      });
      tbody.append(tr);
    });
    table.append(tbody); scroll.append(table); section.append(scroll);
    return section;
  }

  function renderWarehouseComparison(rows, selectedWarehouse) {
    const section = node('section', 'lps-pa-detail-section');
    section.append(node('h3', '', t.warehouseComparison || 'Warehouse comparison'));
    const selected = rows.find((row) => Number(row.warehouse_id) === Number(selectedWarehouse));
    const possible = selected?.health_status === 'STOCKOUT' && rows.some((row) => Number(row.warehouse_id) !== Number(selectedWarehouse) && numeric(row.available_quantity) > 0);
    if (possible) {
      const notice = node('div', 'lps-pa-transfer-note');
      notice.append(badge('STOCKOUT'), node('strong', '', t.possibleTransfer || 'Possible cross-warehouse analysis'), node('span', '', t.possibleTransferHelp || 'A manager must check the safe transfer quantity.'));
      section.append(notice);
    }
    const scroll = node('div', 'lps-pa-table-scroll');
    const table = node('table', 'widefat striped');
    const headers = [t.warehouse,t.physicalQuantity,t.reservedQuantity,t.availableQuantity,t.inventoryValue,t.sales90,t.sales365,t.coverage,t.status,t.verification];
    const trh = node('tr'); headers.forEach((caption) => trh.append(node('th', '', caption || '')));
    const thead = node('thead'); thead.append(trh); table.append(thead);
    const tbody = node('tbody');
    rows.forEach((row) => {
      const tr = node('tr', Number(row.warehouse_id) === Number(selectedWarehouse) ? 'is-current-warehouse' : '');
      [
        `#${row.warehouse_id}`, formatNumber(row.physical_quantity), formatNumber(row.reserved_quantity),
        formatNumber(row.available_quantity), formatMoney(row.inventory_value), formatNumber(row.sold_units_90d),
        formatNumber(row.sold_units_365d), formatNumber(row.coverage_days, 0)
      ].forEach((value) => tr.append(node('td', '', value)));
      const health = node('td'); health.append(badge(row.health_status)); tr.append(health);
      const verification = node('td'); verification.append(badge(row.verification_state)); tr.append(verification);
      tbody.append(tr);
    });
    table.append(tbody); scroll.append(table); section.append(scroll);
    return section;
  }

  function renderHistory(title, rows, renderRow) {
    const section = node('section', 'lps-pa-detail-section');
    section.append(node('h3', '', title));
    const list = node('ul', 'lps-pa-history');
    rows.forEach((row) => list.append(renderRow(row)));
    if (!rows.length) list.append(node('li', 'description', '—'));
    section.append(list);
    return section;
  }

  async function openProduct(sku) {
    el.detail.hidden = false;
    document.body.classList.add('lps-pa-detail-open');
    el.detailContent.replaceChildren(node('p', 'lps-pa-detail-loading', t.loading || 'Loading...'));
    try {
      const data = await request('product', {...scopePayload(), sku});
      const current = data.current || {};
      const header = node('header', 'lps-pa-detail-header');
      header.append(node('p', 'lps-pa-detail-kicker', `${t.sku || 'SKU'} ${current.sku || sku}`));
      const title = node('h2', '', current.product_name || sku); title.id = 'lps-pa-detail-title'; header.append(title);
      const badges = node('div', 'lps-pa-badges'); badges.append(badge(current.health_status), badge(current.verification_state)); header.append(badges);
      el.detailContent.replaceChildren(header, metricGrid(current));
      el.detailContent.append(
        renderMonthly(data.monthly || []),
        renderWarehouseComparison(data.warehouses || [], state.warehouseId),
        renderHistory(t.alertHistory || 'Alert history', data.alerts || [], (row) => {
          const li = node('li'); li.append(badge(row.alert_code, row.severity), node('span', '', `${label(row.status)} · ${formatDate(row.last_seen_at, true)}`));
          if (row.details) li.append(node('small', '', row.details)); return li;
        }),
        renderHistory(t.changeHistory || 'Snapshot changes', data.changes || [], (row) => {
          const li = node('li'); li.append(badge(row.change_type), node('span', '', formatDate(row.detected_at, true))); return li;
        })
      );
    } catch (error) {
      el.detailContent.replaceChildren(node('p', 'lps-pa-message is-error', error.message || String(error)));
    }
  }

  function closeDetail() {
    el.detail.hidden = true;
    document.body.classList.remove('lps-pa-detail-open');
  }

  el.scope.addEventListener('change', async () => {
    markScenarioModified();
    selectScope(el.scope.value);
    el.supplierMode.value = 'ANY';
    try {
      await loadFilterOptions();
      await loadReport();
      if (state.activeTab === 'movements') await loadMovements();
    } catch (error) {
      showMessage(error.message || String(error), 'error');
    }
  });
  el.reload.addEventListener('click', async () => {
    try {
      await loadFilterOptions();
      await loadReport();
      if (state.activeTab === 'movements') await loadMovements();
    } catch (error) {
      showMessage(error.message || String(error), 'error');
    }
  });
  el.supplierMode.addEventListener('change', () => { syncSupplierFilter(); markScenarioModified(); });
  el.tabs.forEach((button) => button.addEventListener('click', () => selectTab(button.dataset.lpsPaTab)));
  el.movementFilters.addEventListener('submit', (event) => {
    event.preventDefault(); markScenarioModified(); state.movementPage = 1; loadMovements();
  });
  el.movementReset.addEventListener('click', () => {
    el.movementFilters.reset(); markScenarioModified(); state.movementPage = 1; loadMovements();
  });
  el.scenarioSelect.addEventListener('change', async () => {
    const scenario = state.scenarios.find((item) => item.id === Number(el.scenarioSelect.value));
    try {
      if (scenario) await applyScenario(scenario);
      else {
        state.activeScenarioId = 0;
        window.localStorage.removeItem('lpsProductAnalyticsScenario');
        renderScenarioSummary(null);
        el.scenarioStatus.textContent = '';
      }
    } catch (error) {
      showMessage(error.message || String(error), 'error');
    }
  });
  el.filters.addEventListener('submit', (event) => { event.preventDefault(); markScenarioModified(); state.page = 1; loadProducts().catch((error) => showMessage(error.message, 'error')); });
  el.reset.addEventListener('click', () => {
    el.filters.reset(); syncSupplierFilter(); state.view = 'all'; state.page = 1;
    markScenarioModified();
    state.sort = 'inventory_value'; state.direction = 'DESC';
    el.views.forEach((button) => button.classList.toggle('button-primary', button.dataset.lpsPaView === 'all'));
    loadProducts().catch((error) => showMessage(error.message, 'error'));
  });
  el.views.forEach((button) => button.addEventListener('click', () => {
    markScenarioModified();
    state.view = button.dataset.lpsPaView || 'all'; state.page = 1;
    el.views.forEach((item) => item.classList.toggle('button-primary', item === button));
    loadProducts().catch((error) => showMessage(error.message, 'error'));
  }));
  el.summary.addEventListener('click', (event) => {
    const button = event.target.closest('[data-alert],[data-verification]');
    if (!button) return;
    if (button.dataset.alert) {
      const viewByAlert = {DATA_ISSUE:'data_issues',STOCKOUT:'stockout',DEAD_STOCK:'dead_stock',OVERSTOCK:'overstock',LOW_MARGIN:'low_margin',DEMAND_FADING:'demand_fading'};
      state.view = viewByAlert[button.dataset.alert] || 'all';
      el.views.forEach((item) => item.classList.toggle('button-primary', item.dataset.lpsPaView === state.view));
    }
    if (button.dataset.verification) el.filters.elements.verification.value = button.dataset.verification;
    markScenarioModified();
    state.page = 1; loadProducts().catch((error) => showMessage(error.message, 'error'));
  });
  el.table.addEventListener('click', (event) => {
    const sort = event.target.closest('[data-sort]');
    if (sort) {
      markScenarioModified();
      state.direction = state.sort === sort.dataset.sort && state.direction === 'DESC' ? 'ASC' : 'DESC';
      state.sort = sort.dataset.sort;
      state.view = 'all';
      el.views.forEach((item) => item.classList.toggle('button-primary', item.dataset.lpsPaView === 'all'));
      loadProducts().catch((error) => showMessage(error.message, 'error'));
      return;
    }
    const sku = event.target.closest('[data-sku]')?.dataset.sku;
    if (sku) openProduct(sku);
  });
  el.detail.addEventListener('click', (event) => { if (event.target.closest('[data-lps-pa-close]')) closeDetail(); });
  document.addEventListener('keydown', (event) => { if (event.key === 'Escape' && !el.detail.hidden) closeDetail(); });

  loadScopes();
})();
