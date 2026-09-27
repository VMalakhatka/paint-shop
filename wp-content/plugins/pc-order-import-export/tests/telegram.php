<?php
/** Local-only integration: every HTTP call and email intercepted; disposable identities and records. */
use PaintCore\PCOE\ConversationStore as Chat;
use PaintCore\PCOE\Conversations;
use PaintCore\PCOE\TelegramStore as Store;
use PaintCore\PCOE\TelegramSettings as Settings;
use PaintCore\PCOE\TelegramBridge as Bridge;
if(!defined('WP_CLI') || !WP_CLI || wp_parse_url(home_url(),PHP_URL_HOST)!=='paint.local')throw new RuntimeException('Local only');
add_filter('pre_wp_mail','__return_true',PHP_INT_MAX);
$original=get_current_user_id();$config=Settings::config();$enabled=get_option(Conversations::OPTION,null);$users=[];$threads=[];$checks=0;
$bot=(string)random_int(900000000,999999999);$peer='700000000001';$peer2='700000000002';$seq=100;$sent=200;$calls=[];$mode='ok';$foreign='';
$https=static fn($url)=>str_replace('http://paint.local','https://paint.local',$url);add_filter('home_url',$https);
$http=static function($pre,$args,$url)use(&$calls,&$mode,&$sent,&$foreign,$bot){
 if(!str_starts_with($url,'https://api.telegram.org/bot'))return new WP_Error('test','External HTTP blocked');
 $method=basename($url);$body=json_decode($args['body'],true);$calls[]=['method'=>$method,'body'=>$body];
 if($method==='sendMessage' && $mode==='timeout')return new WP_Error('timeout','Simulated uncertainty');
 if($method==='sendMessage' && $mode==='exception')throw new RuntimeException('Transport exception contains a URL that must not be exposed');
 if($method==='sendMessage' && in_array($mode,['429','403','500'],true))return ['response'=>['code'=>(int)$mode],'body'=>wp_json_encode(['ok'=>false,'parameters'=>['retry_after'=>40]])];
 $result=match($method){'getMe'=>['id'=>(int)$bot,'is_bot'=>true,'username'=>'LavkaTestOnlyBot'],'getWebhookInfo'=>['url'=>$foreign],'setWebhook'=>true,'sendMessage'=>['message_id'=>++$sent,'chat'=>['id'=>(int)$body['chat_id']]],default=>false};
 return ['response'=>['code'=>200],'body'=>wp_json_encode(['ok'=>true,'result'=>$result])];
};add_filter('pre_http_request',$http,PHP_INT_MAX,3);
$check=static function($ok,$why)use(&$checks){if(!$ok)throw new RuntimeException($why);$checks++;};
$denied=static function($fn)use($check){try{$fn();}catch(Throwable $e){$check(true,'Expected denial');return;}throw new RuntimeException('Expected denial');};
$incoming=static function($text,$chat=null,$extra=[], $number=null)use(&$seq,$peer){
 $id=$number??++$seq;$chat=$chat??$peer;
 return ['update_id'=>$id,'message'=>array_replace_recursive(['message_id'=>$id,'date'=>time()+1,'chat'=>['id'=>(int)$chat,'type'=>'private'],'from'=>['id'=>(int)$chat,'is_bot'=>false,'first_name'=>'Test person'],'text'=>$text],$extra)];
};
$send=static function($update,$secret=null){$r=new WP_REST_Request('POST','/pcoe/v1/telegram');$r->set_header('Content-Type','application/json');$r->set_header('X-Telegram-Bot-Api-Secret-Token',$secret??Settings::config()['secret']);$r->set_body(wp_json_encode($update));return Bridge::webhook($r);};
require_once ABSPATH.'wp-admin/includes/user.php';
try {
 foreach(['administrator','customer','customer','shop_manager'] as $i=>$role){$id=wp_insert_user(['user_login'=>'tg-test-'.wp_generate_uuid4(),'user_pass'=>wp_generate_password(30),'user_email'=>'tg-'.$bot.'-'.$i.'@example.invalid','role'=>$role]);if(is_wp_error($id))throw new RuntimeException('Fixture');$users[]=$id;}
 [$admin,$customer,$other,$manager]=$users;wp_set_current_user($admin);update_option(Conversations::OPTION,'yes',false);delete_option(Settings::OPTION);
 $token=$bot.':'.str_repeat('X',30);Settings::configure($token);
 $check(Settings::enabled() && Store::ready(),'Configuration activates verified bot and InnoDB storage');
 $check(!str_contains(wp_json_encode(Settings::config()),$token),'Token is encrypted at rest');
 $setup=array_values(array_filter($calls,fn($r)=>$r['method']==='setWebhook'))[0]['body'];
 $check($setup['drop_pending_updates']===false && $setup['allowed_updates']===['message','callback_query'] && strlen($setup['secret_token'])>=32,'Authenticated webhook retains pending updates');
 ob_start();Settings::admin();$ui=ob_get_clean();$check(str_contains($ui,'name="bot_token"') && !str_contains($ui,$token) && !str_contains($ui,$setup['secret_token']),'Admin setup never renders saved secrets');
 do_action('rest_api_init');$r=new WP_REST_Request('POST','/pcoe/v1/telegram');$check(rest_do_request($r)->get_status()===403,'Registered REST endpoint denies unauthenticated request');
 $foreign='https://example.invalid/webhook';$denied(fn()=>Settings::configure(''));$foreign='';
 wp_set_current_user($customer);$denied(fn()=>Settings::configure(''));
 $code=Store::issue($customer);$check(strlen($code)===48 && Store::get('code:'.hash('sha256',$code))!==null,'One-time deep link fits Telegram limit');
 $check(!str_contains(wp_json_encode(Store::link($customer)),$code),'Raw link token is not stored');
 $request=$incoming('/start '.$code);
 $check(is_wp_error($send($request,'wrong')),'Webhook secret rejected');
 $check($send($request)->get_status()===200 && Store::link($customer)['status']==='pending','Start only creates pending binding');
 ob_start();Settings::customer();$ui=ob_get_clean();$check(str_contains($ui,'name="generation"') && str_contains($ui,'Test person'),'Customer sees explicit identity confirmation');
 $check(Store::active($customer)===null,'No transport before website confirmation');
 $before=count(Store::records('job',['pending'],100));$send($request);$check(count(Store::records('job',['pending'],100))===$before,'Duplicate webhook creates no extra response');
 $check(!Store::pair($code,$peer2,'Intruder'),'Deep link cannot be reused');
 wp_set_current_user($other);$denied(fn()=>Store::confirm($customer,Store::link($customer)['data']['generation']));
 wp_set_current_user($customer);Store::confirm($customer,Store::link($customer)['data']['generation']);$check(Store::active($customer)!==null,'Website confirmation activates binding');
 $denied(fn()=>Store::issue($other));
 wp_set_current_user($other);$code2=Store::issue($other);
 $check(!Store::pair($code2,$peer,'Other'),'One Telegram peer cannot claim another website account');
 wp_set_current_user($customer);
 $a=Chat::start($customer,0,'Delivery','Site question',wp_generate_uuid4());$b=Chat::start($customer,0,'Other topic','Separate topic',wp_generate_uuid4());$threads=[$a,$b];
 $update=$incoming('Telegram general question');$check($send($update)->get_status()===200,'Telegram incoming accepted');$general=Store::link($customer)['data']['general'];$threads[]=$general;
 $check($general!==$a && count(Chat::messages($general)['messages'])===1,'Unscoped text uses general conversation, not last order');
 $send($update);$check(count(Chat::messages($general)['messages'])===1,'Duplicate incoming message is idempotent');
 $send($incoming('Out of order',null,[],50));$check(count(Chat::messages($general)['messages'])===2,'Out-of-order update is retained');
 $mid=Chat::latest($general);$check(get_post_meta($mid,'_chat_channel',true)==='telegram' && Chat::meta($general)['state']==='waiting_manager','Incoming message marks source and queues manager reply');
 $broken=$incoming('Retry after transactional failure');$before=count(Chat::messages($general)['messages']);
 $fail=static function(){throw new RuntimeException('Simulated transaction failure');};add_action('pcoe_chat_message_saved',$fail,20);
 $check(is_wp_error($send($broken)) && Store::get('update:'.$bot.':'.$broken['update_id'])===null,'Failed webhook rolls back dedupe record');
 remove_action('pcoe_chat_message_saved',$fail,20);$send($broken);$check(count(Chat::messages($general)['messages'])===$before+1,'Telegram retries once after rollback without losing message');
 $count=count(Chat::messages($general)['messages']);$send($incoming('Group',null,['chat'=>['type'=>'group']]));$send($incoming('Spoof',null,['from'=>['id'=>123]]));$send($incoming('Old',null,['date'=>1]));
 $check(count(Chat::messages($general)['messages'])===$count,'Groups, sender mismatch and stale messages are ignored');
 $send($incoming('Unknown reply',null,['reply_to_message'=>['message_id'=>123]]));$check(count(Chat::messages($general)['messages'])===$count,'Unknown reply never lands in arbitrary conversation');
 $callback=['update_id'=>++$seq,'callback_query'=>['id'=>'test-callback','from'=>['id'=>(int)$peer],'message'=>['chat'=>['id'=>(int)$peer,'type'=>'private']],'data'=>'thread:'.$a]];
 $check($send($callback)->get_data()['method']==='answerCallbackQuery','Telegram selection callback acknowledged');$send($incoming('Selected topic'));
 $check(count(Chat::messages($a)['messages'])===2 && count(Chat::messages($b)['messages'])===1,'Selected conversation receives message');
 wp_set_current_user($other);$foreign=Chat::start($other,0,'Private','Other customer text',wp_generate_uuid4());$threads[]=$foreign;
 $callback['update_id']=++$seq;$callback['callback_query']['data']='thread:'.$foreign;$send($callback);$check(Store::link($customer)['data']['selected']===$a,'Foreign conversation selection rejected');
 $foreign='';wp_set_current_user($manager);$key=wp_generate_uuid4();$reply=Chat::reply($a,'Manager public reply',false,$key);$job='message:'.$reply.':0';
 $check(Store::get($job)['status']==='pending','Manager reply atomically creates outbox');Chat::reply($a,'Manager public reply',false,$key);
 $check(Store::get($job)!==null,'Retry reuses same outbox record');
 $internal=Chat::reply($a,'Internal only',true,wp_generate_uuid4());$check(Store::get('message:'.$internal.':0')===null,'Internal notes never enqueue');
 $before=count(Chat::messages($a)['messages']);$brokenMessage=0;
 $fail=static function($thread,$message)use(&$brokenMessage){$brokenMessage=$message;throw new RuntimeException('Simulated transaction failure');};add_action('pcoe_chat_message_saved',$fail,20,2);
 $denied(fn()=>Chat::reply($a,'Rollback outbox too',false,wp_generate_uuid4()));remove_action('pcoe_chat_message_saved',$fail,20);
 $check(count(Chat::messages($a)['messages'])===$before && Store::get('message:'.$brokenMessage.':0')===null,'Canonical message and outbound job roll back together');
 Bridge::deliver($job);$check(Store::get($job)['status']==='sent','Successful transport records Telegram acceptance');
 $transportId=$sent;$callCount=count($calls);Bridge::deliver($job);$check(count($calls)===$callCount,'Already sent reply never sends again');
 $send($incoming('Reply to manager',null,['reply_to_message'=>['message_id'=>$transportId]]));
 wp_set_current_user($customer);$check(get_post(Chat::latest($a))->post_content==='Reply to manager','Telegram reply maps back to exact conversation');
 wp_set_current_user($manager);$long=Chat::reply($a,str_repeat('🙂',4100),false,wp_generate_uuid4());
 $check(Store::get('message:'.$long.':2')!==null && mb_strlen(Store::get('message:'.$long.':0')['data']['payload']['text'],'UTF-8')<2048,'Long emoji messages split below Telegram UTF-16 limit');
 $unknown=Chat::reply($a,'Timeout test',false,wp_generate_uuid4());$mode='timeout';Bridge::deliver('message:'.$unknown.':0');
 $check(Store::get('message:'.$unknown.':0')['status']==='unknown','Timeout is unknown, not success');$callCount=count($calls);Bridge::deliver('message:'.$unknown.':0');$check(count($calls)===$callCount,'Unknown outcome is not auto-retried');
 $exception=Chat::reply($a,'Transport exception',false,wp_generate_uuid4());$mode='exception';Bridge::deliver('message:'.$exception.':0');$check(Store::get('message:'.$exception.':0')['status']==='unknown','Transport exception is contained without provider details');
 $failed=Chat::reply($a,'Blocked test',false,wp_generate_uuid4());$mode='403';Bridge::deliver('message:'.$failed.':0');$check(Store::get('message:'.$failed.':0')['status']==='failed','Blocked bot recorded as failed');
 $rate=Chat::reply($a,'Rate limit test',false,wp_generate_uuid4());$mode='429';Bridge::deliver('message:'.$rate.':0');$j=Store::get('message:'.$rate.':0');$check($j['status']==='pending' && $j['data']['not_before']>time(),'429 honors retry delay');$mode='ok';
 $claim=Chat::reply($a,'Interrupted worker',false,wp_generate_uuid4());$j=Store::get('message:'.$claim.':0');Store::put($j['record_key'],'job',$customer,'sending',$j['data'],$claim);
 global $wpdb;$wpdb->update(Store::table(),['updated_at'=>gmdate('Y-m-d H:i:s',time()-180)],['record_key'=>$j['record_key']]);Bridge::deliver($j['record_key']);$check(Store::get($j['record_key'])['status']==='unknown','Interrupted sending recovered as unknown');
 $pending=Chat::reply($a,'Do not deliver after unlink',false,wp_generate_uuid4());
 wp_set_current_user($customer);Store::gate(fn()=>Store::atomic(fn()=>Store::revoke($customer)));Bridge::deliver('message:'.$pending.':0');$check(Store::get('message:'.$pending.':0')['status']==='cancelled','Disconnect cancels queued content');
 $code=Store::issue($customer);$send($incoming('/start '.$code));Store::confirm($customer,Store::link($customer)['data']['generation']);$send($incoming('Old reply after relink',null,['reply_to_message'=>['message_id'=>$transportId]]));$check(get_post(Chat::latest($a))->post_content!=='Old reply after relink','Old binding reply map cannot survive reconnect');
 $send($incoming('/stop'));$check(Store::active($customer)===null,'Bot stop revokes connection');
 $pause=Settings::config();$pause['active']=false;update_option(Settings::OPTION,$pause,false);$result=$send($incoming('Paused'));$check(is_wp_error($result) && $result->get_error_data()['status']===503,'Paused webhook requests retry instead of discarding messages');$pause['active']=true;update_option(Settings::OPTION,$pause,false);
 $code=Store::issue($customer);$r=Store::get('code:'.hash('sha256',$code));$r['data']['expires']=time()-1;Store::put($r['record_key'],'code',$customer,'issued',$r['data']);$check(!Store::pair($code,$peer,'Test'),'Expired code refused');
 $c=Settings::config();$c['site']='https://cloned.invalid/';update_option(Settings::OPTION,$c,false);$check(!Settings::enabled(),'Cloned database cannot enable transport on another site');
 echo "PASS: $checks Telegram checks; all HTTP/mail intercepted\n";
} finally {
 wp_set_current_user($original);
 foreach($threads as $id){foreach(get_posts(['post_type'=>Chat::MESSAGE,'post_status'=>'private','post_parent'=>$id,'numberposts'=>-1,'fields'=>'ids']) as $mid)wp_delete_post($mid,true);wp_delete_post($id,true);}
 global $wpdb;
 if(Store::ready()) {
  foreach($users as $id)$wpdb->delete(Store::table(),['user_id'=>$id]);
  $wpdb->query($wpdb->prepare('DELETE FROM '.Store::table().' WHERE record_key LIKE %s OR record_key LIKE %s OR record_key LIKE %s','update:'.$bot.':%','service:'.$bot.':%','peer:'.$bot.':%'));
 }
 foreach($users as $id)wp_delete_user($id);
 update_option(Settings::OPTION,$config,false);if($enabled===null)delete_option(Conversations::OPTION);else update_option(Conversations::OPTION,$enabled,false);
 wp_clear_scheduled_hook(Bridge::HOOK);remove_filter('home_url',$https);remove_filter('pre_http_request',$http,PHP_INT_MAX);
}
