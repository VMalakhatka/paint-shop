<?php

defined('ABSPATH') || exit;

function pc_folio_bacs_settings_url(): string {
    return admin_url('admin.php?page=wc-settings&tab=checkout&section=bacs');
}

// Woo's React account modal omits tax ID and strips our confirmation metadata.
// Use the complete gateway form, including when Woo builds its Manage links.
add_filter('experimental_woocommerce_admin_payment_reactify_render_sections', static function ($sections): array {
    return is_array($sections) ? array_values(array_filter($sections, static fn($section) => $section !== 'bacs')) : [];
}, PHP_INT_MAX);

/** Old bookmarks may still address the React router instead of section=bacs. */
function pc_folio_bacs_redirect_incomplete_editor(): void {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET' || !current_user_can('manage_woocommerce')) return;
    if (($_GET['page'] ?? '') !== 'wc-settings' || ($_GET['tab'] ?? '') !== 'checkout') return;
    $path = $_GET['path'] ?? '';
    if (!is_string($path) || rtrim(wp_unslash($path), '/') !== '/offline/bacs') return;
    wp_safe_redirect(pc_folio_bacs_settings_url());
    exit;
}
add_action('admin_init', 'pc_folio_bacs_redirect_incomplete_editor');

/** A previously opened React tab must not bypass the guarded full-form save. */
function pc_folio_bacs_guard_incomplete_rest_save($result, WP_REST_Request $request) {
    if ($result !== null || !in_array($request->get_method(), ['POST', 'PUT', 'PATCH'], true)) return $result;
    $route = rtrim($request->get_route(), '/');
    if ($route === '/wc/v4/settings/payment-gateways/bacs') {
        $values = $request->get_param('values');
        if (!is_array($values) || !array_key_exists('account_details', $values)) return $result;
    } elseif ($route === '/wc-admin/options') {
        // Older Woo account editors write the option directly through this API.
        $values = $request->get_json_params();
        if (!is_array($values) || !array_key_exists('woocommerce_bacs_accounts', $values)) return $result;
    } else {
        return $result;
    }
    return new WP_Error('pc_folio_bacs_full_form_required', sprintf(
        /* translators: %s: URL of the complete WooCommerce bank transfer settings form. */
        __('This bank account editor does not include all recipient fields. Open the complete bank transfer settings, verify the details and save there: %s', 'pc-folio-customer-balance'),
        pc_folio_bacs_settings_url()
    ), ['status' => 409]);
}
// This hook runs after the endpoint's permission check and before any writes.
add_filter('rest_dispatch_request', 'pc_folio_bacs_guard_incomplete_rest_save', 10, 2);

function pc_folio_bacs_accounts_revision($accounts): string {
    return hash('sha256', wp_json_encode($accounts));
}

/** Sanitize one submitted account; confirmation signs all destination fields. */
function pc_folio_bacs_prepare_account(array $posted, bool $confirmed) {
    foreach (['account_name', 'account_number', 'bank_name', 'sort_code', 'iban', 'bic', 'tax_id'] as $key) {
        if (!array_key_exists($key, $posted) || !is_scalar($posted[$key])) {
            return new WP_Error('bacs_fields', __('The bank account form is incomplete. Reload the settings page and try again.', 'pc-folio-customer-balance'));
        }
        $posted[$key] = sanitize_text_field((string) $posted[$key]);
    }
    $account = pc_folio_bacs_normalize_account($posted);
    if ($confirmed) {
        $valid = pc_folio_invoice_validate_settings([
            'name' => $account['account_name'], 'tax_id' => $account['tax_id'],
            'iban' => $account['iban'], 'bank' => $account['bank_name'],
            'mfo' => $account['sort_code'], 'confirmed' => true,
        ]);
        if (is_wp_error($valid)) return $valid;
    }
    $account['pc_folio_confirmed_hash'] = $confirmed ? pc_folio_bacs_confirmation_hash($account) : '';
    return $account;
}

function pc_folio_bacs_save_error(string $code, string $message) {
    if (class_exists('WC_Admin_Settings')) WC_Admin_Settings::add_error($message);
    return new WP_Error($code, $message);
}

function pc_folio_bacs_save_account() {
    pc_folio_bacs_remove_native_output();
    if (empty($_POST['pc_folio_bank_details_present'])) return null;
    if (!current_user_can('manage_options')) {
        return pc_folio_bacs_save_error('bacs_permission', __('Only an administrator can change payment recipient details.', 'pc-folio-customer-balance'));
    }
    foreach (['_wpnonce' => 'woocommerce-settings', '_pc_folio_bank_nonce' => 'pc_folio_bacs_recipient'] as $field => $action) {
        $nonce = $_POST[$field] ?? '';
        if (!is_string($nonce) || !wp_verify_nonce(wp_unslash($nonce), $action)) {
            return pc_folio_bacs_save_error('bacs_nonce', __('The bank account form has expired. Reload the settings page and try again.', 'pc-folio-customer-balance'));
        }
    }
    global $wpdb;
    $lock = pc_folio_bacs_lock_name();
    if ((string) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', $lock)) !== '1') {
        return pc_folio_bacs_save_error('bacs_busy', __('Payment recipient settings are being saved by another administrator. Wait a moment, then reload the page before trying again.', 'pc-folio-customer-balance'));
    }
    try {
        // Options can already have been loaded before the lock was acquired.
        wp_cache_delete('alloptions', 'options');
        wp_cache_delete('notoptions', 'options');
        wp_cache_delete('woocommerce_bacs_accounts', 'options');
        wp_cache_delete('pc_folio_invoice_settings', 'options');
        return pc_folio_bacs_save_locked_account();
    } finally {
        $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
    }
}

/** Called only after capability, both nonces and the configuration lock. */
function pc_folio_bacs_save_locked_account() {
    $accounts = get_option('woocommerce_bacs_accounts', []);
    $revision = $_POST['pc_folio_bank_revision'] ?? '';
    if (!is_string($revision) || !hash_equals(pc_folio_bacs_accounts_revision($accounts), wp_unslash($revision))) {
        return pc_folio_bacs_save_error('bacs_changed', __('Payment recipient details changed after you opened this page. Reload it and review the current account before saving.', 'pc-folio-customer-balance'));
    }
    $posted = $_POST['pc_folio_bank_account'] ?? null;
    $confirmed = ($_POST['pc_folio_bank_confirmed'] ?? '') === '1';
    $account = is_array($posted) ? pc_folio_bacs_prepare_account(wp_unslash($posted), $confirmed) : new WP_Error('bacs_fields', __('The bank account form is incomplete. Reload the settings page and try again.', 'pc-folio-customer-balance'));
    if (is_wp_error($account)) return pc_folio_bacs_save_error($account->get_error_code(), $account->get_error_message());

    // Saving an unrelated BACS setting must not revoke an unchanged verified
    // recipient. Changed financial fields always need a fresh explicit check.
    if (!$confirmed && is_array($accounts) && count($accounts) === 1) {
        $previous = reset($accounts);
        if (is_array($previous) && pc_folio_bacs_normalize_account($previous) === pc_folio_bacs_normalize_account($account)
            && !empty(pc_folio_bacs_source_settings()['confirmed'])) {
            $account['pc_folio_confirmed_hash'] = $previous['pc_folio_confirmed_hash'];
        }
    }
    update_option('woocommerce_bacs_accounts', [$account]);
    if (get_option('woocommerce_bacs_accounts', []) !== [$account]) {
        return pc_folio_bacs_save_error('bacs_save', __('Payment recipient details could not be saved. Reload the page and check the current account.', 'pc-folio-customer-balance'));
    }
    if ($account['pc_folio_confirmed_hash'] !== '') {
        // Cleanup is part of this explicit successful administrator save, never
        // a read/startup migration. Preserve logo and unrelated appearance keys.
        $legacy = (array) get_option('pc_folio_invoice_settings', []);
        foreach (['name', 'tax_id', 'iban', 'bank', 'mfo', 'confirmed'] as $key) unset($legacy[$key]);
        update_option('pc_folio_invoice_settings', $legacy, false);
    }
    return $account;
}
add_action('woocommerce_update_options_payment_gateways_bacs', 'pc_folio_bacs_save_account', 5);

add_filter('woocommerce_settings_api_form_fields_bacs', static function (array $fields): array {
    $fields['account_details'] = ['type' => 'pc_folio_bank_account', 'default' => ''];
    unset($fields['instructions'], $fields['description']);
    return $fields;
});
add_filter('woocommerce_settings_api_sanitized_fields_bacs', static function (array $fields): array {
    foreach (['account_details', 'account_name', 'account_number', 'bank_name', 'sort_code', 'iban', 'bic', 'tax_id'] as $key) unset($fields[$key]);
    return $fields;
});

function pc_folio_bacs_account_field_html($html, $key, $data, $gateway): string {
    if (!$gateway instanceof WC_Gateway_BACS) return (string) $html;
    $accounts = get_option('woocommerce_bacs_accounts', []);
    $account = is_array($accounts) && count($accounts) === 1 ? reset($accounts) : [];
    $fields = is_array($account) ? pc_folio_bacs_normalize_account($account) : [];
    $valid = (bool) pc_folio_payment_details();
    $suggested = false;
    if (is_array($accounts) && !$valid) {
        $candidate = pc_folio_bacs_legacy_candidate($accounts, (array) get_option('pc_folio_invoice_settings', []));
        if ($candidate && empty($fields['tax_id'])) {
            $fields['tax_id'] = $candidate['tax_id'];
            $suggested = true;
        }
    }
    ob_start();
    echo '<tr><th>' . esc_html__('Payment recipient', 'pc-folio-customer-balance') . '</th><td>';
    echo '<p>' . esc_html__('This single verified WooCommerce bank account is used for bank transfers, Folio invoices, customer emails and the payment details on the contacts page.', 'pc-folio-customer-balance') . '</p>';
    if (!current_user_can('manage_options')) {
        echo '<p>' . esc_html__('Only an administrator can change payment recipient details.', 'pc-folio-customer-balance') . '</p></td></tr>';
        return (string) ob_get_clean();
    }
    if (!$valid) echo '<p class="description">' . esc_html__('Confirm the intended recipient here before customers can see bank details. Previous invoice settings are not used for payments.', 'pc-folio-customer-balance') . '</p>';
    if (is_array($accounts) && count($accounts) > 1) echo '<p>' . esc_html__('Several bank accounts are stored. Enter and confirm the one payment recipient to use; saving this form replaces the account list with that single account.', 'pc-folio-customer-balance') . '</p>';
    if ($suggested) echo '<p>' . esc_html__('The tax ID below is suggested from the earlier invoice settings because the bank account matches exactly. Verify it and confirm the recipient before saving.', 'pc-folio-customer-balance') . '</p>';
    echo '<input type="hidden" name="pc_folio_bank_details_present" value="1"><input type="hidden" name="pc_folio_bank_revision" value="' . esc_attr(pc_folio_bacs_accounts_revision($accounts)) . '">';
    echo wp_nonce_field('pc_folio_bacs_recipient', '_pc_folio_bank_nonce', false, false);
    $labels = [
        'account_name' => __('Supplier / payment recipient', 'pc-folio-customer-balance'),
        'tax_id' => __('Tax ID', 'pc-folio-customer-balance'),
        'iban' => __('IBAN', 'pc-folio-customer-balance'),
        'bank_name' => __('Bank', 'pc-folio-customer-balance'),
        'sort_code' => __('Bank code (MFO)', 'pc-folio-customer-balance'),
        'account_number' => __('Account number', 'woocommerce'),
        'bic' => __('BIC / Swift', 'woocommerce'),
    ];
    foreach ($labels as $field => $label) {
        echo '<p><label for="pc-folio-bank-' . esc_attr($field) . '">' . esc_html($label) . '<br><input class="regular-text" type="text" id="pc-folio-bank-' . esc_attr($field) . '" name="pc_folio_bank_account[' . esc_attr($field) . ']" value="' . esc_attr((string) ($fields[$field] ?? '')) . '"></label></p>';
    }
    echo '<p><label><input type="checkbox" name="pc_folio_bank_confirmed" value="1"> ' . esc_html__('I have verified that the recipient name, tax ID and bank account belong to the intended payment recipient.', 'pc-folio-customer-balance') . '</label></p>';
    echo '<p class="description">' . esc_html__('When recipient details change, confirm them again before saving. Saving unchanged details keeps their existing verification.', 'pc-folio-customer-balance') . '</p>';
    echo '</td></tr>';
    return (string) ob_get_clean();
}
add_filter('woocommerce_generate_pc_folio_bank_account_html', 'pc_folio_bacs_account_field_html', 10, 4);
