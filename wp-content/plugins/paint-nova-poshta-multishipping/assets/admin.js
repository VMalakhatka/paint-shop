(function ($) {
    'use strict';

    $(function () {
        var $button = $('#pnpm-test-api');
        var $result = $('#pnpm-test-api-result');

        $button.on('click', function () {
            $button.prop('disabled', true);
            $result.removeClass('pnpm-good pnpm-bad').text(pnpmAdmin.testing);

            $.post(pnpmAdmin.ajaxUrl, {
                action: 'pnpm_test_api',
                nonce: pnpmAdmin.nonce
            }).done(function (response) {
                if (response && response.success) {
                    $result.addClass('pnpm-good').text(response.data.message);
                    return;
                }
                $result.addClass('pnpm-bad').text(
                    response && response.data && response.data.message ? response.data.message : pnpmAdmin.failed
                );
            }).fail(function (xhr) {
                var response = xhr.responseJSON;
                $result.addClass('pnpm-bad').text(
                    response && response.data && response.data.message ? response.data.message : pnpmAdmin.failed
                );
            }).always(function () {
                $button.prop('disabled', false);
            });
        });
    });
}(jQuery));

