<?php
if (!defined('WP_CLI') || !WP_CLI || wp_get_environment_type() !== 'local'
    || parse_url(home_url(), PHP_URL_HOST) !== 'paint.local') throw new RuntimeException('Local only');
$file = '/tmp/psu-search-session.json';
if (($args[0] ?? '') === 'cleanup') {
    if (is_file($file)) {
        $data = json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        WP_Session_Tokens::get_instance($data['user'])->destroy($data['token']);
        unlink($file);
    }
    echo "Temporary search session removed\n"; return;
}
if (is_file($file)) throw new RuntimeException('Clean up existing session first');
$user = get_users(['role__in'=>pc_wholesale_customer_roles(),'number'=>1])[0];
$expires = time()+3600;
$token = WP_Session_Tokens::get_instance($user->ID)->create($expires);
$data = ['user'=>$user->ID,'token'=>$token,'cookies'=>[[
    'name'=>LOGGED_IN_COOKIE,'value'=>wp_generate_auth_cookie($user->ID,$expires,'logged_in',$token),
    'domain'=>'paint.local','path'=>'/','httpOnly'=>true,'secure'=>false,'sameSite'=>'Lax',
]]];
wp_set_current_user($user->ID);
$data['expected'] = PSU_Catalog_Search::products(['catalog_search'=>'KR-79406'])[0]['price'];
$old = umask(0077);
try { file_put_contents($file, wp_json_encode($data), LOCK_EX); } finally { umask($old); }
echo "Temporary wholesale search session prepared\n";
