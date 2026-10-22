/* Start a newly selected category at its heading on phones. Keep native history. */
(() => {
    const phone = window.matchMedia('(max-width: 767px)');
    const key = 'psu-category-entry';
    const current = new URL(window.location.href);
    const address = url => url.origin + url.pathname + url.search;
    let pending;
    try {
        pending = JSON.parse(sessionStorage.getItem(key));
        // Each selection is consumed once, including on reload or an unmatched page.
        sessionStorage.removeItem(key);
    } catch (_) { /* Storage restrictions leave normal browser navigation intact. */ }

    document.addEventListener('click', event => {
        if (!phone.matches || event.defaultPrevented || event.button !== 0
            || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
        const link = event.target.closest('a[href]');
        if (!link || !link.matches('.product-category a, .psu-category-menu a, .woocommerce-breadcrumb a')
            || link.hasAttribute('download') || (link.target && link.target !== '_self')) return;
        const destination = new URL(link.href, current);
        if (destination.origin !== current.origin || destination.hash
            || address(destination) === address(current)) return;
        try {
            sessionStorage.setItem(key, JSON.stringify({url: address(destination), at: Date.now()}));
        } catch (_) { /* No cookies, URL changes or fallback storage are needed. */ }
    });

    const navigation = performance.getEntriesByType('navigation')[0];
    const heading = document.querySelector('body.tax-product_cat #main .woocommerce-products-header h1');
    if (!phone.matches || !heading || current.hash || navigation?.type !== 'navigate'
        || !pending || pending.url !== address(current)
        || !Number.isFinite(pending.at) || Date.now() - pending.at < 0 || Date.now() - pending.at > 30000) return;

    let cancelled = false;
    const cancel = () => { cancelled = true; };
    const inputs = ['pointerdown', 'touchstart', 'wheel', 'keydown'];
    inputs.forEach(type => window.addEventListener(type, cancel, {once: true, passive: true}));
    const release = () => inputs.forEach(type => window.removeEventListener(type, cancel));
    const arrive = event => {
        if (event?.persisted || cancelled || !phone.matches || window.scrollY > 1) { release(); return; }
        requestAnimationFrame(() => {
            release();
            if (cancelled || !phone.matches || window.scrollY > 1) return;
            const toolbar = document.getElementById('wpadminbar');
            const toolbarBottom = toolbar && getComputedStyle(toolbar).position === 'fixed'
                ? Math.max(0, toolbar.getBoundingClientRect().bottom) : 0;
            window.scrollTo({top: Math.max(0, window.scrollY + heading.getBoundingClientRect().top - toolbarBottom - 12), behavior: 'instant'});
        });
    };
    // Let images and browser history restoration settle before one immediate move.
    if (document.readyState === 'complete') arrive();
    else window.addEventListener('pageshow', arrive, {once: true});
})();
