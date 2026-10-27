(function () {
    'use strict';
    const root = document.querySelector('.pcoe-manager');
    if (!root) return;
    // Keep the original form/field DOM intact: other plugins bind to these nodes.
    const sections = [];
    const pageKey = ['customer_id', 'order_id', 'view'].map(key => new URLSearchParams(location.search).get(key) || '').join(':');
    root.querySelectorAll('.pcoe-card').forEach((card, index) => {
        const heading = [...card.querySelectorAll('h2,h3')].find(el => el.closest('.pcoe-card') === card);
        if (!heading) return;
        const title = heading.textContent.trim();
        let hash = 0;
        for (const char of title) hash = ((hash << 5) - hash + char.codePointAt(0)) | 0;
        const key = 'pcoe:sections:' + (pcoeManager.preferenceScope || '') + ':' + pageKey + ':' + (card.id || hash);
        const button = document.createElement('button');
        button.type = 'button'; button.className = 'pcoe-section-toggle';
        const bodyId = card.id || 'pcoe-section-' + index;
        if (!card.id) card.id = bodyId;
        button.setAttribute('aria-controls', bodyId);
        heading.append(button);
        // CSS also covers siblings inserted later by Folio AJAX and contextual help.
        for (let node = heading; node !== card; node = node.parentElement) {
            node.classList.add('pcoe-section-heading-path');
        }
        const setOpen = (open, remember = true) => {
            card.classList.toggle('pcoe-section-collapsed', !open);
            button.textContent = open ? pcoeManager.collapseSection : pcoeManager.expandSection;
            button.setAttribute('aria-expanded', String(open));
            button.setAttribute('aria-label', button.textContent + ': ' + title);
            if (remember) { try { sessionStorage.setItem(key, open ? 'open' : 'closed'); } catch (_) { /* Storage can be disabled. */ } }
        };
        let open = true;
        try { open = sessionStorage.getItem(key) !== 'closed'; } catch (_) { /* Keep usable without storage. */ }
        setOpen(open, false);
        button.addEventListener('click', () => setOpen(button.getAttribute('aria-expanded') !== 'true'));
        sections.push({card, setOpen});
    });
    if (sections.length) {
        const controls = document.createElement('div'); controls.className = 'pcoe-actions pcoe-section-controls';
        [[pcoeManager.collapseAll, false], [pcoeManager.expandAll, true]].forEach(([label, open]) => {
            const button = document.createElement('button'); button.type = 'button'; button.className = 'button'; button.textContent = label;
            button.addEventListener('click', () => sections.forEach(section => section.setOpen(open)));
            controls.append(button);
        });
        sections[0].card.before(controls);
        const reveal = target => sections.forEach(section => { if (target && section.card.contains(target)) section.setOpen(true); });
        const revealHash = () => {
            let id; try { id = decodeURIComponent(location.hash.slice(1)); } catch (_) { return; }
            if (id) reveal(document.getElementById(id));
        };
        window.addEventListener('hashchange', revealHash);
        root.addEventListener('click', event => {
            const link = event.target.closest('a[href^="#"]');
            if (link) { try { reveal(document.getElementById(decodeURIComponent(link.hash.slice(1)))); } catch (_) {} }
        });
        root.addEventListener('invalid', event => reveal(event.target), true);
        revealHash();
    }
    const status = root.querySelector('#pcoe-manager-status');
    const applyForm = root.querySelector('[data-apply-form]');
    const orderForm = root.querySelector('[data-preview-form] input[value="accounts"]')?.form;
    const groupHint = root.querySelector('[data-warehouse-description]');
    const groupDefault = groupHint?.textContent || '';
    function updateWarehouse() {
        if (!orderForm) return;
        const selected = orderForm.elements.warehouse_mode.value !== 'auto';
        const group = orderForm.elements.warehouse_id;
        group.disabled = !selected; group.required = selected;
        groupHint.textContent = selected && group.selectedOptions[0]?.dataset.folioWarehouses ? group.selectedOptions[0].dataset.folioWarehouses : groupDefault;
    }
    orderForm?.addEventListener('change', updateWarehouse);
    updateWarehouse();
    const quickForm = root.querySelector('input[value="quick_order"]')?.form;
    function updateAuxiliary() {
        if (!quickForm?.elements.pool_target) return;
        quickForm.querySelectorAll('input[name="auxiliary[]"]').forEach(input => {
            input.disabled = input.value === quickForm.elements.pool_target.value;
            if (input.disabled) input.checked = false;
        });
    }
    quickForm?.addEventListener('change', updateAuxiliary);
    updateAuxiliary();
    let busy = false;
    let dirty = false;
    function clearPreview() {
        if (!applyForm) return;
        applyForm.hidden = true;
        applyForm.elements.token.value = '';
        applyForm.elements.mode.value = '';
        applyForm.elements.confirmation.checked = false;
        const preview = root.querySelector('[data-preview-result]');
        if (preview) { preview.hidden = true; preview.innerHTML = ''; }
    }
    root.addEventListener('input', function (event) {
        const editor = event.target.closest('form[data-pcoe-manager]');
        if (editor?.elements.operation?.value === 'save') {
            dirty = true;
            root.querySelectorAll('[data-preview-form] button').forEach(button => { button.disabled = true; });
        }
        if (applyForm && !applyForm.contains(event.target)) {
            clearPreview();
        }
    });
    root.querySelectorAll('form[data-pcoe-manager]').forEach(function (form) {
        form.addEventListener('submit', async function (event) {
            event.preventDefault();
            if (busy || !form.reportValidity()) return;
            if (dirty && form.elements.operation.value !== 'save') { status.textContent = pcoeManager.saveFirst; return; }
            if (form.hasAttribute('data-preview-form')) clearPreview();
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
                    applyForm.elements.mode.value = data.mode;
                    applyForm.querySelector('[data-confirmation-label]').textContent = data.confirmation_label;
                    applyForm.querySelector('[data-apply-button]').textContent = data.apply_label;
                    applyForm.elements.confirmation.checked = false;
                    applyForm.hidden = !data.can_apply;
                    preview.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }
            } catch (error) {
                status.textContent = error.message || pcoeManager.error;
                status.className = 'notice notice-error';
                if (form === applyForm) clearPreview();
            } finally {
                busy = false; buttons.forEach(button => { button.disabled = dirty && !!button.closest('[data-preview-form]'); });
                form.removeAttribute('aria-busy');
            }
        });
    });
})();
