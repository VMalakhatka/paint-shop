<?php

namespace Paint\NovaPoshta;

use Paint\NovaPoshta\Domain\DeliveryPolicy;

defined('ABSPATH') || exit;

final class Activator
{
    public static function activate(): void
    {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        $shipments = $wpdb->prefix . 'pnpm_shipments';
        $items = $wpdb->prefix . 'pnpm_shipment_items';
        $events = $wpdb->prefix . 'pnpm_shipment_events';
        $documents = $wpdb->prefix . 'pnpm_private_documents';

        dbDelta("CREATE TABLE {$shipments} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            shipment_uuid CHAR(36) NOT NULL,
            order_id BIGINT UNSIGNED NOT NULL,
            location_id BIGINT UNSIGNED NOT NULL,
            source VARCHAR(32) NOT NULL DEFAULT 'store_api',
            status VARCHAR(48) NOT NULL DEFAULT 'planned',
            idempotency_key CHAR(64) NOT NULL,
            np_ref VARCHAR(64) NULL,
            ttn_number VARCHAR(32) NULL,
            sender_snapshot LONGTEXT NULL,
            recipient_snapshot LONGTEXT NULL,
            pricing_snapshot LONGTEXT NULL,
            request_snapshot LONGTEXT NULL,
            response_snapshot LONGTEXT NULL,
            capabilities LONGTEXT NULL,
            submitted_by BIGINT UNSIGNED NULL,
            approved_by BIGINT UNSIGNED NULL,
            submitted_at DATETIME NULL,
            approved_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY shipment_uuid (shipment_uuid),
            UNIQUE KEY idempotency_key (idempotency_key),
            UNIQUE KEY ttn_number (ttn_number),
            KEY order_id (order_id),
            KEY location_id (location_id),
            KEY source_status (source, status)
        ) {$charset};");

        dbDelta("CREATE TABLE {$items} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            shipment_id BIGINT UNSIGNED NOT NULL,
            order_item_id BIGINT UNSIGNED NOT NULL,
            product_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            variation_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            sku VARCHAR(191) NULL,
            quantity DECIMAL(18,6) NOT NULL DEFAULT 0,
            line_subtotal DECIMAL(18,6) NOT NULL DEFAULT 0,
            line_total DECIMAL(18,6) NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            UNIQUE KEY shipment_order_item (shipment_id, order_item_id),
            KEY order_item_id (order_item_id),
            KEY product_id (product_id)
        ) {$charset};");

        dbDelta("CREATE TABLE {$events} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            shipment_id BIGINT UNSIGNED NOT NULL,
            event_type VARCHAR(64) NOT NULL,
            actor_id BIGINT UNSIGNED NULL,
            correlation_id CHAR(36) NULL,
            safe_context LONGTEXT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY shipment_id (shipment_id),
            KEY event_type (event_type),
            KEY created_at (created_at)
        ) {$charset};");

        dbDelta("CREATE TABLE {$documents} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            shipment_id BIGINT UNSIGNED NOT NULL,
            source_type VARCHAR(24) NOT NULL,
            relative_path TEXT NOT NULL,
            original_name VARCHAR(255) NOT NULL,
            mime_type VARCHAR(100) NOT NULL,
            size_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
            checksum_sha256 CHAR(64) NOT NULL,
            created_by BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY shipment_id (shipment_id),
            KEY checksum_sha256 (checksum_sha256)
        ) {$charset};");

        add_option('pnpm_db_version', PNPM_VERSION, '', false);
        add_option('pnpm_settings', [
            'api_base_url' => 'https://api.novaposhta.ua/v2.0/json/',
            'writes_enabled' => 'no',
            'external_ttn_enabled' => 'yes',
            'checkout_enabled' => 'yes',
            'weight_mode' => 'grams',
            'fallback_item_weight_kg' => 0.25,
            'minimum_declared_cost' => 500,
            'parcel_locker_surcharge' => 10,
        ], '', false);
        add_option(DeliveryPolicy::OPTION_NAME, DeliveryPolicy::defaults(), '', false);

        foreach (['administrator', 'shop_manager'] as $role_name) {
            $role = get_role($role_name);
            if ($role) {
                $role->add_cap('manage_pnpm_shipments');
            }
        }
    }
}
