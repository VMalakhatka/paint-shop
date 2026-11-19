(function ($) {
    'use strict';
    var busy = false, dirty = false, timers = [];
    function clearPreview($card, message) {
        clearTimeout($card.data('expiryTimer'));
        $card.removeData('token').removeData('deadline').removeData('expiryTimer');
        $card.find('[data-shipment-command="create"],.pnpm-create-agreed').removeAttr('data-pnpm-flight data-pnpm-busy').prop('disabled',true).prop('checked',false);
        $card.find('.pnpm-carrier-preview').empty();
        if (message) $card.find('.pnpm-lifecycle-status').text(message);
    }
    function expired($card) {
        if ($card.data('token') && Date.now() >= $card.data('deadline')) {
            clearPreview($card, pnpmLifecycle.expired); return true;
        }
        return false;
    }
    function invalidate() {
        dirty = true;
        $('.pnpm-shipment').each(function () { clearPreview($(this), pnpmLifecycle.dirty); });
        $('.pnpm-shipment [data-shipment-command="preview"]').prop('disabled',true);
    }
    $('#pnpm-manager-form').on('input change', 'input:not([name=agreed]),select', invalidate);
    $('#pnpm-manager-form').on('click','.pnpm-add-box,.pnpm-remove-box,[data-pnpm-command="confirmation"]',invalidate);
    $(document.body).on('pnpm_destination_changed pnpm_preparation_saving',invalidate);
    $(document.body).on('pnpm_preparation_saved',function (_, html) {
        var names = {};
        $('.pnpm-shipment').each(function () {
            var $card = $(this), person = {};
            $card.find('[data-person]').each(function () { person[$(this).data('person')] = $(this).val(); });
            names[$card.data('id')] = {identity:$card.attr('data-recipient'), person:person};
        });
        invalidate();
        var $old = $('#pnpm-lifecycle-panel');
        var $next = typeof html === 'string' ? $($.parseHTML(html, document, false)).filter('#pnpm-lifecycle-panel') : $();
        if ($next.length !== 1 || !$old.length || String($next.data('order')) !== String($old.data('order'))) return;
        timers.forEach(clearTimeout); timers = [];
        $old.replaceWith($next);
        dirty = false;
        $next.find('.pnpm-shipment').each(function () {
            var $card = $(this), previous = names[$card.data('id')];
            // Keep reviewed spelling only for the same saved recipient, never across a recipient change.
            if (!previous || !previous.identity || previous.identity !== $card.attr('data-recipient')) return;
            $card.find('[data-person]:not([readonly])').each(function () {
                var key = $(this).data('person');
                if (Object.prototype.hasOwnProperty.call(previous.person, key)) $(this).val(previous.person[key]);
            });
        });
    });
    $(window).on('focus',function () { $('.pnpm-shipment').each(function () { expired($(this)); }); });
    $(document.body).on('input','.pnpm-shipment [data-person]',function () {
        var $card=$(this).closest('.pnpm-shipment');
        clearPreview($card);
    }).on('change','.pnpm-shipment .pnpm-create-agreed',function () {
        var $card=$(this).closest('.pnpm-shipment');
        if (expired($card)) return;
        $card.find('[data-shipment-command="create"]').prop('disabled',!this.checked || !$card.data('token') || dirty);
    }).on('click','.pnpm-shipment [data-shipment-command]',function () {
        if (busy || $('[data-pnpm-busy]').length) return;
        var $button=$(this), $card=$button.closest('.pnpm-shipment'), command=$button.data('shipment-command');
        if (dirty && command!=='email') return;
        if (command==='create' && (expired($card) || !$card.data('token'))) return;
        if (command==='preview') {
            clearPreview($card);
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
                var ttl = Math.min(300, Math.max(0, Number(p.expires_in) || 0));
                $card.data('token',p.token).data('deadline',Date.now()+ttl*1000);
                var timer=setTimeout(function () { expired($card); },ttl*1000);
                $card.data('expiryTimer',timer); timers.push(timer);
                $('<p>').text(p.recipient+' · '+p.phone).appendTo($preview);
                $('<p>').text(p.origin+' → '+p.destination).appendTo($preview);
                $('<p>').text(pnpmLifecycle.cost+': '+p.payload.Cost+' / '+p.estimate).appendTo($preview);
                $('<p>').text(pnpmLifecycle.payer+': '+pnpmLifecycle[p.payload.PayerType]).appendTo($preview);
                (p.cod_summary || []).forEach(function (line) { $('<p>').text(line).appendTo($preview); });
                p.payload.OptionsSeat.forEach(function (box,i) { $('<p>').text('#'+(i+1)+' · '+pnpmLifecycle.box+': '+box.weight+' / '+[box.volumetricLength,box.volumetricWidth,box.volumetricHeight].join(' × ')).appendTo($preview); });
                $card.find('.pnpm-create-agreed').removeAttr('data-pnpm-flight').prop('disabled',!p.enabled).prop('checked',false);
                $card.find('[data-shipment-command="create"]').removeAttr('data-pnpm-flight').prop('disabled',true);
                $card.find('.pnpm-lifecycle-status').text(p.enabled?'':pnpmLifecycle.disabled);
                expired($card);
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
