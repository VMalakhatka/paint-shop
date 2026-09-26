<?php
/** Local integration: wp eval-file <file> --skip-plugins --skip-themes */
namespace Lavka\Workshops;
if (!defined('WP_CLI') || !WP_CLI || wp_parse_url(home_url(), PHP_URL_HOST) !== 'paint.local') throw new \RuntimeException('Local test only.');
require_once dirname(__DIR__) . '/lavka-workshops.php';
register();
$checks=0; $posts=[]; $user=0;
$check=static function($ok,$message) use (&$checks) { if(!$ok) throw new \RuntimeException($message); $checks++; };
try {
    $patterns=studio_patterns();
    $check(count($patterns) === 10, 'Three starters plus seven compositions');
    foreach ($patterns as $pattern) {
        $check(has_blocks($pattern['content']), 'Patterns use real blocks');
        $check(serialize_blocks(parse_blocks($pattern['content'])) === $pattern['content'], 'Core parser round trip');
        $check(!str_contains($pattern['content'], '[shortcode') && !str_contains($pattern['content'], '<script'), 'Portable safe pattern content');
    }
    $user=wp_insert_user(['user_login'=>'lw-block-test-'.wp_generate_uuid4(),'user_pass'=>wp_generate_password(32),'role'=>'lavka_instructor']);
    if(is_wp_error($user)) throw new \RuntimeException($user->get_error_message());
    wp_set_current_user($user);
    $post=wp_insert_post(['post_type'=>'lavka_workshop','post_status'=>'draft','post_title'=>'Synthetic Gutenberg fixture','post_author'=>$user,'post_content'=>'<p>Legacy text stays.</p>']);
    $posts[]=$post;
    $check(use_block_editor_for_post($post), 'Gutenberg enabled for MK');
    $check(get_post_field('post_content',$post) === '<p>Legacy text stays.</p>', 'Opening does not migrate legacy content');
    $context=new \WP_Block_Editor_Context(['post'=>get_post($post)]);
    $check(in_array('core/columns',apply_filters('allowed_block_types_all',true,$context),true), 'Flexible layout blocks allowed');
    $check(apply_filters('allowed_block_types_all',true,new \WP_Block_Editor_Context()) === true, 'Other editors unchanged');
    $server=rest_get_server();
    $routes=$server->get_routes();
    $check(isset($routes['/wp/v2/lavka_workshop']), 'Article REST endpoint registered');
    $check(!isset($routes['/wp/v2/lavka_mk_request']), 'Private booking requests stay out of REST');
    $sessions=validate_sessions([['date'=>'2030-10-01','time'=>'15:00','city'=>'kyiv','address'=>'Synthetic studio','duration'=>'120','price'=>'850','state'=>'open']]);
    update_post_meta($post,'_lw_sessions',$sessions);
    $request=new \WP_REST_Request('POST','/wp/v2/lavka_workshop/'.$post);
    $request->set_body_params(['content'=>$patterns['workshop']['content'],'title'=>'Synthetic block article']);
    $reply=$server->dispatch($request);
    $check($reply->get_status()===200, 'Instructor saves via Gutenberg REST');
    $check(get_post_field('post_content',$post)===$patterns['workshop']['content'],'Block markup saved intact');
    $check(sessions($post)===$sessions,'REST article save preserves schedule');
    $revision=_wp_put_post_revision($post);
    $request->set_body_params(['content'=>$patterns['report']['content']]);
    $server->dispatch($request);
    wp_restore_post_revision($revision);
    $check(get_post_field('post_content',$post)===$patterns['workshop']['content'],'Revision restores block article');
    $request=new \WP_REST_Request('GET','/wp/v2/lavka_workshop/'.$post);
    wp_set_current_user(0);
    $check($server->dispatch($request)->get_status()>=400,'Guest cannot read draft through new REST route');
    $request=new \WP_REST_Request('POST','/wp/v2/lavka_workshop/'.$post);
    $request->set_body_params(['content'=>'Unauthorized']);
    $check($server->dispatch($request)->get_status()>=400,'Guest cannot edit through REST');
    wp_set_current_user($user);
    wp_update_post(['ID'=>$post,'post_status'=>'publish']);
    wp_set_current_user(0);
    $reply=$server->dispatch(new \WP_REST_Request('GET','/wp/v2/lavka_workshop/'.$post));
    $check($reply->get_status()===200,'Published article available in public REST');
    $check(!str_contains(wp_json_encode($reply->get_data()),'_lw_sessions'),'Private meta is not exposed by enabling article REST');
    $check(str_contains(do_blocks($patterns['workshop']['content']),'<h2'),'Published blocks render');
    echo "PASS: $checks Gutenberg integration checks\n";
} finally {
    $_POST=[];wp_set_current_user(0);
    foreach($posts as $id) wp_delete_post($id,true);
    if($user && !is_wp_error($user)){require_once ABSPATH.'wp-admin/includes/user.php';wp_delete_user($user);}
}
