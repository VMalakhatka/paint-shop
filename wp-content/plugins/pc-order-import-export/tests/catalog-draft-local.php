<?php
/** Local-only synthetic CRUD regression; no real customer, stock or external service writes. */
use PaintCore\PCOE\CatalogDraft as Draft;
use PaintCore\PCOE\ManagerWorkspace;

if (!defined('WP_CLI') || !WP_CLI || wp_parse_url(home_url(), PHP_URL_HOST) !== 'paint.local') throw new RuntimeException('Local only');
add_filter('pre_http_request', static fn() => new WP_Error('blocked', 'Test blocks outbound HTTP'), PHP_INT_MAX);
add_filter('pre_wp_mail', '__return_true', PHP_INT_MAX);
require_once ABSPATH . 'wp-admin/includes/user.php';
$cleanup = static function (array $fixture): void {
    $user = get_user_by('id', $fixture['user']);
    if (!$user || strpos($user->user_login, 'catalog-draft-test-') !== 0) throw new RuntimeException('Not a synthetic fixture');
    wp_set_current_user($user->ID);
    foreach (wc_get_orders(['customer_id' => $user->ID, 'limit' => -1]) as $order) $order->delete(true);
    foreach (array_reverse($fixture['products']) as $id) { $product = wc_get_product($id); if ($product) $product->delete(true); }
    if (!empty($fixture['category'])) wp_delete_term($fixture['category'], 'product_cat');
    wp_delete_user($user->ID);
};
if ($file = getenv('PCOE_CATALOG_CLEANUP')) {
    $cleanup(json_decode(file_get_contents($file), true));
    unlink($file);
    echo "Synthetic catalogue fixture removed.\n";
    return;
}
$checks = 0;
$check = static function ($condition, $label) use (&$checks) { if (!$condition) throw new RuntimeException($label); $checks++; };
$reject = static function (callable $operation, $label) use ($check) {
    try { $operation(); } catch (RuntimeException $e) { $check(true, $label); return; }
    $check(false, $label);
};
$fixture = ['user' => 0, 'products' => []];
$old_uid = get_current_user_id();
$keep = false;
$run = static function (array $data): array { return Draft::execute($data + ['request_key' => wp_generate_uuid4()]); };
try {
    $fixture['user'] = wp_insert_user(['user_login' => 'catalog-draft-test-' . wp_generate_uuid4(), 'user_pass' => wp_generate_password(40), 'role' => 'customer']);
    if (is_wp_error($fixture['user'])) throw new RuntimeException('Fixture user failed');
    wp_set_current_user($fixture['user']);
    WC()->customer = new WC_Customer($fixture['user']);
    WC()->cart = new WC_Cart();
    $category = wp_insert_term('Catalogue draft test ' . substr(wp_generate_uuid4(), 0, 8), 'product_cat');
    $fixture['category'] = $category['term_id'];
    $simple = new WC_Product_Simple();
    $simple->set_sku('QA-DRAFT-' . wp_generate_uuid4());
    $simple->set_name('Draft test paint 250 ml'); $simple->set_status('publish');
    $simple->set_regular_price(10); $simple->set_price(10); $simple->set_manage_stock(true);
    $simple->set_stock_quantity(0); $simple->set_stock_status('outofstock');
    $simple->set_category_ids([$fixture['category']]); $simple->save();
    $fixture['products'][] = $simple->get_id();
    $variable = new WC_Product_Variable(); $variable->set_name('Draft test oil colours'); $variable->set_status('publish');
    $attribute = new WC_Product_Attribute(); $attribute->set_name('Colour'); $attribute->set_options(['Red', 'Blue']); $attribute->set_variation(true);
    $variable->set_attributes([$attribute]); $variable->set_category_ids([$fixture['category']]); $variable->save();
    $fixture['products'][] = $variable->get_id();
    $variation = new WC_Product_Variation(); $variation->set_parent_id($variable->get_id()); $variation->set_attributes(['colour' => '']);
    $variation->set_sku('QA-DRAFT-VAR-' . wp_generate_uuid4());
    $variation->set_regular_price(15); $variation->set_price(15); $variation->set_status('publish');
    $variation->set_manage_stock(true); $variation->set_stock_quantity(0); $variation->save();
    $fixture['products'][] = $variation->get_id();
    WC_Product_Variable::sync($variable->get_id());
    $create = ['operation' => 'create', 'title' => 'Planning without stock', 'request_key' => wp_generate_uuid4()];
    $result = $run($create); $id = $result['order_id'];
    $check($run($create)['order_id'] === $id, 'Create is idempotent');
    $check(Draft::editable(wc_get_order($id)), 'Own draft editable');
    $check(!Draft::active(), 'Default purchase mode');
    $run(['operation' => 'mode', 'mode' => 'draft']);
    $check(Draft::active(), 'Retail customer can enable draft mode');
    $run(['operation' => 'select', 'order_id' => $id]);
    $check((int) get_user_meta($fixture['user'], '_pcoe_catalog_draft', true) === $id, 'Selection persisted');
    $add = ['operation' => 'add', 'order_id' => $id, 'product_id' => $simple->get_id(), 'quantity' => '10000', 'request_key' => wp_generate_uuid4()];
    $run($add); $run($add);
    $order = wc_get_order($id); $items = $order->get_items(); $item = reset($items);
    $check(count($items) === 1 && (int) $item->get_quantity() === 10000, 'Zero stock and large quantity accepted; duplicate not added');
    $check((float) $item->get_total() === 100000.0, 'Customer price saved');
    $check(!$item->get_meta('_pc_alloc_plan'), 'No allocation plan written');
    $check(WC()->cart->is_empty(), 'Cart untouched');
    $check((float) wc_get_product($simple->get_id())->get_stock_quantity() === 0.0, 'Stock untouched');
    $check($order->has_status('pc-draft') && !$order->is_paid() && !$order->get_date_paid(), 'No payment or status transition');
    $check(!pc_folio_order_has_saved_documents($order), 'No Folio document');
    $run(array_merge($add, ['quantity' => '3', 'request_key' => wp_generate_uuid4()]));
    $order = wc_get_order($id); $items = $order->get_items(); $item = reset($items);
    $check((int) $item->get_quantity() === 10003, 'Repeated product merges');
    foreach (['-1', '0', '1.5', '1e3', 'NaN', '1000000000', []] as $bad) $reject(fn() => $run(array_merge($add, ['quantity' => $bad, 'request_key' => wp_generate_uuid4()])), 'Invalid quantity rejected');
    $simple->set_catalog_visibility('hidden'); $simple->save();
    $reject(fn() => $run(array_merge($add, ['request_key' => wp_generate_uuid4()])), 'Hidden product rejected');
    $simple->set_catalog_visibility('visible'); $simple->save();
    $variable_add = ['operation' => 'add', 'order_id' => $id, 'product_id' => $variable->get_id(), 'quantity' => '7'];
    $reject(fn() => $run($variable_add), 'Missing variation selection rejected');
    $run($variable_add + ['attributes' => ['attribute_colour' => 'Red']]);
    $run($variable_add + ['attributes' => ['attribute_colour' => 'Blue']]);
    $order = wc_get_order($id); $items = $order->get_items();
    $check(count($items) === 3, 'Wildcard variation choices remain separate lines');
    $revision = ManagerWorkspace::revision($order);
    $run(['operation' => 'update', 'order_id' => $id, 'revision' => $revision, 'items' => [$item->get_id() => '20000']]);
    $check((int) wc_get_order($id)->get_item($item->get_id())->get_quantity() === 20000, 'Quantity editor ignores stock');
    $reject(fn() => $run(['operation' => 'update', 'order_id' => $id, 'revision' => $revision, 'items' => [$item->get_id() => '1']]), 'Stale editor rejected');
    $order = wc_get_order($id);
    $run(['operation' => 'update', 'order_id' => $id, 'revision' => ManagerWorkspace::revision($order), 'items' => [$item->get_id() => '0']]);
    $check(count(wc_get_order($id)->get_items()) === 2, 'Zero removes line');
    if (!getenv('PCOE_CATALOG_FIXTURE')) {
        // Model a later restock in memory; the frontend stock-write barrier stays intact.
        $vid = $variation->get_id();
        add_filter('woocommerce_product_variation_get_stock_quantity', static fn($value, $product) => $product->get_id() === $vid ? 30 : $value, 1000, 2);
        add_filter('woocommerce_product_variation_get_stock_status', static fn($value, $product) => $product->get_id() === $vid ? 'instock' : $value, 1000, 2);
        foreach (get_terms(['taxonomy' => 'location', 'hide_empty' => false]) as $location) {
            $GLOBALS['slu_location_stock_request_cache'][$vid][$location->term_id] = ['name' => $location->name, 'qty' => 30];
        }
        $draft = wc_get_order($id);
        $analyse = new ReflectionMethod(\PaintCore\PCOE\DraftFolioWorkflow::class, 'analyse');
        $analysis = $analyse->invoke(null, $draft, 'partial_to_cart');
        $prepare = new ReflectionMethod(\PaintCore\PCOE\DraftFolioWorkflow::class, 'prepare_cart_and_remainder');
        $prepare->invoke(null, $draft, $analysis);
        $colours = array_map(static fn($item) => $item['variation']['attribute_colour'] ?? '', WC()->cart->get_cart());
        sort($colours);
        $check($colours === ['Blue', 'Red'], 'Processing preserves wildcard choices as separate cart rows');
        WC()->cart->empty_cart();
    }
    $order = wc_get_order($id); $order->set_customer_id(0); $order->save();
    $reject(fn() => $run(array_merge($add, ['request_key' => wp_generate_uuid4()])), 'Foreign draft rejected');
    $order->set_customer_id($fixture['user']); $order->update_meta_data('_pcoe_manager_command', ['state' => 'pending']); $order->save();
    $reject(fn() => $run(array_merge($add, ['request_key' => wp_generate_uuid4()])), 'Submitted draft rejected');
    $order->delete_meta_data('_pcoe_manager_command'); $order->save();
    wp_set_current_user(0);
    $check(!Draft::active(), 'Guest mode disabled');
    $reject(fn() => $run($create), 'Guest cannot create');
    wp_set_current_user($fixture['user']);
    $check(strpos(Draft::product_form($simple), 'max=') === false, 'Draft input has no stock maximum');
    $check(strpos(Draft::product_form(wc_get_product($variable->get_id()), true), 'attribute_colour') !== false, 'Variation attributes shown');
    $check(strpos(Draft::product_form($simple), 'ajax_add_to_cart') === false, 'Draft buttons are not Woo cart buttons');
    $run(['operation' => 'mode', 'mode' => 'purchase']);
    $check(!Draft::active() && Draft::loop_button('original purchase control', $simple) === 'original purchase control', 'Purchase mode restored');
    $run(['operation' => 'mode', 'mode' => 'draft']);
    $customer = wp_get_current_user();
    $customer->set_role('opt');
    update_post_meta($simple->get_id(), '_wpc_price_role_opt', '8');
    $check((float) ManagerWorkspace::customer_price(wc_get_product($simple->get_id()), $customer) === 8.0, 'Wholesale customer price preserved');
    $quick_html = do_shortcode('[pc_quick_order cat="' . get_term($fixture['category'])->slug . '"]');
    $check(strpos($quick_html, 'pcoe-product-draft') !== false, 'Wholesale list offers direct draft addition');
    $check(strpos($quick_html, 'class="button button-primary pc-qo-addall"') === false, 'Wholesale draft list has no add-to-cart action');
    $customer->set_role('customer');
    if ($file = getenv('PCOE_CATALOG_FIXTURE')) {
        $fixture += ['shop' => wc_get_page_permalink('shop'), 'category_url' => get_term_link($fixture['category'], 'product_cat'),
            'simple' => $simple->get_permalink(), 'variable' => $variable->get_permalink(), 'order_id' => $id, 'order' => $order->get_view_order_url(),
            'cookie_name' => LOGGED_IN_COOKIE, 'cookie_value' => wp_generate_auth_cookie($fixture['user'], time() + 3600, 'logged_in')];
        file_put_contents($file, wp_json_encode($fixture)); chmod($file, 0600); $keep = true;
    }
    echo "Catalogue drafts: {$checks} checks passed.\n";
} finally {
    if (!$keep && $fixture['user'] && !is_wp_error($fixture['user'])) $cleanup($fixture);
    wp_set_current_user($old_uid);
}
