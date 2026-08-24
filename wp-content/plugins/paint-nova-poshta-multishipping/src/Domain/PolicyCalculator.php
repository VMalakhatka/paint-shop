<?php

namespace Paint\NovaPoshta\Domain;

defined('ABSPATH') || exit;

final class PolicyCalculator
{
    /** @param array<string,float> $components
     *  @return array<string,mixed>
     */
    public function calculate(array $components, float $merchandise_total, int $shipment_count): array
    {
        $policy = DeliveryPolicy::load();
        $profile_key = $this->profileKey($policy);
        $profile = is_array($policy['profiles'][$profile_key] ?? null)
            ? $policy['profiles'][$profile_key]
            : DeliveryPolicy::defaults()['profiles']['retail'];
        $tier = $merchandise_total < (float) ($profile['threshold'] ?? 0)
            ? (array) ($profile['below'] ?? [])
            : (array) ($profile['above'] ?? []);
        $allowance = $this->allowance($tier, $components, $merchandise_total);
        $rules = is_array($profile['components'] ?? null) ? $profile['components'] : [];
        $customer = 0.0;
        $store = 0.0;
        $budget_left = $allowance;
        $applied = [];

        foreach (DeliveryPolicy::componentLabels() as $key => $unused) {
            $amount = max(0.0, (float) ($components[$key] ?? 0));
            $rule = (string) ($rules[$key] ?? 'customer');
            $store_part = 0.0;
            if ($rule === 'store') {
                $store_part = $amount;
            } elseif ($rule === 'budget' && $budget_left > 0) {
                $store_part = min($amount, $budget_left);
                $budget_left -= $store_part;
            }
            $store += $store_part;
            $customer += $amount - $store_part;
            $applied[$key] = [
                'total' => round($amount, 2),
                'store' => round($store_part, 2),
                'customer' => round($amount - $store_part, 2),
                'rule' => $rule,
            ];
        }

        $cod = is_array($profile['cod'] ?? null) ? $profile['cod'] : [];
        $cod_allowed = $shipment_count > 1
            ? ($cod['multi_shipment'] ?? 'no') === 'yes'
            : ($cod['single_shipment'] ?? 'no') === 'yes';

        return [
            'profile' => $profile_key,
            'shipment_count' => $shipment_count,
            'total' => round($customer + $store, 2),
            'customer_total' => round($customer, 2),
            'store_total' => round($store, 2),
            'allowance' => round($allowance, 2),
            'components' => $applied,
            'cod_allowed' => $cod_allowed,
            'cod_fee_payer' => (string) ($cod['fee_payer'] ?? 'customer'),
        ];
    }

    /** @param array<string,mixed> $policy */
    private function profileKey(array $policy): string
    {
        $role = 'guest';
        if (is_user_logged_in()) {
            $user = wp_get_current_user();
            $role = sanitize_key((string) ($user->roles[0] ?? 'customer'));
        }
        $segments = is_array($policy['role_segments'] ?? null) ? $policy['role_segments'] : [];
        return ($segments[$role] ?? 'retail') === 'partner' ? 'partner' : 'retail';
    }

    /** @param array<string,mixed> $tier @param array<string,float> $components */
    private function allowance(array $tier, array $components, float $merchandise_total): float
    {
        $mode = (string) ($tier['mode'] ?? 'customer');
        $value = max(0.0, (float) ($tier['value'] ?? 0));
        $total = array_sum(array_map('floatval', $components));
        $allowance = match ($mode) {
            'store' => $total,
            'fixed' => $value,
            'order_percent' => $merchandise_total * $value / 100,
            'delivery_percent' => $total * $value / 100,
            default => 0.0,
        };
        $cap = max(0.0, (float) ($tier['cap'] ?? 0));
        if ($cap > 0) {
            $allowance = min($allowance, $cap);
        }
        return min($total, max(0.0, $allowance));
    }
}
