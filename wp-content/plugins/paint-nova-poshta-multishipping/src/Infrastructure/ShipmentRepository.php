<?php

namespace Paint\NovaPoshta\Infrastructure;

defined('ABSPATH') || exit;

final class ShipmentRepository
{
    /** @return array<int,array<string,mixed>> */
    public function findByOrder(int $order_id): array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'pnpm_shipments';
        $rows = $wpdb->get_results(
            $wpdb->prepare("SELECT * FROM {$table} WHERE order_id = %d ORDER BY id ASC", $order_id),
            ARRAY_A
        );
        return is_array($rows) ? $rows : [];
    }

    public function ttnExists(string $ttn): bool
    {
        global $wpdb;
        $table = $wpdb->prefix . 'pnpm_shipments';
        return (int) $wpdb->get_var(
            $wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE ttn_number = %s", $ttn)
        ) > 0;
    }
}

