(function ($) {
    'use strict';
    $(document).on('click', '.pnpm-external-action', function () {
        var $panel = $(this).closest('.pnpm-external-review');
        var operation = $(this).data('operation');
        var $buttons = $panel.find('button');
        $buttons.prop('disabled', true);
        $panel.find('.pnpm-external-result').text(pnpmExternal.busy);
        $.post(pnpmExternal.url, {
            action: 'pnpm_external_action', nonce: pnpmExternal.nonce,
            shipment_id: $panel.data('shipment'), operation: operation,
            confirmed: $panel.find('.pnpm-external-confirm').prop('checked') ? '1' : '0',
            note: $panel.find('.pnpm-external-note').val() || ''
        }).done(function (result) {
            $panel.find('.pnpm-external-result').text(result.data && result.data.message || pnpmExternal.failed);
            if (result.success && result.data.reload) { window.location.reload(); }
        }).fail(function (xhr) {
            $panel.find('.pnpm-external-result').text(xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message || pnpmExternal.failed);
        }).always(function () { $buttons.prop('disabled', false); });
    });
})(jQuery);
