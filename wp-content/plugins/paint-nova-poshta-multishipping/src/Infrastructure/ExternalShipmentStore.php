<?php

namespace Paint\NovaPoshta\Infrastructure;

use WC_Order;

defined('ABSPATH') || exit;

/** Writes only local shipment records. Unique TTN + transaction protect concurrent checkout. */
final class ExternalShipmentStore
{
    public function submit(WC_Order $order): void
    {
        global $wpdb;
        $plan = $order->get_meta('_pnpm_external_plan', true);
        if ($plan === '' || $plan === null || $plan === []) {
            return;
        }
        if (!is_array($plan)) {
            throw new \RuntimeException(__('Review the customer TTN fields before placing the order.', 'paint-nova-poshta-multishipping'));
        }
        foreach ($plan as $parcel) {
            if (!is_array($parcel) || !isset($parcel['location_id'], $parcel['ttn'], $parcel['label'], $parcel['items'])
                || !is_array($parcel['items'])) {
                throw new \RuntimeException(__('Review the customer TTN fields before placing the order.', 'paint-nova-poshta-multishipping'));
            }
        }
        $lock = 'pnpm_order_' . $order->get_id();
        if ((int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 5)', $lock)) !== 1) {
            throw new \RuntimeException(__('Shipment saving is busy. Please try again.', 'paint-nova-poshta-multishipping'));
        }
        $wpdb->query('START TRANSACTION');
        try {
            $items = [];
            foreach ($order->get_items() as $item) {
                $items[(string) $item->get_meta('_pnpm_cart_key')] = $item;
            }
            $existing = (new ShipmentRepository())->findByOrder($order->get_id());
            if ($existing) {
                $old = array_column($existing, 'ttn_number', 'location_id');
                $new = array_column($plan, 'ttn', 'location_id');
                ksort($old); ksort($new);
                if ($old !== $new) {
                    throw new \RuntimeException(__('This order already has shipments. Contact the manager before changing a TTN.', 'paint-nova-poshta-multishipping'));
                }
                $by_location = array_column($plan, null, 'location_id');
                foreach ($existing as $row) {
                    $saved = json_decode((string) $row['request_snapshot'], true);
                    $parcel = $by_location[$row['location_id']];
                    if ($row['source'] !== 'customer_external' || $row['status'] !== 'submitted' || ($saved['order_items'] ?? []) != $parcel['items']) {
                        throw new \RuntimeException(__('This order already has shipments. Contact the manager before changing a TTN.', 'paint-nova-poshta-multishipping'));
                    }
                    // Woo recreates item IDs when resuming an unpaid checkout.
                    if ($wpdb->delete($wpdb->prefix . 'pnpm_shipment_items', ['shipment_id' => $row['id']]) === false) {
                        throw new \RuntimeException(__('The shipment could not be saved. Contact the manager before retrying.', 'paint-nova-poshta-multishipping'));
                    }
                    $this->saveItems((int) $row['id'], $parcel, $items);
                }
                $wpdb->query('COMMIT');
                return;
            }
            foreach ($plan as $parcel) {
                $now = current_time('mysql', true);
                $this->insert('pnpm_shipments', [
                    'shipment_uuid' => wp_generate_uuid4(), 'order_id' => $order->get_id(),
                    'location_id' => $parcel['location_id'], 'source' => 'customer_external', 'status' => 'submitted',
                    'idempotency_key' => hash('sha256', $order->get_id() . ':' . $parcel['location_id']),
                    'ttn_number' => $parcel['ttn'],
                    'sender_snapshot' => wp_json_encode(['label' => $parcel['label'], 'location_id' => $parcel['location_id']]),
                    'request_snapshot' => wp_json_encode(['input_type' => 'text_or_link', 'order_items' => $parcel['items']]),
                    'pricing_snapshot' => wp_json_encode(['checkout_delivery' => 0, 'carrier_payment' => 'external_ttn']),
                    'capabilities' => wp_json_encode(['track' => true, 'api_edit' => false, 'api_delete' => false]),
                    'submitted_by' => $order->get_customer_id(), 'submitted_at' => $now,
                    'created_at' => $now, 'updated_at' => $now,
                ]);
                $id = (int) $wpdb->insert_id;
                $this->saveItems($id, $parcel, $items);
                $this->event($id, 'submitted', $order->get_customer_id());
            }
            if ($wpdb->query('COMMIT') === false) {
                throw new \RuntimeException(__('The shipment could not be saved. Contact the manager before retrying.', 'paint-nova-poshta-multishipping'));
            }
        } catch (\Throwable $e) {
            $wpdb->query('ROLLBACK');
            throw $e;
        } finally {
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
        }
    }

    public function find(int $id): ?array
    {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}pnpm_shipments WHERE id=%d AND source='customer_external'", $id), ARRAY_A) ?: null;
    }

    private function saveItems(int $id, array $parcel, array $items): void
    {
        foreach ($parcel['items'] as $line) {
            $item = $items[$line['cart_item_key']] ?? null;
            if (!$item || (float) $item->get_quantity() < (float) $line['quantity'] || (float) $line['quantity'] <= 0) {
                throw new \RuntimeException(__('Warehouse allocation changed. Please review the order.', 'paint-nova-poshta-multishipping'));
            }
            $ratio = (float) $line['quantity'] / (float) $item->get_quantity();
            $this->insert('pnpm_shipment_items', [
                'shipment_id' => $id, 'order_item_id' => $item->get_id(), 'product_id' => $item->get_product_id(),
                'variation_id' => $item->get_variation_id(), 'sku' => $line['sku'], 'quantity' => $line['quantity'],
                'line_subtotal' => $item->get_subtotal() * $ratio, 'line_total' => $item->get_total() * $ratio,
            ]);
        }
    }

    public function items(int $id): array
    {
        global $wpdb;
        return (array) $wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->prefix}pnpm_shipment_items WHERE shipment_id=%d", $id), ARRAY_A);
    }

    public function event(int $id, string $type, int $actor): void
    {
        $this->insert('pnpm_shipment_events', ['shipment_id' => $id, 'event_type' => $type,
            'actor_id' => $actor, 'created_at' => current_time('mysql', true)]);
    }

    private function insert(string $table, array $data): void
    {
        global $wpdb;
        $previous = $wpdb->suppress_errors(true);
        try {
            $ok = $wpdb->insert($wpdb->prefix . $table, $data);
        } finally {
            $wpdb->suppress_errors($previous);
        }
        if ($ok === false) {
            throw new \RuntimeException(__('The TTN could not be saved. It may already belong to another order. Contact the manager.', 'paint-nova-poshta-multishipping'));
        }
    }
}
