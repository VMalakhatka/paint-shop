<?php
namespace Paint\WayForPayCheckbox;

final class Bridge
{
    public const JOB = 'pc_wfp_checkbox_sale';
    public const SWEEP = 'pc_wfp_checkbox_recover';
    private Store $store;

    public function __construct()
    {
        $this->store = new Store();
    }

    public static function mode(): string
    {
        $mode = defined('PC_WFP_CHECKBOX_MODE') ? PC_WFP_CHECKBOX_MODE : 'off';
        return in_array($mode, ['preview', 'test', 'live'], true) ? $mode : 'off';
    }

    public function boot(): void
    {
        // Run before the installed legacy gateway's callback, and terminate our HTTP response.
        add_action('woocommerce_api_wc_wayforpay', [$this, 'httpCallback'], -100);
        add_action(self::JOB, [$this, 'work']);
        add_action(self::SWEEP, [$this, 'recover']);
        add_action('action_scheduler_init', [$this, 'scheduleRecovery']);
        add_action('woocommerce_admin_order_data_after_order_details', [$this, 'orderPanel']);
        add_filter('woocommerce_order_actions', [$this, 'orderActions'], 10, 2);
        add_action('woocommerce_order_action_pc_wfp_checkbox_retry', [$this, 'manualRetry']);
    }

    private function settings(): array
    {
        $s = (array) get_option('woocommerce_wayforpay_settings', []);
        return [
            'merchant' => defined('PC_WFP_MERCHANT_ACCOUNT') ? (string) PC_WFP_MERCHANT_ACCOUNT : (string) ($s['merchant_account'] ?? ''),
            'secret' => defined('PC_WFP_MERCHANT_SECRET') ? (string) PC_WFP_MERCHANT_SECRET : (string) ($s['secret_key'] ?? ''),
        ];
    }

    public function httpCallback(): void
    {
        if (self::mode() === 'off') {
            return;
        }
        nocache_headers();
        $raw = file_get_contents('php://input', false, null, 0, 65537);
        $body = is_string($raw) && strlen($raw) <= 65536 ? json_decode($raw, true) : null;
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !is_array($body)) {
            wp_send_json(['error' => 'invalid_callback'], 400);
        }
        $result = $this->accept($body);
        if (is_wp_error($result)) {
            $code = $result->get_error_code();
            wp_send_json(['error' => $code], (int) ($result->get_error_data()['status'] ?? 503));
        }
        wp_send_json($result, 200);
    }

    /** This is the only producer of verified payment rows; never call it from a status hook. */
    public function accept(array $body): array|\WP_Error
    {
        if (self::mode() === 'off') {
            return new \WP_Error('bridge_disabled', '', ['status' => 503]);
        }
        $s = $this->settings();
        try {
            $p = Protocol::verify($body, $s['merchant'], $s['secret']);
        } catch (\RuntimeException $e) {
            return new \WP_Error($e->getMessage(), '', ['status' => 400]);
        }
        if (!$this->store->lock($p['order_id'])) {
            return new \WP_Error('payment_busy', '', ['status' => 503]);
        }
        try {
            $order = wc_get_order($p['order_id']);
            if (!$order || $order->get_type() !== 'shop_order' || $order->get_payment_method() !== 'wayforpay') {
                return new \WP_Error('order_not_eligible', '', ['status' => 400]);
            }
            $row = $this->store->get($p['order_id']);
            $expected = $row ? (int) $row['amount'] : Protocol::cents($order->get_total());
            if ($p['currency'] !== 'UAH' || $p['currency'] !== $order->get_currency()
                || $p['amount'] <= 0 || ($p['status'] !== 'Refunded' && $p['amount'] !== $expected)
                || ($p['status'] === 'Refunded' && $p['amount'] > $expected)) {
                return new \WP_Error('payment_total_mismatch', '', ['status' => 400]);
            }
            if ($row && ($row['reference'] !== $p['reference'] || $row['merchant_hash'] !== $p['merchant_hash'])) {
                return new \WP_Error('payment_identity_conflict', '', ['status' => 409]);
            }
            $ack = Protocol::acknowledgement($p['reference'], $s['secret'], time());
            if ($p['status'] === 'Refunded') {
                if (!$row) {
                    // A refund can arrive before Approved. Persist a tombstone, not a sale.
                    $this->store->insert(['order_id' => $p['order_id'], 'reference' => $p['reference'],
                        'merchant_hash' => $p['merchant_hash'], 'amount' => $expected, 'currency' => $p['currency'],
                        'command' => '[]', 'mode' => self::mode(), 'refunded' => 1,
                        'status' => 'REFUND_REVIEW', 'error_code' => 'refund_requires_item_mapping']);
                    $order->add_order_note(__('WayForPay refund confirmed. Review the Checkbox sale and prepare an itemized return.', 'pc-wayforpay-checkbox'));
                } elseif (!(int) $row['refunded']) {
                    // Provider notifications do not identify refunded Woo lines. Never guess a return payload.
                    $this->store->update($p['order_id'], ['refunded' => 1, 'status' => 'REFUND_REVIEW', 'error_code' => 'refund_requires_item_mapping']);
                    $order->add_order_note(__('WayForPay refund confirmed. Review the Checkbox sale and prepare an itemized return.', 'pc-wayforpay-checkbox'));
                }
                return $ack;
            }
            if ($p['status'] !== 'Approved' || $p['reason'] !== '1100') {
                return $ack; // Pending, declined, cancelled and hold statuses cannot mark an order paid.
            }
            if (!$row) {
                $start = defined('PC_WFP_CHECKBOX_START_AT') ? strtotime((string) PC_WFP_CHECKBOX_START_AT) : false;
                $created = $order->get_date_created();
                if (!$start || !$created || $created->getTimestamp() < $start || $order->is_paid()
                    || !in_array($order->get_status(), ['pending', 'on-hold', 'failed'], true)) {
                    return new \WP_Error('historical_or_ineligible_order', '', ['status' => 409]);
                }
                if (!defined('PC_WFP_PRRO_DISABLED_CONFIRMED') || PC_WFP_PRRO_DISABLED_CONFIRMED !== true) {
                    return new \WP_Error('single_fiscal_provider_not_confirmed', '', ['status' => 503]);
                }
                // Confirmed payment must survive payload/mapping failure. A blocked receipt is manager-visible.
                $command = [];
                $error = '';
                try {
                    if (!defined('PC_WFP_CHECKBOX_TAX_CODE') || !is_int(PC_WFP_CHECKBOX_TAX_CODE)) {
                        throw new \RuntimeException('tax_mapping_required');
                    }
                    $command = Command::build($order, $p, PC_WFP_CHECKBOX_TAX_CODE, wp_generate_uuid4());
                } catch (\Throwable $e) {
                    $error = $e instanceof \RuntimeException ? sanitize_key($e->getMessage()) : 'command_mapping_failed';
                }
                $this->store->insert(['order_id' => $p['order_id'], 'reference' => $p['reference'],
                    'merchant_hash' => $p['merchant_hash'], 'amount' => $p['amount'], 'currency' => $p['currency'],
                    'command' => wp_json_encode($command), 'mode' => self::mode(),
                    'status' => $error ? 'BLOCKED' : 'QUEUED', 'error_code' => $error]);
                $row = $this->store->get($p['order_id']);
            }
            if ((int) $row['refunded']) {
                return $ack; // Late Approved cannot resurrect a refunded payment.
            }
            $this->applyPayment($order, $row);
            if (in_array($row['status'], ['QUEUED', 'RECONCILE'], true)) {
                $this->queue($p['order_id']);
            }
            return $ack;
        } catch (\Throwable) {
            return new \WP_Error('payment_processing_failed', '', ['status' => 503]);
        } finally {
            $this->store->unlock($p['order_id']);
        }
    }

    private function applyPayment(\WC_Order $order, array $row): void
    {
        if ((int) $row['payment_applied']) {
            return;
        }
        // Set trusted evidence before payment_complete triggers Folio and other consumers.
        $order->update_meta_data('_pc_wfp_verified', ['reference' => $row['reference'], 'amount_cents' => (int) $row['amount'], 'currency' => $row['currency']]);
        $order->save();
        if (!$order->get_date_paid()) {
            $order->payment_complete($row['reference']);
        }
        if (!$order->get_date_paid()) {
            throw new \RuntimeException('payment_transition_failed');
        }
        $this->store->update((int) $row['order_id'], ['payment_applied' => 1]);
    }

    private function queue(int $id, int $delay = 5): void
    {
        if (function_exists('as_schedule_single_action')) {
            as_schedule_single_action(time() + $delay, self::JOB, [$id], 'pc-wfp-checkbox', true);
        }
    }

    public function scheduleRecovery(): void
    {
        if (self::mode() !== 'off' && function_exists('as_has_scheduled_action') && !as_has_scheduled_action(self::SWEEP, [], 'pc-wfp-checkbox')) {
            as_schedule_recurring_action(time() + 60, 300, self::SWEEP, [], 'pc-wfp-checkbox', true);
        }
    }

    public function recover(): void
    {
        if (self::mode() !== 'off') {
            foreach ($this->store->pending() as $id) {
                $this->queue((int) $id);
            }
        }
    }

    public function work(int $id): void
    {
        if (self::mode() === 'off' || !$this->store->lock($id)) {
            return;
        }
        try {
            $row = $this->store->get($id);
            if (!$row || (int) $row['refunded'] || (int) $row['attempts'] >= 8 || (int) $row['next_run'] > time()
                || ((int) $row['payment_applied'] && !in_array($row['status'], ['QUEUED', 'RECONCILE'], true))) {
                return;
            }
            $order = wc_get_order($id);
            if (!$order || $row['mode'] !== self::mode()) {
                $this->store->update($id, ['status' => 'BLOCKED', 'error_code' => 'order_or_mode_changed']);
                return;
            }
            $attempt = (int) $row['attempts'] + 1;
            $this->store->update($id, ['attempts' => $attempt]);
            $this->applyPayment($order, $row);
            if (!in_array($row['status'], ['QUEUED', 'RECONCILE'], true)) {
                return;
            }
            if (defined('PC_WFP_CHECKBOX_PAUSED') && PC_WFP_CHECKBOX_PAUSED === true) {
                // Keep validating/recording payments while pausing fiscal work, without spending attempts.
                $this->store->update($id, ['attempts' => (int) $row['attempts'], 'next_run' => time() + 300]);
                return;
            }
            $service = \Paint\CheckboxFiscalization\Plugin::instance()->service();
            $command = json_decode($row['command'], true, 64, JSON_THROW_ON_ERROR);
            $existing = $service->operation($command['operation_key']);
            if ($existing && in_array($existing['status'], ['SUCCEEDED', 'FISCALIZED', 'PROCESSING', 'UNCERTAIN'], true)) {
                $result = $service->reconcile($command['operation_key']);
            } else {
                if ($row['mode'] !== 'preview') {
                    $connection = $service->connectionSummary();
                    if (is_wp_error($connection)) {
                        $this->retryOrBlock($id, $connection, $attempt, false);
                        return;
                    }
                    if ($connection['environment'] !== $row['mode']) {
                        $this->store->update($id, ['status' => 'BLOCKED', 'error_code' => 'fiscal_environment_mismatch']);
                        return;
                    }
                    if (!defined('PC_WFP_MERCHANT_MODE') || PC_WFP_MERCHANT_MODE !== $row['mode']) {
                        $this->store->update($id, ['status' => 'BLOCKED', 'error_code' => 'merchant_environment_not_confirmed']);
                        return;
                    }
                }
                // Keep contacts in Woo only. Freeze their HMAC before the first attempt; a changed address blocks retry.
                $email = (string) $order->get_billing_email();
                if (!is_email($email)) {
                    $this->store->update($id, ['status' => 'BLOCKED', 'error_code' => 'receipt_email_required']);
                    return;
                }
                $hash = hash_hmac('sha256', $email, wp_salt('auth'));
                if ($row['delivery_hash'] !== '' && !hash_equals($row['delivery_hash'], $hash)) {
                    $this->store->update($id, ['status' => 'BLOCKED', 'error_code' => 'receipt_email_changed']);
                    return;
                }
                $this->store->update($id, ['delivery_hash' => $hash]);
                $command['delivery'] = ['email' => $email];
                $result = pc_checkbox_fiscalize($command, $row['mode'] === 'preview' ? 'preview' : 'fiscalize');
            }
            if (is_wp_error($result)) {
                $this->retryOrBlock($id, $result, $attempt, $row['status'] === 'RECONCILE');
                return;
            }
            $status = $result['status'];
            if (in_array($status, ['SUCCEEDED', 'FISCALIZED'], true)) {
                $order->update_meta_data('_pc_wfp_checkbox_receipt', [
                    'id' => $result['checkbox_receipt_id'], 'fiscal_code' => $result['fiscal_code'],
                    'operation_key' => $result['operation_key'], 'mode' => $row['mode'],
                ]);
                $order->save();
                $this->store->update($id, ['status' => 'SUCCEEDED', 'error_code' => '']);
            } elseif ($status === 'PREVIEW') {
                $this->store->update($id, ['status' => 'PREVIEW', 'error_code' => '']);
            } elseif (in_array($status, ['PROCESSING', 'UNCERTAIN'], true)) {
                $delay = min(3600, 30 * (2 ** $attempt));
                $this->store->update($id, ['status' => 'RECONCILE', 'error_code' => '', 'next_run' => time() + $delay]);
                $this->queue($id, $delay);
            } else {
                $this->store->update($id, ['status' => 'BLOCKED', 'error_code' => 'receipt_terminal_failure']);
            }
        } catch (\Throwable) {
            // The executor's durable PROCESSING record is consulted first on the next run.
            $this->store->update($id, ['status' => 'RECONCILE', 'error_code' => 'worker_interrupted', 'next_run' => time() + 300]);
        } finally {
            $this->store->unlock($id);
        }
    }

    private function retryOrBlock(int $id, \WP_Error $error, int $attempt, bool $reconciling): void
    {
        $code = $error->get_error_code();
        $http = (int) ($error->get_error_data()['status'] ?? 0);
        $uncertain = $reconciling || in_array($code, ['reconciliation_required', 'receipt_not_confirmed'], true);
        $retry = $uncertain || $code === 'operation_busy' || $http === 429 || $http >= 500;
        $delay = min(3600, 30 * (2 ** $attempt));
        $this->store->update($id, ['status' => $retry ? ($uncertain ? 'RECONCILE' : 'QUEUED') : 'BLOCKED', 'error_code' => substr(sanitize_key($code), 0, 96), 'next_run' => time() + $delay]);
        if ($retry && $attempt < 8) {
            $this->queue($id, $delay);
        }
    }

    public function orderPanel(\WC_Order $order): void
    {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }
        $row = $this->store->get($order->get_id());
        if (!$row) {
            return;
        }
        $receipt = $order->get_meta('_pc_wfp_checkbox_receipt');
        echo '<p><strong>' . esc_html__('Checkbox receipt', 'pc-wayforpay-checkbox') . '</strong><br>';
        echo esc_html($row['status'] . ' / ' . $row['mode'] . ' / ' . $row['error_code']);
        echo '<br>' . esc_html__('Attempts', 'pc-wayforpay-checkbox') . ': ' . (int) $row['attempts'];
        if (is_array($receipt) && !empty($receipt['fiscal_code'])) {
            echo '<br>' . esc_html($receipt['fiscal_code']);
            echo '<br>' . esc_html__('Receipt ID', 'pc-wayforpay-checkbox') . ': ' . esc_html($receipt['id']);
            echo '<br><a target="_blank" rel="noopener noreferrer" href="' . esc_url('https://api.checkbox.ua/api/v1/receipts/' . rawurlencode($receipt['id']) . '/html') . '">' . esc_html__('View receipt', 'pc-wayforpay-checkbox') . '</a>';
        }
        echo '</p>';
    }

    public function orderActions(array $actions, ?\WC_Order $order = null): array
    {
        if ($order && current_user_can('manage_woocommerce') && $this->store->get($order->get_id())) {
            $actions['pc_wfp_checkbox_retry'] = __('Reconcile / resume Checkbox receipt', 'pc-wayforpay-checkbox');
        }
        return $actions;
    }

    public function manualRetry(\WC_Order $order): void
    {
        // Woo's order-action form enforces its nonce; capability is checked here too.
        if (!current_user_can('manage_woocommerce') || self::mode() === 'off') {
            return;
        }
        $id = $order->get_id();
        if (!$this->store->lock($id)) {
            return;
        }
        try {
            $row = $this->store->get($id);
            if (!$row || (int) $row['refunded'] || $row['mode'] !== self::mode()
                || in_array($row['status'], ['SUCCEEDED', 'PREVIEW'], true) || $row['command'] === '[]') {
                return;
            }
            $this->store->update($id, ['status' => 'QUEUED', 'attempts' => 0, 'next_run' => 0]);
            $this->queue($id);
        } finally {
            $this->store->unlock($id);
        }
    }
}
