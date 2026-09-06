(function () {
    'use strict';
    const root = document.getElementById('lps-snapshot-queue');
    if (!root) return;
    const config = window.LPS_SNAPSHOT_QUEUE, t = config.i18n;
    const el = (name) => document.getElementById('lps-snapshot-' + name);
    const warehouses = document.getElementById('lps-pa-warehouses');
    let state = {}, busy = false, timer, uncertain = false;
    const label = (status) => t.statuses[status] || status || '—';
    const escape = (text) => String(text == null ? '' : text).replace(/[&<>"']/g, (c) => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    function controls() {
        el('start').disabled = busy || uncertain || !!state.active || !el('confirm').checked || !warehouses.selectedOptions.length;
        el('stop').disabled = busy || !state.active || !!state.stop_requested;
        el('horizon').disabled = busy || !!state.active;
        el('confirm').disabled = busy || !!state.active;
        el('spinner').classList.toggle('is-active', busy || !!state.active);
        root.setAttribute('aria-busy', String(busy || !!state.active));
    }
    function render() {
        const reports = state.reports || [];
        el('results').innerHTML = (state.warehouse_ids || []).map((id, index) => {
            const result = reports.find((item) => item.warehouseId === id);
            const option = Array.from(warehouses.options).find((item) => Number(item.value) === id);
            const status = result ? result.status : (index === state.index && state.active ? (state.phase === 'WAITING' ? 'WAITING' : 'RUNNING')
                : (index === state.index && state.phase === 'POLLING' ? state.status : 'NOT_STARTED'));
            return '<tr><th>' + escape(option ? option.textContent : id) + '</th><td>' + escape(label(status)) + '</td><td>' + escape(result && result.generationId || '—') + '</td><td>' + escape(result && result.analyticsSchemaVersion || '—') + '</td></tr>';
        }).join('');
        const phase = state.active && state.snapshot ? state.snapshot.phase : '';
        const progress = state.active ? ' · ' + reports.length + '/' + (state.warehouse_ids || []).length : '';
        el('message').textContent = state.id ? label(state.status) + progress + ' · ' + (state.message || '') + (phase ? ' · ' + phase : '') : t.idle;
        if (state.status === 'COMPLETED' || state.status === 'COMPLETED_WITH_WARNINGS') el('message').textContent += ' ' + t.reload;
        el('details').textContent = JSON.stringify(state, null, 2);
        controls();
    }
    async function request(operation, payload) {
        if (busy) return;
        clearTimeout(timer); busy = true; controls();
        el('message').textContent = t.loading;
        try {
            const response = await fetch(config.ajaxUrl, {method:'POST',credentials:'same-origin',body:new URLSearchParams({action:'lps_analytics_snapshot_queue',_ajax_nonce:config.nonce,operation,payload:JSON.stringify(payload || {})})});
            const json = await response.json();
            if (!response.ok || !json.success) throw new Error(json.data && json.data.message || t.error);
            state = json.data; uncertain = false; render();
        } catch (error) {
            if (operation === 'start') uncertain = true;
            el('message').textContent = (error.message || t.error) + ' ' + t.error;
        } finally {
            busy = false; controls();
            timer = setTimeout(() => request(state.active ? 'tick' : 'status'), state.active || uncertain ? 5000 : 30000);
        }
    }
    el('start').addEventListener('click', () => {
        if (!el('horizon').reportValidity()) return;
        request('start', {warehouseIds:Array.from(warehouses.selectedOptions).map((o)=>Number(o.value)),horizonMonths:Number(el('horizon').value),confirmed:el('confirm').checked});
    });
    el('stop').addEventListener('click', () => request('stop'));
    el('confirm').addEventListener('change', controls);
    warehouses.addEventListener('change', controls);
    request('status');
}());
