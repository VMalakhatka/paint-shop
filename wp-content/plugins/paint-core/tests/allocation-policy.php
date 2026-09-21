<?php
define('ABSPATH', __DIR__);
const DAY_IN_SECONDS = 86400;
const COOKIEPATH = '/';
const COOKIE_DOMAIN = '';
$GLOBALS['role'] = 'guest';
$GLOBALS['hooks'] = [];
function add_action(...$args) {}
function add_filter($name, $callback, ...$args) { $GLOBALS['hooks'][$name] = $callback; }
function pc_wholesale_customer_can_access() { return in_array($GLOBALS['role'], ['partner','opt','opt_osn','schule'], true); }
function get_terms($args) { return [(object)['term_id'=>11], (object)['term_id'=>22]]; }
function is_wp_error($value) { return false; }
function wp_json_encode($value) { return json_encode($value); }
function WC() { return $GLOBALS['wc']; }
class WC_Product { public function get_id() { return 123; } }
class TestSession {
    public $pref = [];
    public function get($key, $default) { return $this->pref ?: $default; }
    public function set($key, $value) { $this->pref = $value; }
}
class TestCart {
    public $cart_contents = [];
    public function get_cart() { return $this->cart_contents; }
    public function get_cart_item($key) { return $this->cart_contents[$key]; }
}
$GLOBALS['wc'] = (object)['session' => new TestSession(), 'cart' => new TestCart()];
function slu_collect_location_stocks_for_product($product) { return [11=>['qty'=>2],22=>['qty'=>5]]; }
function apply_filters($name, $plan, ...$args) { return $GLOBALS['hooks'][$name]($plan, ...$args); }
require dirname(__DIR__) . '/inc/header-allocation-switcher.php';
function expect_same($expected, $actual, $label) {
    if ($expected !== $actual) throw new RuntimeException($label . ': ' . json_encode($actual));
}
$product = new WC_Product();
foreach (['guest','customer','administrator'] as $role) {
    $GLOBALS['role'] = $role;
    WC()->session->pref = ['mode'=>'auto','term_id'=>0];
    expect_same(['mode'=>'single','term_id'=>11], pc_get_alloc_pref(), "$role old auto");
    expect_same([11=>2], pc_build_alloc_plan($product, 7), "$role no split");
    pc_set_alloc_pref(['mode'=>'manual','term_id'=>22]);
    expect_same(['mode'=>'single','term_id'=>22], pc_get_alloc_pref(), "$role forced mode");
    expect_same([22=>5], pc_calc_plan_for($product, 7), "$role selected stock cap");
    expect_same([22=>5], apply_filters('slu_allocation_plan', [11=>2,22=>5], $product, 7, 'frontend-preview'), "$role external split rejected");
    expect_same(['mode'=>'single','term_id'=>11], pc_normalize_alloc_pref(['mode'=>'auto','term_id'=>999]), "$role invalid location");
}
foreach (['opt','partner','opt_osn','schule'] as $role) {
    $GLOBALS['role'] = $role;
    pc_set_alloc_pref(['mode'=>'auto','term_id'=>0]);
    expect_same([11=>2,22=>5], pc_build_alloc_plan($product, 7), "$role auto preserved");
    pc_set_alloc_pref(['mode'=>'single','term_id'=>22]);
    expect_same([22=>5], pc_build_alloc_plan($product, 7), "$role single preserved");
}
$GLOBALS['role'] = 'guest';
WC()->session->pref = [];
$_COOKIE['pc_alloc_pref'] = json_encode(['mode'=>'auto','term_id'=>22]);
expect_same(['mode'=>'single','term_id'=>22], pc_get_alloc_pref(), 'legacy cookie');
expect_same([11=>2,22=>5], pc_build_alloc_plan($product, 7, ['mode'=>'auto','term_id'=>0]), 'explicit manager plan');
pc_set_alloc_pref(['mode'=>'single','term_id'=>11]);
$kyiv = apply_filters('woocommerce_add_cart_item_data', ['pc_retail_location_id'=>22]);
expect_same(11, $kyiv['pc_retail_location_id'], 'forged store ignored');
$kyiv += ['product_id'=>123, 'quantity'=>2, 'data'=>$product];
WC()->cart->cart_contents['kyiv'] = $kyiv;
pc_set_alloc_pref(['mode'=>'single','term_id'=>22]);
$odesa = apply_filters('woocommerce_add_cart_item_data', []);
expect_same(22, $odesa['pc_retail_location_id'], 'new additions use new store');
$odesa += ['product_id'=>123, 'quantity'=>3, 'data'=>$product];
WC()->cart->cart_contents['odesa'] = $odesa;
pc_recalc_alloc_plan_for_cart_item('kyiv');
pc_recalc_alloc_plan_for_cart_item('odesa');
expect_same([11=>2], WC()->cart->cart_contents['kyiv']['pc_alloc_plan'], 'old item stays Kyiv');
expect_same([22=>3], WC()->cart->cart_contents['odesa']['pc_alloc_plan'], 'new item stays Odesa');
expect_same(0, pc_retail_available_qty($product, 11), 'Kyiv exhausted');
expect_same(2, pc_retail_available_qty($product, 22), 'other store does not consume Odesa stock');
expect_same(2, pc_retail_available_qty($product, 11, 'kyiv'), 'update cap ignores current line');
expect_same(0, pc_retail_available_qty($product, 999), 'missing warehouse never falls back');
$old = apply_filters('woocommerce_get_cart_item_from_session', ['pc_alloc_plan'=>[11=>1]]);
expect_same(11, $old['pc_retail_location_id'], 'legacy single plan keeps its warehouse');
$old = apply_filters('woocommerce_get_cart_item_from_session', ['pc_alloc_plan'=>[11=>1,22=>1]]);
expect_same(true, $old['pc_retail_location_review'], 'legacy split needs conscious choice');
expect_same([], pc_cart_item_alloc_plan($old + ['data'=>$product,'quantity'=>2]), 'legacy split cannot silently reallocate');
$GLOBALS['role'] = 'partner';
pc_set_alloc_pref(['mode'=>'auto','term_id'=>0]);
expect_same([11=>2,22=>5], pc_cart_item_alloc_plan($kyiv, 7), 'wholesale ignores old retail pin');
expect_same([], apply_filters('woocommerce_add_cart_item_data', ['pc_retail_location_id'=>11]), 'wholesale identity unchanged');
expect_same(false, isset(apply_filters('woocommerce_get_cart_item_from_session', $kyiv)['pc_retail_location_id']), 'wholesale session clears stale retail pin');
echo "Allocation policy tests passed.\n";
