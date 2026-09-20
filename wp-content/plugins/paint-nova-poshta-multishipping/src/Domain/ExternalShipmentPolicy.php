<?php

namespace Paint\NovaPoshta\Domain;

defined('ABSPATH') || exit;

final class ExternalShipmentPolicy
{
    public const RATE = 'pnpm_customer_ttn';

    public static function allowed(): bool
    {
        $settings = (array) get_option('pnpm_settings', []);
        if (($settings['external_ttn_enabled'] ?? 'yes') !== 'yes'
            || ($settings['checkout_enabled'] ?? 'yes') !== 'yes' || !is_user_logged_in()) {
            return false;
        }
        $roles = function_exists('pc_wholesale_customer_roles')
            ? pc_wholesale_customer_roles() : ['partner', 'opt', 'opt_osn', 'schule'];
        return (bool) array_intersect($roles, (array) wp_get_current_user()->roles);
    }

    public static function selected(?array $methods = null): bool
    {
        $methods ??= WC()->session ? (array) WC()->session->get('chosen_shipping_methods', []) : [];
        return in_array(self::RATE, $methods, true);
    }

    /** The current server-side allocation, never an allocation posted by the browser. */
    public static function plan(): array
    {
        $packages = WC()->cart ? WC()->cart->get_shipping_packages() : [];
        // One logical Woo package may contain several physical warehouse parcels.
        if (count($packages) !== 1) {
            return ['shipments' => [], 'errors' => [__('Please ask the manager to arrange this warehouse shipment.', 'paint-nova-poshta-multishipping')]];
        }
        return (new CartShipmentBuilder())->build(reset($packages));
    }

    public static function label(): string
    {
        return __('Ship using my Nova Poshta TTN', 'paint-nova-poshta-multishipping');
    }
}
