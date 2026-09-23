(function () {
    'use strict';
    const form = document.querySelector('.psu-category-visibility');
    if (!form) return;
    const config = window.psuCategoryVisibility, labels = config.labels;
    const selectedBox = form.querySelector('[data-selected]');
    const selected = new Map(Array.from(selectedBox.querySelectorAll('input')).map(input => [Number(input.value), input.dataset.label]));
    const root = form.querySelector('.psu-visibility-tree');
    const search = form.querySelector('input[type="search"]');
    const status = form.querySelector('[role="status"]');
    let sequence = 0;

    function iconButton(icon, title) {
        const button = document.createElement('button');
        button.type = 'button'; button.className = 'psu-visibility-icon'; button.title = title;
        button.setAttribute('aria-label', title);
        const span = document.createElement('span');
        span.className = 'dashicons dashicons-' + icon; span.setAttribute('aria-hidden', 'true');
        button.append(span); return button;
    }
    function sync() {
        selectedBox.replaceChildren();
        for (const [id, name] of selected) {
            const input = document.createElement('input');
            input.type = 'hidden'; input.name = 'excluded[]'; input.value = id;
            const row = document.createElement('div'); row.className = 'psu-visibility-selected';
            const text = document.createElement('span'); text.textContent = name;
            const remove = iconButton('no-alt', labels.remove.replace('%s', name));
            remove.onclick = () => { selected.delete(id); sync(); };
            row.append(input, text, remove); selectedBox.append(row);
        }
        form.querySelectorAll('[data-category-check]').forEach(input => {
            const inherited = JSON.parse(input.dataset.parents).some(id => selected.has(id));
            input.checked = inherited || selected.has(Number(input.value));
            input.disabled = inherited;
            input.closest('li').classList.toggle('is-inherited', inherited);
        });
    }
    function itemNode(item, searching) {
        const li = document.createElement('li'); li.dataset.category = item.id;
        const row = document.createElement('div'); row.className = 'psu-visibility-row';
        const label = document.createElement('label'), check = document.createElement('input');
        check.type = 'checkbox'; check.value = item.id;
        check.dataset.categoryCheck = ''; check.dataset.parents = JSON.stringify(item.parents);
        check.setAttribute('aria-label', labels.hide.replace('%s', item.path));
        check.onchange = () => {
            if (check.checked) selected.set(item.id, item.path);
            else selected.delete(item.id);
            sync();
        };
        const name = document.createElement('span'); name.textContent = searching ? item.path : item.name;
        label.append(check, name); row.append(label); li.append(row);
        if (item.children) {
            const children = document.createElement('ul'); children.hidden = true;
            children.id = 'psu-visibility-branch-' + (++sequence);
            const toggle = iconButton('plus-alt2', labels.expand.replace('%s', item.name));
            toggle.setAttribute('aria-expanded', 'false'); toggle.setAttribute('aria-controls', children.id);
            toggle.onclick = () => {
                children.hidden = !children.hidden;
                toggle.setAttribute('aria-expanded', String(!children.hidden));
                const title = (children.hidden ? labels.expand : labels.collapse).replace('%s', item.name);
                toggle.setAttribute('aria-label', title); toggle.title = title;
                toggle.firstChild.className = 'dashicons dashicons-' + (children.hidden ? 'plus-alt2' : 'minus');
                if (!children.hidden && !children.dataset.loaded) load(children, item.id, 0, '');
            };
            row.append(toggle); li.append(children);
        }
        return li;
    }
    async function load(list, parent, offset, term) {
        if (list.controller) list.controller.abort();
        const controller = new AbortController(); list.controller = controller;
        list.querySelectorAll(':scope > .psu-visibility-action').forEach(node => node.remove());
        const action = document.createElement('li'); action.className = 'psu-visibility-action';
        action.textContent = labels.loading; list.append(action); list.setAttribute('aria-busy', 'true');
        const url = new URL(config.url);
        url.search = new URLSearchParams({action:'psu_category_visibility_search', security:config.nonce, parent, offset, term});
        try {
            const response = await fetch(url, {credentials:'same-origin', signal:controller.signal});
            if (!response.ok) throw new Error('Request failed');
            const data = await response.json();
            if (!Array.isArray(data.items)) throw new Error('Invalid response');
            if (list.controller !== controller || !list.isConnected) return;
            action.remove();
            data.items.forEach(item => list.append(itemNode(item, term !== '')));
            list.dataset.loaded = '1';
            if (!list.children.length) { action.textContent = labels.empty; list.append(action); }
            if (data.next !== null) {
                const more = document.createElement('button');
                more.type = 'button'; more.className = 'button-link'; more.textContent = labels.more;
                more.onclick = () => load(list, parent, data.next, term);
                action.replaceChildren(more); list.append(action);
            }
            sync(); status.textContent = '';
        } catch (error) {
            if (error.name === 'AbortError' || !list.isConnected) return;
            const retry = document.createElement('button'); retry.type = 'button'; retry.className = 'button-link';
            retry.textContent = labels.retry; retry.onclick = () => load(list, parent, offset, term);
            action.replaceChildren(document.createTextNode(labels.error + ' '), retry); status.textContent = labels.error;
        } finally {
            if (list.controller === controller) list.removeAttribute('aria-busy');
        }
    }
    let timer;
    search.addEventListener('input', () => {
        clearTimeout(timer);
        if (root.controller) root.controller.abort();
        timer = setTimeout(() => { root.replaceChildren(); load(root, 0, 0, search.value.trim()); }, 250);
    });
    sync(); load(root, 0, 0, '');
}());
