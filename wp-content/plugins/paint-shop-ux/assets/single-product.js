/* Presentation only: one Woo AJAX request for simple products; no automatic retry. */
(function ($) {
    'use strict';
    var config = window.psuSingleProduct;
    if (!config || !window.wc_add_to_cart_params) return;

    $(document).on('submit.psuProduct', '.psu-product[data-product-type="simple"] .psu-product__purchase form.cart', function (event) {
        var form = $(this), button = form.find('.single_add_to_cart_button'), qty = form.find('input.qty');
        var productId = Number(button.val() || form.find('[name="add-to-cart"]').val());
        var quantity = qty.length ? Number(qty.val()) : 1;
        if (!productId || !Number.isFinite(quantity) || quantity <= 0) return;
        event.preventDefault();
        if (form.data('psu-pending') || button.prop('disabled')) return;

        var feedback = form.prev('.psu-product-feedback');
        if (!feedback.length) feedback = $('<div class="psu-product-feedback" role="status" aria-live="polite" aria-atomic="true"></div>').insertBefore(form);
        feedback.hide().empty().removeClass('is-error');
        var tell = function (message, error) {
            feedback.empty().toggleClass('is-error', error).append(document.createTextNode(message + ' '));
            $('<a></a>').attr('href', config.cartUrl).text(config.cartLabel).appendTo(feedback);
            feedback.show();
        };
        form.data('psu-pending', true);
        button.prop('disabled', true).attr('aria-busy', 'true').addClass('loading');
        var data = form.serializeArray();
        data.push({name: 'product_id', value: productId});
        var exhausted = false;
        $.ajax({
            type: 'POST',
            url: window.wc_add_to_cart_params.wc_ajax_url.replace('%%endpoint%%', 'add_to_cart'),
            data: $.param(data),
            dataType: 'json',
            success: function (response) {
                if (response && response.error) { tell(config.rejected, true); return; }
                if (!response || !response.fragments || typeof response.fragments !== 'object' || typeof response.cart_hash !== 'string') {
                    tell(config.unknown, true); return;
                }
                $(document.body).trigger('added_to_cart', [response.fragments, response.cart_hash, button]);
                var max = Number(qty.attr('max'));
                if (qty.length && qty.attr('max') !== undefined && Number.isFinite(max) && max >= 0) {
                    var remaining = Math.max(0, max - quantity);
                    qty.attr('max', String(remaining));
                    exhausted = remaining === 0;
                    if (exhausted) qty.val('0').prop('disabled', true);
                    else qty.val(String(Math.min(Number(qty.val()) || 1, remaining)));
                }
                var count = form.closest('.psu-product__purchase').find('.slu-in-cart-qty');
                if (count.length) count.text(String((Number(count.text()) || 0) + quantity));
                tell(config.success, false);
            },
            error: function () { tell(config.unknown, true); },
            complete: function () {
                form.data('psu-pending', false);
                button.prop('disabled', exhausted).removeAttr('aria-busy').removeClass('loading').toggleClass('disabled', exhausted);
            }
        });
    });
})(jQuery);
