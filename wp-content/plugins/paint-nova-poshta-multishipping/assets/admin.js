(function ($) {
    'use strict';

    $(function () {
        var senderDirectory = null;
        var $apiButton = $('#pnpm-test-api');
        var $apiResult = $('#pnpm-test-api-result');
        var $sendersButton = $('#pnpm-load-senders');
        var $sendersResult = $('#pnpm-load-senders-result');

        $apiButton.on('click', function () {
            $apiButton.prop('disabled', true);
            $apiResult.removeClass('pnpm-good pnpm-bad').text(pnpmAdmin.testing);

            $.post(pnpmAdmin.ajaxUrl, {
                action: 'pnpm_test_api',
                nonce: pnpmAdmin.nonce
            }).done(function (response) {
                if (response && response.success) {
                    $apiResult.addClass('pnpm-good').text(response.data.message);
                    return;
                }
                showFailure($apiResult, response);
            }).fail(function (xhr) {
                showFailure($apiResult, xhr.responseJSON);
            }).always(function () {
                $apiButton.prop('disabled', false);
            });
        });

        $sendersButton.on('click', function () {
            loadSenders(true);
        });

        $('.pnpm-mappings')
            .on('change', '.pnpm-sender-type', function () {
                var $row = $(this).closest('.pnpm-mapping-row');
                toggleHandoverControls($row);
                updateMappingStatus($row);
            })
            .on('change', '.pnpm-counterparty-select', function () {
                var $row = $(this).closest('.pnpm-mapping-row');
                var ref = String($(this).val() || '');
                $row.find('.pnpm-counterparty-ref').val(ref);
                populateSenderDetails($row, ref, '', '');
                clearHandoverPoint($row);
                updateMappingStatus($row);
            })
            .on('change', '.pnpm-address-select', function () {
                var $row = $(this).closest('.pnpm-mapping-row');
                applyAddress($row, String($(this).val() || ''));
                clearHandoverPoint($row);
                updateMappingStatus($row);
            })
            .on('change', '.pnpm-contact-select', function () {
                var $row = $(this).closest('.pnpm-mapping-row');
                applyContact($row, String($(this).val() || ''), true);
                updateMappingStatus($row);
            })
            .on('click', '.pnpm-search-handover', function () {
                searchHandoverPoints($(this).closest('.pnpm-mapping-row'));
            })
            .on('keydown', '.pnpm-handover-query', function (event) {
                if (event.key === 'Enter') {
                    event.preventDefault();
                    searchHandoverPoints($(this).closest('.pnpm-mapping-row'));
                }
            })
            .on('change', '.pnpm-handover-select', function () {
                var $row = $(this).closest('.pnpm-mapping-row');
                var ref = String($(this).val() || '');
                var label = ref ? String($(this).find('option:selected').text() || '') : '';
                $row.find('.pnpm-handover-warehouse-ref').val(ref);
                $row.find('.pnpm-handover-warehouse-label').val(label);
                updateMappingStatus($row);
            })
            .on('change input', '.pnpm-enabled, .pnpm-ref-input, .pnpm-phone, .pnpm-customer-label', function () {
                updateMappingStatus($(this).closest('.pnpm-mapping-row'));
            });

        function loadSenders(refresh) {
            $sendersButton.prop('disabled', true);
            $sendersResult.removeClass('pnpm-good pnpm-bad').text(pnpmAdmin.loadingSenders);

            $.post(pnpmAdmin.ajaxUrl, {
                action: 'pnpm_load_senders',
                nonce: pnpmAdmin.nonce,
                refresh: refresh ? 'yes' : 'no'
            }).done(function (response) {
                if (!response || !response.success || !response.data || !response.data.directory) {
                    showFailure($sendersResult, response, pnpmAdmin.sendersFailed);
                    return;
                }
                senderDirectory = response.data.directory;
                populateRows();
                $sendersResult.addClass('pnpm-good').text(response.data.message);
            }).fail(function (xhr) {
                showFailure($sendersResult, xhr.responseJSON, pnpmAdmin.sendersFailed);
            }).always(function () {
                $sendersButton.prop('disabled', false);
            });
        }

        function populateRows() {
            $('.pnpm-mapping-row').each(function () {
                var $row = $(this);
                var currentCounterparty = String($row.find('.pnpm-counterparty-ref').val() || '');
                var currentAddress = String($row.find('.pnpm-sender-address-ref').val() || '');
                var currentContact = String($row.find('.pnpm-contact-ref').val() || '');
                var counterparties = senderDirectory && Array.isArray(senderDirectory.counterparties)
                    ? senderDirectory.counterparties : [];

                if (!currentCounterparty && counterparties.length === 1) {
                    currentCounterparty = String(counterparties[0].ref || '');
                    $row.find('.pnpm-counterparty-ref').val(currentCounterparty);
                }

                fillSelect(
                    $row.find('.pnpm-counterparty-select'),
                    pnpmAdmin.chooseSender,
                    counterparties,
                    currentCounterparty,
                    function (item) {
                        return item.description + (item.cityDescription ? ' · ' + item.cityDescription : '');
                    }
                );
                populateSenderDetails($row, currentCounterparty, currentAddress, currentContact);
                restoreHandoverPoint($row);
                toggleHandoverControls($row);
                updateMappingStatus($row);
            });
        }

        function populateSenderDetails($row, counterpartyRef, currentAddress, currentContact) {
            var counterparty = findByRef(
                senderDirectory && Array.isArray(senderDirectory.counterparties)
                    ? senderDirectory.counterparties : [],
                counterpartyRef
            );
            var addresses = counterparty && Array.isArray(counterparty.addresses) ? counterparty.addresses : [];
            var contacts = counterparty && Array.isArray(counterparty.contacts) ? counterparty.contacts : [];

            fillSelect(
                $row.find('.pnpm-address-select'),
                pnpmAdmin.chooseAddress,
                addresses,
                currentAddress,
                function (item) {
                    return (item.cityDescription ? item.cityDescription + ' · ' : '') + item.description;
                }
            );
            fillSelect(
                $row.find('.pnpm-contact-select'),
                pnpmAdmin.chooseContact,
                contacts,
                currentContact,
                function (item) {
                    return item.description;
                }
            );
            applyAddress($row, currentAddress);
            applyContact($row, currentContact, false);
        }

        function applyAddress($row, ref) {
            var counterparty = currentCounterparty($row);
            var addresses = counterparty && Array.isArray(counterparty.addresses) ? counterparty.addresses : [];
            var address = findByRef(addresses, ref);

            $row.find('.pnpm-sender-address-ref').val(ref);
            $row.find('.pnpm-city-ref').val(address ? String(address.cityRef || '') : '');
            $row.find('.pnpm-city-label').text(
                address && address.cityDescription ? address.cityDescription : ''
            );
        }

        function applyContact($row, ref, replacePhone) {
            var counterparty = currentCounterparty($row);
            var contacts = counterparty && Array.isArray(counterparty.contacts) ? counterparty.contacts : [];
            var contact = findByRef(contacts, ref);
            var $phone = $row.find('.pnpm-phone');

            $row.find('.pnpm-contact-ref').val(ref);
            if (contact && contact.phones && (replacePhone || !$phone.val())) {
                var match = String(contact.phones).match(/[0-9]{10,12}/);
                if (match) {
                    $phone.val(match[0]);
                }
            }
        }

        function searchHandoverPoints($row) {
            var senderType = String($row.find('.pnpm-sender-type').val() || 'warehouse');
            if (senderType !== 'warehouse') {
                return;
            }

            var cityRef = $.trim(String($row.find('.pnpm-city-ref').val() || ''));
            var query = $.trim(String($row.find('.pnpm-handover-query').val() || ''));
            var $button = $row.find('.pnpm-search-handover');
            var $help = $row.find('.pnpm-handover-help');
            if (!cityRef || !query) {
                $help.removeClass('pnpm-good').addClass('pnpm-bad').text(pnpmAdmin.handoverQueryRequired);
                return;
            }

            $button.prop('disabled', true);
            $help.removeClass('pnpm-good pnpm-bad').text(pnpmAdmin.loadingHandoverPoints);
            $.post(pnpmAdmin.ajaxUrl, {
                action: 'pnpm_search_handover_points',
                nonce: pnpmAdmin.nonce,
                cityRef: cityRef,
                query: query
            }).done(function (response) {
                if (!response || !response.success || !response.data || !Array.isArray(response.data.points)) {
                    showFailure($help, response, pnpmAdmin.handoverPointsFailed);
                    return;
                }

                var points = response.data.points;
                var currentRef = String($row.find('.pnpm-handover-warehouse-ref').val() || '');
                var currentLabel = String($row.find('.pnpm-handover-warehouse-label').val() || '');
                if (currentRef && currentLabel && !findByRef(points, currentRef)) {
                    points.unshift({ref: currentRef, label: currentLabel});
                }
                fillSelect(
                    $row.find('.pnpm-handover-select'),
                    pnpmAdmin.chooseHandoverPoint,
                    points,
                    currentRef,
                    function (item) {
                        return item.label;
                    }
                );
                $help.toggleClass('pnpm-good', points.length > 0)
                    .toggleClass('pnpm-bad', points.length === 0)
                    .text(points.length > 0 ? response.data.message : pnpmAdmin.handoverPointsEmpty);
            }).fail(function (xhr) {
                showFailure($help, xhr.responseJSON, pnpmAdmin.handoverPointsFailed);
            }).always(function () {
                $button.prop('disabled', false);
            });
        }

        function clearHandoverPoint($row) {
            $row.find('.pnpm-handover-warehouse-ref, .pnpm-handover-warehouse-label').val('');
            $row.find('.pnpm-handover-select').empty().append($('<option>', {
                value: '',
                text: pnpmAdmin.chooseHandoverPoint
            }));
            $row.find('.pnpm-handover-help').removeClass('pnpm-good pnpm-bad').text('');
        }

        function restoreHandoverPoint($row) {
            var ref = String($row.find('.pnpm-handover-warehouse-ref').val() || '');
            var label = String($row.find('.pnpm-handover-warehouse-label').val() || '');
            var $select = $row.find('.pnpm-handover-select');
            $select.empty().append($('<option>', {
                value: ref,
                text: ref && label ? label : pnpmAdmin.chooseHandoverPoint
            })).val(ref);
        }

        function toggleHandoverControls($row) {
            var required = String($row.find('.pnpm-sender-type').val() || 'warehouse') === 'warehouse';
            $row.find('.pnpm-handover-query, .pnpm-search-handover, .pnpm-handover-select')
                .prop('disabled', !required);
            var $help = $row.find('.pnpm-handover-help');
            if (!required) {
                $help.removeClass('pnpm-good pnpm-bad').text(pnpmAdmin.handoverNotRequired);
            } else if ($help.text() === pnpmAdmin.handoverNotRequired) {
                $help.text('');
            }
        }

        function currentCounterparty($row) {
            var ref = String($row.find('.pnpm-counterparty-ref').val() || '');
            return findByRef(
                senderDirectory && Array.isArray(senderDirectory.counterparties)
                    ? senderDirectory.counterparties : [],
                ref
            );
        }

        function fillSelect($select, placeholder, items, selectedRef, labelCallback) {
            $select.empty().append($('<option>', {value: '', text: placeholder}));
            items.forEach(function (item) {
                $select.append($('<option>', {
                    value: String(item.ref || ''),
                    text: labelCallback(item)
                }));
            });
            if (selectedRef && !findByRef(items, selectedRef)) {
                $select.append($('<option>', {
                    value: selectedRef,
                    text: pnpmAdmin.savedRef + ': ' + selectedRef
                }));
            }
            $select.val(selectedRef || '');
        }

        function findByRef(items, ref) {
            var normalized = String(ref || '');
            for (var index = 0; index < items.length; index += 1) {
                if (String(items[index].ref || '') === normalized) {
                    return items[index];
                }
            }
            return null;
        }

        function updateMappingStatus($row) {
            var enabled = $row.find('.pnpm-enabled').prop('checked');
            var required = [
                '.pnpm-counterparty-ref',
                '.pnpm-city-ref',
                '.pnpm-sender-address-ref',
                '.pnpm-contact-ref',
                '.pnpm-phone',
                '.pnpm-customer-label'
            ];
            if (String($row.find('.pnpm-sender-type').val() || 'warehouse') === 'warehouse') {
                required.push('.pnpm-handover-warehouse-ref');
            }
            var ready = enabled && required.every(function (selector) {
                return $.trim(String($row.find(selector).val() || '')) !== '';
            });
            var $status = $row.find('.pnpm-mapping-status');

            $status.toggleClass('pnpm-good', !enabled || ready).toggleClass('pnpm-bad', enabled && !ready);
            $status.text(!enabled
                ? pnpmAdmin.mappingDisabled
                : (ready ? pnpmAdmin.mappingReady : pnpmAdmin.mappingIncomplete));
        }

        function showFailure($target, response, fallback) {
            var message = response && response.data && response.data.message
                ? response.data.message
                : (fallback || pnpmAdmin.failed);
            $target.addClass('pnpm-bad').text(message);
        }

        if ($sendersButton.length) {
            $('.pnpm-mapping-row').each(function () {
                restoreHandoverPoint($(this));
                toggleHandoverControls($(this));
                updateMappingStatus($(this));
            });
            loadSenders(false);
        }
    });
}(jQuery));
