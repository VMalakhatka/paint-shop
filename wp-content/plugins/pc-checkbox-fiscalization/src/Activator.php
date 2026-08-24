<?php

namespace Paint\CheckboxFiscalization;

defined('ABSPATH') || exit;

final class Activator
{
    public static function activate(): void
    {
        self::migrate();

        foreach (['administrator', 'shop_manager'] as $role_name) {
            $role = get_role($role_name);
            if ($role) {
                $role->add_cap('manage_pc_checkbox_fiscalization');
            }
        }
    }

    public static function maybeMigrate(): void
    {
        if ((string) get_option('pccf_db_version', '') !== PCCF_DB_VERSION) {
            self::migrate();
        }
    }

    private static function migrate(): void
    {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table = $wpdb->prefix . 'pc_checkbox_operations';
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            operation_key varchar(191) NOT NULL,
            receipt_uuid char(36) NOT NULL,
            source_system varchar(64) NOT NULL,
            source_type varchar(64) NOT NULL,
            source_id varchar(191) NOT NULL,
            operation_type varchar(16) NOT NULL,
            request_hash char(64) NOT NULL,
            status varchar(24) NOT NULL,
            mode varchar(16) NOT NULL,
            checkbox_receipt_id char(36) NULL,
            fiscal_code varchar(128) NULL,
            shift_id char(36) NULL,
            transaction_id char(36) NULL,
            total_cents bigint(20) NOT NULL DEFAULT 0,
            attempts int(10) unsigned NOT NULL DEFAULT 0,
            http_code int(10) unsigned NULL,
            error_code varchar(128) NULL,
            error_message text NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY operation_key (operation_key),
            UNIQUE KEY receipt_uuid (receipt_uuid),
            KEY status_updated (status, updated_at)
        ) {$charset};";

        dbDelta($sql);
        update_option('pccf_db_version', PCCF_DB_VERSION, false);
    }
}

