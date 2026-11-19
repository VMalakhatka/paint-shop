<?php
/** Standalone contract tests. No DB, HTTP, mail, payment or stock operations. */
namespace Paint\NovaPoshta\Domain {
    class CartShipmentBuilder {
        public function build($package) { return $GLOBALS['plan']; }
    }
    class PolicyCalculator {
        public function calculate($components, $total, $count) { return ['cod_allowed' => $GLOBALS['policy_allowed']]; }
    }
}
namespace {
    define('ABSPATH', __DIR__);
    $hooks = []; $checks = 0; $admin = false; $ajax = false; $pay = false; $settings = [];
    function add_action($name, $callback, $priority = 10, $args = 1) { add_filter($name, $callback, $priority, $args); }
    function add_filter($name, $callback, $priority = 10, $args = 1) { $GLOBALS['hooks'][$name] = [$callback, $priority, $args]; }
    function __($text, $domain = '') { return $text; }
    function WC() { return $GLOBALS['wc']; }
    function is_admin() { return $GLOBALS['admin']; }
    function wp_doing_ajax() { return $GLOBALS['ajax']; }
    function is_wc_endpoint_url($endpoint) { return $GLOBALS['pay']; }
    function get_option($name, $default = []) { return $GLOBALS['settings']; }
    class WC_Shipping_Rate {
        public function __construct(private $method = 'pnpm_nova_poshta') {}
        public function get_method_id() { return $this->method; }
    }
    class WP_Error {
        public $errors = [];
        public function add($code, $message) { $this->errors[$code] = $message; }
    }
    class WC_Order {
        public $method = 'cod'; public $paid = null; public $external = []; public $shipping = [];
        public function get_payment_method() { return $this->method; }
        public function get_date_paid() { return $this->paid; }
        public function get_meta($key, $single) { return $this->external; }
        public function get_shipping_methods() { return $this->shipping; }
    }
    $wc = new class {
        public $cart; public $session; public $shipping;
        public function shipping() { return $this->shipping; }
    };
    $wc->cart = new class { public $physical = true; public function needs_shipping() { return $this->physical; } };
    $wc->session = new class {
        public $data = [];
        public function get($key, $default = []) { return $this->data[$key] ?? $default; }
    };
    $wc->shipping = new class { public $packages = []; public function get_packages() { return $this->packages; } };
    $rate = new WC_Shipping_Rate();
    $package = ['contents_cost' => 100, 'rates' => ['np:7' => $rate, 'pickup:2' => new WC_Shipping_Rate('local_pickup'), 'external:4' => new WC_Shipping_Rate('pnpm_customer_ttn')]];
    $recipient = ['city_ref' => 'fixture', 'delivery_type' => 'branch'];
    $quote = ['recipient' => $recipient, 'errors' => [], 'shipments' => [['location_id' => 7]], 'policy' => ['cod_allowed' => true], 'estimate_available' => false];
    $reset = static function () use ($wc, $package, $recipient, $quote) {
        $GLOBALS['settings'] = []; $GLOBALS['pay'] = false; $GLOBALS['admin'] = false; $GLOBALS['ajax'] = false;
        $GLOBALS['plan'] = ['shipments' => [['location_id' => 7]], 'errors' => []]; $GLOBALS['policy_allowed'] = true;
        $wc->cart->physical = true; $wc->shipping->packages = [3 => $package];
        $wc->session->data = ['chosen_shipping_methods' => [3 => 'np:7'], 'pnpm_recipient' => $recipient, 'pnpm_quote' => $quote];
    };
    $assert = static function ($ok, $label) use (&$checks) { if (!$ok) throw new \RuntimeException($label); $checks++; };
    require dirname(__DIR__) . '/inc/checkout-cod.php';
    $reset();
    $assert(psu_checkout_cod_allowed(), 'Eligible single parcel, unknown tariff/weight allowed');
    $gateways = ['bacs' => new \stdClass(), 'cod' => new \stdClass()];
    $assert(psu_checkout_cod_gateways($gateways) === $gateways, 'Existing COD retained');
    $assert(psu_checkout_cod_gateways(['bacs'=>$gateways['bacs']]) === ['bacs'=>$gateways['bacs']], 'Disabled or previously removed COD never reinserted');
    foreach ([[], [3=>'unknown'], [3=>'pickup:2'], [3=>'external:4'], [3=>['np:7']], [0=>'np:7'], [3=>'np:7',4=>'np:7']] as $methods) {
        $assert(!psu_checkout_cod_allowed($methods), 'Reject missing, forged, pickup, external, malformed, wrong-index or multiple rates');
    }
    foreach ([
        static function () use ($wc) { $wc->cart->physical = false; },
        static function () { $GLOBALS['pay'] = true; },
        static function () { $GLOBALS['settings'] = ['checkout_enabled'=>'no']; },
        static function () use ($wc) { $wc->shipping->packages = []; },
        static function () use ($wc, $package) { $wc->shipping->packages[4] = $package; },
        static function () use ($wc) { $wc->session->data['pnpm_quote'] = []; },
        static function () use ($wc) { $wc->session->data['pnpm_quote']['errors'] = ['allocation error']; },
        static function () use ($wc) { $wc->session->data['pnpm_quote']['policy']['cod_allowed'] = false; },
        static function () use ($wc) { $wc->session->data['pnpm_quote']['policy']['cod_allowed'] = 'yes'; },
        static function () use ($wc) { $wc->session->data['pnpm_quote']['shipments'][] = ['location_id'=>8]; },
        static function () use ($wc) { $wc->session->data['pnpm_recipient']['city_ref'] = 'changed'; },
        static function () { $GLOBALS['plan']['shipments'][] = ['location_id'=>8]; },
        static function () { $GLOBALS['plan']['errors'] = ['allocation changed']; },
        static function () { $GLOBALS['policy_allowed'] = false; },
    ] as $mutate) {
        $reset(); $mutate();
        $assert(!psu_checkout_cod_allowed(), 'Unsafe or stale checkout is denied');
        $assert(psu_checkout_cod_gateways($gateways) === ['bacs'=>$gateways['bacs']], 'Only COD removed');
        $errors = new WP_Error(); $errors->add('other', 'Keep');
        psu_checkout_cod_validate(['payment_method'=>'cod','shipping_method'=>[3=>'np:7']], $errors);
        $assert(isset($errors->errors['psu_cod_unavailable'], $errors->errors['other']), 'Forged POST rejected without clearing other errors');
    }
    $reset(); $errors = new WP_Error();
    psu_checkout_cod_validate(['payment_method'=>'cod','shipping_method'=>[3=>'np:7']], $errors);
    $assert(!$errors->errors, 'Valid POST accepted');
    psu_checkout_cod_validate(['payment_method'=>'bacs','shipping_method'=>[]], $errors);
    $assert(!$errors->errors, 'Other payments not affected');
    $wc->session->data = []; $admin = true;
    $assert(psu_checkout_cod_gateways($gateways) === $gateways, 'Admin editor unaffected');
    $ajax = true;
    $assert(!isset(psu_checkout_cod_gateways($gateways)['cod']), 'AJAX remains guarded');
    $reset(); $session = $wc->session; $wc->session = null;
    $assert(!psu_checkout_cod_allowed(), 'No session is denied'); $wc->session = $session;
    $cart = $wc->cart; $wc->cart = null;
    $assert(!psu_checkout_cod_allowed(), 'No cart is denied'); $wc->cart = $cart;
    $order = new WC_Order(); $order->shipping = [$rate];
    $before = serialize($order);
    $assert(psu_checkout_cod_order_status('processing', $order) === 'on-hold', 'Unpaid native NP COD stays on hold');
    $assert(serialize($order) === $before, 'No order, collection amount or paid flag mutation');
    $order->paid = 'already-paid';
    $assert(psu_checkout_cod_order_status('processing', $order) === 'processing', 'Existing paid fact untouched');
    $order->paid = null; $order->external = ['fixture'];
    $assert(psu_checkout_cod_order_status('processing', $order) === 'processing', 'External TTN not rewritten');
    $order->external = []; $order->method = 'bacs';
    $assert(psu_checkout_cod_order_status('processing', $order) === 'processing', 'Other gateway untouched');
    $order->method = 'cod'; $order->shipping = [new WC_Shipping_Rate('local_pickup')];
    $assert(psu_checkout_cod_order_status('processing', $order) === 'processing', 'Other shipping untouched');
    $assert($hooks['woocommerce_gateway_title'][0]('Title', 'bacs') === 'Title', 'Other payment title unchanged');
    $assert($hooks['woocommerce_gateway_title'][0]('Title', 'cod') === 'Payment on receipt (Nova Poshta)', 'COD title');
    $assert($hooks['woocommerce_available_payment_gateways'][1] > 1000, 'Does not override NP external-TTN filter');
    echo "Checkout COD: {$checks} checks passed; no external operations.\n";
}
