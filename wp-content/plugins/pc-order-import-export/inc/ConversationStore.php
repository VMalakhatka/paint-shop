<?php
namespace PaintCore\PCOE;
defined('ABSPATH') || exit;

/** Private, text-only records. All reads and writes go through owner/capability checks. */
final class ConversationStore {
    private static bool $transaction = false;
    private static array $touched = [];
    private static array $deferredLocks = [];
    const THREAD = 'pcoe-chat';
    const MESSAGE = 'pcoe-chat-message';
    public static function register(): void {
        foreach ([self::THREAD,self::MESSAGE] as $type) register_post_type($type, [
            'public'=>false,'publicly_queryable'=>false,'exclude_from_search'=>true,'show_ui'=>false,
            'show_in_rest'=>false,'show_in_nav_menus'=>false,'can_export'=>false,'rewrite'=>false,'query_var'=>false,
            'supports'=>[], 'capabilities'=>array_fill_keys(['edit_post','read_post','delete_post','edit_posts','edit_others_posts','publish_posts','read_private_posts','create_posts'], 'do_not_allow')]);
    }
    public static function manager(): bool { return current_user_can('manage_woocommerce'); }
    public static function customer(int $id): bool {
        $u = get_userdata($id);
        $roles = array_merge(['customer'], function_exists('pc_wholesale_customer_roles') ? pc_wholesale_customer_roles() : ['opt','partner']);
        return $u && (bool) array_intersect($roles, (array)$u->roles);
    }
    public static function deny(): void { throw new \RuntimeException(__('Conversation unavailable.', 'pc-order-import-export')); }
    public static function read(int $id): \WP_Post {
        $p = get_post($id);
        if (!$p || $p->post_type !== self::THREAD || $p->post_status !== 'private'
            || !get_current_user_id() || (!self::manager() && (!self::customer(get_current_user_id()) || (int)$p->post_author !== get_current_user_id()))) self::deny();
        return $p;
    }
    public static function meta(int $id): array {
        self::read($id);
        return ['assignee'=>(int)get_post_meta($id,'_chat_assignee',true), 'state'=>(string)get_post_meta($id,'_chat_state',true),
            'order_id'=>(int)get_post_meta($id,'_chat_order',true), 'revision'=>(string)get_post_meta($id,'_chat_revision',true)];
    }
    /** Internal transaction boundary shared with channel adapters; callers authorize the actor. */
    public static function lock(string $key, callable $fn) {
        global $wpdb;
        $name='pcoe-chat:'.substr(hash('sha256',$wpdb->prefix.$key),0,45);
        if ((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)',$name))!==1) throw new \RuntimeException(__('Conversation is busy. Retry with the same message.', 'pc-order-import-export'));
        $nested=self::$transaction;
        try { return self::atomic($fn); } finally {
            if ($nested) self::$deferredLocks[]=$name;
            else $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$name));
        }
    }
    private static function atomic(callable $fn) {
        if (self::$transaction) return $fn();
        global $wpdb;
        if ($wpdb->query('SET TRANSACTION ISOLATION LEVEL READ COMMITTED') === false || $wpdb->query('START TRANSACTION') === false) self::deny();
        self::$transaction=true; self::$touched=[]; self::$deferredLocks=[];
        try {
            $result=$fn();
            if ($wpdb->query('COMMIT')===false) throw new \RuntimeException(__('Could not save the conversation. Please retry.','pc-order-import-export'));
            return $result;
        } catch (\Throwable $e) { $wpdb->query('ROLLBACK'); throw $e; }
        finally {
            self::$transaction=false;
            foreach(self::$deferredLocks as $name) $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$name));
            self::$deferredLocks=[];
            foreach (array_unique(self::$touched) as $id) { clean_post_cache($id); wp_cache_delete($id,'post_meta'); }
        }
    }
    public static function storage_ready(): bool {
        global $wpdb;
        foreach ([$wpdb->posts,$wpdb->postmeta] as $table) {
            $engine=$wpdb->get_var($wpdb->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',$table));
            if (strtolower((string)$engine)!=='innodb') return false;
        }
        return true;
    }
    private static function set(int $id,string $key,$value): void {
        self::$touched[]=$id;
        if (get_post_meta($id,$key,true)===(string)$value) return;
        if (update_post_meta($id,$key,$value)===false) throw new \RuntimeException(__('Could not save the conversation. Please retry.','pc-order-import-export'));
    }
    private static function text(string $text): string {
        $text=trim(sanitize_textarea_field($text));
        if ($text==='' || mb_strlen($text)>5000) throw new \RuntimeException(__('Enter a message of 1–5000 characters.', 'pc-order-import-export'));
        return $text;
    }
    private static function request(string $key): string {
        if (!preg_match('/^[a-f0-9-]{36}$/D',$key)) self::deny();
        return hash('sha256', get_current_user_id().':'.$key);
    }
    private static function save(array $post): int {
        $meta=$post['meta_input']??[]; unset($post['meta_input']);
        $id=empty($post['ID']) ? wp_insert_post(wp_slash($post),true) : wp_update_post(wp_slash($post),true);
        if (is_wp_error($id)) throw new \RuntimeException(__('Could not save the conversation. Please retry.', 'pc-order-import-export'));
        self::$touched[]=(int)$id;
        foreach ($meta as $key=>$value) self::set((int)$id,$key,$value);
        return (int)$id;
    }
    private static function touch(int $id): void {
        self::set($id,'_chat_revision',wp_generate_uuid4());
        self::save(['ID'=>$id,'post_modified'=>current_time('mysql'),'post_modified_gmt'=>current_time('mysql',true)]);
    }
    private static function find(string $key): int {
        $ids=get_posts(['post_type'=>self::THREAD,'post_status'=>'private','name'=>$key,'numberposts'=>1,'fields'=>'ids']);
        return (int)($ids[0]??0);
    }
    public static function start(int $customer, int $order_id, string $subject, string $text, string $request): int {
        if (!get_current_user_id() || !self::customer($customer) || (!self::manager() && $customer!==get_current_user_id())) self::deny();
        $order=$order_id ? wc_get_order($order_id) : false;
        if ($order_id && (!$order || (int)$order->get_customer_id()!==$customer)) self::deny();
        $text=self::text($text); $request=self::request($request);
        $subject=trim(sanitize_text_field($subject));
        if ($subject==='' || mb_strlen($subject)>160) throw new \RuntimeException(__('Enter a subject of 1–160 characters.', 'pc-order-import-export'));
        $key=hash('sha256',$customer.':'.($order_id ? 'order:'.$order_id : 'request:'.$request));
        return self::lock('start:'.$key,static function() use($key,$customer,$order_id,$subject,$text,$request) {
            $id=self::find($key);
            if (!$id) {
                $id=self::save(['post_type'=>self::THREAD,'post_status'=>'private','post_author'=>$customer,'post_name'=>$key,'post_title'=>$subject]);
                self::set($id,'_chat_order',$order_id);
                self::set($id,'_chat_assignee',self::manager()?get_current_user_id():0);
                self::set($id,'_chat_state','waiting_manager');
            }
            self::append($id,$text,false,$request);
            return $id;
        });
    }
    public static function reply(int $id,string $text,bool $internal,string $request): int {
        self::read($id);
        if ($internal && !self::manager()) self::deny();
        return self::append($id,self::text($text),$internal,self::request($request));
    }
    private static function append(int $id,string $text,bool $internal,string $request): int {
        return self::lock('thread:'.$id,static function() use($id,$text,$internal,$request) {
            clean_post_cache($id);
            self::read($id);
            $existing=get_posts(['post_type'=>self::MESSAGE,'post_status'=>'private','post_parent'=>$id,'name'=>$request,'numberposts'=>1,'fields'=>'ids']);
            if ($existing) return (int)$existing[0];
            $message=self::save(['post_type'=>self::MESSAGE,'post_status'=>'private','post_parent'=>$id,
                'post_author'=>get_current_user_id(),'post_name'=>$request,'post_content'=>$text,
                'meta_input'=>['_chat_visibility'=>$internal?'internal':'public','_chat_actor'=>self::manager()?'manager':'customer']]);
            // Message, assignment and queue state commit together under the thread lock.
            if (!$internal && self::manager() && !(int)get_post_meta($id,'_chat_assignee',true)) self::set($id,'_chat_assignee',get_current_user_id());
            if (!$internal) self::set($id,'_chat_state',self::manager()?'waiting_customer':'waiting_manager');
            self::touch($id);
            // Channel adapters only enqueue durable work here. Never send HTTP inside this transaction.
            do_action('pcoe_chat_message_saved',$id,$message,$internal);
            return $message;
        });
    }
    public static function assign(int $id,int $manager,string $state,string $revision): void {
        if (!self::manager()) self::deny();
        if ($manager && !user_can($manager,'manage_woocommerce')) throw new \RuntimeException(__('Select an active manager.', 'pc-order-import-export'));
        if (!in_array($state,['waiting_manager','waiting_customer','closed'],true)) self::deny();
        self::lock('thread:'.$id,static function() use($id,$manager,$state,$revision) {
            clean_post_cache($id);
            $meta=self::meta($id);
            if (!hash_equals($meta['revision'],$revision)) throw new \RuntimeException(__('Conversation changed. Refresh before changing its owner or state.', 'pc-order-import-export'));
            if ($meta['assignee']===$manager && $meta['state']===$state) return;
            self::save(['post_type'=>self::MESSAGE,'post_status'=>'private','post_parent'=>$id,'post_author'=>get_current_user_id(),
                'post_content'=>sprintf(__('Assignment: %1$s. State: %2$s.', 'pc-order-import-export'),$manager?(get_userdata($manager)->display_name):__('Unassigned','pc-order-import-export'), Conversations::states()[$state]),
                'meta_input'=>['_chat_visibility'=>'internal','_chat_actor'=>'system']]);
            self::set($id,'_chat_assignee',$manager); self::set($id,'_chat_state',$state); self::touch($id);
        });
    }
    public static function messages(int $id,int $page=1): array {
        self::read($id);
        $args=['post_type'=>self::MESSAGE,'post_status'=>'private','post_parent'=>$id,'posts_per_page'=>50,'paged'=>max(1,$page),'orderby'=>'ID','order'=>'DESC'];
        if (!self::manager()) $args['meta_query']=[['key'=>'_chat_visibility','value'=>'public']];
        $query=new \WP_Query($args);
        return ['messages'=>array_reverse($query->posts),'pages'=>(int)$query->max_num_pages];
    }
    public static function latest(int $id): int {
        self::read($id);
        $args=['post_type'=>self::MESSAGE,'post_status'=>'private','post_parent'=>$id,'numberposts'=>1,'orderby'=>'ID','order'=>'DESC','fields'=>'ids'];
        if (!self::manager()) $args['meta_query']=[['key'=>'_chat_visibility','value'=>'public']];
        return (int)(get_posts($args)[0]??0);
    }
    public static function for_order(int $customer,int $order): int {
        if (!get_current_user_id() || (!self::manager() && $customer!==get_current_user_id())) self::deny();
        return self::find(hash('sha256',$customer.':order:'.$order));
    }
    public static function listing(string $filter,int $page,int $customer=0): \WP_Query {
        if (!get_current_user_id()) self::deny();
        $args=['post_type'=>self::THREAD,'post_status'=>'private','posts_per_page'=>20,'paged'=>max(1,$page),'orderby'=>['modified'=>'DESC','ID'=>'DESC']];
        if (!self::manager()) $args['author']=get_current_user_id(); elseif ($customer) $args['author']=$customer;
        if ($filter==='mine' && self::manager()) $args['meta_query']=[['key'=>'_chat_assignee','value'=>get_current_user_id(),'type'=>'NUMERIC']];
        elseif ($filter==='unassigned' && self::manager()) $args['meta_query']=[['key'=>'_chat_assignee','value'=>0,'type'=>'NUMERIC']];
        elseif (in_array($filter,['waiting_manager','waiting_customer','closed'],true)) $args['meta_query']=[['key'=>'_chat_state','value'=>$filter]];
        return new \WP_Query($args);
    }
}
