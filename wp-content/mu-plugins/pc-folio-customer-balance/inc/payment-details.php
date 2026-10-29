<?php

defined('ABSPATH') || exit;

require_once __DIR__ . '/bacs-recipient.php';

function pc_folio_bacs_source_settings(): array {
    $empty = ['name' => '', 'tax_id' => '', 'iban' => '', 'bank' => '', 'mfo' => '', 'confirmed' => false];
    $accounts = get_option('woocommerce_bacs_accounts', []);
    if (!is_array($accounts) || count($accounts) !== 1) return $empty;
    $account = reset($accounts);
    if (!is_array($account)) return $empty;
    $fields = pc_folio_bacs_normalize_account($account);
    if (!$fields) return $empty;
    $proof = $account['pc_folio_confirmed_hash'] ?? '';
    return [
        'name' => $fields['account_name'], 'tax_id' => $fields['tax_id'],
        'iban' => $fields['iban'], 'bank' => $fields['bank_name'], 'mfo' => $fields['sort_code'],
        'confirmed' => is_string($proof) && $proof !== '' && hash_equals(pc_folio_bacs_confirmation_hash($account), $proof),
    ];
}

/**
 * Bank transfer and payment invoices share one explicitly confirmed recipient.
 * Woo's one verified bank account is authoritative; legacy invoice fields and
 * deprecated single-account gateway settings are never an alternative source.
 */
function pc_folio_payment_details(): array {
    $settings = pc_folio_invoice_settings();
    if (is_wp_error(pc_folio_invoice_validate_settings($settings))) return [];
    foreach (['name', 'tax_id', 'bank', 'mfo'] as $key) $settings[$key] = (string) ($settings[$key] ?? '');
    $settings['iban'] = strtoupper(preg_replace('/\s+/', '', (string) $settings['iban']));
    return $settings;
}

function pc_folio_bacs_accounts(): array {
    $settings = pc_folio_payment_details();
    if (!$settings) return [];
    return [[
        'account_name' => $settings['name'],
        'account_number' => '',
        'bank_name' => $settings['bank'],
        'sort_code' => $settings['mfo'],
        'iban' => $settings['iban'],
        'bic' => '',
    ]];
}

// Do not filter the stored option: Woo is now the editor and source. Rendering
// still validates fresh data, even if a gateway was instantiated before a change.
add_filter('woocommerce_bacs_accounts', 'pc_folio_bacs_accounts', PHP_INT_MAX);

function pc_folio_bacs_instruction(): string {
    return __('Pay using the recipient details below and include your order number in the payment reference. Check the recipient name before confirming the transfer.', 'pc-folio-customer-balance');
}

function pc_folio_bacs_unavailable_message(): string {
    return __('Bank details are awaiting verification. Please contact your manager before paying. Do not use bank details from an earlier email or invoice.', 'pc-folio-customer-balance');
}

function pc_folio_bacs_checkout_description(): string {
    return pc_folio_payment_details()
        ? __('Verified bank details will be shown after placing the order and in the order email. Include your order number in the payment reference.', 'pc-folio-customer-balance')
        : pc_folio_bacs_unavailable_message();
}

function pc_folio_bacs_settings($settings) {
    if (!is_array($settings)) return $settings;
    $accounts = pc_folio_bacs_accounts();
    // Blocks reads this option directly. Also replace the deprecated single
    // account fields so no consumer can revive an old payment destination.
    foreach (['account_name', 'account_number', 'bank_name', 'sort_code', 'iban', 'bic'] as $key) {
        $settings[$key] = $accounts[0][$key] ?? '';
    }
    $settings['description'] = pc_folio_bacs_checkout_description();
    $settings['instructions'] = $accounts ? pc_folio_bacs_instruction() : pc_folio_bacs_unavailable_message();
    return $settings;
}
add_filter('option_woocommerce_bacs_settings', 'pc_folio_bacs_settings', PHP_INT_MAX);

add_filter('woocommerce_gateway_description', static function ($description, $gateway_id) {
    if ($gateway_id !== 'bacs') return $description;
    // Historical free-text instructions can contain another account as well.
    return esc_html(pc_folio_bacs_checkout_description());
}, PHP_INT_MAX, 2);

/** Replace only the native renderer; keep BACS enabled/status/payment logic. */
function pc_folio_bacs_remove_native_output($registry = null): void {
    if ($registry === null) {
        if (!function_exists('WC')) return;
        $registry = WC()->payment_gateways();
    }
    foreach ($registry->payment_gateways() as $gateway) {
        if (!$gateway instanceof WC_Gateway_BACS) continue;
        remove_action('woocommerce_email_before_order_table', [$gateway, 'email_instructions'], 10);
        remove_action('woocommerce_thankyou_bacs', [$gateway, 'thankyou_page'], 10);
        // The native table saver would discard tax ID and confirmation proof.
        remove_action('woocommerce_update_options_payment_gateways_bacs', [$gateway, 'save_account_details'], 10);
    }
}
add_action('wc_payment_gateways_initialized', 'pc_folio_bacs_remove_native_output', PHP_INT_MAX);

function pc_folio_bacs_render_details(bool $plain_text = false, bool $with_instruction = true): void {
    $settings = pc_folio_payment_details();
    if (!$settings) {
        $message = pc_folio_bacs_unavailable_message();
        echo $plain_text ? $message . "\n\n" : '<p class="woocommerce-info pc-payment-details-unavailable">' . esc_html($message) . '</p>';
        return;
    }
    $fields = [
        __('Supplier / payment recipient', 'pc-folio-customer-balance') => $settings['name'],
        __('Tax ID', 'pc-folio-customer-balance') => $settings['tax_id'],
        __('IBAN', 'pc-folio-customer-balance') => $settings['iban'],
        __('Bank', 'pc-folio-customer-balance') => $settings['bank'],
    ];
    if ($settings['mfo'] !== '') $fields[__('Bank code (MFO)', 'pc-folio-customer-balance')] = $settings['mfo'];
    if ($plain_text) {
        if ($with_instruction) echo pc_folio_bacs_instruction() . "\n\n";
        foreach ($fields as $label => $value) echo $label . ': ' . wp_strip_all_tags((string) $value) . "\n";
        echo "\n";
        return;
    }
    echo '<section class="woocommerce-bacs-bank-details">';
    if ($with_instruction) echo '<p>' . esc_html(pc_folio_bacs_instruction()) . '</p>';
    echo '<ul class="wc-bacs-bank-details order_details bacs_details">';
    foreach ($fields as $label => $value) echo '<li>' . esc_html($label) . ': <strong>' . esc_html((string) $value) . '</strong></li>';
    echo '</ul></section>';
}

function pc_folio_payment_details_shortcode(): string {
    ob_start();
    pc_folio_bacs_render_details(false, false);
    return (string) ob_get_clean();
}
add_shortcode('pc_folio_payment_details', 'pc_folio_payment_details_shortcode');

function pc_folio_bacs_email_instructions($order, $sent_to_admin, $plain_text = false): void {
    pc_folio_bacs_remove_native_output();
    if ($sent_to_admin || !$order || $order->get_payment_method() !== 'bacs') return;
    $status = apply_filters('woocommerce_bacs_email_instructions_order_status', 'on-hold', $order);
    if ($order->has_status($status)) pc_folio_bacs_render_details((bool) $plain_text);
}
add_action('woocommerce_email_before_order_table', 'pc_folio_bacs_email_instructions', 9, 3);

function pc_folio_bacs_thankyou($order_id): void {
    pc_folio_bacs_remove_native_output();
    $order = wc_get_order($order_id);
    if ($order && $order->get_payment_method() === 'bacs') pc_folio_bacs_render_details();
}
add_action('woocommerce_thankyou_bacs', 'pc_folio_bacs_thankyou', 9);

require_once __DIR__ . '/bacs-settings.php';
