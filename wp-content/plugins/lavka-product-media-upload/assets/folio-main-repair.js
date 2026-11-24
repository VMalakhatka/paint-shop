(function () {
    'use strict';

    const panel = document.getElementById('lpmu-folio-repair');
    if (!panel) { return; }

    const { ajaxUrl, nonce, strings } = window.LPMU_DATA;
    const form = panel.querySelector('form');
    const skuInput = form.elements.sku;
    const submit = form.querySelector('[type="submit"]');
    const apply = panel.querySelector('[data-apply]');
    const preview = panel.querySelector('[data-preview]');
    const status = panel.querySelector('[data-status]');
    let token = '';
    let busy = false;

    function reset() {
        token = '';
        preview.hidden = true;
        status.textContent = '';
    }

    function setBusy(value) {
        busy = value;
        panel.setAttribute('aria-busy', String(value));
        skuInput.disabled = submit.disabled = value;
        apply.disabled = value || !token;
    }

    async function request(step, fields) {
        const body = new URLSearchParams({ action: 'lpmu_main_repair', nonce, step, ...fields });
        const response = await fetch(ajaxUrl, { method: 'POST', credentials: 'same-origin', body });
        const { success, data } = await response.json();
        if (!response.ok || !success) { throw new Error(data?.message || strings.requestFailed); }
        return data;
    }

    skuInput.addEventListener('input', reset);
    document.getElementById('lpmu-results').addEventListener('click', (event) => {
        const button = event.target.closest('[data-lpmu-main-repair]');
        if (!button || busy) { return; }
        reset();
        panel.open = true;
        skuInput.value = button.dataset.lpmuMainRepair;
        skuInput.focus();
        panel.scrollIntoView({ block: 'center' });
    });

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (busy) { return; }
        reset();
        setBusy(true);
        status.textContent = strings.checkingMainRepair;
        try {
            const data = await request('preview', { sku: skuInput.value.trim() });
            panel.querySelector('[data-product]').textContent = data.sku + ' · ' + data.product_name;
            panel.querySelector('[data-before]').textContent = data.before || '—';
            panel.querySelector('[data-after]').textContent = data.after;
            panel.querySelector('[data-photo]').href = data.url;
            token = data.token;
            apply.hidden = !token;
            preview.hidden = false;
            status.textContent = token ? strings.confirmMainRepair : strings.mainRepairAlreadyAligned;
        } catch (error) {
            status.textContent = error instanceof SyntaxError ? strings.requestFailed : error.message;
        } finally {
            setBusy(false);
        }
    });

    apply.addEventListener('click', async () => {
        if (busy || !token) { return; }
        setBusy(true);
        status.textContent = strings.savingMainRepair;
        try {
            await request('apply', { token });
            status.textContent = strings.mainRepairDone;
        } catch (error) {
            status.textContent = error instanceof SyntaxError ? strings.requestFailed : error.message;
        } finally {
            token = '';
            apply.hidden = true;
            setBusy(false);
        }
    });
}());
