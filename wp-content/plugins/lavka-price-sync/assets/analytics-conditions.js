(function () {
    'use strict';
    const text = window.LPS_CONDITIONS_I18N || {};
    const t = key => text[key] || key;
    const copy = value => JSON.parse(JSON.stringify(value));
    const esc = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));
    window.LPS_CONDITIONS = function (root, prefix, labels) {
        const as = prefix === 'lps-as', selectionAttr = as ? 'data-lps-as-selection' : 'data-lps-selection';
        const sectionAttr = as ? 'data-lps-as-section' : 'data-lps-section';
        const grid = root.querySelector('#' + prefix + '-product-filter-grid');
        const box = document.createElement('section'); box.className = 'lps-conditions';
        box.innerHTML = '<h2>' + esc(t('title')) + '</h2><p>' + esc(t('rule')) + '</p><div class="lps-condition-set" aria-live="polite"></div>' +
            '<h3>' + esc(t('tree')) + '</h3><p class="lps-tree-warning"></p><label>' + esc(t('search')) +
            '<input type="search" class="lps-tree-search"></label><div class="lps-group-tree"></div>';
        grid.closest('details').before(box);
        let filters = {product:{}, movement:{}}, paths = [], supported = false;
        const editing = new Set();
        const cards = () => [...root.querySelectorAll('[' + selectionAttr + ']')];
        const nameOf = card => card.getAttribute(selectionAttr);
        const sectionOf = card => card.getAttribute(sectionAttr);
        const control = (card, name) => card.querySelector('.' + prefix + '-' + name);
        const changed = () => box.dispatchEvent(new Event('change', {bubbles:true}));
        function readCard(card) {
            const node = control(card, 'values'), area = card.querySelector('textarea');
            const values = node ? [...node.selectedOptions].map(o => o.value) :
                [...new Set((area?.value || '').split(/[\r\n;,|]+/).map(x=>x.trim()).filter(Boolean))];
            return {mode:control(card,'mode')?.value || 'ANY', values};
        }
        function editCard(section, key) {
            const card = cards().find(c=>sectionOf(c)===section && nameOf(c)===key);
            if (!card) return;
            const selected = filters[section][key];
            editing.add(section + ':' + key);
            card.hidden = false;
            card.closest('details').open = true;
            const values = control(card,'values'), mode = control(card,'mode'), area = card.querySelector('textarea');
            if (mode) { mode.value=selected.mode; mode.dispatchEvent(new Event('change')); }
            if (values) {
                selected.values.forEach(v=>{if (![...values.options].some(o=>o.value===v)) values.add(new Option(v,v));});
                [...values.options].forEach(o=>{o.selected=selected.values.includes(o.value);});
            }
            if (area) area.value=selected.values.join('\n');
            card.scrollIntoView({block:'center'}); (values || area || mode)?.focus();
        }
        function summary() {
            const set = box.querySelector('.lps-condition-set'); set.replaceChildren();
            function row(caption, mode, remove, edit, toggle) {
                const node = document.createElement('div'); node.className='lps-condition ' + mode.toLowerCase();
                node.innerHTML='<span><strong>' + esc(t(mode==='EXCLUDE'?'excluded':'included')) + '</strong> ' + esc(caption) + '</span>';
                const addButton=(label, fn)=>{const b=document.createElement('button');b.type='button';b.className='button';b.textContent=label;b.addEventListener('click',fn);node.append(b);};
                if (edit) addButton(t('edit'),edit);
                if (toggle) addButton(t('toggle'),toggle);
                addButton(t('remove'),remove);set.append(node);
            }
            for (const section of ['product','movement']) Object.entries(filters[section]).forEach(([key, selection])=>{
                if (key==='search' || key==='groupBranches') return;
                (selection.values || []).forEach(value=>row((labels[key] || key)+': '+value,selection.mode,()=>{
                    selection.values=selection.values.filter(v=>v!==value);
                    if (!selection.values.length) delete filters[section][key];summary();changed();
                },()=>editCard(section,key)));
            });
            (filters.product.groupBranches || []).forEach((branch,index)=>row(branch.path.map(x=>x || t('blank')).join(' → '),branch.mode,
                ()=>{filters.product.groupBranches.splice(index,1);summary();changed();},null,
                ()=>{branch.mode=branch.mode==='INCLUDE'?'EXCLUDE':'INCLUDE';summary();changed();}));
            if (!set.children.length) set.textContent=t('empty');
        }
        function addBranch(path, mode) {
            if (!supported || !path.at(-1)) return;
            const branches=filters.product.groupBranches || (filters.product.groupBranches=[]);
            const existing=branches.find(b=>JSON.stringify(b.path)===JSON.stringify(path));
            if (existing) existing.mode=mode;
            else if(branches.length<100) branches.push({mode,path:[...path]});
            summary();changed();
        }
        function tree() {
            const target=box.querySelector('.lps-group-tree'); target.replaceChildren();
            if (!supported) return;
            const search=box.querySelector('.lps-tree-search').value.trim().toLocaleLowerCase();
            const roots=new Map();
            paths.filter(path=>!search || path.join(' / ').toLocaleLowerCase().includes(search)).forEach(path=>{
                let nodes=roots;path.forEach((name,level)=>{if(!nodes.has(name))nodes.set(name,{path:path.slice(0,level+1),children:new Map()});nodes=nodes.get(name).children;});
            });
            function render(nodes,parent) {
                [...nodes.entries()].sort(([a],[b])=>a.localeCompare(b)).forEach(([name,item])=>{
                    const details=document.createElement('details'), head=document.createElement('summary');
                    const title=document.createElement('span');title.textContent=name || t('blank');head.append(title);
                    for(const mode of ['INCLUDE','EXCLUDE']) {
                        const b=document.createElement('button');b.type='button';b.className='button';b.disabled=!name;
                        b.textContent=t(mode==='INCLUDE'?'include':'exclude');b.addEventListener('click',e=>{e.preventDefault();addBranch(item.path,mode);});head.append(b);
                    }
                    details.append(head);parent.append(details);
                    let loaded=false;
                    const children=()=>{if(loaded)return;loaded=true;const nested=document.createElement('div');nested.className='lps-tree-children';details.append(nested);render(item.children,nested);};
                    details.addEventListener('toggle',()=>{if(details.open)children();});
                    if(search){children();details.open=true;}
                });
            }
            render(roots,target);
        }
        function refresh(capabilities) {
            const feature=capabilities?.features?.groupHierarchy;
            supported=feature?.supported===true && feature.version===1;
            paths=supported && Array.isArray(feature.paths)?feature.paths:[];
            box.querySelector('.lps-tree-warning').textContent=supported?'':t('unavailable');
            box.querySelector('.lps-tree-search').disabled=!supported;
            cards().forEach(card=>{
                if (/^(groups|groupLevel[1-6])$/.test(nameOf(card))) card.hidden=true;
                if (card.querySelector('[data-add-condition]')) return;
                const button=document.createElement('button');button.type='button';button.className='button';button.dataset.addCondition='true';button.textContent=t('add');
                const pending=document.createElement('small');pending.textContent=t('pending');
                button.addEventListener('click',()=>{
                    const selection=readCard(card);if(selection.mode==='ANY' || !selection.values.length)return;
                    const section=sectionOf(card), key=nameOf(card), id=section+':'+key, previous=filters[section][key];
                    if(!editing.has(id) && previous?.mode===selection.mode) selection.values=[...new Set([...previous.values,...selection.values])];
                    filters[section][key]=selection;editing.delete(id);summary();changed();
                });card.append(button,pending);
            });summary();tree();
        }
        function apply(product, movement) {
            editing.clear();
            filters={product:copy(product || {}),movement:copy(movement || {})};
            // Safely migrate a contiguous, single-value INCLUDE path; keep every other legacy condition intact.
            const keys=Object.keys(filters.product).filter(k=>/^groupLevel[1-6]$/.test(k)).sort();
            if(supported && !filters.product.groupBranches?.length && keys.length && keys.every((k,i)=>k==='groupLevel'+(i+1) && filters.product[k].mode==='INCLUDE' && filters.product[k].values.length===1)) {
                const path=keys.map(k=>filters.product[k].values[0]);
                if(paths.some(p=>path.every((v,i)=>p[i]===v))) {
                    keys.forEach(k=>delete filters.product[k]);filters.product.groupBranches=[{mode:'INCLUDE',path}];
                }
            }
            summary();
        }
        box.querySelector('.lps-tree-search').addEventListener('input',tree);
        return {refresh,apply,read:section=>{
            if(filters.product.groupBranches?.length && !supported) throw new Error(t('unavailable'));
            const result=copy(filters[section]); if(result.groupBranches && !result.groupBranches.length) delete result.groupBranches;return result;
        },clear:()=>{filters={product:{},movement:{}};editing.clear();summary();},set:(section,key,value)=>{filters[section][key]=copy(value);summary();changed();}};
    };
}());
