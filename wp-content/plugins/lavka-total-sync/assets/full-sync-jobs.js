(function ($) {
    'use strict';
    var panel = document.getElementById('lts-full-job');
    if (!panel) return;
    var timer;
    var pending = false;
    function render(job) {
        if (!job || !job.jobId) return;
        panel.hidden = false;
        panel.dataset.status = job.status;
        panel.querySelectorAll('[data-job-field]').forEach(function (el) {
            var value = job[el.dataset.jobField];
            el.textContent = value === undefined || value === null ? '' : String(value);
        });
        var warning = panel.querySelector('.lts-job-connection');
        warning.hidden = !job.poll_error;
        warning.textContent = job.poll_error ? LTS_FULL_JOB.unavailable : '';
        panel.querySelector('.lts-job-diagnostics').textContent = JSON.stringify(job, null, 2);
    }
    function poll() {
        if (pending || document.hidden) { timer = setTimeout(poll, 5000); return; }
        pending = true;
        $.ajax({url: LTS_ADMIN.ajaxUrl, method: 'POST', timeout: 10000,
            data: {action: 'lts_full_job_status', nonce: LTS_ADMIN.nonce}})
            .done(function (response) {
                if (response && response.success) render(response.data.job);
            }).fail(function () {
                var warning = panel.querySelector('.lts-job-connection');
                warning.hidden = false;
                warning.textContent = LTS_FULL_JOB.unavailable;
            }).always(function () {
                pending = false;
                timer = setTimeout(poll, 5000);
            });
    }
    $(document).on('lts:job', function (event, job) { render(job); });
    window.addEventListener('pagehide', function () { clearTimeout(timer); });
    render(LTS_FULL_JOB.job);
    poll();
})(jQuery);
