<?php
namespace PaintCore\PCOE;
defined('ABSPATH') || exit;

final class Conversations {
    const OPTION='pcoe_chat_enabled';
    public static function hooks(): void {
        add_action('init',[ConversationStore::class,'register']);
        add_action('admin_post_pcoe_chat',[self::class,'post']);
        add_action('wp_ajax_pcoe_chat_poll',[self::class,'poll']);
        add_filter('woocommerce_account_menu_items',[self::class,'menu']);
        add_filter('woocommerce_account_menu_item_classes',static function($classes,$endpoint) {
            if(isset($_GET['pcoe_chat'])) { $classes=array_diff($classes,['is-active']); if($endpoint==='pcoe-messages') $classes[]='is-active'; }
            return $classes;
        },10,2);
        add_filter('woocommerce_get_endpoint_url',[self::class,'endpoint'],10,4);
        add_action('template_redirect',[self::class,'route']);
        add_action('woocommerce_order_details_after_order_table',[self::class,'order_link']);
    }
    public static function enabled(): bool { return get_option(self::OPTION)==='yes'; }
    public static function states(): array { return ['waiting_manager'=>__('Manager reply needed','pc-order-import-export'), 'waiting_customer'=>__('Waiting for customer','pc-order-import-export'), 'closed'=>__('Closed','pc-order-import-export')]; }
    public static function url(array $args=[],?bool $admin=null): string {
        $admin=$admin??ConversationStore::manager();
        return add_query_arg($args,$admin?add_query_arg(['page'=>ManagerWorkspace::PAGE,'view'=>'messages'],admin_url('admin.php')):add_query_arg('pcoe_chat','1',wc_get_page_permalink('myaccount')));
    }
    public static function menu(array $items): array {
        if (self::enabled() && ConversationStore::customer(get_current_user_id())) {
            $logout=$items['customer-logout']??null; unset($items['customer-logout']);
            $items['pcoe-messages']=__('Messages','pc-order-import-export'); if ($logout) $items['customer-logout']=$logout;
        }
        return $items;
    }
    public static function endpoint($url,$endpoint,$value,$permalink) { return $endpoint==='pcoe-messages'?self::url([],false):$url; }
    public static function route(): void {
        if (function_exists('is_account_page') && is_account_page() && isset($_GET['pcoe_chat']) && is_user_logged_in()) {
            nocache_headers();
            remove_action('woocommerce_account_content','woocommerce_account_content');
            add_action('woocommerce_account_content',[self::class,'render']);
        }
    }
    public static function order_link($order): void {
        if (!self::enabled() || !$order instanceof \WC_Order || (int)$order->get_customer_id()!==get_current_user_id() || !ConversationStore::customer(get_current_user_id())) return;
        echo '<p><a class="button" href="'.esc_url(self::url(['new_order'=>$order->get_id()],false)).'">'.esc_html__('Discuss this order','pc-order-import-export').'</a></p>';
    }
    private static function fields(string $operation,int $id=0): void {
        echo '<input type="hidden" name="action" value="pcoe_chat"><input type="hidden" name="operation" value="'.esc_attr($operation).'"><input type="hidden" name="chat_id" value="'.esc_attr($id).'"><input type="hidden" name="request_id" value="'.esc_attr(wp_generate_uuid4()).'">';
        wp_nonce_field('pcoe_chat');
    }
    public static function post(): void {
        if (!is_user_logged_in()) wp_die(esc_html__('Please sign in.','pc-order-import-export'),'',['response'=>403]);
        check_admin_referer('pcoe_chat');
        $op=sanitize_key($_POST['operation']??''); $id=absint($_POST['chat_id']??0);
        try {
            if ($op==='enable' || $op==='disable') {
                if (!current_user_can('manage_options')) ConversationStore::deny();
                if ($op==='enable' && !ConversationStore::storage_ready()) throw new \RuntimeException(__('Chat requires transactional storage. Contact the administrator.','pc-order-import-export'));
                update_option(self::OPTION,$op==='enable'?'yes':'no',false);
            } else {
                if (!self::enabled()) throw new \RuntimeException(__('Chat is not enabled.','pc-order-import-export'));
                if ($op==='start') $id=ConversationStore::start(ConversationStore::manager()?absint($_POST['customer_id']??0):get_current_user_id(),absint($_POST['order_id']??0),(string)wp_unslash($_POST['subject']??''),(string)wp_unslash($_POST['message']??''),(string)($_POST['request_id']??''));
                elseif ($op==='reply') ConversationStore::reply($id,(string)wp_unslash($_POST['message']??''),($_POST['visibility']??'')==='internal',(string)($_POST['request_id']??''));
                elseif ($op==='assign') ConversationStore::assign($id,absint($_POST['assignee']??0),sanitize_key($_POST['state']??''),(string)($_POST['revision']??''));
                else ConversationStore::deny();
            }
            wp_safe_redirect(self::url($id?['chat_id'=>$id]:[])); exit;
        } catch (\Throwable $e) {
            // Keep typed text in the current form for a safe retry, without URL or persistent log copies.
            status_header(400); nocache_headers();
            echo '<!doctype html><html><meta charset="utf-8"><title>'.esc_html__('Message not saved','pc-order-import-export').'</title><body><h1>'.esc_html__('Message not saved','pc-order-import-export').'</h1><p>'.esc_html($e->getMessage()).'</p><p><a href="'.esc_url(self::url($id?['chat_id'=>$id]:[])).'">'.esc_html__('Return to messages','pc-order-import-export').'</a></p>';
            if (in_array($op,['start','reply'],true)) {
                echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
                foreach (['action'=>'pcoe_chat','operation'=>$op,'chat_id'=>$id,'request_id'=>(string)($_POST['request_id']??''),'customer_id'=>absint($_POST['customer_id']??0),'order_id'=>absint($_POST['order_id']??0),'subject'=>(string)wp_unslash($_POST['subject']??''),'visibility'=>sanitize_key($_POST['visibility']??'')] as $k=>$v) echo '<input type="hidden" name="'.esc_attr($k).'" value="'.esc_attr($v).'">';
                wp_nonce_field('pcoe_chat'); echo '<textarea name="message" rows="8" cols="60" maxlength="5000" required>'.esc_textarea(wp_unslash($_POST['message']??'')).'</textarea><p><button>'.esc_html__('Retry saving','pc-order-import-export').'</button></p></form>';
            }
            echo '</body></html>'; exit;
        }
    }
    public static function poll(): void {
        check_ajax_referer('pcoe_chat','nonce');
        $buffer=ob_get_level();
        try {
            $id=absint($_GET['chat_id']??0); ConversationStore::read($id);
            ob_start(); self::messages($id,1,true); $html=ob_get_clean();
            wp_send_json_success(['html'=>$html]);
        } catch (\Throwable $e) {
            while(ob_get_level()>$buffer) ob_end_clean();
            wp_send_json_error(['message'=>__('Could not refresh messages. Reload the page.','pc-order-import-export')],403);
        }
    }
    private static function assets(): void {
        wp_enqueue_style('pcoe-chat',PCOE_URL.'assets/conversations.css',[],filemtime(PCOE_DIR.'/assets/conversations.css'));
        wp_enqueue_script('pcoe-chat',PCOE_URL.'assets/conversations.js',[],filemtime(PCOE_DIR.'/assets/conversations.js'),true);
        wp_localize_script('pcoe-chat','pcoeChat',['url'=>admin_url('admin-ajax.php'),'nonce'=>wp_create_nonce('pcoe_chat'),'error'=>__('Could not refresh messages. Reload the page.','pc-order-import-export')]);
        // Account/admin renderers can run after the document head.
        if (!wp_style_is('pcoe-chat','done')) wp_print_styles('pcoe-chat');
    }
    public static function render(): void {
        if (!get_current_user_id() || (!ConversationStore::manager() && !ConversationStore::customer(get_current_user_id()))) { echo '<p>'.esc_html__('Conversation unavailable.','pc-order-import-export').'</p>'; return; }
        self::assets(); $admin=ConversationStore::manager();
        echo '<div class="pcoe-chat"><h2>'.esc_html($admin?__('Customer conversations','pc-order-import-export'):__('Messages','pc-order-import-export')).'</h2>';
        if (!self::enabled()) echo '<p>'.esc_html__('Chat is not enabled.','pc-order-import-export').'</p>';
        if ($admin && current_user_can('manage_options')) {
            echo '<details><summary>'.esc_html__('Chat settings','pc-order-import-export').'</summary><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';self::fields(self::enabled()?'disable':'enable');
            echo '<p>'.esc_html__('Enable text conversations for registered customers and wholesale accounts. Telegram is configured separately below.','pc-order-import-export').'</p><button class="button">'.esc_html(self::enabled()?__('Disable new messages','pc-order-import-export'):__('Enable chat','pc-order-import-export')).'</button></form></details>';
        }
        if($admin)TelegramSettings::admin();else TelegramSettings::customer();
        try {
            $id=absint($_GET['chat_id']??0);
            if ($id) self::thread($id); else { self::queue(); if(self::enabled()) self::new_form(); }
        } catch (\Throwable $e) { echo '<p role="alert">'.esc_html($e->getMessage()).'</p>'; }
        echo '</div>';
    }
    private static function queue(): void {
        $admin=ConversationStore::manager(); $page=max(1,absint($_GET['chat_page']??1)); $filter=sanitize_key($_GET['chat_filter']??'');
        echo '<nav class="pcoe-chat-filters">';
        foreach (array_merge([''=>__('All conversations','pc-order-import-export')],$admin?['mine'=>__('Assigned to me','pc-order-import-export'),'unassigned'=>__('Unassigned','pc-order-import-export')]:[],self::states()) as $key=>$label) echo '<a class="button" '.($filter===$key?'aria-current="page"':'').' href="'.esc_url(self::url(['chat_filter'=>$key])).'">'.esc_html($label).'</a>';
        echo '</nav>';
        $q=ConversationStore::listing($filter,$page);
        if (!$q->posts) echo '<p>'.esc_html__('No conversations yet.','pc-order-import-export').'</p>';
        foreach ($q->posts as $post) {
            $meta=ConversationStore::meta($post->ID); $owner=get_userdata((int)$post->post_author); $manager=$meta['assignee']?get_userdata($meta['assignee']):false;
            $last=ConversationStore::latest($post->ID); $unread=$last && $last>(int)get_user_meta(get_current_user_id(),'_pcoe_chat_seen_'.$post->ID,true);
            echo '<article class="pcoe-chat-card"><a href="'.esc_url(self::url(['chat_id'=>$post->ID])).'"><strong>'.esc_html($post->post_title).'</strong></a>'.($unread?' <strong>'.esc_html__('Unread','pc-order-import-export').'</strong>':'');
            echo '<p>'.esc_html(($admin && $owner?$owner->display_name.' · ':'').(self::states()[$meta['state']]??'').' · '.($manager?$manager->display_name:__('Unassigned','pc-order-import-export'))).'</p><small>'.esc_html(get_date_from_gmt($post->post_modified_gmt,'d.m.Y H:i')).'</small></article>';
        }
        self::pages($page,(int)$q->max_num_pages,'chat_page',['chat_filter'=>$filter]);
    }
    private static function new_form(): void {
        $admin=ConversationStore::manager();$customer=$admin?absint($_GET['new_customer']??0):get_current_user_id();$order=absint($_GET['new_order']??0);
        if (!ConversationStore::customer($customer)) {
            if ($admin) echo '<p><a class="button" href="'.esc_url(ManagerWorkspace::url(0)).'">'.esc_html__('Choose a customer to start a conversation','pc-order-import-export').'</a></p>';
            return;
        }
        if ($order && ($existing=ConversationStore::for_order($customer,$order))) {
            echo '<p><a class="button" href="'.esc_url(self::url(['chat_id'=>$existing])).'">'.esc_html__('Open order conversation','pc-order-import-export').'</a></p>'; return;
        }
        $orders=wc_get_orders(['customer_id'=>$customer,'limit'=>20,'orderby'=>'date','order'=>'DESC']);
        if ($order && ($chosen=wc_get_order($order)) && (int)$chosen->get_customer_id()===$customer) $orders[]=$chosen;
        echo '<section class="pcoe-chat-card"><h3>'.esc_html__('New conversation','pc-order-import-export').'</h3><p>'.esc_html(get_userdata($customer)->display_name).'</p><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">'; self::fields('start');
        echo '<input type="hidden" name="customer_id" value="'.esc_attr($customer).'"><label>'.esc_html__('Related order','pc-order-import-export').'<select name="order_id"><option value="0">'.esc_html__('General question','pc-order-import-export').'</option>';
        $seen=[];foreach($orders as $item) { if(isset($seen[$item->get_id()]))continue;$seen[$item->get_id()]=true;
            echo '<option value="'.esc_attr($item->get_id()).'" '.selected($order,$item->get_id(),false).'>'.esc_html('#'.$item->get_order_number().' · '.($item->get_date_created()?wc_format_datetime($item->get_date_created()):'')).'</option>';
        }
        echo '</select></label><label>'.esc_html__('Subject','pc-order-import-export').'<input type="text" name="subject" maxlength="160" required></label>';
        self::compose(false); echo '</form></section>';
    }
    private static function compose(bool $internal): void {
        echo '<label>'.esc_html__('Message','pc-order-import-export').'<textarea name="message" maxlength="5000" rows="5" required></textarea></label>';
        if ($internal) echo '<label>'.esc_html__('Message visibility','pc-order-import-export').'<select name="visibility"><option value="public">'.esc_html__('Send to customer','pc-order-import-export').'</option><option value="internal">'.esc_html__('Internal note — staff only','pc-order-import-export').'</option></select></label>';
        echo '<button class="button button-primary" type="submit">'.esc_html__('Save message','pc-order-import-export').'</button>';
    }
    private static function thread(int $id): void {
        $post=ConversationStore::read($id);$meta=ConversationStore::meta($id);$admin=ConversationStore::manager();
        echo '<p><a href="'.esc_url(self::url()).'">'.esc_html__('All conversations','pc-order-import-export').'</a></p><h3>'.esc_html($post->post_title).'</h3><p>'.esc_html(self::states()[$meta['state']]??'').'</p>';
        $manager=$meta['assignee']?get_userdata($meta['assignee']):false;
        echo '<p>'.esc_html__('Responsible manager','pc-order-import-export').': '.esc_html($manager?$manager->display_name:__('Unassigned','pc-order-import-export')).'</p>';
        if ($admin) { $owner=get_userdata((int)$post->post_author); echo '<p><a href="'.esc_url(ManagerWorkspace::url((int)$post->post_author)).'">'.esc_html($owner?$owner->display_name:__('Customer','pc-order-import-export')).'</a></p>'; }
        if ($meta['order_id']) {
            $order=wc_get_order($meta['order_id']);
            if ($order && (int)$order->get_customer_id()===(int)$post->post_author) echo '<p><a href="'.esc_url($admin?$order->get_edit_order_url():$order->get_view_order_url()).'">'.esc_html(sprintf(__('Order #%s','pc-order-import-export'),$order->get_order_number())).'</a></p>';
        }
        if ($admin && self::enabled()) {
            echo '<form class="pcoe-chat-assignment" method="post" action="'.esc_url(admin_url('admin-post.php')).'">';self::fields('assign',$id);
            echo '<input type="hidden" name="revision" value="'.esc_attr($meta['revision']).'"><label>'.esc_html__('Responsible manager','pc-order-import-export').'<select name="assignee"><option value="0">'.esc_html__('Unassigned','pc-order-import-export').'</option>';
            foreach(get_users(['capability'=>'manage_woocommerce','orderby'=>'display_name']) as $u) echo '<option value="'.esc_attr($u->ID).'" '.selected($meta['assignee'],$u->ID,false).'>'.esc_html($u->display_name).'</option>';
            echo '</select></label><label>'.esc_html__('State','pc-order-import-export').'<select name="state">';foreach(self::states() as $key=>$label) echo '<option value="'.esc_attr($key).'" '.selected($meta['state'],$key,false).'>'.esc_html($label).'</option>';
            echo '</select></label><button class="button">'.esc_html__('Save assignment and state','pc-order-import-export').'</button></form>';
        }
        $page=max(1,absint($_GET['messages_page']??1));
        echo '<div class="pcoe-chat-messages" data-chat="'.esc_attr($id).'" data-live="'.($page===1?'1':'0').'">';self::messages($id,$page,true);echo '</div><p class="pcoe-chat-error" role="status"></p>';
        echo '<p><a href="'.esc_url(self::url(['chat_id'=>$id])).'">'.esc_html__('Refresh messages','pc-order-import-export').'</a></p>';
        if (self::enabled()) { echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';self::fields('reply',$id);self::compose($admin);echo '</form>'; }
    }
    public static function messages(int $id,int $page,bool $seen): void {
        $data=ConversationStore::messages($id,$page);$last=0;
        foreach($data['messages'] as $m) {
            $internal=get_post_meta($m->ID,'_chat_visibility',true)==='internal';$actor=get_post_meta($m->ID,'_chat_actor',true);
            $author=get_userdata((int)$m->post_author);$last=max($last,$m->ID);
            echo '<article class="pcoe-chat-message '.($internal?'is-internal':'').'" data-message="'.esc_attr($m->ID).'"><small>'.esc_html(($author?$author->display_name:__('User','pc-order-import-export')).' · '.get_date_from_gmt($m->post_date_gmt,'d.m.Y H:i').' · '.($internal?__('Internal note','pc-order-import-export'):($actor==='customer'?__('Customer','pc-order-import-export'):__('Manager','pc-order-import-export')))).'</small><p>'.nl2br(esc_html($m->post_content)).'</p></article>';
            if(get_post_meta($m->ID,'_chat_channel',true)==='telegram')echo '<small>Telegram</small>';
            if(ConversationStore::manager() && !$internal && $actor==='manager') { $delivery=TelegramBridge::status($m->ID);if($delivery)echo '<p class="pcoe-telegram-status">'.esc_html($delivery).'</p>'; }
        }
        if ($seen && $page===1 && $last) update_user_meta(get_current_user_id(),'_pcoe_chat_seen_'.$id,$last);
        if (!$data['messages']) echo '<p>'.esc_html__('No messages yet.','pc-order-import-export').'</p>';
        self::pages($page,$data['pages'],'messages_page',['chat_id'=>$id]);
    }
    private static function pages(int $page,int $total,string $key,array $args): void {
        echo '<nav class="pcoe-chat-filters">';
        if($page>1) echo '<a href="'.esc_url(self::url(array_merge($args,[$key=>$page-1]))).'">'.esc_html__('Previous page','pc-order-import-export').'</a>';
        if($page<$total) echo '<a href="'.esc_url(self::url(array_merge($args,[$key=>$page+1]))).'">'.esc_html__('Next page','pc-order-import-export').'</a>';
        echo '</nav>';
    }
}
