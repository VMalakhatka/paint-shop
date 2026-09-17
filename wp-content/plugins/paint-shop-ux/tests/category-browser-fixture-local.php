<?php
// Temporary local auth sessions and current-code RU fixture; no user/profile edits.
if (!defined('WP_CLI') || !WP_CLI || wp_get_environment_type() !== 'local'
    || parse_url(home_url(), PHP_URL_HOST) !== 'paint.local') throw new RuntimeException('Local only');
$file = '/tmp/psu-category-browser-session.json';
$mode = $args[0] ?? 'prepare';
if ($mode === 'cleanup') {
    if (is_file($file)) {
        $data = json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        foreach ($data['sessions'] as $session) WP_Session_Tokens::get_instance($session['user'])->destroy($session['token']);
        if (isset($data['test_version']) && get_option('psu_category_menu_version') === $data['test_version']) {
            if ($data['original_version'] === false) delete_option('psu_category_menu_version');
            else update_option('psu_category_menu_version', $data['original_version'], false);
        }
        unlink($file);
    }
    echo "Temporary local sessions removed\n";
    return;
}
if ($mode === 'bump') {
    $data = json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
    $data['test_version'] = wp_generate_uuid4();
    file_put_contents($file, wp_json_encode($data), LOCK_EX);
    update_option('psu_category_menu_version', $data['test_version'], false);
    echo "Isolated local menu namespace selected\n";
    return;
}
if (is_file($file)) throw new RuntimeException('Clean up existing fixture first');
$config = null;
foreach (get_option('widget_psu_category_menu', []) as $number => $settings) {
    if (is_numeric($number)) $config = PSU_Category_Menu::config('psu_category_menu-'.$number);
    if ($config) break;
}
if (!$config) throw new RuntimeException('Active native widget required');
$quick = null;
foreach (get_posts(['post_type'=>'page','post_status'=>'publish','numberposts'=>-1]) as $page) {
    if (has_shortcode($page->post_content,'pc_quick_order') && !$page->post_password) { $quick = $page; break; }
}
if (!$quick) throw new RuntimeException('Quick-order page required');
$data = ['sessions'=>[], 'original_version'=>get_option('psu_category_menu_version', false),
    'quick_url'=>get_permalink($quick), 'quick_id'=>$quick->ID, 'widget'=>$config['widget']];
$index = PSU_Category_Menu::index(); $depth = 0;
foreach ($index['parents'] as $id => $parent) {
    $path = PSU_Category_Menu::path($id, $index);
    if (isset($index['visible'][$id]) && count($path) > $depth) {
        $depth = count($path); $data['deep_slug'] = get_term($id, 'product_cat')->slug;
    }
}
$old = umask(0077);
try {
    // Journal each created token so a failed preparation can still be cleaned up.
    file_put_contents($file, wp_json_encode($data), LOCK_EX);
    foreach (['wholesale'=>pc_wholesale_customer_roles(),'customer'=>['customer'],'manager'=>['shop_manager']] as $label=>$roles) {
        $candidates = get_users(['role__in'=>$roles,'number'=>20]); $user = null;
        foreach ($candidates as $candidate) {
            if (pc_wholesale_customer_can_access($candidate) === ($label === 'wholesale')) { $user = $candidate; break; }
        }
        if (!$user) { $data['unavailable'][] = $label; continue; }
        $expires = time()+3600;
        $token = WP_Session_Tokens::get_instance($user->ID)->create($expires);
        $data['sessions'][$label] = ['user'=>$user->ID,'token'=>$token,'cookies'=>[[
            'name'=>LOGGED_IN_COOKIE,'value'=>wp_generate_auth_cookie($user->ID,$expires,'logged_in',$token),
            'domain'=>'paint.local','path'=>'/','httpOnly'=>true,'secure'=>false,'sameSite'=>'Lax',
        ]]];
        file_put_contents($file, wp_json_encode($data), LOCK_EX);
    }
    file_put_contents($file, wp_json_encode($data), LOCK_EX);
    if (!isset($data['sessions']['wholesale'])) throw new RuntimeException('Existing local wholesale user required');
    $switched = switch_to_locale('ru_RU');
    try {
        $widget = new PSU_Category_Widget(); $widget->id = $config['widget'];
        ob_start(); PSU_Category_Menu::render($config, $widget,
            ['before_widget'=>'<aside class="widget widget_psu_category_menu">','after_widget'=>'</aside>','before_title'=>'<h2>','after_title'=>'</h2>'], $config);
        file_put_contents('/tmp/psu-category-menu-ru.html', ob_get_clean());
    } finally { if ($switched) restore_previous_locale(); }
    echo wp_json_encode(['ready'=>true,'roles'=>array_keys($data['sessions']),'unavailable'=>$data['unavailable']??[]]),"\n";
} finally { umask($old); }
