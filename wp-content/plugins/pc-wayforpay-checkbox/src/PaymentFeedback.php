<?php
namespace Paint\WayForPayCheckbox;

/** Safe diagnostic only. Never marks paid, queues work or changes Woo status. */
final class PaymentFeedback
{
    public const META = '_pc_wfp_payment_feedback';
    private const STATES = ['Declined', 'Expired', 'Pending', 'InProcessing', 'WaitingAuthComplete'];

    /** Caller holds the shared payment lock and has checked signature/identity/totals. */
    public static function record(\WC_Order $order, array $payment): void
    {
        if ($order->get_date_paid() || $order->get_meta('_pc_wfp_verified', true)
            || !in_array($payment['status'], self::STATES, true)
            || !preg_match('/^[0-9]{4}$/D', $payment['reason'])) return;
        $previous = $order->get_meta(self::META, true);
        if (is_array($previous)) {
            if (strcmp(substr($previous['reference'], -10), substr($payment['reference'], -10)) > 0) return;
            if ($previous['reference'] === $payment['reference']) {
                if ($previous['status'] === $payment['status'] && $previous['code'] === $payment['reason']) return;
                if (in_array($previous['status'], ['Declined', 'Expired'], true)) return;
            }
        }
        $order->update_meta_data(self::META, [
            'reference' => $payment['reference'], 'status' => $payment['status'],
            'code' => $payment['reason'], 'updated_at' => gmdate('c'),
        ]);
        $order->save();
    }

    /** Browser POST can carry signed FAILURE evidence only; Approved still needs the callback. */
    public static function returned(\WC_Order $order, array $body): void
    {
        $credentials = Availability::credentials();
        try {
            $p = Protocol::verify($body, $credentials['merchant'], $credentials['secret']);
            if ($p['order_id'] !== $order->get_id() || $order->get_type() !== 'shop_order'
                || $order->get_payment_method() !== 'wayforpay' || $p['currency'] !== 'UAH'
                || $p['currency'] !== $order->get_currency() || $p['amount'] <= 0
                || $p['amount'] !== Protocol::cents($order->get_total())
                || !in_array($p['status'], self::STATES, true)) return;
            $store = new Store();
            if (!$store->lock($order->get_id())) return;
            try {
                // Reload inside the lock: a successful callback may have just completed.
                if (!$store->get($order->get_id())) self::record(wc_get_order($order->get_id()), $p);
            } finally {
                $store->unlock($order->get_id());
            }
        } catch (\Throwable) {
            // No unverified reason is persisted or displayed. Navigation still completes.
        }
    }

    public static function message($feedback): string
    {
        if (!is_array($feedback)) return '';
        $status = $feedback['status'] ?? '';
        $code = $feedback['code'] ?? '';
        if (!in_array($status, ['Declined', 'Expired'], true)) return '';
        if (in_array($code, ['1109', '1110', '1113', '1115', '1117', '1118', '1120', '1121', '1122'], true)) {
            return __('WayForPay could not process this payment because of a payment service restriction or error. Your order is saved. Contact the manager before trying again; do not repeat payment if money was charged.', 'pc-wayforpay-checkbox');
        }
        if ($status === 'Expired' || in_array($code, ['1124', '1125'], true)) {
            return __('Payment was cancelled or the payment session expired. Your order is saved. Check whether money was charged before trying again or contact the manager.', 'pc-wayforpay-checkbox');
        }
        return __('WayForPay did not confirm this payment. Your order is saved. Check with your bank or contact the manager to choose another payment method. Do not repeat payment if money has already been charged.', 'pc-wayforpay-checkbox');
    }

    public function panel($order): void
    {
        if (!$order instanceof \WC_Order || !current_user_can('manage_woocommerce')) return;
        $data = $order->get_meta(self::META, true);
        if (!is_array($data)) return;
        echo '<p><strong>' . esc_html__('Last WayForPay payment diagnostic', 'pc-wayforpay-checkbox') . '</strong><br>'
            . esc_html($data['status'] . ' / ' . $data['code'] . ' / ' . $data['updated_at']) . '<br>'
            . esc_html__('Historical diagnostic; the confirmed payment status takes precedence.', 'pc-wayforpay-checkbox') . '</p>';
    }
}
