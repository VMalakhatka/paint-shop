<?php
/** Local synthetic orders/users, no payment, Folio, stock movement or outgoing mail. */
if (!defined('WP_CLI') || !WP_CLI || parse_url(home_url(), PHP_URL_HOST) !== 'paint.local') throw new RuntimeException('Local only');
add_filter('pre_http_request', static fn() => new WP_Error('test', 'External requests blocked'), PHP_INT_MAX);
$sent = []; $mail_ok = true;
add_filter('pre_wp_mail', static function ($return, $mail) use (&$sent, &$mail_ok) { $sent[] = $mail; return $mail_ok; }, PHP_INT_MAX, 2);
add_filter('woocommerce_email_enabled_customer_new_account', '__return_true');
$users = []; $orders = []; $checks = 0;
$check = static function ($ok, $label) use (&$checks): void { if (!$ok) throw new RuntimeException($label); $checks++; };
$prefix = 'checkout-fixture-' . substr(wp_generate_uuid4(), 0, 12);
$make = static function ($email) use (&$orders): WC_Order {
    $order = new WC_Order(); $order->set_created_via('checkout-comfort-fixture');
    $order->set_billing_email($email); $order->set_billing_first_name('Fixture');
    $order->set_billing_phone('+380001112233'); $order->set_billing_address_1('Synthetic street');
    $order->save(); $orders[] = $order->get_id(); return $order;
};
$previous_user = get_current_user_id(); wp_set_current_user(0);
try {
    $o = $make($prefix . '@example.invalid');
    pc_guest_customer_attach_order($o);
    $users[] = $uid = $o->get_customer_id();
    $check($uid > 0 && email_exists($o->get_billing_email()) === $uid, 'New account exists and belongs to order email');
    $check(get_user_by('id', $uid)->roles === ['customer'], 'New account has customer role');
    $check(get_user_meta($uid, '_folio_partner_id', true) === PC_GUEST_CUSTOMER_FOLIO_ID, 'New account gets default mapping');
    $check($o->get_meta('_pc_guest_customer_account_created') === 'yes', 'Created flag persisted');
    $check($o->get_meta('_pc_guest_customer_account_email_sent') === 'yes' && count($sent) === 1, 'Exactly one account mail accepted by intercepted transport');
    $check(str_contains($sent[0]['message'], 'key=') || str_contains($sent[0]['message'], 'key%3D'), 'Mail contains password setup link');
    $check(get_current_user_id() === 0, 'Guest never authenticated');
    pc_guest_customer_attach_order($o->get_id());
    $check(count($sent) === 1, 'Reprocessing does not resend or create account');
    $_GET['key'] = $o->get_order_key(); ob_start(); do_action('woocommerce_thankyou', $o->get_id()); $html = ob_get_clean();
    $check(str_contains($html, 'pc-account-result'), 'Checkout holder sees account outcome');
    unset($_GET['key']); ob_start(); do_action('woocommerce_thankyou', $o->get_id()); $html = ob_get_clean();
    $check(!str_contains($html, 'pc-account-result'), 'No account result without key or owner');

    // Existing profiles, including staff, cannot be changed through guest checkout.
    foreach (['customer', 'opt', 'administrator'] as $role) {
        $id = wp_insert_user(['user_login'=>$prefix.'-'.$role, 'user_email'=>$prefix.'-'.$role.'@example.invalid', 'user_pass'=>wp_generate_password(32), 'role'=>$role]);
        $check(!is_wp_error($id), 'Existing synthetic user created'); $users[] = $id;
        update_user_meta($id, 'billing_phone', '+380009998877');
        update_user_meta($id, '_folio_partner_id', 'fixture-mapping');
        $before = get_user_meta($id);
        $existing = $make($prefix.'-'.$role.'@example.invalid'); pc_guest_customer_attach_order($existing);
        $check($existing->get_customer_id() === $id, 'Existing email linked');
        $after = get_user_meta($id);
        unset($after['wc_last_active'], $before['wc_last_active']); // Woo bookkeeping, not profile fields.
        $changed = array_keys(array_filter($after, static fn($v,$k) => !isset($before[$k]) || $before[$k] !== $v, ARRAY_FILTER_USE_BOTH));
        $check(!$changed && get_user_by('id',$id)->roles === [$role], 'Existing profile, role and mapping unchanged: '.implode(',', $changed));
        $check(get_current_user_id() === 0 && count($sent) === 1, 'Existing email never grants login or resends welcome');
    }
    $different = $make($prefix.'-different@example.invalid'); pc_guest_customer_attach_order($different); $users[] = $different->get_customer_id();
    $check($different->get_customer_id() !== $uid && $different->get_customer_id() > 0, 'Same phone with different email creates separate account');
    $mail_ok = false; $failed = $make($prefix.'-mail-failed@example.invalid'); pc_guest_customer_attach_order($failed); $users[] = $failed->get_customer_id();
    $check($failed->get_customer_id() > 0 && $failed->get_meta('_pc_guest_customer_account_email_sent') === 'no', 'Mail failure preserves account/order with honest mail status');
    $empty = $make(''); pc_guest_customer_attach_order($empty);
    $check(!$empty->get_customer_id() && $empty->get_meta('_pc_guest_customer_register_status') === 'skipped', 'No email cannot link by phone');

    $pickup = new WC_Shipping_Rate('pnpm_pickup:123', 'Pickup', 0, [], 'local_pickup');
    $delivery = new WC_Shipping_Rate('pnpm_nova_poshta:1', 'Delivery', 0, [], 'pnpm_nova_poshta');
    $packages = [['rates'=>[$pickup->get_id()=>$pickup, $delivery->get_id()=>$delivery]]];
    $check(psu_checkout_pickup_only([$pickup->get_id()],$packages), 'Actual pickup rate recognized');
    $check(!psu_checkout_pickup_only([$delivery->get_id()],$packages), 'Delivery retains address requirements');
    $check(!psu_checkout_pickup_only(['pnpm_pickup:forged'],$packages), 'Unknown pickup ID rejected');
    $check(!psu_checkout_pickup_only([],[]), 'No shipping is not pickup');
    $check(!psu_checkout_pickup_only([$pickup->get_id()],[$packages[0],$packages[0]]), 'Every package must be selected');
    $check(!psu_checkout_pickup_only([$pickup->get_id(),$delivery->get_id()],[$packages[0],$packages[0]]), 'Mixed delivery retains address requirements');
    $property = new ReflectionProperty(WC_Shipping::class, 'packages'); $old_packages = $property->getValue(WC()->shipping());
    $property->setValue(WC()->shipping(),$packages);
    try {
        $errors = new WP_Error();
        foreach (['billing_address_1','billing_city','billing_state','billing_postcode','shipping_address_1','billing_first_name','billing_phone','billing_country'] as $field) $errors->add($field.'_required','Required');
        $errors->add('billing_postcode_validation','Invalid postcode');
        $errors->add('billing_phone_validation','Invalid phone');
        psu_checkout_validate_contact_and_pickup(['shipping_method'=>[$pickup->get_id()],'billing_email'=>''],$errors);
        $check(!$errors->get_error_message('billing_address_1_required') && !$errors->get_error_message('shipping_address_1_required'), 'Pickup removes address required errors');
        $check(!$errors->get_error_message('billing_postcode_validation'), 'Hidden unused postcode cannot block pickup');
        foreach (['billing_first_name_required','billing_phone_required','billing_country_required','billing_email_required','billing_phone_validation'] as $code) $check((bool)$errors->get_error_message($code), 'Preserve '.$code);
        $errors = new WP_Error('billing_address_1_required','Required');
        psu_checkout_validate_contact_and_pickup(['shipping_method'=>[$delivery->get_id()],'billing_email'=>'fixture@example.invalid'],$errors);
        $check((bool)$errors->get_error_message('billing_address_1_required'),'Delivery keeps address error');
        psu_checkout_validate_contact_and_pickup(['shipping_method'=>[$pickup->get_id()],'billing_email'=>'invalid'],$errors);
        $check((bool)$errors->get_error_message('billing_email_validation'), 'Pickup still rejects malformed email');
        $fields = apply_filters('woocommerce_checkout_fields',['billing'=>['billing_email'=>['required'=>false,'validate'=>[]]]]);
        $check($fields['billing']['billing_email']['required'] && in_array('email',$fields['billing']['billing_email']['validate'],true),'Email required and validated');
    } finally { $property->setValue(WC()->shipping(),$old_packages); }
    echo wp_json_encode(['passed'=>$checks,'outgoing_emails'=>0,'payments'=>0,'external_requests_allowed'=>false]) . PHP_EOL;
} finally {
    require_once ABSPATH.'wp-admin/includes/user.php';
    foreach ($orders as $id) { $order = wc_get_order($id); if ($order && $order->get_created_via() === 'checkout-comfort-fixture') $order->delete(true); }
    foreach (array_unique($users) as $id) { $user=get_user_by('id',$id); if ($user && str_starts_with($user->user_email,$prefix)) wp_delete_user($id); }
    wp_set_current_user($previous_user);
}
