/* Basic consent mode: no Google library/request before a positive choice. */
(function () {
    'use strict';
    var c = window.lavkaAnalyticsConfig;
    if (!c || !/^G-[A-Z0-9]{6,20}$/.test(c.id)) return;
    var granted = false, started = false, flushing = false;
    var emitted = new Set(), pageEventsSent = false;
    var storage = {
        get: function (key) { try { return sessionStorage.getItem(key); } catch (_) { return null; } },
        set: function (key, value) { try { sessionStorage.setItem(key, value); } catch (_) {} },
        remove: function (key) { try { sessionStorage.removeItem(key); } catch (_) {} }
    };
    function cookie() {
        var match = document.cookie.match(/(?:^|;\s*)lca_consent=(granted|denied)(?:;|$)/);
        return match ? match[1] : '';
    }
    function referrer() {
        try { var url = new URL(document.referrer); return url.origin + '/'; } catch (_) { return ''; }
    }
    function campaign() {
        var previous = storage.get('lca_source');
        var data = {}; try { data = previous ? JSON.parse(previous) : {}; } catch (_) {}
        var params = new URLSearchParams(location.search);
        var valid = function (v) { return /^[a-zA-Z0-9._-]{1,60}$/.test(v || '') && !/\d{5,}/.test(v) ? v : ''; };
        if (params.has('utm_source')) {
            data = {source:valid(params.get('utm_source')), medium:valid(params.get('utm_medium')), campaign:valid(params.get('utm_campaign'))};
        } else if (!data.source) {
            try {
                var host = new URL(document.referrer).hostname;
                if (host !== location.hostname && !/(?:wayforpay|paypal|liqpay|googlepay|privat24)/i.test(host)) {
                    data = {source:valid(host), medium:/google|bing|duckduckgo/.test(host) ? 'organic' : 'referral', campaign:''};
                }
            } catch (_) {}
        }
        if (!data.source) data = {source:'direct', medium:'none', campaign:''};
        data.device = /Mobi/i.test(navigator.userAgent) ? 'mobile' : (/iPad|Tablet/i.test(navigator.userAgent) ? 'tablet' : 'desktop');
        storage.set('lca_source', JSON.stringify(data));
        var form = document.querySelector('form.checkout');
        if (form) {
            var input = form.querySelector('input[name="lca_attribution"]');
            if (!input) { input = document.createElement('input'); input.type = 'hidden'; input.name = 'lca_attribution'; form.appendChild(input); }
            input.value = JSON.stringify(data);
        }
        return data;
    }
    function send(name, params, key) {
        if (!granted || !started) return;
        if (key && (emitted.has(key) || storage.get('lca_seen_' + key))) return;
        var p = Object.assign({}, params, {send_to:c.id, customer_segment:c.segment, page_location:c.page, page_referrer:referrer(), page_title:'Storefront'});
        if (c.debug) p.debug_mode = true;
        window.gtag('event', name, p);
        if (key) { emitted.add(key); storage.set('lca_seen_' + key, '1'); }
    }
    function lists() {
        var items = [];
        document.querySelectorAll('[data-lca-item]').forEach(function (node, index) {
            try { var item = JSON.parse(node.getAttribute('data-lca-item')); item.index = index; items.push(item); } catch (_) {}
        });
        // Quick-order page uses its own WP_Query: serialize only publicly displayed SKU/name,
        // not localized price strings. Mutations still come from server Woo hooks.
        document.querySelectorAll('.pc-qo-row[data-id]').forEach(function (node) {
            var sku = node.querySelector('.pc-qo-sku'), title = node.querySelector('.pc-qo-title');
            if (sku && title) items.push({item_id:sku.textContent.trim() === '—' ? 'woo:' + node.dataset.id : sku.textContent.trim(),
                item_name:title.textContent.trim(), quantity:1, index:items.length});
        });
        for (var i = 0; i < items.length; i += 200) {
            send('view_item_list', {currency:c.currency, item_list_id:document.querySelector('.pc-qo-wrap') ? 'quick_order' : 'catalog', items:items.slice(i, i+200)});
        }
    }
    function flush() {
        if (!granted || flushing) return;
        flushing = true;
        var data = new URLSearchParams({action:'lca_events', nonce:c.nonce});
        fetch(c.url, {method:'POST', credentials:'same-origin', body:data}).then(function (response) {
            return response.ok ? response.json() : null;
        }).then(function (result) {
            if (result && result.success && result.data) {
                (result.data.events || []).forEach(function (event) { send(event.name, event.params, event.id); });
            }
        }).catch(function () {}).finally(function () { flushing = false; });
    }
    function start() {
        if (!granted) return;
        var source = campaign();
        window['ga-disable-' + c.id] = false;
        if (!started) {
            window.dataLayer = window.dataLayer || [];
            window.gtag = window.gtag || function () { window.dataLayer.push(arguments); };
            window.gtag('consent', 'default', {analytics_storage:'denied', ad_storage:'denied', ad_user_data:'denied', ad_personalization:'denied'});
            window.gtag('consent', 'update', {analytics_storage:'granted'});
            window.gtag('js', new Date());
            window.gtag('config', c.id, {send_page_view:false, allow_google_signals:false, allow_ad_personalization_signals:false,
                page_location:c.page, page_referrer:referrer(), page_title:'Storefront',
                campaign_source:source.source, campaign_medium:source.medium, campaign_name:source.campaign});
            var script = document.createElement('script'); script.async = true;
            script.src = 'https://www.googletagmanager.com/gtag/js?id=' + encodeURIComponent(c.id);
            document.head.appendChild(script);
            started = true;
        }
        window.gtag('consent', 'update', {analytics_storage:'granted'});
        if (!pageEventsSent) {
            pageEventsSent = true;
            send('page_view', {});
            c.events.forEach(function (event) {
                var key = event.params.transaction_id ? event.name + ':' + event.params.transaction_id : '';
                send(event.name, event.params, key);
            });
            lists();
        }
        flush();
    }
    function choose(state) {
        granted = state === 'granted';
        document.cookie = 'lca_consent=' + (granted ? 'granted' : 'denied') + ';Path=/;Max-Age=15552000;SameSite=Lax' + (location.protocol === 'https:' ? ';Secure' : '');
        var banner = document.querySelector('.lca-consent'); if (banner) banner.remove();
        if (granted) start();
        else {
            fetch(c.url, {method:'POST', credentials:'same-origin', body:new URLSearchParams({action:'lca_events', nonce:c.nonce})}).catch(function () {});
            storage.remove('lca_source');
            try { Object.keys(sessionStorage).forEach(function (key) { if (key.indexOf('lca_seen_') === 0) storage.remove(key); }); } catch (_) {}
            var input = document.querySelector('input[name="lca_attribution"]'); if (input) input.remove();
            window['ga-disable-' + c.id] = true;
            if (started) window.gtag('consent', 'update', {analytics_storage:'denied'});
            // Delete only this module's GA cookies; do not touch cart/login cookies.
            document.cookie.split(';').forEach(function (row) {
                var name = row.trim().split('=')[0];
                if (name === '_ga' || name === '_ga_' + c.id.slice(2)) {
                    ['', location.hostname, '.' + location.hostname].forEach(function (domain) {
                        document.cookie = name + '=;Path=/;Max-Age=0' + (domain ? ';Domain=' + domain : '');
                    });
                }
            });
        }
    }
    function banner() {
        if (document.querySelector('.lca-consent')) return;
        var box = document.createElement('section'); box.className = 'lca-consent'; box.setAttribute('aria-label', c.labels.settings);
        var text = document.createElement('p'); text.textContent = c.labels.message; box.appendChild(text);
        ['allow', 'deny'].forEach(function (label) {
            var button = document.createElement('button'); button.type = 'button'; button.textContent = c.labels[label];
            button.addEventListener('click', function () { window['ga-disable-' + c.id] = label !== 'allow'; choose(label === 'allow' ? 'granted' : 'denied'); });
            box.appendChild(button);
        });
        if (c.privacy) { var link = document.createElement('a'); link.href = c.privacy; link.textContent = c.labels.privacy; box.appendChild(link); }
        document.body.appendChild(box);
    }
    window.lavkaAnalyticsConsent = choose;
    var preferences = document.createElement('button'); preferences.type = 'button'; preferences.className = 'lca-preferences';
    preferences.textContent = c.labels.settings; preferences.addEventListener('click', banner); document.body.appendChild(preferences);
    if (cookie() === 'granted') { granted = true; start(); } else if (!cookie()) { banner(); }
    document.addEventListener('click', function (e) {
        var link = e.target.closest('li.product a.woocommerce-LoopProduct-link,.pc-qo-title a');
        if (!link || !granted) return;
        var row = link.closest('.pc-qo-row');
        if (row) {
            var sku = row.querySelector('.pc-qo-sku');
            if (sku) send('select_item', {item_list_id:'quick_order', items:[{item_id:sku.textContent.trim() === '—' ? 'woo:' + row.dataset.id : sku.textContent.trim(),item_name:link.textContent.trim()}]});
            return;
        }
        var node = link.closest('li.product').querySelector('[data-lca-item]');
        if (node) { try { send('select_item', {item_list_id:'catalog', items:[JSON.parse(node.getAttribute('data-lca-item'))]}); } catch (_) {} }
    });
    if (window.jQuery) {
        window.jQuery(document.body).on('added_to_cart removed_from_cart updated_cart_totals wc_fragments_refreshed', flush);
        window.jQuery(document).ajaxComplete(function (_, xhr, settings) {
            if (!granted || String(settings.data || '').includes('action=lca_events')) return;
            var relevant = /action=(?:pc_cart_adjust|pc_cart_update_item|pc_bulk_add_to_cart|pcoe_import_cart|pcoe_draft_to_cart|pc_folio_customer_document_repeat)(?:&|$)/.test(String(settings.data || ''))
                || /wc-ajax=(?:add_to_cart|remove_from_cart)/.test(settings.url || '');
            if (relevant && xhr.status >= 200 && xhr.status < 300) flush();
        });
        window.jQuery(document.body).on('updated_checkout', function () { if (granted) campaign(); });
        window.jQuery(document.body).on('checkout_error', function () { send('checkout_error', {error_type:'validation_or_processing'}); });
        window.jQuery('form.checkout').on('checkout_place_order', function () {
            if (!granted) return;
            send('checkout_submit', {});
            var payment = document.querySelector('input[name="payment_method"]:checked');
            if (payment) send('add_payment_info', Object.assign({}, c.cart, {payment_type:/^[a-zA-Z0-9_-]{1,60}$/.test(payment.value) ? payment.value : 'other'}));
            send('add_shipping_info', Object.assign({}, c.cart, {shipping_tier:'checkout_selection'}));
        });
    }
    document.addEventListener('change', function (e) {
        if (!granted || !e.target.matches('input[name="payment_method"],select.shipping_method,input.shipping_method')) return;
        if (e.target.name === 'payment_method') {
            // Standard stage event, no card/address/phone fields.
            send('add_payment_info', Object.assign({}, c.cart, {payment_type:/^[a-zA-Z0-9_-]{1,60}$/.test(e.target.value) ? e.target.value : 'other'}));
        } else send('add_shipping_info', Object.assign({}, c.cart, {shipping_tier:'selected'}));
    });
})();
