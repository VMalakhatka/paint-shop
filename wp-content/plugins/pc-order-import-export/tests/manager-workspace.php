<?php
/** Local WordPress integration test. Run with wp eval-file; all HTTP and email are intercepted. */
use PaintCore\PCOE\ManagerWorkspace as Workspace;
if (!defined('WP_CLI') || !WP_CLI || wp_parse_url(home_url(), PHP_URL_HOST) !== 'paint.local') throw new RuntimeException('Local paint test only.');
require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
require_once ABSPATH . 'wp-admin/includes/screen.php';
set_current_screen('dashboard');
add_filter('pre_wp_mail', '__return_true', PHP_INT_MAX);
$orders = []; $users = []; $products = []; $terms = []; $checks = 0; $writes = 0; $http_failure = false;
$check = static function ($condition, $message) use (&$checks): void { if (!$condition) throw new RuntimeException($message); $checks++; };
$invoke = static function ($method, ...$args) { return (new ReflectionMethod(Workspace::class, $method))->invoke(null, ...$args); };
$expect_error = static function (callable $fn) use ($check): void { try { $fn(); } catch (Throwable $e) { $check(true, 'rejected'); return; } throw new RuntimeException('Expected rejection'); };
add_filter('pre_http_request', static function ($pre, $args, $url) use (&$writes, &$http_failure) {
    if (!str_contains($url, '/admin/folio/order-accounts')) return new WP_Error('test_block', 'External HTTP blocked by test.');
    $payload = json_decode($args['body'], true);
    if (!$payload['preview_only']) { $writes++; if ($http_failure) return new WP_Error('timeout', 'Simulated timeout'); }
    $documents = [];
    foreach ($payload['items'] as $item) foreach ($item['allocations'] as $allocation) {
        $wid = (int) $allocation['folio_warehouses'][0]['id'];
        $accounted = (bool) $payload['folio_account_header']['accountingEnabled'];
        if (!isset($documents[$wid])) $documents[$wid] = ['document_id' => 900000 + $wid, 'document_number' => (string) (900000 + $wid), 'folio_warehouse_id' => $wid,
            'document_type' => $accounted ? 'account' : 'non_accounting_account', 'accounting_enabled' => $accounted,
            'source_external_request_id' => $payload['folio_account_header']['externalRequestId'] . ':wh:' . $wid, 'items' => []];
        $documents[$wid]['items'][] = ['order_item_id' => $item['order_item_id'], 'sku' => $item['sku'], 'quantity' => $allocation['quantity'],
            'price' => $item['unit_price'], 'amount' => $allocation['quantity'] * $item['unit_price'], 'folio_warehouse_id' => $wid, 'allocation_status' => $accounted ? 'allocated' : 'non_accounting'];
    }
    return ['response' => ['code' => $payload['preview_only'] ? 200 : 201], 'headers' => [], 'body' => wp_json_encode([
        'ok' => true, 'preview_only' => $payload['preview_only'], 'woo_order_id' => $payload['woo_order']['id'], 'documents' => array_values($documents), 'errors' => [], 'warnings' => []])];
}, PHP_INT_MAX, 3);
$original = get_current_user_id();
try {
    $tag = strtolower(wp_generate_password(9, false));
    $managers = get_users(['role__in' => ['administrator', 'shop_manager'], 'number' => 1]);
    if (!$managers) throw new RuntimeException('An existing manager is required for local testing.');
    $manager_id = (int) $managers[0]->ID;
    foreach (['opt', 'partner'] as $role) {
        $id = wp_insert_user(['user_login' => 'pcoe-test-' . $role . '-' . $tag, 'user_pass' => wp_generate_password(30), 'user_email' => $role . '-' . $tag . '@example.invalid', 'role' => $role]);
        if (is_wp_error($id)) throw new RuntimeException($id->get_error_message());
        $users[] = $id;
        update_user_meta($id, '_folio_partner_id', 'TEST'); update_user_meta($id, '_folio_partner_short_name', 'TEST');
    }
    [$customer_id, $other_id] = $users;
    wp_set_current_user($manager_id); $user = Workspace::customer($customer_id);
    foreach ([901, 902] as $wid) {
        $term = wp_insert_term('PCOE test ' . $tag . '-' . $wid, 'location');
        if (is_wp_error($term)) throw new RuntimeException($term->get_error_message());
        $terms[] = $term['term_id']; update_term_meta($term['term_id'], 'lavka_folio_warehouses', [['id' => (string) $wid, 'priority' => 1]]);
    }
    $product = new WC_Product_Simple(); $product->set_name('PCOE test product'); $product->set_sku('PCOE-' . $tag); $product->set_status('publish');
    $product->set_regular_price('120'); $product->set_price('120'); $product->set_manage_stock(false); $product->set_stock_status('instock'); $product->save();
    $products[] = $product->get_id();
    update_post_meta($product->get_id(), 'wpcpu_enable', 'disable');
    update_post_meta($product->get_id(), '_wpc_price_role_opt', '75'); update_post_meta($product->get_id(), '_wpc_price_role_partner', '55');
    wp_set_object_terms($product->get_id(), $terms, 'location');
    $GLOBALS['PC_ALLOW_STOCK_WRITE'] = true;
    foreach ($terms as $tid) update_post_meta($product->get_id(), '_stock_at_' . $tid, '10');
    unset($GLOBALS['PC_ALLOW_STOCK_WRITE']);
    $check((float) Workspace::customer_price($product, $user) === 75.0, 'Customer role price');
    $check((float) Workspace::customer_price($product, get_userdata($other_id)) === 55.0, 'Second customer role price');
    $check(get_current_user_id() === $manager_id, 'Actor identity restored');
    $price_error = static function () { throw new RuntimeException('price test failure'); };
    add_filter('woocommerce_product_get_price', $price_error, PHP_INT_MAX);
    $expect_error(fn() => Workspace::customer_price($product, $user));
    remove_filter('woocommerce_product_get_price', $price_error, PHP_INT_MAX);
    $check(get_current_user_id() === $manager_id, 'Actor restored after exception');
    $_POST = ['title' => 'Local manager test'];
    $created = $invoke('create', 'new', $user); parse_str(wp_parse_url($created['url'], PHP_URL_QUERY), $ids); $id = (int) $ids['order_id']; $orders[] = $id;
    $order = Workspace::order($id, $customer_id);
    $check((int) $order->get_customer_id() === $customer_id && (int) $order->get_meta('_pcoe_manager_created_by') === $manager_id, 'Ownership and attribution');
    $expect_error(fn() => Workspace::order($id, $other_id));
    wp_set_current_user($other_id); $expect_error(fn() => Workspace::customer($customer_id)); wp_set_current_user($manager_id);
    $_POST = ['sku' => $product->get_sku(), 'add_quantity' => '15', 'title' => 'Local draft', 'note' => 'Test note'];
    $invoke('save', $order, $user); $order = wc_get_order($id);
    $item_id = array_key_first($order->get_items());
    $_POST = ['quantities_json' => wp_json_encode([$item_id => '14']), 'title' => 'Local draft'];
    $invoke('save', $order, $user); $order = wc_get_order($id);
    $check((int) $order->get_item($item_id)->get_quantity() === 14, 'JSON quantities save without form-variable limits');
    $_POST['quantities_json'] = wp_json_encode([$item_id => '15']);
    $invoke('save', $order, $user); $order = wc_get_order($id); $before = Workspace::revision($order);
    $_POST = ['mode' => 'accounts', 'warehouse_mode' => 'auto', 'warehouse_id' => 0];
    $preview = $invoke('preview', $order, $user);
    $check($writes === 0 && Workspace::revision(wc_get_order($id)) === $before, 'Preview does not write Folio or draft');
    $stored = get_transient('pcoe_manager_preview_' . $preview['token']);
    $check(count($stored['response']['documents']) === 2, 'Two warehouse accounts: ' . wp_json_encode(['stock' => slu_collect_location_stocks_for_product($product), 'allocations' => $stored['payload']['items'][0]['allocations']]));
    $check((float) $stored['payload']['items'][0]['unit_price'] === 75.0, 'Preview uses customer price');
    $bad = $stored['response']; $bad['documents'][0]['items'][0]['quantity'] += 1;
    $expect_error(fn() => Workspace::validate_response($bad, $stored['payload'], true));
    $_POST = ['token' => $preview['token']]; $expect_error(fn() => $invoke('apply', $order, $user));
    $check($writes === 0, 'Confirmation required');
    $_POST['confirmation'] = '1';
    update_post_meta($product->get_id(), '_wpc_price_role_opt', '76');
    $expect_error(fn() => $invoke('apply', $order, $user)); $check($writes === 0, 'Stale price blocks apply');
    update_post_meta($product->get_id(), '_wpc_price_role_opt', '75');
    $invoke('apply', $order, $user);
    $order = wc_get_order($id); $command = $order->get_meta('_pcoe_manager_command');
    $check($command['status'] === 'complete' && $writes === 1, 'Apply completes once');
    $children = (array) $order->get_meta('_folio_child_order_ids');
    $keys = pc_folio_order_documents_meta_keys(); $children = (array) $order->get_meta($keys['child_order_ids']);
    $check(count($children) === 2, 'Woo children linked');
    foreach ($children as $child_id) {
        $orders[] = $child_id; $child = wc_get_order($child_id);
        $check((int) $child->get_customer_id() === $customer_id, 'Child owns same customer');
        foreach ($child->get_items() as $item) $check((bool) $item->get_meta('_pc_alloc_plan'), 'Child has explicit stock plan');
    }
    $invoke('apply', $order, $user); $check($writes === 1, 'Exact replay does not resend');
    $check(!Workspace::editable($order), 'Submitted draft cannot be edited');
    $_POST = ['request_key' => wp_generate_uuid4()]; $copy = $invoke('copy', $order, $user); parse_str(wp_parse_url($copy['url'], PHP_URL_QUERY), $ids); $copy_id = (int) $ids['order_id']; $orders[] = $copy_id;
    $copy_order = wc_get_order($copy_id); $check(Workspace::editable($copy_order), 'Copy omits accounting state');
    $check($copy === $invoke('copy', $order, $user), 'Copy replay reuses draft');
    $_POST = ['mode' => 'accounts', 'warehouse_mode' => 'single', 'warehouse_id' => $terms[0]];
    $preview = $invoke('preview', $copy_order, $user); $http_failure = true;
    $_POST = ['token' => $preview['token'], 'confirmation' => '1'];
    $expect_error(fn() => $invoke('apply', $copy_order, $user));
    $copy_order = wc_get_order($copy_id);
    $check($copy_order->get_meta('_pcoe_manager_command')['status'] === 'unknown', 'Timeout persisted as unknown');
    $sent = $writes; $expect_error(fn() => $invoke('apply', $copy_order, $user)); $check($writes === $sent, 'Unknown cannot resend');
    $check(pc_folio_order_has_saved_documents($copy_order), 'Legacy create routes respect manager pending operation');
    $http_failure = false;
    $_POST = ['request_key' => wp_generate_uuid4()];
    $non = $invoke('copy', $order, $user); parse_str(wp_parse_url($non['url'], PHP_URL_QUERY), $ids);
    $non_id = (int) $ids['order_id']; $orders[] = $non_id; $non_order = wc_get_order($non_id);
    $warehouse_filter = static fn() => 901; add_filter('pcoe_folio_non_accounting_warehouse_id', $warehouse_filter);
    $_POST = ['mode' => 'non_accounting', 'warehouse_mode' => 'auto', 'warehouse_id' => 0];
    $non_preview = $invoke('preview', $non_order, $user);
    $non_payload = get_transient('pcoe_manager_preview_' . $non_preview['token']);
    $check(count($non_payload['response']['documents']) === 1 && !$non_payload['response']['documents'][0]['accounting_enabled'], 'Whole non-accounting preview');
    $before_stock = array_map(fn($tid)=>get_post_meta($product->get_id(), '_stock_at_' . $tid, true), $terms);
    $_POST = ['token' => $non_preview['token'], 'confirmation' => '1'];
    $invoke('apply', $non_order, $user); $non_order = wc_get_order($non_id);
    $check($non_order->has_status('pc-draft') && !Workspace::editable($non_order), 'Non-accounting stays draft and blocks duplication');
    $check($before_stock === array_map(fn($tid)=>get_post_meta($product->get_id(), '_stock_at_' . $tid, true), $terms), 'Non-accounting leaves stock unchanged');
    remove_filter('pcoe_folio_non_accounting_warehouse_id', $warehouse_filter);
    // Connection locks exclude a second DB connection without changing any data or grants.
    $second = new wpdb(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);
    $name = $invoke('lock_name', 'order:' . $id);
    $invoke('lock', 'order:' . $id, static function () use ($second, $name, $check) {
        $check((string) $second->get_var($second->prepare('SELECT GET_LOCK(%s, 0)', $name)) === '0', 'Concurrent connection cannot acquire order lock');
    });
    $second->close();
    // Real CSV/XLSX parser with synthetic SKU, and no Folio traffic.
    $sheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sheet->getActiveSheet()->fromArray([['sku', 'qty'], [$product->get_sku(), 3]]);
    $file = tempnam(sys_get_temp_dir(), 'pcoe-xlsx-');
    (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($sheet))->save($file);
    [$rows, $err] = \PaintCore\PCOE\Helpers::read_rows($file, 'test.xlsx'); unlink($file);
    $check(!$err && count($rows) === 2, 'Excel parser');
    echo 'PASS: ' . $checks . " manager workflow assertions; external HTTP and email mocked.\n";
} finally {
    wp_set_current_user($manager_id ?? $original);
    foreach (array_reverse(array_unique($orders)) as $id) { $order = wc_get_order($id); if ($order) $order->delete(true); }
    foreach ($products as $id) wp_delete_post($id, true);
    foreach ($terms as $tid) wp_delete_term($tid, 'location');
    require_once ABSPATH . 'wp-admin/includes/user.php';
    foreach ($users as $id) wp_delete_user($id);
    wp_set_current_user($original);
}
