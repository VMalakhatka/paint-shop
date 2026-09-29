<?php
namespace PaintCore\PCOE;
defined('ABSPATH') || exit;

/** Telegram is a transport for ConversationStore, never an order/financial command interface. */
final class TelegramBridge {
    const HOOK='pcoe_telegram_deliver';
    public static function hooks(): void {
        add_action('admin_post_pcoe_telegram',[TelegramSettings::class,'post']);
        add_action('rest_api_init',static function(){register_rest_route('pcoe/v1','/telegram',[
            'methods'=>'POST','callback'=>[self::class,'webhook'],'permission_callback'=>[self::class,'authorize']]);});
        add_action('pcoe_chat_message_saved',[self::class,'enqueue'],10,3);
        add_action('pcoe_chat_assignment_saved',[TelegramManagers::class,'assigned'],10,2);
        add_filter('cron_schedules',static function($items){$items['pcoe_minute']=['interval'=>60,'display'=>'PCOE minute'];return $items;});
        add_action('init',[self::class,'schedule']);add_action(self::HOOK,[self::class,'run']);
    }
    public static function schedule(): void {
        if(TelegramSettings::enabled() && !wp_next_scheduled(self::HOOK))wp_schedule_event(time()+5,'pcoe_minute',self::HOOK);
        elseif(!TelegramSettings::enabled() && wp_next_scheduled(self::HOOK))wp_clear_scheduled_hook(self::HOOK);
    }
    public static function authorize(\WP_REST_Request $request) {
        $c=TelegramSettings::config();$secret=(string)$request->get_header('x-telegram-bot-api-secret-token');
        if(empty($c['secret']) || $secret==='' || !hash_equals($c['secret'],$secret))return new \WP_Error('forbidden','Forbidden',['status'=>403]);
        if(!TelegramSettings::enabled())return new \WP_Error('paused','Paused',['status'=>503]);
        return true;
    }
    public static function webhook(\WP_REST_Request $request) {
        $auth=self::authorize($request);if(is_wp_error($auth))return $auth;
        if(strlen($request->get_body())>65536)return new \WP_Error('size','Too large',['status'=>413]);
        $update=$request->get_json_params();if(!is_array($update) || !isset($update['update_id']) || !is_int($update['update_id']) || $update['update_id']<0)return new \WP_Error('invalid','Invalid update',['status'=>400]);
        try {
            self::receive($update);
            $answer=isset($update['callback_query']['id'])?['method'=>'answerCallbackQuery','callback_query_id'=>(string)$update['callback_query']['id']]:['ok'=>true];
            return new \WP_REST_Response($answer,200);
        }
        catch(\Throwable $e){return new \WP_Error('retry','Please retry',['status'=>503]);}
    }
    private static function uuid(string $key): string {
        $h=hash('sha256',$key);return substr($h,0,8).'-'.substr($h,8,4).'-'.substr($h,12,4).'-'.substr($h,16,4).'-'.substr($h,20,12);
    }
    public static function receive(array $update): void {
        $callback=$update['callback_query']??null;$m=$callback['message']??($update['message']??null);$from=$callback['from']??($m['from']??[]);
        if(!is_array($m) || ($m['chat']['type']??'')!=='private' || !empty($from['is_bot']))return;
        $chat=(string)($m['chat']['id']??'');
        if(!preg_match('/^[1-9][0-9]{0,15}$/D',$chat) || (string)($from['id']??'')!==$chat)return;
        $bot=TelegramSettings::config()['bot'];$key='update:'.$bot.':'.$update['update_id'];
        TelegramStore::gate(static fn()=>TelegramStore::atomic(static function()use($update,$callback,$m,$from,$chat,$bot,$key){
            if(!TelegramSettings::enabled())throw new \RuntimeException('Paused');
            if(TelegramStore::get($key))return;
            // This record and the canonical message commit together, even for out-of-order updates.
            TelegramStore::put($key,'update',0,'received',[]);
            $text=is_string($m['text']??null)?trim(sanitize_textarea_field($m['text'])):'';
            if(!$callback && preg_match('/^\/start\s+([a-f0-9]{48})$/D',$text,$match)) {
                $ok=TelegramStore::pair($match[1],$chat,(string)($from['first_name']??'').' '.(string)($from['last_name']??''));
                $record=$ok?TelegramStore::get('code:'.hash('sha256',$match[1])):null;
                $manager=$record && TelegramStore::audience((int)$record['user_id'])==='manager';
                self::service($key,$chat,$ok?__('Return to Messages on the website, refresh the page and confirm your Telegram account.','pc-order-import-export'):__('This link is expired or unavailable. Create a new link in your website account.','pc-order-import-export'),null,0,
                    ['inline_keyboard'=>[[['text'=>__('Open on website','pc-order-import-export'),'url'=>Conversations::url([],$manager)]]]]);return;
            }
            $peer=TelegramStore::get(TelegramStore::peer($bot,$chat));$user=(int)($peer['user_id']??0);$link=$user?TelegramStore::active($user):null;
            if(!$peer || $peer['status']!=='active' || !$link || ($link['data']['chat']??'')!==$chat || ($link['data']['generation']??'')!==($peer['data']['generation']??'')) {
                self::service($key,$chat,__('Connect Telegram from Messages in your website account first.','pc-order-import-export'));return;
            }
            if(!$callback && (int)($m['date']??0)<(int)$link['data']['linked_at'])return;
            $original=get_current_user_id();wp_set_current_user($user);switch_to_locale(get_user_locale($user));
            try {
                if(!$callback && $text==='/stop') { TelegramStore::revoke($user);self::service($key,$chat,__('Telegram disconnected. Your conversation history remains on the website.','pc-order-import-export'));return; }
                if(($link['data']['audience']??'customer')==='manager') {
                    TelegramManagers::receive($key,$text,$m,$callback,$link,self::uuid($key));return;
                }
                if($callback) {
                    $value=(string)($callback['data']??'');
                    if(!preg_match('/^thread:([0-9]+)$/D',$value,$match))return;
                    $thread=(int)$match[1];
                    if($thread) { try { ConversationStore::read($thread); } catch(\Throwable $e){return;} }
                    $d=$link['data'];$d['selected']=$thread;$d['selection_expires']=time()+1800;
                    TelegramStore::put('link:'.$user,'link',$user,'active',$d);
                    self::service($key,$chat,$thread?sprintf(__('Write your message for conversation #%d. Selection is valid for 30 minutes.','pc-order-import-export'),$thread):__('Write your general question to the manager.','pc-order-import-export'),$link,$thread);return;
                }
                if(in_array($text,['/start','/help','/threads','/general'],true)) {
                    if($text==='/general') { $d=$link['data'];unset($d['selected'],$d['selection_expires']);TelegramStore::put('link:'.$user,'link',$user,'active',$d); }
                    $rows=[[['text'=>__('General question','pc-order-import-export'),'callback_data'=>'thread:0']]];
                    foreach(array_slice(ConversationStore::listing('',1)->posts,0,10) as $thread)$rows[]=[['text'=>'#'.$thread->ID.' '.mb_substr($thread->post_title,0,45),'callback_data'=>'thread:'.$thread->ID]];
                    self::service($key,$chat,__('Choose a conversation, reply to a bot message, or write a general question. /stop disconnects Telegram.','pc-order-import-export'),$link,0,['inline_keyboard'=>$rows]);return;
                }
                if($text==='' || str_starts_with($text,'/') || mb_strlen($text)>5000) { self::service($key,$chat,__('Please send text only, up to 5000 characters. Use /threads to choose a conversation. Files and voice messages are not supported yet.','pc-order-import-export'),$link);return; }
                $thread=0;
                if(isset($m['reply_to_message']['message_id'])) {
                    $map=TelegramStore::get('sent:'.$bot.':'.$chat.':'.(string)$m['reply_to_message']['message_id']);
                    if(!$map || (int)$map['user_id']!==$user || ($map['data']['generation']??'')!==$link['data']['generation']) { self::service($key,$chat,__('This reply could not be linked. Use /threads to select the conversation and send it again.','pc-order-import-export'),$link);return; }
                    $thread=(int)$map['reference_id'];
                } elseif(($link['data']['selection_expires']??0)>=time())$thread=(int)($link['data']['selected']??0);
                if($thread) {
                    try { ConversationStore::read($thread); }
                    catch(\Throwable $e) { self::service($key,$chat,__('This reply could not be linked. Use /threads to select the conversation and send it again.','pc-order-import-export'),$link);return; }
                }
                $request=self::uuid($key);$message=0;
                if(!$thread) {
                    $thread=(int)($link['data']['general']??0);
                    if($thread) { try { ConversationStore::read($thread); } catch(\Throwable $e){$thread=0;} }
                    if(!$thread) {
                        $thread=ConversationStore::start($user,0,__('General question from Telegram','pc-order-import-export'),$text,$request);
                        $d=$link['data'];$d['general']=$thread;TelegramStore::put('link:'.$user,'link',$user,'active',$d);
                        $message=ConversationStore::latest($thread);
                    }
                }
                if(!$message)$message=ConversationStore::reply($thread,$text,false,$request);
                if(!update_post_meta($message,'_chat_channel','telegram'))throw new \RuntimeException('Channel metadata unavailable');
                self::service($key,$chat,sprintf(__('Message saved in conversation #%d. The manager will reply here and on the website.','pc-order-import-export'),$thread),$link,$thread);
            } finally { restore_previous_locale();wp_set_current_user($original); }
        }));
    }
    public static function job(string $key,string $chat,array $payload,?array $link,int $thread=0,int $message=0,string $scope='assigned',array $context=[]): void {
        if(TelegramStore::get($key))return;
        TelegramStore::put($key,'job',(int)($link['user_id']??0),'pending',[
            'bot'=>TelegramSettings::config()['bot'],'chat'=>$chat,'generation'=>$link['data']['generation']??'',
            'audience'=>$link['data']['audience']??'customer','scope'=>$scope,'context'=>$context,
            'payload'=>array_merge(['chat_id'=>$chat,'link_preview_options'=>['is_disabled'=>true]],$payload),
            'thread'=>$thread,'attempts'=>0,'not_before'=>0],$message);
    }
    public static function service(string $key,string $chat,string $text,?array $link=null,int $thread=0,array $markup=[]): void {
        if(!$markup)$markup=['inline_keyboard'=>[[['text'=>__('Open on website','pc-order-import-export'),'url'=>Conversations::url($thread?['chat_id'=>$thread]:[],($link['data']['audience']??'customer')==='manager')]]]];
        $job=$link?'response:'.$key:'service:'.TelegramSettings::config()['bot'].':'.$chat.':'.intdiv(time(),60).':'.substr(hash('sha256',$text),0,8);
        self::job($job,$chat,['text'=>$text,'reply_markup'=>$markup],$link,$thread);
    }
    /** Called within the message transaction; private notes never enter either outbox. */
    public static function enqueue(int $thread,int $message,bool $internal): void {
        if($internal || !TelegramSettings::enabled())return;
        TelegramManagers::enqueue($thread,$message);
        if(get_post_meta($message,'_chat_actor',true)!=='manager')return;
        $post=get_post($thread);$link=TelegramStore::active((int)$post->post_author);if(!$link || ($link['data']['audience']??'customer')!=='customer')return;
        switch_to_locale(get_user_locale((int)$post->post_author));
        try {
            $text=get_post($message)->post_content;$parts=max(1,(int)ceil(mb_strlen($text)/1800));
            for($i=0;$i<$parts;$i++) {
                $body='#'.$thread.' · '.$post->post_title.($parts>1?' ('.($i+1).'/'.$parts.')':'')."\n\n".mb_substr($text,$i*1800,1800);
                self::job('message:'.$message.':'.$i,$link['data']['chat'],['text'=>$body,'reply_markup'=>['inline_keyboard'=>[[['text'=>__('Open on website','pc-order-import-export'),'url'=>Conversations::url(['chat_id'=>$thread],false)]]]]],$link,$thread,$message);
            }
        } finally { restore_previous_locale(); }
    }
    public static function run(): void {
        if(!TelegramSettings::enabled())return;
        $until=microtime(true)+15;
        foreach(TelegramStore::records('job',['pending','sending'],20) as $key) {
            try { self::deliver($key); } catch(\Throwable $e) { break; }
            if(microtime(true)>$until)break;
        }
    }
    public static function deliver(string $key): void {
        TelegramStore::gate(static function()use($key){
            if(!TelegramSettings::enabled())return;
            $job=TelegramStore::get($key);if(!$job || $job['kind']!=='job')return;
            $d=$job['data'];$user=(int)$job['user_id'];$reference=(int)$job['reference_id'];
            if($job['status']==='sending') {
                if(strtotime($job['updated_at'].' UTC')<time()-120)TelegramStore::put($key,'job',$user,'unknown',$d,$reference);
                return;
            }
            if($job['status']!=='pending' || $d['not_before']>time())return;
            $link=$user?TelegramStore::active($user):null;
            $manager=($d['audience']??'customer')==='manager';
            $valid=$d['bot']===TelegramSettings::config()['bot'] && (!$user || ($link && $link['data']['chat']===$d['chat'] && $link['data']['generation']===$d['generation'] && ($link['data']['audience']??'customer')===($d['audience']??'customer')));
            if(($d['scope']??'')==='approval')$valid=$valid && $manager && ApprovalNotifications::telegram_allowed($user,(array)($d['context']??[]));
            if($user && !empty($d['thread'])) {
                $conversation=get_post($d['thread']);
                $valid=$valid && $conversation && $conversation->post_type===ConversationStore::THREAD && $conversation->post_status==='private' && ($manager?TelegramManagers::allowed((int)$d['thread'],$user,$d['scope']??'assigned'):(int)$conversation->post_author===$user);
            }
            if($reference) {
                $m=get_post($reference);$thread=$m?get_post($m->post_parent):null;
                $valid=$valid && $m && $thread && $m->post_type===ConversationStore::MESSAGE && $m->post_status==='private' && $thread->post_type===ConversationStore::THREAD && $m->post_parent===(int)$d['thread'] && get_post_meta($reference,'_chat_visibility',true)==='public' && ($manager || ((int)$thread->post_author===$user && get_post_meta($reference,'_chat_actor',true)==='manager'));
            }
            if(!$valid) { TelegramStore::put($key,'job',$user,'cancelled',$d,$reference);return; }
            $d['attempts']++;
            // Claim is committed before HTTP. A crash or uncertain transport never causes a blind resend.
            TelegramStore::atomic(static fn()=>TelegramStore::put($key,'job',$user,'sending',$d,$reference));
            $response=TelegramSettings::api('sendMessage',$d['payload']);$status='unknown';
            if(!empty($response['ok']) && !empty($response['result']['message_id']) && (string)($response['result']['chat']['id']??'')===$d['chat'])$status='sent';
            elseif($response['code']===429 && $d['attempts']<4) { $status='pending';$d['not_before']=time()+$response['retry']; }
            elseif($response['code']>=400 && $response['code']<500)$status='failed';
            TelegramStore::atomic(static function()use($key,$job,$d,$status,$response,$user,$reference){
                TelegramStore::put($key,'job',$user,$status,$d,$reference);
                if($status==='sent' && $user)TelegramStore::put('sent:'.$d['bot'].':'.$d['chat'].':'.$response['result']['message_id'],'sent',$user,'sent',['generation'=>$d['generation']],(int)$d['thread']);
            });
        });
    }
    public static function status(int $message): string {
        if(!TelegramStore::ready())return '';
        global $wpdb;$rows=$wpdb->get_col($wpdb->prepare('SELECT status FROM '.TelegramStore::table()." WHERE kind='job' AND record_key LIKE 'message:%%' AND reference_id=%d",$message));
        if(!$rows)return '';
        $labels=['unknown'=>__('Telegram: delivery unknown; check with the customer before resending.','pc-order-import-export'),'failed'=>__('Telegram: delivery failed. The reply is saved on the website.','pc-order-import-export'),'cancelled'=>__('Telegram: delivery cancelled after disconnection.','pc-order-import-export'),'sending'=>__('Telegram: sending.','pc-order-import-export'),'pending'=>__('Telegram: queued.','pc-order-import-export'),'sent'=>__('Telegram: accepted by Telegram, not a read receipt.','pc-order-import-export')];
        foreach($labels as $state=>$label)if(in_array($state,$rows,true))return $label;
        return '';
    }
}
