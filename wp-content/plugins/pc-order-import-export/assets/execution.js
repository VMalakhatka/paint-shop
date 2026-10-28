// Standard POST forms keep working without JavaScript. Disable repeated submissions while waiting.
function pcoeRoadmapChoice(select, changed) {
    const form = select.closest('form');
    if (!form) return;
    form.querySelectorAll('[data-roadmap-scenario]').forEach(panel => { panel.hidden = panel.dataset.roadmapScenario !== select.value; });
    const empty = form.querySelector('[data-roadmap-empty]');
    if (empty) empty.hidden = !!select.value;
    const status = form.querySelector('[data-roadmap-status]');
    const option = select.selectedOptions[0];
    if (status) {
        const skip = status.querySelector('[value="skipped"]');
        if (skip) skip.disabled = option?.dataset.optional !== '1';
        if (changed) status.value = option?.dataset.status || 'in_progress';
        if (skip?.disabled && status.value === 'skipped') status.value = 'in_progress';
    }
    if (changed) {
        const detail = form.querySelector('[name="stage_detail"]');
        const due = form.querySelector('[name="due"]');
        if (detail) detail.value = option?.dataset.detail || '';
        if (due) due.value = option?.dataset.due || '';
    }
}
document.addEventListener('change', event => {
    if (event.target.matches('[data-roadmap-stage]')) pcoeRoadmapChoice(event.target, true);
});
document.addEventListener('click', event => {
    const link = event.target.closest('[data-roadmap-select]');
    if (!link) return;
    const select = link.closest('#pcoe-execution')?.querySelector('[data-roadmap-stage]');
    if (!select) return;
    event.preventDefault();
    select.value = link.dataset.roadmapSelect;
    pcoeRoadmapChoice(select, true);
    select.focus();select.scrollIntoView({block:'center',behavior:'smooth'});
});
document.addEventListener('DOMContentLoaded', () => document.querySelectorAll('[data-roadmap-stage]').forEach(select => pcoeRoadmapChoice(select, false)));
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
    document.querySelectorAll('[data-roadmap-stage]').forEach(select => pcoeRoadmapChoice(select, false));
    document.querySelectorAll('.pcoe-execution-form').forEach(form => {
        delete form.dataset.busy;
        form.removeAttribute('aria-busy');
        form.querySelectorAll('button').forEach(button => { button.disabled = false; });
    });
});
