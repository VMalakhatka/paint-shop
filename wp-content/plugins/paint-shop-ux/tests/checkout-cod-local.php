<?php
/** Run via wp eval-file on paint.local. Synthetic order, no carrier/Java/mail. */
if (!defined('WP_CLI') || !WP_CLI || parse_url(home_url(), PHP_URL_HOST) !== 'paint.local') throw new RuntimeException('Local only');
require_once dirname(__DIR__) . '/inc/checkout-cod.php';
add_filter('pre_http_request', static fn() => new WP_Error('fixture', 'External requests blocked'), PHP_INT_MAX);
add_filter('pre_wp_mail', '__return_true', PHP_INT_MAX);
$checks = 0; $id = 0; $cart = WC()->cart; $session = WC()->session;
$assert = static function ($ok, $message) use (&$checks) { if (!$ok) throw new RuntimeException($message); $checks++; };
try {
    WC()->session = new WC_Session_Handler();
    WC()->cart = new WC_Cart();
    $gateway = new WC_Gateway_COD();
    $order = new WC_Order();
    $order->set_created_via('psu-cod-fixture');
    $order->set_currency('UAH');
    $order->set_total('123.45');
    $order->set_payment_method($gateway);
    $shipping = new WC_Order_Item_Shipping();
    $shipping->set_method_id('pnpm_nova_poshta');
    $shipping->set_method_title('Synthetic NP');
    $order->add_item($shipping);
    $id = $order->save();
    $assert($id > 0, 'Fixture saved through Woo CRUD');
    $saved = wc_get_order($id);
    $assert($saved->get_payment_method() === 'cod', 'Standard manager-visible payment method persisted');
    $assert($saved->get_payment_method_title() === __('Payment on receipt (Nova Poshta)', 'paint-shop-ux'), 'Localized title persisted');
    $assert(!$saved->get_date_paid() && !$saved->get_transaction_id(), 'No payment confirmation');
    $result = $gateway->process_payment($id);
    $saved = wc_get_order($id);
    $assert($result['result'] === 'success', 'Native COD accepts the selection');
    $assert($saved->get_status() === 'on-hold' && !$saved->is_paid(), 'Native completion remains unpaid/on-hold');
    $assert(!$saved->get_date_paid() && !$saved->get_transaction_id(), 'No paid date or transaction after selection');
    $assert((float) $saved->get_total() === 123.45, 'Total unchanged');
    echo "Checkout COD local: {$checks} checks passed; synthetic order only.\n";
} finally {
    if ($id) { $saved = wc_get_order($id); if ($saved && $saved->get_created_via() === 'psu-cod-fixture') $saved->delete(true); }
    WC()->cart = $cart; WC()->session = $session;
}
