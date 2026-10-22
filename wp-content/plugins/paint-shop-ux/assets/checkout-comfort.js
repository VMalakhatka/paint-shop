(function ($) {
    'use strict';
    $(function () {
        const selector = ['billing', 'shipping'].flatMap(section =>
            ['address_1', 'address_2', 'city', 'state', 'postcode'].map(field => '#' + section + '_' + field + '_field')
        ).join(',');
        const saved = new WeakMap();
        let active = false;
        function restore() {
            document.querySelectorAll(selector).forEach(row => {
                const state = saved.get(row);
                row.classList.remove('psu-pickup-address');
                if (!state) return;
                row.classList.toggle('validate-required', state.required);
                state.inputs.forEach(({input, required, aria}) => {
                    input.required = required;
                    if (aria === null) input.removeAttribute('aria-required');
                    else input.setAttribute('aria-required', aria);
                });
                saved.delete(row);
            });
        }
        function sync() {
            const hint = document.querySelector('.woocommerce-checkout-review-order-table .psu-pickup-hint');
            let ids = [];
            try { ids = JSON.parse(hint?.dataset.pickupRates || '[]'); } catch (_) {}
            const choices = Array.from(document.querySelectorAll('form.checkout input[name^="shipping_method"]:checked, form.checkout input[type="hidden"][name^="shipping_method"], form.checkout select[name^="shipping_method"]'));
            const pickup = choices.length > 0 && choices.every(input => ids.includes(input.value));
            if (hint) hint.hidden = !pickup;
            if (!pickup) { restore(); active = false; return; }
            document.querySelectorAll(selector).forEach(row => {
                if (!saved.has(row)) saved.set(row, {
                    required: row.classList.contains('validate-required'),
                    inputs: Array.from(row.querySelectorAll('input, select')).map(input => ({input, required:input.required, aria:input.getAttribute('aria-required')}))
                });
                row.classList.add('psu-pickup-address');
                row.classList.remove('validate-required', 'woocommerce-invalid', 'woocommerce-invalid-required-field');
                row.querySelectorAll('input, select').forEach(input => { input.required = false; input.setAttribute('aria-required', 'false'); });
            });
            active = true;
        }
        $(document.body).on('updated_checkout.psuComfort change.psuComfort', sync);
        // Woo updates locale requirements synchronously. Drop snapshots so a new
        // country/region input is restored with that country's rules, not old ones.
        $(document.body).on('country_to_state_changing.psuComfort', function () {
            if (active) document.querySelectorAll(selector).forEach(row => {
                saved.delete(row);
                const required = row.classList.contains('validate-required');
                row.querySelectorAll('input, select').forEach(input => {
                    input.required = required;
                    input.setAttribute('aria-required', String(required));
                });
            });
            setTimeout(sync, 0);
        });
        sync();
    });
})(jQuery);
