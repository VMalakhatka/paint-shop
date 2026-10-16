(function ($) {
    'use strict';
    const config = window.psuMobileCart;
    if (!config || !document.getElementById('psu-mobile-cart')) return;
    const media = window.matchMedia('(max-width:768px)');
    let request = null;
    let generation = 0;
    let announcement = null;
    let scheduled = null;
    let observed = null;
    const root = () => document.getElementById('psu-mobile-cart');
    const allowed = () => media.matches && !document.body.classList.contains('pcoe-draft-mode');
    const hasCart = () => /(?:^|;\s*)woocommerce_items_in_cart=([1-9]\d*)/.test(document.cookie);
    const typing = () => document.activeElement?.matches('textarea, input:not([type=button]):not([type=submit]):not([type=checkbox]):not([type=radio]), [contenteditable=true]');
    const measure = () => {
        if (root() && !root().hidden) document.body.style.setProperty('--psu-cart-bar-height', Math.ceil(root().getBoundingClientRect().height) + 'px');
    };
    const observer = window.ResizeObserver ? new ResizeObserver(measure) : null;
    function sync() {
        const bar = root();
        if (!bar) return;
        const visible = allowed() && Number(bar.dataset.count) > 0 && !typing();
        bar.hidden = !visible;
        document.body.classList.toggle('psu-mobile-cart-visible', visible);
        if (observer && observed !== bar) {
            observer.disconnect();
            observer.observe(bar);
            observed = bar;
        }
        measure();
    }
    function replace(html) {
        if (typeof html !== 'string') return;
        const template = document.createElement('template');
        template.innerHTML = html.trim();
        const next = template.content.querySelector('#psu-mobile-cart');
        if (next && root()) root().replaceWith(next);
        sync();
    }
    function cancel() {
        generation++;
        if (request) request.abort();
        request = null;
    }
    async function refresh(force = false) {
        cancel();
        if (!allowed()) { sync(); return; }
        if (!force && !hasCart()) {
            if (root()) root().dataset.count = '0';
            sync();
            return;
        }
        const ticket = generation;
        const controller = new AbortController();
        request = controller;
        const timeout = setTimeout(() => controller.abort(), 10000);
        try {
            const response = await fetch(config.url, {credentials:'same-origin', cache:'no-store', signal:controller.signal});
            if (!response.ok) throw new Error('Cart summary unavailable');
            const data = await response.json();
            if (ticket === generation) replace(data.html);
        } catch (_) {
            // Never invent totals or retry a cart mutation; existing navigation stays available.
        } finally {
            clearTimeout(timeout);
            if (ticket === generation) request = null;
        }
    }
    function schedule() {
        clearTimeout(scheduled);
        scheduled = setTimeout(() => refresh(), 80);
    }
    $(document.body).on('added_to_cart.psuMobileCart removed_from_cart.psuMobileCart', function (event, fragments) {
        cancel();
        // Woo and catalogue adapters may also replace fragments in this event.
        // Apply our visibility/announcement after every synchronous listener finishes.
        queueMicrotask(() => {
            if (fragments && fragments['#psu-mobile-cart']) replace(fragments['#psu-mobile-cart']);
            else refresh(true);
            if (event.type === 'added_to_cart' && root()) {
                clearTimeout(announcement);
                const status = root().querySelector('[role=status]');
                if (status) status.textContent = config.added;
                announcement = setTimeout(() => {
                    const current = root()?.querySelector('[role=status]');
                    if (current) current.textContent = '';
                }, 3000);
            }
            sync();
        });
    });
    window.addEventListener('pageshow', schedule);
    window.addEventListener('resize', sync);
    document.addEventListener('visibilitychange', () => { if (!document.hidden) schedule(); });
    document.addEventListener('focusin', sync);
    document.addEventListener('focusout', () => setTimeout(sync, 0));
    media.addEventListener('change', schedule);
    schedule();
})(jQuery);
