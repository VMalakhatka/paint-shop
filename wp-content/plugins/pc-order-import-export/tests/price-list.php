<?php
/** Local-only roundtrip; all created records are removed, external calls blocked. */
use PaintCore\PCOE\Helpers;
use PaintCore\PCOE\PriceList;
use PaintCore\PCOE\ImporterDraft;
use PaintCore\PCOE\ImporterCart;

if (!defined('WP_CLI') || !WP_CLI || wp_parse_url(home_url(), PHP_URL_HOST) !== 'paint.local') {
    throw new RuntimeException('Run only with WP-CLI on paint.local.');
}
add_filter('pre_http_request', static fn() => new WP_Error('test_blocked', 'No external requests'), PHP_INT_MAX);
add_filter('pre_wp_mail', '__return_true', PHP_INT_MAX);
if (!defined('DOING_AJAX')) define('DOING_AJAX', true);
$assert = static function ($condition, $message) { if (!$condition) throw new RuntimeException($message); };
$ajax = static function (callable $callback): array {
    $die = static fn() => static function () { throw new RuntimeException('test_ajax_done'); };
    add_filter('wp_die_ajax_handler', $die);
    ob_start();
    try { $callback(); } catch (RuntimeException $e) { if ($e->getMessage() !== 'test_ajax_done') throw $e; }
    finally { $raw = ob_get_clean(); remove_filter('wp_die_ajax_handler', $die); }
    $result = json_decode($raw, true);
    if (!is_array($result)) throw new RuntimeException('Invalid AJAX JSON: ' . $raw);
    return $result;
};
$uid = 0; $pids = []; $orders = []; $path = wp_tempnam('price-list-test-');
$original_user = get_current_user_id();
add_action('woocommerce_new_order', static function ($id) use (&$orders) { $orders[] = $id; });
try {
    wp_set_current_user(0);
    $assert(!PriceList::can_access(), 'Guest price list access');
    $denied = $ajax([PriceList::class, 'handle']);
    $assert(!$denied['success'], 'Guest download accepted');
    $uid = wp_insert_user(['user_login'=>'pcoe-price-test-' . wp_generate_password(8, false), 'user_pass'=>wp_generate_password(), 'role'=>'partner']);
    $assert(!is_wp_error($uid), 'Cannot create fixture user');
    wp_set_current_user($uid);
    $_GET['_wpnonce'] = 'invalid';
    $assert(!$ajax([PriceList::class, 'handle'])['success'], 'Bad nonce accepted');
    $locations = PriceList::location_ids();
    $assert(count($locations) === 2, 'Expected two configured selling locations');
    for ($i = 0; $i < 2; $i++) {
        $product = new WC_Product_Simple();
        $product->set_name($i ? 'Other item' : '=TEST leading formula title');
        $product->set_sku('0000-PCOE-' . wp_generate_password(8, false));
        $product->set_regular_price('100');
        $product->set_status('publish');
        $product->set_manage_stock(true);
        $product->set_stock_quantity(8);
        $product->update_meta_data('_wpc_price_role_partner', '70');
        $product->update_meta_data('_wpc_price_role_opt', '80');
        $product->update_meta_data('_wc_gtin_code', '012345678905');
        $product->update_meta_data('_stock_at_' . $locations[0], 5);
        $product->update_meta_data('_stock_at_' . $locations[1], 3);
        $product->save();
        $pids[] = $product->get_id();
        wp_set_object_terms($product->get_id(), $locations, 'location');
        $product->set_stock_quantity(8);
        $product->save();
    }
    $products = array_map('wc_get_product', $pids);
    $stock_before = $products[0]->get_stock_quantity();
    $_COOKIE['pc_alloc_pref'] = '{"mode":"auto","term_id":0}';
    $assert(PriceList::can_access(), 'Partner denied');
    $book = PriceList::workbook($products, $locations);
    $sheet = $book->getActiveSheet();
    $assert((float)$sheet->getCell('F2')->getValue() === (float)wc_get_price_to_display($products[0]), 'Customer price mismatch');
    $assert((float)$products[0]->get_price() === 70.0, 'Partner role price wrong');
    $assert($sheet->getCell('B2')->getValue() === '012345678905', 'Leading barcode zero lost');
    $assert($sheet->getCell('C2')->getDataType() === 's', 'Formula injection in title');
    $assert((float)$sheet->getCell('G2')->getValue() === 8.0, 'Stock is not Kyiv + Odesa');
    $assert($sheet->getCell('I2')->getHyperlink()->getUrl() === get_permalink($pids[0]), 'Wrong product link');
    $rows = $sheet->toArray(null, false, false, false);
    [$map, $start] = Helpers::detect_colmap_and_start($rows);
    $assert(!empty($map['price_list']) && $map['qty'] === 7 && $map['price'] === null, 'Price-list mapping wrong');
    $assert(Helpers::price_list_import_error($rows, $map, $start) !== null, 'Empty order accepted');
    $sheet->setCellValue('H2', 2);
    $sheet->setCellValue('F2', 0.01);
    (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($book))->save($path);
    [$loaded, $error] = Helpers::read_rows($path, 'lavka.xlsx');
    $assert(!$error, 'Cannot read generated XLSX');
    [$map, $start] = Helpers::detect_colmap_and_start($loaded);
    $assert(Helpers::price_list_import_error($loaded, $map, $start) === null, 'Valid order rejected');
    $received = [];
    $result = Helpers::process_rows_with_adder($loaded, $map, $start, [
        'allow_price'=>true,
        'adder'=>static function ($p, $qty, $price) use (&$received) { $received[] = [$p->get_id(), $qty, $price]; return true; },
    ]);
    $assert($result['ok'] === 1 && $result['skipped'] === 0 && count($result['report']) === 1, 'Blank catalogue rows reported as errors');
    $assert($received[0][0] === $pids[0] && $received[0][1] == 2 && $received[0][2] === null, 'SKU/quantity/price not safe');
    [$generic_map, $generic_start] = Helpers::detect_colmap_and_start([['sku', 'qty', 'price'], [$products[0]->get_sku(), '3', '12.50']]);
    $assert(empty($generic_map['price_list']) && $generic_start === 1 && $generic_map['qty'] === 1 && $generic_map['price'] === 2, 'Generic import mapping regressed');
    delete_post_meta($pids[1], '_stock_at_' . $locations[1]);
    $assert(PriceList::stock($products[1], $locations) === null, 'Unknown stock became zero');
    foreach (['Замовити','Заказать','Order quantity'] as $header) {
        $copy = $loaded; $copy[0][7] = $header;
        [$m] = Helpers::detect_colmap_and_start($copy);
        $assert($m['qty'] === 7 && !empty($m['price_list']), 'Locale mapping failed');
    }
    $broken = $loaded; $broken[0][7] = 'wrong';
    [$m] = Helpers::detect_colmap_and_start($broken);
    $assert(Helpers::price_list_import_error($broken, $m, 1) !== null, 'Stock substituted for missing order column');
    foreach (['garbage', '=2+2', '-1', '1e999'] as $bad) {
        $copy = $loaded; $copy[1][7] = $bad;
        $result = Helpers::process_rows_with_adder($copy, $map, 1, ['adder'=>static fn() => true]);
        $assert($result['ok'] === 0 && $result['skipped'] === 1, 'Invalid quantity accepted: ' . $bad);
    }
    $stock_before = wc_get_product($pids[0])->get_stock_quantity();
    $assert(PriceList::stock($products[0], $locations) === 8.0, 'Invalid stock fixture before draft');
    $_POST = ['_wpnonce'=>wp_create_nonce('pcoe_import_draft'), 'title'=>'PCOE price fixture'];
    $_FILES['file'] = ['tmp_name'=>$path, 'name'=>'lavka.xlsx'];
    $draft = $ajax([ImporterDraft::class, 'handle']);
    $assert($draft['success'] && $draft['data']['imported'] === 1, 'Draft import failed');
    $order = wc_get_order($draft['data']['order_id']);
    $assert($order->get_customer_id() === $uid && $order->has_status('pc-draft'), 'Wrong draft owner/status');
    $assert(abs((float)$order->get_subtotal() - (float)wc_get_price_excluding_tax($products[0], ['qty'=>2])) < 0.01, 'Uploaded price changed draft total');
    $assert(wc_get_product($pids[0])->get_stock_quantity() === $stock_before && PriceList::stock(wc_get_product($pids[0]), $locations) === 8.0, 'Draft changed stock');
    wc_load_cart();
    WC()->cart->empty_cart();
    $_POST['_wpnonce'] = wp_create_nonce('pcoe_import_cart');
    $cart_result = $ajax([ImporterCart::class, 'handle']);
    $assert($cart_result['success'] && $cart_result['data']['added'] === 1 && $cart_result['data']['skipped'] === 0, 'Cart import failed: ' . wp_json_encode($cart_result));
    $assert(WC()->cart->get_cart_contents_count() == 2, 'Wrong cart quantity');
    WC()->cart->empty_cart();
    WC()->session->destroy_session();
    $user = get_user_by('id', $uid); $user->set_role('opt'); wp_set_current_user(0); wp_set_current_user($uid);
    $assert((float)wc_get_product($pids[0])->get_price() === 80.0, 'Opt price mismatch');
    $user->set_role('customer'); wp_set_current_user(0); wp_set_current_user($uid);
    $assert(!PriceList::can_access(), 'Retail customer has wholesale export');
    $book->disconnectWorksheets();
    WP_CLI::success('Price list: XLSX roundtrip, roles, nonce, stock, barcode, quantities and draft repricing passed.');
} finally {
    foreach ($orders as $id) { $order = wc_get_order($id); if ($order) $order->delete(true); }
    foreach ($pids as $id) { $product = wc_get_product($id); if ($product) $product->delete(true); }
    wp_set_current_user($original_user);
    if ($uid && !is_wp_error($uid)) { require_once ABSPATH . 'wp-admin/includes/user.php'; wp_delete_user($uid); }
    if ($path) @unlink($path);
}
