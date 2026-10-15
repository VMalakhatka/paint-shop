<?php
namespace Paint\WayForPayCheckbox;

final class Protocol
{
    public const FIELDS = ['merchantAccount', 'orderReference', 'amount', 'currency', 'authCode', 'cardPan', 'transactionStatus', 'reasonCode'];

    public static function cents(mixed $value): int
    {
        if ((!is_string($value) && !is_int($value) && !is_float($value))
            || !preg_match('/^([0-9]{1,9})(?:\.([0-9]{1,2}))?$/D', (string) $value, $m)) {
            throw new \RuntimeException('invalid_amount');
        }
        return (int) $m[1] * 100 + (int) str_pad($m[2] ?? '', 2, '0');
    }

    public static function verify(array $body, string $merchant, string $secret): array
    {
        if ($merchant === '' || $secret === '') {
            throw new \RuntimeException('gateway_not_configured');
        }
        $values = [];
        foreach (self::FIELDS as $key) {
            if (!isset($body[$key]) || !is_scalar($body[$key]) || is_bool($body[$key]) || strlen((string) $body[$key]) > 256) {
                throw new \RuntimeException('invalid_callback');
            }
            $values[] = (string) $body[$key];
        }
        $signature = $body['merchantSignature'] ?? null;
        if (!is_string($signature) || !hash_equals(hash_hmac('md5', implode(';', $values), $secret), $signature)
            || !hash_equals($merchant, (string) $body['merchantAccount'])) {
            throw new \RuntimeException('invalid_signature');
        }
        if (!preg_match('/^([1-9][0-9]{0,17})_woo_w4p_([0-9]{10})$/D', (string) $body['orderReference'], $m)) {
            throw new \RuntimeException('invalid_reference');
        }
        return [
            'order_id' => (int) $m[1], 'reference' => (string) $body['orderReference'],
            'merchant_hash' => hash('sha256', $merchant), 'amount' => self::cents($body['amount']),
            'currency' => (string) $body['currency'], 'status' => (string) $body['transactionStatus'],
            'reason' => (string) $body['reasonCode'],
        ];
    }

    public static function acknowledgement(string $reference, string $secret, int $time): array
    {
        return ['orderReference' => $reference, 'status' => 'accept', 'time' => $time,
            'signature' => hash_hmac('md5', $reference . ';accept;' . $time, $secret)];
    }
}
