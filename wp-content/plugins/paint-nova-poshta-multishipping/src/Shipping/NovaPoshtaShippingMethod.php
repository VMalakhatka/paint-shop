<?php

namespace Paint\NovaPoshta\Shipping;

use Paint\NovaPoshta\Domain\CartShipmentBuilder;
use Paint\NovaPoshta\Domain\PolicyCalculator;
use Paint\NovaPoshta\Infrastructure\ApiClient;
use Paint\NovaPoshta\Infrastructure\TariffQuoteService;

defined('ABSPATH') || exit;

final class NovaPoshtaShippingMethod extends \WC_Shipping_Method
{
    public function __construct(int $instance_id = 0)
    {
        $this->id = 'pnpm_nova_poshta';
        $this->instance_id = absint($instance_id);
        $this->method_title = __('Nova Poshta multishipping', 'paint-nova-poshta-multishipping');
        $this->method_description = __('Delivery by Nova Poshta from one or more warehouses.', 'paint-nova-poshta-multishipping');
        $this->supports = ['shipping-zones', 'instance-settings', 'instance-settings-modal'];
        $this->init();
    }

    private function init(): void
    {
        $this->instance_form_fields = [
            'enabled' => [
                'title' => __('Enable', 'paint-nova-poshta-multishipping'),
                'type' => 'checkbox',
                'label' => __('Enable Nova Poshta delivery', 'paint-nova-poshta-multishipping'),
                'default' => 'yes',
            ],
            'title' => [
                'title' => __('Title', 'paint-nova-poshta-multishipping'),
                'type' => 'text',
                'default' => __('Nova Poshta delivery', 'paint-nova-poshta-multishipping'),
            ],
        ];
        $this->enabled = (string) $this->get_option('enabled', 'yes');
        $this->title = (string) $this->get_option('title', __('Nova Poshta delivery', 'paint-nova-poshta-multishipping'));
        add_action('woocommerce_update_options_shipping_' . $this->id, [$this, 'process_admin_options']);
    }

    public function calculate_shipping($package = []): void
    {
        if ($this->enabled !== 'yes' || !$this->checkoutEnabled() || !WC()->session) {
            return;
        }
        $recipient = (array) WC()->session->get('pnpm_recipient', []);
        $city_ref = sanitize_text_field((string) ($recipient['city_ref'] ?? ''));
        $delivery_type = sanitize_key((string) ($recipient['delivery_type'] ?? 'branch'));
        if ($city_ref === '' || !in_array($delivery_type, ['branch', 'parcel_locker', 'address'], true)) {
            WC()->session->set('pnpm_quote', []);
            return;
        }

        $plan = (new CartShipmentBuilder())->build(is_array($package) ? $package : []);
        if ($plan['errors'] !== [] || $plan['shipments'] === []) {
            WC()->session->set('pnpm_quote', [
                'errors' => $plan['errors'] ?: [__('No Nova Poshta shipments could be prepared.', 'paint-nova-poshta-multishipping')],
                'warnings' => $plan['warnings'],
            ]);
            return;
        }

        $quoted = (new TariffQuoteService(new ApiClient()))->quote($plan['shipments'], $city_ref, $delivery_type);
        if ($quoted['errors'] !== []) {
            WC()->session->set('pnpm_quote', [
                'errors' => $quoted['errors'],
                'warnings' => array_merge($plan['warnings'], $quoted['warnings']),
            ]);
            return;
        }

        $merchandise_total = max(0.0, (float) ($package['contents_cost'] ?? 0));
        $policy = (new PolicyCalculator())->calculate(
            $quoted['components'],
            $merchandise_total,
            count($plan['shipments'])
        );
        $summary = [
            'recipient' => $recipient,
            'shipments' => $plan['shipments'],
            'quotes' => $quoted['quotes'],
            'policy' => $policy,
            'warnings' => array_values(array_unique(array_merge($plan['warnings'], $quoted['warnings']))),
            'errors' => [],
        ];
        WC()->session->set('pnpm_quote', $summary);

        $label = sprintf(
            /* translators: 1: shipping method title, 2: parcel count. */
            _n('%1$s — %2$d parcel', '%1$s — %2$d parcels', count($plan['shipments']), 'paint-nova-poshta-multishipping'),
            $this->title,
            count($plan['shipments'])
        );
        $this->add_rate([
            'id' => $this->get_rate_id(),
            'label' => $label,
            'cost' => (float) $policy['customer_total'],
            'taxes' => false,
            'package' => $package,
            'meta_data' => [
                'pnpm_shipment_count' => count($plan['shipments']),
                'pnpm_carrier_total' => (float) $policy['total'],
                'pnpm_store_contribution' => (float) $policy['store_total'],
            ],
        ]);
    }

    private function checkoutEnabled(): bool
    {
        $settings = get_option('pnpm_settings', []);
        return !is_array($settings) || ($settings['checkout_enabled'] ?? 'yes') === 'yes';
    }
}
