<?php
namespace Paint\WayForPayCheckbox;

/** Bank income is independent of invoice creation and Checkbox receipt status. */
final class FolioPayments
{
    public const JOB = 'pc_wfp_folio_payment';
    public const SWEEP = 'pc_wfp_folio_recover';
    private FolioStore $store;
    private Store $payments;
    private FolioClient $client;
    public function __construct(?FolioClient $client = null)
    {
        $this->store = new FolioStore(); $this->payments = new Store(); $this->client = $client ?? new FolioClient();
    }
    public static function mode(): string
    {
        $mode = defined('PC_WFP_FOLIO_MODE') ? PC_WFP_FOLIO_MODE : 'off';
        return in_array($mode, ['preview', 'live'], true) ? $mode : 'off';
    }
    public function boot(): void
    {
        add_action('pc_wfp_verified_payment_saved', [$this, 'capture'], 10, 2);
        add_filter('pc_wfp_folio_payer_snapshot', [$this, 'payerSnapshot'], 10, 2);
        add_action('pc_wfp_verified_refund_saved', [$this, 'refund']);
        add_action(self::JOB, [$this, 'work']);
        add_action(self::SWEEP, [$this, 'recover']);
        add_action('action_scheduler_init', [$this, 'scheduleRecovery']);
        add_action('woocommerce_admin_order_data_after_order_details', [$this, 'panel']);
        add_filter('woocommerce_order_actions', [$this, 'actions'], 10, 2);
        add_action('woocommerce_order_action_pc_wfp_folio_retry', [$this, 'retry']);
    }
    public function payerSnapshot(string $unused, \WC_Order $order): string
    {
        $payer = trim((string) get_user_meta((int) $order->get_user_id(), '_folio_partner_short_name', true));
        if ($payer === '') $payer = trim((string) get_user_meta((int) $order->get_user_id(), '_folio_partner_id', true));
        if ($payer === '' && defined('PC_GUEST_CUSTOMER_FOLIO_ID')) $payer = (string) PC_GUEST_CUSTOMER_FOLIO_ID;
        return $payer;
    }
    /** Called under the shared payment lock, only after signature/amount/Approved validation. */
    public function capture(\WC_Order $order, array $payment): void
    {
        if (self::mode() === 'off') return;
        $id = (int) $payment['order_id'];
        if ($this->store->get($id)) return;
        $rawStart = defined('PC_WFP_FOLIO_START_AT') ? (string) PC_WFP_FOLIO_START_AT : '';
        $start = preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D', $rawStart) ? strtotime($rawStart) : false;
        $created = $order->get_date_created();
        $verified = !empty($payment['approved_at']) ? strtotime($payment['approved_at'] . ' UTC') : false;
        // No scanning/backfill of past orders; enabling a feature cannot book an old manual payment.
        if (!$start) throw new \RuntimeException('folio_cutoff_required');
        if (!$created || $created->getTimestamp() < $start || !$verified || $verified < $start) return;
        $scope = defined('PC_WFP_FOLIO_SOURCE_SCOPE') ? (string) PC_WFP_FOLIO_SOURCE_SCOPE : '';
        $payer = (string) ($payment['folio_payer_key'] ?? '');
        $error = '';
        $key = hash('sha256', $scope . '|' . $payment['merchant_hash'] . '|' . $payment['reference']);
        $command = [];
        try {
            $command = FolioCommand::build($payment, $scope, $payer, gmdate('Y-m-d\TH:i:s\Z', $verified));
            if ($order->get_meta('_folio_manual_payment_recovery', true)) throw new \RuntimeException('folio_manual_payment_exists');
        } catch (\RuntimeException $e) { $error = $e->getMessage(); }
        $this->store->insert(['order_id' => $id, 'operation_key' => $key,
            'request_hash' => $command ? FolioCommand::hash($command) : '', 'command' => wp_json_encode($command),
            'mode' => self::mode(), 'status' => $error ? 'REVIEW' : 'QUEUED', 'error_code' => $error,
            'refunded' => (int) $payment['refunded']]);
        $this->queue($id);
    }
    public function refund(int $id): void
    {
        $row = $this->store->get($id);
        if (!$row) return;
        $data = ['refunded' => 1];
        if ($row['status'] === 'SUCCEEDED') $data += ['status' => 'REFUND_REVIEW', 'error_code' => 'folio_refund_expense_required'];
        $this->store->update($id, $data);
        // Do not erase the income fact. A refund is a separate expense, never an automatic deletion.
    }
    private function queue(int $id): void
    {
        if (function_exists('as_schedule_single_action')) as_schedule_single_action(time() + 5, self::JOB, [$id], 'pc-wfp-folio', true);
    }
    public function scheduleRecovery(): void
    {
        if (self::mode() !== 'off' && function_exists('as_has_scheduled_action')
            && !as_has_scheduled_action(self::SWEEP, [], 'pc-wfp-folio')) {
            as_schedule_recurring_action(time() + 60, 300, self::SWEEP, [], 'pc-wfp-folio', true);
        }
    }
    public function recover(): void
    {
        if (self::mode() !== 'off') foreach ($this->store->pending() as $id) $this->queue((int) $id);
    }
    public function work(int $id): void
    {
        if (self::mode() === 'off' || (defined('PC_WFP_FOLIO_PAUSED') && PC_WFP_FOLIO_PAUSED === true)) return;
        if (!$this->payments->lock($id)) return;
        $row = null;
        try {
            $row = $this->store->get($id);
            if (!$row || !in_array($row['status'], ['QUEUED', 'RECONCILE'], true) || (int) $row['next_run'] > time()) return;
            if ((int) $row['attempts'] >= 12) { $this->review($id, 'folio_attempt_limit'); return; }
            $payment = $this->payments->get($id);
            $c = json_decode($row['command'], true);
            if (!$payment || !$c || !hash_equals($row['request_hash'], FolioCommand::hash($c))
                || $payment['reference'] !== $c['reference'] || $payment['merchant_hash'] !== $c['merchantHash']
                || (int) $payment['amount'] !== $c['amountCents'] || $payment['currency'] !== $c['currency']
                || ($payment['folio_payer_key'] ?? '') !== $c['payerKey']
                || gmdate('Y-m-d\TH:i:s\Z', strtotime(($payment['approved_at'] ?? '') . ' UTC')) !== $c['confirmedAt']) {
                $this->review($id, 'folio_payment_evidence_changed'); return;
            }
            if ($row['mode'] !== self::mode() || (string) $c['sourceScope'] !== (defined('PC_WFP_FOLIO_SOURCE_SCOPE') ? PC_WFP_FOLIO_SOURCE_SCOPE : '')
                || ($row['mode'] === 'live' && ($payment['mode'] !== 'live'
                    || !defined('PC_WFP_MERCHANT_MODE') || PC_WFP_MERCHANT_MODE !== 'live'))) {
                $this->review($id, 'folio_mode_changed'); return;
            }
            if ((int) $payment['refunded']) $this->store->update($id, ['refunded' => 1]);
            $this->store->update($id, ['attempts' => (int) $row['attempts'] + 1]);
            // Always lookup first. After any attempted apply, lookup is the ONLY automatic request.
            $result = $this->client->call('GET', $row['operation_key']);
            if ($result['http'] === 200) { $this->acceptResult($id, $row, $result['data']); return; }
            if ($result['http'] !== 404) { $this->failure($id, $row, $result); return; }
            if ((int) $row['sent']) { $this->review($id, 'folio_unknown_outcome_not_found'); return; }
            $result = $this->client->call('POST', 'preview', $c);
            if ($result['http'] !== 200) { $this->failure($id, $row, $result); return; }
            $data = $result['data'];
            if (($data['status'] ?? '') !== 'READY') { $this->acceptResult($id, $row, $data); return; }
            if (!$this->matches($row, $data) || !preg_match('/^[a-f0-9]{64}$/D', (string) ($data['revision'] ?? ''))) {
                $this->review($id, 'folio_response_mismatch'); return;
            }
            if ($row['mode'] === 'preview') { $this->store->update($id, ['status' => 'PREVIEW', 'error_code' => '']); return; }
            // Persist before HTTP. A process crash must never cause another automatic POST.
            $this->store->update($id, ['sent' => 1, 'status' => 'RECONCILE']);
            $row['sent'] = 1;
            $result = $this->client->call('POST', 'apply', $c + ['expectedRevision' => $data['revision']]);
            if ($result['http'] === 200) $this->acceptResult($id, $row, $result['data']);
            else $this->failure($id, $row, $result);
        } catch (\Throwable) {
            // If persisting the sent marker failed, no HTTP was sent; a DB outage must remain retryable.
            if ($row) $this->failure($id, $row, ['http' => 0, 'code' => 'folio_processing_failed']);
        } finally { $this->payments->unlock($id); }
    }
    private function matches(array $row, array $data): bool
    {
        $c = json_decode($row['command'], true);
        return ($data['operationKey'] ?? '') === $row['operation_key']
            && ($data['requestHash'] ?? '') === $row['request_hash']
            && ($data['amountCents'] ?? null) === ($c['amountCents'] ?? -1)
            && ($data['paymentWarehouse'] ?? null) === 5;
    }
    private function acceptResult(int $id, array $row, array $data): void
    {
        if (!$this->matches($row, $data) || ($data['status'] ?? '') !== 'SUCCEEDED'
            || (int) ($data['paymentId'] ?? 0) <= 0 || (int) ($data['paymentNumber'] ?? 0) <= 0) {
            $this->review($id, 'folio_result_requires_review'); return;
        }
        $fresh = $this->store->get($id);
        $refunded = !empty($fresh['refunded']);
        $this->store->update($id, ['status' => $refunded ? 'REFUND_REVIEW' : 'SUCCEEDED',
            'error_code' => $refunded ? 'folio_refund_expense_required' : '',
            'payment_id' => (int) $data['paymentId'], 'payment_number' => (int) $data['paymentNumber']]);
        $order = wc_get_order($id);
        if ($order) $order->add_order_note(sprintf(__('Folio bank income #%s recorded on warehouse 5. Invoice allocation is separate.', 'pc-wayforpay-checkbox'), $data['paymentNumber']));
    }
    private function review(int $id, string $code): void { $this->store->update($id, ['status' => 'REVIEW', 'error_code' => $code]); }
    private function failure(int $id, array $row, array $result): void
    {
        $attempts = (int) $row['attempts'] + 1;
        if ($attempts >= 12 || in_array($result['http'], [400, 401, 403, 409, 422], true)) {
            $this->review($id, $result['code'] ?: 'folio_request_rejected'); return;
        }
        $this->store->update($id, ['status' => !empty($row['sent']) ? 'RECONCILE' : 'QUEUED',
            'error_code' => $result['code'] ?: 'folio_unavailable', 'next_run' => time() + min(3600, 30 * (2 ** min($attempts, 7)))]);
    }
    public function panel(\WC_Order $order): void
    {
        if (!current_user_can('manage_woocommerce')) return;
        $row = $this->store->get($order->get_id());
        echo '<p><strong>' . esc_html__('Folio bank income', 'pc-wayforpay-checkbox') . '</strong>: ';
        echo esc_html($row ? $row['status'] . ($row['payment_number'] ? ' #' . $row['payment_number'] : '') : self::mode());
        if ($row && $row['error_code']) echo '<br><code>' . esc_html($row['error_code']) . '</code>';
        if ($row && in_array($row['status'], ['REVIEW', 'REFUND_REVIEW'], true)) {
            echo '<br>' . esc_html__('Check the payment in Folio before any manual entry. Reconciliation does not send the payment again.', 'pc-wayforpay-checkbox');
        }
        if ($row && !empty($row['refunded'])) {
            echo '<br>' . esc_html__('WayForPay refund received. Income is retained; a separate bank expense requires review.', 'pc-wayforpay-checkbox');
        }
        echo '</p>';
    }
    public function actions(array $actions, ?\WC_Order $order = null): array
    {
        if (current_user_can('manage_woocommerce') && $order instanceof \WC_Order && $this->store->get($order->get_id()))
            $actions['pc_wfp_folio_retry'] = __('Reconcile / resume Folio bank income', 'pc-wayforpay-checkbox');
        return $actions;
    }
    /** Woo's native order-action form supplies its nonce. This never resets a sent marker. */
    public function retry(\WC_Order $order): void
    {
        if (!current_user_can('manage_woocommerce') || !$this->payments->lock($order->get_id())) return;
        try {
            $row = $this->store->get($order->get_id());
            if (!$row || in_array($row['status'], ['SUCCEEDED', 'PREVIEW', 'REFUND_REVIEW'], true)) return;
            $this->store->update($order->get_id(), ['status' => (int) $row['sent'] ? 'RECONCILE' : 'QUEUED', 'attempts' => 0, 'next_run' => 0]);
            $this->queue($order->get_id());
        } finally { $this->payments->unlock($order->get_id()); }
    }
}
