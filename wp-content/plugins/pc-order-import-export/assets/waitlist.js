/* Native POST forms: visible pending state, no mutation retries. */
document.addEventListener('submit', function (event) {
    const form = event.target;
    if (!form.matches('.pcoe-waitlist-form')) return;
    if (form.dataset.submitting) { event.preventDefault(); return; }
    form.dataset.submitting = '1';
    const button = event.submitter;
    // Disabled buttons are not submitted: preserve the chosen operation first.
    if (button && button.name) {
        const choice = document.createElement('input');
        choice.type = 'hidden'; choice.name = button.name; choice.value = button.value;
        form.appendChild(choice);
    }
    form.setAttribute('aria-busy', 'true');
    form.querySelectorAll('button').forEach(function (item) { item.disabled = true; });
    if (button) button.textContent = form.dataset.pending;
});
window.addEventListener('pageshow', function (event) {
    // A history-restored form may carry a consumed revision. Refresh by GET only.
    if (event.persisted && document.querySelector('.pcoe-waitlist-form[data-submitting]')) window.location.reload();
});
