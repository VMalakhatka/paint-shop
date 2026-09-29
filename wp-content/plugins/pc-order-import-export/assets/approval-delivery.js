(function ($) {
    'use strict';
    const form = document.querySelector('.pcoe-approval-form');
    if (!form) return;
    const panel = form.querySelector('[data-approval-delivery]');
    const result = panel.querySelector('[data-quote-result]');
    const button = panel.querySelector('[data-quote-button]');
    const submit = form.querySelector('button[type=submit]');
    const token = form.elements.delivery_quote_token;
    const destination = form.elements.destination;
    const cod = form.elements.payment.querySelector('option[value=cod]');
    const codDisabled = cod ? cod.disabled : false;
    let generation = 0, request = null, timer = null;
    const selected = () => form.elements.delivery.value.startsWith('pnpm_nova_poshta:');
    function invalidate(message) {
        generation++;
        if (request) request.abort();
        clearTimeout(timer);
        token.value = '';
        button.disabled = false;
        result.textContent = message || pcoeApprovalDelivery.waiting;
        result.removeAttribute('aria-busy');
        submit.disabled = selected();
        if (cod) cod.disabled = codDisabled;
    }
    function visibility() {
        invalidate();
        const active = selected();
        panel.hidden = !active;
        destination.closest('.form-row').hidden = active;
        destination.required = !active;
    }
    function line(text) { const p = document.createElement('p'); p.textContent = text; result.append(p); }
    button.addEventListener('click', function () {
        invalidate(pcoeApprovalDelivery.busy);
        const current = generation;
        button.disabled = true; result.setAttribute('aria-busy', 'true');
        const data = Object.fromEntries(new FormData(form));
        data.action = 'pcoe_approval_quote'; data.nonce = pcoeApprovalDelivery.nonce;
        request = $.ajax({url:pcoeApprovalDelivery.ajaxUrl, method:'POST', data, timeout:60000})
            .done(function (response) {
                if (current !== generation || !selected()) return;
                if (!response || !response.success) { fail(response); return; }
                const quote = response.data;
                token.value = quote.token;
                result.textContent = ''; line(quote.summary);
                (quote.parcels || []).forEach(line); (quote.warnings || []).forEach(line);
                destination.value = quote.recipient.city_label + ', ' + (quote.recipient.delivery_type === 'address' ? quote.recipient.address : quote.recipient.point_label);
                if (cod && !quote.cod_allowed) {
                    cod.disabled = true;
                    if (form.elements.payment.value === 'cod') form.elements.payment.value = '';
                    line(pcoeApprovalDelivery.cod);
                }
                submit.disabled = false;
                timer = setTimeout(() => invalidate(pcoeApprovalDelivery.expired), Math.max(0, quote.expires * 1000 - Date.now()));
            })
            .fail(function (xhr, status) { if (current === generation && status !== 'abort') fail(xhr.responseJSON); })
            .always(function () { if (current === generation) { button.disabled = false; result.removeAttribute('aria-busy'); } });
        function fail(response) { token.value = ''; submit.disabled = true; result.textContent = response?.data?.message || pcoeApprovalDelivery.failed; }
    });
    $(document.body).on('pnpm_destination_changed', () => invalidate());
    $(form).on('input change', '[name^=pnpm_]', () => invalidate());
    $(form).on('change', '[name=delivery]', visibility);
    form.addEventListener('pcoe_contact_changed', function () {
        $(form).find('[name=pnpm_city_label], [name=pnpm_city_ref], [name=pnpm_point_label], [name=pnpm_point_ref], [name=pnpm_address]').val('');
        $('#pnpm-point-card, #pnpm-city-results, #pnpm-point-results').empty().prop('hidden', true);
        visibility();
    });
    form.addEventListener('submit', event => { if (selected() && !token.value) { event.preventDefault(); invalidate(); } });
    visibility();
})(jQuery);
