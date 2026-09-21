(function () {
    'use strict';
    const root = document.getElementById('lpmu-suppliers');
    if (!root) return;
    const config = window.LPMU_SUPPLIERS, s = config.strings;
    const $ = id => document.getElementById(id);
    const source = $('lsc-source'), search = $('lsc-search'), settings = $('lsc-settings');
    let sources = [], page = 1, total = 0, busy = false, editing = '', selected = new Map(), listingSerial = 0;
    const element = (tag, text, cls) => { const n = document.createElement(tag); if (text !== undefined) n.textContent = text; if (cls) n.className = cls; return n; };
    const option = (value, text) => { const n = element('option', text); n.value = value; return n; };
    function message(text, error) { $('lsc-status').replaceChildren(element('div', text, error ? 'lsc-error' : 'lsc-info')); }
    function error(e) { message(e.message || s.failed, true); }
    async function request(op, values = {}, file) {
        const body = new FormData();
        body.set('action', 'lpmu_supplier'); body.set('nonce', config.nonce); body.set('op', op);
        for (const [key, value] of Object.entries(values)) body.set(key, String(value));
        if (file) body.set('xml', file);
        const response = await fetch(config.ajax, {method: 'POST', credentials: 'same-origin', body});
        let result;
        try { result = await response.json(); } catch (_) { throw new Error(s.unknown); }
        if (!response.ok || !result.success) throw new Error(result.data && result.data.message || s.failed);
        return result.data;
    }
    function setBusy(value) {
        busy = value;
        root.setAttribute('aria-busy', value ? 'true' : 'false');
        root.querySelectorAll('button,input,select,textarea').forEach(n => n.disabled = value);
        if (!value) updateSelection();
    }
    function updateSelection() {
        $('lsc-selected').textContent = s.selected.replace('%d', selected.size);
        $('lsc-prepare').disabled = busy || !selected.size;
        $('lsc-prev').disabled = busy || page <= 1;
        $('lsc-next').disabled = busy || page * 12 >= total;
    }
    function clearSelection() { selected.clear(); $('lsc-uploader').hidden = true; updateSelection(); }
    function sourceChanged() {
        const row = sources.find(x => x.id === source.value);
        $('lsc-open').hidden = !row || !row.url;
        if (row && row.url) $('lsc-open').href = row.url;
        $('lsc-file-panel').hidden = !!row && row.type === 'drive';
        $('lsc-updated').textContent = row && row.active.updated ? s.updated + ' ' + row.active.updated + ' · ' + row.active.count + ' ' + s.products : s.notLoaded;
        if (settings && row) {
            editing = row.id;
            for (const field of ['name', 'type', 'url']) settings.elements[field].value = row[field];
            settings.elements.match_sku.checked = !!row.match_sku;
            settings.elements.daily.checked = !!row.daily;
            setMapping(row.mapping);
        }
        if (row && row.status.state === 'running') message(s.running + ' ' + row.status.count);
        else if (row && row.status.state === 'failed') message(row.status.message, true);
    }
    async function loadSources(preferred) {
        sources = await request('sources');
        source.replaceChildren(option('', s.choose));
        sources.forEach(row => source.append(option(row.id, row.name)));
        source.value = preferred || (sources[0] && sources[0].id) || '';
        sourceChanged();
    }
    function facets(id, values) {
        const select = $(id), old = select.value;
        select.replaceChildren(option('', s.all));
        values.forEach(value => select.append(option(value, value)));
        select.value = values.includes(old) ? old : '';
    }
    async function load() {
        const serial = ++listingSerial;
        if (!source.value) { $('lsc-results').replaceChildren(element('p', s.addSource)); total = 0; updateSelection(); return; }
        const values = Object.fromEntries(new FormData(search));
        values.source = source.value; values.page = page;
        const result = await request('list', values);
        if (serial !== listingSerial) return;
        total = result.total;
        facets('lsc-brand', result.brands); facets('lsc-category', result.categories);
        $('lsc-results').replaceChildren();
        if (!result.items.length) $('lsc-results').append(element('p', s.empty, 'lsc-panel'));
        result.items.forEach(renderItem);
        $('lsc-page').textContent = s.page.replace('%1$d', page).replace('%2$d', Math.max(1, Math.ceil(total / 12))) + ' · ' + total + ' ' + s.products;
        updateSelection();
    }
    function image(url, alt, size) {
        const img = element('img'); img.src = url; img.alt = alt; img.loading = 'lazy'; img.referrerPolicy = 'no-referrer';
        if (size) { img.width = size; img.height = size; }
        img.addEventListener('error', () => { img.replaceWith(element('p', s.imageUnavailable)); });
        return img;
    }
    function renderItem(item) {
        const card = element('article', undefined, 'lsc-product');
        card.append(element('h2', item.name), element('div', [item.sku, item.barcode, item.brand, item.category].filter(Boolean).join(' · '), 'lsc-meta'));
        const comparison = element('div', undefined, 'lsc-compare'), current = element('div', undefined, 'lsc-current');
        current.append(element('strong', s.ourProduct));
        current.append(element('p', s[item.state] || s.unmatched, 'lsc-badge' + (!item.product ? ' warning' : '')));
        if (item.product) {
            current.append(element('p', item.product.name + ' · ' + item.product.sku));
            item.product.current.forEach(url => current.append(image(url, s.existingPhoto, 72)));
            if (!item.product.current.length) current.append(element('p', s.noPhotos));
        }
        const matchLabel = element('label', s.ourSku); matchLabel.htmlFor = 'lsc-sku-' + item.id;
        const sku = element('input'); sku.id = matchLabel.htmlFor; sku.type = 'text'; sku.value = item.product ? item.product.sku : '';
        const map = element('button', s.confirmMatch, 'button'); map.type = 'button';
        map.addEventListener('click', async () => {
            if (!sku.value.trim()) { sku.focus(); return; }
            if (!window.confirm(s.confirmMatchPrompt.replace('%s', sku.value))) return;
            setBusy(true);
            try { await request('map', {item: item.id, sku: sku.value}); clearSelection(); await load(); }
            catch (e) { error(e); } finally { setBusy(false); }
        });
        current.append(matchLabel, sku, map); comparison.append(current);
        const supplied = element('div'); supplied.append(element('strong', s.supplierPhotos));
        if (item.kind === 'video') {
            supplied.append(element('p', s.videoHelp));
            const link = element('a', s.openVideo, 'button'); link.href = item.link; link.target = '_blank'; link.rel = 'noopener noreferrer'; supplied.append(link);
        }
        const photos = element('div', undefined, 'lsc-photos');
        item.images.forEach(photo => {
            const key = item.id + ':' + photo.index, remembered = selected.get(key);
            const tile = element('div', undefined, 'lsc-photo'), check = element('input'); check.type = 'checkbox'; check.checked = !!remembered;
            const label = element('label'), zoom = element('a'); zoom.href = photo.url; zoom.target = '_blank'; zoom.rel = 'noopener noreferrer';
            zoom.append(image(photo.url, item.name + ' ' + (photo.index + 1), 130));
            label.append(check, document.createTextNode(' ' + s.choosePhoto + ' ' + (photo.index + 1)));
            const role = element('select'); role.setAttribute('aria-label', s.role);
            role.append(option('', s.chooseRole), option('main', s.main));
            if (!item.product || item.product.type !== 'variation') role.append(option('gallery', s.gallery));
            role.value = remembered ? remembered.role : '';
            const pos = element('input'); pos.type = 'number'; pos.min = 1; pos.max = 999; pos.value = remembered ? remembered.position : photo.index + 1; pos.setAttribute('aria-label', s.position);
            pos.hidden = role.value !== 'gallery';
            function changed() {
                $('lsc-uploader').hidden = true;
                pos.hidden = role.value !== 'gallery';
                if (check.checked && !item.product) { check.checked = false; message(s.matchFirst, true); return; }
                if (check.checked) selected.set(key, {item: item.id, index: photo.index, url: photo.url, role: role.value, position: Number(pos.value)});
                else selected.delete(key);
                updateSelection();
            }
            [check, role, pos].forEach(n => n.addEventListener('change', changed));
            tile.append(zoom, label, role, pos); photos.append(tile);
        });
        supplied.append(photos); comparison.append(supplied); card.append(comparison);
        const details = element('details'); details.append(element('summary', s.info));
        if (item.price) details.append(element('p', s.supplierPrice + ': ' + item.price));
        details.append(element('p', item.description || s.noDescription));
        const attributes = element('dl');
        (item.attributes || []).forEach(a => { attributes.append(element('dt', a.name), element('dd', a.value)); });
        details.append(attributes); card.append(details);
        $('lsc-results').append(card);
    }
    async function refresh(file) {
        if (!source.value) { message(s.choose, true); return; }
        const id = source.value;
        clearSelection(); setBusy(true); message(s.running);
        const timer = setInterval(() => request('status', {source: id}).then(x => {
            if (x.status.state === 'running') message(s.running + ' ' + x.status.count);
        }).catch(() => {}), 4000);
        try { await request('refresh', {source: id}, file); await loadSources(id); page = 1; await load(); message(s.refreshed); }
        catch (e) { error(e); }
        finally { clearInterval(timer); setBusy(false); }
    }
    function setMapping(mapping) {
        if (!$('lsc-mapping')) return;
        $('lsc-mapping').replaceChildren();
        Object.entries(mapping || config.mapping).forEach(([key, value]) => {
            const label = element('label', s['field_' + key] || key), input = element('input'); input.name = 'map_' + key; input.value = value;
            label.append(input); $('lsc-mapping').append(label);
        });
    }
    if (settings) {
        setMapping(config.mapping);
        $('lsc-new').addEventListener('click', () => { editing = ''; settings.reset(); setMapping(config.mapping); settings.elements.name.focus(); });
        settings.addEventListener('submit', async event => {
            event.preventDefault();
            const values = Object.fromEntries(new FormData(settings)), mapping = {};
            Object.keys(config.mapping).forEach(key => mapping[key] = values['map_' + key]);
            values.mapping = JSON.stringify(mapping); values.source = editing;
            setBusy(true);
            try { const saved = await request('save', values); clearSelection(); await loadSources(saved.id); page = 1; await load(); message(s.saved); }
            catch (e) { error(e); } finally { setBusy(false); }
        });
    }
    $('lsc-refresh').addEventListener('click', () => refresh());
    $('lsc-import').addEventListener('click', () => { const file = $('lsc-xml').files[0]; if (file) refresh(file); else message(s.chooseXml, true); });
    source.addEventListener('change', () => { clearSelection(); page = 1; search.reset(); sourceChanged(); load().catch(error); });
    search.addEventListener('submit', e => { e.preventDefault(); page = 1; load().catch(error); });
    $('lsc-prev').addEventListener('click', () => { page--; load().catch(error); });
    $('lsc-next').addEventListener('click', () => { page++; load().catch(error); });
    $('lsc-clear').addEventListener('click', () => { clearSelection(); load().catch(error); });
    $('lsc-prepare').addEventListener('click', async () => {
        const selection = Array.from(selected.values());
        const limit = Math.min(20, window.LPMU_DATA.maxFiles);
        if (selection.length > limit) { message(s.limit.replace('%d', limit), true); return; }
        if (selection.some(x => !x.role || (x.role === 'gallery' && (!Number.isInteger(x.position) || x.position < 1)))) { message(s.rolesRequired, true); return; }
        setBusy(true); $('lsc-uploader').hidden = true;
        try {
            const blobs = []; let bytes = 0;
            for (let i = 0; i < selection.length; i++) {
                message(s.downloading.replace('%1$d', i + 1).replace('%2$d', selection.length));
                const response = await fetch(selection[i].url, {credentials: 'same-origin'});
                if (!response.ok) throw new Error(s.imageUnavailable);
                const blob = await response.blob();
                const ext = {'image/jpeg':'jpg', 'image/png':'png', 'image/webp':'webp'}[blob.type];
                if (!ext) throw new Error(s.imageUnavailable);
                bytes += blob.size;
                if (bytes > window.LPMU_DATA.maxRequestBytes - 65536) throw new Error(s.tooLarge);
                selection[i].extension = ext; blobs.push(blob);
            }
            const result = await request('registry', {selection: JSON.stringify(selection)});
            const images = new DataTransfer(), registry = new DataTransfer();
            blobs.forEach((blob, i) => images.items.add(new File([blob], result.files[i], {type: blob.type})));
            const xlsx = Uint8Array.from(atob(result.xlsx), x => x.charCodeAt(0));
            registry.items.add(new File([xlsx], 'supplier-images.xlsx', {type:'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'}));
            $('lpmu-registry').files = registry.files; $('lpmu-registry').dispatchEvent(new Event('change'));
            $('lpmu-images').files = images.files; $('lpmu-images').dispatchEvent(new Event('change'));
            $('lpmu-generate-names').checked = true; $('lpmu-generate-names').dispatchEvent(new Event('change'));
            $('lsc-uploader').hidden = false; $('lsc-uploader').scrollIntoView({behavior:'smooth'});
            message(s.prepared);
        } catch (e) { error(e); } finally { setBusy(false); }
    });
    loadSources().then(load).catch(error);
})();
