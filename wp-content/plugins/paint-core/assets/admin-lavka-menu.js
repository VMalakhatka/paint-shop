(function () {
    'use strict';
    const menu = document.querySelector('#toplevel_page_lavka-hub > .wp-submenu');
    if (!menu || !Array.isArray(window.paintCoreLavkaMenu)) return;
    const items = new Map();
    Array.from(menu.children).forEach(function (item) {
        const link = item.querySelector('a');
        if (!link) return;
        const page = new URL(link.href, window.location.href).searchParams.get('page');
        if (page) items.set(page, item);
    });
    const groups = [];
    function setOpen(group, open) {
        group.button.setAttribute('aria-expanded', String(open));
        group.list.hidden = !open;
    }
    window.paintCoreLavkaMenu.forEach(function (definition) {
        const children = definition.pages.map(page => items.get(page)).filter(Boolean);
        if (!children.length) return;
        const wrapper = document.createElement('li');
        wrapper.className = 'lavka-menu-group';
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'lavka-menu-toggle';
        button.textContent = definition.label;
        const list = document.createElement('ul');
        list.id = 'lavka-menu-' + definition.id;
        list.className = 'lavka-menu-children';
        button.setAttribute('aria-controls', list.id);
        const active = children.some(item => item.classList.contains('current'));
        wrapper.classList.toggle('lavka-menu-active', active);
        menu.insertBefore(wrapper, children[0]);
        wrapper.append(button, list);
        children.forEach(item => list.appendChild(item));
        const group = { button: button, list: list };
        groups.push(group);
        setOpen(group, active);
        button.addEventListener('click', function () {
            const open = button.getAttribute('aria-expanded') !== 'true';
            groups.forEach(other => setOpen(other, other === group && open));
        });
        list.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                event.stopPropagation();
                setOpen(group, false);
                button.focus();
            }
        });
    });
})();
