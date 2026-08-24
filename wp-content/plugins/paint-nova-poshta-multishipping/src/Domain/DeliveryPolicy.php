<?php

namespace Paint\NovaPoshta\Domain;

defined('ABSPATH') || exit;

final class DeliveryPolicy
{
    public const OPTION_NAME = 'pnpm_delivery_policy_v1';

    /** @return array<string,mixed> */
    public static function defaults(): array
    {
        $customer_pays = [
            'mode' => 'customer',
            'value' => 0.0,
            'cap' => 0.0,
        ];
        $components = array_fill_keys(array_keys(self::componentLabels()), 'customer');

        return [
            'schema_version' => 1,
            'role_segments' => [
                'guest' => 'retail',
                'customer' => 'retail',
                'subscriber' => 'retail',
                'internet_client' => 'retail',
                'partner' => 'partner',
                'opt' => 'partner',
                'opt_osn' => 'partner',
                'schule' => 'partner',
            ],
            'profiles' => [
                'retail' => [
                    'threshold' => 1200.0,
                    'below' => $customer_pays,
                    'above' => $customer_pays,
                    'components' => $components,
                    'cod' => [
                        'single_shipment' => 'yes',
                        'multi_shipment' => 'no',
                        'fee_payer' => 'customer',
                    ],
                ],
                'partner' => [
                    'threshold' => 1200.0,
                    'below' => $customer_pays,
                    'above' => $customer_pays,
                    'components' => $components,
                    'cod' => [
                        'single_shipment' => 'yes',
                        'multi_shipment' => 'no',
                        'fee_payer' => 'customer',
                    ],
                ],
            ],
        ];
    }

    /** @param list<string> $available_roles
     *  @return array<string,mixed>
     */
    public static function load(array $available_roles = []): array
    {
        $stored = get_option(self::OPTION_NAME, []);

        return self::sanitize(is_array($stored) ? $stored : [], $available_roles);
    }

    /** @param array<string,mixed> $raw
     *  @param list<string> $available_roles
     *  @return array<string,mixed>
     */
    public static function sanitize(array $raw, array $available_roles = []): array
    {
        $defaults = self::defaults();
        $allowed_roles = ['guest' => true];
        foreach ($available_roles as $role) {
            $role = sanitize_key($role);
            if ($role !== '') {
                $allowed_roles[$role] = true;
            }
        }

        $role_segments = [];
        $raw_segments = is_array($raw['role_segments'] ?? null) ? $raw['role_segments'] : [];
        $segments_to_read = array_key_exists('role_segments', $raw)
            ? $raw_segments
            : $defaults['role_segments'];
        foreach ($segments_to_read as $role => $segment) {
            $role = sanitize_key((string) $role);
            if ($role === '' || ($available_roles && !isset($allowed_roles[$role]))) {
                continue;
            }
            if (in_array($segment, ['retail', 'partner'], true)) {
                $role_segments[$role] = $segment;
            }
        }

        $profiles = [];
        $raw_profiles = is_array($raw['profiles'] ?? null) ? $raw['profiles'] : [];
        foreach (['retail', 'partner'] as $profile_key) {
            $profile_defaults = $defaults['profiles'][$profile_key];
            $profile = is_array($raw_profiles[$profile_key] ?? null)
                ? $raw_profiles[$profile_key]
                : [];
            $components = [];
            $raw_components = is_array($profile['components'] ?? null) ? $profile['components'] : [];
            foreach (array_keys(self::componentLabels()) as $component_key) {
                $payer = (string) ($raw_components[$component_key] ?? $profile_defaults['components'][$component_key]);
                $components[$component_key] = in_array($payer, ['customer', 'store', 'budget'], true)
                    ? $payer
                    : 'customer';
            }

            $raw_cod = is_array($profile['cod'] ?? null) ? $profile['cod'] : [];
            $fee_payer = (string) ($raw_cod['fee_payer'] ?? $profile_defaults['cod']['fee_payer']);
            $profiles[$profile_key] = [
                'threshold' => self::number($profile['threshold'] ?? $profile_defaults['threshold'], 0.0, 100000000.0),
                'below' => self::sanitizeTier($profile['below'] ?? [], $profile_defaults['below']),
                'above' => self::sanitizeTier($profile['above'] ?? [], $profile_defaults['above']),
                'components' => $components,
                'cod' => [
                    'single_shipment' => ($raw_cod['single_shipment'] ?? $profile_defaults['cod']['single_shipment']) === 'yes' ? 'yes' : 'no',
                    'multi_shipment' => ($raw_cod['multi_shipment'] ?? $profile_defaults['cod']['multi_shipment']) === 'yes' ? 'yes' : 'no',
                    'fee_payer' => in_array($fee_payer, ['customer', 'store', 'budget'], true) ? $fee_payer : 'customer',
                ],
            ];
        }

        return [
            'schema_version' => 1,
            'role_segments' => $role_segments,
            'profiles' => $profiles,
        ];
    }

    /** @return array<string,string> */
    public static function componentLabels(): array
    {
        return [
            'base_transport' => __('Base transport tariff', 'paint-nova-poshta-multishipping'),
            'declared_value' => __('Declared value commission', 'paint-nova-poshta-multishipping'),
            'sender_courier' => __('Courier pickup from the sender', 'paint-nova-poshta-multishipping'),
            'recipient_address' => __('Courier delivery to the recipient', 'paint-nova-poshta-multishipping'),
            'parcel_locker' => __('Parcel locker surcharge', 'paint-nova-poshta-multishipping'),
            'packaging' => __('Packaging and packing service', 'paint-nova-poshta-multishipping'),
            'extra_shipments' => __('Second and subsequent warehouse shipments', 'paint-nova-poshta-multishipping'),
        ];
    }

    /** @param mixed $raw
     *  @param array<string,mixed> $defaults
     *  @return array<string,mixed>
     */
    private static function sanitizeTier(mixed $raw, array $defaults): array
    {
        $raw = is_array($raw) ? $raw : [];
        $mode = (string) ($raw['mode'] ?? $defaults['mode']);
        if (!in_array($mode, ['customer', 'store', 'fixed', 'order_percent', 'delivery_percent'], true)) {
            $mode = 'customer';
        }
        $value_maximum = in_array($mode, ['order_percent', 'delivery_percent'], true)
            ? 100.0
            : 100000000.0;

        return [
            'mode' => $mode,
            'value' => self::number($raw['value'] ?? $defaults['value'], 0.0, $value_maximum),
            'cap' => self::number($raw['cap'] ?? $defaults['cap'], 0.0, 100000000.0),
        ];
    }

    private static function number(mixed $value, float $minimum, float $maximum): float
    {
        $normalized = str_replace(',', '.', trim((string) $value));
        if ($normalized === '' || !is_numeric($normalized)) {
            return $minimum;
        }

        return min($maximum, max($minimum, round((float) $normalized, 2)));
    }
}
