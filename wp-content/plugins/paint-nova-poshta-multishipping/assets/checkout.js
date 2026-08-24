(function ($) {
    'use strict';

    var cityTimer = null;
    var pointTimer = null;

    function request(action, data, $results) {
        $results.prop('hidden', false).html('<div class="pnpm-directory-message">' + pnpmCheckout.searching + '</div>');
        return $.get(pnpmCheckout.ajaxUrl, $.extend({
            action: action,
            nonce: pnpmCheckout.nonce
        }, data)).done(function (response) {
            var items = response && response.success && response.data ? response.data.items : [];
            if (!items || !items.length) {
                $results.html('<div class="pnpm-directory-message">' + pnpmCheckout.nothingFound + '</div>');
                return;
            }
            $results.empty();
            $.each(items, function (_, item) {
                $('<button type="button" class="pnpm-directory-option"></button>')
                    .text(item.label || '')
                    .attr('data-ref', item.ref || '')
                    .attr('data-label', item.label || '')
                    .appendTo($results);
            });
        }).fail(function () {
            $results.html('<div class="pnpm-directory-message pnpm-directory-error">' + pnpmCheckout.requestFailed + '</div>');
        });
    }

    function resetPoint() {
        $('#pnpm_point_ref').val('');
        $('#pnpm_point_label').val('');
        $('#pnpm-point-results').prop('hidden', true).empty();
    }

    function updateFieldVisibility() {
        var type = $('#pnpm_delivery_type').val() || 'branch';
        var address = type === 'address';
        $('#pnpm-point-fields').toggle(!address);
        $('#pnpm-address-help').toggle(address);
        var label = type === 'parcel_locker' ? pnpmCheckout.parcelLockerLabel : pnpmCheckout.branchLabel;
        $('label[for="pnpm_point_label"]').text(label);
    }

    $(document.body).on('input', '#pnpm_city_label', function () {
        var query = $.trim($(this).val());
        $('#pnpm_city_ref').val('');
        resetPoint();
        clearTimeout(cityTimer);
        if (query.length < 2) {
            $('#pnpm-city-results').prop('hidden', true).empty();
            return;
        }
        cityTimer = setTimeout(function () {
            request('pnpm_search_recipient_cities', {query: query}, $('#pnpm-city-results'));
        }, 300);
    });

    $(document.body).on('click', '#pnpm-city-results .pnpm-directory-option', function () {
        $('#pnpm_city_label').val($(this).data('label'));
        $('#pnpm_city_ref').val($(this).data('ref'));
        $('#pnpm-city-results').prop('hidden', true).empty();
        resetPoint();
        $(document.body).trigger('update_checkout');
    });

    $(document.body).on('change', '#pnpm_delivery_type', function () {
        resetPoint();
        updateFieldVisibility();
        $(document.body).trigger('update_checkout');
    });

    $(document.body).on('input', '#pnpm_point_label', function () {
        var query = $.trim($(this).val());
        var cityRef = $('#pnpm_city_ref').val();
        $('#pnpm_point_ref').val('');
        clearTimeout(pointTimer);
        if (!cityRef || query.length < 1) {
            $('#pnpm-point-results').prop('hidden', true).empty();
            return;
        }
        pointTimer = setTimeout(function () {
            request('pnpm_search_recipient_points', {
                cityRef: cityRef,
                query: query,
                kind: $('#pnpm_delivery_type').val() || 'branch'
            }, $('#pnpm-point-results'));
        }, 300);
    });

    $(document.body).on('click', '#pnpm-point-results .pnpm-directory-option', function () {
        $('#pnpm_point_label').val($(this).data('label'));
        $('#pnpm_point_ref').val($(this).data('ref'));
        $('#pnpm-point-results').prop('hidden', true).empty();
        $(document.body).trigger('update_checkout');
    });

    $(document).on('click', function (event) {
        if (!$(event.target).closest('.pnpm-directory-field, .pnpm-directory-results').length) {
            $('.pnpm-directory-results').prop('hidden', true);
        }
    });

    $(document.body).on('updated_checkout', updateFieldVisibility);
    $(updateFieldVisibility);
})(jQuery);
