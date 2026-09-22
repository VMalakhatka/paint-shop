<?php
/** Local integration test. Synthetic orders are removed; all HTTP and mail are intercepted. */
use Paint\NovaPoshta\Checkout\ExternalTtnCheckout;
use Paint\NovaPoshta\Admin\ExternalTtnPanel;
use Paint\NovaPoshta\Domain\ExternalShipmentPolicy as Policy;
use Paint\NovaPoshta\Infrastructure\ExternalShipmentStore;
use Paint\NovaPoshta\Infrastructure\ShipmentRepository;
use Paint\NovaPoshta\Infrastructure\ExternalTracking;

if (!defined('WP_CLI') || !WP_CLI || wp_get_environment_type() !== 'local') { throw new RuntimeException('Local CLI only.'); }
add_filter('pre_wp_mail', '__return_true', PHP_INT_MAX);
$tracking_code = '1'; $http_calls = 0;
add_filter('pre_http_request', static function ($pre, $args, $url) use (&$tracking_code, &$http_calls) {
    $body = json_decode($args['body'] ?? '{}', true);
    if (($body['modelName'] ?? '') !== 'TrackingDocument' || ($body['calledMethod'] ?? '') !== 'getStatusDocuments') {
        return new WP_Error('test_block', 'HTTP blocked by test');
    }
    $http_calls++;
    if ($tracking_code === 'timeout') { return new WP_Error('timeout', 'Synthetic timeout'); }
    if ($tracking_code === 'invalid') { return ['response' => ['code' => 200], 'headers' => [], 'body' => wp_json_encode(['success' => false, 'errorCodes' => ['20001401442']])]; }
    return ['response' => ['code' => 200], 'headers' => [], 'body' => wp_json_encode(['success' => true, 'data' => [[
        'Number' => $body['methodProperties']['Documents'][0]['DocumentNumber'], 'StatusCode' => $tracking_code,
        'Status' => 'Synthetic tracking status', 'PhoneRecipient' => 'PRIVATE_NOT_TO_STORE',
    ]]])];
}, PHP_INT_MAX, 3);
$orders = []; $users = []; $checks = 0; $original_user = get_current_user_id();
$assert = static function ($condition, $message) use (&$checks) { if (!$condition) { throw new RuntimeException($message); } $checks++; };
$reject = static function (callable $fn) use ($assert) { try { $fn(); } catch (RuntimeException $e) { $assert(true, 'rejected'); return; } throw new RuntimeException('Expected rejection'); };
$tag = strtolower(wp_generate_password(8, false));
$mappings = [901 => ['enabled' => 'yes', 'city_ref' => 'test', 'customer_label' => 'Warehouse A'], 902 => ['enabled' => 'yes', 'city_ref' => 'test', 'customer_label' => 'Warehouse B']];
add_filter('pre_option_pnpm_location_mappings', static fn() => $mappings);
add_filter('pre_option_pnpm_settings', static fn() => ['checkout_enabled' => 'yes', 'external_ttn_enabled' => 'yes']);
try {
    foreach (['opt', 'customer'] as $role) {
        $id = wp_insert_user(['user_login' => 'pnpm-test-' . $role . '-' . $tag, 'user_email' => $role . '-' . $tag . '@example.invalid', 'user_pass' => wp_generate_password(30), 'role' => $role]);
        if (is_wp_error($id)) { throw new RuntimeException($id->get_error_message()); }
        $users[] = $id;
    }
    wp_set_current_user($users[0]);
    wc_load_cart();
    WC()->customer = new WC_Customer($users[0]);
    WC()->customer->set_shipping_country('UA');
    $product = new WC_Product_Simple(); $product->set_name('Synthetic product'); $product->set_price(100); $product->set_weight(500);
    WC()->cart->cart_contents = ['testkey' => ['data' => $product, 'product_id' => 0, 'variation_id' => 0, 'quantity' => 5,
        'line_total' => 500, 'line_subtotal' => 500, 'line_tax' => 0, 'line_subtotal_tax' => 0, 'pc_alloc_plan' => [901 => 3, 902 => 2]]];
    WC()->session->set('chosen_shipping_methods', [Policy::RATE]);
    WC()->session->set('order_awaiting_payment', 0);
    $checkout = new ExternalTtnCheckout(); $store = new ExternalShipmentStore(); $repo = new ShipmentRepository(); $panel = new ExternalTtnPanel();
    $ordinary = new WC_Order();
    $ordinary->set_created_via('pnpm-local-test');
    $ordinary->set_status('pending');
    $ordinary->save();
    $orders[] = $ordinary->get_id();
    foreach (['', [], null] as $empty_plan) {
        $ordinary->update_meta_data('_pnpm_external_plan', $empty_plan);
        $checkout->persist($ordinary);
        $assert(!$repo->findByOrder($ordinary->get_id()), 'Empty plan creates no shipments');
    }
    foreach (['broken', [''], [['location_id' => 901]]] as $invalid_plan) {
        $ordinary->update_meta_data('_pnpm_external_plan', $invalid_plan);
        $reject(fn() => $checkout->persist($ordinary));
        $assert(!$repo->findByOrder($ordinary->get_id()), 'Malformed plan creates no shipments');
    }
    $ordinary->delete_meta_data('_pnpm_external_plan');
    do_action('woocommerce_checkout_order_created', $ordinary);
    $assert(!$repo->findByOrder($ordinary->get_id()), 'Ordinary checkout-created hook completes without TTN');
    $assert(Policy::allowed(), 'Wholesale allowed');
    $rates = $checkout->rates([], ['destination' => ['country' => 'UA']]);
    $assert(isset($rates[Policy::RATE]) && (float) $rates[Policy::RATE]->get_cost() === 0.0, 'Zero local shipping charge');
    $assert(!isset($checkout->rates([], ['destination' => ['country' => 'DE']])[Policy::RATE]), 'Domestic only');
    $data = ['shipping_method' => [Policy::RATE], 'payment_method' => 'bacs'];
    $ttn1 = '990' . substr(str_pad((string) random_int(1, 999999999), 11, '0', STR_PAD_LEFT), -11);
    $ttn2 = (string) ((int) $ttn1 + 1);
    $_POST = ['pnpm_external_ttn' => [901 => "Please ship\nTTN " . $ttn1, 902 => $ttn2], 'pnpm_external_confirm' => '1'];
    $errors = new WP_Error(); $checkout->validate($data, $errors);
    $assert(!$errors->has_errors(), 'Two warehouse checkout valid: ' . implode(';', $errors->get_error_messages()));
    $fixture_dir = getenv('PNPM_TEST_FIXTURE_DIR');
    if ($fixture_dir && is_dir($fixture_dir)) {
        switch_to_locale('uk');
        $checkout->capture(http_build_query($_POST));
        ob_start(); $checkout->fields(); $fields = ob_get_clean();
        $assert(str_contains($fields, 'Відправка за моєю ТТН'), 'Translated checkout render');
        $jquery = file_get_contents(ABSPATH . 'wp-includes/js/jquery/jquery.min.js');
        $script = file_get_contents(dirname(__DIR__) . '/assets/checkout.js');
        $css = file_get_contents(dirname(__DIR__) . '/assets/checkout.css');
        file_put_contents($fixture_dir . '/checkout.html', '<!doctype html><html lang="uk"><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Customer TTN checkout fixture</title><style>body{font:16px system-ui;margin:24px auto;max-width:920px;padding:0 12px}label{display:block}textarea{box-sizing:border-box;font:inherit}h4{margin:0} input[type=checkbox]{width:18px;height:18px}label input{display:inline}</style><style>' . $css . '</style><body><form><label><input type="radio" name="shipping_method[0]" value="flat_rate:1"> Звичайна доставка</label><label><input type="radio" name="shipping_method[0]" value="pnpm_customer_ttn" checked> Відправка за моєю ТТН Нової пошти</label><div id="pnpm-checkout-fields">Звичайні поля Нової пошти</div>' . $fields . '</form><script>' . $jquery . '</script><script>var pnpmCheckout={branchLabel:"Відділення",parcelLockerLabel:"Поштомат"};</script><script>' . $script . '</script></body></html>');
        restore_previous_locale();
    }
    $assert(!isset($checkout->gateways(['cod' => true, 'bacs' => true])['cod']), 'External COD excluded');
    $order = new WC_Order(); $order->set_customer_id($users[0]); $order->set_created_via('pnpm-local-test'); $order->set_status('pending');
    $item = new WC_Order_Item_Product(); $item->set_name('Synthetic product'); $item->set_quantity(5); $item->set_subtotal(500); $item->set_total(500);
    $checkout->stampItem($item, 'testkey', [], $order); $item->update_meta_data('_pc_alloc_plan', [901 => 3, 902 => 2]);
    $order->add_item($item); $checkout->save($order, $data); $order->save(); $orders[] = $order->get_id();
    $checkout->persist($order); $rows = $repo->findByOrder($order->get_id());
    $assert(count($rows) === 2, 'Two persisted TTNs');
    $assert((float) $store->items((int) $rows[0]['id'])[0]['quantity'] === 3.0 && (float) $store->items((int) $rows[1]['id'])[0]['quantity'] === 2.0, '3+2 split persisted');
    $checkout->persist($order); $assert(count($repo->findByOrder($order->get_id())) === 2, 'Idempotent retry');
    $old_item_id = $item->get_id();
    $order->remove_item($old_item_id);
    $replacement = new WC_Order_Item_Product(); $replacement->set_name('Synthetic product'); $replacement->set_quantity(5); $replacement->set_total(500); $replacement->set_subtotal(500);
    $replacement->update_meta_data('_pnpm_cart_key', 'testkey'); $replacement->update_meta_data('_pc_alloc_plan', [901 => 3, 902 => 2]);
    $order->add_item($replacement); $order->save(); $checkout->persist($order);
    $assert((int) $store->items((int) $rows[0]['id'])[0]['order_item_id'] === $replacement->get_id(), 'Retry rebinds recreated Woo items');
    $errors = new WP_Error(); $checkout->validate($data, $errors); $assert($errors->has_errors(), 'Other checkout duplicate blocked');
    WC()->session->set('order_awaiting_payment', $order->get_id());
    $errors = new WP_Error(); $checkout->validate($data, $errors); $assert(!$errors->has_errors(), 'Same unpaid checkout allowed');
    $errors = new WP_Error(); $checkout->validate(['shipping_method' => ['flat_rate:1']], $errors); $assert($errors->has_errors(), 'Changing shipping on reserved order blocked');
    WC()->session->set('order_awaiting_payment', 0);
    $other = new WC_Order(); $other->set_customer_id($users[0]); $other->set_status('pending'); $other->save(); $orders[] = $other->get_id();
    $other_item = new WC_Order_Item_Product(); $other_item->set_name('Synthetic product'); $other_item->set_quantity(5); $other_item->set_subtotal(500); $other_item->set_total(500); $other_item->update_meta_data('_pnpm_cart_key', 'testkey'); $other->add_item($other_item);
    $collision = $order->get_meta('_pnpm_external_plan'); $collision[0]['ttn'] = (string) ((int) $ttn2 + 1);
    $other->update_meta_data('_pnpm_external_plan', $collision); $other->save();
    $reject(fn() => $store->submit($other)); $assert(!$repo->findByOrder($other->get_id()), 'Unique constraint conflict rolls batch back');
    $track = (new ExternalTracking())->check($ttn1); $assert($track['available'] && $track['code'] === '1' && !str_contains(wp_json_encode($track), 'PRIVATE'), 'Tracking public status only');
    $tracking_code = 'timeout'; $assert(!(new ExternalTracking())->check($ttn1)['available'], 'API failure represented without losing order');
    $tracking_code = 'invalid'; $assert((new ExternalTracking())->check($ttn1)['rejected'], 'Invalid number is distinguished from network failure');
    $replacement->update_meta_data('_pc_alloc_plan', [901 => 4, 902 => 1]); $replacement->save();
    $reject(fn() => $panel->approve($order, $rows[0], 'Local synthetic verification'));
    $replacement->update_meta_data('_pc_alloc_plan', [901 => 3, 902 => 2]); $replacement->save();
    $panel->approve($order, $rows[0], 'Local synthetic verification');
    $assert($store->find((int) $rows[0]['id'])['status'] === 'approved', 'Approval persisted');
    $panel->approve($order, $rows[0], 'Repeated synthetic verification');
    global $wpdb;
    $assert((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}pnpm_shipment_events WHERE shipment_id=%d AND event_type='approved'", $rows[0]['id'])) === 1, 'One approval audit event');
    ob_start(); $panel->customer($order); $html = ob_get_clean(); $assert(str_contains($html, $ttn1), 'Owner sees TTNs');
    $other->update_meta_data('_folio_parent_order_id', $order->get_id()); $other->save();
    ob_start(); $panel->customer($other); $html = ob_get_clean(); $assert(str_contains($html, $ttn2), 'Split child links original warehouse shipments');
    wp_set_current_user($users[1]); $assert(!Policy::allowed(), 'Retail denied');
    ob_start(); $panel->customer($order); $html = ob_get_clean(); $assert($html === '', 'Another customer cannot see TTNs');
    $errors = new WP_Error(); $checkout->validate($data, $errors); $assert($errors->has_errors(), 'Forged retail shipping rejected');
    wp_set_current_user(0); $assert(!Policy::allowed(), 'Guest denied');
    wp_set_current_user($users[0]);
    switch_to_locale('uk');
    ob_start(); pc_wholesale_help_render_endpoint(); $help_html = ob_get_clean();
    $assert(str_contains($help_html, 'id="customer-ttn"') && str_contains($help_html, 'Відправка за вашою готовою ТТН'), 'Wholesale help anchor and translation');
    restore_previous_locale();
    $_POST['pnpm_external_ttn'][902] = $ttn1;
    $errors = new WP_Error(); $checkout->validate($data, $errors); $assert($errors->has_errors(), 'Same TTN for two warehouses rejected');
    $_POST['pnpm_external_ttn'][902] = ''; $errors = new WP_Error(); $checkout->validate($data, $errors); $assert($errors->has_errors(), 'Missing warehouse TTN rejected');
    $_POST['pnpm_external_confirm'] = '0'; $errors = new WP_Error(); $checkout->validate($data, $errors); $assert(in_array('pnpm_external_confirm', $errors->get_error_codes(), true), 'Confirmation required');
    $errors = new WP_Error(); $checkout->validate($data + [], $errors);
    $cod = $data; $cod['payment_method'] = 'cod'; $errors = new WP_Error(); $checkout->validate($cod, $errors); $assert(in_array('pnpm_external_cod', $errors->get_error_codes(), true), 'Forged COD rejected server-side');
    WC()->cart->cart_contents['testkey']['pc_alloc_plan'] = [901 => 5];
    $_POST = ['pnpm_external_ttn' => [901 => (string) ((int) $ttn2 + 2)], 'pnpm_external_confirm' => '1'];
    $errors = new WP_Error(); $checkout->validate($data, $errors); $assert(!$errors->has_errors(), 'Single warehouse checkout valid');
    echo "External TTN: {$checks} checks passed; HTTP calls intercepted: {$http_calls}.\n";
} finally {
    global $wpdb;
    foreach ($orders as $id) {
        foreach ((new ShipmentRepository())->findByOrder($id) as $row) {
            foreach (['pnpm_shipment_items', 'pnpm_shipment_events'] as $table) { $wpdb->delete($wpdb->prefix . $table, ['shipment_id' => $row['id']]); }
        }
        $wpdb->delete($wpdb->prefix . 'pnpm_shipments', ['order_id' => $id]);
        $order = wc_get_order($id); if ($order) { $order->delete(true); }
    }
    require_once ABSPATH . 'wp-admin/includes/user.php';
    foreach ($users as $id) { wp_delete_user($id); }
    if (WC()->cart) { WC()->cart->cart_contents = []; }
    wp_set_current_user($original_user);
}
