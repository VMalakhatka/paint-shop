(function ($) {
    'use strict';
    var externalDraft = {};

    var cityTimer = null;
    var pointTimer = null;
    var cityRequest = null;
    var pointRequest = null;
    var detailRequest = null;
    var cityGeneration = 0;
    var pointGeneration = 0;
    var nextPage = null;
    var seenPoints = {};
    var cardKey = '';
    var labels = pnpmCheckout.point;

    function message($target, text, error) {
        $('<div class="pnpm-directory-message"></div>').toggleClass('pnpm-directory-error', !!error).text(text).appendTo($target);
    }

    function pointContext() {
        return { cityRef: $('#pnpm_city_ref').val(), query: $.trim($('#pnpm_point_label').val()), kind: $('#pnpm_delivery_type').val() || 'branch' };
    }

    function pointKey() {
        return [$('#pnpm_city_ref').val(), $('#pnpm_point_ref').val(), $('#pnpm_delivery_type').val()].join('|');
    }

    function renderCard(item) {
        var $card = $('#pnpm-point-card').empty().prop('hidden', false);
        $('<h4></h4>').text(labels.title).appendTo($card);
        $('<p><strong></strong></p>').find('strong').text(item.label).end().appendTo($card);
        if (item.shortAddress) { $('<p></p>').text(item.shortAddress).appendTo($card); }
        var $dl = $('<dl></dl>').appendTo($card);
        function row(label, value) { $('<dt></dt>').text(label).appendTo($dl); $('<dd></dd>').text(value).appendTo($dl); }
        function weight(value, unit) { return value > 0 ? value + ' ' + unit : labels.unknown; }
        function dimensions(values) {
            return values && values.length === 3 && values.every(function (v) { return v > 0; }) ? values.join(' × ') + ' ' + labels.cm : labels.unknown;
        }
        row(labels.status, item.selectable ? labels.working : labels.unavailable);
        row(labels.placeWeight, weight(item.placeWeight, labels.kg));
        row(labels.totalWeight, weight(item.totalWeight, labels.kg));
        row(labels.declaredValue, weight(item.declaredValue, labels.currency));
        row(labels.receivingDimensions, dimensions(item.receivingDimensions));
        row(labels.sendingDimensions, dimensions(item.sendingDimensions));
        var $table = $('<table></table>').appendTo($card);
        $('<caption></caption>').text(labels.hoursTitle).appendTo($table);
        var $head = $('<tr></tr>').appendTo($('<thead></thead>').appendTo($table));
        [labels.day, labels.Schedule, labels.Reception, labels.Delivery].forEach(function (text) { $('<th scope="col"></th>').text(text).appendTo($head); });
        var $body = $('<tbody></tbody>').appendTo($table);
        $.each(labels.days, function (day, text) {
            var $row = $('<tr></tr>').appendTo($body);
            $('<th scope="row"></th>').text(text).appendTo($row);
            ['Schedule', 'Reception', 'Delivery'].forEach(function (field) {
                $('<td></td>').text((item.hours && item.hours[field] && item.hours[field][day]) || labels.shortUnknown).appendTo($row);
            });
        });
        $('<p class="pnpm-point-note"></p>').text(labels.note).appendTo($card);
        $('<p class="pnpm-point-warning"></p>').text(labels.warning).appendTo($card);
        $('<a target="_blank" rel="noopener noreferrer" href="https://novaposhta.ua/help/"></a>').text(labels.official).appendTo($('<p></p>').appendTo($card));
        if (labels.helpUrl) { $('<a target="_blank" rel="noopener noreferrer"></a>').attr('href', labels.helpUrl).text(labels.help).appendTo($('<p></p>').appendTo($card)); }
    }

    function loadPoints(page) {
        var context = pointContext();
        if (!context.cityRef || context.kind === 'address') { return; }
        var generation = ++pointGeneration;
        if (pointRequest) { pointRequest.abort(); }
        var $results = $('#pnpm-point-results').prop('hidden', false);
        if (page === 1) { $results.empty(); seenPoints = {}; }
        $results.find('.pnpm-directory-message, .pnpm-directory-more').remove();
        message($results, pnpmCheckout.searching);
        pointRequest = $.get(pnpmCheckout.ajaxUrl, $.extend({ action: 'pnpm_search_recipient_points', nonce: pnpmCheckout.nonce, page: page }, context))
            .done(function (response) {
                if (generation !== pointGeneration) { return; }
                $results.find('.pnpm-directory-message').remove();
                if (!response || !response.success || !response.data) { failed(); return; }
                var data = response.data;
                nextPage = data.nextPage;
                $.each(data.items || [], function (_, item) {
                    if (seenPoints[item.ref]) { return; }
                    seenPoints[item.ref] = true;
                    var $option = $('<button type="button" class="pnpm-directory-option"></button>').data('point', item);
                    $('<span></span>').text(item.label).appendTo($option);
                    $('<small></small>').text(item.selectable ? labels.placeWeight + ': ' + (item.placeWeight > 0 ? item.placeWeight + ' ' + labels.kg : labels.shortUnknown) : labels.unavailable).appendTo($option);
                    $option.appendTo($results);
                });
                if (!$results.find('.pnpm-directory-option').length) { message($results, nextPage ? labels.emptyPage : pnpmCheckout.nothingFound); }
                if (nextPage) { $('<button type="button" class="pnpm-directory-more"></button>').text(labels.more).on('click', function (event) { event.stopPropagation(); loadPoints(nextPage); }).appendTo($results); }
            }).fail(function (_, status) { if (status !== 'abort' && generation === pointGeneration) { failed(); } });
        function failed() {
            $results.find('.pnpm-directory-message, .pnpm-directory-more').remove();
            message($results, pnpmCheckout.requestFailed, true);
            $('<button type="button" class="pnpm-directory-more"></button>').text(labels.retry).on('click', function (event) { event.stopPropagation(); loadPoints(page); }).appendTo($results);
        }
    }

    function restoreCard() {
        var ref = $('#pnpm_point_ref').val();
        var key = pointKey();
        if (!ref || key === cardKey || $('#pnpm_delivery_type').val() === 'address') { return; }
        cardKey = key;
        if (detailRequest) { detailRequest.abort(); }
        var $card = $('#pnpm-point-card').empty().prop('hidden', false);
        message($card, pnpmCheckout.searching);
        detailRequest = $.get(pnpmCheckout.ajaxUrl, { action: 'pnpm_recipient_point', nonce: pnpmCheckout.nonce, cityRef: $('#pnpm_city_ref').val(), ref: ref, kind: $('#pnpm_delivery_type').val() })
            .done(function (response) {
                if (key !== pointKey()) { return; }
                if (!response || !response.success || !response.data.item) { failed(); return; }
                renderCard(response.data.item);
                if (!response.data.item.selectable) { $('#pnpm_point_ref').val(''); $(document.body).trigger('update_checkout'); }
            }).fail(function (_, status) { if (status !== 'abort' && key === pointKey()) { failed(); } });
        function failed() {
            $card.empty(); message($card, pnpmCheckout.requestFailed, true);
            $('<button type="button"></button>').text(labels.retry).on('click', function () { cardKey = ''; restoreCard(); }).appendTo($card);
        }
    }

    function request(action, data, $results) {
        var generation = ++cityGeneration;
        if (cityRequest) { cityRequest.abort(); }
        $results.prop('hidden', false).html('<div class="pnpm-directory-message">' + pnpmCheckout.searching + '</div>');
        return $.get(pnpmCheckout.ajaxUrl, $.extend({
            action: action,
            nonce: pnpmCheckout.nonce
        }, data)).done(function (response) {
            if (generation !== cityGeneration) { return; }
            if (!response || !response.success) { $results.empty(); message($results, pnpmCheckout.requestFailed, true); return; }
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
        }).fail(function (_, status) {
            if (status === 'abort' || generation !== cityGeneration) { return; }
            $results.html('<div class="pnpm-directory-message pnpm-directory-error">' + pnpmCheckout.requestFailed + '</div>');
        });
    }

    function resetPoint() {
        clearTimeout(pointTimer);
        pointGeneration++;
        if (pointRequest) { pointRequest.abort(); }
        if (detailRequest) { detailRequest.abort(); }
        cardKey = ''; nextPage = null; seenPoints = {};
        $('#pnpm_point_ref').val('');
        $('#pnpm_point_label').val('');
        $('#pnpm-point-results').prop('hidden', true).empty();
        $('#pnpm-point-card').prop('hidden', true).empty();
    }

    function updateFieldVisibility() {
        var method = $('input[name^="shipping_method"]:checked, input[type="hidden"][name^="shipping_method"], select[name^="shipping_method"]').first().val() || '';
        var external = method === 'pnpm_customer_ttn';
        $('#pnpm-external-fields').prop('hidden', !external);
        $('#pnpm-checkout-fields').toggle(!external);
        $('#pnpm-external-fields textarea').each(function () {
            if (Object.prototype.hasOwnProperty.call(externalDraft, this.id)) {
                $(this).val(externalDraft[this.id]);
                showExtractedNumber($(this));
            }
            $(this).prop('disabled', !external);
        });
        var type = $('#pnpm_delivery_type').val() || 'branch';
        var address = type === 'address';
        $('#pnpm-point-fields').toggle(!address);
        $('#pnpm-address-help').toggle(address);
        var label = type === 'parcel_locker' ? pnpmCheckout.parcelLockerLabel : pnpmCheckout.branchLabel;
        $('label[for="pnpm_point_label"]').text(label);
        if (!external && !address) { restoreCard(); }
    }

    $(document.body).on('input', '#pnpm_city_label', function () {
        var query = $.trim($(this).val());
        $('#pnpm_city_ref').val('');
        resetPoint();
        clearTimeout(cityTimer);
        cityGeneration++;
        if (cityRequest) { cityRequest.abort(); }
        if (query.length < 2) {
            $('#pnpm-city-results').prop('hidden', true).empty();
            return;
        }
        cityTimer = setTimeout(function () {
            cityRequest = request('pnpm_search_recipient_cities', {query: query}, $('#pnpm-city-results'));
        }, 300);
    });

    $(document.body).on('click', '#pnpm-city-results .pnpm-directory-option', function () {
        cityGeneration++;
        clearTimeout(cityTimer);
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
        resetPoint();
        $('#pnpm_point_label').val(query);
        if (!cityRef) { return; }
        pointTimer = setTimeout(function () {
            loadPoints(1);
        }, 300);
    });

    $(document.body).on('focus', '#pnpm_point_label', function () {
        if (!$('#pnpm_point_ref').val()) { clearTimeout(pointTimer); pointTimer = setTimeout(function () { loadPoints(1); }, 300); }
    });

    $(document.body).on('click', '#pnpm-point-results .pnpm-directory-option', function () {
        var item = $(this).data('point');
        if (!item) { return; }
        pointGeneration++;
        clearTimeout(pointTimer);
        if (pointRequest) { pointRequest.abort(); }
        if (detailRequest) { detailRequest.abort(); }
        $('#pnpm_point_label').val(item.label);
        $('#pnpm_point_ref').val(item.selectable ? item.ref : '');
        cardKey = pointKey();
        renderCard(item);
        $('#pnpm-point-results').prop('hidden', true).empty();
        $(document.body).trigger('update_checkout');
    });

    $(document).on('click', function (event) {
        if (!$(event.target).closest('.pnpm-directory-field, .pnpm-directory-results').length) {
            $('.pnpm-directory-results').prop('hidden', true);
        }
    });

    $(document.body).on('updated_checkout', updateFieldVisibility);
    $(document.body).on('change', 'input[name^="shipping_method"], select[name^="shipping_method"]', updateFieldVisibility);
    $(document.body).on('input', '#pnpm-external-fields textarea', function () {
        externalDraft[this.id] = $(this).val();
        showExtractedNumber($(this));
        $('[name="pnpm_external_confirm"]').prop('checked', false);
    });
    function showExtractedNumber($field) {
        var numbers = ($field.val().match(/(?<!\d)\d{14}(?!\d)/g) || []).filter(function (n, i, a) { return a.indexOf(n) === i; });
        $field.closest('.pnpm-external-parcel').find('.pnpm-extracted-ttn').text(numbers.length === 1 ? 'TTN: ' + numbers[0] : '');
    }
    $(updateFieldVisibility);
})(jQuery);
