<?php

defined('ABSPATH') || exit;

/** Pure shared contract, also usable by an explicitly approved migration tool. */
function pc_folio_bacs_normalize_account(array $account): array {
    $fields = [];
    foreach (['account_name', 'account_number', 'bank_name', 'sort_code', 'iban', 'bic', 'tax_id'] as $key) {
        if (array_key_exists($key, $account) && !is_scalar($account[$key])) return [];
        $fields[$key] = trim((string) ($account[$key] ?? ''));
    }
    $fields['iban'] = strtoupper(preg_replace('/\s+/', '', $fields['iban']));
    return $fields;
}

function pc_folio_bacs_confirmation_hash(array $account): string {
    $fields = pc_folio_bacs_normalize_account($account);
    if (!$fields) return '';
    $payload = wp_json_encode(['version' => 1, 'fields' => $fields]);
    return is_string($payload) ? 'v1:' . hash_hmac('sha256', $payload, wp_salt('auth')) : '';
}

/** Shared by the explicit editor save and the separately approved migration. */
function pc_folio_bacs_lock_name(): string {
    global $wpdb;
    return 'pc-folio-bacs-' . substr(hash('sha256', DB_NAME . ':' . $wpdb->options), 0, 40);
}

/** UI suggestion / explicit migration candidate only; never used for payments. */
function pc_folio_bacs_legacy_candidate(array $accounts, array $legacy): array {
    if (count($accounts) !== 1 || !in_array($legacy['confirmed'] ?? false, [true, 1, '1'], true)) return [];
    foreach (['name', 'tax_id', 'iban', 'bank', 'mfo'] as $key) {
        if (isset($legacy[$key]) && !is_scalar($legacy[$key])) return [];
    }
    if (is_wp_error(pc_folio_invoice_validate_settings($legacy))) return [];
    $account = reset($accounts);
    if (!is_array($account)) return [];
    $fields = pc_folio_bacs_normalize_account($account);
    if (!$fields || $fields['account_number'] !== '' || $fields['bic'] !== '') return [];
    $expected = pc_folio_bacs_normalize_account([
        'account_name' => $legacy['name'], 'bank_name' => $legacy['bank'],
        'iban' => $legacy['iban'], 'sort_code' => $legacy['mfo'] ?? '', 'tax_id' => $legacy['tax_id'],
    ]);
    foreach (['account_name', 'bank_name', 'iban', 'sort_code'] as $key) {
        if ($fields[$key] !== $expected[$key]) return [];
    }
    if ($fields['tax_id'] !== '' && $fields['tax_id'] !== $expected['tax_id']) return [];
    $account['tax_id'] = $expected['tax_id'];
    $account['pc_folio_confirmed_hash'] = pc_folio_bacs_confirmation_hash($account);
    return $account['pc_folio_confirmed_hash'] !== '' ? $account : [];
}
