<?php
/** Read-only local regression: unsaved orders, no email or HTTP delivered. */
use PaintCore\PCOE\ManagerNotifications as Notices;
if (!defined('WP_CLI') || !WP_CLI || wp_parse_url(home_url(), PHP_URL_HOST) !== 'paint.local') throw new RuntimeException('Local only');
add_filter('pre_wp_mail', '__return_true', PHP_INT_MAX);
add_filter('pre_http_request', static fn() => new WP_Error('test', 'Blocked'), PHP_INT_MAX);
$checks = 0;
$check = static function($value, $message) use (&$checks) { if (!$value) throw new RuntimeException($message); $checks++; };
$order = new WC_Order(); $order->set_date_created('2026-09-26 10:00:00'); $order->set_billing_email('customer@example.invalid');
$check(Notices::subject('New order', $order) === 'customer@example.invalid | New order', 'Subject starts with email');
$original = "Content-Type: text/html\r\nReply-to: Shop <shop@example.invalid>\r\nCc: other@example.invalid\r\n";
$headers = Notices::headers($original, 'new_order', $order);
$check(substr_count(strtolower($headers), 'reply-to:') === 1 && str_contains($headers, 'Reply-To: customer@example.invalid'), 'One customer reply header even without name');
$check(str_contains($headers, 'Cc: other@example.invalid'), 'Other headers retained');
$check(Notices::headers($original, 'customer_processing_order', $order) === $original, 'Customer mail unchanged');
$check(str_starts_with(Notices::reply_url($order), 'mailto:customer@example.invalid?subject='), 'Mailto subject encoded');
foreach ([false,true] as $plain) {
 ob_start(); Notices::contact($order,true,$plain,(object)['id'=>'new_order']); $body=ob_get_clean();
 $check(str_contains($body,'customer@example.invalid') && str_contains($body,'mailto:'), 'HTML/plain reply contact');
}
ob_start(); Notices::contact($order,false,false,(object)['id'=>'new_order']); $check(ob_get_clean()==='', 'No customer email changes');
$mail = WC()->mailer()->get_emails()['WC_Email_New_Order'];
$mail->object = $order;
$check(str_starts_with($mail->get_subject(), 'customer@example.invalid | '), 'Woo subject hook active');
$check(str_contains($mail->get_headers(), 'Reply-To: customer@example.invalid'), 'Woo header hook active');
$check(str_contains($mail->get_content_html(), 'mailto:customer@example.invalid'), 'Actual HTML template contains reply link');
$order->update_meta_data('_folio_documents_result', ['documents' => [
 ['document_id' => 101, 'document_number' => 'A-101', 'document_date' => '2026-09-20T00:00:00', 'document_created_at' => '2026-09-26T10:00:00'],
 ['document_id' => 102, 'document_number' => 'B&102', 'document_date' => [2026,9,21]],
 ['document_id' => 103, 'document_number' => 'C-103', 'document_created_at' => '2026-09-26T10:00:00'],
]]);
$order->update_meta_data('_folio_document_id', 101); $order->update_meta_data('_folio_document_number', 'A-101');
$rows = Notices::documents($order);
$check(count($rows) === 3 && $rows[0]['date'] === '20.09.2026' && $rows[1]['date'] === '21.09.2026', 'Multiple warehouse dates and duplicate direct link');
$check($rows[2]['date'] === '', 'Created timestamp never replaces document date');
$check(str_contains($mail->get_content_html(), 'A-101') && str_contains($mail->get_content_html(), '20.09.2026'), 'Folio references in actual HTML email');
$check(str_contains($mail->get_content_plain(), 'C-103'), 'Folio references in plain email');
$preview = new WC_Order(); $preview->update_meta_data('_folio_documents_result', ['preview_only'=>true, 'documents'=>[['document_id'=>999,'document_number'=>'PREVIEW']]]);
$check(Notices::documents($preview) === [], 'Preview excluded');
$link = pc_folio_get_single_document_link(['document_id'=>101, 'document_number'=>'A-101', 'document_date'=>'2026-09-20T00:00:00']);
$check($link['document_date'] === '2026-09-20T00:00:00', 'Date preserved in direct and child links');
$empty = new WC_Order();
$check(Notices::subject('New order',$empty)==='New order' && Notices::reply_url($empty)==='', 'Missing email');
require_once ABSPATH.'wp-admin/includes/class-wp-screen.php'; require_once ABSPATH.'wp-admin/includes/screen.php';set_current_screen('dashboard');
$original_user = get_current_user_id(); $original_get=$_GET;
try {
 $managers=get_users(['role__in'=>['administrator','shop_manager'],'number'=>1]);
 if (!$managers) throw new RuntimeException('Manager required');
 wp_set_current_user($managers[0]->ID); $_GET=['view'=>'orders'];
 $order->set_customer_note('Test comment <script>alert(1)</script>');
 $order->set_shipping_first_name('Test recipient'); $order->set_shipping_address_1('Test address');
 $order->set_billing_phone('000000000');
 ob_start(); PaintCore\PCOE\ManagerOrderDetails::render($order); $details = ob_get_clean();
 $check(str_contains($details,'Test recipient') && str_contains($details,'Test address') && str_contains($details,'Test comment'), 'Inline delivery and comment');
 $check(!str_contains($details,'<script>'), 'Customer comment escaped');
 wp_set_current_user(0);
 ob_start(); PaintCore\PCOE\ManagerOrderDetails::render($order); $check(ob_get_clean()==='', 'Private notes protected by manager capability');
 wp_set_current_user($managers[0]->ID);

 ob_start(); PaintCore\PCOE\ManagerWorkspace::render(); $html=ob_get_clean();
 $check(str_contains($html,'<details>'), 'Manager help available');
 $clients = get_users(['role__in'=>['opt','partner'], 'number'=>1]);
 if (!$clients) throw new RuntimeException('Local wholesale customer required');
 wp_set_current_user($clients[0]->ID);
 ob_start(); pc_wholesale_help_render_endpoint(); $guide = ob_get_clean();
 $check(str_contains($guide,'id="order-details"'), 'Wholesale guide section accessible');
 $check(str_contains($html,'nav-tab-active') && str_contains($html,'<table') && str_contains($html,'orders'), 'Manager orders tab renders');
} finally { wp_set_current_user($original_user); $_GET=$original_get; }
echo "PASS: $checks manager notification checks\n";
