(function () {
    'use strict';
    document.querySelector('[data-import-select-all]')?.addEventListener('change', function () {
        this.form.querySelectorAll('input[name="clients[]"]').forEach(input => {input.checked = this.checked;});
    });
    const form = document.querySelector('[data-customer-import-run]');
    if (!form) return;
    const status = document.querySelector('[data-customer-import-status]');
    let busy = false;
    // Never apply stale saved values after the operator changes the edit form.
    document.querySelectorAll('form input[name^="fields["], form select[name^="fields["], form textarea[name^="fields["]').forEach(input => input.addEventListener('input', () => {form.hidden = true;}));
    form.addEventListener('submit', async event => {
        event.preventDefault();
        if (busy || !form.reportValidity()) return;
        busy = true;
        const values = new FormData(form);
        const buttons = [...document.querySelectorAll('.pcoe-manager button')];
        buttons.forEach(button => {button.disabled = true;});
        form.setAttribute('aria-busy', 'true');
        try {
            let complete = false;
            while (!complete) {
                status.textContent = pcoeCustomerImport.busy;
                const body = new FormData();
                body.set('action', 'pcoe_client_import_step');
                body.set('nonce', pcoeCustomerImport.nonce);
                body.set('import_id', form.dataset.importId);
                body.set('confirm', values.get('confirm') || '');
                body.set('send', values.get('send') || '');
                const response = await fetch(pcoeCustomerImport.url, {method: 'POST',body,credentials: 'same-origin'});
                const json = await response.json();
                if (!response.ok || !json.success) throw new Error(json.data?.message || pcoeCustomerImport.error);
                complete = json.data.complete;
                status.textContent = json.data.processed + ' / ' + json.data.total;
            }
            location.reload();
        } catch(error) {
            status.textContent = (error.message || '') + ' ' + pcoeCustomerImport.error;
            // Reload reconciles durable creating/sending markers; no automatic retry.
        } finally {form.removeAttribute('aria-busy');}
    });
}());
