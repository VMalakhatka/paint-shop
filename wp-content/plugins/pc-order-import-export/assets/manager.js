(function () {
    'use strict';
    const root = document.querySelector('.pcoe-manager');
    if (!root) return;
    const status = root.querySelector('#pcoe-manager-status');
    const applyForm = root.querySelector('[data-apply-form]');
    let busy = false;
    let dirty = false;
    root.addEventListener('input', function (event) {
        const editor = event.target.closest('form[data-pcoe-manager]');
        if (editor?.elements.operation?.value === 'save') {
            dirty = true;
            const previewButton = root.querySelector('[data-preview-form] button');
            if (previewButton) previewButton.disabled = true;
        }
        if (applyForm && !applyForm.contains(event.target)) {
            applyForm.hidden = true;
            applyForm.elements.token.value = '';
        }
    });
    root.querySelectorAll('form[data-pcoe-manager]').forEach(function (form) {
        form.addEventListener('submit', async function (event) {
            event.preventDefault();
            if (busy || !form.reportValidity()) return;
            if (dirty && form.elements.operation.value !== 'save') { status.textContent = pcoeManager.saveFirst; return; }
            const body = new FormData(form);
            if (form.elements.operation.value === 'save') {
                const quantities = {};
                [...body.keys()].forEach(key => {
                    const match = /^quantity\[(\d+)\]$/.exec(key);
                    if (match) { quantities[match[1]] = body.get(key); body.delete(key); }
                });
                body.set('quantities_json', JSON.stringify(quantities));
            }
            body.set('action', 'pcoe_manager');
            body.set('manager_nonce', pcoeManager.nonce);
            const buttons = [...root.querySelectorAll('form[data-pcoe-manager] button')];
            busy = true; buttons.forEach(button => { button.disabled = true; });
            form.setAttribute('aria-busy', 'true');
            status.textContent = pcoeManager.busy;
            status.className = 'notice notice-info';
            try {
                const response = await fetch(pcoeManager.url, { method: 'POST', body, credentials: 'same-origin' });
                const json = await response.json();
                if (!response.ok || !json.success) throw new Error(json.data?.message || pcoeManager.error);
                const data = json.data;
                status.textContent = data.message || '';
                status.className = 'notice notice-success';
                if (data.report_html) {
                    const report = root.querySelector('[data-import-report]');
                    report.innerHTML = data.report_html;
                    if (data.url) {
                        const link = document.createElement('a');
                        link.href = data.url; link.className = 'button button-primary';
                        link.textContent = data.message; report.append(link);
                    } else {
                        form.elements.request_key.value = 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) { var r = crypto.getRandomValues(new Uint8Array(1))[0] & 15; return (c === 'x' ? r : (r & 3) | 8).toString(16); });
                    }
                } else if (data.url) {
                    window.location.assign(data.url);
                }
                if (data.preview_html) {
                    const preview = root.querySelector('[data-preview-result]');
                    preview.innerHTML = data.preview_html; preview.hidden = false;
                    applyForm.elements.token.value = data.token;
                    applyForm.elements.confirmation.checked = false;
                    applyForm.hidden = false;
                    preview.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }
            } catch (error) {
                status.textContent = error.message || pcoeManager.error;
                status.className = 'notice notice-error';
                if (form === applyForm) { applyForm.hidden = true; applyForm.elements.token.value = ''; }
            } finally {
                busy = false; buttons.forEach(button => { button.disabled = dirty && !!button.closest('[data-preview-form]'); });
                form.removeAttribute('aria-busy');
            }
        });
    });
})();
