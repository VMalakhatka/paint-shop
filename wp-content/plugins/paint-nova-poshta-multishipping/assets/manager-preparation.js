(function ($) {
    'use strict';
    var $form = $('#pnpm-manager-form');
    if (!$form.length) return;
    var busy = false;
    function dirty() {
        $('#pnpm-manager-preview').empty();
        $('#pnpm-manager-status').text(pnpmManager.dirty);
        $form.find('[name=agreed]').prop('checked', false);
    }
    $form.on('input change', 'input:not([name=agreed]),select', dirty);
    $(document.body).on('pnpm_destination_changed', dirty);
    $form.on('submit', function (event) { event.preventDefault(); });
    $form.on('click', '.pnpm-add-box', function () {
        var $boxes = $(this).siblings('.pnpm-boxes');
        if ($boxes.children().length < 20) {
            $boxes.append(document.querySelector('#pnpm-box-template').content.cloneNode(true)); dirty();
        }
    });
    $form.on('click', '.pnpm-remove-box', function () {
        if ($(this).closest('.pnpm-boxes').children().length > 1) { $(this).closest('.pnpm-box').remove(); dirty(); }
    });
    $form.on('click', '[data-pnpm-command]', function () {
        if (busy) return;
        var command = $(this).data('pnpm-command');
        var recipient = {name:$form.find('[name=name]').val(), phone:$form.find('[name=phone]').val(), address:$('#pnpm_address').val() || ''};
        ['city_label','city_ref','delivery_type','point_label','point_ref'].forEach(function (key) { recipient[key] = $('#pnpm_' + key).val(); });
        var payload = {revision:$form.attr('data-revision'),recipient:recipient,groups:{},agreed:$form.find('[name=agreed]').is(':checked') ? '1' : ''};
        $form.find('fieldset[data-location]').each(function () {
            var $group = $(this), boxes = [];
            $group.find('.pnpm-box').each(function () {
                var box = {}; $(this).find('[data-box]').each(function () { box[$(this).data('box')] = $(this).val(); }); boxes.push(box);
            });
            payload.groups[$group.data('location')] = {payer:$group.find('.pnpm-payer').val(),boxes:boxes};
        });
        busy = true;
        // Freeze the reviewed input while a request is in flight; no automatic retries.
        $form.find('input,select,button:not(:disabled)').attr('data-pnpm-busy', '1').prop('disabled', true);
        $('#pnpm-manager-preview').empty(); $('#pnpm-manager-status').text(pnpmManager.loading);
        $.post(pnpmManager.url, {action:'pnpm_manager_preparation',nonce:pnpmManager.nonce,command:command,order_id:$form.data('order'),payload:JSON.stringify(payload)})
            .done(function (response) {
                if (!response || !response.success) { $('#pnpm-manager-status').text(response && response.data && response.data.message || pnpmManager.failure); return; }
                var data = response.data;
                if (data.revision) $form.attr('data-revision', data.revision);
                if (data.recipient) {
                    ['name','phone'].forEach(function (key) { $form.find('[name=' + key + ']').val(data.recipient[key] || ''); });
                    $('#pnpm_city_label').trigger('input'); // Cancel stale directory responses before replacing values.
                    ['city_label','city_ref','delivery_type','point_label','point_ref','address'].forEach(function (key) { $('#pnpm_' + key).val(data.recipient[key] || ''); });
                    $(document.body).trigger('updated_checkout');
                    $form.find('[name=agreed]').prop('checked', false);
                }
                $('#pnpm-manager-status').text(data.message);
                if (data.preview) {
                    var $preview = $('#pnpm-manager-preview');
                    $('<p></p>').text([data.preview.recipient.name,data.preview.recipient.phone,data.preview.recipient.city_label,data.preview.recipient.point_label || data.preview.recipient.address].join(' · ')).appendTo($preview);
                    $.each(data.preview.groups, function (_, group) {
                        $('<h3></h3>').text(group.location_name).appendTo($preview);
                        group.boxes.forEach(function (box, index) { $('<p></p>').text('#' + (index + 1) + ': ' + box.weight + ' ' + pnpmManager.kg + ' · ' + [box.length,box.width,box.height].join(' × ') + ' ' + pnpmManager.cm).appendTo($preview); });
                        $('<p></p>').text(pnpmManager.payer + ': ' + pnpmManager[group.payer] + ' · ' + pnpmManager.cod + ': ' + group.cod + ' ' + pnpmManager.currency).appendTo($preview);
                    });
                    var $list = $('<ul class="pnpm-blockers"></ul>').appendTo($preview);
                    data.blockers.forEach(function (text) { $('<li></li>').text(text).appendTo($list); });
                }
            }).fail(function (xhr) { $('#pnpm-manager-status').text(xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message || pnpmManager.failure); })
            .always(function () { busy = false; $form.find('[data-pnpm-busy]').prop('disabled', false).removeAttr('data-pnpm-busy'); });
    });
})(jQuery);
