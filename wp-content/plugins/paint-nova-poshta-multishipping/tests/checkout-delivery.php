<?php
/** Deterministic checkout shipping contract. No database, orders or carrier writes. */
define('ABSPATH', __DIR__); define('MINUTE_IN_SECONDS', 60); define('PNPM_VERSION', 'test');
define('PNPM_NOVA_POSHTA_API_KEY', 'test-only');
class WP_Error { public function __construct(public $code, public $message) {} public function get_error_message() { return $this->message; } public function get_error_code() { return $this->code; } }
class WC_Product {
    public function __construct(public float $weight = 0) {}
    public function needs_shipping() { return true; }
    public function get_weight() { return $this->weight; }
    public function get_sku() { return 'TEST'; }
    public function get_name() { return 'Test product'; }
    public function get_id() { return 1; }
}
class WC_Shipping_Rate {
    public array $meta = [];
    public function __construct(public $id, public $label, public $cost, public $taxes, public $method) {}
    public function get_method_id() { return $this->method; }
    public function add_meta_data($key, $value) { $this->meta[$key] = $value; }
}
class WC_Shipping_Method {
    public $id, $instance_id, $method_title, $method_description, $supports, $instance_form_fields, $enabled, $title;
    public array $rates = [];
    public function get_option($key, $default) { return $default; }
    public function add_rate($rate) { $this->rates[] = $rate; }
    public function get_rate_id() { return 'pnpm_nova_poshta:2'; }
}
function __($s, $domain = '') { return $s; }
function absint($n) { return abs((int) $n); }
function sanitize_key($s) { return $s; }
function sanitize_text_field($s) { return trim(strip_tags($s)); }
function add_action(...$args) {}
function add_filter(...$args) {}
function is_wp_error($value) { return $value instanceof WP_Error; }
function is_user_logged_in() { return !empty($GLOBALS['roles']); }
function wp_get_current_user() { return (object) ['roles' => $GLOBALS['roles'] ?? []]; }
function wc_price($n) { return number_format($n, 2, '.', ''); }
function wp_strip_all_tags($s) { return strip_tags($s); }
function wp_parse_args($args, $defaults) { return array_merge($defaults, $args); }
function get_term($id, $taxonomy) { return (object) ['name' => 'Warehouse ' . $id]; }
function wc_get_price_decimals() { return 2; }
function wc_format_decimal($n, $precision = 2) { return number_format($n, $precision, '.', ''); }
function wc_get_weight($n, $unit) { return $n; }
function wp_json_encode($value) { return json_encode($value); }
function get_transient($key) { return false; }
function set_transient(...$args) {}
function esc_url_raw($value) { return $value; }
function wp_remote_post($url, $args) {
    $GLOBALS['calls']++;
    if ($GLOBALS['api_failure']) return new WP_Error('timeout', 'Provider unavailable');
    return ['response' => ['code' => 200], 'body' => json_encode(['success' => true, 'data' => [['Cost' => 100]]])];
}
function wp_remote_retrieve_response_code($r) { return $r['response']['code']; }
function wp_remote_retrieve_body($r) { return $r['body']; }
$options = ['pnpm_location_mappings' => [1 => ['enabled' => 'yes', 'city_ref' => 'sender'], 2 => ['enabled' => 'yes', 'city_ref' => 'sender2']]];
function get_option($key, $default = []) { return $GLOBALS['options'][$key] ?? $default; }
$wc = (object) ['session' => new class {
    private array $data = [];
    public function get($k, $d = null) { return $this->data[$k] ?? $d; }
    public function set($k, $v) { $this->data[$k] = $v; }
}];
function WC() { return $GLOBALS['wc']; }
require __DIR__ . '/noop-throttle.php';
foreach (['Domain/DeliveryPolicy', 'Domain/PolicyCalculator', 'Domain/CartShipmentBuilder', 'Infrastructure/ApiClient', 'Infrastructure/TariffQuoteService', 'Shipping/NovaPoshtaShippingMethod', 'Shipping/CheckoutRates'] as $class) require __DIR__ . '/../src/' . $class . '.php';
$checks = 0; $calls = 0; $api_failure = false;
function check($ok, $label) { if (!$ok) throw new RuntimeException($label); $GLOBALS['checks']++; }
function package(float $total = 1200, float $weight = 0, array $plan = [1 => 1]): array {
    return ['destination' => ['country' => 'UA'], 'contents_cost' => $total, 'contents' => [
        'test' => ['data' => new WC_Product($weight), 'quantity' => array_sum($plan), 'line_total' => $total, 'pc_alloc_plan' => $plan],
    ]];
}
function calculate($package): array {
    $method = new Paint\NovaPoshta\Shipping\NovaPoshtaShippingMethod(2);
    $method->calculate_shipping($package);
    check(count($method->rates) === 1, 'Exactly one NP rate, no fallback duplicate');
    check($method->rates[0]['cost'] === 0, 'No delivery collected with goods');
    return WC()->session->get('pnpm_quote');
}
$calc = new Paint\NovaPoshta\Domain\PolicyCalculator();
foreach ([1199.99,1200.0] as $total) check(!$calc->baseTransportCovered($total), 'At/below boundary customer pays');
check($calc->baseTransportCovered(1200.01), 'Strictly above boundary base covered');
check($calc->baseTransportThreshold() === 1200.0, 'Guest threshold comes from policy');
$p = $calc->calculate(['base_transport' => 100, 'extra_shipments' => 80, 'declared_value' => 15, 'packaging' => 20], 1200.01, 2);
check($p['store_total'] === 180.0 && $p['customer_total'] === 35.0, 'Insurance/extras remain customer-paid, base includes each parcel');
check(!$p['cod_allowed'], 'Multi-warehouse COD restriction preserved');
$q = calculate(package());
check(!$q['estimate_available'] && !$q['base_transport_free'] && $calls === 0, 'Missing destination has rate without fake estimate');
WC()->session->set('pnpm_recipient', ['city_ref' => 'recipient', 'delivery_type' => 'branch']);
$q = calculate(package(1500));
check(!$q['estimate_available'] && $q['base_transport_free'] && $q['errors'] === [] && $calls === 0, 'Unknown weight does not call carrier or block checkout');
$q = calculate(package(1500, 200));
check($q['estimate_available'] && $q['policy']['store_total'] === 100.0 && $calls > 0, 'Known weight produces separate estimate');
$api_failure = true;
$q = calculate(package(1500, 200));
check(!$q['estimate_available'] && $q['errors'] === [], 'API outage nonblocking, stale estimate cleared');
$q = calculate(package(1500, 200, [9 => 1]));
check($q['errors'] !== [], 'Invalid warehouse allocation/config still blocks submission');
$rates = new Paint\NovaPoshta\Shipping\CheckoutRates();
$nova = new WC_Shipping_Rate('pnpm_nova_poshta:2','NP',0,[],'pnpm_nova_poshta');
$legacy = new WC_Shipping_Rate('novaposhta','Legacy',80,[],'novaposhta');
$free = new WC_Shipping_Rate('free_shipping:1','Free',0,[],'free_shipping');
$input = ['novaposhta' => $legacy, 'free_shipping:1' => $free, 'pnpm_nova_poshta:2' => $nova];
$input['flat_rate:9'] = new WC_Shipping_Rate('flat_rate:9', 'Old NP', 80, [], 'flat_rate');
$input['custom_np'] = new WC_Shipping_Rate('custom_np', 'Other old NP', 80, [], 'custom_np');
$input['pnpm_customer_ttn'] = new WC_Shipping_Rate('pnpm_customer_ttn', 'Own TTN', 0, [], 'pnpm_customer_ttn');
$filtered = $rates->filter($input, package());
check(count($filtered) === 2, 'Only NP and pickup; flat/custom/own-TTN rates removed');
check(!isset($filtered['novaposhta']) && !isset($filtered['free_shipping:1']), 'Legacy fixed tariff and destination-less free rate removed');
check(isset($filtered['pnpm_pickup:1']) && $filtered['pnpm_pickup:1']->cost === 0, 'Free pickup for selected warehouse');
check($filtered['pnpm_pickup:1']->meta['pnpm_pickup_location_id'] === 1, 'Pickup warehouse persisted in shipping metadata');
check(isset($rates->filter($input, package(500, 0, [2 => 1]))['pnpm_pickup:2']), 'Other warehouse uses its own pickup location');
check(str_contains($rates->filter($input, package(500, 0, [2 => 1]))['pnpm_pickup:2']->label, 'Warehouse 2'), 'Pickup label follows origin, not recipient destination');
check(!isset($rates->filter($input, package(1500, 200, [1=>1,2=>1]))['pnpm_pickup:1']), 'No implied cross-warehouse consolidation');
check($rates->filter(['novaposhta'=>$legacy], package()) === ['novaposhta'=>$legacy], 'Does not suppress legacy when new method not configured');
check($rates->defaultMethod('novaposhta',$filtered,'') === 'pnpm_nova_poshta:2', 'NP default');
check($rates->defaultMethod('flat_rate:9',$filtered,'flat_rate:9') === 'pnpm_nova_poshta:2', 'Cached legacy selection replaced by NP');
check($rates->cacheVersion([package()])[0]['pnpm_checkout_version'] === PNPM_VERSION, 'Version invalidates cached rates');
check($rates->filter($input, ['destination' => ['country' => 'DE']]) === $input, 'Other country untouched');
check($rates->defaultMethod('pnpm_nova_poshta:2',$filtered,'pnpm_pickup:1') === 'pnpm_pickup:1', 'Explicit pickup choice survives refresh');
$options[Paint\NovaPoshta\Domain\DeliveryPolicy::OPTION_NAME] = Paint\NovaPoshta\Domain\DeliveryPolicy::defaults();
$options[Paint\NovaPoshta\Domain\DeliveryPolicy::OPTION_NAME]['profiles']['retail']['above']['mode'] = 'customer';
check(!$calc->baseTransportCovered(1500), 'Explicit saved customer policy not overwritten');
check($calc->baseTransportThreshold() === null, 'No free promise when above-threshold policy is customer-paid');
$options[Paint\NovaPoshta\Domain\DeliveryPolicy::OPTION_NAME]['profiles']['partner']['threshold'] = 3000;
$GLOBALS['roles'] = ['opt'];
check($calc->baseTransportThreshold() === 3000.0, 'Wholesale profile uses configured threshold');
check(!$calc->baseTransportCovered(3000) && $calc->baseTransportCovered(3000.01), 'Wholesale strict boundary preserved');
$shipping = new Paint\NovaPoshta\Shipping\NovaPoshtaShippingMethod(2);
WC()->session->set('pnpm_recipient', []);
$shipping->calculate_shipping(package(500));
check(str_contains($shipping->rates[0]['label'], '3000.00') && !str_contains($shipping->rates[0]['label'], '1200'), 'Displayed threshold matches wholesale settings');
$shipping = new Paint\NovaPoshta\Shipping\NovaPoshtaShippingMethod(2);
$shipping->calculate_shipping(package(3000.01));
check(str_contains($shipping->rates[0]['label'], 'base tariff covered by us'), 'Reached threshold shows store coverage');
$options[Paint\NovaPoshta\Domain\DeliveryPolicy::OPTION_NAME]['profiles']['partner']['above']['cap'] = 100;
check($calc->baseTransportThreshold() === null && !$calc->baseTransportCovered(3001), 'Capped subsidy not advertised as full base coverage');
echo "Checkout delivery: $checks checks passed; no database/network.\n";
