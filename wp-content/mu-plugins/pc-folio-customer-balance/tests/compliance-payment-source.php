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
foreach ($ids as $key => $id) {
    $check(get_post_field('post_content', (int) $id, 'raw') === $before[$key], 'Generating the template must not rewrite a stored legal page');
}
echo "PASS: $checks compliance payment-source checks; stored pages unchanged\n";
