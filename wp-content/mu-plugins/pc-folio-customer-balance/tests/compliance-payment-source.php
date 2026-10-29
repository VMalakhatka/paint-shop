<?php
/** Read-only local checks: generated contacts reference the canonical renderer, never a frozen bank account. */
if (!defined('WP_CLI') || !WP_CLI || wp_parse_url(home_url(), PHP_URL_HOST) !== 'paint.local') {
    throw new RuntimeException('Local only');
}
$checks = 0;
$check = static function ($condition, string $message) use (&$checks): void {
    if (!$condition) throw new RuntimeException($message);
    $checks++;
};
$check(function_exists('pc_wayforpay_page_contents'), 'Compliance template must be loaded');
$check(PC_WAYFORPAY_CONTENT_VERSION === '2026-08-15-5', 'This payment repair must not trigger a rewrite of all legal pages');
$seller = pc_wayforpay_seller();
$check(!array_key_exists('iban', $seller) && !array_key_exists('bank', $seller), 'Seller profile must not provide a second bank-account source');
$ids = (array) get_option(PC_WAYFORPAY_PAGES_OPTION, []);
$before = [];
foreach ($ids as $key => $id) $before[$key] = get_post_field('post_content', (int) $id, 'raw');
$urls = array_map(static fn($row) => home_url('/' . $row['slug'] . '/'), pc_wayforpay_page_definitions());
$contents = pc_wayforpay_page_contents($urls);
$check(substr_count($contents['contacts'], '[pc_folio_payment_details]') === 1, 'Generated contacts must contain one canonical payment-details shortcode');
$check(!preg_match('/UA\s*\d(?:[\d\s]){26,}/', $contents['contacts']), 'Generated contacts must not freeze any bank account into page content');
$check(str_contains($contents['contacts'], 'pc-seller-details'), 'Legal seller identity remains present');
foreach (['terms', 'payment_delivery', 'refund'] as $key) {
    $check(!empty($contents[$key]) && !str_contains($contents[$key], '[pc_folio_payment_details]'), 'Payment renderer belongs only in the contacts template');
}
require_once __DIR__ . '/payment-fixture.php';
$original_post = $GLOBALS['post'] ?? null;
$account = pc_payment_fixture_account(['name' => 'Current synthetic recipient', 'tax_id' => '0000000000',
    'iban' => 'UA89' . str_repeat('0', 25), 'bank' => 'Current synthetic bank', 'confirmed' => true]);
$account_filter = static function () use (&$account): array { return [$account]; };
$deny_http = static fn() => new WP_Error('local_fixture', 'External requests are blocked');
$deny_mail = static fn() => false;
add_filter('pre_option_woocommerce_bacs_accounts', $account_filter, PHP_INT_MAX);
add_filter('pre_http_request', $deny_http, PHP_INT_MAX);
add_filter('pre_wp_mail', $deny_mail, PHP_INT_MAX);
try {
    $GLOBALS['post'] = get_post((int) ($ids['contacts'] ?? 0));
    $check($GLOBALS['post'] instanceof WP_Post && (bool) get_post_meta($GLOBALS['post']->ID, '_pc_wayforpay_managed', true), 'Test the actual contacts identity from the managed-page mapping');
    $old_rows = '<div><dt>Поточний рахунок (IBAN)</dt><dd><code>OBSOLETE-TEST-ACCOUNT</code></dd></div>' . "\n"
        . '<div><dt>Банк</dt><dd>Historical synthetic bank</dd></div>';
    $legacy = str_replace('[pc_folio_payment_details]', '', $contents['contacts']);
    $legacy = str_replace('</dl>', $old_rows . "\n</dl>", $legacy);
    $dynamic = pc_wayforpay_contacts_payment_source($legacy);
    $check(substr_count($dynamic, '[pc_folio_payment_details]') === 1 && !str_contains($dynamic, 'OBSOLETE-TEST-ACCOUNT') && !str_contains($dynamic, 'Historical synthetic bank'), 'Known legacy rows are replaced with one canonical renderer');
    $check(strpos($dynamic, '</dl>') < strpos($dynamic, '[pc_folio_payment_details]'), 'Payment renderer is outside the seller definition list');
    $check(str_replace($old_rows, '', $legacy) === str_replace("\n[pc_folio_payment_details]", '', $dynamic), 'Only the bank rows and shortcode change; legal identity and contact content are preserved');
    $html = apply_filters('the_content', $legacy);
    $check(str_contains($html, 'Current synthetic bank') && !str_contains($html, 'Historical synthetic bank') && !str_contains($html, 'OBSOLETE-TEST-ACCOUNT'), 'Full content rendering reads current Woo details without a stale account');
    $account['bank_name'] = 'Updated synthetic bank';$account['pc_folio_confirmed_hash'] = pc_folio_bacs_confirmation_hash($account);
    $html = apply_filters('the_content', $legacy);
    $check(str_contains($html, 'Updated synthetic bank') && !str_contains($html, 'Current synthetic bank'), 'Next rendering reflects a verified Woo change without editing the stored page');
    $account['pc_folio_confirmed_hash'] = '';
    $html = apply_filters('the_content', $legacy);
    $check(str_contains($html, 'pc-payment-details-unavailable') && !str_contains($html, 'OBSOLETE-TEST-ACCOUNT') && !str_contains($html, 'Updated synthetic bank'), 'Unverified Woo data shows a warning and never restores the historical account');
    $check(pc_wayforpay_contacts_payment_source($contents['contacts']) === $contents['contacts'], 'New shortcode template is unchanged');
    $mixed = $legacy . "\n[pc_folio_payment_details]";
    $check(substr_count(pc_wayforpay_contacts_payment_source($mixed), '[pc_folio_payment_details]') === 1, 'An existing payment shortcode is not duplicated');
    $custom = str_replace('<dt>Банк</dt>', '<dt>Мій банк</dt>', $legacy);
    $check(pc_wayforpay_contacts_payment_source($custom) === $custom, 'Unknown customized markup is left for explicit review');
    $GLOBALS['post'] = get_post((int) ($ids['terms'] ?? 0));
    $check(pc_wayforpay_contacts_payment_source($legacy) === $legacy, 'Other managed legal pages are not modified');
} finally {
    $GLOBALS['post'] = $original_post;
    remove_filter('pre_option_woocommerce_bacs_accounts', $account_filter, PHP_INT_MAX);
    remove_filter('pre_http_request', $deny_http, PHP_INT_MAX);
    remove_filter('pre_wp_mail', $deny_mail, PHP_INT_MAX);
}
foreach ($ids as $key => $id) {
    $check(get_post_field('post_content', (int) $id, 'raw') === $before[$key], 'Generating the template must not rewrite a stored legal page');
}
echo "PASS: $checks compliance payment-source checks; stored pages unchanged\n";
