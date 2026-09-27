<?php
namespace PaintCore\PCOE;
defined('ABSPATH') || exit;

final class TelegramSettings {
    const OPTION='pcoe_telegram_config';
    public static function config(): array { return (array)get_option(self::OPTION,[]); }
    public static function enabled(): bool {
        $c=self::config();
        // A cloned production database must never start sending from another site.
        return Conversations::enabled() && !empty($c['active']) && ($c['site']??'')===home_url('/') && !empty($c['token']);
    }
    private static function seal(string $value): string {
        $iv=random_bytes(12);$tag='';
        $encrypted=openssl_encrypt($value,'aes-256-gcm',hash('sha256',wp_salt('auth'),true),OPENSSL_RAW_DATA,$iv,$tag);
        if($encrypted===false)throw new \RuntimeException(__('Could not protect the bot token.','pc-order-import-export'));
        return base64_encode($iv.$tag.$encrypted);
    }
    private static function token(array $config): string {
        $raw=base64_decode($config['token']??'',true);
        if(!$raw || strlen($raw)<29)return '';
        return (string)openssl_decrypt(substr($raw,28),'aes-256-gcm',hash('sha256',wp_salt('auth'),true),OPENSSL_RAW_DATA,substr($raw,0,12),substr($raw,12,16));
    }
    /** Fixed destination; never expose the token-bearing URL or provider diagnostics. */
    public static function api(string $method,array $payload=[],?array $config=null): array {
        if(!in_array($method,['getMe','getWebhookInfo','setWebhook','sendMessage'],true))ConversationStore::deny();
        $config=$config??self::config();$token=self::token($config);
        if(!preg_match('/^\d{1,20}:[A-Za-z0-9_-]{20,128}$/D',$token))return ['ok'=>false,'code'=>0];
        try {
            $response=wp_remote_post('https://api.telegram.org/bot'.$token.'/'.$method,[
                'timeout'=>8,'redirection'=>0,'limit_response_size'=>65536,'headers'=>['Content-Type'=>'application/json'],
                'body'=>wp_json_encode((object)$payload),'sslverify'=>true]);
        } catch(\Throwable $e) { return ['ok'=>false,'code'=>0]; }
        if(is_wp_error($response))return ['ok'=>false,'code'=>0];
        $body=json_decode(wp_remote_retrieve_body($response),true);$code=wp_remote_retrieve_response_code($response);
        if($code===200 && !empty($body['ok']))return ['ok'=>true,'result'=>$body['result']??null,'code'=>200];
        return ['ok'=>false,'code'=>$code,'retry'=>max(1,(int)($body['parameters']['retry_after']??60))];
    }
    public static function configure(string $token): void {
        if(!current_user_can('manage_options') || !Conversations::enabled())ConversationStore::deny();
        if(wp_parse_url(home_url('/'),PHP_URL_SCHEME)!=='https')throw new \RuntimeException(__('Telegram requires the public HTTPS site.','pc-order-import-export'));
        TelegramStore::gate(static function()use($token){
            $old=self::config();$candidate=$old;
            if(strlen($token)>200)throw new \RuntimeException(__('Bot token could not be verified.','pc-order-import-export'));
            if($token!=='')$candidate['token']=self::seal(trim($token));
            $me=self::api('getMe',[],$candidate);
            if(empty($me['ok']) || empty($me['result']['is_bot']) || !preg_match('/^[A-Za-z0-9_]{5,32}$/D',$me['result']['username']??''))throw new \RuntimeException(__('Bot token could not be verified.','pc-order-import-export'));
            $bot=(string)$me['result']['id'];
            if(!empty($old['bot']) && $old['bot']!==$bot)throw new \RuntimeException(__('Use the same bot. Changing bots requires a separate migration.','pc-order-import-export'));
            $url=rest_url('pcoe/v1/telegram');$webhook=self::api('getWebhookInfo',[],$candidate);
            if(empty($webhook['ok']) || (!empty($webhook['result']['url']) && $webhook['result']['url']!==$url))throw new \RuntimeException(__('This bot is already connected elsewhere, or its webhook could not be checked.','pc-order-import-export'));
            TelegramStore::install();
            $candidate=array_merge($candidate,['active'=>false,'bot'=>$bot,'username'=>$me['result']['username'],'site'=>home_url('/'),'secret'=>$old['secret']??bin2hex(random_bytes(32))]);
            update_option(self::OPTION,$candidate,false);
            $set=self::api('setWebhook',['url'=>$url,'secret_token'=>$candidate['secret'],'allowed_updates'=>['message','callback_query'],'max_connections'=>2,'drop_pending_updates'=>false],$candidate);
            if(empty($set['ok']))throw new \RuntimeException(__('Webhook activation was not confirmed. The channel remains paused; retry setup explicitly.','pc-order-import-export'));
            $candidate['active']=true;update_option(self::OPTION,$candidate,false);
        });
        TelegramBridge::schedule();
    }
    public static function form(string $op,string $label,array $extra=[]): void {
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
        self::fields($op);foreach($extra as $key=>$value)echo '<input type="hidden" name="'.esc_attr($key).'" value="'.esc_attr($value).'">';
        echo '<button class="button">'.esc_html($label).'</button></form>';
    }
    public static function fields(string $op): void {
        echo '<input type="hidden" name="action" value="pcoe_telegram"><input type="hidden" name="operation" value="'.esc_attr($op).'">';wp_nonce_field('pcoe_telegram');
    }
    public static function admin(): void {
        if(!current_user_can('manage_options'))return;
        $c=self::config();
        echo '<details class="pcoe-chat-card"><summary>Telegram</summary><p>'.esc_html(self::enabled()?__('Telegram is enabled.','pc-order-import-export'):__('Telegram is not enabled.','pc-order-import-export')).'</p>';
        echo '<p>'.esc_html__('Create a dedicated bot with /newbot in @BotFather, then paste its token here. Use this bot only for this website. The token is stored encrypted and is never shown again.','pc-order-import-export').'</p>';
        if(!empty($c['username']))echo '<p>@'.esc_html($c['username']).'</p>';
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';self::fields('configure');
        echo '<label>'.esc_html__('Bot token (leave empty to keep the saved token)','pc-order-import-export').'<input type="password" name="bot_token" maxlength="200" autocomplete="new-password"></label><button class="button">'.esc_html__('Verify and enable Telegram','pc-order-import-export').'</button></form>';
        if(self::enabled())self::form('pause',__('Pause Telegram','pc-order-import-export'));
        if(TelegramStore::ready()) {
            $problem=TelegramStore::records('job',['failed','unknown','sending'],10);
            if($problem)echo '<p role="status">'.esc_html__('Some Telegram deliveries need review. Open the relevant conversation to see their status. Unknown deliveries are not retried automatically.','pc-order-import-export').'</p>';
            foreach($problem as $key) {
                $job=TelegramStore::get($key);$thread=(int)($job['data']['thread']??0);
                if($thread)echo '<p><a href="'.esc_url(Conversations::url(['chat_id'=>$thread],true)).'">#'.esc_html($thread).'</a> — '.esc_html(TelegramBridge::status((int)$job['reference_id'])).'</p>';
            }
        }
        echo '</details>';
    }
    public static function customer(): void {
        if(ConversationStore::manager() || !ConversationStore::customer(get_current_user_id()))return;
        $c=self::config();if(empty($c['bot']) || !TelegramStore::ready())return;
        $link=TelegramStore::link(get_current_user_id());$status=$link['status']??'';
        echo '<section class="pcoe-chat-card"><h3>Telegram</h3>';
        if($status==='active') {
            echo '<p>'.esc_html__('Telegram is linked. New public manager replies will also arrive in the bot.','pc-order-import-export').'</p>';
            echo '<p><a rel="noreferrer noopener" target="_blank" href="'.esc_url('https://t.me/'.$c['username']).'">'.esc_html__('Open Telegram bot','pc-order-import-export').'</a></p>';
            if(!self::enabled())echo '<p>'.esc_html__('Telegram delivery is currently paused by the store.','pc-order-import-export').'</p>';
            self::form('unlink',__('Disconnect Telegram','pc-order-import-export'));
        } elseif(self::enabled()) {
            echo '<p>'.esc_html__('Link your own Telegram account to receive manager replies and write in the same conversations. After starting the bot, return here and confirm the account. Do not share the one-time link.','pc-order-import-export').'</p>';
            if($status==='pending' && ($link['data']['expires']??0)>=time()) {
                echo '<p>'.esc_html(sprintf(__('Confirm Telegram account: %1$s (ID ending %2$s).','pc-order-import-export'),$link['data']['name'],substr($link['data']['chat'],-4))).'</p>';
                self::form('confirm',__('Confirm my Telegram account','pc-order-import-export'),['generation'=>$link['data']['generation']]);
            }
            self::form('link',__('Create one-time Telegram link','pc-order-import-export'));
        }
        echo '</section>';
    }
    public static function post(): void {
        check_admin_referer('pcoe_telegram');$op=sanitize_key($_POST['operation']??'');
        try {
            if(in_array($op,['configure','pause'],true)) {
                if(!current_user_can('manage_options'))ConversationStore::deny();
                if($op==='configure')self::configure((string)wp_unslash($_POST['bot_token']??''));
                else TelegramStore::gate(static function(){$c=self::config();$c['active']=false;update_option(self::OPTION,$c,false);});
            } else {
                $user=get_current_user_id();if(!$user || !ConversationStore::customer($user) || ConversationStore::manager())ConversationStore::deny();
                if($op==='link') {
                    $code=TelegramStore::issue($user);$url='https://t.me/'.self::config()['username'].'?start='.$code;
                    nocache_headers();header('Referrer-Policy: no-referrer');
                    wp_die('<p>'.esc_html__('Open the bot and press Start. Then return to Messages, refresh the page and confirm your Telegram account. The link expires in 15 minutes.','pc-order-import-export').'</p><p><a rel="noreferrer noopener" target="_blank" href="'.esc_url($url).'">'.esc_html__('Open Telegram bot','pc-order-import-export').'</a></p><p><a href="'.esc_url(Conversations::url([],false)).'">'.esc_html__('Return to messages','pc-order-import-export').'</a></p>',esc_html__('Connect Telegram','pc-order-import-export'),['response'=>200]);
                } elseif($op==='confirm')TelegramStore::confirm($user,(string)($_POST['generation']??''));
                elseif($op==='unlink')TelegramStore::gate(static fn()=>TelegramStore::atomic(static fn()=>TelegramStore::revoke($user)));
                else ConversationStore::deny();
            }
            wp_safe_redirect(Conversations::url());exit;
        } catch(\Throwable $e) {
            wp_die(esc_html($e->getMessage()),esc_html__('Telegram setup','pc-order-import-export'),['response'=>400,'back_link'=>true]);
        }
    }
}
