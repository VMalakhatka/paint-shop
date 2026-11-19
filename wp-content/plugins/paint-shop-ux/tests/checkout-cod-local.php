<?php
/** Run via wp eval-file on paint.local. Synthetic order, no carrier/Java/mail. */
if (!defined('WP_CLI') || !WP_CLI || parse_url(home_url(), PHP_URL_HOST) !== 'paint.local') throw new RuntimeException('Local only');
require_once dirname(__DIR__) . '/inc/checkout-cod.php';
add_filter('pre_http_request', static fn() => new WP_Error('fixture', 'External requests blocked'), PHP_INT_MAX);
add_filter('pre_wp_mail', '__return_true', PHP_INT_MAX);
$checks = 0; $id = 0; $cart = WC()->cart; $session = WC()->session;
$user = wp_get_current_user();
$packages_property = new ReflectionProperty(WC_Shipping::class, 'packages');
$old_packages = $packages_property->getValue(WC()->shipping());
$policy = \Paint\NovaPoshta\Domain\DeliveryPolicy::defaults();
$mapping = static fn() => [777=>['enabled'=>'yes','city_ref'=>'synthetic-city']];
$policy_filter = static function () use (&$policy) { return $policy; };
$settings_filter = static fn() => ['checkout_enabled'=>'yes'];
add_filter('pre_option_pnpm_location_mappings', $mapping);
add_filter('pre_option_pnpm_delivery_policy_v1', $policy_filter);
add_filter('pre_option_pnpm_settings', $settings_filter);
$assert = static function ($ok, $message) use (&$checks) { if (!$ok) throw new RuntimeException($message); $checks++; };
try {
    WC()->session = new WC_Session_Handler();
    WC()->cart = new WC_Cart();
    $gateway = new WC_Gateway_COD();
    $gateway->enabled = 'yes'; // Request-local only; do not change saved settings.
    $gateway->enable_for_methods = ['pnpm_nova_poshta'];
    $gateway->enable_for_virtual = false;
    $product = new WC_Product_Simple();
    $product->set_name('Synthetic COD product');
    $product->set_price('123.45');
    $item = ['data'=>$product,'quantity'=>1,'pc_alloc_plan'=>[777=>1],'line_total'=>123.45];
    WC()->cart->set_cart_contents(['fixture'=>$item]);
    $rate = new WC_Shipping_Rate('pnpm_nova_poshta:7', 'Synthetic NP', 0, [], 'pnpm_nova_poshta', 7);
    $package = ['contents'=>['fixture'=>$item],'contents_cost'=>123.45,'rates'=>[$rate->get_id()=>$rate]];
    $packages_property->setValue(WC()->shipping(), [0=>$package]);
    WC()->session->set('chosen_shipping_methods', [$rate->get_id()]);
    $recipient = ['city_ref'=>'synthetic-city','delivery_type'=>'branch','point_ref'=>'synthetic-point'];
    WC()->session->set('pnpm_recipient', $recipient);
    $plan = (new \Paint\NovaPoshta\Domain\CartShipmentBuilder())->build($package);
    $quote = ['recipient'=>$recipient,'shipments'=>$plan['shipments'],'errors'=>[], 'policy'=>['cod_allowed'=>true]];
    WC()->session->set('pnpm_quote', $quote);
    foreach (['guest','customer','opt'] as $role) {
        $fake_user = new WP_User();
        $fake_user->ID = $role === 'guest' ? 0 : PHP_INT_MAX;
        $fake_user->roles = $role === 'guest' ? [] : [$role];
        $GLOBALS['current_user'] = $fake_user;
        $assert($gateway->is_available(), 'Native shipping restrictions allow NP: '.$role);
        $assert(psu_checkout_cod_allowed(), 'Actual local NP builder/policy allow single parcel: '.$role);
        $available = apply_filters('woocommerce_available_payment_gateways', ['cod'=>$gateway]);
        $assert(isset($available['cod']), 'NP + external-TTN + PSU hooks retain COD: '.$role);
    }
    $policy['profiles']['partner']['cod']['single_shipment'] = 'no';
    $assert(!psu_checkout_cod_allowed(), 'Current wholesale policy overrides stale allowed quote');
    $policy = \Paint\NovaPoshta\Domain\DeliveryPolicy::defaults();
    $packages_property->setValue(WC()->shipping(), [0=>$package, 1=>$package]);
    $assert(!psu_checkout_cod_allowed(), 'Actual Woo multi-package denied');
    $packages_property->setValue(WC()->shipping(), [0=>$package]);
    foreach (['uk'=>'Оплата при отриманні (Нова пошта)', 'ru_RU'=>'Наложенный платёж (Новая почта)'] as $locale=>$title) {
        unload_textdomain('paint-shop-ux');
        load_textdomain('paint-shop-ux', dirname(__DIR__).'/languages/paint-shop-ux-'.$locale.'.mo', $locale);
        $assert($gateway->get_title() === $title, 'Compiled gateway translation: '.$locale);
    }
    $GLOBALS['current_user'] = $user;
    $order = new WC_Order();
    $order->set_created_via('psu-cod-fixture');
    $order->set_currency('UAH');
    $order->set_total('123.45');
    $order->set_payment_method($gateway);
    $shipping = new WC_Order_Item_Shipping();
    $shipping->set_method_id('pnpm_nova_poshta');
    $shipping->set_method_title('Synthetic NP');
    $order->add_item($shipping);
    $id = $order->save();
    $assert($id > 0, 'Fixture saved through Woo CRUD');
    $saved = wc_get_order($id);
    $assert($saved->get_payment_method() === 'cod', 'Standard manager-visible payment method persisted');
    $assert($saved->get_payment_method_title() === __('Payment on receipt (Nova Poshta)', 'paint-shop-ux'), 'Localized title persisted');
    $assert(!$saved->get_date_paid() && !$saved->get_transaction_id(), 'No payment confirmation');
    $result = $gateway->process_payment($id);
    $saved = wc_get_order($id);
    $assert($result['result'] === 'success', 'Native COD accepts the selection');
    $assert($saved->get_status() === 'on-hold' && !$saved->is_paid(), 'Native completion remains unpaid/on-hold');
    $assert(!$saved->get_date_paid() && !$saved->get_transaction_id(), 'No paid date or transaction after selection');
    $assert((float) $saved->get_total() === 123.45, 'Total unchanged');
    echo "Checkout COD local: {$checks} checks passed; synthetic order only.\n";
} finally {
    if ($id) { $saved = wc_get_order($id); if ($saved && $saved->get_created_via() === 'psu-cod-fixture') $saved->delete(true); }
    WC()->cart = $cart; WC()->session = $session;
    $packages_property->setValue(WC()->shipping(), $old_packages);
    $GLOBALS['current_user'] = $user;
    remove_filter('pre_option_pnpm_location_mappings', $mapping);
    remove_filter('pre_option_pnpm_delivery_policy_v1', $policy_filter);
    remove_filter('pre_option_pnpm_settings', $settings_filter);
}
