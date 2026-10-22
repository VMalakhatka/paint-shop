<?php
namespace Paint\WayForPayCheckbox;

final class Command
{
    public static function build(\WC_Order $order, array $payment, int $tax, string $uuid): array
    {
        if ($tax < 0 || $tax > 999 || $order->get_currency() !== 'UAH'
            || Protocol::cents($order->get_total()) !== $payment['amount']) {
            throw new \RuntimeException('order_total_mismatch');
        }
        $goods = [];
        foreach ($order->get_items(['line_item', 'shipping', 'fee']) as $id => $item) {
            $total = Protocol::cents($item->get_total()) + Protocol::cents($item->get_total_tax());
            if ($total === 0) {
                continue;
            }
            $quantity = $item->get_type() === 'line_item' ? (string) $item->get_quantity() : '1';
            // Integer quantities only in this adapter. The generic executor still supports fractional goods.
            if (!preg_match('/^[1-9][0-9]{0,5}$/D', $quantity)) {
                throw new \RuntimeException('fractional_quantity_requires_mapping');
            }
            $qty = (int) $quantity;
            $code = 'woo-item-' . $id;
            if ($item->get_type() === 'line_item') {
                $product = $item->get_product();
                if ($product && $product->get_sku() !== '') {
                    $code = $product->get_sku();
                }
            }
            // Split the same item into at most two prices; discounts are already included in Woo totals.
            $base = intdiv($total, $qty);
            $remainder = $total % $qty;
            foreach ([[$qty - $remainder, $base], [$remainder, $base + 1]] as [$count, $price]) {
                if ($count === 0 || $price === 0) {
                    if ($count > 0 && $price === 0) {
                        throw new \RuntimeException('zero_price_allocation_requires_mapping');
                    }
                    continue;
                }
                $goods[] = ['code' => $code, 'name' => $item->get_name(), 'price_cents' => $price,
                    'quantity_thousandths' => $count * 1000, 'line_total_cents' => $count * $price,
                    'tax_codes' => [$tax], 'discounts' => []];
            }
        }
        if (array_sum(array_column($goods, 'line_total_cents')) !== $payment['amount']) {
            throw new \RuntimeException('goods_total_mismatch');
        }
        $command = ['schema_version' => '1.0',
            'operation_key' => 'wfp:' . substr($payment['merchant_hash'], 0, 24) . ':woo:' . $order->get_id() . ':SELL:v1',
            'receipt_id' => $uuid, 'operation_type' => 'SELL',
            'source' => ['system' => 'woocommerce', 'entity_type' => 'order', 'entity_id' => (string) $order->get_id()],
            'currency' => 'UAH', 'expected_total_cents' => $payment['amount'], 'goods' => $goods,
            'payments' => [['type' => 'CASHLESS', 'value_cents' => $payment['amount'], 'label' => 'WayForPay',
                'code' => 1, 'transaction_id' => $payment['reference']]], 'discounts' => []];
        return (new \Paint\CheckboxFiscalization\Domain\CommandValidator())->normalize($command);
    }
}
