<?php
/** Local synthetic users only; product and stock reads, no orders or Folio calls. */
if (!defined('WP_CLI') || !WP_CLI || parse_url(home_url(), PHP_URL_HOST) !== 'paint.local') throw new RuntimeException('Local only');
add_filter('pre_http_request', static fn() => new WP_Error('test', 'External HTTP blocked'), PHP_INT_MAX);
add_filter('pre_wp_mail', '__return_true', PHP_INT_MAX);
$file = '/tmp/psu-mobile-cart-fixture.json';
if (($args[0] ?? '') === 'cleanup') {
    require_once ABSPATH . 'wp-admin/includes/user.php';
    $data = json_decode(file_get_contents($file), true);
    foreach ($data['users'] as $session) {
        $user = get_user_by('id', $session['id']);
        if (!$user || strpos($user->user_login, 'psu-cart-test-') !== 0) throw new RuntimeException('Synthetic users only');
        wp_delete_user($user->ID);
    }
    unlink($file);
    echo "Mobile cart fixture removed.\n";
    return;
}
if (is_file($file)) throw new RuntimeException('Clean existing fixture first');
$check = static function ($ok, $label): void { if (!$ok) throw new RuntimeException($label); };
$product = wc_get_product(wc_get_product_id_by_sku('KR-79406'));
$check($product && $product->is_purchasable(), 'Representative local product required');
$data = ['product' => $product->get_id(), 'url' => $product->get_permalink(), 'shop' => wc_get_page_permalink('shop'),
    'home' => home_url('/'), 'cart' => wc_get_cart_url(), 'checkout' => wc_get_checkout_url(), 'users' => []];
$categories = $product->get_category_ids();
$data['category'] = $categories ? get_term_link($categories[0], 'product_cat') : $data['shop'];
$old = umask(0077);
try {
    foreach (['retail' => 'customer', 'wholesale' => 'opt', 'draft' => 'customer'] as $name => $role) {
        $id = wp_insert_user(['user_login' => 'psu-cart-test-' . wp_generate_uuid4(), 'user_pass' => wp_generate_password(40), 'role' => $role]);
        $check(!is_wp_error($id), 'Fixture user created');
        if ($name === 'draft') update_user_meta($id, '_pcoe_catalog_mode', 'draft');
        $data['users'][$name] = ['id' => $id, 'cookies' => [['name' => LOGGED_IN_COOKIE,
            'value' => wp_generate_auth_cookie($id, time() + 3600, 'logged_in'), 'domain' => 'paint.local', 'path' => '/', 'httpOnly' => true]]];
        file_put_contents($file, wp_json_encode($data));
    }
    wp_set_current_user($data['users']['retail']['id']);
    WC()->customer = new WC_Customer(get_current_user_id());
    WC()->cart = new WC_Cart();
    $html = psu_mobile_cart_html();
    $check(strpos($html, 'data-count="0" hidden') !== false, 'Cache-neutral shell');
    $check(WC()->cart->add_to_cart($product->get_id(), 2), 'Normal local cart accepts quantity');
    WC()->cart->calculate_totals();
    $html = psu_mobile_cart_html(true);
    $check(strpos($html, 'data-count="2"') !== false, 'Unit count from Woo cart');
    $check(strpos($html, wp_kses_post(WC()->cart->get_cart_subtotal())) !== false, 'Price rendered by Woo');
    $check(strpos($html, esc_url(wc_get_cart_url())) !== false, 'Configured cart URL');
    $check(strpos(psu_mobile_cart_html(), 'data-count="0" hidden') !== false, 'Populated cart never leaks into cached shell');
    $fragments = apply_filters('woocommerce_add_to_cart_fragments', ['existing' => 'untouched']);
    $check($fragments['existing'] === 'untouched' && isset($fragments['#psu-mobile-cart']), 'Existing fragments preserved');
    update_user_meta(get_current_user_id(), '_pcoe_catalog_mode', 'draft');
    $check(strpos(psu_mobile_cart_html(true), 'data-count="0"') !== false, 'Draft hides cart CTA');
    delete_user_meta(get_current_user_id(), '_pcoe_catalog_mode');
    WC()->cart->empty_cart();
    echo "Mobile cart local checks passed; temporary browser users prepared.\n";
} finally { umask($old); }
