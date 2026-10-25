<?php

namespace Paint\NovaPoshta\Registry;

defined('ABSPATH') || exit;

/** Separate read-model: never migrate or unlock the carrier-write lifecycle tables. */
final class Schema
{
    public const VERSION = '1';

    public static function ready(): bool
    {
        return get_option('pnpm_registry_schema') === self::VERSION;
    }

    public static function install(): void
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $p = $wpdb->prefix . 'pnpm_registry';
        $charset = $wpdb->get_charset_collate();
        $definitions = [
            $p => "id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                ttn CHAR(14) NOT NULL,
                direction VARCHAR(16) NOT NULL DEFAULT 'unknown',
                purpose VARCHAR(24) NOT NULL DEFAULT 'unclassified',
                partner_code VARCHAR(32) NOT NULL DEFAULT '',
                related_ttn VARCHAR(14) NOT NULL DEFAULT '',
                note TEXT NULL,
                revision BIGINT UNSIGNED NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY ttn (ttn),
                KEY classification (direction,purpose)",
            $p . '_observations' => "id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                registry_id BIGINT UNSIGNED NOT NULL,
                source VARCHAR(32) NOT NULL,
                payload_hash CHAR(64) NOT NULL,
                payload LONGTEXT NOT NULL,
                observed_at DATETIME NOT NULL,
                recorded_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                KEY shipment_source (registry_id,source,observed_at)",
            $p . '_links' => "id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                registry_id BIGINT UNSIGNED NOT NULL,
                document_key CHAR(64) NOT NULL,
                document_type VARCHAR(24) NOT NULL,
                document_id VARCHAR(32) NOT NULL DEFAULT '',
                document_number VARCHAR(100) NOT NULL,
                document_date DATE NOT NULL,
                partner_code VARCHAR(32) NOT NULL DEFAULT '',
                warehouse_id VARCHAR(32) NOT NULL DEFAULT '',
                role VARCHAR(24) NOT NULL,
                verification VARCHAR(24) NOT NULL,
                evidence TEXT NOT NULL,
                snapshot LONGTEXT NOT NULL,
                active TINYINT NOT NULL DEFAULT 1,
                confirmed_by BIGINT UNSIGNED NOT NULL,
                confirmed_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY document_link (registry_id,document_key,role),
                KEY document_key (document_key)",
            $p . '_audit' => "id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                registry_id BIGINT UNSIGNED NOT NULL,
                event_type VARCHAR(32) NOT NULL,
                actor_id BIGINT UNSIGNED NOT NULL,
                context LONGTEXT NOT NULL,
                created_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                KEY registry_id (registry_id)",
            $p . '_batches' => "id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                token_hash CHAR(64) NOT NULL,
                actor_id BIGINT UNSIGNED NOT NULL,
                kind VARCHAR(24) NOT NULL,
                status VARCHAR(16) NOT NULL DEFAULT 'preview',
                payload LONGTEXT NOT NULL,
                result LONGTEXT NULL,
                expires_at DATETIME NOT NULL,
                created_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY token_hash (token_hash)",
        ];
        foreach ($definitions as $table => $definition) {
            dbDelta("CREATE TABLE $table (\n$definition\n) ENGINE=InnoDB $charset;");
            $engine = $wpdb->get_var($wpdb->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', $table));
            if (strtolower((string) $engine) !== 'innodb') throw new \RuntimeException('Registry requires InnoDB.');
        }
        update_option('pnpm_registry_schema', self::VERSION, false);
    }
}
