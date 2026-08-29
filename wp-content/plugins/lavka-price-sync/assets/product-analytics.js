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
    views: Array.from(root.querySelectorAll('[data-lps-pa-view]')),
    filters: document.getElementById('lps-pa-filters'),
    supplierMode: document.getElementById('lps-pa-supplier-mode'),
    suppliers: document.getElementById('lps-pa-suppliers'),
    reset: document.getElementById('lps-pa-reset'),
    table: document.getElementById('lps-pa-products'),
    head: document.getElementById('lps-pa-products-head'),
    body: document.querySelector('#lps-pa-products tbody'),
    tableMeta: document.getElementById('lps-pa-table-meta'),
    pagination: document.getElementById('lps-pa-pagination'),
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
    productsRequest: 0
  };

  const columns = [
    ['sku', t.sku || 'SKU', 'sku'],
    ['product_name', t.product || 'Product', 'product_name'],
    ['physical_quantity', t.physicalQuantity || 'Physical quantity'],
    ['reserved_quantity', t.reservedQuantity || 'Reserved quantity'],
    ['available_quantity', t.availableQuantity || 'Available quantity'],
    ['accounting_price', t.accountingPrice || 'Accounting price'],
    ['inventory_value', t.inventoryValue || 'Capital in stock', 'inventory_value'],
    ['sold_units_90d', t.sales90 || 'Sales, 90 days'],
    ['sold_units_365d', t.sales365 || 'Sales, 365 days', 'sold_units_365d'],
    ['revenue_365d', t.revenue365 || 'Revenue, 365 days', 'revenue_365d'],
    ['gross_profit_365d', t.grossProfit365 || 'Gross profit, 365 days', 'gross_profit_365d'],
    ['inventory_turns_365d', t.turns || 'Inventory turns', 'inventory_turns_365d'],
    ['gmroi_365d', t.gmroi || 'GMROI', 'gmroi_365d'],
    ['coverage_days', t.coverage || 'Coverage, days', 'coverage_days'],
    ['last_sale_date', t.lastSale || 'Last sale', 'last_sale_date'],
    ['last_receipt_date', t.lastReceipt || 'Last receipt'],
    ['states', t.status || 'Status']
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
        const caption = `${t.warehouse || 'Warehouse'} #${scope.warehouse_id} · ${scope.source_database}`;
        el.scope.appendChild(new Option(caption, value));
      });
      if (stored && state.scopes.some((scope) => `${scope.source_database}|${scope.warehouse_id}` === stored)) {
        el.scope.value = stored;
      }
      selectScope(el.scope.value);
      await loadFilterOptions();
      await loadReport();
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
    const data = await request('filter_options', scopePayload());
    const suppliers = Array.isArray(data.suppliers) ? data.suppliers : [];
    el.suppliers.replaceChildren(...suppliers.map((supplier) => new Option(supplier, supplier)));
    if (!suppliers.length) {
      const empty = new Option(t.noSuppliers || 'No suppliers are available for this warehouse.', '');
      empty.disabled = true;
      el.suppliers.append(empty);
      el.supplierMode.value = 'ANY';
    }
    syncSupplierFilter();
  }

  async function loadReport() {
    if (!state.sourceDatabase || !state.warehouseId) return;
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
      severity: form.get('severity') || '',
      sales: form.get('sales') || '',
      supplierMode: form.get('supplierMode') || 'ANY',
      supplierValues: form.getAll('supplierValues[]').join('\n'),
      inventoryMin: form.get('inventoryMin') || '',
      inventoryMax: form.get('inventoryMax') || '',
      lastSaleFrom: form.get('lastSaleFrom') || '',
      lastSaleTo: form.get('lastSaleTo') || '',
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
    } else if (['accounting_price','inventory_value','revenue_365d','gross_profit_365d'].includes(key)) {
      td.textContent = formatMoney(row[key]);
      if (key === 'gross_profit_365d' && numeric(row[key]) < 0) td.classList.add('is-negative');
    } else if (['inventory_turns_365d','gmroi_365d'].includes(key)) {
      td.textContent = formatRatio(row[key]);
    } else if (key === 'coverage_days') {
      td.textContent = formatNumber(row[key], 0);
    } else if (['last_sale_date','last_receipt_date'].includes(key)) {
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

  function metricGrid(row) {
    const grid = node('dl', 'lps-pa-metric-grid');
    const values = [
      [t.physicalQuantity, formatNumber(row.physical_quantity)],
      [t.reservedQuantity, formatNumber(row.reserved_quantity)],
      [t.availableQuantity, formatNumber(row.available_quantity)],
      [t.inventoryValue, formatMoney(row.inventory_value)],
      [t.sales365, formatNumber(row.sold_units_365d)],
      [t.revenue365, formatMoney(row.revenue_365d)],
      [t.grossProfit365, formatMoney(row.gross_profit_365d)],
      [t.turns, formatRatio(row.inventory_turns_365d)],
      [t.gmroi, formatRatio(row.gmroi_365d)],
      [t.coverage, formatNumber(row.coverage_days, 0)],
      [t.lastSale, formatDate(row.last_sale_date)],
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
      t.averageCapital,t.turns,t.gmroi,t.sellThrough
    ];
    const keys = [
      'month_start','opening_quantity','closing_quantity','opening_inventory_value',
      'closing_inventory_value','receipt_quantity','receipt_cost','sales_quantity','sales_revenue',
      'sales_cogs','gross_profit','return_quantity','return_revenue','average_inventory_value',
      'inventory_turns','gmroi','sell_through_percent'
    ];
    const trh = node('tr'); headers.forEach((caption) => trh.append(node('th', '', caption || '')));
    const thead = node('thead'); thead.append(trh); table.append(thead);
    const tbody = node('tbody');
    months.forEach((row) => {
      const tr = node('tr');
      keys.forEach((key) => {
        const money = ['opening_inventory_value','closing_inventory_value','receipt_cost','sales_revenue','sales_cogs','gross_profit','return_revenue','average_inventory_value'].includes(key);
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
    selectScope(el.scope.value);
    el.supplierMode.value = 'ANY';
    try {
      await loadFilterOptions();
      await loadReport();
    } catch (error) {
      showMessage(error.message || String(error), 'error');
    }
  });
  el.reload.addEventListener('click', async () => {
    try {
      await loadFilterOptions();
      await loadReport();
    } catch (error) {
      showMessage(error.message || String(error), 'error');
    }
  });
  el.supplierMode.addEventListener('change', syncSupplierFilter);
  el.filters.addEventListener('submit', (event) => { event.preventDefault(); state.page = 1; loadProducts().catch((error) => showMessage(error.message, 'error')); });
  el.reset.addEventListener('click', () => {
    el.filters.reset(); syncSupplierFilter(); state.view = 'all'; state.page = 1;
    state.sort = 'inventory_value'; state.direction = 'DESC';
    el.views.forEach((button) => button.classList.toggle('button-primary', button.dataset.lpsPaView === 'all'));
    loadProducts().catch((error) => showMessage(error.message, 'error'));
  });
  el.views.forEach((button) => button.addEventListener('click', () => {
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
    state.page = 1; loadProducts().catch((error) => showMessage(error.message, 'error'));
  });
  el.table.addEventListener('click', (event) => {
    const sort = event.target.closest('[data-sort]');
    if (sort) {
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
