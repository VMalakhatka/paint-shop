<?php
namespace Paint\WayForPayCheckbox;

/** Browser navigation only. Never accepts a payment or creates a fiscal operation. */
final class ReturnPage
{
    public function boot(): void
    {
        add_action('template_redirect', [$this, 'redirect'], -100);
        add_filter('woocommerce_get_checkout_order_received_url', [$this, 'receivedUrl'], 100, 2);
        add_filter('woocommerce_thankyou_order_received_text', [$this, 'message'], 20, 2);
    }

    public function receivedUrl(string $url, $order): string
    {
        if (!$order instanceof \WC_Order || $order->get_payment_method() !== 'wayforpay') {
            return $url;
        }
        // Override the Folio account-list redirect, but retain Woo's key/login/email checks.
        return add_query_arg('key', $order->get_order_key(),
            wc_get_endpoint_url('order-received', $order->get_id(), wc_get_checkout_url()));
    }

    public function target(array $query): string
    {
        if (isset($query['wc-api']) || !is_scalar($query['order'] ?? null)
            || !is_string($query['key'] ?? null) || !ctype_digit((string) $query['order'])) {
            return '';
        }
        $order = wc_get_order((int) $query['order']);
        if (!$order instanceof \WC_Order || $order->get_payment_method() !== 'wayforpay'
            || $query['key'] === '' || !hash_equals($order->get_order_key(), $query['key'])) {
            return '';
        }
        return $this->receivedUrl('', $order);
    }

    public function redirect(): void
    {
        if (is_admin() || wp_doing_ajax() || is_wc_endpoint_url()) {
            return;
        }
        $url = $this->target(wp_unslash($_GET));
        if ($url !== '') {
            nocache_headers();
            wp_safe_redirect($url, 303, 'PC WayForPay Checkbox');
            exit;
        }
    }

    public function message(string $text, $order): string
    {
        if (!$order instanceof \WC_Order || $order->get_payment_method() !== 'wayforpay') {
            return $text;
        }
        if (in_array($order->get_status(), ['failed', 'cancelled', 'refunded'], true)) {
            return esc_html__('This order is cancelled, failed or refunded. Check its details or contact the manager before making another payment.', 'pc-wayforpay-checkbox');
        }
        $verified = $order->get_meta('_pc_wfp_verified', true);
        $paid = is_array($verified) && !empty($verified['reference'])
            && $verified['reference'] === $order->get_transaction_id() && $order->get_date_paid();
        $message = $paid
            ? __('Your order has been paid successfully. You can view it in your account. The manager will notify you about shipment.', 'pc-wayforpay-checkbox')
            : __('Your order has been received. We are waiting for payment confirmation from WayForPay. Refresh this page shortly; do not pay again if money has already been charged.', 'pc-wayforpay-checkbox');
        return esc_html($message) . ' <a href="' . esc_url(wc_get_account_endpoint_url('orders')) . '">'
            . esc_html__('View my orders', 'pc-wayforpay-checkbox') . '</a>';
    }
}
