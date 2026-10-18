<?php
namespace Paint\WayForPayCheckbox;

/** Read-only admin projection. Never runs a worker, reconciliation or a fiscal API call. */
final class ReceiptPanel
{
    public function boot(): void
    {
        add_action('wp_ajax_pc_wfp_receipt_status', [$this, 'refresh']);
    }

    public function display(\WC_Order $order): void
    {
        if (!current_user_can('manage_woocommerce') || !current_user_can('edit_shop_order', $order->get_id())) return;
        $row = (new Store())->get($order->get_id());
        if (!$row) return;
        wp_enqueue_script('pc-wfp-receipt-status', plugins_url('assets/receipt-status.js', dirname(__DIR__) . '/pc-wayforpay-checkbox.php'), [], '0.2.1', true);
        echo '<div class="pc-wfp-receipt-panel" data-order="' . (int) $order->get_id() . '" data-nonce="' . esc_attr(wp_create_nonce('pc_wfp_receipt_status_' . $order->get_id())) . '" data-url="' . esc_url(admin_url('admin-ajax.php')) . '" data-failure="' . esc_attr__('Status refresh failed. The displayed result may be outdated. Try again.', 'pc-wayforpay-checkbox') . '">';
        echo '<div class="pc-wfp-receipt-content">' . $this->content($order, $row) . '</div>';
        echo '<button type="button" class="button pc-wfp-receipt-refresh">' . esc_html__('Refresh receipt status', 'pc-wayforpay-checkbox') . '</button>';
        echo '<p class="pc-wfp-receipt-refresh-note" role="status"></p></div>';
    }

    public function refresh(): void
    {
        $id = absint($_POST['order_id'] ?? 0);
        if (!current_user_can('manage_woocommerce') || !current_user_can('edit_shop_order', $id)) {
            wp_send_json_error([], 403);
            return;
        }
        if (!check_ajax_referer('pc_wfp_receipt_status_' . $id, 'nonce', false)) {
            wp_send_json_error([], 403);
            return;
        }
        $order = wc_get_order($id);
        $row = $order ? (new Store())->get($id) : null;
        if (!$order || !$row) {
            wp_send_json_error([], 404);
            return;
        }
        nocache_headers();
        wp_send_json_success(['html' => $this->content($order, $row)]);
    }

    private function content(\WC_Order $order, array $row): string
    {
        $command = json_decode($row['command'], true);
        $operation = !empty($command['operation_key'])
            ? \Paint\CheckboxFiscalization\Plugin::instance()->service()->operation($command['operation_key']) : null;
        return self::render($row, $operation, $order->get_meta('_pc_wfp_checkbox_receipt'));
    }

    /** Pure rendering also supports HPOS: all order metadata comes from WC_Order. */
    public static function render(array $row, ?array $operation, mixed $receipt): string
    {
        $pending = in_array($row['status'], ['QUEUED', 'RECONCILE'], true);
        $success = $row['status'] === 'SUCCEEDED';
        $confirmed = in_array($operation['status'] ?? '', ['SUCCEEDED', 'FISCALIZED'], true);
        if ($confirmed) {
            $receipt = ['id' => $operation['checkbox_receipt_id'] ?? '', 'fiscal_code' => $operation['fiscal_code'] ?? ''];
        } elseif (!$success) {
            $receipt = []; // Never present unrelated/stale order metadata as a confirmed receipt.
        }
        ob_start();
        echo '<div style="overflow-wrap:anywhere" data-pending="' . ($pending ? '1' : '0') . '"><p><strong>' . esc_html__('Checkbox receipt', 'pc-wayforpay-checkbox') . '</strong><br>';
        echo esc_html($row['status'] . ' / ' . $row['mode']);
        if (!$pending && !$success && $row['error_code'] !== '') echo ' / ' . esc_html($row['error_code']);
        echo '<br>' . esc_html__('Attempts', 'pc-wayforpay-checkbox') . ': ' . (int) $row['attempts'];
        echo '<br>' . esc_html__('Saved status updated (UTC)', 'pc-wayforpay-checkbox') . ': ' . esc_html($row['updated_at']);
        echo '</p>';
        if ($pending) {
            $message = $confirmed
                ? __('Receipt confirmed. The site is finishing status synchronization. Do not issue another receipt.', 'pc-wayforpay-checkbox')
                : ($row['status'] === 'RECONCILE'
                    ? __('Waiting for confirmation of the existing receipt. Do not issue another receipt.', 'pc-wayforpay-checkbox')
                    : __('Queued for processing. The background worker has not finished yet. Do not repeat the action.', 'pc-wayforpay-checkbox'));
            echo '<p>' . esc_html($message) . '</p>';
        }
        if (!$success && !$confirmed && (!empty($operation['error_message']) || !empty($row['error_code']))) {
            if ($pending) echo '<details><summary>' . esc_html__('Previous attempt diagnostic (not the current result)', 'pc-wayforpay-checkbox') . '</summary>';
            echo '<p>' . esc_html__('Checkbox diagnostic', 'pc-wayforpay-checkbox') . ': ';
            if (!empty($operation['error_message'])) {
                echo esc_html($operation['status'] . ' / HTTP ' . $operation['http_code'] . ' / ' . $operation['error_message']);
                if (!empty($operation['shift_id'])) echo '<br>' . esc_html__('Shift ID', 'pc-wayforpay-checkbox') . ': ' . esc_html($operation['shift_id']);
            } else {
                echo esc_html($row['error_code']);
            }
            echo '</p>';
            if ($pending) echo '</details>';
        }
        if (is_array($receipt) && !empty($receipt['fiscal_code']) && !empty($receipt['id'])) {
            echo '<p>' . esc_html($receipt['fiscal_code']) . '<br>' . esc_html__('Receipt ID', 'pc-wayforpay-checkbox') . ': ' . esc_html($receipt['id']);
            echo '<br><a target="_blank" rel="noopener noreferrer" href="' . esc_url('https://api.checkbox.ua/api/v1/receipts/' . rawurlencode($receipt['id']) . '/html') . '">' . esc_html__('View receipt', 'pc-wayforpay-checkbox') . '</a></p>';
        }
        echo '</div>';
        return (string) ob_get_clean();
    }
}
