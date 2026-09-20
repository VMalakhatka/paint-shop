<?php
/** Local email rendering only. No messages are sent; synthetic orders are cleaned up. */
use Paint\NovaPoshta\Email\DeliverySummary;
use Paint\NovaPoshta\Domain\ExternalShipmentPolicy;
use Paint\NovaPoshta\Infrastructure\ExternalShipmentStore;
use Paint\NovaPoshta\Infrastructure\ShipmentRepository;

if (!defined('WP_CLI') || !WP_CLI || wp_get_environment_type() !== 'local') { throw new RuntimeException('Local CLI only.'); }
add_filter('pre_wp_mail', '__return_true', PHP_INT_MAX);
add_filter('pre_http_request', static fn() => new WP_Error('blocked', 'HTTP blocked by email test'), PHP_INT_MAX);
$orders = []; $checks = 0;
$check = static function ($ok, $message) use (&$checks) { if (!$ok) { throw new RuntimeException($message); } $checks++; };
$fixture_dir = getenv('PNPM_EMAIL_FIXTURE_DIR');
$make = static function () use (&$orders) {
    $order = new WC_Order(); $order->set_status('pending'); $order->set_created_via('pnpm-email-test');
    $order->set_billing_email('pnpm-email-test@example.invalid'); $order->set_billing_first_name('Тестовий'); $order->set_billing_last_name('Покупець');
    $order->save(); $orders[] = $order->get_id();
    return $order;
};
try {
    switch_to_locale('uk');
    $parent = $make();
    $item = new WC_Order_Item_Product(); $item->set_name('Тестовий товар'); $item->set_quantity(5); $item->set_total(500); $item->set_subtotal(500);
    $item->update_meta_data('_pnpm_cart_key', 'email-test'); $parent->add_item($item);
    $shipping = new WC_Order_Item_Shipping(); $shipping->set_method_id(ExternalShipmentPolicy::RATE); $shipping->set_method_title('Customer TTN'); $shipping->set_total(0); $parent->add_item($shipping);
    $base = '990' . str_pad((string) random_int(1, 999999999), 11, '0', STR_PAD_LEFT);
    $numbers = [$base, (string) ((int) $base + 1)];
    $plan = [];
    foreach ([901 => 3, 902 => 2] as $location => $qty) {
        $plan[] = ['location_id' => $location, 'label' => $location === 901 ? 'Київ' : 'Одеса', 'ttn' => $numbers[count($plan)],
            'items' => [['cart_item_key' => 'email-test', 'sku' => 'EMAIL-TEST', 'quantity' => $qty]]];
    }
    $parent->update_meta_data('_pnpm_external_plan', $plan); $parent->calculate_totals(false); $parent->save();
    (new ExternalShipmentStore())->submit($parent);
    $summary = new DeliverySummary();
    $emails = WC()->mailer()->get_emails();
    foreach (['WC_Email_New_Order', 'WC_Email_Customer_Processing_Order', 'WC_Email_Customer_On_Hold_Order'] as $type) {
        $email = $emails[$type]; $email->object = $parent;
        $html = $email->get_content_html(); $plain = $email->get_content_plain();
        $check(substr_count($html, 'class="pnpm-email-delivery"') === 1, $type . ' single delivery block');
        $position = strpos($html, 'class="pnpm-email-delivery"');
        $check($position > strpos($html, '</h2>') && $position < strpos($html, 'Тестовий товар'), $type . ' after order heading and before products');
        $check(str_contains($html, $numbers[0]) && str_contains($html, $numbers[1]) && str_contains($html, 'Київ') && str_contains($html, 'Одеса'), $type . ' all warehouse TTNs');
        $check(str_contains($plain, $numbers[0]) && str_contains($plain, $numbers[1]) && !str_contains($plain, 'pnpm-email-delivery'), $type . ' plain text');
        if ($fixture_dir && is_dir($fixture_dir) && $type === 'WC_Email_New_Order') {
            file_put_contents($fixture_dir . '/new-order.html', $email->style_inline($html));
        }
    }
    $child = $make(); $child->update_meta_data('_folio_split_from_order_id', $parent->get_id()); $child->save();
    $html = $summary->render($child, false, false);
    $check(str_contains($html, $numbers[0]) && str_contains($html, 'усі склади'), 'Early split email shows original order scope');
    $check(!str_contains($html, 'wp-admin'), 'Customer email never links admin order');
    $check(str_contains($summary->render($child, true, false), 'wp-admin'), 'Manager original order link');
    $child->set_billing_email('unrelated@example.invalid'); $child->save();
    $check(!str_contains($summary->render($child, false, false), $numbers[0]), 'Unrelated guest cannot inherit TTNs');
    $child->delete_meta_data('_folio_split_from_order_id');
    $pickup = new WC_Order_Item_Shipping(); $pickup->set_method_id('local_pickup'); $pickup->set_method_title('Самовивіз зі складу'); $child->add_item($pickup); $child->save();
    $check(str_contains($summary->render($child, false, false), 'Самовивіз зі складу'), 'Pickup method displayed');
    $pickup->set_method_id('flat_rate'); $pickup->set_method_title('Доставка кур’єром'); $pickup->save();
    $check(str_contains($summary->render($child, false, false), 'Доставка кур’єром'), 'Other shipping title displayed');
    $pickup->set_method_title('<script>alert(1)</script>Пошта & доставка'); $pickup->save();
    $html = $summary->render($child, false, false);
    $check(!str_contains($html, '<script>') && str_contains($html, '&amp;'), 'Titles escaped');
    $child->remove_item($pickup->get_id()); $child->save();
    $check($summary->render($child, false, false) === '', 'No fabricated shipping method');
    $child->update_meta_data('_pnpm_external_plan', $plan); $child->save();
    $check(!str_contains($summary->render($child, false, false), $numbers[0]), 'Unsaved shipment plan not presented as registered TTN');
    echo "Email delivery: {$checks} checks passed. No email sent.\n";
} finally {
    restore_previous_locale();
    global $wpdb;
    foreach ($orders as $id) {
        foreach ((new ShipmentRepository())->findByOrder($id) as $row) {
            foreach (['pnpm_shipment_items', 'pnpm_shipment_events'] as $table) { $wpdb->delete($wpdb->prefix . $table, ['shipment_id' => $row['id']]); }
        }
        $wpdb->delete($wpdb->prefix . 'pnpm_shipments', ['order_id' => $id]);
        $order = wc_get_order($id); if ($order) { $order->delete(true); }
    }
}
