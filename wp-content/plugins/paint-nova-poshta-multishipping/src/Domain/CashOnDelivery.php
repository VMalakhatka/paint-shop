<?php
namespace Paint\NovaPoshta\Domain;

defined('ABSPATH') || exit;

/** Reviewed collection instructions, separate from declared value and delivery charges. */
final class CashOnDelivery
{
    public static function basis(array $context): array
    {
        return ['payment'=>(string)$context['payment'], 'total'=>self::cents($context['total']), 'paid'=>(bool)$context['paid']];
    }

    public static function defaults(array $context): array
    {
        return ['enabled'=>$context['payment'] === 'cod' && !$context['paid'],
            'amount'=>number_format((float)$context['total'], 2, '.', ''), 'service'=>'', 'fee_payer'=>match ($context['profile']['cod']['fee_payer'] ?? '') { 'customer'=>'Recipient', 'store'=>'Sender', default=>'' }];
    }

    public static function review(array $input, array $context): array
    {
        if (!in_array($input['enabled'] ?? null, [true, false, '1', '0'], true)) self::invalid();
        $enabled = in_array($input['enabled'], [true, '1'], true);
        if (!$enabled) return ['enabled'=>false, 'amount'=>'0.00', 'service'=>'', 'fee_payer'=>'', 'basis'=>self::basis($context)];
        if ($context['paid']) throw new \RuntimeException(__('This order is already paid. Cash on delivery would collect payment again.', 'paint-nova-poshta-multishipping'));
        if (count($context['groups']) !== 1) throw new \RuntimeException(__('Cash on delivery currently requires one warehouse. Do not repeat the order amount on several shipments.', 'paint-nova-poshta-multishipping'));
        if (($context['profile']['cod']['single_shipment'] ?? 'no') !== 'yes') throw new \RuntimeException(__('COD is disabled by the customer delivery policy.', 'paint-nova-poshta-multishipping'));
        $amount = self::cents($input['amount'] ?? null);
        if ($amount <= 0 || $amount > self::cents($context['total'])) throw new \RuntimeException(__('Enter a cash on delivery amount greater than zero and no greater than the order total.', 'paint-nova-poshta-multishipping'));
        if (!in_array($input['service'] ?? '', ['money_transfer','payment_control'], true)) throw new \RuntimeException(__('Choose how Nova Poshta should return the cash on delivery payment.', 'paint-nova-poshta-multishipping'));
        if ($input['service'] === 'money_transfer' && !in_array($input['fee_payer'] ?? '', ['Sender','Recipient'], true)) self::invalid();
        return ['enabled'=>true, 'amount'=>number_format($amount / 100, 2, '.', ''), 'service'=>$input['service'],
            'fee_payer'=>$input['service'] === 'money_transfer' ? $input['fee_payer'] : '', 'basis'=>self::basis($context)];
    }

    public static function saved($saved, array $context): array
    {
        // Old drafts did not contain reviewed collection instructions. Require an explicit save.
        if (!is_array($saved) || ($saved['basis'] ?? null) !== self::basis($context)) {
            throw new \RuntimeException(__('Review and save cash on delivery settings again: the payment method, order amount or payment status changed, or this preparation uses the old format.', 'paint-nova-poshta-multishipping'));
        }
        return self::review($saved, $context);
    }

    public static function summary(array $settings): array
    {
        if (empty($settings['enabled'])) return [__('Cash on delivery: none.', 'paint-nova-poshta-multishipping')];
        $lines = [sprintf(__('Amount to collect: %s UAH.', 'paint-nova-poshta-multishipping'), $settings['amount'])];
        if ($settings['service'] === 'money_transfer') {
            $lines[] = __('Separate money transfer (return delivery of funds)', 'paint-nova-poshta-multishipping');
            $lines[] = __('Money transfer fee payer', 'paint-nova-poshta-multishipping') . ': ' . ($settings['fee_payer'] === 'Sender'
                ? __('Sender', 'paint-nova-poshta-multishipping') : __('Recipient', 'paint-nova-poshta-multishipping'));
        } else {
            $lines[] = __('To the FOP account under the NovaPay contract (payment control)', 'paint-nova-poshta-multishipping');
            $lines[] = __('Payment control requires an active NovaPay contract; its fees follow that contract. The collection amount is separate from declared value and delivery charges.', 'paint-nova-poshta-multishipping');
        }
        $lines[] = __('The delivery estimate does not include the money transfer commission. Collection instructions do not confirm that payment was received.', 'paint-nova-poshta-multishipping');
        return $lines;
    }

    public static function carrier(array $settings): array
    {
        if (!$settings['enabled']) return [];
        if ($settings['service'] === 'money_transfer') return ['BackwardDeliveryData'=>[
            ['PayerType'=>$settings['fee_payer'], 'CargoType'=>'Money', 'RedeliveryString'=>$settings['amount']],
        ]];
        return ['AfterpaymentOnGoodsCost'=>$settings['amount']];
    }

    private static function cents($value): int
    {
        if (!is_scalar($value) || is_bool($value)) self::invalid();
        $value = str_replace(',', '.', trim((string)$value));
        if (!preg_match('/^\d{1,9}(?:\.\d{1,2})?$/D', $value)) self::invalid();
        [$whole, $decimal] = array_pad(explode('.', $value, 2), 2, '');
        return (int)$whole * 100 + (int)str_pad($decimal, 2, '0');
    }

    private static function invalid(): never
    {
        throw new \RuntimeException(__('Check the cash on delivery amount and payment return settings.', 'paint-nova-poshta-multishipping'));
    }
}
