// Standard POST forms keep working without JavaScript. Disable repeated submissions while waiting.
function pcoeRoadmapChoice(select, changed) {
    const form = select.closest('form');
    if (!form) return;
    form.querySelectorAll('[data-roadmap-scenario]').forEach(panel => { panel.hidden = panel.dataset.roadmapScenario !== select.value; });
    const empty = form.querySelector('[data-roadmap-empty]');
    if (empty) empty.hidden = !!select.value;
    const option = select.selectedOptions[0];
    if (changed) {
        const detail = form.querySelector('[name="stage_detail"]');
        const due = form.querySelector('[name="due"]');
        if (detail) detail.value = option?.dataset.detail || '';
        if (due) due.value = option?.dataset.due || '';
    }
}
function pcoeOperationChoice(select, changed = false) {
    const form = select.closest('form');
    if (!form) return;
    const option = select.selectedOptions[0];
    const own = select.value === 'custom';
    const named = own || select.value === 'planned:legacy';
    const title = form.querySelector('[data-operation-title]');
    if (title) {
        title.hidden = !own;
        const input = title.querySelector('input');
        input.disabled = !own; input.required = own;
    }
    const status = form.querySelector('[data-operation-status]');
    if (status) {
        if (changed) status.value = 'completed';
        status.querySelector('[value="in_progress"]').disabled = named;
        status.querySelector('[value="skipped"]').disabled = named || option?.dataset.optional !== '1';
        if (status.selectedOptions[0]?.disabled) status.value = 'completed';
    }
    form.querySelectorAll('[data-operation-scenario]').forEach(panel => { panel.hidden = panel.dataset.operationScenario !== option?.dataset.stage; });
    form.querySelectorAll('[data-operation-plan]').forEach(panel => { panel.hidden = panel.dataset.operationPlan !== select.value; });
    form.querySelector('[data-operation-custom]').hidden = !named;
    form.querySelector('[data-operation-empty]').hidden = !!select.value;
}
document.addEventListener('change', event => {
    if (event.target.matches('[data-roadmap-stage]')) pcoeRoadmapChoice(event.target, true);
    if (event.target.matches('[data-operation-choice]')) pcoeOperationChoice(event.target, true);
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
document.addEventListener('DOMContentLoaded', () => document.querySelectorAll('[data-operation-choice]').forEach(select => pcoeOperationChoice(select)));
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
    document.querySelectorAll('[data-operation-choice]').forEach(select => pcoeOperationChoice(select));
    document.querySelectorAll('.pcoe-execution-form').forEach(form => {
        delete form.dataset.busy;
        form.removeAttribute('aria-busy');
        form.querySelectorAll('button').forEach(button => { button.disabled = false; });
    });
});
