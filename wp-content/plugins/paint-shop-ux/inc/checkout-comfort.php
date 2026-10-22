<?php
defined('ABSPATH') || exit;

/** Keep classic checkout on the existing, email-based guest account workflow. */
add_filter('pre_option_woocommerce_enable_checkout_login_reminder', static function ($value) {
    return function_exists('is_checkout') && is_checkout() ? 'yes' : $value;
});
add_action('woocommerce_before_checkout_form', static function (): void {
    if (is_user_logged_in() || !function_exists('pc_guest_customer_attach_order')) return;
    echo '<section class="psu-checkout-account" aria-label="' . esc_attr__('Your account', 'paint-shop-ux') . '">';
    echo '<strong>' . esc_html__('Register while placing your order', 'paint-shop-ux') . '</strong>';
    echo '<p>' . esc_html__('You can sign in above or continue without signing in. After placing the order, we will create an account using your email and send a password setup link. If an account already uses that email, the order will be linked to it; signing in is required to access the account.', 'paint-shop-ux') . '</p>';
    echo '<a href="#billing_email">' . esc_html__('Continue and create an account', 'paint-shop-ux') . '</a></section>';
}, 11);

/** Street/location fields are optional only when every actual package is pickup. */
function psu_checkout_pickup_only(array $methods, array $packages): bool {
    if (!$packages || count($methods) !== count($packages)) return false;
    foreach ($packages as $index => $package) {
        $id = $methods[$index] ?? '';
        if (!is_string($id)) return false;
        $rate = $package['rates'][$id] ?? null;
        if (!$rate instanceof WC_Shipping_Rate || $rate->get_method_id() !== 'local_pickup') return false;
    }
    return true;
}

add_filter('woocommerce_checkout_fields', static function (array $fields): array {
    if (isset($fields['billing']['billing_email'])) {
        $fields['billing']['billing_email']['required'] = true;
        $fields['billing']['billing_email']['validate'] = array_unique(array_merge(
            (array) ($fields['billing']['billing_email']['validate'] ?? []), ['email']
        ));
    }
    return $fields;
}, 1000);

function psu_checkout_validate_contact_and_pickup(array $data, WP_Error $errors): void {
    // Also reject missing fields, which Woo's standard field loop may skip.
    if (empty($data['billing_email']) && !$errors->get_error_message('billing_email_required')) {
        $errors->add('billing_email_required', __('Enter your email address.', 'paint-shop-ux'), ['id' => 'billing_email']);
    }
    if (!empty($data['billing_email']) && !is_email($data['billing_email']) && !$errors->get_error_message('billing_email_validation')) {
        $errors->add('billing_email_validation', __('Enter a valid email address.', 'paint-shop-ux'), ['id' => 'billing_email']);
    }
    $packages = WC()->shipping()->get_packages();
    if (!psu_checkout_pickup_only((array) ($data['shipping_method'] ?? []), $packages)) return;
    foreach (['billing', 'shipping'] as $section) {
        foreach (['address_1', 'address_2', 'city', 'state', 'postcode'] as $field) {
            // These fields are hidden and unused for pickup; a stale postcode or
            // region must not leave an error that the buyer cannot correct.
            $errors->remove($section . '_' . $field . '_required');
            $errors->remove($section . '_' . $field . '_validation');
        }
    }
}
add_action('woocommerce_after_checkout_validation', 'psu_checkout_validate_contact_and_pickup', 1000, 2);

add_action('wp_enqueue_scripts', static function (): void {
    if (!function_exists('is_checkout') || !is_checkout() || is_wc_endpoint_url()) return;
    $base = dirname(__DIR__);
    $url = plugins_url('assets/', $base . '/paint-shop-ux.php');
    wp_enqueue_style('psu-checkout-comfort', $url . 'checkout-comfort.css', [], (string) filemtime($base . '/assets/checkout-comfort.css'));
    wp_enqueue_script('psu-checkout-comfort', $url . 'checkout-comfort.js', ['jquery', 'wc-checkout', 'wc-address-i18n'], (string) filemtime($base . '/assets/checkout-comfort.js'), true);
});

// Publish the actual rate IDs after each recalculation, rather than guessing by label.
add_action('woocommerce_review_order_after_shipping', static function (): void {
    $ids = [];
    foreach (WC()->shipping()->get_packages() as $package) {
        foreach ($package['rates'] ?? [] as $id => $rate) {
            if ($rate->get_method_id() === 'local_pickup') $ids[] = (string) $id;
        }
    }
    echo '<tr class="psu-pickup-hint" data-pickup-rates="' . esc_attr(wp_json_encode($ids)) . '" hidden><td colspan="2">';
    echo esc_html__('Store pickup: no street address is needed. Email is required.', 'paint-shop-ux') . '</td></tr>';
});
