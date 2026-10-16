<?php
namespace Paint\WayForPayCheckbox;

final class FolioCommand
{
    public static function build(array $payment, string $scope, string $payer, string $confirmedAt): array
    {
        if (!preg_match('/^[a-zA-Z0-9._-]{1,64}$/D', $scope)
            || !preg_match('/^[\p{L}\p{N} ._-]{1,8}$/uD', $payer) || trim($payer) !== $payer
            || !preg_match('/^[a-f0-9]{64}$/D', $payment['merchant_hash'])
            || (int) $payment['amount'] <= 0 || (int) $payment['amount'] > 999999999
            || $payment['currency'] !== 'UAH') throw new \RuntimeException('folio_mapping_required');
        return ['operationKey' => hash('sha256', $scope . '|' . $payment['merchant_hash'] . '|' . $payment['reference']),
            'sourceScope' => $scope, 'merchantHash' => $payment['merchant_hash'], 'reference' => $payment['reference'],
            'orderId' => (int) $payment['order_id'], 'amountCents' => (int) $payment['amount'], 'currency' => 'UAH',
            'confirmedAt' => $confirmedAt, 'payerKey' => $payer];
    }
    public static function hash(array $c): string
    {
        return hash('sha256', implode('|', ['v1', $c['sourceScope'], $c['merchantHash'], $c['reference'],
            $c['orderId'], $c['amountCents'], $c['currency'], $c['confirmedAt'], $c['payerKey']]));
    }
}
