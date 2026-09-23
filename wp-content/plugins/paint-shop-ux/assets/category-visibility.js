(function ($) {
    'use strict';
    $(function () {
        $('#psu-category-excluded').selectWoo({
            width: '100%',
            placeholder: $('#psu-category-excluded').data('placeholder'),
            ajax: {
                url: psuCategoryVisibility.url,
                dataType: 'json',
                delay: 250,
                data: function (params) {
                    return {action: 'psu_category_visibility_search', security: psuCategoryVisibility.nonce,
                        term: params.term || '', page: params.page || 1};
                },
                processResults: function (data) { return data; }
            }
        });
    });
}(jQuery));
