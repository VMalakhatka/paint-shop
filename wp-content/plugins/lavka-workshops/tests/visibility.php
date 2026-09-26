<?php
/** Local only: wp eval-file <file> --skip-plugins --skip-themes */
namespace Lavka\Workshops;
if (!defined('WP_CLI') || !WP_CLI || wp_parse_url(home_url(), PHP_URL_HOST) !== 'paint.local') throw new \RuntimeException('Local test only.');
require_once dirname(__DIR__) . '/lavka-workshops.php';
$mode='closed';add_filter('pre_option_lw_section_visibility', static function() use (&$mode) {return $mode;});
register();
$checks=0;$posts=[];$users=[];
$check=static function($ok,$message) use (&$checks) {if(!$ok)throw new \RuntimeException($message);$checks++;};
if (!defined('DOING_AJAX')) define('DOING_AJAX',true);
add_filter('wp_die_ajax_handler', static fn()=>static function(){throw new \RuntimeException('ajax-finished');});
$ajax=static function($callback){ob_start();try{$callback();}catch(\RuntimeException $e){if($e->getMessage()!=='ajax-finished')throw $e;}return json_decode(ob_get_clean(),true);};
try {
    $id=wp_insert_post(['post_type'=>'lavka_workshop','post_status'=>'publish','post_title'=>'LW private visibility fixture','post_content'=>'Confidential test article']);$posts[]=$id;
    $ordinary=wp_insert_post(['post_type'=>'post','post_status'=>'publish','post_title'=>'LW ordinary fixture']);$posts[]=$ordinary;
    $date=validate_sessions([['date'=>'2030-10-01','time'=>'15:00','city'=>'kyiv','address'=>'Synthetic studio','duration'=>'120','price'=>'850','state'=>'open']]);update_post_meta($id,'_lw_sessions',$date);
    $server=rest_get_server();
    foreach(['subscriber','lavka_instructor'] as $role) {$uid=wp_insert_user(['user_login'=>'lw-private-'.wp_generate_uuid4(),'user_pass'=>wp_generate_password(32),'role'=>$role]);if(is_wp_error($uid))throw new \RuntimeException('User create failed');$users[$role]=$uid;}
    foreach([0,$users['subscriber']] as $uid) {
        wp_set_current_user($uid);
        $check(!section_allowed(),'Guest/customer cannot enter closed section');
        foreach(['/wp/v2/lavka_workshop','/wp/v2/lavka_workshop/'.$id] as $route) $check($server->dispatch(new \WP_REST_Request('GET',$route))->get_status()===404,'REST collection/item denied');
        $check(!get_posts(['post_type'=>'lavka_workshop','post_status'=>'publish','suppress_filters'=>true]),'Direct get_posts archive hidden');
        $check(!(new \WP_Query(['p'=>$id]))->posts,'Plain ID hidden');
        $check(!in_array($id,get_posts(['post_type'=>['post','lavka_workshop'],'fields'=>'ids','numberposts'=>-1]),true),'Mixed list excludes workshop');
        $check(!in_array($id,get_posts(['post_type'=>'any','fields'=>'ids','numberposts'=>-1]),true),'Any-type query excludes workshop');
        $check((bool)get_posts(['p'=>$ordinary,'post_type'=>'post']),'Ordinary pages remain available');
        $check(apply_filters('oembed_request_post_id',$id)===0,'Embed metadata hidden');
        $_GET=['workshop'=>$id];$check(!$ajax(__NAMESPACE__.'\\request_nonce')['success'],'Nonce endpoint denied');
        $_POST=['workshop'=>$id,'session'=>$date[0]['id'],'nonce'=>wp_create_nonce('lw_request_'.$id),'guest_name'=>'Synthetic Test','phone'=>'+380000000001','consent'=>'1'];
        $check(!$ajax(__NAMESPACE__.'\\receive_request')['success'],'Even valid booking nonce cannot bypass closed section');
    }
    wp_set_current_user($users['lavka_instructor']);
    $check(section_allowed(),'Instructor can test');
    $check((bool)get_posts(['post_type'=>'lavka_workshop','p'=>$id]),'Instructor sees published workshop');
    $check($server->dispatch(new \WP_REST_Request('GET','/wp/v2/lavka_workshop/'.$id))->get_status()===200,'Instructor REST read works');
    $_GET=['workshop'=>$id];$nonce=$ajax(__NAMESPACE__.'\\request_nonce')['data']['nonce'];
    $_POST['nonce']=$nonce;$check($ajax(__NAMESPACE__.'\\receive_request')['success'],'Instructor submits real test workflow');
    $requests=get_posts(['post_type'=>'lavka_mk_request','post_status'=>'private','meta_key'=>'_lw_workshop','meta_value'=>$id,'fields'=>'ids']);$posts=array_merge($posts,$requests);
    $check(count($requests)===1,'One private test request saved');
    $check(!isset(apply_filters('wp_sitemaps_post_types',get_post_types(['public'=>true],'objects'))['lavka_workshop']),'Core sitemap hides workshops even for staff');
    $check(apply_filters('rank_math/sitemap/exclude_post_type',false,'lavka_workshop'),'SEO sitemap hides workshops');
    $check(!apply_filters('rank_math/sitemap/exclude_post_type',false,'product'),'Shop sitemap unchanged');
    $check(section_request(['post_type'=>'lavka_workshop']) && section_request(['lavka_workshop'=>'slug']) && section_request(['p'=>$id]),'Archive, slug and plain route recognized');
    $mode='public';wp_set_current_user(0);
    $check(section_allowed(),'Explicit public mode restores guest access');
    $check($server->dispatch(new \WP_REST_Request('GET','/wp/v2/lavka_workshop/'.$id))->get_status()===200,'Public REST mode works');
    echo "PASS: $checks closed-section checks\n";
} finally {
    $_POST=[];$_GET=[];$mode='public';wp_set_current_user(0);
    foreach(array_reverse($posts) as $post)wp_delete_post($post,true);
    require_once ABSPATH.'wp-admin/includes/user.php';foreach($users as $uid)wp_delete_user($uid);
    delete_transient('lw_rate_'.hash_hmac('sha256',$_SERVER['REMOTE_ADDR']??'',wp_salt()));
}
