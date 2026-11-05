<?php
namespace Paint\WayForPayCheckbox;

/** Read-only admission check for NEW payment attempts; never changes payment state. */
final class Availability
{
    private ?array $result = null;
    private bool $hidden = false;

    public static function credentials(): array
    {
        $s = (array) get_option('woocommerce_wayforpay_settings', []);
        return [
            'merchant' => defined('PC_WFP_MERCHANT_ACCOUNT') ? (string) PC_WFP_MERCHANT_ACCOUNT : (string) ($s['merchant_account'] ?? ''),
            'secret' => defined('PC_WFP_MERCHANT_SECRET') ? (string) PC_WFP_MERCHANT_SECRET : (string) ($s['secret_key'] ?? ''),
        ];
    }

    public function boot(): void
    {
        add_filter('woocommerce_available_payment_gateways', [$this, 'gateways'], 1000);
        add_action('woocommerce_review_order_before_payment', [$this, 'notice']);
        add_action('woocommerce_pay_order_before_payment', [$this, 'notice']);
        add_action('woocommerce_after_checkout_validation', [$this, 'validate'], 10, 2);
        add_action('woocommerce_before_pay_action', [$this, 'validatePay']);
        add_action('woocommerce_receipt_wayforpay', [$this, 'receipt'], 0);
    }

    public static function message(): string
    {
        return __('Card payment through WayForPay is temporarily unavailable. Please choose another payment method or contact the manager. Do not repeat payment if money has already been charged.', 'pc-wayforpay-checkbox');
    }

    public function status(): array
    {
        if ($this->result !== null) return $this->result;
        $s = self::credentials();
        $key = 'pc_wfp_availability_' . substr(hash_hmac('sha256', $s['merchant'], $s['secret']), 0, 32);
        $cached = get_transient($key);
        if (is_array($cached) && isset($cached['available'], $cached['code'])) return $this->result = $cached;
        $result = ['available' => false, 'code' => 'not_configured'];
        if ($s['merchant'] !== '' && $s['secret'] !== '') {
            // A one-second read-only window tests API access without starting a transaction.
            // Do not retain or inspect transaction/customer data returned in this response.
            $end = time();
            $body = ['transactionType' => 'TRANSACTION_LIST', 'apiVersion' => 1,
                'merchantAccount' => $s['merchant'], 'dateBegin' => $end - 1, 'dateEnd' => $end];
            $body['merchantSignature'] = hash_hmac('md5', $s['merchant'] . ';' . ($end - 1) . ';' . $end, $s['secret']);
            $response = wp_remote_post('https://api.wayforpay.com/api', [
                'timeout' => 3, 'redirection' => 0, 'sslverify' => true, 'limit_response_size' => 65536,
                'headers' => ['Content-Type' => 'application/json'], 'body' => wp_json_encode($body),
            ]);
            $result['code'] = 'unavailable';
            if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
                $json = json_decode(wp_remote_retrieve_body($response), true);
                $code = $json['reasonCode'] ?? null;
                if ((is_int($code) || is_string($code)) && preg_match('/^[0-9]{4}$/D', (string) $code)) {
                    $result = ['available' => (string) $code === '1100', 'code' => (string) $code];
                }
            }
        }
        $result['checked_at'] = gmdate('c');
        set_transient($key, $result, $result['available'] ? 120 : 30);
        return $this->result = $result;
    }

    public function gateways(array $gateways): array
    {
        if (is_admin() && !wp_doing_ajax()) return $gateways;
        if (isset($gateways['wayforpay']) && !$this->status()['available']) {
            unset($gateways['wayforpay']);
            $this->hidden = true;
        }
        return $gateways;
    }

    public function notice(): void
    {
        if ($this->hidden) wc_print_notice(self::message(), 'notice');
    }

    public function validate(array $data, \WP_Error $errors): void
    {
        if (($data['payment_method'] ?? '') === 'wayforpay' && !$this->status()['available']) {
            $errors->add('pc_wfp_unavailable', self::message());
        }
    }

    public function validatePay($order): void
    {
        if (($_POST['payment_method'] ?? '') === 'wayforpay' && !$this->status()['available']) {
            wc_add_notice(self::message(), 'error');
        }
    }

    public function receipt(int $id): void
    {
        if ($this->status()['available']) return;
        // Woo has already checked the order key, permission and email before this hook.
        // Stop the legacy gateway's unconditional auto-submitting form on this request.
        $gateways = WC()->payment_gateways()->payment_gateways();
        if (isset($gateways['wayforpay'])) {
            remove_action('woocommerce_receipt_wayforpay', [$gateways['wayforpay'], 'receipt_page']);
        }
        wc_print_notice(self::message(), 'error');
        $order = wc_get_order($id);
        if ($order instanceof \WC_Order) {
            echo '<p><a class="button" href="' . esc_url($order->get_checkout_payment_url()) . '">'
                . esc_html__('Choose another payment method', 'pc-wayforpay-checkbox') . '</a></p>';
        }
    }
}
