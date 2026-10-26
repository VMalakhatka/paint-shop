// Standard POST forms keep working without JavaScript. Disable repeated submissions while waiting.
document.addEventListener('submit', function (event) {
    const form = event.target;
    if (!form.matches('.pcoe-execution-form')) return;
    if (form.dataset.busy) { event.preventDefault(); return; }
    form.dataset.busy = '1';
    form.setAttribute('aria-busy', 'true');
    form.querySelectorAll('button[type="submit"], button:not([type])').forEach(button => { button.disabled = true; });
    const status = document.createElement('p');
    status.setAttribute('role', 'status');
    status.textContent = window.pcoeExecutionWait || '';
    form.append(status);
});
window.addEventListener('pageshow', function () {
    document.querySelectorAll('.pcoe-execution-form').forEach(form => {
        delete form.dataset.busy;
        form.removeAttribute('aria-busy');
        form.querySelectorAll('button').forEach(button => { button.disabled = false; });
    });
});
