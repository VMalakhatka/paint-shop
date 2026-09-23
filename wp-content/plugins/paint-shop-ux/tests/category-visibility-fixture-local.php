<?php
// Disposable local admin session and categories for browser regression.
if (!defined('WP_CLI') || !WP_CLI || wp_get_environment_type() !== 'local'
    || parse_url(home_url(), PHP_URL_HOST) !== 'paint.local') throw new RuntimeException('Local only');
$file = '/tmp/psu-category-visibility-session.json';
if (($args[0] ?? '') === 'cleanup') {
    if (!is_file($file)) return;
    $data = json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
    WP_Session_Tokens::get_instance($data['user'])->destroy($data['token']);
    if ($data['original'] === false) delete_option(PSU_Category_Menu_Visibility::OPTION);
    else update_option(PSU_Category_Menu_Visibility::OPTION, $data['original'], false);
    if (!empty($data['product'])) wc_get_product($data['product'])->delete(true);
    foreach (array_reverse($data['terms']) as $id) wp_delete_term($id, 'product_cat');
    unlink($file);
    echo "Visibility browser fixture removed.\n";
    return;
}
if (is_file($file)) throw new RuntimeException('Clean up existing fixture first');
$admins = get_users(['role'=>'administrator','number'=>1,'fields'=>'ID']);
if (!$admins) throw new RuntimeException('Local admin required');
$user = (int) $admins[0]; $expires = time() + 3600;
$token = WP_Session_Tokens::get_instance($user)->create($expires);
$data = ['user'=>$user,'token'=>$token,'original'=>get_option(PSU_Category_Menu_Visibility::OPTION, false),'terms'=>[],'cookies'=>[]];
$mask = umask(0077);
try {
    $persist = static function () use (&$data, $file) { file_put_contents($file, wp_json_encode($data), LOCK_EX); };
    $persist();
    foreach ([AUTH_COOKIE=>'auth', LOGGED_IN_COOKIE=>'logged_in'] as $name=>$scheme) {
        $data['cookies'][] = ['name'=>$name,'value'=>wp_generate_auth_cookie($user,$expires,$scheme,$token),
            'domain'=>'paint.local','path'=>'/','httpOnly'=>true,'secure'=>false,'sameSite'=>'Lax'];
    }
    foreach (['empty','root','child'] as $kind) {
        $result = wp_insert_term('PSU Visibility ' . ucfirst($kind) . ' ' . wp_generate_uuid4(), 'product_cat',
            ['parent'=>$kind === 'child' ? $data['root'] : 0]);
        if (is_wp_error($result)) throw new RuntimeException($result->get_error_message());
        $data[$kind] = (int) $result['term_id']; $data['terms'][] = $data[$kind]; $persist();
        $data[$kind . '_url'] = get_term_link($data[$kind], 'product_cat');
    }
    $product = new WC_Product_Simple(); $product->set_name('PSU visibility browser fixture');
    $product->set_status('publish'); $product->set_regular_price('1'); $product->set_category_ids([$data['child']]);
    $data['product'] = $product->save(); $persist();
    echo "Local visibility browser fixture ready.\n";
} finally { umask($mask); }
