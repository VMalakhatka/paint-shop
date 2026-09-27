<?php
namespace PaintCore\PCOE;
defined('ABSPATH') || exit;

/** Private channel records. Message bodies remain in ConversationStore. */
final class TelegramStore {
    public static function table(): string { global $wpdb; return $wpdb->prefix.'pcoe_telegram'; }
    public static function install(): void {
        if (!current_user_can('manage_options')) ConversationStore::deny();
        global $wpdb;
        require_once ABSPATH.'wp-admin/includes/upgrade.php';
        $table=self::table();$collate=$wpdb->get_charset_collate();
        dbDelta("CREATE TABLE $table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            record_key varchar(191) NOT NULL,
            kind varchar(16) NOT NULL,
            user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            reference_id bigint(20) unsigned NOT NULL DEFAULT 0,
            status varchar(24) NOT NULL,
            data longtext NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY record_key (record_key),
            KEY queue (kind,status,id),
            KEY owner (kind,user_id)
        ) ENGINE=InnoDB $collate;");
        if (!self::ready() || !ConversationStore::storage_ready()) throw new \RuntimeException(__('Telegram storage is unavailable.','pc-order-import-export'));
    }
    public static function ready(): bool {
        global $wpdb;
        return strtolower((string)$wpdb->get_var($wpdb->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',self::table())))==='innodb';
    }
    /** One channel gate also serializes revocation with the short external send. */
    public static function gate(callable $fn) {
        global $wpdb;$key='pcoe-tg:'.substr(hash('sha256',self::table().DB_NAME),0,40);
        if ((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 1)',$key))!==1) throw new \RuntimeException(__('Telegram is busy. Please retry.','pc-order-import-export'));
        try { return $fn(); } finally { $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$key)); }
    }
    public static function atomic(callable $fn) { return ConversationStore::lock('telegram-data',$fn); }
    public static function get(string $key): ?array {
        global $wpdb;$row=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::table().' WHERE record_key=%s',$key),ARRAY_A);
        if(!$row)return null;$row['data']=json_decode($row['data'],true)?:[];return $row;
    }
    public static function put(string $key,string $kind,int $user,string $status,array $data,int $reference=0): array {
        global $wpdb;$old=self::get($key);
        $row=['kind'=>$kind,'user_id'=>$user,'reference_id'=>$reference,'status'=>$status,'data'=>wp_json_encode($data),'updated_at'=>gmdate('Y-m-d H:i:s')];
        $ok=$old?$wpdb->update(self::table(),$row,['id'=>$old['id']]):$wpdb->insert(self::table(),array_merge(['record_key'=>$key],$row));
        if($ok===false)throw new \RuntimeException(__('Telegram record could not be saved.','pc-order-import-export'));
        return self::get($key);
    }
    public static function records(string $kind,array $states,int $limit=20): array {
        global $wpdb;$marks=implode(',',array_fill(0,count($states),'%s'));
        return $wpdb->get_col($wpdb->prepare('SELECT record_key FROM '.self::table()." WHERE kind=%s AND status IN ($marks) ORDER BY id ASC LIMIT %d",array_merge([$kind],$states,[$limit])))?:[];
    }
    public static function link(int $user): ?array { return self::get('link:'.$user); }
    /** Persist the audience: a later role change must never upgrade an old customer binding. */
    public static function audience(int $user): string {
        return user_can($user,'manage_woocommerce')?'manager':(ConversationStore::customer($user)?'customer':'');
    }
    public static function eligible(int $user,array $data): bool {
        return self::audience($user)!=='' && self::audience($user)===($data['audience']??'customer');
    }
    public static function active(int $user): ?array {
        $link=self::link($user);$config=TelegramSettings::config();
        return $link && $link['status']==='active' && ($link['data']['bot']??'')===($config['bot']??'') && self::eligible($user,$link['data']) ? $link : null;
    }
    public static function peer(string $bot,string $chat): string { return 'peer:'.$bot.':'.$chat; }
    public static function revoke(int $user): void {
        $link=self::link($user);if(!$link)return;
        $d=$link['data'];
        if(!empty($d['chat'])) {
            $key=self::peer($d['bot'],$d['chat']);$peer=self::get($key);
            if($peer && (int)$peer['user_id']===$user) self::put($key,'peer',$user,'revoked',[]);
        }
        self::put('link:'.$user,'link',$user,'revoked',['bot'=>$d['bot']??'']);
    }
    public static function issue(int $user): string {
        if($user!==get_current_user_id() || self::audience($user)==='' || !TelegramSettings::enabled())ConversationStore::deny();
        return self::gate(static fn()=>self::atomic(static function()use($user){
            if(self::active($user))ConversationStore::deny();
            // Remove an obsolete peer after a role change before issuing the new audience binding.
            self::revoke($user);
            $code=bin2hex(random_bytes(24));$generation=wp_generate_uuid4();$bot=TelegramSettings::config()['bot'];
            self::put('link:'.$user,'link',$user,'issued',['bot'=>$bot,'audience'=>self::audience($user),'generation'=>$generation,'expires'=>time()+900]);
            self::put('code:'.hash('sha256',$code),'code',$user,'issued',['bot'=>$bot,'generation'=>$generation,'expires'=>time()+900]);
            return $code;
        }));
    }
    /** Called only by the authenticated webhook; final activation happens in the website session. */
    public static function pair(string $code,string $chat,string $name): bool {
        $key='code:'.hash('sha256',$code);$record=self::get($key);$bot=TelegramSettings::config()['bot'];
        if(!$record || $record['status']!=='issued' || ($record['data']['expires']??0)<time() || ($record['data']['bot']??'')!==$bot)return false;
        $user=(int)$record['user_id'];$link=self::link($user);$peer=self::get(self::peer($bot,$chat));
        if(!$link || $link['status']!=='issued' || ($link['data']['generation']??'')!==$record['data']['generation'] || !self::eligible($user,$link['data']) || ($peer && $peer['status']==='active' && (int)$peer['user_id']!==$user))return false;
        self::put($key,'code',$user,'used',$record['data']);
        self::put('link:'.$user,'link',$user,'pending',array_merge($link['data'],['chat'=>$chat,'name'=>mb_substr(sanitize_text_field($name),0,80)]));
        return true;
    }
    public static function confirm(int $user,string $generation): void {
        if($user!==get_current_user_id() || self::audience($user)==='' || !TelegramSettings::enabled())ConversationStore::deny();
        self::gate(static fn()=>self::atomic(static function()use($user,$generation){
            $link=self::link($user);
            if(!$link || $link['status']!=='pending' || !self::eligible($user,$link['data']) || ($link['data']['expires']??0)<time() || !hash_equals($link['data']['generation'],$generation))ConversationStore::deny();
            $d=$link['data'];$key=self::peer($d['bot'],$d['chat']);$peer=self::get($key);
            if($peer && $peer['status']==='active' && (int)$peer['user_id']!==$user)ConversationStore::deny();
            $d['linked_at']=time();unset($d['expires']);
            self::put('link:'.$user,'link',$user,'active',$d);self::put($key,'peer',$user,'active',['generation'=>$generation]);
        }));
    }
}
