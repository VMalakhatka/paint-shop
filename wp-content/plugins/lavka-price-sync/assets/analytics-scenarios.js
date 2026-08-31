(() => {
  'use strict';

  const config = window.LPS_ANALYTICS_SCENARIOS || {};
  const t = config.i18n || {};
  const root = document.getElementById('lps-analytics-scenarios');
  if (!root) return;

  const el = {
    message: document.getElementById('lps-as-message'), spinner: document.getElementById('lps-as-spinner'),
    list: document.getElementById('lps-as-list'), form: document.getElementById('lps-as-form'),
    id: document.getElementById('lps-as-id'), version: document.getElementById('lps-as-version'),
    name: document.getElementById('lps-as-name'), description: document.getElementById('lps-as-description'),
    visibility: document.getElementById('lps-as-visibility'), status: document.getElementById('lps-as-status'),
    activeTab: document.getElementById('lps-as-active-tab'), scope: document.getElementById('lps-as-scope'),
    scopeMeta: document.getElementById('lps-as-scope-meta'), supplierMode: document.getElementById('lps-as-supplier-mode'),
    suppliers: document.getElementById('lps-as-suppliers'), documentType: document.getElementById('lps-as-document-type'),
    operationKind: document.getElementById('lps-as-operation-kind'), newButton: document.getElementById('lps-as-new'),
    duplicate: document.getElementById('lps-as-duplicate'), archive: document.getElementById('lps-as-archive'),
    save: document.getElementById('lps-as-save'), revisions: document.getElementById('lps-as-revisions'),
    revisionList: document.getElementById('lps-as-revision-list'), editorMeta: document.getElementById('lps-as-editor-meta')
  };

  const state = {items: [], scopes: [], selectedId: 0, loading: false};

  function node(tag, className = '', text = '') {
    const item = document.createElement(tag);
    if (className) item.className = className;
    if (text !== '') item.textContent = String(text);
    return item;
  }

  function showMessage(message = '', kind = 'info') {
    el.message.hidden = !message;
    el.message.className = `lps-as-message is-${kind}`;
    el.message.textContent = message;
  }

  function setBusy(busy) {
    state.loading = busy;
    el.spinner.classList.toggle('is-active', busy);
    el.newButton.disabled = busy;
    el.save.disabled = busy;
    el.duplicate.disabled = busy || !state.selectedId;
    el.archive.disabled = busy || !state.selectedId;
  }

  async function request(operation, data = {}) {
    const body = new URLSearchParams({action: 'lps_analytics_scenarios', _ajax_nonce: config.nonce || '', operation, ...data});
    const response = await fetch(config.ajaxUrl, {
      method: 'POST', credentials: 'same-origin',
      headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'}, body: body.toString()
    });
    let payload;
    try { payload = await response.json(); } catch (error) { throw new Error(`${t.loadFailed || 'Analytics scenarios could not be loaded.'} HTTP ${response.status}`); }
    if (!response.ok || !payload?.success) throw new Error(payload?.data?.message || t.loadFailed || 'Analytics scenarios could not be loaded.');
    return payload.data;
  }

  function emptyProfile() {
    return {
      schemaVersion: 1, context: {sourceDatabase: '', warehouseIds: []},
      products: {supplierMode: 'ANY', supplierValues: [], supplierQuality: 'ANY', availableSign: 'ANY', accountingPriceMode: 'ANY', demandPeriod: '365', regularDemand: 'ANY', oneOffDemand: 'ANY', financePeriod: '365', perPage: '50', view: 'all', sort: 'inventory_value', direction: 'DESC'},
      movements: {movementPerPage: '50'}, presentation: {activeTab: 'products'}
    };
  }

  function renderScopes() {
    el.scope.replaceChildren(new Option(t.noScope || 'Select an available Folio warehouse.', ''));
    state.scopes.forEach((scope) => {
      const value = `${scope.source_database}|${scope.warehouse_id}`;
      el.scope.append(new Option(`#${scope.warehouse_id} · ${scope.source_database}`, value));
    });
  }

  function renderList() {
    el.list.replaceChildren();
    if (!state.items.length) {
      el.list.append(node('p', 'description', t.noScenarios || 'No analytics scenarios have been created yet.'));
      return;
    }
    state.items.forEach((scenario) => {
      const button = node('button', `lps-as-list-item${scenario.id === state.selectedId ? ' is-selected' : ''}`);
      button.type = 'button'; button.dataset.scenarioId = String(scenario.id);
      button.append(node('strong', '', scenario.name));
      const meta = node('span', 'lps-as-list-meta');
      meta.append(
        node('span', `lps-as-badge is-${scenario.visibility}`, scenario.visibility === 'personal' ? (t.personal || 'Personal') : (t.shared || 'Shared')),
        node('span', `lps-as-badge is-${scenario.status}`, scenario.status === 'archived' ? (t.archivedStatus || 'Archived') : (t.active || 'Active')),
        node('span', '', `v${scenario.version}`)
      );
      button.append(meta);
      if (scenario.description) button.append(node('small', '', scenario.description));
      el.list.append(button);
    });
  }

  function setFields(selector, values) {
    root.querySelectorAll(selector).forEach((field) => {
      const key = field.dataset.scenarioProduct || field.dataset.scenarioMovement;
      const value = values?.[key] ?? '';
      if (field.multiple) {
        const selected = new Set(Array.isArray(value) ? value.map(String) : []);
        Array.from(field.options).forEach((option) => { option.selected = selected.has(option.value); });
      } else field.value = String(value ?? '');
    });
  }

  function collectFields(selector, datasetKey) {
    const result = {};
    root.querySelectorAll(selector).forEach((field) => {
      const key = field.dataset[datasetKey];
      result[key] = field.multiple ? Array.from(field.selectedOptions).map((option) => option.value) : field.value;
    });
    return result;
  }

  function syncSupplierMode() {
    el.suppliers.disabled = el.supplierMode.value === 'ANY';
    if (el.suppliers.disabled) Array.from(el.suppliers.options).forEach((option) => { option.selected = false; });
  }

  function preserveUnavailableOption(select, value) {
    const normalized = String(value || '').trim();
    if (!normalized || Array.from(select.options).some((option) => option.value === normalized)) return;
    select.append(new Option(`${normalized} · ${t.savedUnavailableValue || 'Saved value is not available in the current snapshot'}`, normalized));
  }

  async function loadScopeOptions(preserve = {}) {
    const [sourceDatabase = '', warehouseId = '0'] = String(el.scope.value || '').split('|');
    el.scopeMeta.textContent = '';
    el.suppliers.replaceChildren(); el.documentType.replaceChildren(new Option('—', '')); el.operationKind.replaceChildren(new Option('—', ''));
    if (!sourceDatabase || !Number(warehouseId)) return;
    try {
      const data = await request('scope_options', {sourceDatabase, warehouseId});
      const suppliers = Array.isArray(data.suppliers) ? data.suppliers : [];
      suppliers.forEach((item) => {
        const value = String(item?.value || '').trim();
        if (value) el.suppliers.append(new Option(`${value} (${Number(item.products) || 0})`, value));
      });
      (preserve.supplierValues || []).forEach((value) => preserveUnavailableOption(el.suppliers, value));
      const selectedSuppliers = new Set((preserve.supplierValues || []).map(String));
      Array.from(el.suppliers.options).forEach((option) => { option.selected = selectedSuppliers.has(option.value); });
      const fill = (select, items) => {
        select.replaceChildren(new Option('—', ''));
        (Array.isArray(items) ? items : []).forEach((item) => {
          const value = String(item?.value || '').trim();
          if (value) select.append(new Option(`${value} (${Number(item.movements) || 0})`, value));
        });
      };
      fill(el.documentType, data.movementOptions?.documentTypes);
      fill(el.operationKind, data.movementOptions?.operationKinds);
      preserveUnavailableOption(el.documentType, preserve.documentType);
      preserveUnavailableOption(el.operationKind, preserve.operationKind);
      if (preserve.documentType && Array.from(el.documentType.options).some((item) => item.value === preserve.documentType)) el.documentType.value = preserve.documentType;
      if (preserve.operationKind && Array.from(el.operationKind.options).some((item) => item.value === preserve.operationKind)) el.operationKind.value = preserve.operationKind;
      el.scopeMeta.textContent = `schema v${Number(data.analyticsSchemaVersion) || 1}`;
      syncSupplierMode();
    } catch (error) {
      showMessage(`${t.scopeLoadFailed || 'Warehouse filter options could not be loaded.'} ${error.message}`, 'error');
    }
  }

  async function editScenario(scenario) {
    state.selectedId = Number(scenario?.id) || 0;
    const profile = scenario?.profile || emptyProfile();
    el.id.value = state.selectedId || ''; el.version.value = scenario?.version || 0;
    el.name.value = scenario?.name || ''; el.description.value = scenario?.description || '';
    el.visibility.value = scenario?.visibility || 'shared'; el.status.value = scenario?.status || 'active';
    el.activeTab.value = profile.presentation?.activeTab || 'products';
    const warehouseId = Number(profile.context?.warehouseIds?.[0]) || 0;
    const scopeValue = `${profile.context?.sourceDatabase || ''}|${warehouseId}`;
    el.scope.value = Array.from(el.scope.options).some((option) => option.value === scopeValue) ? scopeValue : '';
    setFields('[data-scenario-product]', profile.products || {});
    setFields('[data-scenario-movement]', profile.movements || {});
    await loadScopeOptions({
      supplierValues: profile.products?.supplierValues || [],
      documentType: profile.movements?.documentType || '', operationKind: profile.movements?.operationKind || ''
    });
    el.duplicate.disabled = !state.selectedId; el.archive.disabled = !state.selectedId || scenario?.status === 'archived';
    el.editorMeta.textContent = state.selectedId ? `${t.version || 'Version'} ${scenario.version} · ${scenario.owner || ''}` : '';
    renderList(); await loadRevisions();
  }

  function collectProfile() {
    const [sourceDatabase = '', warehouseId = '0'] = String(el.scope.value || '').split('|');
    return {
      schemaVersion: 1,
      context: {sourceDatabase, warehouseIds: Number(warehouseId) ? [Number(warehouseId)] : []},
      products: collectFields('[data-scenario-product]', 'scenarioProduct'),
      movements: collectFields('[data-scenario-movement]', 'scenarioMovement'),
      presentation: {activeTab: el.activeTab.value === 'movements' ? 'movements' : 'products'}
    };
  }

  async function loadRevisions() {
    el.revisions.hidden = !state.selectedId; el.revisionList.replaceChildren();
    if (!state.selectedId) return;
    const data = await request('revisions', {scenarioId: state.selectedId});
    (data.items || []).forEach((revision) => el.revisionList.append(node('div', 'lps-as-revision', `v${revision.version} · ${revision.changedBy} · ${revision.changedAt}`)));
  }

  async function refresh(selectedId = 0) {
    setBusy(true);
    try {
      const data = await request('bootstrap');
      state.items = Array.isArray(data.items) ? data.items : []; state.scopes = Array.isArray(data.scopes) ? data.scopes : [];
      renderScopes(); renderList();
      const wanted = Number(selectedId) || Number(new URLSearchParams(location.search).get('scenario')) || 0;
      await editScenario(state.items.find((item) => item.id === wanted) || null);
    } catch (error) { showMessage(error.message, 'error'); } finally { setBusy(false); }
  }

  el.list.addEventListener('click', (event) => {
    const button = event.target.closest('[data-scenario-id]');
    if (!button) return;
    editScenario(state.items.find((item) => item.id === Number(button.dataset.scenarioId))).catch((error) => showMessage(error.message, 'error'));
  });
  el.newButton.addEventListener('click', () => editScenario(null));
  el.scope.addEventListener('change', () => loadScopeOptions());
  el.supplierMode.addEventListener('change', syncSupplierMode);
  el.form.addEventListener('submit', async (event) => {
    event.preventDefault();
    if (!el.scope.value) { showMessage(t.noScope || 'Select an available Folio warehouse.', 'error'); el.scope.focus(); return; }
    setBusy(true);
    try {
      const data = await request('save', {
        scenarioId: el.id.value, expectedVersion: el.version.value, name: el.name.value,
        description: el.description.value, visibility: el.visibility.value, status: el.status.value,
        profileJson: JSON.stringify(collectProfile())
      });
      state.items = data.items || []; showMessage(t.saved || 'The analytics scenario has been saved.', 'success');
      renderList(); await editScenario(state.items.find((item) => item.id === Number(data.selectedId)) || null);
    } catch (error) { showMessage(error.message, 'error'); } finally { setBusy(false); }
  });
  el.duplicate.addEventListener('click', async () => {
    setBusy(true);
    try { const data = await request('duplicate', {scenarioId: state.selectedId}); state.items = data.items || []; await editScenario(state.items.find((item) => item.id === Number(data.selectedId))); }
    catch (error) { showMessage(error.message, 'error'); } finally { setBusy(false); }
  });
  el.archive.addEventListener('click', async () => {
    if (!state.selectedId || !confirm(t.confirmArchive || 'Archive this analytics scenario?')) return;
    setBusy(true);
    try { const data = await request('archive', {scenarioId: state.selectedId, expectedVersion: el.version.value}); state.items = data.items || []; showMessage(t.archived || 'The analytics scenario has been archived.', 'success'); await editScenario(null); }
    catch (error) { showMessage(error.message, 'error'); } finally { setBusy(false); }
  });

  refresh();
})();
