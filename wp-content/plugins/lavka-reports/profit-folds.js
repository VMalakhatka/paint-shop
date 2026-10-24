/* Presentation only: keep report data and export independent of disclosure state. */
(function () {
    'use strict';
    const root = document.getElementById('lavr-profit-report');
    if (!root) return;
    const states = new Map();
    const selector = '#lavr-profit-result > section, #lavr-profit-comparison, #lph-revisions, #lavr-profit-master-audit';

    function enhance() {
        observer.disconnect();
        root.querySelectorAll(selector).forEach((section, index) => {
            const existing = section.querySelector(':scope > details[data-profit-fold]');
            if (existing) {
                // Revision pagination appends new rows to its original container.
                [...section.childNodes].filter(node => node !== existing).forEach(node => existing.append(node));
                return;
            }
            const heading = section.querySelector('h2, h3');
            if (!heading) return;
            const key = section.id || section.getAttribute('aria-labelledby') || String(index);
            const details = document.createElement('details');
            details.dataset.profitFold = key;
            details.open = states.get(key) !== false;
            const summary = document.createElement('summary');
            summary.append(heading);
            details.append(summary);
            while (section.firstChild) details.append(section.firstChild);
            section.append(details);
        });
        // Existing city tables and static panels use the same keyboard-native control.
        root.querySelectorAll('details').forEach(details => {
            const summary = details.querySelector(':scope > summary');
            if (!summary || summary.dataset.foldBound) return;
            summary.dataset.foldBound = '1';
            const parent = details.parentElement;
            const key = details.dataset.profitFold || details.id ||
                (parent.id ? parent.id + ':' + summary.textContent.trim() : null);
            if (key && states.has(key)) details.open = states.get(key);
            details.addEventListener('toggle', () => {
                if (key && details.isConnected) states.set(key, details.open);
            });
        });
        observer.observe(root, {childList: true, subtree: true});
    }
    const observer = new MutationObserver(enhance);
    enhance();
})();
