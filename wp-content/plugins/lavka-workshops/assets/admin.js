(() => {
    const body = document.querySelector('.lw-dates tbody');
    const template = document.querySelector('#lw-date-template');
    if (!body || !template) return;
    let index = 1000;
    function add(source) {
        const fragment = document.createElement('template');
        fragment.innerHTML = template.innerHTML.replaceAll('__INDEX__', String(index++));
        const row = fragment.content.querySelector('tr');
        if (source) {
            const values = [...source.querySelectorAll('input, select')];
            row.querySelectorAll('input, select').forEach((field, i) => { field.value = values[i].value; });
            row.querySelector('input[type=hidden]').value = '';
            row.querySelector('input[type=date]').value = '';
            row.querySelector('select[name$="[state]"]').value = 'open';
        }
        body.append(row);
        row.querySelector('input[type=date]').focus();
    }
    document.querySelector('#lw-add-date').addEventListener('click', () => add());
    body.addEventListener('click', event => {
        if (event.target.closest('.lw-copy-row')) add(event.target.closest('tr'));
        if (event.target.closest('.lw-remove-row') && window.confirm(lwAdmin.remove)) event.target.closest('tr').remove();
    });
})();
