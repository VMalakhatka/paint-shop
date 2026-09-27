<?php
/** Local integration. Synthetic users/orders/posts only; email and HTTP blocked. */
use PaintCore\PCOE\ConversationStore as Store;
use PaintCore\PCOE\Conversations as Chat;
if (!defined('WP_CLI') || !WP_CLI || wp_parse_url(home_url(),PHP_URL_HOST)!=='paint.local') throw new RuntimeException('Local only');
add_filter('pre_wp_mail','__return_true',PHP_INT_MAX);
add_filter('pre_http_request',static fn()=>new WP_Error('test','Blocked'),PHP_INT_MAX);
$users=[];$threads=[];$orders=[];$checks=0;$original=get_current_user_id();$option=get_option(Chat::OPTION,null);
$check=static function($ok,$why) use(&$checks){if(!$ok)throw new RuntimeException($why);$checks++;};
$denied=static function(callable $fn) use($check){try{$fn();}catch(Throwable $e){$check(true,'denied');return;}throw new RuntimeException('Expected rejection');};
require_once ABSPATH.'wp-admin/includes/user.php';
try {
 Store::register();$check(Store::storage_ready(),'InnoDB storage');update_option(Chat::OPTION,'yes',false);
 $tag=wp_generate_uuid4();foreach(['customer','opt','shop_manager','shop_manager'] as $i=>$role){$id=wp_insert_user(['user_login'=>'chat-test-'.$tag.'-'.$i,'user_email'=>'chat-'.$tag.'-'.$i.'@example.invalid','user_pass'=>wp_generate_password(30),'role'=>$role]);if(is_wp_error($id))throw new RuntimeException('User fixture failed');$users[]=$id;}
 [$client,$other,$manager,$manager2]=$users;
 wp_set_current_user($client);
 $key=wp_generate_uuid4();$id=Store::start($client,0,'Delivery question','Where is my order?',$key);$threads[]=$id;
 $check(Store::start($client,0,'Delivery question','Where is my order?',$key)===$id,'Duplicate start');
 $check(count(Store::messages($id)['messages'])===1,'One message after retry');
 $check(Store::meta($id)['state']==='waiting_manager','Customer requires manager reply');
 $check(Store::listing('',1)->found_posts===1,'Customer queue scoped');
 wp_set_current_user($other);$denied(fn()=>Store::read($id));$denied(fn()=>Store::reply($id,'Wrong customer',false,wp_generate_uuid4()));
 wp_set_current_user($client);$denied(fn()=>Store::reply($id,'Private',true,wp_generate_uuid4()));
 $order=new WC_Order();$order->set_customer_id($client);$order->save();$orders[]=$order->get_id();
 wp_set_current_user($other);$denied(fn()=>Store::start($other,$order->get_id(),'Foreign order','Hello',wp_generate_uuid4()));
 wp_set_current_user($client);$oid=Store::start($client,$order->get_id(),'Order question','First',wp_generate_uuid4());$threads[]=$oid;
 $check(Store::start($client,$order->get_id(),'Same order','Second',wp_generate_uuid4())===$oid,'Single thread per order');
 wp_set_current_user($manager);
 $old=Store::meta($id)['revision'];$msgkey=wp_generate_uuid4();$mid=Store::reply($id,'We are checking.',false,$msgkey);
 $check(Store::reply($id,'We are checking.',false,$msgkey)===$mid,'Duplicate reply');
 $check(Store::meta($id)['assignee']===$manager && Store::meta($id)['state']==='waiting_customer','First manager reply claims thread');
 Store::reply($id,'Internal confidential test',true,wp_generate_uuid4());
 $check(Store::meta($id)['state']==='waiting_customer','Internal note does not change who owes reply');
 $denied(fn()=>Store::assign($id,$manager2,'closed',$old));
 $denied(fn()=>Store::assign($id,$client,'closed',Store::meta($id)['revision']));
 Store::assign($id,$manager2,'closed',Store::meta($id)['revision']);
 $check(Store::meta($id)['assignee']===$manager2 && Store::meta($id)['state']==='closed','Assignment and closure');
 wp_set_current_user($client);
 $public=Store::messages($id)['messages'];$check(count($public)===2,'Private notes and assignment audit hidden');
 ob_start();Chat::messages($id,1,true);$html=ob_get_clean();$check(!str_contains($html,'confidential')&&!str_contains($html,'Assignment:'),'Internal contents absent in customer HTML');
 Store::reply($id,'A new question',false,wp_generate_uuid4());$check(Store::meta($id)['state']==='waiting_manager','Customer reopens closed thread');
 $check(Store::meta($id)['assignee']===$manager2,'Reopening preserves responsible manager');
 $before=count(Store::messages($id)['messages']);
 $reject=static function($empty,$data){return ($data['post_type']??'')===Store::MESSAGE?true:$empty;};
 add_filter('wp_insert_post_empty_content',$reject,10,2);
 $failedkey=wp_generate_uuid4();$denied(fn()=>Store::reply($id,'Fail once',false,$failedkey));remove_filter('wp_insert_post_empty_content',$reject,10);
 $check(count(Store::messages($id)['messages'])===$before,'Failed write rolled back');
 Store::reply($id,'Retry after failure',false,$failedkey);$check(count(Store::messages($id)['messages'])===$before+1,'Same request can retry after rollback');
 wp_set_current_user($manager2);$check(Store::listing('mine',1)->found_posts===1,'Mine filter');
 $prior=Store::meta($id);$count=count(Store::messages($id)['messages']);
 $break_state=static function($value,$object,$key) use($id){return $object===$id && $key==='_chat_state'?false:$value;};
 add_filter('update_post_metadata',$break_state,10,3);$denied(fn()=>Store::reply($id,'Rollback both message and queue',false,wp_generate_uuid4()));remove_filter('update_post_metadata',$break_state,10);
 $check(count(Store::messages($id)['messages'])===$count && Store::meta($id)===$prior,'Message and state rollback atomically');

 $check(Store::listing('unassigned',1)->found_posts>=1,'Unassigned filter');
 $_GET=['chat_id'=>$id];ob_start();Chat::render();$ui=ob_get_clean();$check(str_contains($ui,'pcoe-chat-messages')&&str_contains($ui,'name="assignee"'),'Manager controls');
 wp_set_current_user($client);ob_start();Chat::render();$ui=ob_get_clean();$check(!str_contains($ui,'name="assignee"')&&!str_contains($ui,'name="visibility"'),'No manager controls for customer');
 wp_set_current_user(0);$denied(fn()=>Store::read($id));
 $check(!get_post_type_object(Store::THREAD)->show_in_rest && !get_post_type_object(Store::MESSAGE)->publicly_queryable,'No public/REST records');
 echo "PASS: $checks conversation checks\n";
} finally {
 wp_set_current_user($original);
 foreach($threads as $id){foreach(get_posts(['post_type'=>Store::MESSAGE,'post_status'=>'private','post_parent'=>$id,'numberposts'=>-1,'fields'=>'ids']) as $mid)wp_delete_post($mid,true);wp_delete_post($id,true);}
 foreach($orders as $id){$o=wc_get_order($id);if($o)$o->delete(true);}
 foreach($users as $id)wp_delete_user($id);
 if($option===null)delete_option(Chat::OPTION);else update_option(Chat::OPTION,$option,false);
}
