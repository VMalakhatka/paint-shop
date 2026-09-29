<?php
namespace Paint\NovaPoshta\Domain;
defined('ABSPATH') || exit;

/** Quote saved documents without allocating today's stock or touching the cart. */
final class DocumentShipmentBuilder {
    public function build(?\WC_Order $order, array $snapshot): array {
        $contents = [];
        if ($order) {
            $allocation = (new AllocationSnapshotBuilder())->build($order);
            $plans = [];
            foreach ($allocation['shipments'] as $shipment) foreach ($shipment['items'] as $line) {
                $plans[$line['order_item_id']][$shipment['location_id']] = $line['quantity'];
            }
            foreach ($order->get_items() as $id => $item) {
                $product = $item->get_product();
                if (!$product) $this->fail();
                if (!$product->needs_shipping()) continue;
                $quantity = (float) $item->get_quantity();
                $plan = $plans[$id] ?? [];
                if (!$plan) $plan = [$this->location((string) $item->get_meta('_folio_warehouse_id')) => $quantity];
                $contents[$id] = ['data'=>$product, 'quantity'=>$quantity, 'line_total'=>(float)$item->get_total(), 'pc_alloc_plan'=>$plan];
            }
        } else {
            $location = $this->location((string)($snapshot['warehouseId'] ?? ''));
            foreach ($snapshot['items'] as $id => $item) {
                $sku = (string)($item['sku'] ?? '');
                $product = $sku !== '' ? wc_get_product(wc_get_product_id_by_sku($sku)) : false;
                if (!$product) $this->fail();
                if (!$product->needs_shipping()) continue;
                $quantity = (float)$item['quantity'];
                $contents[$id] = ['data'=>$product, 'quantity'=>$quantity, 'line_total'=>(float)$item['amount'], 'pc_alloc_plan'=>[$location=>$quantity]];
            }
        }
        // Explicit nonempty plans prevent CartShipmentBuilder's live-stock fallback.
        foreach ($contents as $item) if ($item['quantity'] <= 0 || !$item['pc_alloc_plan']) $this->fail();
        $plan = (new CartShipmentBuilder())->build(['contents'=>$contents]);
        if (!$plan['shipments'] || $plan['errors']) $this->fail();
        $plan['merchandise_total'] = array_sum(array_column($contents, 'line_total'));
        return $plan;
    }

    private function location(string $folio): int {
        if ($folio === '') $this->fail();
        $terms = get_terms(['taxonomy'=>'location', 'hide_empty'=>false]);
        $matches = [];
        if (!is_wp_error($terms)) foreach ($terms as $term) {
            $ids = function_exists('lavka_get_location_folio_warehouses')
                ? lavka_get_location_folio_warehouses($term->term_id)
                : get_term_meta($term->term_id, 'lavka_folio_warehouses', true);
            if (is_array($ids)) foreach ($ids as $row) {
                if (is_array($row) && (string)($row['id'] ?? '') === $folio) { $matches[] = $term->term_id; break; }
            }
        }
        if (count($matches) !== 1) $this->fail();
        return (int)$matches[0];
    }

    private function fail(): never {
        throw new \RuntimeException(__('Delivery cannot be calculated from the saved order warehouses. Ask the manager to check the products and sender settings.', 'paint-nova-poshta-multishipping'));
    }
}
