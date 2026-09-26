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
$empty = new WC_Order();
$check(Notices::subject('New order',$empty)==='New order' && Notices::reply_url($empty)==='', 'Missing email');
require_once ABSPATH.'wp-admin/includes/class-wp-screen.php'; require_once ABSPATH.'wp-admin/includes/screen.php';set_current_screen('dashboard');
$original_user = get_current_user_id(); $original_get=$_GET;
try {
 $managers=get_users(['role__in'=>['administrator','shop_manager'],'number'=>1]);
 if (!$managers) throw new RuntimeException('Manager required');
 wp_set_current_user($managers[0]->ID); $_GET=['view'=>'orders'];
 ob_start(); PaintCore\PCOE\ManagerWorkspace::render(); $html=ob_get_clean();
 $check(str_contains($html,'nav-tab-active') && str_contains($html,'<table') && str_contains($html,'orders'), 'Manager orders tab renders');
} finally { wp_set_current_user($original_user); $_GET=$original_get; }
echo "PASS: $checks manager notification checks\n";
