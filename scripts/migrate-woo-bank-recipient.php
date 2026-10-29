<?php
/**
 * Optional operator migration BEFORE deployment; never loaded by WordPress.
 * wp eval-file scripts/migrate-woo-bank-recipient.php preview
 * PC_FOLIO_PAYMENT_BACKUP_DIR=/private/operator/path wp eval-file ... apply PREVIEW_HASH
 * Explicit apply authorization is required. No bank destination fields are changed.
 */
if (!defined('WP_CLI') || !WP_CLI) throw new RuntimeException('WP-CLI is required.');
require_once __DIR__ . '/../wp-content/mu-plugins/pc-folio-customer-balance/inc/bacs-recipient.php';
if (!function_exists('pc_folio_invoice_validate_settings')) WP_CLI::error('Load the invoice plugin before running this migration.');

global $wpdb;
$mode = $args[0] ?? 'preview';
if (!in_array($mode, ['preview', 'apply'], true)) WP_CLI::error('Use preview, or apply with the reviewed preview hash.');
$lock = pc_folio_bacs_lock_name();
if ((string) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', $lock)) !== '1') WP_CLI::error('Another recipient update is running. Check its result before retrying.');
$failure = null;
try {
    $read = static function (string $name) use ($wpdb) {
        return $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name));
    };
    $raw = $read('woocommerce_bacs_accounts');
    $legacy_raw = $read('pc_folio_invoice_settings');
    $accounts = is_string($raw) ? maybe_unserialize($raw) : null;
    $legacy = is_string($legacy_raw) ? maybe_unserialize($legacy_raw) : null;
    $candidate = is_array($accounts) && is_array($legacy) ? pc_folio_bacs_legacy_candidate($accounts, $legacy) : [];
    if (!$candidate) throw new RuntimeException('No safe exact-match migration. Review the single recipient in WooCommerce; no settings were changed.');
    $new = [$candidate];
    $preview_payload = wp_json_encode([$raw, $legacy_raw, $new]);
    if (!is_string($preview_payload)) throw new RuntimeException('Preview could not be encoded. No settings were changed.');
    $fingerprint = hash_hmac('sha256', $preview_payload, wp_salt('auth'));
    if ($accounts === $new) {
        WP_CLI::line(wp_json_encode(['already_ready' => true, 'changed' => false]));
    } elseif ($mode === 'preview') {
        WP_CLI::line(wp_json_encode(['ready' => true, 'preview_hash' => $fingerprint, 'bank_fields_unchanged' => true, 'adds' => ['tax_id', 'pc_folio_confirmed_hash'], 'legacy_retained_for_pre_deploy_compatibility' => true, 'changed' => false]));
    } else {
        if (!is_string($args[1] ?? null) || !hash_equals($fingerprint, $args[1])) throw new RuntimeException('Preview changed or was not supplied. Run preview again; nothing was written.');
        $dir = realpath((string) getenv('PC_FOLIO_PAYMENT_BACKUP_DIR'));
        $root = realpath(ABSPATH);
        if (!$dir || !$root || !is_dir($dir) || $dir === $root || strpos($dir . '/', $root . '/') === 0 || (fileperms($dir) & 0077) !== 0) {
            throw new RuntimeException('Provide an existing private backup directory outside the public site, with permissions 0700.');
        }
        $file = $dir . '/woo-recipient-' . gmdate('Ymd-His') . '-' . wp_generate_uuid4() . '.json';
        $body = wp_json_encode(['at_utc' => gmdate('c'), 'accounts' => $accounts, 'legacy' => $legacy, 'new_accounts' => $new], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        if (!is_string($body)) throw new RuntimeException('Private backup could not be encoded.');
        $handle = fopen($file, 'x');
        if (!$handle) throw new RuntimeException('Private backup could not be created.');
        chmod($file, 0600);
        $bytes = fwrite($handle, $body);
        fclose($handle);
        if ($bytes !== strlen($body) || hash_file('sha256', $file) !== hash('sha256', $body)) throw new RuntimeException('Private backup verification failed.');
        if ($read('pc_folio_invoice_settings') !== $legacy_raw) throw new RuntimeException('Legacy settings changed during preview; nothing was written.');
        // Compare-and-swap protects against older code that does not share our lock.
        $result = $wpdb->query($wpdb->prepare("UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND BINARY option_value = BINARY %s", maybe_serialize($new), 'woocommerce_bacs_accounts', $raw));
        if ($result !== 1) throw new RuntimeException('Account changed concurrently or the update failed. Inspect current state before retrying.');
        wp_cache_delete('woocommerce_bacs_accounts', 'options');
        wp_cache_delete('alloptions', 'options');
        wp_cache_delete('notoptions', 'options');
        if ($read('woocommerce_bacs_accounts') !== maybe_serialize($new)) throw new RuntimeException('Saved result could not be verified. Inspect current state; do not repeat blindly.');
        WP_CLI::line(wp_json_encode(['updated' => true, 'bank_fields_unchanged' => true, 'private_backup' => basename($file), 'legacy_retained_for_pre_deploy_compatibility' => true, 'mail_sent' => false, 'orders_changed' => false]));
    }
} catch (Throwable $error) {
    $failure = $error->getMessage();
} finally {
    $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
}
if ($failure !== null) WP_CLI::error($failure);
