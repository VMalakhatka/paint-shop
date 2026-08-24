<?php

namespace Paint\NovaPoshta\Checkout;

use Paint\NovaPoshta\Infrastructure\ApiClient;
use Paint\NovaPoshta\Infrastructure\RecipientDirectory;
use Paint\NovaPoshta\Infrastructure\WarehouseDirectory;
use WC_Order;

defined('ABSPATH') || exit;

final class CheckoutIntegration
{
    public function __construct(
        private readonly RecipientDirectory $recipients,
        private readonly WarehouseDirectory $warehouses
    ) {
    }

    public static function create(): self
    {
        $api = new ApiClient();
        return new self(new RecipientDirectory($api), new WarehouseDirectory($api));
    }

    public function hooks(): void
    {
        add_action('wp_enqueue_scripts', [$this, 'assets']);
        add_action('woocommerce_after_order_notes', [$this, 'renderFields']);
        add_action('woocommerce_checkout_update_order_review', [$this, 'captureState']);
        add_action('woocommerce_after_checkout_validation', [$this, 'validate'], 10, 2);
        add_action('woocommerce_checkout_create_order', [$this, 'saveOrder'], 20, 2);
        add_filter('woocommerce_available_payment_gateways', [$this, 'filterPaymentGateways'], 900);
        add_action('woocommerce_review_order_after_shipping', [$this, 'renderQuoteSummary']);
        add_action('wp_ajax_pnpm_search_recipient_cities', [$this, 'searchCities']);
        add_action('wp_ajax_nopriv_pnpm_search_recipient_cities', [$this, 'searchCities']);
        add_action('wp_ajax_pnpm_search_recipient_points', [$this, 'searchPoints']);
        add_action('wp_ajax_nopriv_pnpm_search_recipient_points', [$this, 'searchPoints']);
    }

    public function assets(): void
    {
        if (!function_exists('is_checkout') || !is_checkout() || is_order_received_page()) {
            return;
        }
        wp_enqueue_style('pnpm-checkout', PNPM_URL . 'assets/checkout.css', [], PNPM_VERSION);
        wp_enqueue_script('pnpm-checkout', PNPM_URL . 'assets/checkout.js', ['jquery', 'wc-checkout'], PNPM_VERSION, true);
        wp_localize_script('pnpm-checkout', 'pnpmCheckout', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('pnpm_checkout_directory'),
            'searching' => __('Searching Nova Poshta...', 'paint-nova-poshta-multishipping'),
            'nothingFound' => __('Nothing was found. Refine the search.', 'paint-nova-poshta-multishipping'),
            'requestFailed' => __('Nova Poshta directory could not be loaded.', 'paint-nova-poshta-multishipping'),
            'branchLabel' => __('Nova Poshta branch', 'paint-nova-poshta-multishipping'),
            'parcelLockerLabel' => __('Nova Poshta parcel locker', 'paint-nova-poshta-multishipping'),
        ]);
    }

    public function renderFields($checkout): void
    {
        if (!$this->checkoutEnabled()) {
            return;
        }
        $state = WC()->session ? (array) WC()->session->get('pnpm_recipient', []) : [];
        echo '<section class="pnpm-checkout-fields" id="pnpm-checkout-fields">';
        echo '<h3>' . esc_html__('Nova Poshta delivery', 'paint-nova-poshta-multishipping') . '</h3>';
        echo '<p class="pnpm-checkout-intro">' . esc_html__('Choose the destination. The site will calculate every warehouse parcel and apply your delivery policy.', 'paint-nova-poshta-multishipping') . '</p>';

        woocommerce_form_field('pnpm_city_label', [
            'type' => 'text',
            'required' => false,
            'class' => ['form-row-wide', 'pnpm-directory-field'],
            'label' => __('City or settlement', 'paint-nova-poshta-multishipping'),
            'placeholder' => __('Start typing and choose a city from the list', 'paint-nova-poshta-multishipping'),
            'custom_attributes' => ['autocomplete' => 'off'],
        ], (string) ($state['city_label'] ?? ''));
        echo '<input type="hidden" id="pnpm_city_ref" name="pnpm_city_ref" value="' . esc_attr((string) ($state['city_ref'] ?? '')) . '">';
        echo '<div class="pnpm-directory-results" id="pnpm-city-results" hidden></div>';

        woocommerce_form_field('pnpm_delivery_type', [
            'type' => 'select',
            'required' => false,
            'class' => ['form-row-wide'],
            'label' => __('How to receive the parcel', 'paint-nova-poshta-multishipping'),
            'options' => [
                'branch' => __('Nova Poshta branch', 'paint-nova-poshta-multishipping'),
                'parcel_locker' => __('Nova Poshta parcel locker', 'paint-nova-poshta-multishipping'),
                'address' => __('Courier delivery to the address', 'paint-nova-poshta-multishipping'),
            ],
        ], (string) ($state['delivery_type'] ?? 'branch'));

        echo '<div id="pnpm-point-fields">';
        woocommerce_form_field('pnpm_point_label', [
            'type' => 'text',
            'required' => false,
            'class' => ['form-row-wide', 'pnpm-directory-field'],
            'label' => __('Branch or parcel locker', 'paint-nova-poshta-multishipping'),
            'placeholder' => __('Enter a number or address and choose from the list', 'paint-nova-poshta-multishipping'),
            'custom_attributes' => ['autocomplete' => 'off'],
        ], (string) ($state['point_label'] ?? ''));
        echo '<input type="hidden" id="pnpm_point_ref" name="pnpm_point_ref" value="' . esc_attr((string) ($state['point_ref'] ?? '')) . '">';
        echo '<div class="pnpm-directory-results" id="pnpm-point-results" hidden></div></div>';
        echo '<p class="pnpm-address-help" id="pnpm-address-help">' . esc_html__('For courier delivery, fill in the shipping street and building in the standard address fields above.', 'paint-nova-poshta-multishipping') . '</p>';
        echo '</section>';
    }

    public function captureState(string $posted_data): void
    {
        if (!WC()->session) {
            return;
        }
        parse_str($posted_data, $data);
        $state = $this->sanitizeState($data);
        $previous = (array) WC()->session->get('pnpm_recipient', []);
        WC()->session->set('pnpm_recipient', $state);
        if ($previous !== $state) {
            foreach (array_keys((array) (WC()->cart?->get_shipping_packages() ?? [])) as $package_index) {
                WC()->session->set('shipping_for_package_' . $package_index, false);
            }
            WC()->session->set('pnpm_quote', []);
        }
    }

    public function validate(array $data, \WP_Error $errors): void
    {
        if (!$this->novaPoshtaSelected()) {
            return;
        }
        $posted = wp_unslash($_POST);
        $state = $this->sanitizeState($data + (is_array($posted) ? $posted : []));
        if ($state['city_ref'] === '') {
            $errors->add('pnpm_city_required', __('Choose a city from the Nova Poshta list.', 'paint-nova-poshta-multishipping'));
        }
        if ($state['delivery_type'] !== 'address' && $state['point_ref'] === '') {
            $errors->add('pnpm_point_required', __('Choose a Nova Poshta branch or parcel locker from the list.', 'paint-nova-poshta-multishipping'));
        }
        if ($state['delivery_type'] === 'address') {
            $different = !empty($data['ship_to_different_address']);
            $address = trim((string) ($data[$different ? 'shipping_address_1' : 'billing_address_1'] ?? ''));
            if ($address === '') {
                $errors->add('pnpm_address_required', __('Enter the courier delivery street and building.', 'paint-nova-poshta-multishipping'));
            }
        }
        $quote = WC()->session ? (array) WC()->session->get('pnpm_quote', []) : [];
        foreach ((array) ($quote['errors'] ?? []) as $message) {
            $errors->add('pnpm_quote_error', sanitize_text_field((string) $message));
        }
        if (empty($quote['policy'])) {
            $errors->add('pnpm_quote_missing', __('Delivery has not been calculated yet. Check the Nova Poshta destination.', 'paint-nova-poshta-multishipping'));
        }
    }

    public function saveOrder(WC_Order $order, array $data): void
    {
        if (!$this->novaPoshtaSelected()) {
            return;
        }
        $state = WC()->session ? (array) WC()->session->get('pnpm_recipient', []) : $this->sanitizeState($data);
        $quote = WC()->session ? (array) WC()->session->get('pnpm_quote', []) : [];
        $order->update_meta_data('_pnpm_recipient', $state);
        $order->update_meta_data('_pnpm_checkout_quote', $quote);
        $order->update_meta_data('_pnpm_shipment_count', count((array) ($quote['shipments'] ?? [])));
    }

    public function filterPaymentGateways(array $gateways): array
    {
        if ((is_admin() && !wp_doing_ajax()) || !$this->novaPoshtaSelected() || !isset($gateways['cod']) || !WC()->session) {
            return $gateways;
        }
        $quote = (array) WC()->session->get('pnpm_quote', []);
        if (isset($quote['policy']['cod_allowed']) && !$quote['policy']['cod_allowed']) {
            unset($gateways['cod']);
        }
        return $gateways;
    }

    public function renderQuoteSummary(): void
    {
        if (!WC()->session) {
            return;
        }
        $quote = (array) WC()->session->get('pnpm_quote', []);
        $quote_errors = array_values(array_filter(array_map('sanitize_text_field', (array) ($quote['errors'] ?? []))));
        if ($quote_errors !== []) {
            echo '<tr class="pnpm-checkout-summary pnpm-checkout-summary-error"><th>' . esc_html__('Nova Poshta calculation', 'paint-nova-poshta-multishipping') . '</th><td>';
            echo '<span class="pnpm-cod-note">' . esc_html(implode(' ', $quote_errors)) . '</span></td></tr>';
            return;
        }
        if (!$this->novaPoshtaSelected()) {
            return;
        }
        $policy = is_array($quote['policy'] ?? null) ? $quote['policy'] : [];
        if ($policy === []) {
            return;
        }
        $shipments = count((array) ($quote['shipments'] ?? []));
        echo '<tr class="pnpm-checkout-summary"><th>' . esc_html__('Nova Poshta calculation', 'paint-nova-poshta-multishipping') . '</th><td>';
        echo '<strong>' . esc_html(sprintf(
            /* translators: %d: parcel count. */
            _n('%d parcel', '%d parcels', $shipments, 'paint-nova-poshta-multishipping'),
            $shipments
        )) . '</strong><br>';
        echo esc_html(sprintf(
            /* translators: 1: carrier total, 2: store contribution. */
            __('Carrier total: %1$s. Store contribution: %2$s.', 'paint-nova-poshta-multishipping'),
            wp_strip_all_tags(wc_price((float) ($policy['total'] ?? 0))),
            wp_strip_all_tags(wc_price((float) ($policy['store_total'] ?? 0)))
        ));
        if (isset($policy['cod_allowed']) && !$policy['cod_allowed']) {
            echo '<br><span class="pnpm-cod-note">' . esc_html__('Cash on delivery is unavailable for this multi-warehouse delivery. Choose prepayment.', 'paint-nova-poshta-multishipping') . '</span>';
        }
        echo '</td></tr>';
    }

    public function searchCities(): void
    {
        check_ajax_referer('pnpm_checkout_directory', 'nonce');
        $result = $this->recipients->searchCities(wp_unslash((string) ($_GET['query'] ?? '')));
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()], 400);
        }
        wp_send_json_success(['items' => $result]);
    }

    public function searchPoints(): void
    {
        check_ajax_referer('pnpm_checkout_directory', 'nonce');
        $city_ref = sanitize_text_field(wp_unslash((string) ($_GET['cityRef'] ?? '')));
        $query = sanitize_text_field(wp_unslash((string) ($_GET['query'] ?? '')));
        $kind = sanitize_key(wp_unslash((string) ($_GET['kind'] ?? 'branch')));
        $result = $this->warehouses->search($city_ref, $query);
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()], 400);
        }
        $expected = $kind === 'parcel_locker' ? 'postomat' : 'branch';
        $result = array_values(array_filter($result, static fn(array $item): bool => ($item['kind'] ?? '') === $expected));
        wp_send_json_success(['items' => $result]);
    }

    /** @param array<string,mixed> $data @return array<string,string> */
    private function sanitizeState(array $data): array
    {
        $delivery_type = sanitize_key((string) ($data['pnpm_delivery_type'] ?? 'branch'));
        if (!in_array($delivery_type, ['branch', 'parcel_locker', 'address'], true)) {
            $delivery_type = 'branch';
        }
        return [
            'city_label' => sanitize_text_field((string) ($data['pnpm_city_label'] ?? '')),
            'city_ref' => sanitize_text_field((string) ($data['pnpm_city_ref'] ?? '')),
            'delivery_type' => $delivery_type,
            'point_label' => sanitize_text_field((string) ($data['pnpm_point_label'] ?? '')),
            'point_ref' => sanitize_text_field((string) ($data['pnpm_point_ref'] ?? '')),
        ];
    }

    private function novaPoshtaSelected(): bool
    {
        if (!function_exists('WC') || !WC() || !WC()->session) {
            return false;
        }
        $chosen = (array) WC()->session->get('chosen_shipping_methods', []);
        return isset($chosen[0]) && str_starts_with((string) $chosen[0], 'pnpm_nova_poshta');
    }

    private function checkoutEnabled(): bool
    {
        $settings = get_option('pnpm_settings', []);
        return !is_array($settings) || ($settings['checkout_enabled'] ?? 'yes') === 'yes';
    }
}
