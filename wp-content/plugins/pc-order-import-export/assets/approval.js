(function () {
    'use strict';
    document.querySelectorAll('.pcoe-approval-form [data-saved-contact]').forEach(function (select) {
        select.addEventListener('change', function () {
            const values = JSON.parse(select.selectedOptions[0]?.dataset.contact || '{}');
            const form = select.form;
            for (const key of ['recipient', 'phone', 'destination', 'payment', 'delivery']) {
                const field = form.elements[key];
                if (!field) continue;
                const value = values[key] || '';
                field.value = field.tagName === 'SELECT' && !Array.from(field.options).some(option => option.value === value) ? '' : value;
            }
            form.dispatchEvent(new CustomEvent('pcoe_contact_changed', {bubbles:true}));
        });
    });
})();
