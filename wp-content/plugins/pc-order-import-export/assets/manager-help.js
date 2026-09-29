/* Explicit operation selectors, never translated button text. Admin-only enqueue. */
(() => {
    'use strict';
    const cfg = window.pcoeManagerHelp;
    const support = {'pcoe-approvals':'approval', 'pc-folio-customer-balance':'balance-export', 'pc-folio-customer-debtors':'debtors'};
    const root = document.querySelector(support[cfg?.page] ? '#wpbody-content > .wrap' : '.pcoe-manager:not(.pcoe-help)');
    if (!cfg || !root) return;
    const attached = new WeakMap();
    function link(target, anchor) {
        if (!target || target.matches('.pcoe-help-link')) return;
        const links = attached.get(target) || new Map();
        if (links.get(anchor)?.isConnected) return;
        const a = document.createElement('a');
        a.className = 'pcoe-help-link'; a.dataset.help = anchor;
        a.href = cfg.url.replace(/#.*$/, '') + '#' + anchor;
        a.textContent = cfg.label; a.title = cfg.title;
        a.target = '_blank'; a.rel = 'noopener noreferrer';
        if (target.matches('.nav-tab')) {
            // WordPress tabs float; a bare inline help sibling breaks their row.
            let group = target.closest('.pcoe-help-tab');
            if (!group) {
                group = document.createElement('span');
                group.className = 'pcoe-help-tab';
                target.before(group);
                group.append(target);
            }
            group.append(a);
        } else target.after(a);
        links.set(anchor, a); attached.set(target, links);
    }
    const maps = [
        ['.nav-tab[href*="view=orders"]', 'orders'],
        ['.nav-tab[href*="view=messages"]', 'queue'],
        ['.nav-tab:not([href*="view="])', 'customers'],
        ['.pcoe-directory-filters .pcoe-actions', 'customers'],
        ['.pcoe-customer-heading h2', 'customers'],
        ['.pcoe-customer-team h2, .pcoe-customer-team button', 'team'],
        ['a[href*="new_customer="]', 'reply'],
        ['a[href="#pcoe-folio-documents"], #pcoe-folio-documents h2', 'documents'],
        ['[data-pc-documents-form] button, [data-document-view], [data-pc-document-detail-title]', 'documents'],
        ['a[href*="page=pc-folio-customer-balance"]', 'balance'],
        ['a[href*="action=pcoe_export"]', 'orders'],
        ['a[href*="page=pcoe-approvals"]', 'approval'],
        ['.pcoe-chat > h2, .pcoe-chat-filters', 'queue'],
        ['.pcoe-chat-assignment button', 'queue'],
        ['.pcoe-chat select[name="visibility"]', 'reply'],
        ['.pcoe-chat-messages', 'reply'],
        ['.pcoe-telegram-status', 'telegram-errors'],
        ['.pcoe-warning, #pcoe-manager-status:not(:empty)', 'errors'],
        ['[data-preview-result]:not([hidden])', 'prepare'],
        ['.pcoe-order-note:first-of-type', 'delivery'],
        ['a[href*="tracking.novaposhta.ua"]', 'delivery'],
        ['.pcoe-actions a[href*="post.php"], .pcoe-actions a[href*="page=wc-orders"]', 'orders'],
    ];
    const operations = {new:'draft', import:'import', save:'draft', copy:'orders', preview:'prepare', apply:'apply'};
    function scan() {
        if (support[cfg.page]) {
            root.querySelectorAll('h1, h2, h3, button, input[type="submit"]').forEach(el => link(el, support[cfg.page]));
            return;
        }
        maps.forEach(([selector, anchor]) => root.querySelectorAll(selector).forEach(el => link(el, anchor)));
        root.querySelectorAll('[data-pcoe-help]').forEach(el => link(el, el.dataset.pcoeHelp));
        root.querySelectorAll('form').forEach(form => {
            const op = form.querySelector('input[name="operation"]')?.value;
            const action = form.querySelector('input[name="action"]')?.value;
            let anchor = form.matches('[data-pcoe-manager]') ? operations[op] : null;
            if (action === 'pcoe_chat') anchor = ['enable','disable'].includes(op) ? 'admin' : op === 'assign' ? 'queue' : 'reply';
            if (action === 'pcoe_telegram') anchor = ['configure','pause'].includes(op) ? 'admin' : op === 'unlink' ? 'telegram-errors' : 'telegram-connect';
            if (anchor) {
                link(form.querySelector('button'), anchor);
                const section = form.closest('section, details');
                link(section?.querySelector('h2, h3, summary'), anchor);
            }
        });
        root.querySelectorAll('a[href^="https://t.me/"]').forEach(el => {
            link(el, 'telegram-work');
            link(el.closest('section')?.querySelector('h3'), 'telegram-connect');
        });
    }
    // Observe child replacements only; our own insertions are ignored to avoid feedback loops.
    const observer = new MutationObserver(records => {
        if (records.some(r => [...r.addedNodes, ...r.removedNodes].some(n => n.nodeType === 1 && !n.matches('.pcoe-help-link')))) scan();
    });
    scan(); observer.observe(root, {childList:true, subtree:true});
})();
