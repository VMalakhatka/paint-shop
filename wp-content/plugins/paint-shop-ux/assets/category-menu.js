(() => {
    'use strict';
    document.querySelectorAll('.psu-category-menu').forEach(menu => {
        const labels = JSON.parse(menu.dataset.labels);
        const pending = new Map();
        const status = menu.querySelector('[role="status"]');
        const label = (key, name) => labels[key].replace('%s', name);
        const showButtons = root => root.querySelectorAll('button[hidden]').forEach(button => { button.hidden = false; });
        const setOpen = (button, list, open) => {
            button.setAttribute('aria-expanded', String(open));
            button.setAttribute('aria-label', label(open ? 'collapse' : 'expand', button.dataset.name));
            button.querySelector('span').textContent = open ? '\u2212' : '+';
            list.hidden = !open;
        };
        const createNode = item => {
            const li = document.createElement('li'); li.dataset.category = item.id;
            const row = document.createElement('div'); row.className = 'psu-category-menu__row';
            const link = document.createElement('a'); link.href = item.url; link.textContent = item.name;
            if (item.count !== null) {
                const count = document.createElement('small'); count.textContent = ` (${item.count})`; link.append(count);
            }
            row.append(link); li.append(row);
            if (item.children) {
                const list = document.createElement('ul'); list.hidden = true;
                list.id = `${menu.dataset.widget}-branch-${item.id}`;
                list.dataset.parent = item.id; list.dataset.unloaded = '1';
                const button = document.createElement('button'); button.type = 'button';
                button.className = 'psu-category-menu__toggle'; button.dataset.name = item.name;
                button.setAttribute('aria-controls', list.id);
                const icon = document.createElement('span'); icon.setAttribute('aria-hidden', 'true'); button.append(icon);
                setOpen(button, list, false); row.append(button); li.append(list);
            }
            return li;
        };
        const load = (list, offset, trigger) => {
            const key = `${list.dataset.parent}:${offset}`;
            if (pending.has(key)) return pending.get(key);
            list.setAttribute('aria-busy', 'true'); status.textContent = labels.loading;
            const loading = document.createElement('li'); loading.className = 'psu-category-menu__loading'; loading.textContent = labels.loading; list.append(loading);
            if (trigger) trigger.disabled = true;
            const url = new URL(menu.dataset.endpoint, location.href);
            Object.entries({widget: menu.dataset.widget, parent: list.dataset.parent, offset, locale: menu.dataset.locale}).forEach(([k,v]) => url.searchParams.set(k,v));
            const controller = new AbortController();
            const timeout = setTimeout(() => controller.abort(), 15000);
            const promise = fetch(url, {credentials: 'same-origin', signal: controller.signal})
                .then(async response => {
                    if (!response.ok) throw new Error('categories');
                    const data = await response.json();
                    if (!Array.isArray(data.items)) throw new Error('categories');
                    list.querySelectorAll(':scope > .psu-category-menu__error, :scope > .psu-category-menu__more').forEach(el => el.remove());
                    const existing = new Map([...list.children].filter(el => el.dataset.category).map(el => [el.dataset.category, el]));
                    let firstInserted = null;
                    data.items.forEach(item => {
                        const previous = existing.get(String(item.id));
                        if (previous?.dataset.pinned) {
                            delete previous.dataset.pinned; list.append(previous);
                            if (!firstInserted) firstInserted = previous;
                        } else if (!previous) {
                            const node = createNode(item); list.append(node); existing.set(String(item.id), node);
                            if (!firstInserted) firstInserted = node;
                        }
                    });
                    list.querySelectorAll(':scope > li[data-pinned]').forEach(node => list.append(node));
                    delete list.dataset.unloaded;
                    if (data.next !== null) {
                        const more = document.createElement('li'); more.className = 'psu-category-menu__more';
                        const button = document.createElement('button'); button.type = 'button'; button.dataset.offset = data.next; button.textContent = labels.more; more.append(button); list.append(more);
                    }
                    if (!list.querySelector('[data-category]') && data.next === null) {
                        const empty = document.createElement('li'); empty.textContent = labels.empty; list.append(empty);
                    }
                    status.textContent = '';
                    if (trigger?.dataset.offset !== undefined) (firstInserted?.querySelector('a') || list.querySelector(':scope > .psu-category-menu__more button') || list.parentElement.querySelector('.psu-category-menu__toggle'))?.focus();
                }).catch(() => {
                    list.querySelectorAll(':scope > .psu-category-menu__error').forEach(el => el.remove());
                    const error = document.createElement('li'); error.className = 'psu-category-menu__error'; error.append(labels.error + ' ');
                    const retry = document.createElement('button'); retry.type = 'button'; retry.dataset.offset = offset; retry.textContent = labels.retry; error.append(retry); list.append(error);
                    status.textContent = labels.error;
                }).finally(() => {
                    clearTimeout(timeout); loading.remove(); list.removeAttribute('aria-busy');
                    if (trigger) trigger.disabled = false; pending.delete(key);
                });
            pending.set(key, promise); return promise;
        };
        showButtons(menu);
        menu.querySelectorAll('.psu-category-menu__toggle').forEach(button => setOpen(button, document.getElementById(button.getAttribute('aria-controls')), button.getAttribute('aria-expanded') === 'true'));
        menu.addEventListener('click', event => {
            const button = event.target.closest('button'); if (!button || !menu.contains(button)) return;
            if (button.classList.contains('psu-category-menu__toggle')) {
                const list = document.getElementById(button.getAttribute('aria-controls'));
                const open = button.getAttribute('aria-expanded') !== 'true'; setOpen(button, list, open);
                if (open && list.dataset.unloaded) load(list, 0);
            } else if (button.dataset.offset !== undefined) load(button.closest('ul'), Number(button.dataset.offset), button);
        });
    });
})();
