/* Keep the existing shared name beside compact product names while scrolling. */
(() => {
    const header = document.querySelector('#main .woocommerce-products-header');
    const context = header?.querySelector(':scope > .term-description');
    if (!context || context.children.length !== 1 || !context.textContent.trim()) return;
    const inline = new Set(['P', 'H2', 'H3', 'H4', 'H5', 'H6', 'SPAN', 'STRONG', 'EM', 'B', 'I', 'U', 'S', 'SMALL', 'BR']);
    if ([...context.querySelectorAll('*')].some(element => !inline.has(element.tagName))) return;
    // Move the same node, without duplicating text, IDs or the category heading.
    // A sibling of the header can remain sticky throughout the product list.
    header.after(context);
    context.classList.add('psu-catalog-context');
    const toolbar = document.getElementById('wpadminbar');
    let scheduled = false;
    const update = () => {
        scheduled = false;
        const rect = toolbar?.getBoundingClientRect();
        const top = rect ? Math.max(0, Math.min(rect.height, rect.bottom)) : 0;
        context.style.setProperty('--psu-context-top', top + 'px');
        // Long descriptions remain in normal flow, especially on narrow screens.
        context.classList.toggle('is-sticky', context.offsetHeight <= Math.min(160, innerHeight / 4));
    };
    const schedule = () => {
        if (!scheduled) { scheduled = true; requestAnimationFrame(update); }
    };
    update();
    window.addEventListener('resize', schedule, {passive: true});
    if (toolbar) window.addEventListener('scroll', schedule, {passive: true});
    if ('ResizeObserver' in window) {
        const observer = new ResizeObserver(schedule);
        observer.observe(context);
        if (toolbar) observer.observe(toolbar);
    }
})();
