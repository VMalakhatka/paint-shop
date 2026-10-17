/* One explicit request per gesture; an uncertain write is never retried automatically. */
(() => {
    'use strict';
    const config = window.pcoeCatalogDraft;
    if (!config) return;
    const toolbar = document.querySelector('.pcoe-catalog-toolbar');
    const token = () => [...window.crypto.getRandomValues(new Uint8Array(20))].map(value => value.toString(16).padStart(2, '0')).join('');
    async function send(container, fields, reload = false) {
        if (container.dataset.busy) return;
        const status = container.querySelector('.pcoe-draft-status') || toolbar?.querySelector('.pcoe-draft-status');
        const controls = [...container.querySelectorAll('button, input, select')];
        const disabled = controls.map(control => control.disabled);
        const key = container.dataset.requestKey || token();
        container.dataset.requestKey = key;
        container.dataset.busy = '1';
        controls.forEach(control => { control.disabled = true; });
        if (status) status.textContent = config.saving;
        const body = new URLSearchParams({action: 'pcoe_catalog_draft', nonce: config.nonce, request_key: key, ...fields});
        try {
            const response = await fetch(config.url, {method: 'POST', credentials: 'same-origin', body});
            const result = await response.json();
            if (!result.success) {
                if (status) status.textContent = result.data?.message || config.unknown;
                // A known rejection may be corrected. Unknown network outcomes retain the token.
                delete container.dataset.requestKey;
                return;
            }
            delete container.dataset.requestKey;
            if (status) status.textContent = config.saved;
            if (reload) window.location.reload();
        } catch (_) {
            if (status) status.textContent = config.unknown;
        } finally {
            delete container.dataset.busy;
            controls.forEach((control, index) => { control.disabled = disabled[index]; });
        }
    }
    document.addEventListener('click', event => {
        const button = event.target.closest('[data-pcoe-mode]');
        if (button && button.getAttribute('aria-pressed') !== 'true') {
            send(toolbar, {operation: 'mode', mode: button.dataset.pcoeMode}, true);
        }
    });
    document.addEventListener('change', event => {
        if (event.target.matches('[data-pcoe-draft]') && event.target.value) {
            send(toolbar, {operation: 'select', order_id: event.target.value}, true);
        }
    });
    document.addEventListener('submit', event => {
        const form = event.target;
        if (!form.matches('.pcoe-product-draft, [data-pcoe-create], [data-pcoe-update]')) return;
        event.preventDefault();
        const values = Object.fromEntries(new FormData(form));
        if (form.matches('[data-pcoe-create]')) {
            send(form, {operation: 'create', title: values.title}, true);
        } else if (form.matches('[data-pcoe-update]')) {
            send(form, {...values, operation: 'update', order_id: form.dataset.order}, true);
        } else {
            const selected = toolbar?.querySelector('[data-pcoe-draft]')?.value;
            if (!selected) { form.querySelector('.pcoe-draft-status').textContent = config.select; return; }
            const fields = {operation: 'add', order_id: selected, product_id: form.dataset.product, quantity: values.quantity};
            Object.entries(values).forEach(([name, value]) => {
                if (name.startsWith('attribute_')) fields[`attributes[${name}]`] = value;
            });
            send(form, fields);
        }
    });
})();
