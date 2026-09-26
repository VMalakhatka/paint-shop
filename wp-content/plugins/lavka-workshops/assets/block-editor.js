(() => {
    const {createElement: h, useState} = wp.element;
    const {useSelect, dispatch} = wp.data;
    const {Button, Modal} = wp.components;
    const {PluginDocumentSettingPanel} = wp.editor;
    const labels = lwStudio.labels;
    function Studio() {
        const {blocks, type, link} = useSelect(select => ({
            blocks: select('core/block-editor').getBlocks(),
            type: select('core/editor').getCurrentPostType(),
            link: lwStudio.previewLink,
        }), []);
        const [chooser, setChooser] = useState(true);
        if (type !== 'lavka_workshop') return null;
        const empty = !blocks.length || blocks.every(b => b.name === 'core/paragraph' && !b.attributes.content);
        const legacy = blocks.some(b => b.name === 'core/freeform');
        function insert(key, starter = false) {
            const next = wp.blocks.parse(lwStudio.patterns[key].content);
            if (starter && empty) dispatch('core/block-editor').resetBlocks(next);
            else dispatch('core/block-editor').insertBlocks(next);
            setChooser(false);
        }
        function convert() {
            const next = blocks.flatMap(block => block.name === 'core/freeform'
                ? wp.blocks.rawHandler({HTML: (block.attributes.content || '').replace(/<!-- (?:lw:section [a-z]+|\/lw:section) -->/g, '')})
                : block);
            dispatch('core/block-editor').resetBlocks(next);
        }
        return h(wp.element.Fragment, null,
            chooser && empty && h(Modal, {title: labels.choose, onRequestClose: () => setChooser(false), className:'lw-studio-chooser'},
                h('p', null, labels.intro),
                h('div', {className:'lw-starter-grid'}, Object.entries(lwStudio.patterns).filter(([,p]) => p.starter).map(([key,p]) =>
                    h(Button, {key, className:'lw-starter-card', onClick:()=>insert(key,true)},
                        h('span',{className:`lw-layout-sketch lw-sketch-${key}`, 'aria-hidden':true},h('i'),h('i'),h('i')),
                        h('strong',null,p.title),h('span',null,p.description)))),
                h(Button,{variant:'tertiary',onClick:()=>setChooser(false)},labels.blank)),
            h(PluginDocumentSettingPanel,{name:'lavka-studio',title:labels.title,initialOpen:true},
                lwStudio.closedNotice && h('p',{className:'lw-legacy-help'},lwStudio.closedNotice),
                h('p',null,labels.help),
                empty && h(Button,{variant:'primary',onClick:()=>setChooser(true)},labels.choose),
                legacy && h('div',{className:'lw-legacy-help'},h('p',null,labels.legacyHelp),h(Button,{variant:'secondary',onClick:convert},labels.legacy)),
                h('h3',null,labels.patterns),
                h('div',{className:'lw-pattern-buttons'},Object.entries(lwStudio.patterns).filter(([,p])=>!p.starter).map(([key,p])=>h(Button,{key,variant:'secondary',onClick:()=>insert(key)},p.title))),
                link && h('p',null,h(Button,{href:link,target:'_blank',rel:'noopener',variant:'link'},labels.saved)),
                h('p',{className:'description'},labels.saveHelp)));
    }
    wp.plugins.registerPlugin('lavka-publication-studio',{render:Studio,icon:'art'});
})();
