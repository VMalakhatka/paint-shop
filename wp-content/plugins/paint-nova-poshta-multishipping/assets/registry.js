/* Private manager page: preview is immutable, edits invalidate it, writes are never auto-retried. */
(() => {
    'use strict';
    const cfg = window.pnpmRegistry;
    if (!cfg) return;
    const display = value => value === null ? '—' : (typeof value === 'object' ? JSON.stringify(value, null, 2) : String(value));
    const table = data => {
        const list = document.createElement('dl'); list.className = 'pnpm-data';
        Object.entries(data).forEach(([key, value]) => {
            const term = document.createElement('dt'), detail = document.createElement('dd');
            term.textContent = cfg.labels[key] || key;
            detail.textContent = cfg.labels[String(value)] || display(value);
            list.append(term, detail);
        });
        return list;
    };
    document.querySelectorAll('.pnpm-registry-form').forEach(form => {
        let token = '', busy = false, generation = 0;
        const message = form.querySelector('.pnpm-message'), preview = form.querySelector('.pnpm-preview');
        const confirm = form.querySelector('.pnpm-confirm'), agreed = form.querySelector('[name="confirmed"]');
        const apply = form.querySelector('.pnpm-apply'), next = form.querySelector('.pnpm-next');
        const invalidate = () => {
            ++generation;
            token = '';
            if (confirm) { confirm.hidden = true; agreed.checked = false; apply.disabled = true; preview.replaceChildren(); }
        };
        form.addEventListener('input', event => {
            if (event.target === agreed) { apply.disabled = !token || !agreed.checked || busy; return; }
            invalidate(); message.textContent = '';
            if (next) { next.hidden = true; form.elements.previous.value = ''; }
        });
        const request = async (command, continuation = false) => {
            if (busy || form.dataset.pickerBlocked === '1' || !form.reportValidity()) return;
            if (command === 'confirm' && (!token || !agreed.checked)) return;
            if (command === 'history' && !continuation) { form.elements.previous.value = ''; if (next) next.hidden = true; }
            const savedToken = token;
            const body = new FormData(form);
            body.set('action', 'pnpm_registry'); body.set('nonce', cfg.nonce); body.set('command', command);
            if (command === 'confirm') body.set('token', token);
            else invalidate();
            const requestGeneration = generation;
            busy = true;
            form.dataset.busy = '1';
            if (command === 'classify') form.dispatchEvent(new Event('pnpm:classification-saving'));
            const controls = [...form.querySelectorAll('input,select,textarea,button')];
            const previousDisabled = controls.map(control => control.disabled);
            controls.forEach(control => { control.disabled = true; });
            message.textContent = cfg.loading;
            try {
                const response = await fetch(cfg.url, {method:'POST', body, credentials:'same-origin', cache:'no-store'});
                const result = await response.json();
                if (!response.ok || !result.success) throw new Error(result.data?.message || cfg.failure);
                const data = result.data;
                if (data.token) {
                    // Another form may have changed the saved classification during this read.
                    if (requestGeneration !== generation) return;
                    token = data.token; preview.replaceChildren();
                    if (data.document) {
                        const header = {...data.document}, order = header.order_snapshot;
                        delete header.order_snapshot;
                        preview.append(table(header));
                        if (order) { const shown = {...order}; delete shown.order_modified; preview.append(table(shown)); }
                    }
                    if (data.scope) preview.append(table(data.scope));
                    (data.rows || []).forEach(row => {
                        const details = document.createElement('details'), summary = document.createElement('summary');
                        summary.textContent = row.ttn; details.append(summary, table(row.data)); preview.append(details);
                    });
                    confirm.hidden = false; message.textContent = cfg.preview;
                } else if (command === 'classify' && data.classification) {
                    invalidate(); message.textContent = cfg.saved;
                    form.dispatchEvent(new CustomEvent('pnpm:classification-saved', {detail: data}));
                } else if (command === 'confirm' && form.dataset.command === 'history') {
                    invalidate(); form.elements.previous.value = savedToken;
                    next.hidden = !data.scope?.next_page;
                    message.textContent = data.scope?.next_page ? cfg.more : cfg.empty;
                } else {
                    invalidate(); message.textContent = cfg.saved;
                    window.location.assign(cfg.page + (data.id ? '&id=' + encodeURIComponent(data.id) : ''));
                }
            } catch (error) {
                // Unknown write outcome: retain token for a deliberate idempotent confirmation, never retry automatically.
                message.textContent = error.message || cfg.failure;
            } finally {
                busy = false;
                delete form.dataset.busy;
                controls.forEach((control, i) => { control.disabled = previousDisabled[i]; });
                if (apply) apply.disabled = !token || !agreed.checked;
                if (command === 'classify') form.dispatchEvent(new Event('pnpm:classification-finished'));
            }
        };
        form.addEventListener('submit', event => { event.preventDefault(); request(form.dataset.command); });
        if (apply) apply.addEventListener('click', () => request('confirm'));
        if (next) next.addEventListener('click', () => request('history', true));
    });
})();
