<?php
/** wp eval-file; isolated synthetic records, all outbound HTTP/mail blocked. */
use PaintCore\PCOE\CustomerApproval as Approval;
if (!defined('WP_CLI') || !WP_CLI || wp_parse_url(home_url(), PHP_URL_HOST) !== 'paint.local') throw new RuntimeException('Local test only');
add_filter('pre_wp_mail', '__return_true', PHP_INT_MAX);
$http = static function ($pre, $args, $url) {
    if (($args['method'] ?? 'GET') !== 'GET' || !str_contains($url, '/customer-documents/ACCOUNT/42')) return new WP_Error('blocked', 'Unexpected HTTP blocked');
    return ['response' => ['code' => 200], 'headers' => [], 'body' => wp_json_encode(['ok' => true,
        'partner' => ['shortName' => 'TEST'], 'document' => ['documentType' => 'ACCOUNT', 'documentId' => 42,
        'documentNumber' => 'TEST-42', 'totalAmount' => 20, 'warehouseId' => 1, 'items' => [
            ['sku' => 'TEST', 'name' => 'Synthetic product', 'quantity' => 2, 'price' => 10, 'amount' => 20]]]])];
};
add_filter('pre_http_request', $http, PHP_INT_MAX, 3);
$invoke = static fn($method, ...$args) => (new ReflectionMethod(Approval::class, $method))->invoke(null, ...$args);
$check = static function ($value, $label) { if (!$value) throw new RuntimeException($label); };
$uid = 0; $order = null; $post_id = 0; $original = get_current_user_id();
try {
    $uid = wp_insert_user(['user_login' => 'approval-test-' . wp_generate_uuid4(), 'user_pass' => wp_generate_password(32), 'role' => 'opt']);
    if (is_wp_error($uid)) throw new RuntimeException($uid->get_error_message());
    update_user_meta($uid, '_folio_partner_short_name', 'TEST'); update_user_meta($uid, '_folio_partner_id', 'TEST');
    wp_set_current_user($uid);
    $folio = ['customer_id' => $uid, 'order_id' => 0, 'type' => 'ACCOUNT', 'document_id' => 42];
    $snap = Approval::snapshot($folio);
    $check($snap['total'] === '20' && count($snap['items']) === 1, 'Standalone Folio snapshot');
    $order = wc_create_order(['customer_id' => $uid, 'status' => 'pc-draft']);
    $item = new WC_Order_Item_Product(); $item->set_name('Synthetic product'); $item->set_quantity(2); $item->set_total(20); $item->set_subtotal(20);
    $order->add_item($item); $order->calculate_totals(); $order->update_meta_data('_folio_document_id', '42'); $order->save();
    $_REQUEST = ['customer_id' => $uid, 'document_type' => 'ACCOUNT', 'document_id' => 42];
    $source = $invoke('source_from_request');
    $check((int) $source['order_id'] === $order->get_id(), 'Linked document resolves to Woo order');
    $snapshot = Approval::snapshot($source);
    $check(count($snapshot['documents']) === 1, 'Linked Folio included');
    $data = ['source' => $source, 'snapshot' => $snapshot, 'revision' => Approval::revision($snapshot), 'status' => 'pending', 'history' => []];
    $post_id = $invoke('locked', Approval::key($source), fn() => $invoke('save', $data));
    $check((int) $invoke('find', $source)->ID === $post_id, 'Persistent target lookup');
    ob_start(); $invoke('details', get_post($post_id), false); $form_html = ob_get_clean();
    $check(str_contains($form_html, 'name="consent"') && str_contains($form_html, 'name="payment"'), 'Customer form exposes required controls');
    if (getenv('PCOE_APPROVAL_PREVIEW')) {
        $form_html = preg_replace('/<input[^>]*type="hidden"[^>]*>/', '', $form_html);
        $form_html = preg_replace('/action="[^"]*"/', 'action="#"', $form_html);
        file_put_contents('/tmp/customer-approval-preview.html', '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><style>body{font:16px system-ui;margin:24px;max-width:960px}table{border-collapse:collapse;width:100%}th,td{padding:10px;border:1px solid #ddd}select,textarea{max-width:100%;padding:8px;box-sizing:border-box}button{padding:12px;background:#7952b3;color:white;border:0}</style>' . $form_html);
    }
    $choices = ['payment' => ['bacs' => 'Bank'], 'delivery' => ['local_pickup:1' => 'Pickup']];
    $input = ['revision' => $data['revision'], 'consent' => '1', 'payment' => 'bacs', 'delivery' => 'local_pickup:1', 'recipient' => 'Test', 'phone' => '0000', 'destination' => 'Warehouse'];
    $data = Approval::confirmed($data, $uid, Approval::revision(Approval::snapshot($source)), $input, $choices);
    $invoke('save', $data, $post_id);
    $saved = $invoke('data', $invoke('read', $post_id));
    $check($saved['status'] === 'confirmed' && $saved['preferences']['payment']['id'] === 'bacs', 'Preference and audit persist');
    $fresh_order = wc_get_order($order->get_id());
    $check($fresh_order->get_status() === 'pc-draft' && !$fresh_order->get_date_paid() && !$fresh_order->get_payment_method(), 'Order status and payment unchanged');
    ob_start(); $invoke('details', get_post($post_id), true); $html = ob_get_clean();
    $check(str_contains($html, 'Bank') && str_contains($html, 'Warehouse'), 'Manager sees saved choices');
    $item->set_quantity(3); $item->set_total(30); $item->save(); $order->calculate_totals(); $order->save();
    $check(Approval::revision(Approval::snapshot($source)) !== $data['revision'], 'Order edit invalidates confirmation');
    $options = Approval::choices(); $check(is_array($options['delivery']) && is_array($options['payment']), 'Configured options load');
    echo "Local approval persistence, Folio ownership, linked order, rendering and no-payment checks passed\n";
} finally {
    if ($post_id) wp_delete_post($post_id, true);
    if ($order) $order->delete(true);
    wp_set_current_user($original);
    if ($uid && !is_wp_error($uid)) { require_once ABSPATH . 'wp-admin/includes/user.php'; wp_delete_user($uid); }
    remove_filter('pre_http_request', $http, PHP_INT_MAX);
}
