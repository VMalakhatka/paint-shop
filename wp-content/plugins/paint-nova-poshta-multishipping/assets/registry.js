/* Private manager page: preview is immutable, edits invalidate it, writes are never auto-retried. */
(() => {
    'use strict';
    const cfg = window.pnpmRegistry;
    if (!cfg) return;
    const display = value => value === null ? '—' : (typeof value === 'object' ? JSON.stringify(value, null, 2) : String(value));
    const format = (text, values) => text.replace(/\{(\w+)\}/g, (_, key) => String(values[key] ?? ''));
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
    const heading = key => { const el = document.createElement('h4'); el.textContent = cfg.labels[key]; return el; };
    const paragraph = key => { const el = document.createElement('p'); el.textContent = cfg.labels[key]; return el; };
    const nonempty = data => Object.fromEntries(Object.entries(data).filter(([,value]) => value !== '' && value !== null));
    const folioHeaders = (target, documents, title, empty) => {
        target.append(heading(title));
        if (!documents.length) target.append(paragraph(empty));
        documents.forEach(document => target.append(table(nonempty(document))));
    };
    const wooPreview = (target, document) => {
        const order = {...document.order_snapshot}, documents = order.folio_documents || [];
        delete order.folio_documents; delete order.order_modified;
        if (!order.parent_order_id) delete order.parent_order_id;
        target.append(heading('order_snapshot'), table(nonempty(order)));
        if (document.evidence) target.append(table({evidence_optional: document.evidence}));
        folioHeaders(target, documents, 'folio_documents', 'no_order_folio');
        const context = document.registry_context;
        if (context) {
            target.append(heading('registry_context'), table({partner: context.partner || '—'}));
            folioHeaders(target, context.documents || [], 'registry_documents', 'no_registry_folio');
        }
        target.append(paragraph('folio_context_hint'));
    };
    const basisAccount = (target, document) => {
        const account = document.related_account;
        if (!account) return;
        const section = window.document.createElement('section');
        section.className = 'pnpm-basis-account';
        const shown = {id: account.id, number: account.number, date: account.date,
            partner: account.partner, warehouse: account.warehouse, source: account.source};
        section.append(heading('related_account'), table(shown));
        const label = window.document.createElement('label'), choice = window.document.createElement('input');
        choice.type = 'checkbox'; choice.className = 'pnpm-basis-choice';
        choice.value = `${document.id}:${account.source}:${account.id}`;
        label.append(choice, window.document.createTextNode(' ' + cfg.labels.confirm_basis_account));
        section.append(label, paragraph('basis_account_optional')); target.append(section);
    };
    document.querySelectorAll('[data-link-target]').forEach(button => button.addEventListener('click', () => {
        const card = document.getElementById(button.dataset.linkTarget);
        const form = card?.querySelector(`form[data-command="${button.dataset.linkAction}"]`);
        if (!form) return;
        card.open = true;
        form.scrollIntoView({block: 'center'});
        form.querySelector('input:not([type="hidden"]),button[type="submit"]')?.focus({preventScroll: true});
    }));
    document.querySelectorAll('.pnpm-registry-form').forEach(form => {
        let token = '', busy = false, generation = 0;
        let rangeCursor = '', rangeStep = 0, rangePaused = false;
        const rangeStop = form.querySelector('.pnpm-range-stop'), rangeResume = form.querySelector('.pnpm-range-resume');
        const rangeSummary = document.createElement('div'), rangeRows = document.createElement('div');
        const rangeSeen = new Map();
        const message = form.querySelector('.pnpm-message'), preview = form.querySelector('.pnpm-preview');
        const confirm = form.querySelector('.pnpm-confirm'), agreed = form.querySelector('[name="confirmed"]');
        const apply = form.querySelector('.pnpm-apply'), next = form.querySelector('.pnpm-next');
        const invalidate = () => {
            ++generation;
            token = '';
            rangeCursor = ''; rangeStep = 0; rangeSeen.clear(); rangeRows.replaceChildren(); rangeSummary.replaceChildren();
            if (rangeResume) rangeResume.hidden = true;
            if (confirm) { confirm.hidden = true; agreed.checked = false; apply.disabled = true; preview.replaceChildren(); }
        };
        form.addEventListener('input', event => {
            if (event.target.classList.contains('pnpm-basis-choice')) { agreed.checked = false; apply.disabled = true; return; }
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
            if (command === 'confirm') {
                body.set('token', token);
                body.set('basis_accounts', JSON.stringify([...form.querySelectorAll('.pnpm-basis-choice:checked')].map(el => el.value)));
            }
            else if (!(command === 'history_range' && continuation)) invalidate();
            const requestGeneration = generation;
            busy = true;
            form.dataset.busy = '1';
            if (command === 'classify') form.dispatchEvent(new Event('pnpm:classification-saving'));
            const controls = [...form.querySelectorAll('input,select,textarea,button')];
            const previousDisabled = controls.map(control => control.disabled);
            controls.forEach(control => { control.disabled = true; });
            message.textContent = cfg.loading;
            const send = async payload => {
                const response = await fetch(cfg.url, {method:'POST', body:payload, credentials:'same-origin', cache:'no-store'});
                const result = await response.json();
                if (!response.ok || !result.success) throw new Error(result.data?.message || cfg.failure);
                return result.data;
            };
            try {
                let data;
                if (command === 'history_range') {
                    rangePaused = false; rangeStop.hidden = false; rangeStop.disabled = false; rangeResume.hidden = true;
                    preview.replaceChildren(rangeSummary, rangeRows);
                    if (!rangeCursor) { data = await send(body); rangeCursor = data.cursor; rangeStep = data.step; }
                    do {
                        if (rangePaused) break;
                        const stepBody = new FormData();
                        Object.entries({action:'pnpm_registry', nonce:cfg.nonce, command:'history_range_step', cursor:rangeCursor, step:rangeStep})
                            .forEach(([key,value]) => stepBody.set(key, value));
                        data = await send(stepBody);
                        if (requestGeneration !== generation) return;
                        rangeStep = data.step;
                        rangeSummary.replaceChildren(table(data.scope));
                        (data.rows || []).forEach(row => {
                            const previous = rangeSeen.get(row.ttn);
                            const details = document.createElement('details'), summary = document.createElement('summary');
                            summary.textContent = row.ttn; details.append(summary);
                            // Render full carrier fields only when opened, keeping large previews responsive.
                            details.addEventListener('toggle', () => { if (details.open && details.children.length === 1) details.append(table(row.data)); });
                            if (previous) previous.replaceWith(details); else rangeRows.append(details);
                            rangeSeen.set(row.ttn, details);
                        });
                        message.textContent = format(cfg.range.progress, {from:data.scope.window_from, to:data.scope.window_to,
                            page:data.scope.page, pages:data.scope.pages_read, count:data.scope.count});
                    } while (!data.done);
                    if (data?.done) { token = data.token; confirm.hidden = false; message.textContent = cfg.range.ready; rangeCursor = ''; }
                    else message.textContent = cfg.range.paused;
                } else data = await send(body);
                if (command === 'history_range') {
                    // Staged rows above are confirmed only once, after the final empty page of the final month.
                } else if (data.token) {
                    // Another form may have changed the saved classification during this read.
                    if (requestGeneration !== generation) return;
                    token = data.token; preview.replaceChildren();
                    if (data.document) {
                        if (data.document.type === 'WOO_ORDER') wooPreview(preview, data.document);
                        else {
                            const header = {...data.document}, account = header.related_account, values = header.values;
                            delete header.values;
                            delete header.related_account;
                            preview.append(table(header));
                            if (values) { const shown={...values}; delete shown.schema_version; preview.append(heading('values'),table(shown)); }
                            if (account) basisAccount(preview, data.document);
                        }
                    }
                    (data.documents || []).forEach(document => {
                        const header = {...document}, account = header.related_account, values = header.values;
                        delete header.values;
                        delete header.related_account;
                        const section = window.document.createElement('section');
                        section.className = 'pnpm-batch-document'; section.append(table(header));
                        if (values) { const shown={...values}; delete shown.schema_version; section.append(heading('values'),table(shown)); }
                        if (account) basisAccount(section, document);
                        preview.append(section);
                    });
                    if (data.scope) preview.append(table(data.scope));
                    (data.rows || []).forEach(row => {
                        const details = document.createElement('details'), summary = document.createElement('summary');
                        summary.textContent = row.ttn; details.append(summary, table(row.data)); preview.append(details);
                    });
                    confirm.hidden = false; message.textContent = cfg.preview;
                } else if (data.reconciliation) {
                    invalidate(); preview.append(table(data.reconciliation)); message.textContent = cfg.compared;
                } else if (command === 'classify' && data.classification) {
                    invalidate(); message.textContent = cfg.saved;
                    form.dispatchEvent(new CustomEvent('pnpm:classification-saved', {detail: data}));
                } else if (command === 'confirm' && form.dataset.command === 'history_range') {
                    invalidate();
                    preview.append(table(data.scope));
                    message.textContent = format(cfg.range.saved, {...data.scope, count:data.count});
                    const refresh = document.createElement('a'); refresh.href = cfg.page;
                    refresh.className = 'button'; refresh.textContent = cfg.refreshList;
                    message.append(document.createElement('br'), refresh);
                } else if (command === 'confirm' && form.dataset.command === 'history') {
                    invalidate(); form.elements.previous.value = savedToken;
                    next.hidden = !data.scope?.next_page;
                    const scope = data.scope || {};
                    message.textContent = format(cfg.savedWindow, {from: scope.window_from, to: scope.window_to, page: scope.page, count: data.count}) + ' ' +
                        (scope.next_page ? format(cfg.continueWindow, {from: scope.next_from, page: scope.next_page}) : cfg.empty);
                    const refresh = document.createElement('a'); refresh.href = cfg.page;
                    refresh.className = 'button'; refresh.textContent = cfg.refreshList;
                    message.append(document.createElement('br'), refresh);
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
                if (rangeStop) { rangeStop.hidden = true; rangeResume.hidden = !rangeCursor; rangeResume.disabled = false; }
                if (command === 'classify') form.dispatchEvent(new Event('pnpm:classification-finished'));
            }
        };
        form.addEventListener('submit', event => { event.preventDefault(); request(form.dataset.command); });
        if (apply) apply.addEventListener('click', () => request('confirm'));
        if (next) next.addEventListener('click', () => request('history', true));
        if (rangeStop) rangeStop.addEventListener('click', () => { rangePaused = true; rangeStop.disabled = true; });
        if (rangeResume) rangeResume.addEventListener('click', () => request('history_range', true));
    });
})();
