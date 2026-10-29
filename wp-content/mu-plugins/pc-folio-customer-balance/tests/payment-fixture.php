<?php
/** Synthetic test adapter. Deliberate config changes are signed; stale signatures are tested separately. */
defined('ABSPATH') || exit;
if (!defined('WP_CLI') || !WP_CLI || wp_parse_url(home_url(), PHP_URL_HOST) !== 'paint.local') throw new RuntimeException('Local fixture only.');
function pc_payment_fixture_account(array $settings): array {
    $row = [
        'account_name' => $settings['name'] ?? '', 'account_number' => '',
        'bank_name' => $settings['bank'] ?? '', 'sort_code' => $settings['mfo'] ?? '',
        'iban' => $settings['iban'] ?? '', 'bic' => '', 'tax_id' => $settings['tax_id'] ?? '',
        'pc_folio_confirmed_hash' => '',
    ];
    if (in_array($settings['confirmed'] ?? false, [true, 1, '1'], true)) {
        $row['pc_folio_confirmed_hash'] = pc_folio_bacs_confirmation_hash($row);
    }
    return $row;
}
