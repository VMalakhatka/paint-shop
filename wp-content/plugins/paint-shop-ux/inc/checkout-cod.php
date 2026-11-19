<?php
defined('ABSPATH') || exit;

/** Use the native Woo COD gateway; never enable it over an administrator's setting. */
function psu_checkout_cod_allowed(?array $methods = null): bool {
    if (!function_exists('WC') || !WC()->cart || !WC()->session || !WC()->cart->needs_shipping()
        || is_wc_endpoint_url('order-pay')
        || !class_exists(\Paint\NovaPoshta\Domain\CartShipmentBuilder::class)
        || !class_exists(\Paint\NovaPoshta\Domain\PolicyCalculator::class)) return false;

    $settings = (array) get_option('pnpm_settings', []);
    if (($settings['checkout_enabled'] ?? 'yes') !== 'yes') return false;
    $methods ??= (array) WC()->session->get('chosen_shipping_methods', []);
    $packages = WC()->shipping()->get_packages();
    // The carrier module stores one checkout quote. Multiple Woo packages or
    // multiple warehouse parcels need a separate, approved COD allocation policy.
    if (count($packages) !== 1 || count($methods) !== 1) return false;
    $index = array_key_first($packages);
    $package = $packages[$index];
    $id = $methods[$index] ?? null;
    if (!is_string($id)) return false;
    $rate = $package['rates'][$id] ?? null;
    if (!$rate instanceof WC_Shipping_Rate || $rate->get_method_id() !== 'pnpm_nova_poshta') return false;

    $quote = (array) WC()->session->get('pnpm_quote', []);
    $recipient = (array) WC()->session->get('pnpm_recipient', []);
    if (!$recipient || ($quote['recipient'] ?? []) !== $recipient || !empty($quote['errors'])
        || !is_array($quote['shipments'] ?? null) || count($quote['shipments']) !== 1
        || ($quote['policy']['cod_allowed'] ?? false) !== true) return false;

    // Recheck allocation and role policy, even when Woo restored cached rates.
    // These domain services only read local data; no carrier request is made.
    $plan = (new \Paint\NovaPoshta\Domain\CartShipmentBuilder())->build($package);
    if (!empty($plan['errors']) || count($plan['shipments']) !== 1) return false;
    $policy = (new \Paint\NovaPoshta\Domain\PolicyCalculator())->calculate([], max(0, (float) ($package['contents_cost'] ?? 0)), 1);
    return ($policy['cod_allowed'] ?? false) === true;
}

function psu_checkout_cod_gateways(array $gateways): array {
    if (is_admin() && !wp_doing_ajax()) return $gateways;
    if (isset($gateways['cod']) && !psu_checkout_cod_allowed()) unset($gateways['cod']);
    return $gateways;
}
// Run after NP's policy and external-TTN filters. Never reinsert a removed gateway.
add_filter('woocommerce_available_payment_gateways', 'psu_checkout_cod_gateways', 1100);

function psu_checkout_cod_validate(array $data, WP_Error $errors): void {
    if (($data['payment_method'] ?? '') === 'cod'
        && !psu_checkout_cod_allowed((array) ($data['shipping_method'] ?? []))) {
        $errors->add('psu_cod_unavailable', __('Payment on receipt is available only for an eligible single Nova Poshta parcel. Check delivery or choose another payment method.', 'paint-shop-ux'));
    }
}
add_action('woocommerce_after_checkout_validation', 'psu_checkout_cod_validate', 1100, 2);

add_filter('woocommerce_gateway_title', static function ($title, $id) {
    return $id === 'cod' ? __('Payment on receipt (Nova Poshta)', 'paint-shop-ux') : $title;
}, 20, 2);
add_filter('woocommerce_gateway_description', static function ($description, $id) {
    return $id === 'cod'
        ? __('Pay for the goods when receiving your Nova Poshta parcel. The manager confirms the collection amount before shipment. Delivery and money-transfer fees are separate and follow the agreed delivery terms.', 'paint-shop-ux')
        : $description;
}, 20, 2);

function psu_checkout_cod_order_status($status, $order) {
    if (!$order instanceof WC_Order || $order->get_payment_method() !== 'cod'
        || $order->get_date_paid() || $order->get_meta('_pnpm_external_plan', true)) return $status;
    $shipping = $order->get_shipping_methods();
    if (!$shipping) return $status;
    foreach ($shipping as $item) {
        if ($item->get_method_id() !== 'pnpm_nova_poshta') return $status;
    }
    // Native COD otherwise uses processing, which WC_Order::is_paid treats as paid.
    return 'on-hold';
}
add_filter('woocommerce_cod_process_payment_order_status', 'psu_checkout_cod_order_status', 1100, 2);
