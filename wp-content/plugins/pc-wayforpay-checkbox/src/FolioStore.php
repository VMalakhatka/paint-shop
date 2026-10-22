<?php
namespace Paint\WayForPayCheckbox;

/** Independent durable outbox. Never shares a SQL transaction with Folio. */
final class FolioStore
{
    private string $table;
    public function __construct() { global $wpdb; $this->table = $wpdb->prefix . 'pc_wfp_folio_payments'; }
    public static function upgrade(): void
    {
        if ((int) get_option('pc_wfp_folio_schema', 0) === 2) return;
        global $wpdb;
        $lock = 'pcwfp-schema:' . substr(hash('sha256', $wpdb->prefix), 0, 40);
        if ((string) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,5)', $lock)) !== '1') return;
        try {
            Store::install(); self::install();
        } finally { $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock)); }
    }
    public static function install(): void
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table = $wpdb->prefix . 'pc_wfp_folio_payments';
        dbDelta("CREATE TABLE $table (
            order_id bigint(20) unsigned NOT NULL,
            operation_key char(64) NOT NULL,
            request_hash char(64) NOT NULL,
            command longtext NOT NULL,
            mode varchar(16) NOT NULL,
            status varchar(32) NOT NULL,
            error_code varchar(96) NOT NULL DEFAULT '',
            attempts int unsigned NOT NULL DEFAULT 0,
            next_run bigint(20) unsigned NOT NULL DEFAULT 0,
            sent tinyint NOT NULL DEFAULT 0,
            refunded tinyint NOT NULL DEFAULT 0,
            payment_id bigint(20) unsigned NOT NULL DEFAULT 0,
            payment_number bigint(20) unsigned NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (order_id),
            UNIQUE KEY operation_key (operation_key),
            KEY recovery (status,next_run)
        ) " . $wpdb->get_charset_collate() . ';');
        // Only mark installed after the table can actually be read.
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) === $table
            && $wpdb->get_var("SHOW COLUMNS FROM {$wpdb->prefix}pc_wfp_receipts LIKE 'folio_payer_key'") === 'folio_payer_key'
            && $wpdb->get_var("SHOW COLUMNS FROM {$wpdb->prefix}pc_wfp_receipts LIKE 'approved_at'") === 'approved_at') {
            update_option('pc_wfp_folio_schema', 2, false);
        }
    }
    public function get(int $id): ?array
    {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table} WHERE order_id=%d", $id), ARRAY_A) ?: null;
    }
    public function insert(array $row): void
    {
        global $wpdb;
        $row['created_at'] = $row['updated_at'] = gmdate('Y-m-d H:i:s');
        if ($wpdb->insert($this->table, $row) !== 1) throw new \RuntimeException('folio_storage_failed');
    }
    public function update(int $id, array $data): void
    {
        global $wpdb;
        $data['updated_at'] = gmdate('Y-m-d H:i:s');
        if ($wpdb->update($this->table, $data, ['order_id' => $id]) === false) throw new \RuntimeException('folio_storage_failed');
    }
    public function pending(): array
    {
        global $wpdb;
        return $wpdb->get_col($wpdb->prepare("SELECT order_id FROM {$this->table}
            WHERE status IN ('QUEUED','RECONCILE') AND next_run <= %d
            ORDER BY updated_at LIMIT 50", time())) ?: [];
    }
}
