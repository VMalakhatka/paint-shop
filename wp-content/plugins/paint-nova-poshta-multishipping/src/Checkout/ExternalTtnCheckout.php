<?php

namespace Paint\NovaPoshta\Checkout;

use Paint\NovaPoshta\Domain\ExternalShipmentPolicy as Policy;
use Paint\NovaPoshta\Domain\TtnNormalizer;
use Paint\NovaPoshta\Infrastructure\ExternalShipmentStore;
use Paint\NovaPoshta\Infrastructure\ShipmentRepository;
use WC_Order;

defined('ABSPATH') || exit;

final class ExternalTtnCheckout
{
    private array $validated = [];

    public function hooks(): void
    {
        add_filter('woocommerce_cart_shipping_packages', [$this, 'packageContext']);
        add_filter('woocommerce_package_rates', [$this, 'rates'], 100, 2);
        add_action('woocommerce_after_order_notes', [$this, 'fields'], 25);
        add_action('woocommerce_checkout_update_order_review', [$this, 'capture']);
        add_filter('woocommerce_update_order_review_fragments', [$this, 'fragments']);
        add_action('woocommerce_after_checkout_validation', [$this, 'validate'], 30, 2);
        add_action('woocommerce_checkout_create_order_line_item', [$this, 'stampItem'], 30, 4);
        add_action('woocommerce_checkout_create_order', [$this, 'save'], 30, 2);
        add_action('woocommerce_checkout_order_created', [$this, 'persist'], 1);
        add_filter('woocommerce_available_payment_gateways', [$this, 'gateways'], 1000);
    }

    public function packageContext(array $packages): array
    {
        foreach ($packages as &$package) {
            $package['pnpm_external_context'] = [PNPM_VERSION, Policy::allowed(), hash('sha256', wp_json_encode(get_option('pnpm_location_mappings', [])))];
        }
        return $packages;
    }

    public function rates(array $rates, array $package): array
    {
        unset($rates[Policy::RATE]);
        if (!Policy::allowed() || ($package['destination']['country'] ?? '') !== 'UA') {
            return $rates;
        }
        $plan = Policy::plan();
        if (empty($plan['errors']) && !empty($plan['shipments'])) {
            $rates[Policy::RATE] = new \WC_Shipping_Rate(Policy::RATE, Policy::label(), 0, [], Policy::RATE);
        }
        return $rates;
    }

    public function capture(string $posted): void
    {
        if (!WC()->session) { return; }
        parse_str($posted, $data);
        $values = [];
        if (Policy::allowed() && is_array($data['pnpm_external_ttn'] ?? null)) {
            foreach (array_slice($data['pnpm_external_ttn'], 0, 30, true) as $id => $text) {
                if (is_scalar($text)) {
                    $values[absint($id)] = mb_substr(sanitize_textarea_field((string) $text), 0, 2000);
                }
            }
        }
        WC()->session->set('pnpm_external_input', $values);
    }

    public function fields(): void
    {
        echo '<section id="pnpm-external-fields" class="pnpm-checkout-fields"' . (Policy::selected() ? '' : ' hidden') . '>';
        if (Policy::allowed()) {
            echo '<h3>' . esc_html(Policy::label()) . '</h3><p>';
            esc_html_e('Enter one existing TTN per warehouse. Delivery is 0 UAH in this order; Nova Poshta services are paid under your TTN. The warehouse must approve it before dispatch.', 'paint-nova-poshta-multishipping');
            echo '</p>';
            $plan = Policy::plan();
            $saved = WC()->session ? (array) WC()->session->get('pnpm_external_input', []) : [];
            foreach ($plan['errors'] as $error) { echo '<p>' . esc_html($error) . '</p>'; }
            foreach ($plan['shipments'] as $parcel) {
                $id = (int) $parcel['location_id'];
                $label = $parcel['customer_label'] ?: $parcel['location_name'];
                echo '<div class="pnpm-external-parcel"><h4>' . esc_html($label) . '</h4><ul>';
                foreach ($parcel['items'] as $line) {
                    echo '<li>' . esc_html($line['name'] . ' × ' . wc_format_decimal($line['quantity'])) . '</li>';
                }
                echo '</ul>';
                woocommerce_form_field('pnpm_external_ttn[' . $id . ']', [
                    'id' => 'pnpm-external-' . $id, 'type' => 'textarea', 'class' => ['form-row-wide'],
                    'required' => true,
                    'label' => __('Existing TTN, message or official tracking link', 'paint-nova-poshta-multishipping'),
                    'custom_attributes' => ['maxlength' => '2000', 'rows' => '2', 'autocomplete' => 'off'],
                ], (string) ($saved[$id] ?? ''));
                $number = (new TtnNormalizer())->normalize((string) ($saved[$id] ?? ''));
                echo '<p class="pnpm-extracted-ttn" aria-live="polite">' . (is_string($number) ? esc_html('TTN: ' . $number) : '') . '</p></div>';
            }
            echo '<label><input type="checkbox" name="pnpm_external_confirm" value="1"> ';
            esc_html_e('I checked the TTN numbers and assigned goods for every warehouse.', 'paint-nova-poshta-multishipping');
            echo '</label>';
            if (function_exists('pc_wholesale_help_url')) {
                echo '<p><a href="' . esc_url(pc_wholesale_help_url('customer-ttn')) . '" target="_blank">' . esc_html__('How to submit your TTN', 'paint-nova-poshta-multishipping') . '</a></p>';
            }
        }
        echo '</section>';
    }

    public function fragments(array $fragments): array
    {
        ob_start(); $this->fields();
        $fragments['#pnpm-external-fields'] = ob_get_clean();
        return $fragments;
    }

    public function validate(array $data, \WP_Error $errors): void
    {
        $this->validated = [];
        $methods = (array) ($data['shipping_method'] ?? []);
        $retry_order = WC()->session ? (int) WC()->session->get('order_awaiting_payment', 0) : 0;
        $retry = $retry_order ? wc_get_order($retry_order) : false;
        $existing = $retry && (int) $retry->get_customer_id() === get_current_user_id()
            ? (new ShipmentRepository())->findByOrder($retry_order) : [];
        if (!Policy::selected($methods)) {
            if ($existing) { $errors->add('pnpm_external_locked', __('This order already has shipments. Contact the manager before changing a TTN.', 'paint-nova-poshta-multishipping')); }
            return;
        }
        if (!Policy::allowed() || count($methods) !== 1) {
            $errors->add('pnpm_external_denied', __('This shipping option is available only to signed-in wholesale customers.', 'paint-nova-poshta-multishipping'));
            return;
        }
        if (($data['payment_method'] ?? '') === 'cod') {
            $errors->add('pnpm_external_cod', __('Pay for our order separately. COD on the customer TTN belongs to its sender.', 'paint-nova-poshta-multishipping'));
        }
        $posted = wp_unslash($_POST);
        if (($posted['pnpm_external_confirm'] ?? '') !== '1') {
            $errors->add('pnpm_external_confirm', __('Confirm the TTN numbers and goods for every warehouse.', 'paint-nova-poshta-multishipping'));
        }
        $input = is_array($posted['pnpm_external_ttn'] ?? null) ? $posted['pnpm_external_ttn'] : [];
        $plan = Policy::plan();
        foreach ($plan['errors'] as $error) { $errors->add('pnpm_external_plan', $error); }
        if (!$plan['shipments']) {
            $errors->add('pnpm_external_plan', __('Please ask the manager to arrange this warehouse shipment.', 'paint-nova-poshta-multishipping'));
        }
        $seen = [];
        $same = array_column($existing, 'ttn_number', 'location_id');
        foreach ($plan['shipments'] as $parcel) {
            $id = (int) $parcel['location_id'];
            $text = $input[$id] ?? '';
            $number = (new TtnNormalizer())->normalize(is_scalar($text) ? (string) $text : '');
            $label = $parcel['customer_label'] ?: $parcel['location_name'];
            if (is_wp_error($number)) {
                $errors->add('pnpm_external_number', $label . ': ' . $number->get_error_message());
                continue;
            }
            if (isset($seen[$number]) || ((new ShipmentRepository())->ttnExists($number) && ($same[$id] ?? '') !== $number)) {
                $errors->add('pnpm_external_duplicate', __('A TTN is already in use. Use a separate TTN for each warehouse and order.', 'paint-nova-poshta-multishipping'));
            }
            $seen[$number] = true;
            $this->validated[] = ['location_id' => $id, 'label' => $label, 'ttn' => $number, 'items' => $parcel['items']];
        }
        if ($existing) {
            $new = array_column($this->validated, null, 'location_id');
            $changed = count($existing) !== count($new);
            foreach ($existing as $row) {
                $saved = json_decode((string) $row['request_snapshot'], true);
                $parcel = $new[$row['location_id']] ?? [];
                $changed = $changed || $row['source'] !== 'customer_external' || $row['status'] !== 'submitted'
                    || $row['ttn_number'] !== ($parcel['ttn'] ?? '') || ($saved['order_items'] ?? []) != ($parcel['items'] ?? []);
            }
            if ($changed) { $errors->add('pnpm_external_locked', __('This order already has shipments. Contact the manager before changing a TTN.', 'paint-nova-poshta-multishipping')); }
        }
    }

    public function stampItem($item, string $cart_key, array $values, WC_Order $order): void
    {
        if ($this->validated) { $item->update_meta_data('_pnpm_cart_key', $cart_key); }
    }

    public function save(WC_Order $order, array $data): void
    {
        if (!Policy::selected((array) ($data['shipping_method'] ?? []))) { return; }
        if (!Policy::allowed() || !$this->validated) {
            throw new \RuntimeException(__('Review the customer TTN fields before placing the order.', 'paint-nova-poshta-multishipping'));
        }
        $order->update_meta_data('_pnpm_external_plan', $this->validated);
        $order->update_meta_data('_pnpm_shipment_count', count($this->validated));
    }

    public function persist(WC_Order $order): void
    {
        (new ExternalShipmentStore())->submit($order);
    }

    public function gateways(array $gateways): array
    {
        if (function_exists('WC') && WC()->session && Policy::selected()) { unset($gateways['cod']); }
        $pay_order_id = absint(get_query_var('order-pay'));
        $pay_order = $pay_order_id ? wc_get_order($pay_order_id) : false;
        if ($pay_order && $pay_order->get_meta('_pnpm_external_plan', true)) { unset($gateways['cod']); }
        return $gateways;
    }
}
