(() => {
    const root = document.querySelector('.lw-article-editor');
    if (!root) return;
    const source = document.querySelector('#content');
    const getEditor = key => window.tinymce?.get(`lw_article_${key}`);
    let restoring = false;
    function value(key) {
        const editor = getEditor(key);
        return editor && !editor.isHidden() ? editor.getContent() : document.querySelector(`#lw_article_${key}`).value;
    }
    function hasContent(html) {
        const fragment = document.createElement('template');
        fragment.innerHTML = html;
        return fragment.content.textContent.trim() || fragment.content.querySelector('img,iframe,audio,video,hr');
    }
    function sync() {
        if (restoring) return;
        source.value = Object.keys(lwArticle.modules).map(key => {
            const body = value(key).trim();
            // WP preview may submit via jQuery; keep the server-side module inputs current too.
            getEditor(key)?.save();
            if (!hasContent(body)) return '';
            const title = document.querySelector(`[name="lw_article_titles[${key}]"]`).value;
            const heading = document.createElement('h2');
            heading.textContent = title;
            return `<!-- lw:section ${key} -->\n${key === 'about' ? '' : heading.outerHTML + '\n'}${body}\n<!-- /lw:section -->`;
        }).filter(Boolean).join('\n\n');
        source.dispatchEvent(new Event('change', {bubbles: true}));
    }
    root.addEventListener('input', sync);
    root.addEventListener('click', event => {
        const button = event.target.closest('.lw-fill-example');
        if (!button) return;
        const key = button.dataset.editor.replace('lw_article_', '');
        const feedback = button.closest('.lw-module').querySelector('.lw-module-feedback');
        if (hasContent(value(key))) { feedback.textContent = lwArticle.occupied; return; }
        const wrapper = document.createElement('div');
        lwArticle.modules[key].sample.split(/\\n|\n/).forEach(line => {
            const paragraph = document.createElement('p');
            paragraph.textContent = line;
            wrapper.append(paragraph);
        });
        const editor = getEditor(key);
        if (editor) { editor.setContent(wrapper.innerHTML); editor.fire('change'); editor.focus(); }
        else document.querySelector(`#lw_article_${key}`).value = wrapper.innerHTML;
        sync();
        feedback.textContent = lwArticle.filled;
    });
    const connect = editor => {
        if (!editor.id.startsWith('lw_article_')) return;
        // Initial TinyMCE normalization is not a user edit (especially for legacy articles).
        const bind = () => editor.on('change input undo redo SetContent', sync);
        if (editor.initialized) bind();
        else editor.once('init', bind);
    };
    if (window.tinymce) {
        window.tinymce.editors.forEach(connect);
        window.tinymce.on('AddEditor', event => connect(event.editor));
    }
    // The standard WP preview/autosave reads #content; keep it in sync with the modules.
    document.querySelector('#post').addEventListener('submit', sync, true);
    document.querySelector('#post-preview')?.addEventListener('click', sync, true);
    // Core's browser-backup restore targets a single visible #content editor.
    // Restore our modules instead; never insert text into whichever field has focus.
    document.addEventListener('click', event => {
        if (!event.target.closest('#local-storage-notice .restore-backup')) return;
        const backup = window.wp?.autosave?.local?.getSavedPostData();
        if (!backup) return;
        event.preventDefault();
        event.stopImmediatePropagation();
        const content = backup.content || '';
        const pattern = /<!-- lw:section ([a-z]+) -->([\s\S]*?)<!-- \/lw:section -->/g;
        const matches = [...content.matchAll(pattern)];
        const keys = matches.map(match => match[1]);
        const structured = matches.length && !content.replace(pattern, '').trim() &&
            keys.every(key => Object.hasOwn(lwArticle.modules, key)) && new Set(keys).size === keys.length;
        const parts = Object.fromEntries(Object.keys(lwArticle.modules).map(key => [key, '']));
        if (structured) matches.forEach(match => { parts[match[1]] = match[2].trim(); });
        else parts.about = content;
        restoring = true;
        document.querySelector('#title').value = backup.post_title || '';
        document.querySelector('#excerpt').value = backup.excerpt || '';
        Object.keys(parts).forEach(key => {
            const heading = key !== 'about' && parts[key].match(/^<h2\b[^>]*>([\s\S]*?)<\/h2>\s*/i);
            if (heading) {
                const fragment = document.createElement('template');
                fragment.innerHTML = heading[1];
                document.querySelector(`[name="lw_article_titles[${key}]"]`).value = fragment.content.textContent;
                parts[key] = parts[key].slice(heading[0].length);
            }
            const editor = getEditor(key);
            const body = window.switchEditors ? window.switchEditors.wpautop(parts[key]) : parts[key];
            if (editor) editor.undoManager.transact(() => editor.setContent(body));
            else document.querySelector(`#lw_article_${key}`).value = body;
        });
        restoring = false;
        sync();
        document.querySelector('#local-storage-notice').style.display = 'none';
    }, true);
})();
