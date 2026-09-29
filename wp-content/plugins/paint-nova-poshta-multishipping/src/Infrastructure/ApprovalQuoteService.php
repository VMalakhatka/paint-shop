<?php
namespace Paint\NovaPoshta\Infrastructure;
use Paint\NovaPoshta\Domain\DocumentShipmentBuilder;
use Paint\NovaPoshta\Domain\PolicyCalculator;
use Paint\NovaPoshta\Domain\DeliveryPolicy;
defined('ABSPATH') || exit;

/** Read-only delivery estimate for a document, using checkout's tariff and policy. */
final class ApprovalQuoteService {
    public function context(?\WC_Order $order, array $snapshot): array {
        if (($snapshot['currency'] ?? '') !== 'UAH') throw new \RuntimeException(__('Nova Poshta estimates require an order in UAH.', 'paint-nova-poshta-multishipping'));
        if ((get_option('pnpm_settings', [])['checkout_enabled'] ?? 'yes') !== 'yes') throw new \RuntimeException(__('Nova Poshta delivery is currently unavailable.', 'paint-nova-poshta-multishipping'));
        $plan = (new DocumentShipmentBuilder())->build($order, $snapshot);
        $policy = (new PolicyCalculator())->calculate([], $plan['merchandise_total'], count($plan['shipments']));
        $plan['fingerprint'] = hash('sha256', wp_json_encode([$plan, DeliveryPolicy::load(), get_option('pnpm_settings', []), $policy['profile']]));
        return $plan;
    }

    public function quote(array $plan, array $input): array {
        $type = (string)($input['pnpm_delivery_type'] ?? 'branch');
        if (!in_array($type, ['branch','parcel_locker','address'], true)) $this->invalid();
        $city = (new RecipientDirectory(new ApiClient()))->selected((string)($input['pnpm_city_ref'] ?? ''));
        if (is_wp_error($city)) throw new \RuntimeException($city->get_error_message());
        $recipient = ['city_ref'=>$city['ref'], 'city_label'=>$city['label'], 'delivery_type'=>$type, 'point_ref'=>'', 'point_label'=>'', 'address'=>''];
        if ($type === 'address') {
            $recipient['address'] = trim(sanitize_text_field((string)($input['pnpm_address'] ?? '')));
            if ($recipient['address'] === '' || mb_strlen($recipient['address']) > 300) $this->invalid();
        } else {
            $point = (new WarehouseDirectory(new ApiClient()))->find($city['ref'], (string)($input['pnpm_point_ref'] ?? ''));
            if (is_wp_error($point) || !$point['selectable'] || $point['kind'] !== ($type === 'parcel_locker' ? 'postomat' : 'branch')) $this->invalid();
            foreach ($plan['shipments'] as $shipment) {
                foreach (['placeWeight'=>'weight_kg', 'totalWeight'=>'weight_kg', 'declaredValue'=>'declared_cost'] as $limit=>$value) {
                    if ($point[$limit] !== null && $shipment[$value] > $point[$limit]) throw new \RuntimeException(__('The selected point cannot accept this parcel weight or declared value. Choose another point or courier delivery.', 'paint-nova-poshta-multishipping'));
                }
            }
            $recipient['point_ref'] = $point['ref']; $recipient['point_label'] = $point['label'];
        }
        $quote = (new TariffQuoteService(new ApiClient()))->quote($plan['shipments'], $city['ref'], $type);
        if ($quote['errors']) throw new \RuntimeException(implode(' ', $quote['errors']));
        $policy = (new PolicyCalculator())->calculate($quote['components'], $plan['merchandise_total'], count($plan['shipments']));
        return ['recipient'=>$recipient, 'quotes'=>$quote['quotes'], 'policy'=>$policy,
            'warnings'=>array_values(array_unique(array_merge($plan['warnings'], $quote['warnings']))), 'fingerprint'=>$plan['fingerprint']];
    }

    private function invalid(): never {
        throw new \RuntimeException(__('Choose the city and an available point from the Nova Poshta list, or enter a courier address.', 'paint-nova-poshta-multishipping'));
    }
}
