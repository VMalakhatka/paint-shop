/* Read saved state only; never submit the order form or start a fiscal job. */
(() => {
    'use strict';
    document.querySelectorAll('.pc-wfp-receipt-panel').forEach((panel) => {
        const content = panel.querySelector('.pc-wfp-receipt-content');
        const button = panel.querySelector('.pc-wfp-receipt-refresh');
        const note = panel.querySelector('.pc-wfp-receipt-refresh-note');
        let busy = false;
        let polls = 0;
        let timer;
        const pending = () => content.querySelector('[data-pending="1"]') !== null;
        const schedule = () => {
            clearTimeout(timer);
            if (polls < 40 && pending()) timer = setTimeout(() => refresh(false), 15000);
        };
        async function refresh(manual) {
            if (busy) return;
            if (!manual && document.hidden) { schedule(); return; }
            busy = true;
            button.disabled = true;
            content.setAttribute('aria-busy', 'true');
            clearTimeout(timer);
            if (manual) polls = 0;
            else polls++;
            const controller = new AbortController();
            const timeout = setTimeout(() => controller.abort(), 10000);
            try {
                const response = await fetch(panel.dataset.url, {
                    method: 'POST', credentials: 'same-origin', cache: 'no-store',
                    signal: controller.signal,
                    body: new URLSearchParams({
                        action: 'pc_wfp_receipt_status', order_id: panel.dataset.order,
                        nonce: panel.dataset.nonce
                    })
                });
                const result = await response.json();
                if (!response.ok || !result.success || typeof result.data?.html !== 'string') throw new Error('status');
                // HTML is escaped by our permission-checked same-origin renderer.
                content.innerHTML = result.data.html;
                note.textContent = '';
            } catch (_) {
                note.textContent = panel.dataset.failure;
            } finally {
                clearTimeout(timeout);
                busy = false;
                button.disabled = false;
                content.setAttribute('aria-busy', 'false');
                schedule();
            }
        }
        button.addEventListener('click', () => refresh(true));
        schedule();
    });
})();
