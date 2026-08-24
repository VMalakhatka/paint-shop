<?php

namespace Paint\NovaPoshta\Domain;

use WC_Order;

defined('ABSPATH') || exit;

final class AllocationSnapshotBuilder
{
    /** @return array{shipments:array<int,array<string,mixed>>,warnings:string[],errors:string[]} */
    public function build(WC_Order $order): array
    {
        $shipments = [];
        $warnings = [];
        $errors = [];

        foreach ($order->get_items('line_item') as $item_id => $item) {
            $quantity = (float) $item->get_quantity();
            $allocations = $this->itemAllocations($item, $quantity);
            if ($allocations === []) {
                $errors[] = sprintf(
                    /* translators: %d: order item ID */
                    __('Order item %d has no warehouse allocation.', 'paint-nova-poshta-multishipping'),
                    $item_id
                );
                continue;
            }

            $allocated = array_sum(array_column($allocations, 'quantity'));
            if (abs($allocated - $quantity) > 0.000001) {
                $errors[] = sprintf(
                    /* translators: 1: item ID, 2: allocated quantity, 3: ordered quantity */
                    __('Order item %1$d allocation is %2$s of %3$s.', 'paint-nova-poshta-multishipping'),
                    $item_id,
                    wc_format_decimal($allocated),
                    wc_format_decimal($quantity)
                );
            }

            foreach ($allocations as $allocation) {
                $location_id = (int) $allocation['location_id'];
                if (!isset($shipments[$location_id])) {
                    $term = get_term($location_id, 'location');
                    $shipments[$location_id] = [
                        'location_id' => $location_id,
                        'location_name' => $term && !is_wp_error($term) ? $term->name : (string) $location_id,
                        'items' => [],
                        'quantity' => 0.0,
                    ];
                }

                $line_ratio = $quantity > 0 ? (float) $allocation['quantity'] / $quantity : 0.0;
                $shipments[$location_id]['items'][] = [
                    'order_item_id' => (int) $item_id,
                    'product_id' => (int) $item->get_product_id(),
                    'variation_id' => (int) $item->get_variation_id(),
                    'sku' => $item->get_product() ? $item->get_product()->get_sku() : '',
                    'name' => $item->get_name(),
                    'quantity' => (float) $allocation['quantity'],
                    'line_subtotal' => round((float) $item->get_subtotal() * $line_ratio, wc_get_price_decimals()),
                    'line_total' => round((float) $item->get_total() * $line_ratio, wc_get_price_decimals()),
                    'source' => $allocation['source'],
                ];
                $shipments[$location_id]['quantity'] += (float) $allocation['quantity'];
            }
        }

        ksort($shipments, SORT_NUMERIC);
        return [
            'shipments' => array_values($shipments),
            'warnings' => $warnings,
            'errors' => array_values(array_unique($errors)),
        ];
    }

    /** @return array<int,array{location_id:int,quantity:float,source:string}> */
    private function itemAllocations(object $item, float $quantity): array
    {
        $slw_data = $item->get_meta('_slw_data', true);
        if (is_array($slw_data) && $slw_data !== []) {
            $result = [];
            foreach ($slw_data as $location_id => $data) {
                $allocated = is_array($data) ? (float) ($data['quantity_subtracted'] ?? 0) : 0.0;
                if ((int) $location_id > 0 && $allocated > 0) {
                    $result[] = [
                        'location_id' => (int) $location_id,
                        'quantity' => $allocated,
                        'source' => '_slw_data',
                    ];
                }
            }
            if ($result !== []) {
                return $result;
            }
        }

        $location_id = (int) $item->get_meta('_stock_location', true);
        if ($location_id > 0 && $quantity > 0) {
            return [[
                'location_id' => $location_id,
                'quantity' => $quantity,
                'source' => '_stock_location',
            ]];
        }

        return [];
    }
}
