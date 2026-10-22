<?php
namespace Paint\NovaPoshta\Admin;

use Paint\NovaPoshta\Checkout\CheckoutIntegration;
use Paint\NovaPoshta\Infrastructure\ManagerPreparation;
use Paint\NovaPoshta\Infrastructure\ShipmentRepository;

defined('ABSPATH') || exit;

/** Adapter for the existing-order manager workspace. Never a checkout action. */
final class ManagerPreparationPanel
{
    public function hooks(): void
    {
        add_action('pcoe_manager_order_shipments', [$this, 'render']);
        add_action('admin_enqueue_scripts', [$this, 'assets']);
        add_action('wp_ajax_pnpm_manager_preparation', [$this, 'ajax']);
    }

    public function assets(): void
    {
        if (!ManagerPreparation::allowed() || ($_GET['page'] ?? '') !== 'pcoe-customers' || empty($_GET['order_id'])) return;
        CheckoutIntegration::create()->enqueueAssets(false, true);
        wp_enqueue_script('pnpm-manager', PNPM_URL . 'assets/manager-preparation.js', ['jquery','pnpm-checkout'], PNPM_VERSION, true);
        wp_enqueue_style('pnpm-manager', PNPM_URL . 'assets/manager-preparation.css', [], PNPM_VERSION);
        wp_localize_script('pnpm-manager', 'pnpmManager', ['url'=>admin_url('admin-ajax.php'), 'nonce'=>wp_create_nonce('pnpm_manager_preparation'),
            'kg'=>__('kg', 'paint-nova-poshta-multishipping'), 'cm'=>__('cm', 'paint-nova-poshta-multishipping'),
            'currency'=>__('UAH', 'paint-nova-poshta-multishipping'), 'cod'=>__('Cash on delivery', 'paint-nova-poshta-multishipping'),
            'payer'=>__('Delivery payer', 'paint-nova-poshta-multishipping'), 'Sender'=>__('Sender', 'paint-nova-poshta-multishipping'),
            'Recipient'=>__('Recipient', 'paint-nova-poshta-multishipping'),
            'loading'=>__('Checking preparation…', 'paint-nova-poshta-multishipping'),
            'failure'=>__('The operation could not be confirmed. Reload before trying again.', 'paint-nova-poshta-multishipping'),
            'dirty'=>__('Unsaved changes. Save and check the preparation again.', 'paint-nova-poshta-multishipping')]);
    }

    public function render(\WC_Order $order): void
    {
        if (!ManagerPreparation::allowed()) return;
        echo '<section class="pcoe-card pnpm-manager"><h2 data-pcoe-help="np-preparation">' . esc_html__('Prepare Nova Poshta shipment', 'paint-nova-poshta-multishipping') . '</h2>';
        $parent_id = (int)($order->get_meta('_folio_parent_order_id') ?: $order->get_meta('_folio_split_from_order_id'));
        if ($parent_id) {
            $parent = wc_get_order($parent_id);
            if ($parent instanceof \WC_Order && $parent->get_customer_id() === $order->get_customer_id()
                && ($parent->get_customer_id() || $parent->get_billing_email() === $order->get_billing_email())) {
                echo '<p><a href="' . esc_url(\PaintCore\PCOE\ManagerWorkspace::url((int)$parent->get_customer_id(), $parent_id)) . '">' . esc_html__('Open the original order to prepare shipments for all warehouses.', 'paint-nova-poshta-multishipping') . '</a></p>';
            }
            echo '</section>'; return;
        }
        $service = new ManagerPreparation();
        try { $context = $service->context($order); }
        catch (\Throwable $e) { echo '<p>' . esc_html($e->getMessage()) . '</p></section>'; return; }
        echo '<p>' . esc_html__('Preparation is saved for this order only. It does not change the customer profile, create a TTN or send notifications.', 'paint-nova-poshta-multishipping') . '</p>';
        echo '<form id="pnpm-manager-form" data-order="' . esc_attr($order->get_id()) . '" data-revision="' . esc_attr($context['revision']) . '">';
        echo '<p><button type="button" class="button" data-pnpm-command="confirmation">' . esc_html__('Use current customer confirmation', 'paint-nova-poshta-multishipping') . '</button></p>';
        echo '<p class="description">' . esc_html__('Import checks the current order version. Review imported details and save separately; your saved preparation is not overwritten automatically.', 'paint-nova-poshta-multishipping') . '</p>';
        foreach (['name'=>__('Recipient name', 'paint-nova-poshta-multishipping'), 'phone'=>__('Recipient phone', 'paint-nova-poshta-multishipping')] as $key=>$label) {
            echo '<label>' . esc_html($label) . '<input name="' . esc_attr($key) . '" maxlength="300" value="' . esc_attr($context['recipient'][$key] ?? '') . '" required></label>';
        }
        CheckoutIntegration::create()->renderDestination($context['recipient'], true);
        echo '<p>' . esc_html__('Payment method and order total', 'paint-nova-poshta-multishipping') . ': ' . esc_html($order->get_payment_method_title() . ' · ' . $order->get_total() . ' UAH') . '</p>';
        foreach ($context['groups'] as $location=>$group) {
            echo '<fieldset data-location="' . esc_attr($location) . '"><legend>' . esc_html($group['location_name']) . '</legend><ul>';
            foreach ($group['items'] as $item) echo '<li>' . esc_html($item['sku'] . ' · ' . $item['name'] . ' × ' . $item['quantity']) . '</li>';
            echo '</ul><p>' . esc_html__('Warehouse sender', 'paint-nova-poshta-multishipping') . ': ' . esc_html(implode(' · ', array_filter([
                $group['sender']['customer_label'] ?? '', $group['sender']['handover_warehouse_label'] ?? '',
                ($group['sender']['sender_type'] ?? '') === 'doors' ? __('Courier pickup from sender address', 'paint-nova-poshta-multishipping') : __('Drop off at a branch / parcel locker', 'paint-nova-poshta-multishipping'),
                $group['sender']['phone'] ?? '',
            ]))) . '</p><p><a target="_blank" rel="noopener" href="' . esc_url(admin_url('admin.php?page=pnpm-settings')) . '">' . esc_html__('Check sender settings', 'paint-nova-poshta-multishipping') . '</a></p>';
            echo '<label>' . esc_html__('Delivery payer', 'paint-nova-poshta-multishipping') . '<select class="pnpm-payer">';
            foreach (['Recipient'=>__('Recipient', 'paint-nova-poshta-multishipping'), 'Sender'=>__('Sender', 'paint-nova-poshta-multishipping')] as $value=>$label) echo '<option value="' . esc_attr($value) . '"' . selected($group['payer'], $value, false) . '>' . esc_html($label) . '</option>';
            echo '</select></label><div class="pnpm-boxes">';
            foreach ($group['boxes'] as $box) self::box($box);
            echo '</div><button type="button" class="button pnpm-add-box">' . esc_html__('Add packed box', 'paint-nova-poshta-multishipping') . '</button></fieldset>';
        }
        echo '<template id="pnpm-box-template">'; self::box([]); echo '</template>';
        echo '<p><label><input type="checkbox" name="agreed" value="1"> ' . esc_html__('Recipient details were agreed; warehouse contents, packed box weights, dimensions and payer were checked.', 'paint-nova-poshta-multishipping') . '</label></p>';
        echo '<p><button type="button" class="button" data-pnpm-command="save">' . esc_html__('Save preparation', 'paint-nova-poshta-multishipping') . '</button> <button type="button" class="button button-primary" data-pnpm-command="preview">' . esc_html__('Check preview', 'paint-nova-poshta-multishipping') . '</button></p>';
        echo '<div id="pnpm-manager-status" role="status" aria-live="polite"></div><div id="pnpm-manager-preview"></div>';
        echo '<p>' . esc_html__('Save preparation and reload this page before creating a TTN.', 'paint-nova-poshta-multishipping') . '</p></form>';
        self::audit($order->get_id());
        echo '</section>';
    }

    private static function box(array $box): void
    {
        echo '<div class="pnpm-box">';
        foreach (['weight'=>__('Weight, kg', 'paint-nova-poshta-multishipping'), 'length'=>__('Length, cm', 'paint-nova-poshta-multishipping'),
            'width'=>__('Width, cm', 'paint-nova-poshta-multishipping'), 'height'=>__('Height, cm', 'paint-nova-poshta-multishipping')] as $key=>$label) {
            echo '<label>' . esc_html($label) . '<input type="number" data-box="' . esc_attr($key) . '" min="0.001" max="10000" step="any" value="' . esc_attr($box[$key] ?? '') . '" required></label>';
        }
        echo '<button type="button" class="button pnpm-remove-box">' . esc_html__('Remove box', 'paint-nova-poshta-multishipping') . '</button></div>';
    }

    private static function audit(int $order_id): void
    {
        global $wpdb;
        $events = $wpdb->get_results($wpdb->prepare("SELECT e.actor_id, e.created_at, e.safe_context, s.location_id FROM {$wpdb->prefix}pnpm_shipment_events e INNER JOIN {$wpdb->prefix}pnpm_shipments s ON s.id=e.shipment_id WHERE s.order_id=%d AND e.event_type='manager_preparation_saved' ORDER BY e.id DESC LIMIT 10", $order_id), ARRAY_A);
        echo '<details><summary>' . esc_html__('Preparation audit (last 10 saves)', 'paint-nova-poshta-multishipping') . '</summary><ul>';
        foreach ((array)$events as $event) {
            $context = json_decode((string)$event['safe_context'], true) ?: [];
            echo '<li>' . esc_html($event['created_at'] . ' UTC · #' . $event['actor_id'] . ' · ' . $event['location_id']) . '<br><code>' . esc_html(implode(', ', (array)($context['changed_fields'] ?? []))) . '</code></li>';
        }
        echo '</ul></details>';
    }

    public function ajax(): void
    {
        if (!ManagerPreparation::allowed()) wp_send_json_error(['message'=>__('Shipment access denied.', 'paint-nova-poshta-multishipping')], 403);
        check_ajax_referer('pnpm_manager_preparation', 'nonce');
        try {
            $raw = wp_unslash($_POST['payload'] ?? '');
            if (!is_string($raw) || strlen($raw) > 65536) throw new \RuntimeException(__('Invalid preparation request.', 'paint-nova-poshta-multishipping'));
            $input = json_decode($raw, true);
            if (!is_array($input)) throw new \RuntimeException(__('Invalid preparation request.', 'paint-nova-poshta-multishipping'));
            $service = new ManagerPreparation();
            $order = $service->order(absint($_POST['order_id'] ?? 0));
            $context = $service->context($order);
            $command = sanitize_key($_POST['command'] ?? '');
            if (!hash_equals($context['revision'], (string)($input['revision'] ?? ''))) throw new \RuntimeException(__('The order or preparation changed. Reload and review it again.', 'paint-nova-poshta-multishipping'));
            if ($command === 'confirmation') {
                $recipient = apply_filters('pnpm_manager_confirmed_recipient', null, $order);
                if (!is_array($recipient) || !$recipient) throw new \RuntimeException(__('No current confirmed delivery details are available for this order.', 'paint-nova-poshta-multishipping'));
                wp_send_json_success(['recipient'=>$recipient, 'message'=>__('Imported for review only. Save the preparation separately.', 'paint-nova-poshta-multishipping')]);
            }
            if (!in_array($command, ['save','preview'], true)) throw new \RuntimeException(__('Invalid preparation request.', 'paint-nova-poshta-multishipping'));
            if ($command === 'save') {
                $context = $service->save($order->get_id(), $input);
                wp_send_json_success(['revision'=>$context['revision'], 'message'=>__('Preparation saved. No TTN or notification was sent.', 'paint-nova-poshta-multishipping')]);
            }
            $prepared = $service->prepare($context, $input);
            wp_send_json_success(['preview'=>$prepared, 'blockers'=>$service->blockers($context, $prepared),
                'message'=>__('Preview only; changes are not saved.', 'paint-nova-poshta-multishipping')]);
        } catch (\Throwable $e) { wp_send_json_error(['message'=>$e->getMessage()], 409); }
    }
}
