<?php

namespace Paint\NovaPoshta\Shipping;

defined('ABSPATH') || exit;

/** Replace legacy NP rates only in packages served by our configured method. */
final class CheckoutRates
{
    public function hooks(): void
    {
        add_filter('woocommerce_package_rates', [$this, 'filter'], 1000, 2);
        add_filter('woocommerce_shipping_chosen_method', [$this, 'defaultMethod'], 1000, 3);
        add_filter('woocommerce_cart_shipping_packages', [$this, 'cacheVersion'], 1000);
        add_filter('woocommerce_shipping_free_shipping_is_available', [$this, 'freeShippingAvailable'], 1000, 2);
    }

    public function freeShippingAvailable(bool $available, array $package): bool
    {
        if (!$available || ($package['destination']['country'] ?? '') !== 'UA') return $available;
        $settings = get_option('pnpm_settings', []);
        if (is_array($settings) && ($settings['checkout_enabled'] ?? 'yes') !== 'yes') return $available;
        $zone = \WC_Shipping_Zones::get_zone_matching_package($package);
        foreach ($zone->get_shipping_methods(true) as $method) {
            if ($method->id === 'pnpm_nova_poshta') return false;
        }
        return $available;
    }

    public function cacheVersion(array $packages): array
    {
        foreach ($packages as &$package) {
            $package['pnpm_checkout_version'] = PNPM_VERSION;
            $package['pnpm_policy_hash'] = md5(wp_json_encode(\Paint\NovaPoshta\Domain\DeliveryPolicy::load()));
            $package['pnpm_policy_roles'] = wp_get_current_user()->roles;
        }
        unset($package);
        return $packages;
    }

    public function filter(array $rates, array $package): array
    {
        if (($package['destination']['country'] ?? '') !== 'UA') return $rates;
        $nova = array_filter($rates, static fn($rate) => $rate->get_method_id() === 'pnpm_nova_poshta');
        if (!$nova) return $rates;
        // This checkout offers NP or allocation-backed pickup only. Legacy rates
        // can have arbitrary IDs (including flat_rate and custom methods).
        $rates = $nova;
        $locations = [];
        foreach ((array) ($package['contents'] ?? []) as $item) {
            $product = $item['data'] ?? null;
            if (!$product instanceof \WC_Product || !$product->needs_shipping()) continue;
            $quantity = (float) ($item['quantity'] ?? 0);
            $plan = (array) ($item['pc_alloc_plan'] ?? []);
            if (!$plan && function_exists('pc_calc_plan_for')) $plan = (array) pc_calc_plan_for($product, (int) $quantity);
            $plan = array_filter($plan, static fn($q) => (float) $q > 0);
            if (!$plan || abs(array_sum($plan) - $quantity) > 0.000001) return $rates;
            foreach ($plan as $location => $q) $locations[(int) $location] = true;
        }
        // Never imply that stock in another city is transferred for pickup.
        if (count($locations) === 1) {
            $location = (int) array_key_first($locations);
            $term = get_term($location, 'location');
            if ($term && !is_wp_error($term)) {
                $id = 'pnpm_pickup:' . $location;
                $rate = new \WC_Shipping_Rate($id, sprintf(
                    /* translators: %s: selected warehouse name. */
                    __('Store pickup — %s, free (wait for confirmation)', 'paint-nova-poshta-multishipping'),
                    $term->name
                ), 0, [], 'local_pickup');
                $rate->add_meta_data('pnpm_pickup_location_id', $location);
                $rates[$id] = $rate;
            }
        }
        return $nova + $rates;
    }

    public function defaultMethod($default, array $rates, $previous): string
    {
        if (is_string($previous) && isset($rates[$previous])) return $previous;
        foreach ($rates as $id => $rate) if ($rate->get_method_id() === 'pnpm_nova_poshta') return (string) $id;
        return (string) $default;
    }
}
