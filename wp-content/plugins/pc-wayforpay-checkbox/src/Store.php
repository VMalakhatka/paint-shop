<?php
namespace Paint\WayForPayCheckbox;

final class Store
{
    private string $table;
    public function __construct()
    {
        global $wpdb;
        $this->table = $wpdb->prefix . 'pc_wfp_receipts';
    }

    public static function install(): void
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table = $wpdb->prefix . 'pc_wfp_receipts';
        dbDelta("CREATE TABLE $table (
            order_id bigint(20) unsigned NOT NULL,
            reference varchar(191) NOT NULL,
            merchant_hash char(64) NOT NULL,
            amount bigint(20) unsigned NOT NULL,
            currency char(3) NOT NULL,
            command longtext NOT NULL,
            delivery_hash char(64) NOT NULL DEFAULT '',
            mode varchar(16) NOT NULL,
            status varchar(32) NOT NULL,
            error_code varchar(96) NOT NULL DEFAULT '',
            attempts int unsigned NOT NULL DEFAULT 0,
            next_run bigint(20) unsigned NOT NULL DEFAULT 0,
            payment_applied tinyint NOT NULL DEFAULT 0,
            refunded tinyint NOT NULL DEFAULT 0,
            folio_payer_key varchar(32) NOT NULL DEFAULT '',
            approved_at datetime NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (order_id),
            UNIQUE KEY reference (reference),
            KEY recovery (status, updated_at)
        ) " . $wpdb->get_charset_collate() . ';');
    }

    public function lock(int $id): bool
    {
        global $wpdb;
        return (string) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,0)', $this->lockName($id))) === '1';
    }
    public function unlock(int $id): void
    {
        global $wpdb;
        $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $this->lockName($id)));
    }
    private function lockName(int $id): string
    {
        return 'pcwfp:' . substr(hash('sha256', $this->table . ':' . $id), 0, 56);
    }
    public function get(int $id): ?array
    {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table} WHERE order_id=%d", $id), ARRAY_A) ?: null;
    }
    public function insert(array $data): void
    {
        global $wpdb;
        $data['created_at'] = $data['updated_at'] = gmdate('Y-m-d H:i:s');
        if ($wpdb->insert($this->table, $data) !== 1) {
            throw new \RuntimeException('payment_storage_failed');
        }
    }
    public function update(int $id, array $data): void
    {
        global $wpdb;
        $data['updated_at'] = gmdate('Y-m-d H:i:s');
        if ($wpdb->update($this->table, $data, ['order_id' => $id]) === false) {
            throw new \RuntimeException('payment_storage_failed');
        }
    }
    public function pending(): array
    {
        global $wpdb;
        return $wpdb->get_col($wpdb->prepare("SELECT order_id FROM {$this->table} WHERE (status IN ('QUEUED','RECONCILE') OR (payment_applied = 0 AND refunded = 0)) AND attempts < 8 AND next_run <= %d ORDER BY updated_at LIMIT 50", time())) ?: [];
    }
}
