<?php

namespace Paint\NovaPoshta\Admin;

use Paint\NovaPoshta\Infrastructure\ExternalShipmentStore;
use Paint\NovaPoshta\Infrastructure\ExternalTracking;
use Paint\NovaPoshta\Infrastructure\ShipmentRepository;
use WC_Order;

defined('ABSPATH') || exit;

final class ExternalTtnPanel
{
    public function hooks(): void
    {
        add_action('pnpm_order_panel', [$this, 'admin']);
        add_action('woocommerce_order_details_after_order_table', [$this, 'customer']);
        add_action('wp_ajax_pnpm_external_action', [$this, 'action']);
        add_action('admin_enqueue_scripts', [$this, 'assets']);
    }

    public function assets(): void
    {
        $screen = get_current_screen();
        if (!$screen || !in_array($screen->id, ['shop_order', 'woocommerce_page_wc-orders'], true)) { return; }
        wp_enqueue_script('pnpm-external-admin', PNPM_URL . 'assets/external-admin.js', ['jquery'], PNPM_VERSION, true);
        wp_localize_script('pnpm-external-admin', 'pnpmExternal', [
            'url' => admin_url('admin-ajax.php'), 'nonce' => wp_create_nonce('pnpm_external_action'),
            'busy' => __('Checking shipment...', 'paint-nova-poshta-multishipping'),
            'failed' => __('The action failed. Refresh the order before retrying.', 'paint-nova-poshta-multishipping'),
        ]);
    }

    private function sourceOrder(WC_Order $order): WC_Order
    {
        $id = (int) $order->get_meta('_folio_parent_order_id');
        $parent = $id ? wc_get_order($id) : false;
        return $parent && (int) $parent->get_customer_id() === (int) $order->get_customer_id() ? $parent : $order;
    }

    public function customer(WC_Order $order): void
    {
        if (!get_current_user_id() || ((int) $order->get_customer_id() !== get_current_user_id() && !current_user_can('manage_pnpm_shipments'))) { return; }
        $source = $this->sourceOrder($order);
        $rows = (new ShipmentRepository())->findByOrder($source->get_id());
        $rows = array_filter($rows, static fn($row) => $row['source'] === 'customer_external');
        if (!$rows) { return; }
        echo '<section class="pnpm-customer-shipments"><h2>' . esc_html__('Your Nova Poshta TTNs', 'paint-nova-poshta-multishipping') . '</h2>';
        if ($source->get_id() !== $order->get_id()) {
            echo '<p><a href="' . esc_url($source->get_view_order_url()) . '">' . esc_html__('Shipments for the original order (all warehouses)', 'paint-nova-poshta-multishipping') . '</a></p>';
        }
        foreach ($rows as $row) {
            $sender = json_decode((string) $row['sender_snapshot'], true);
            echo '<p>' . esc_html(($sender['label'] ?? '') . ' — ' . $row['ttn_number']) . ' — ' . esc_html($this->status($row['status'])) . '</p>';
        }
        echo '<p>' . esc_html__('Nova Poshta services are paid under your TTN. Contact the manager to correct a submitted TTN.', 'paint-nova-poshta-multishipping') . '</p></section>';
    }

    public function admin(WC_Order $order): void
    {
        if (!current_user_can('manage_pnpm_shipments')) { return; }
        $source = $this->sourceOrder($order);
        if ($source->get_id() !== $order->get_id()) {
            echo '<p><a href="' . esc_url($source->get_edit_order_url()) . '">' . esc_html__('Manage customer TTNs in the original order', 'paint-nova-poshta-multishipping') . '</a></p>';
            return;
        }
        $store = new ExternalShipmentStore();
        foreach ((new ShipmentRepository())->findByOrder($order->get_id()) as $row) {
            if ($row['source'] !== 'customer_external') { continue; }
            $sender = json_decode((string) $row['sender_snapshot'], true);
            $tracking = json_decode((string) $row['response_snapshot'], true) ?: [];
            echo '<div class="pnpm-external-review" data-shipment="' . esc_attr($row['id']) . '"><h4>' . esc_html(($sender['label'] ?? '') . ' — TTN ' . $row['ttn_number']) . '</h4>';
            echo '<p>' . esc_html($this->status($row['status'])) . '</p><ul>';
            foreach ($store->items((int) $row['id']) as $line) {
                $item = $order->get_item((int) $line['order_item_id']);
                echo '<li>' . esc_html(($item ? $item->get_name() : $line['sku']) . ' × ' . wc_format_decimal($line['quantity'])) . '</li>';
            }
            echo '</ul><p><a target="_blank" rel="noopener noreferrer" href="' . esc_url('https://tracking.novaposhta.ua/#/uk/' . $row['ttn_number']) . '">' . esc_html__('Open official tracking', 'paint-nova-poshta-multishipping') . '</a></p>';
            if (!empty($tracking['checked_at'])) {
                echo '<p>' . esc_html(wp_date('Y-m-d H:i', (int) $tracking['checked_at']) . ' — ' . ($tracking['text'] ?: __('Tracking is unavailable; verify the TTN manually.', 'paint-nova-poshta-multishipping'))) . '</p>';
            }
            echo '<button type="button" class="button pnpm-external-action" data-operation="check">' . esc_html__('Check Nova Poshta status', 'paint-nova-poshta-multishipping') . '</button>';
            if ($row['status'] === 'submitted') {
                echo '<p><label><input type="checkbox" class="pnpm-external-confirm"> ' . esc_html__('I verified the sender warehouse, assigned goods, recipient, payer, COD, value, weight, places and label. This TTN is awaiting dispatch.', 'paint-nova-poshta-multishipping') . '</label></p>';
                echo '<p><label>' . esc_html__('Manual verification note (required if tracking is unavailable)', 'paint-nova-poshta-multishipping') . '<br><input type="text" maxlength="500" class="pnpm-external-note widefat"></label></p>';
                echo '<button type="button" class="button button-primary pnpm-external-action" data-operation="approve">' . esc_html__('Approve for warehouse handoff', 'paint-nova-poshta-multishipping') . '</button>';
            }
            echo '<p class="pnpm-external-result" role="status"></p></div>';
        }
    }

    private function status(string $status): string
    {
        return $status === 'approved'
            ? __('Approved by the warehouse', 'paint-nova-poshta-multishipping')
            : __('Submitted by customer; awaiting warehouse review', 'paint-nova-poshta-multishipping');
    }

    public function action(): void
    {
        check_ajax_referer('pnpm_external_action', 'nonce');
        if (!current_user_can('manage_pnpm_shipments')) { wp_send_json_error(['message' => __('Access denied.', 'paint-nova-poshta-multishipping')], 403); }
        $store = new ExternalShipmentStore();
        $id = absint($_POST['shipment_id'] ?? 0);
        $row = $store->find($id);
        $order = $row ? wc_get_order((int) $row['order_id']) : false;
        if (!$order) { wp_send_json_error(['message' => __('Shipment not found.', 'paint-nova-poshta-multishipping')], 404); }
        $operation = sanitize_key($_POST['operation'] ?? '');
        if (!in_array($operation, ['check', 'approve'], true)) { wp_send_json_error([], 400); }
        if ($operation === 'approve' && (string) ($_POST['confirmed'] ?? '') !== '1') {
            wp_send_json_error(['message' => __('Confirm the warehouse shipment checklist first.', 'paint-nova-poshta-multishipping')], 400);
        }
        try {
            $tracking = (new ExternalTracking())->check($row['ttn_number']);
            global $wpdb;
            $wpdb->update($wpdb->prefix . 'pnpm_shipments', ['response_snapshot' => wp_json_encode($tracking), 'updated_at' => current_time('mysql', true)], ['id' => $id]);
            if ($operation === 'check') {
                $store->event($id, 'tracking_checked', get_current_user_id());
                wp_send_json_success(['message' => $tracking['text'] ?: __('Tracking is unavailable; verify the TTN manually.', 'paint-nova-poshta-multishipping')]);
            }
            $note = sanitize_textarea_field(wp_unslash((string) ($_POST['note'] ?? '')));
            if ($tracking['rejected'] || ($tracking['available'] && $tracking['code'] !== '1')) {
                throw new \RuntimeException(__('Nova Poshta does not show this TTN as awaiting dispatch. Check it with the customer.', 'paint-nova-poshta-multishipping'));
            }
            if (!$tracking['available'] && (mb_strlen(trim($note)) < 10 || mb_strlen($note) > 500)) {
                throw new \RuntimeException(__('Record how you manually verified the TTN (10–500 characters).', 'paint-nova-poshta-multishipping'));
            }
            $this->approve($order, $row, $note);
            wp_send_json_success(['message' => __('Approved by the warehouse', 'paint-nova-poshta-multishipping'), 'reload' => true]);
        } catch (\Throwable $e) {
            wp_send_json_error(['message' => $e->getMessage()], 400);
        }
    }

    public function approve(WC_Order $order, array $row, string $note): void
    {
        global $wpdb;
        $lock = 'pnpm_order_' . $order->get_id();
        if ((int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 5)', $lock)) !== 1) {
            throw new \RuntimeException(__('Shipment saving is busy. Please try again.', 'paint-nova-poshta-multishipping'));
        }
        try {
            $fresh = wc_get_order($order->get_id());
            if (!$fresh) { throw new \RuntimeException(__('Shipment not found.', 'paint-nova-poshta-multishipping')); }
            $this->approveLocked($fresh, $row, $note);
        } finally {
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
        }
    }

    private function approveLocked(WC_Order $order, array $row, string $note): void
    {
        global $wpdb;
        if ($order->has_status(['cancelled', 'refunded', 'failed', 'completed'])) {
            throw new \RuntimeException(__('This order cannot be approved for dispatch.', 'paint-nova-poshta-multishipping'));
        }
        $store = new ExternalShipmentStore();
        // Compare all frozen quantities with the current parent order and any actual SLW allocation.
        $totals = [];
        foreach ((new ShipmentRepository())->findByOrder($order->get_id()) as $parcel) {
            foreach ($store->items((int) $parcel['id']) as $line) {
                $item = $order->get_item((int) $line['order_item_id']);
                if (!$item || (int) $item->get_product_id() !== (int) $line['product_id'] || (int) $item->get_variation_id() !== (int) $line['variation_id']) {
                    throw new \RuntimeException(__('Warehouse allocation changed. Please review the order.', 'paint-nova-poshta-multishipping'));
                }
                $totals[$item->get_id()] = ($totals[$item->get_id()] ?? 0) + (float) $line['quantity'];
                $actual = $item->get_meta('_slw_data');
                if (is_array($actual) && $actual && abs((float) ($actual[$parcel['location_id']]['quantity_subtracted'] ?? 0) - (float) $line['quantity']) > 0.000001) {
                    throw new \RuntimeException(__('Warehouse allocation changed. Please review the order.', 'paint-nova-poshta-multishipping'));
                }
                $planned = $item->get_meta('_pc_alloc_plan');
                if ((!is_array($actual) || !$actual) && is_array($planned) && $planned
                    && abs((float) ($planned[$parcel['location_id']] ?? 0) - (float) $line['quantity']) > 0.000001) {
                    throw new \RuntimeException(__('Warehouse allocation changed. Please review the order.', 'paint-nova-poshta-multishipping'));
                }
            }
        }
        foreach ($order->get_items() as $item) {
            if ($item->get_product() && !$item->get_product()->needs_shipping()) { continue; }
            if (abs(($totals[$item->get_id()] ?? 0) - (float) $item->get_quantity()) > 0.000001) {
                throw new \RuntimeException(__('Warehouse allocation changed. Please review the order.', 'paint-nova-poshta-multishipping'));
            }
        }
        $wpdb->query('START TRANSACTION');
        try {
            $changed = $wpdb->update($wpdb->prefix . 'pnpm_shipments', ['status' => 'approved', 'approved_by' => get_current_user_id(),
                'approved_at' => current_time('mysql', true), 'updated_at' => current_time('mysql', true)], ['id' => $row['id'], 'status' => 'submitted']);
            if ($changed === false) { throw new \RuntimeException(__('The action failed. Refresh the order before retrying.', 'paint-nova-poshta-multishipping')); }
            if ($changed === 1) {
                $store->event((int) $row['id'], 'approved', get_current_user_id());
                if ($note !== '') {
                    if ($wpdb->insert($wpdb->prefix . 'pnpm_shipment_events', ['shipment_id' => $row['id'], 'event_type' => 'manual_verification',
                        'actor_id' => get_current_user_id(), 'safe_context' => wp_json_encode(['note' => $note]), 'created_at' => current_time('mysql', true)]) === false) {
                        throw new \RuntimeException(__('The action failed. Refresh the order before retrying.', 'paint-nova-poshta-multishipping'));
                    }
                }
            }
            $wpdb->query('COMMIT');
        } catch (\Throwable $e) { $wpdb->query('ROLLBACK'); throw $e; }
    }
}
