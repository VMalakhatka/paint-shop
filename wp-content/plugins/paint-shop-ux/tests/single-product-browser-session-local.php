<?php
/** Temporary local read-only browser sessions; cleanup always destroys our tokens. */
if (!defined('WP_CLI') || !WP_CLI || wp_get_environment_type() !== 'local'
    || parse_url(home_url(), PHP_URL_HOST) !== 'paint.local') throw new RuntimeException('Local only');
$file = '/tmp/psu-product-browser-session.json';
if (($args[0] ?? '') === 'cleanup') {
    if (is_file($file)) {
        $data = json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        foreach ($data['sessions'] as $session) WP_Session_Tokens::get_instance($session['user'])->destroy($session['token']);
        unlink($file);
    }
    echo "Temporary product sessions removed\n"; return;
}
if (is_file($file)) throw new RuntimeException('Clean up existing product sessions first');
$id = wc_get_product_id_by_sku('KR-79406');
if (!$id) throw new RuntimeException('Local representative SKU is required');
$product = wc_get_product($id);
$plain = static function (string $html): string { return html_entity_decode(wp_strip_all_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'); };
$data = ['sessions' => [], 'url' => get_permalink($id), 'title' => $product->get_name(), 'sku' => $product->get_sku(),
    'guest_price' => $plain($product->get_price_html()), 'help_url' => pc_wholesale_help_url('katalog'), 'shop_url' => wc_get_page_permalink('shop')];
$old = umask(0077);
try {
    file_put_contents($file, wp_json_encode($data), LOCK_EX);
    foreach (['wholesale' => pc_wholesale_customer_roles(), 'customer' => ['customer']] as $label => $roles) {
        $users = get_users(['role__in' => $roles, 'number' => 1]);
        if (!$users) continue;
        $user = $users[0]; $expires = time() + 1800;
        $token = WP_Session_Tokens::get_instance($user->ID)->create($expires);
        $data['sessions'][$label] = ['user' => $user->ID, 'token' => $token, 'cookies' => [[
            'name' => LOGGED_IN_COOKIE, 'value' => wp_generate_auth_cookie($user->ID, $expires, 'logged_in', $token),
            'domain' => '127.0.0.1', 'path' => '/', 'httpOnly' => true, 'secure' => false, 'sameSite' => 'Lax',
        ]]];
        wp_set_current_user($user->ID);
        $data['sessions'][$label]['price'] = $plain(wc_get_product($id)->get_price_html());
        file_put_contents($file, wp_json_encode($data), LOCK_EX);
    }
    if (!isset($data['sessions']['wholesale'])) throw new RuntimeException('Local wholesale role required');
    echo wp_json_encode(['ready' => true, 'roles' => array_keys($data['sessions'])]), "\n";
} finally { umask($old); }
