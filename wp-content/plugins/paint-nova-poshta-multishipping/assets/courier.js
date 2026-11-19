(function ($) {
    'use strict';
    const c = window.pnpmCourier;
    function clear(group, slots) {
        group.removeData('pickup-token');
        clearTimeout(group.data('pickup-timer'));
        group.find('.pnpm-pickup-agreed').prop({checked:false, disabled:true});
        group.find('[data-courier-command="create"]').prop('disabled',true);
        group.find('.pnpm-pickup-preview').empty();
        if (slots) group.find('.pnpm-pickup-interval').empty().append($('<option>').val('').text('—'));
    }
    $(document).on('change input','.pnpm-courier-select,.pnpm-pickup-date,.pnpm-pickup-interval,.pnpm-pickup-weight',function () {
        const g=$(this).closest('.pnpm-courier-group');
        clear(g,$(this).is('.pnpm-courier-select,.pnpm-pickup-date'));
        if ($(this).is('.pnpm-courier-select')) {
            let weight=0;
            g.find('.pnpm-courier-select:checked').each(function(){ weight+=Number($(this).data('weight'))||0; });
            g.find('.pnpm-pickup-weight').val(weight?weight.toFixed(3):'');
        }
    });
    $(document).on('change','.pnpm-pickup-agreed',function(){
        const g=$(this).closest('.pnpm-courier-group');
        g.find('[data-courier-command="create"]').prop('disabled',!this.checked || !g.data('pickup-token'));
    });
    $(document).on('click','[data-courier-command]',async function () {
        const button=$(this), g=button.closest('section'), command=button.data('courier-command');
        if (g.data('busy')) return;
        const payload={action:'pnpm_courier', nonce:c.nonce, command, id:button.data('id'),
            ids:g.find('.pnpm-courier-select:checked').map(function(){return this.value;}).get(),
            date:g.find('.pnpm-pickup-date').val(), interval:g.find('.pnpm-pickup-interval').val(),
            weight:g.find('.pnpm-pickup-weight').val(), token:g.data('pickup-token'),agreed:g.find('.pnpm-pickup-agreed').prop('checked')?'1':'0'};
        if (command==='create' && (!payload.token || payload.agreed!=='1')) return;
        if (command!=='refresh') clear(g,false);
        const controls=g.find('input,select,button'), disabled=controls.map(function(){return this.disabled;}).get();
        g.data('busy',true); controls.prop('disabled',true); g.find('.pnpm-courier-status').text(c.busy);
        let result;
        try { result=await $.post(c.url,payload); }
        catch (error) { result=error.responseJSON || null; }
        controls.each(function(i){this.disabled=disabled[i];}); g.data('busy',false);
        const status=g.find('.pnpm-courier-status');
        if (!result || !result.success) { status.text(result?.data?.message || c.failed); return; }
        status.text('');
        if (command==='create' || command==='refresh') { window.location.reload(); return; }
        if (command==='intervals') {
            const select=g.find('.pnpm-pickup-interval').empty().append($('<option>').val('').text(c.choose));
            result.data.slots.forEach(slot=>select.append($('<option>').val(slot.number).text(slot.label)));
            if (!result.data.slots.length) status.text(c.empty);
            return;
        }
        const p=result.data.preview, view=g.find('.pnpm-pickup-preview');
        [c.address+' '+p.address, p.phone, p.date+' · '+p.time, c.weight+': '+p.weight+' · '+c.places+': '+p.places,p.ttns.join(', ')].forEach(line=>view.append($('<p>').text(line)));
        if (!p.enabled) { status.text(c.disabled); return; }
        g.data('pickup-token',p.token); g.find('.pnpm-pickup-agreed').prop('disabled',false);
        g.data('pickup-timer',setTimeout(function(){clear(g,false); status.text(c.expired);},290000));
    });
})(jQuery);
