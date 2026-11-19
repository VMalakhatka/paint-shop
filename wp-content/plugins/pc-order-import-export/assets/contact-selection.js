(() => {
    const all = document.querySelector('[data-contact-select-all]');
    if (!all) return;
    const form = all.form;
    const rows = Array.from(form.querySelectorAll('[data-contact-select]'));
    const update = () => {
        const count = rows.filter(row => row.checked).length;
        all.checked = count === rows.length;
        all.indeterminate = count > 0 && count < rows.length;
        form.querySelectorAll('button').forEach(button => { button.disabled = count === 0; });
        rows.forEach(row => {
            row.closest('tr').querySelectorAll('select').forEach(select => { select.disabled = !row.checked; });
        });
    };
    all.addEventListener('change', () => {
        rows.forEach(row => { row.checked = all.checked; });
        update();
    });
    form.addEventListener('change', event => { if (event.target.matches('[data-contact-select]')) update(); });
    window.addEventListener('pageshow', update);
    update();
})();
