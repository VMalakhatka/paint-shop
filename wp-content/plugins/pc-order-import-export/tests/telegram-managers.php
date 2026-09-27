<?php
/** Included by telegram.php: reuses isolated fixtures and its blocked HTTP/mail transport. */
use PaintCore\PCOE\ConversationStore as Chat;
use PaintCore\PCOE\Conversations;
use PaintCore\PCOE\TelegramStore as Store;
use PaintCore\PCOE\TelegramSettings as Settings;
use PaintCore\PCOE\TelegramBridge as Bridge;
if (!defined('WP_CLI') || !WP_CLI || !isset($check,$incoming,$users) || wp_parse_url(home_url(),PHP_URL_HOST)!=='paint.local') throw new RuntimeException('Run through local telegram.php');
$managerPeer='700000000003';$adminPeer='700000000004';
$bind=static function($user,$chat)use($send,$incoming,$check){
 wp_set_current_user($user);$code=Store::issue($user);$send($incoming('/start '.$code,$chat));
 $check(Store::link($user)['status']==='pending' && Store::active($user)===null,'Every audience must confirm in its own website session');
 Store::confirm($user,Store::link($user)['data']['generation']);
};
$bind($customer,$peer);$bind($manager,$managerPeer);$bind($admin,$adminPeer);
$check(Store::active($manager)['data']['audience']==='manager','Manager binding stores explicit audience');
wp_set_current_user($manager);$denied(fn()=>Store::issue($admin));
ob_start();Settings::customer();$ui=ob_get_clean();$check(str_contains($ui,'/queue') && !str_contains($ui,'name="bot_token"'),'Manager connection UI is separate from administrator bot setup');
ob_start();Settings::admin();$check(ob_get_clean()==='','Shop manager cannot change global bot credentials');
wp_set_current_user($other);$collision=Store::issue($other);$check(!Store::pair($collision,$managerPeer,'Other'),'A client cannot link a manager Telegram peer');

wp_set_current_user($customer);
$order=wc_create_order(['customer_id'=>$customer]);if(is_wp_error($order))throw new RuntimeException('Order fixture');
try {
 $u=Chat::start($customer,$order->get_id(),'Manager transport',str_repeat('🙂',1600),wp_generate_uuid4());$threads[]=$u;$first=Chat::latest($u);
 $managerJob='manager-message:'.$first.':'.$manager.':0';$adminJob='manager-message:'.$first.':'.$admin.':0';
 $check(Store::get($managerJob)['status']==='pending' && Store::get($adminJob)['status']==='pending','Unassigned public message fans out to connected managers');
 $payload=Store::get($managerJob)['data']['payload'];
 $check(str_contains($payload['text'],get_userdata($customer)->display_name) && str_contains($payload['text'],(string)$order->get_order_number()),'Manager notification identifies customer and Woo order');
 $check(strlen(mb_convert_encoding($payload['text'],'UTF-16LE','UTF-8'))/2<=4096 && isset($payload['reply_markup']['inline_keyboard'][0][0]['callback_data']),'Notification fits Telegram limit and has claim button');
 $check(Store::get('message:'.$first.':0')===null,'Customer website message is not echoed to its own Telegram');
 $callbackFor=static function($chat,$message,$thread)use(&$seq){return ['update_id'=>++$seq,'callback_query'=>['id'=>'manager-test-'.$seq,'from'=>['id'=>(int)$chat], 'message'=>['message_id'=>$message,'chat'=>['id'=>(int)$chat,'type'=>'private']], 'data'=>'claim:'.$thread]];};
 $send($callbackFor($managerPeer,123,$u));$check(Chat::meta($u)['assignee']===0,'Forged claim without a delivered message mapping cannot assign');
 Bridge::deliver($managerJob);$managerMessage=$sent;Bridge::deliver($adminJob);$adminMessage=$sent;
 $check(Store::get($managerJob)['status']==='sent','Manager notification can be delivered');
 $before=count(Chat::messages($u)['messages']);
 $send($incoming('Not claimed',$managerPeer,['reply_to_message'=>['message_id'=>$managerMessage]]));
 $check(count(Chat::messages($u)['messages'])===$before,'Reply to an unassigned notification requires explicit claim');
 $claimUpdate=$callbackFor($managerPeer,$managerMessage,$u);$check($send($claimUpdate)->get_status()===200,'Manager claim callback is accepted');
 $check(Chat::meta($u)['assignee']===$manager,'Take request assigns current manager');
 wp_set_current_user($manager);$before=count(Chat::messages($u)['messages']);$send($claimUpdate);
 $check(count(Chat::messages($u)['messages'])===$before,'Duplicate claim callback does not duplicate assignment audit');
 $send($callbackFor($adminPeer,$adminMessage,$u));$check(Chat::meta($u)['assignee']===$manager,'Second manager cannot steal a claimed request');
 Bridge::deliver('manager-message:'.$first.':'.$admin.':1');$check(Store::get('manager-message:'.$first.':'.$admin.':1')['status']==='cancelled','Queued unassigned notification cancels after claim');
 $send($incoming('Wrong manager reply',$adminPeer,['reply_to_message'=>['message_id'=>$adminMessage]]));
 $check(count(Chat::messages($u)['messages'])===$before,'Other manager cannot reply through its old queue message');
 $send($incoming('Unscoped manager text',$managerPeer));$check(count(Chat::messages($u)['messages'])===$before,'Unscoped manager text never guesses a customer');
 $replyUpdate=$incoming('Manager answer through Telegram',$managerPeer,['reply_to_message'=>['message_id'=>$managerMessage]]);
 $check($send($replyUpdate)->get_status()===200,'Manager reply accepted');$replyId=Chat::latest($u);
 $check((int)get_post($replyId)->post_author===$manager && get_post_meta($replyId,'_chat_actor',true)==='manager' && get_post_meta($replyId,'_chat_channel',true)==='telegram','Canonical reply retains manager identity and Telegram source');
 $check(get_post_meta($replyId,'_chat_visibility',true)==='public' && Chat::meta($u)['state']==='waiting_customer','Manager reply is public and updates queue state');
 $check(Store::get('message:'.$replyId.':0')['status']==='pending','Manager Telegram reply enters customer outbox');
 $before=count(Chat::messages($u)['messages']);$send($replyUpdate);$check(count(Chat::messages($u)['messages'])===$before,'Duplicate manager webhook creates one reply');
 Bridge::deliver('message:'.$replyId.':0');$check(Store::get('message:'.$replyId.':0')['status']==='sent','Manager reply reaches customer transport');
 $pendingKeys=Store::records('job',['pending'],1000);$note=Chat::reply($u,'Private staff note',true,wp_generate_uuid4());
 $check(Store::records('job',['pending'],1000)===$pendingKeys,'Internal note does not notify either audience');
 $noReply=Chat::latest($u);$send($incoming('Unknown reply',$managerPeer,['reply_to_message'=>['message_id'=>987654]]));$check(Chat::latest($u)===$noReply,'Unknown reply reference never routes manager text');
 // A transaction failure rolls back manager canonical text, customer outbox and dedupe together.
 $failing=$incoming('Transactional manager reply',$managerPeer,['reply_to_message'=>['message_id'=>$managerMessage]]);
 $fail=static function(){throw new RuntimeException('Manager rollback test');};add_action('pcoe_chat_message_saved',$fail,20);
 $check(is_wp_error($send($failing)) && Store::get('update:'.$bot.':'.$failing['update_id'])===null,'Manager failure leaves update retryable');
 remove_action('pcoe_chat_message_saved',$fail,20);$send($failing);$check(get_post(Chat::latest($u))->post_content==='Transactional manager reply','Manager retry preserves exactly one canonical reply');
 wp_set_current_user($customer);$incomingSite=Chat::reply($u,'New customer message',false,wp_generate_uuid4());
 $check(Store::get('manager-message:'.$incomingSite.':'.$manager.':0')!==null && Store::get('manager-message:'.$incomingSite.':'.$admin.':0')===null,'Assigned customer messages go only to responsible manager');
 wp_set_current_user($admin);$meta=Chat::meta($u);Chat::assign($u,$admin,$meta['state'],$meta['revision']);
 $assignment='assignment:'.$u.':'.Chat::meta($u)['revision'].':'.$admin.':0';
 $check(Store::get($assignment)!==null && !str_contains(Store::get($assignment)['data']['payload']['text'],'Private staff note'),'Reassignment gives new owner public context, never internal notes');
 Bridge::deliver('manager-message:'.$incomingSite.':'.$manager.':0');$check(Store::get('manager-message:'.$incomingSite.':'.$manager.':0')['status']==='cancelled','Reassignment cancels previous manager pending content');
 $before=count(Chat::messages($u)['messages']);$send($incoming('Stale owner',$managerPeer,['reply_to_message'=>['message_id'=>$managerMessage]]));$check(count(Chat::messages($u)['messages'])===$before,'Previous manager cannot reply after reassignment');
 Bridge::deliver($assignment);$assignedMessage=$sent;$check(Store::get($assignment)['status']==='sent','New assignee context delivered');
 $send($incoming('New owner answer',$adminPeer,['reply_to_message'=>['message_id'=>$assignedMessage]]));$check(get_post(Chat::latest($u))->post_content==='New owner answer','New assignee can reply using assignment context');
 $meta=Chat::meta($u);Chat::assign($u,$admin,'closed',$meta['revision']);$before=count(Chat::messages($u)['messages']);
 $send($incoming('Closed reply',$adminPeer,['reply_to_message'=>['message_id'=>$assignedMessage]]));$check(count(Chat::messages($u)['messages'])===$before,'Manager cannot reopen closed request accidentally through stale Telegram reply');
 wp_set_current_user($customer);$reopened=Chat::reply($u,'Customer reopens',false,wp_generate_uuid4());$check(Store::get('manager-message:'.$reopened.':'.$admin.':0')!==null,'Customer reopen notifies responsible manager');
 wp_set_current_user($admin);$meta=Chat::meta($u);Chat::assign($u,0,'waiting_manager',$meta['revision']);
 $unassigned='assignment:'.$u.':'.Chat::meta($u)['revision'];$check(Store::get($unassigned.':'.$manager.':0')!==null && Store::get($unassigned.':'.$admin.':0')!==null,'Removing assignee returns public context to connected manager queue');
 // Commands cannot disclose conversations belonging to another assignee.
 $command=$incoming('/queue',$managerPeer);$send($command);$list='manager-list:update:'.$bot.':'.$command['update_id'].':'.$u.':'.$manager.':0';
 $check(Store::get($list)!==null,'Queue command lists unassigned requests');
 $meta=Chat::meta($u);Chat::assign($u,$admin,'waiting_manager',$meta['revision']);Bridge::deliver($list);$check(Store::get($list)['status']==='cancelled','Queued list card rechecks assignment before transport');
 $command=$incoming('/threads',$managerPeer);$send($command);$check(Store::get('manager-list:update:'.$bot.':'.$command['update_id'].':'.$u.':'.$manager.':0')===null,'My conversations excludes another manager requests');
 $command=$incoming('/threads',$adminPeer);$send($command);$check(Store::get('manager-list:update:'.$bot.':'.$command['update_id'].':'.$u.':'.$admin.':0')!==null,'My conversations includes assigned request');
 // Link generations and live capabilities apply to all manager inputs and deliveries.
 wp_set_current_user($manager);$send($incoming('/stop',$managerPeer));$check(Store::active($manager)===null,'Manager stop disconnects only its own channel');
 $bind($manager,$managerPeer);$before=count(Chat::messages($u)['messages']);$send($callbackFor($managerPeer,$managerMessage,$u));$check(count(Chat::messages($u)['messages'])===$before,'Reconnected manager cannot reuse old-generation callback');
 wp_set_current_user($admin);$meta=Chat::meta($u);Chat::assign($u,$manager,'waiting_manager',$meta['revision']);
 $downgradeJob='assignment:'.$u.':'.Chat::meta($u)['revision'].':'.$manager.':0';
 (new WP_User($manager))->set_role('customer');$check(Store::active($manager)===null,'Capability removal immediately invalidates manager binding');
 Bridge::deliver($downgradeJob);$check(Store::get($downgradeJob)['status']==='cancelled','Role removal cancels pending manager delivery');
 $before=count(Chat::messages($u)['messages']);$send($incoming('Revoked manager',$managerPeer,['reply_to_message'=>['message_id'=>$managerMessage]]));$check(count(Chat::messages($u)['messages'])===$before,'Revoked manager webhook cannot write client data');
 (new WP_User($manager))->set_role('shop_manager');
 (new WP_User($customer))->add_role('shop_manager');$check(Store::active($customer)===null,'Promoting customer never upgrades existing bot binding to staff access');
 (new WP_User($customer))->remove_role('shop_manager');
 $legacy=Store::link($customer);unset($legacy['data']['audience']);Store::put('link:'.$customer,'link',$customer,'active',$legacy['data']);$check(Store::active($customer)!==null,'Legacy customer bindings remain compatible');
 (new WP_User($customer))->add_role('shop_manager');$check(Store::active($customer)===null,'Legacy binding also fails closed after privilege change');(new WP_User($customer))->remove_role('shop_manager');
} finally { $order->delete(true); }
wp_set_current_user($customer);
