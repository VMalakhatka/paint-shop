/* Explicit UI-to-guide map. Never infer actions from translated button text. */
(function () {
    'use strict';
    var config = window.pcWholesaleContextHelp;
    if (!config || document.querySelector('.pc-wholesale-help') ||
        (window.pcFolioDocuments && Number(window.pcFolioDocuments.managerCustomerId) > 0)) return;
    var rules = [
        ['.pcoe-chat > h2', 'messages'],
        ['.pcoe-chat-filters', 'messages-status'],
        ['.pcoe-chat-card:has(input[name="action"][value="pcoe_chat"]) > h3, .pcoe-chat form:has(input[name="operation"][value="start"]) button', 'messages-new'],
        ['.pcoe-chat form:has(input[name="operation"][value="reply"]) button', 'messages-new'],
        ['.pcoe-chat-card:has(input[name="action"][value="pcoe_telegram"]) > h3', 'telegram-connect'],
        ['.pcoe-chat form:has(input[name="action"][value="pcoe_telegram"]):has(input[name="operation"][value="link"]) button, .pcoe-chat form:has(input[name="operation"][value="confirm"]) button', 'telegram-connect'],
        ['.pcoe-chat form:has(input[name="operation"][value="unlink"]) button', 'telegram-disconnect'],
        ['.pcoe-chat a[href^="https://t.me/"]', 'telegram-reply'],
        ['.psu-search-filters', 'katalog'],
        ['.pc-alloc, .pc-location-switcher', 'sklad'],
        ['.single_add_to_cart_button', 'katalog'],
        ['.pc-qo-toolbar, .pc-qo-addall', 'spyskom'],
        ['.pcoe-price-list-download', 'import'],
        ['.woocommerce-cart-form [name="update_cart"], .cart_totals h2', 'koshyk'],
        ['.checkout-button, #place_order, #order_review_heading', 'oformlennia'],
        ['#customer_details h3', 'oformlennia'],
        ['.pcoe-import > details > summary, #pcoe-import-form button[type="submit"], #pcoe-import-draft-form button[type="submit"]', 'import'],
        ['.pcoe-export > details > summary, .pcoe-export a.button, .pcoe-split', 'eksport'],
        ['.pcoe-cart-to-draft button[type="submit"]', 'chernetky'],
        ['form:has(input[name="action"][value="pcoe_cart_to_draft"]) button[type="submit"]', 'chernetky'],
        ['form:has(input[name="action"][value="pcoe_approval_confirm"]) button[type="submit"]', 'customer-confirmation'],
        ['.pcoe-waitlist h2, .pcoe-waitlist button[type="submit"], .pcoe-track-consent', 'waiting-list'],
        ['.woocommerce-order-details__title', 'order-details'],
        ['#pcoe-draft-folio h2, #pcoe-draft-folio h3, #pcoe-draft-folio button[type="submit"]', 'stari-zamovlennia'],
        ['#pc-folio-balance-title, [data-pc-folio-form] button, [data-pc-folio-export], [data-pc-folio-print]', 'balans'],
        ['#pc-folio-documents-title, [data-pc-documents-form] button, [data-document-view], [data-pc-document-detail-title]', 'folio-search'],
        ['.pc-folio-documents__repeat h4, [data-repeat-target]', 'folio-repeat'],
        ['.pc-folio-documents__invoice h4, .pc-folio-documents__invoice-form button[type="button"]', 'folio-invoice'],
        ['.pc-folio-documents__invoice-form button[type="submit"]', 'folio-invoice-email']
    ];
    function refresh() {
        rules.forEach(function (rule) {
            document.querySelectorAll(rule[0]).forEach(function (control) {
                if (control.closest('.pcoe-manager, [data-manager-mode="1"]')) return;
                var next = control.nextElementSibling;
                if (next && next.classList.contains('pc-context-help')) return;
                var link = document.createElement('a');
                link.className = 'pc-context-help';
                link.href = config.url + '#' + rule[1];
                link.target = '_blank';
                link.rel = 'noopener';
                link.textContent = config.label;
                link.title = config.newTab;
                link.setAttribute('aria-label', config.label + ' — ' + config.newTab);
                control.insertAdjacentElement('afterend', link);
            });
        });
    }
    var scheduled = false;
    var observer = new MutationObserver(function () {
        if (scheduled) return;
        scheduled = true;
        window.requestAnimationFrame(function () {
            scheduled = false;
            observer.disconnect();
            refresh();
            observe();
        });
    });
    function observe() { observer.observe(document.body, {childList: true, subtree: true}); }
    refresh();
    observe();
}());
