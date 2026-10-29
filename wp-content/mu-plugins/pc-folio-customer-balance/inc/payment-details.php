<?php

defined('ABSPATH') || exit;

/**
 * Bank transfer and payment invoices share one explicitly confirmed recipient.
 * Never fall back to the independent Woo BACS option or legacy gateway fields.
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

// The option filter also bypasses Woo's early empty-account guard and its legacy
// option fallback. The runtime filter refreshes a gateway instantiated earlier.
add_filter('pre_option_woocommerce_bacs_accounts', 'pc_folio_bacs_accounts', PHP_INT_MAX);
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

function pc_folio_payment_details_admin_notice(): void {
    if (!current_user_can('manage_woocommerce')) return;
    if (($_GET['page'] ?? '') !== 'wc-settings' || ($_GET['tab'] ?? '') !== 'checkout' || ($_GET['section'] ?? '') !== 'bacs') return;
    $valid = (bool) pc_folio_payment_details();
    echo '<div class="notice notice-' . ($valid ? 'info' : 'error') . '"><p>';
    echo esc_html__('Bank transfers and Folio payment invoices use the confirmed details in Lavka: payment invoices. The separate bank account and free-text instructions on this WooCommerce screen are not used.', 'pc-folio-customer-balance') . ' ';
    echo '<a href="' . esc_url(admin_url('admin.php?page=pc-folio-invoices')) . '">' . esc_html__('Open payment recipient settings', 'pc-folio-customer-balance') . '</a>';
    if (!$valid) echo ' ' . esc_html(pc_folio_bacs_unavailable_message());
    echo '</p></div>';
}
add_action('admin_notices', 'pc_folio_payment_details_admin_notice');
