(function ($) {
    'use strict';
    var busy = false, dirty = false;
    function invalidate() {
        dirty = true;
        $('.pnpm-shipment').removeData('token').find('[data-shipment-command="create"],.pnpm-create-agreed').prop('disabled',true).prop('checked',false);
        $('.pnpm-shipment [data-shipment-command="preview"]').prop('disabled',true);
        $('.pnpm-lifecycle-status').text(pnpmLifecycle.dirty);
    }
    $('#pnpm-manager-form').on('input change', 'input:not([name=agreed]),select', invalidate);
    $('#pnpm-manager-form').on('click','.pnpm-add-box,.pnpm-remove-box,[data-pnpm-command="confirmation"]',invalidate);
    $(document.body).on('pnpm_destination_changed pnpm_preparation_saved',invalidate);
    $('.pnpm-shipment').on('input','[data-person]',function () {
        var $card=$(this).closest('.pnpm-shipment');
        $card.removeData('token').find('.pnpm-carrier-preview').empty();
        $card.find('[data-shipment-command="create"],.pnpm-create-agreed').prop('disabled',true).prop('checked',false);
    }).on('change','.pnpm-create-agreed',function () {
        $(this).closest('.pnpm-shipment').find('[data-shipment-command="create"]').prop('disabled',!this.checked);
    }).on('click','[data-shipment-command]',function () {
        if (busy) return;
        var $button=$(this), $card=$button.closest('.pnpm-shipment'), command=$button.data('shipment-command');
        if (dirty && command!=='email') return;
        if (command==='preview') {
            $card.removeData('token');
            $card.find('[data-shipment-command="create"],.pnpm-create-agreed').prop('disabled',true).prop('checked',false);
        }
        var person={}; $card.find('[data-person]').each(function () { person[$(this).data('person')]=$(this).val(); });
        busy=true;
        $('.pnpm-lifecycle button:not(:disabled),.pnpm-lifecycle input:not(:disabled),#pnpm-manager-form input:not(:disabled),#pnpm-manager-form select:not(:disabled),#pnpm-manager-form button:not(:disabled)').attr('data-pnpm-flight','1').prop('disabled',true);
        $card.find('.pnpm-lifecycle-status').text(pnpmLifecycle.busy);
        $.post(pnpmLifecycle.url,{action:'pnpm_shipment_command',nonce:pnpmLifecycle.nonce,order_id:$card.data('order'),id:$card.data('id'),
            command:command,person:person,token:$card.data('token') || '',agreed:$card.find('.pnpm-create-agreed').is(':checked')?'1':'',recipient_hash:$button.data('email') || ''})
            .done(function (response) {
                if (!response || !response.success) { $card.find('.pnpm-lifecycle-status').text(response && response.data && response.data.message || pnpmLifecycle.failed); return; }
                if (!response.data.preview) { window.location.reload(); return; }
                var p=response.data.preview, $preview=$card.find('.pnpm-carrier-preview').empty();
                $card.data('token',p.token);
                $('<p>').text(p.recipient+' · '+p.phone).appendTo($preview);
                $('<p>').text(p.origin+' → '+p.destination).appendTo($preview);
                $('<p>').text(pnpmLifecycle.cost+': '+p.payload.Cost+' / '+p.estimate).appendTo($preview);
                $('<p>').text(pnpmLifecycle.payer+': '+pnpmLifecycle[p.payload.PayerType]).appendTo($preview);
                p.payload.OptionsSeat.forEach(function (box,i) { $('<p>').text('#'+(i+1)+' · '+pnpmLifecycle.box+': '+box.weight+' / '+[box.volumetricLength,box.volumetricWidth,box.volumetricHeight].join(' × ')).appendTo($preview); });
                $card.find('.pnpm-create-agreed').removeAttr('data-pnpm-flight').prop('disabled',!p.enabled).prop('checked',false);
                $card.find('[data-shipment-command="create"]').removeAttr('data-pnpm-flight').prop('disabled',true);
                $card.find('.pnpm-lifecycle-status').text(p.enabled?'':pnpmLifecycle.disabled);
            }).fail(function (xhr) { $card.find('.pnpm-lifecycle-status').text(xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message || pnpmLifecycle.failed); })
            .always(function () {
                busy=false;
                $('[data-pnpm-flight]').prop('disabled',false).removeAttr('data-pnpm-flight');
                if (command==='create' || command==='email') {
                    // Even an HTTP timeout must not invite a second send. Reload reads durable server state.
                    $button.prop('disabled',true); $card.find('.pnpm-create-agreed').prop('disabled',true);
                }
            });
    });
})(jQuery);
