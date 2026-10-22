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
        $package = is_array($package) ? $package : [];
        if (($package['destination']['country'] ?? '') !== 'UA') return;
        $calculator = new PolicyCalculator();
        $merchandise_total = max(0.0, (float) ($package['contents_cost'] ?? 0));
        $base_free = $calculator->baseTransportCovered($merchandise_total);
        $label = $base_free
            ? __('Nova Poshta — base tariff covered by us; insurance and extras paid by recipient', 'paint-nova-poshta-multishipping')
            : __('Nova Poshta — carrier tariff, paid separately by recipient', 'paint-nova-poshta-multishipping');
        $threshold = $calculator->baseTransportThreshold();
        if (!$base_free && $threshold !== null) {
            $label .= '. ' . sprintf(
                /* translators: %s: order value threshold in the customer's delivery policy. */
                __('For goods over %s, we cover the base tariff; insurance and extras remain payable separately.', 'paint-nova-poshta-multishipping'),
                wp_strip_all_tags(wc_price($threshold))
            );
        }
        $summary = [
            'recipient' => $recipient, 'shipments' => [], 'quotes' => [], 'errors' => [], 'warnings' => [],
            'estimate_available' => false, 'payment_collection' => 'carrier', 'base_transport_free' => $base_free,
        ];
        // A zero Woo charge means payment to the carrier, not free carrier service.
        $this->add_rate([
            'id' => $this->get_rate_id(),
            'label' => $label,
            'cost' => 0, 'taxes' => false, 'package' => $package,
            'meta_data' => ['pnpm_payment_collection' => 'carrier', 'pnpm_base_transport_free' => $base_free ? 'yes' : 'no'],
        ]);
        WC()->session->set('pnpm_quote', $summary);
        $city_ref = sanitize_text_field((string) ($recipient['city_ref'] ?? ''));
        $delivery_type = sanitize_key((string) ($recipient['delivery_type'] ?? 'branch'));
        if ($city_ref === '' || !in_array($delivery_type, ['branch', 'parcel_locker', 'address'], true)) {
            return;
        }

        $plan = (new CartShipmentBuilder())->build(is_array($package) ? $package : []);
        $summary['shipments'] = $plan['shipments'];
        $summary['warnings'] = $plan['warnings'];
        $summary['policy'] = $calculator->calculate([], $merchandise_total, count($plan['shipments']));
        WC()->session->set('pnpm_quote', $summary);
        if ($plan['errors'] !== [] || $plan['shipments'] === []) {
            $summary['errors'] = $plan['errors'] ?: [__('No Nova Poshta shipments could be prepared.', 'paint-nova-poshta-multishipping')];
            WC()->session->set('pnpm_quote', $summary);
            return;
        }

        foreach ($plan['shipments'] as $shipment) {
            if (empty($shipment['weight_known'])) return;
        }

        $quoted = (new TariffQuoteService(new ApiClient()))->quote($plan['shipments'], $city_ref, $delivery_type);
        if ($quoted['errors'] !== []) {
            $summary['warnings'] = array_merge($plan['warnings'], $quoted['warnings']);
            WC()->session->set('pnpm_quote', $summary);
            return;
        }

        $merchandise_total = max(0.0, (float) ($package['contents_cost'] ?? 0));
        $policy = (new PolicyCalculator())->calculate(
            $quoted['components'],
            $merchandise_total,
            count($plan['shipments'])
        );
        $summary = array_merge($summary, [
            'recipient' => $recipient,
            'shipments' => $plan['shipments'],
            'quotes' => $quoted['quotes'],
            'policy' => $policy,
            'warnings' => array_values(array_unique(array_merge($plan['warnings'], $quoted['warnings']))),
            'errors' => [],
            'estimate_available' => true,
        ]);
        WC()->session->set('pnpm_quote', $summary);
    }

    private function checkoutEnabled(): bool
    {
        $settings = get_option('pnpm_settings', []);
        return !is_array($settings) || ($settings['checkout_enabled'] ?? 'yes') === 'yes';
    }
}
