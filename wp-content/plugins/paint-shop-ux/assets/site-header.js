/* Progressive navigation: links remain available when JavaScript is unavailable. */
(() => {
    'use strict';
    const header = document.querySelector('.psu-site-header');
    if (!header || header.dataset.enhanced) return;
    const toggle = header.querySelector('.psu-site-header__toggle');
    const nav = header.querySelector('.psu-site-header__nav');
    if (!toggle || !nav) return;
    const mobile = window.matchMedia('(max-width: 768px)');
    // Share the final control row on small screens instead of consuming another
    // full row. Without JS the menu remains expanded and this button stays hidden.
    const allocation = header.querySelector('.pc-alloc');
    if (allocation) allocation.append(toggle);
    const disclosures = [];
    nav.querySelectorAll('li').forEach((item, index) => {
        const submenu = [...item.children].find(child => child.matches('ul.sub-menu'));
        const link = [...item.children].find(child => child.matches('a'));
        if (!submenu || !link) return;
        submenu.id = 'psu-submenu-' + index;
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'psu-site-header__submenu-toggle';
        button.setAttribute('aria-controls', submenu.id);
        button.setAttribute('aria-label', nav.dataset.submenuLabel.replace('%s', link.textContent.trim()));
        button.innerHTML = '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="m6 9 6 6 6-6"/></svg>';
        link.after(button);
        const setOpen = open => {
            button.setAttribute('aria-expanded', String(open));
            submenu.hidden = !open;
            submenu.style.left = '';
            if (open && !mobile.matches && submenu.parentElement.parentElement.id === 'psu-primary-menu') {
                const rect = submenu.getBoundingClientRect();
                const offset = Math.max(16 - rect.left, Math.min(0, innerWidth - 16 - rect.right));
                submenu.style.left = offset + 'px';
            }
        };
        setOpen(false);
        button.addEventListener('click', () => setOpen(submenu.hidden));
        disclosures.push({button, submenu, item, setOpen});
    });
    const closeSubmenus = () => disclosures.forEach(d => d.setOpen(false));
    const setMenu = open => {
        nav.hidden = mobile.matches && !open;
        toggle.setAttribute('aria-expanded', String(!nav.hidden));
        if (nav.hidden) closeSubmenus();
    };
    header.dataset.enhanced = 'true';
    setMenu(!mobile.matches);
    toggle.addEventListener('click', () => setMenu(nav.hidden));
    nav.addEventListener('click', event => {
        if (mobile.matches && event.target.closest('a')) setMenu(false);
    });
    header.addEventListener('keydown', event => {
        if (event.key !== 'Escape') return;
        const active = [...disclosures].reverse().find(d => !d.submenu.hidden && d.item.contains(document.activeElement));
        if (active) { active.setOpen(false); active.button.focus(); }
        else if (mobile.matches && !nav.hidden) { setMenu(false); toggle.focus(); }
        else return;
        event.preventDefault();
        event.stopPropagation();
    });
    document.addEventListener('click', event => {
        if (!header.contains(event.target)) { closeSubmenus(); if (mobile.matches) setMenu(false); }
    });
    let previousWidth = innerWidth;
    window.addEventListener('resize', () => {
        if (innerWidth === previousWidth) return;
        previousWidth = innerWidth;
        const focused = disclosures.find(d => !d.submenu.hidden && d.submenu.contains(document.activeElement));
        closeSubmenus();
        if (focused && !nav.hidden) focused.button.focus();
    });
    mobile.addEventListener('change', () => {
        const menuHadFocus = nav.contains(document.activeElement);
        const toggleHadFocus = document.activeElement === toggle;
        closeSubmenus();
        setMenu(!mobile.matches);
        if (mobile.matches && menuHadFocus) toggle.focus();
        else if (!mobile.matches && toggleHadFocus) nav.querySelector('a')?.focus();
    });
})();
