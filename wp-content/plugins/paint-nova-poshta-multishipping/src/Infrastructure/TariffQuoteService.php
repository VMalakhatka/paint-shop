<?php

namespace Paint\NovaPoshta\Infrastructure;

use WP_Error;

defined('ABSPATH') || exit;

final class TariffQuoteService
{
    private const CACHE_TTL = 10 * MINUTE_IN_SECONDS;

    public function __construct(private readonly ApiClient $api)
    {
    }

    /** @param array<int,array<string,mixed>> $shipments
     *  @return array{components:array<string,float>,quotes:array<int,array<string,mixed>>,warnings:string[],errors:string[]}
     */
    public function quote(array $shipments, string $recipient_city_ref, string $delivery_type): array
    {
        $components = [
            'base_transport' => 0.0,
            'declared_value' => 0.0,
            'sender_courier' => 0.0,
            'recipient_address' => 0.0,
            'parcel_locker' => 0.0,
            'packaging' => 0.0,
            'extra_shipments' => 0.0,
        ];
        $quotes = [];
        $warnings = [];
        $errors = [];
        $settings = wp_parse_args((array) get_option('pnpm_settings', []), [
            'minimum_declared_cost' => 500.0,
            'parcel_locker_surcharge' => 10.0,
        ]);
        $minimum_cost = max(1.0, (float) $settings['minimum_declared_cost']);

        foreach (array_values($shipments) as $index => $shipment) {
            $sender_city_ref = sanitize_text_field((string) ($shipment['sender_city_ref'] ?? ''));
            $weight = max(0.1, (float) ($shipment['weight_kg'] ?? 0.1));
            $declared = max(1.0, (float) ($shipment['declared_cost'] ?? 0));
            $baseline_declared = min($minimum_cost, $declared);
            $sender_type = ($shipment['sender_type'] ?? 'warehouse') === 'doors' ? 'Doors' : 'Warehouse';
            $recipient_type = $delivery_type === 'address' ? 'Doors' : 'Warehouse';

            $base = $this->price($sender_city_ref, $recipient_city_ref, 'WarehouseWarehouse', $weight, $baseline_declared);
            $valued = $this->price($sender_city_ref, $recipient_city_ref, 'WarehouseWarehouse', $weight, $declared);
            $sender = $sender_type === 'Doors'
                ? $this->price($sender_city_ref, $recipient_city_ref, 'DoorsWarehouse', $weight, $declared)
                : $valued;
            $actual_service = $sender_type . $recipient_type;
            $actual = $actual_service === ($sender_type . 'Warehouse')
                ? $sender
                : $this->price($sender_city_ref, $recipient_city_ref, $actual_service, $weight, $declared);

            foreach ([$base, $valued, $sender, $actual] as $result) {
                if (is_wp_error($result)) {
                    $errors[] = $result->get_error_message();
                    continue 2;
                }
            }

            $base_cost = (float) $base;
            $declared_cost = max(0.0, (float) $valued - $base_cost);
            $sender_cost = max(0.0, (float) $sender - (float) $valued);
            $recipient_cost = max(0.0, (float) $actual - (float) $sender);
            $locker_cost = $delivery_type === 'parcel_locker'
                ? max(0.0, (float) $settings['parcel_locker_surcharge'])
                : 0.0;

            if ($index === 0) {
                $components['base_transport'] += $base_cost;
            } else {
                $components['extra_shipments'] += $base_cost;
            }
            $components['declared_value'] += $declared_cost;
            $components['sender_courier'] += $sender_cost;
            $components['recipient_address'] += $recipient_cost;
            $components['parcel_locker'] += $locker_cost;
            $quotes[] = [
                'location_id' => (int) ($shipment['location_id'] ?? 0),
                'location_label' => (string) (($shipment['customer_label'] ?? '') ?: ($shipment['location_name'] ?? '')),
                'weight_kg' => $weight,
                'declared_cost' => $declared,
                'service_type' => $actual_service,
                'carrier_cost' => round((float) $actual, 2),
                'parcel_locker_surcharge' => round($locker_cost, 2),
            ];
        }

        foreach ($components as &$amount) {
            $amount = round($amount, 2);
        }
        unset($amount);
        return compact('components', 'quotes', 'warnings', 'errors');
    }

    /** @return float|WP_Error */
    private function price(string $sender, string $recipient, string $service, float $weight, float $cost)
    {
        if ($sender === '' || $recipient === '') {
            return new WP_Error('pnpm_quote_missing_city', __('Sender or recipient city is missing.', 'paint-nova-poshta-multishipping'));
        }
        $properties = [
            'CitySender' => $sender,
            'CityRecipient' => $recipient,
            'Weight' => wc_format_decimal($weight, 3),
            'ServiceType' => $service,
            'Cost' => wc_format_decimal($cost, 2),
            'CargoType' => 'Cargo',
            'SeatsAmount' => '1',
        ];
        $cache_key = 'pnpm_price_' . md5(wp_json_encode($properties));
        $cached = get_transient($cache_key);
        if (is_numeric($cached)) {
            return (float) $cached;
        }

        $response = $this->api->call('InternetDocument', 'getDocumentPrice', $properties);
        if (is_wp_error($response)) {
            return $response;
        }
        if (($response['success'] ?? false) !== true || !isset($response['data'][0]['Cost'])) {
            $messages = array_merge((array) ($response['errors'] ?? []), (array) ($response['warnings'] ?? []));
            return new WP_Error(
                'pnpm_quote_rejected',
                $messages
                    ? implode('; ', array_map('sanitize_text_field', $messages))
                    : __('Nova Poshta could not calculate the delivery cost.', 'paint-nova-poshta-multishipping')
            );
        }
        $price = max(0.0, (float) $response['data'][0]['Cost']);
        set_transient($cache_key, $price, self::CACHE_TTL);
        return $price;
    }
}
