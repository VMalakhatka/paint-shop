<?php
/** Offline regression tests: no WP database, email, payment or HTTP. */
define('ABSPATH', __DIR__);
$options = []; $caps = []; $cookie = []; $products = []; $orders = [];
$ajax = true; $admin = true;
class Session { public $data=[]; function get($key,$default=null) { return $this->data[$key] ?? $default; } function set($key,$value) { $this->data[$key]=$value; } }
class Cart { public $removed_cart_contents=[]; public $row; function get_cart_item($key) { return $this->row; } function get_cart() { return [$this->row]; } }
$wc = (object) ['session'=>new Session, 'cart'=>new Cart];
function WC() { global $wc; return $wc; }
function wp_doing_ajax() { global $ajax; return $ajax; }
function is_admin() { global $admin; return $admin; }
function is_order_received_page() { return false; }
function is_checkout_pay_page() { return false; }
function sanitize_key($key) { return $key; }
function wc_get_product($id) { return new WC_Product; }
function wp_generate_uuid4() { static $n=0; return 'fixture-' . ++$n; }
function add_action(...$args) {}
function add_filter(...$args) {}
function get_option($key, $default = false) { global $options; return $options[$key] ?? $default; }
function current_user_can($cap) { global $caps; return !empty($caps[$cap]); }
function wp_get_current_user() { return (object) ['roles' => ['customer']]; }
function wp_parse_url(...$args) { return parse_url(...$args); }
function wc_get_price_excluding_tax($p) { return $p->price; }
function get_woocommerce_currency() { return 'UAH'; }
function wc_get_order($id) { global $orders; return $orders[$id] ?? false; }
function get_query_var($key) { return $key === 'order-received' ? 10 : 0; }
function absint($id) { return abs((int) $id); }
function wc_clean($s) { return $s; }
function wp_unslash($s) { return stripslashes($s); }
function get_current_user_id() { return 0; }
class WC_Product {
    public $price = 25;
    function get_sku() { return 'TEST-SKU'; } function get_id() { return 1; } function get_name() { return 'Public product'; }
}
class WC_Order {
    public $meta = []; public $id; public $status = 'processing'; public $customer = 0; public $currency = 'UAH';
    function __construct($id) { $this->id = $id; }
    function get_id() { return $this->id; } function get_meta($key) { return $this->meta[$key] ?? ''; }
    function meta_exists($key) { return array_key_exists($key, $this->meta); }
    function update_meta_data($key, $value) { $this->meta[$key] = $value; }
    function save_meta_data() {} function get_customer_id() { return $this->customer; }
    function get_order_key() { return 'private-key'; } function get_status() { return $this->status; }
    function get_currency() { return $this->currency; } function get_shipping_total() { return 10; } function get_total_tax() { return 0; }
    function get_items() { return [new Line]; }
}
class Line {
    function get_product() { return new WC_Product; } function get_quantity() { return 2; } function get_total() { return 40; }
}
require dirname(__DIR__) . '/lavka-customer-analytics.php';
$n = 0;
function check($result, $message) { global $n; if (!$result) { throw new Exception($message); } $n++; }
check(!Lavka_Customer_Analytics::enabled(), 'Default disabled');
$options['lca_settings'] = ['enabled' => 'no', 'measurement_id' => 'G-TEST1234'];
check(!Lavka_Customer_Analytics::enabled(), 'Truthy text cannot enable');
$options['lca_settings'] = ['enabled' => true, 'measurement_id' => 'bad'];
check(!Lavka_Customer_Analytics::enabled(), 'Reject invalid measurement');
$options['lca_settings']['measurement_id'] = 'G-TEST1234';
check(Lavka_Customer_Analytics::enabled(), 'Explicit setting enables');
check(Lavka_Customer_Analytics::token('person@example.com') === '', 'No emails');
check(Lavka_Customer_Analytics::token('380671234567') === '', 'No phone tokens');
check(Lavka_Customer_Analytics::token('google') === 'google', 'Allow campaign source');
check(Lavka_Customer_Analytics::public_path('/product/paint/?key=secret&s=email@example.com') === '/product/paint/', 'Strip queries');
check(Lavka_Customer_Analytics::public_path('/checkout/order-received/10/?key=secret') === '/', 'Strip private route');
$event = Lavka_Customer_Analytics::ecommerce([Lavka_Customer_Analytics::item(new WC_Product, 3)]);
check($event['value'] === 75.0 && $event['currency'] === 'UAH', 'Quantity/value');
$root = $orders[10] = new WC_Order(10); $child = $orders[11] = new WC_Order(11);
$root->meta = ['_lca_segment' => 'wholesale', '_lca_consent' => 'granted', '_lca_payment_confirmed_at' => 'date', '_folio_child_order_ids' => [11]];
$child->meta['_folio_split_from_order_id'] = 10;
Lavka_Customer_Analytics::inherit($child);
check($child->get_meta('_lca_segment') === 'wholesale', 'Segment inheritance');
check(!$child->get_meta('_lca_payment_confirmed_at'), 'Do not copy payment evidence');
$_GET['key'] = 'wrong';
check(Lavka_Customer_Analytics::receipt_events() === [], 'Reject wrong receipt key');
$_GET['key'] = 'private-key';
$events = Lavka_Customer_Analytics::receipt_events();
check(count($events) === 1 && $events[0]['params']['value'] === 40.0, 'No parent+child double count');
check($events[0]['params']['payment_state'] === 'unconfirmed', 'Processing is not payment');
Lavka_Customer_Analytics::payment_complete(11);
check((bool) $child->get_meta('_lca_payment_confirmed_at'), 'Confirmed hook records evidence');
check(count(Lavka_Customer_Analytics::receipt_events()) === 2, 'Separate confirmed payment event');
$child->status = 'failed';
check(Lavka_Customer_Analytics::receipt_events() === [], 'Do not purchase failed split');
$child->status = 'processing'; $child->customer = 123;
check(Lavka_Customer_Analytics::receipt_events() === [], 'Reject unrelated child customer');
$child->customer = 0; $child->currency = 'USD';
check(Lavka_Customer_Analytics::receipt_events() === [], 'Reject mixed currencies');
$large = Lavka_Customer_Analytics::ecommerce(array_fill(0, 201, ['price' => 1, 'quantity' => 1]));
check($large['items_truncated'] && count($large['items']) === 200 && $large['value'] === 201.0, 'Flag GA4 large-basket limit');
$_REQUEST['action'] = 'pcoe_import_cart';
check(!Lavka_Customer_Analytics::collection_request(), 'No mutation queue before consent');
$_COOKIE['lca_consent'] = 'granted';
check(Lavka_Customer_Analytics::collection_request(), 'Actual PCOE import action supported');
$wc->cart->row = ['data'=>new WC_Product,'quantity'=>2];
Lavka_Customer_Analytics::add_to_cart('key',1,2,0);
check(count($wc->session->data['lca_event_queue']) === 1, 'Successful Woo hook produces one queued event');
check($wc->session->data['lca_event_queue'][0]['params']['items'][0]['quantity'] === 2.0, 'Actual successful quantity');
Lavka_Customer_Analytics::quantity('key',2,2,$wc->cart);
check(count($wc->session->data['lca_event_queue']) === 1, 'No event for unchanged quantity');
Lavka_Customer_Analytics::quantity('key',1,2,$wc->cart);
check($wc->session->data['lca_event_queue'][1]['name'] === 'remove_from_cart', 'Quantity decrement');
$wc->cart->removed_cart_contents['key']=$wc->cart->row;
Lavka_Customer_Analytics::removed('key',$wc->cart);
check($wc->session->data['lca_event_queue'][2]['name'] === 'remove_from_cart', 'Successful remove');
$_REQUEST['action']='pcoe_manager';
check(!Lavka_Customer_Analytics::collection_request(), 'Exclude manager route');
$_REQUEST['action']='pc_cart_update_item'; $caps['manage_woocommerce']=true;
check(!Lavka_Customer_Analytics::collection_request(), 'Exclude staff mutation');
$caps=[]; $_REQUEST['wc-ajax']='checkout';
check(!Lavka_Customer_Analytics::collection_request(), 'Checkout emptying is not customer removal');
unset($_REQUEST['wc-ajax']);
Lavka_Customer_Analytics::emptied();
check(count($wc->session->data['lca_event_queue'])===4, 'Customer import/draft cart replacement emits old cart removal');
echo "PASS: $n model assertions\n";
