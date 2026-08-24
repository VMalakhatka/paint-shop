<?php

namespace Paint\NovaPoshta\Domain;

use WC_Product;

defined('ABSPATH') || exit;

final class CartShipmentBuilder
{
    /** @return array{shipments:array<int,array<string,mixed>>,warnings:string[],errors:string[]} */
    public function build(array $package): array
    {
        $shipments = [];
        $warnings = [];
        $errors = [];
        $settings = $this->settings();
        $mappings = get_option('pnpm_location_mappings', []);
        $mappings = is_array($mappings) ? $mappings : [];

        foreach ((array) ($package['contents'] ?? []) as $cart_key => $item) {
            $product = $item['data'] ?? null;
            $quantity = (float) ($item['quantity'] ?? 0);
            if (!$product instanceof WC_Product || $quantity <= 0 || !$product->needs_shipping()) {
                continue;
            }

            $allocations = $this->allocations($item, $product, $quantity);
            if ($allocations === []) {
                $errors[] = sprintf(
                    /* translators: %s: product SKU. */
                    __('No warehouse allocation was found for %s.', 'paint-nova-poshta-multishipping'),
                    $product->get_sku() ?: $product->get_name()
                );
                continue;
            }

            $allocated = array_sum($allocations);
            if (abs($allocated - $quantity) > 0.000001) {
                $errors[] = sprintf(
                    /* translators: 1: SKU, 2: allocated quantity, 3: requested quantity. */
                    __('Warehouse allocation for %1$s is %2$s of %3$s.', 'paint-nova-poshta-multishipping'),
                    $product->get_sku() ?: $product->get_name(),
                    wc_format_decimal($allocated),
                    wc_format_decimal($quantity)
                );
            }

            foreach ($allocations as $location_id => $allocated_quantity) {
                $mapping = is_array($mappings[$location_id] ?? null) ? $mappings[$location_id] : [];
                if (($mapping['enabled'] ?? 'no') !== 'yes') {
                    $errors[] = sprintf(
                        /* translators: %d: Stock Location ID. */
                        __('Stock location %d is not enabled for Nova Poshta.', 'paint-nova-poshta-multishipping'),
                        $location_id
                    );
                    continue;
                }
                if (trim((string) ($mapping['city_ref'] ?? '')) === '') {
                    $errors[] = sprintf(
                        /* translators: %d: Stock Location ID. */
                        __('Stock location %d has no Nova Poshta sender city.', 'paint-nova-poshta-multishipping'),
                        $location_id
                    );
                    continue;
                }

                if (!isset($shipments[$location_id])) {
                    $term = get_term($location_id, 'location');
                    $shipments[$location_id] = [
                        'location_id' => $location_id,
                        'location_name' => $term && !is_wp_error($term) ? $term->name : (string) $location_id,
                        'customer_label' => sanitize_text_field((string) ($mapping['customer_label'] ?? '')),
                        'sender_city_ref' => sanitize_text_field((string) $mapping['city_ref']),
                        'sender_type' => ($mapping['sender_type'] ?? 'warehouse') === 'doors' ? 'doors' : 'warehouse',
                        'items' => [],
                        'quantity' => 0.0,
                        'weight_kg' => 0.0,
                        'declared_cost' => 0.0,
                    ];
                }

                $ratio = $quantity > 0 ? $allocated_quantity / $quantity : 0.0;
                $line_total = round((float) ($item['line_total'] ?? 0) * $ratio, wc_get_price_decimals());
                $unit_weight = $this->unitWeight($product, $settings, $warnings);
                $shipments[$location_id]['items'][] = [
                    'cart_item_key' => (string) $cart_key,
                    'product_id' => (int) $product->get_id(),
                    'sku' => (string) $product->get_sku(),
                    'name' => (string) $product->get_name(),
                    'quantity' => $allocated_quantity,
                    'line_total' => $line_total,
                ];
                $shipments[$location_id]['quantity'] += $allocated_quantity;
                $shipments[$location_id]['weight_kg'] += $unit_weight * $allocated_quantity;
                $shipments[$location_id]['declared_cost'] += max(0.0, $line_total);
            }
        }

        foreach ($shipments as &$shipment) {
            $shipment['weight_kg'] = max(0.1, round((float) $shipment['weight_kg'], 3));
            $shipment['declared_cost'] = max(1.0, round((float) $shipment['declared_cost'], 2));
        }
        unset($shipment);

        ksort($shipments, SORT_NUMERIC);
        return [
            'shipments' => array_values($shipments),
            'warnings' => array_values(array_unique($warnings)),
            'errors' => array_values(array_unique($errors)),
        ];
    }

    /** @return array<int,float> */
    private function allocations(array $item, WC_Product $product, float $quantity): array
    {
        $plan = is_array($item['pc_alloc_plan'] ?? null) ? $item['pc_alloc_plan'] : [];
        if ($plan === [] && function_exists('pc_calc_plan_for')) {
            $plan = (array) pc_calc_plan_for($product, (int) $quantity);
        }

        $result = [];
        foreach ($plan as $location_id => $allocated_quantity) {
            $location_id = absint($location_id);
            $allocated_quantity = (float) $allocated_quantity;
            if ($location_id > 0 && $allocated_quantity > 0) {
                $result[$location_id] = $allocated_quantity;
            }
        }
        return $result;
    }

    /** @param string[] $warnings */
    private function unitWeight(WC_Product $product, array $settings, array &$warnings): float
    {
        $raw = (float) $product->get_weight();
        if ($raw > 0) {
            if (($settings['weight_mode'] ?? 'grams') === 'grams') {
                return max(0.001, $raw / 1000);
            }
            return max(0.001, (float) wc_get_weight($raw, 'kg'));
        }

        $warnings[] = sprintf(
            /* translators: %s: product SKU. */
            __('A fallback weight was used for %s.', 'paint-nova-poshta-multishipping'),
            $product->get_sku() ?: $product->get_name()
        );
        return (float) ($settings['fallback_item_weight_kg'] ?? 0.25);
    }

    /** @return array<string,mixed> */
    private function settings(): array
    {
        $stored = get_option('pnpm_settings', []);
        return wp_parse_args(is_array($stored) ? $stored : [], [
            'weight_mode' => 'grams',
            'fallback_item_weight_kg' => 0.25,
        ]);
    }
}
