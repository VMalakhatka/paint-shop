<?php
/** Read-only customer progress; refreshing never retries a create or payment. */
defined('ABSPATH') || exit;

function pc_folio_customer_progress(WC_Order $order): array {
    $state = (string) $order->get_meta('_folio_auto_status');
    $online = $order->get_payment_method() === 'wayforpay';
    $verified = $order->get_meta('_pc_wfp_verified');
    $paid = $online && is_array($verified) && !empty($verified['reference'])
        && $verified['reference'] === $order->get_transaction_id() && $order->get_date_paid();
    $payment = $online ? ($paid
        ? __('Online payment confirmed.', 'pc-folio-order-link')
        : __('Online payment has not yet been confirmed. Please wait; do not pay again if funds have been charged.', 'pc-folio-order-link')) : '';
    $terminal = in_array($order->get_status(), ['failed', 'cancelled', 'refunded'], true);
    $result = pc_folio_get_order_documents_result($order);
    $link = pc_folio_get_order_document_link($order);
    $documents = !empty($result['documents']) || !empty($link['document_id']) || !empty($link['document_number']);
    $poll = false;
    if ($terminal || $state === 'error') {
        $message = __('Processing needs attention. Contact your manager; we will help clarify the result and resolve the issue. Do not repeat the order or payment.', 'pc-folio-order-link');
    } elseif ($state === 'running') {
        $message = __('Folio documents are being created. Please wait and keep this page open.', 'pc-folio-order-link');
        $poll = true;
    } elseif ($documents) {
        $message = __('Folio documents have been saved. You can continue; document details are shown below.', 'pc-folio-order-link');
        $poll = $online && !$paid;
    } elseif ($state === 'waiting_payment') {
        $message = $online
            ? __('Waiting for payment confirmation and Folio processing. Please keep this page open; the status will refresh automatically.', 'pc-folio-order-link')
            : __('Folio documents have not yet been confirmed. Use Continue if available, or contact your manager; we will help resolve this.', 'pc-folio-order-link');
        $poll = $online;
    } else {
        $message = __('Folio document creation has not yet been confirmed. Contact your manager if the status does not change; we will help resolve this.', 'pc-folio-order-link');
        $poll = $online && !$paid;
    }
    return compact('payment', 'message', 'poll');
}

function pc_folio_render_progress($order): void {
    if (!$order instanceof WC_Order) $order = wc_get_order(absint($order));
    if (!$order) return;
    static $shown = [];
    if (isset($shown[$order->get_id()])) return;
    $shown[$order->get_id()] = true;
    $view = pc_folio_customer_progress($order);
    echo '<section class="pc-folio-customer-notice" role="status" aria-live="polite">';
    if ($view['payment']) echo '<p>'.esc_html($view['payment']).'</p>';
    echo '<p class="pc-folio-progress-message">'.esc_html($view['message']).'</p>';
    if ($view['poll'] && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
        $timeout = __('Confirmation is taking longer than expected. Contact your manager; we will check the result and help resolve it. Do not repeat the payment or order.', 'pc-folio-order-link');
        echo '<p class="pc-folio-progress-timeout" hidden>'.esc_html($timeout).'</p>';
        ?>
        <script>
        (function(script){
            var key = 'pc-folio-progress-' + <?php echo (int)$order->get_id(); ?>;
            var start;
            try {
                start = Number(sessionStorage.getItem(key)) || Date.now();
                sessionStorage.setItem(key, String(start));
            } catch(e) { script.parentNode.querySelector('.pc-folio-progress-timeout').hidden = false; script.parentNode.querySelector('.pc-folio-progress-message').hidden = true; return; }
            if (Date.now() - start >= 120000) {
                script.parentNode.querySelector('.pc-folio-progress-timeout').hidden = false; script.parentNode.querySelector('.pc-folio-progress-message').hidden = true;
                return;
            }
            setTimeout(function(){
                // Only reload a read-only GET; never resubmit a form.
                window.location.reload();
            }, 10000);
        }(document.currentScript));
        </script>
        <?php
    }
    echo '</section>';
}
// Woo renders these hooks only after its normal order ownership/key checks.
add_action('woocommerce_order_details_before_order_table', 'pc_folio_render_progress', 4);
add_action('woocommerce_thankyou', 'pc_folio_render_progress', 4);
add_action('woocommerce_before_account_orders', static function(){
    $id = absint($_GET['folio_checkout_order'] ?? 0);
    $order = $id ? wc_get_order($id) : null;
    if ($order && get_current_user_id() > 0 && (int)$order->get_customer_id() === get_current_user_id()) {
        pc_folio_render_progress($order);
        pc_folio_render_customer_order_message($order);
    }
});
