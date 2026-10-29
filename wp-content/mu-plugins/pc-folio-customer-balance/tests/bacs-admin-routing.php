<?php
/** Local regression: actual Woo settings routing and REST controllers; recipient writes stay in memory. */
defined('ABSPATH') || exit;
if (!defined('WP_CLI') || !WP_CLI || wp_parse_url(home_url(), PHP_URL_HOST) !== 'paint.local') throw new RuntimeException('Local fixture only.');
require_once __DIR__ . '/payment-fixture.php';
require_once WC_ABSPATH . 'includes/admin/class-wc-admin-settings.php';
class PCOEBacsRoutingRedirect extends RuntimeException {}
$checks = 0; $users = []; $writes = []; $http_calls = 0; $mail_calls = 0;
$assert = static function ($condition, string $label) use (&$checks) { if (!$condition) throw new RuntimeException($label); $checks++; };
$original_user = get_current_user_id(); $original_get = $_GET; $original_server = $_SERVER;
$original_tab = $GLOBALS['current_tab'] ?? null; $original_section = $GLOBALS['current_section'] ?? null;
$account = pc_payment_fixture_account(['name'=>'Synthetic recipient','tax_id'=>'00000000','iban'=>'UA89'.str_repeat('0',25),'bank'=>'Synthetic bank','mfo'=>'000000','confirmed'=>true]);
$state = [
    'woocommerce_bacs_accounts' => [$account],
    'woocommerce_bacs_settings' => ['enabled'=>'yes','title'=>'Synthetic transfer'],
    'woocommerce_cod_settings' => ['enabled'=>'no','title'=>'Synthetic COD'],
    'pc_folio_invoice_settings' => ['logo_id'=>0],
];
// Use a reference: subsequent reads must see attempted writes, if any.
$get = static function ($pre, $option) use (&$state) { return array_key_exists($option, $state) ? $state[$option] : $pre; };
$block = static function ($value, $option, $old) use (&$state, &$writes) {
    $writes[] = $option;
    if (array_key_exists($option, $state)) $state[$option] = $value;
    return $old;
};
$no_http = static function () use (&$http_calls) { $http_calls++; return new WP_Error('fixture_external_block', 'External requests blocked'); };
$no_mail = static function () use (&$mail_calls) { $mail_calls++; return false; };
$destination = null;
$redirect = static function ($url) use (&$destination) { $destination = $url; throw new PCOEBacsRoutingRedirect(); };
add_filter('pre_http_request', $no_http, PHP_INT_MAX, 3); add_filter('pre_wp_mail', $no_mail, PHP_INT_MAX, 2);
try {
    foreach (['administrator', 'shop_manager', 'customer'] as $role) {
        $id = wp_insert_user(['user_login'=>'bacs-routing-'.wp_generate_uuid4(), 'user_pass'=>wp_generate_password(30), 'user_email'=>wp_generate_uuid4().'@example.invalid', 'role'=>$role]);
        if (is_wp_error($id)) throw new RuntimeException('Cannot create local test identity');
        $users[] = $id;
    }
    [$admin, $manager, $customer] = $users;
    add_filter('pre_option', $get, PHP_INT_MAX, 3); add_filter('pre_update_option', $block, PHP_INT_MAX, 3);
    wp_set_current_user($admin);
    $page = null;
    foreach (WC_Admin_Settings::get_settings_pages() as $candidate) {
        if ($candidate instanceof WC_Settings_Payment_Gateways) $page = $candidate;
    }
    $assert($page instanceof WC_Settings_Payment_Gateways, 'Actual Woo Payments settings controller loaded');
    $assert(!$page->should_render_react_section('bacs'), 'Woo routes BACS to the complete PHP form');
    foreach (['', 'main', 'offline', 'cod', 'cheque'] as $section) {
        $assert($page->should_render_react_section($section), 'Other payment sections retain their native editor: '.$section);
    }
    $gateway = WC()->payment_gateways()->payment_gateways()['bacs'];
    $settings_url = $gateway->get_settings_url();
    parse_str((string) wp_parse_url($settings_url, PHP_URL_QUERY), $query);
    $assert(($query['section'] ?? '') === 'bacs' && !isset($query['path']), 'Woo Manage link selects complete BACS settings without React path');
    $GLOBALS['current_tab'] = 'checkout'; $GLOBALS['current_section'] = 'bacs';
    ob_start(); $page->output(); $html = ob_get_clean();
    foreach (['account_name', 'tax_id', 'iban', 'bank_name', 'sort_code', 'account_number', 'bic'] as $field) {
        $assert(str_contains($html, 'name="pc_folio_bank_account['.$field.']"'), 'Actual settings page renders recipient field: '.$field);
    }
    $assert(str_contains($html, 'name="pc_folio_bank_confirmed"') && str_contains($html, 'name="_pc_folio_bank_nonce"'), 'Actual page renders confirmation and guarded save nonce');
    $assert(str_contains($page->add_body_classes(''), 'woocommerce-settings-payments-section_legacy'), 'Woo marks the page for complete settings layout');
    if ($file = getenv('PCOE_BACS_ROUTING_PREVIEW')) file_put_contents($file, '<meta charset="utf-8">'.$html);

    add_filter('wp_redirect', $redirect, -999);
    foreach (['/offline/bacs', '/offline/bacs/'] as $path) {
        $_SERVER['REQUEST_METHOD'] = 'GET'; $_GET = ['page'=>'wc-settings', 'tab'=>'checkout', 'path'=>$path]; $destination = null;
        try { pc_folio_bacs_redirect_incomplete_editor(); } catch (PCOEBacsRoutingRedirect $stop) {}
        $assert($destination === pc_folio_bacs_settings_url(), 'Old React bookmark redirects to the complete form');
    }
    foreach ([['GET', '/offline/cod'], ['POST', '/offline/bacs'], ['GET', ['bad']]] as [$method, $path]) {
        $_SERVER['REQUEST_METHOD'] = $method; $_GET = ['page'=>'wc-settings', 'tab'=>'checkout', 'path'=>$path]; $destination = null;
        pc_folio_bacs_redirect_incomplete_editor();
        $assert($destination === null, 'Unrelated, malformed or posted navigation is not redirected');
    }
    $_GET = $original_get; $_SERVER = $original_server;

    $server = rest_get_server();
    // Register the shipped controllers even when the local site's optional V4 feature is disabled.
    (new Automattic\WooCommerce\Internal\RestApi\Routes\V4\Settings\PaymentGateways\Controller())->register_routes();
    (new Automattic\WooCommerce\Admin\API\Options())->register_routes();
    $dispatch = static function ($method, $route, array $payload) use (&$writes, $server) {
        $request = new WP_REST_Request($method, $route);
        $request->set_header('Content-Type', 'application/json'); $request->set_body(wp_json_encode($payload));
        $writes = [];
        return $server->dispatch($request);
    };
    foreach ([$admin, $manager] as $actor) {
        wp_set_current_user($actor);
        foreach (['POST', 'PUT', 'PATCH'] as $method) {
            foreach ([[], [$account], null] as $accounts) {
                foreach ([
                    ['/wc/v4/settings/payment-gateways/bacs', ['values'=>['account_details'=>$accounts], 'title'=>'Must not be saved']],
                    ['/wc-admin/options', ['woocommerce_bacs_accounts'=>$accounts, 'woocommerce_bacs_settings'=>['title'=>'Must not be saved']]],
                ] as [$route, $payload]) {
                    $before = $state; $response = $dispatch($method, $route, $payload); $data = $response->get_data();
                    $assert($response->get_status() === 409 && ($data['code'] ?? '') === 'pc_folio_bacs_full_form_required', 'Incomplete REST account writer is rejected');
                    $assert($state === $before && $writes === [], 'Rejected REST request cannot partially change recipient or gateway settings');
                    $assert(str_contains($data['message'], pc_folio_bacs_settings_url()), 'REST rejection provides the complete editor URL');
                }
            }
        }
    }
    foreach ([$customer, 0] as $actor) {
        wp_set_current_user($actor);
        $response = $dispatch('POST', '/wc/v4/settings/payment-gateways/bacs', ['values'=>['account_details'=>[]]]);
        $assert(in_array($response->get_status(), [401,403], true) && $writes === [], 'Native REST permission checks still deny unauthorized users');
    }
    wp_set_current_user($admin);
    $response = $dispatch('GET', '/wc/v4/settings/payment-gateways/bacs', []);
    $assert($response->get_status() === 200 && $writes === [], 'BACS settings remain readable');
    $response = $dispatch('PATCH', '/wc/v4/settings/payment-gateways/bacs', ['title'=>'Updated synthetic title']);
    $assert($response->get_status() === 200 && $state['woocommerce_bacs_settings']['title'] === 'Updated synthetic title' && $state['woocommerce_bacs_accounts'] === [$account], 'Nonfinancial BACS updates remain available without touching recipient');
    $response = $dispatch('PATCH', '/wc/v4/settings/payment-gateways/cod', ['title'=>'Updated synthetic COD']);
    $assert($response->get_status() === 200 && $state['woocommerce_cod_settings']['title'] === 'Updated synthetic COD', 'Other gateways retain their REST settings editor');
    $response = $dispatch('POST', '/wc-admin/options', ['woocommerce_bacs_settings'=>['title'=>'Synthetic legacy title']]);
    $assert($response->get_status() === 200 && $state['woocommerce_bacs_accounts'] === [$account], 'Legacy non-recipient options remain available');
    $assert($http_calls === 0 && $mail_calls === 0, 'Routing and REST checks send no email or external requests');
    echo "PASS: $checks BACS admin routing and REST checks; option writes intercepted\n";
} finally {
    remove_filter('wp_redirect', $redirect, -999);
    remove_filter('pre_option', $get, PHP_INT_MAX); remove_filter('pre_update_option', $block, PHP_INT_MAX);
    $_GET = $original_get; $_SERVER = $original_server;
    $GLOBALS['current_tab'] = $original_tab; $GLOBALS['current_section'] = $original_section;
    wp_set_current_user($original_user);
    require_once ABSPATH.'wp-admin/includes/user.php'; foreach ($users as $id) wp_delete_user($id);
    remove_filter('pre_http_request', $no_http, PHP_INT_MAX); remove_filter('pre_wp_mail', $no_mail, PHP_INT_MAX);
}
