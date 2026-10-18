<?php
/** Local runtime check with in-memory product/session, no orders, HTTP or mail. */
if (!defined('WP_CLI') || !WP_CLI || wp_get_environment_type() !== 'local') throw new RuntimeException('Local CLI only');
add_filter('pre_http_request', static fn() => new WP_Error('test_offline', 'HTTP blocked'), PHP_INT_MAX);
add_filter('pre_wp_mail', '__return_true', PHP_INT_MAX);
$terms = get_terms(['taxonomy' => 'location', 'hide_empty' => false]);
if (is_wp_error($terms) || !$terms) throw new RuntimeException('Need a local stock location');
$location = (int) $terms[0]->term_id;
add_filter('pre_option_pnpm_location_mappings', static fn() => [$location => ['enabled' => 'yes', 'city_ref' => 'synthetic-sender']]);
add_filter('pre_option_pnpm_delivery_policy_v1', static fn() => Paint\NovaPoshta\Domain\DeliveryPolicy::defaults());
$original = WC()->session;
WC()->session = new class {
    private array $data = [];
    public function get($k, $d = null) { return $this->data[$k] ?? $d; }
    public function set($k, $v) { $this->data[$k] = $v; }
};
$checks = 0;
$check = static function ($ok, $why) use (&$checks) { if (!$ok) throw new RuntimeException($why); $checks++; };
try {
    $product = new WC_Product_Simple(); $product->set_name('Synthetic delivery test'); $product->set_sku('SYNTHETIC');
    $state = ['city_label' => 'Test', 'city_ref' => 'synthetic-city', 'delivery_type' => 'branch', 'point_label' => 'Test branch', 'point_ref' => 'synthetic-point'];
    WC()->session->set('pnpm_recipient', $state);
    WC()->session->set('chosen_shipping_methods', ['pnpm_nova_poshta:2']);
    $method = new Paint\NovaPoshta\Shipping\NovaPoshtaShippingMethod(2);
    $package = ['destination' => ['country' => 'UA', 'state' => '', 'postcode' => ''], 'contents_cost' => 1200.01, 'contents' => [
        'synthetic' => ['data' => $product, 'quantity' => 1, 'line_total' => 1200.01, 'pc_alloc_plan' => [$location => 1]],
    ]];
    $method->calculate_shipping($package);
    $rates = $method->rates;
    $check(count($rates) === 1 && (float) reset($rates)->get_cost() === 0.0, 'Real Woo shipping rate does not add fee');
    $rates['novaposhta'] = new WC_Shipping_Rate('novaposhta', 'Old rate', 80, [], 'novaposhta');
    $rates['flat_rate:9'] = new WC_Shipping_Rate('flat_rate:9', 'Old NP', 80, [], 'flat_rate');
    $rates['pnpm_customer_ttn'] = new WC_Shipping_Rate('pnpm_customer_ttn', 'Own TTN', 0, [], 'pnpm_customer_ttn');
    $rates = (new Paint\NovaPoshta\Shipping\CheckoutRates())->filter($rates, $package);
    $check(count($rates) === 2 && !isset($rates['novaposhta']), 'Real Woo rates replaced with NP and pickup');
    $pickup = $rates['pnpm_pickup:' . $location];
    $check((int) $pickup->get_meta_data()['pnpm_pickup_location_id'] === $location, 'Real Woo rate preserves pickup location');
    $integration = Paint\NovaPoshta\Checkout\CheckoutIntegration::create();
    $data = []; foreach ($state as $key => $value) $data['pnpm_' . $key] = $value;
    $errors = new WP_Error(); $integration->validate($data, $errors);
    $check(!$errors->has_errors(), 'Missing weight does not block valid destination');
    ob_start(); $integration->renderQuoteSummary(); $html = ob_get_clean();
    $check(!str_contains($html, '80,00') && str_contains($html, 'pnpm-checkout-summary'), 'No old fixed charge in actual summary');
    WC()->session->set('chosen_shipping_methods', ['pnpm_pickup:' . $location]);
    ob_start(); $integration->renderQuoteSummary(); $html = ob_get_clean();
    $check($html === '', 'Pickup does not show stale NP summary');
    $errors = new WP_Error(); $integration->validate([], $errors);
    $check(!$errors->has_errors(), 'Pickup requires no NP destination');
    $f = new Paint\NovaPoshta\Shipping\CheckoutRates();
    $check(!$f->freeShippingAvailable(true, $package), 'Configured NP prevents Woo free-shipping hiding NP selector');
    foreach ($terms as $term) {
        $sample = $package;
        $sample['contents']['synthetic']['pc_alloc_plan'] = [(int) $term->term_id => 1];
        $options = $f->filter($method->rates, $sample);
        $origin = $options['pnpm_pickup:' . $term->term_id];
        $check(str_contains($origin->get_label(), $term->name), 'Pickup label follows ' . $term->name);
        $check((int) $origin->get_meta_data()['pnpm_pickup_location_id'] === (int) $term->term_id, 'Pickup metadata follows origin');
    }
    if ($dir = getenv('PNPM_POINT_FIXTURE_DIR')) {
        switch_to_locale('uk');
        $labels = [];
        foreach ([4.0, 1200.0, 1200.01] as $total) {
            $sample = $package; $sample['contents_cost'] = $total;
            $sample['contents']['synthetic']['line_total'] = $total;
            $shipping = new Paint\NovaPoshta\Shipping\NovaPoshtaShippingMethod(2);
            $shipping->calculate_shipping($sample);
            foreach ($shipping->rates as $rate) $labels[] = '<tr><th>' . wc_price($total) . '</th><td>' . wc_cart_totals_shipping_method_label($rate) . '</td></tr>';
        }
        $labels[] = '<tr><th>Самовивіз</th><td>' . wc_cart_totals_shipping_method_label($pickup) . '</td></tr>';
        file_put_contents($dir . '/shipping-labels.html', '<!doctype html><html lang="uk"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><style>body{font:16px/1.5 Arial;margin:16px;color:#303a40}main{max-width:720px;margin:auto}table{width:100%;table-layout:fixed;border-collapse:collapse}th,td{text-align:left;vertical-align:top;padding:12px;border-bottom:1px solid #ddd;overflow-wrap:anywhere}th{width:90px}h1{font-size:24px}</style><main><h1>Доставка: тестові суми товарів</h1><table>' . implode('', $labels) . '</table></main></html>');
        add_filter('woocommerce_is_cart', '__return_true');
        ob_start();
        foreach ($terms as $index => $term) {
            $sample = $package; $sample['contents_cost'] = 4;
            $sample['contents']['synthetic']['pc_alloc_plan'] = [(int) $term->term_id => 1];
            $shipping = new Paint\NovaPoshta\Shipping\NovaPoshtaShippingMethod(2);
            $shipping->calculate_shipping($sample);
            wc_get_template('cart/cart-shipping.php', [
                'package' => $sample, 'package_name' => $term->name, 'index' => $index,
                'available_methods' => $f->filter($shipping->rates, $sample),
                'chosen_method' => 'pnpm_nova_poshta:2', 'formatted_destination' => 'Київ',
                'has_calculated_shipping' => true, 'show_shipping_calculator' => true,
                'show_package_details' => false,
            ]);
        }
        $rows = ob_get_clean();
        remove_filter('woocommerce_is_cart', '__return_true');
        file_put_contents($dir . '/shipping-options.html', '<!doctype html><html lang="uk"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><style>body{font:16px/1.5 Arial;margin:16px;color:#303a40}table{max-width:720px;margin:auto;width:100%;table-layout:fixed}th,td{padding:8px;vertical-align:top;overflow-wrap:anywhere}th{width:60px}li{margin-bottom:16px}ul{padding:0;list-style:none}input[type=radio]{margin-right:8px}</style><style>' . file_get_contents(PNPM_DIR . 'assets/checkout.css') . '</style><table>' . $rows . '</table></html>');
        restore_previous_locale();
    }
    echo "Local checkout delivery: {$checks} checks passed; no orders or external requests.\n";
} finally { WC()->session = $original; }
