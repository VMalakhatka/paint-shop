<?php
namespace PaintCore\PCOE;
defined('ABSPATH') || exit;

/** Manager transport has narrower write access than the website: assigned threads only. */
final class TelegramManagers {
    public static function allowed(int $thread, int $user, string $scope='assigned'): bool {
        $post=get_post($thread);
        if (!user_can($user,'manage_woocommerce') || !$post || $post->post_type!==ConversationStore::THREAD || $post->post_status!=='private') return false;
        $assignee=(int)get_post_meta($thread,'_chat_assignee',true);
        $state=get_post_meta($thread,'_chat_state',true);
        return $scope==='unassigned' ? !$assignee && $state==='waiting_manager' : $assignee===$user && $state!=='closed';
    }

    private static function recipients(int $thread): array {
        $assignee=(int)get_post_meta($thread,'_chat_assignee',true);
        if ($assignee) {
            $link=TelegramStore::active($assignee);
            return $link && self::allowed($thread,$assignee) ? [$link] : [];
        }
        global $wpdb;
        $records=$wpdb->get_results('SELECT user_id,data FROM '.TelegramStore::table()." WHERE kind='link' AND status='active'",ARRAY_A);
        $links=[];
        foreach ($records as $record) {
            // Avoid loading every customer profile when broadcasting the staff queue.
            if ((json_decode($record['data'],true)['audience']??'customer')!=='manager') continue;
            $user=(int)$record['user_id'];
            $link=TelegramStore::active((int)$user);
            if ($link && self::allowed($thread,(int)$user,'unassigned')) $links[]=$link;
        }
        return $links;
    }

    public static function enqueue(int $thread, int $message): void {
        foreach (self::recipients($thread) as $link) self::card('manager-message:'.$message,$thread,$link,$message);
    }

    public static function assigned(int $thread, array $previous): void {
        if (!TelegramSettings::enabled() || !TelegramStore::ready()) return;
        $assignee=(int)get_post_meta($thread,'_chat_assignee',true);
        $state=get_post_meta($thread,'_chat_state',true);
        if ($assignee===$previous['assignee'] && !($previous['state']==='closed' && $state!=='closed') && !(!$assignee && $previous['state']!=='waiting_manager' && $state==='waiting_manager')) return;
        $key='assignment:'.$thread.':'.get_post_meta($thread,'_chat_revision',true);
        foreach (self::recipients($thread) as $link) self::card($key,$thread,$link);
    }

    /** Every card is a separate guarded delivery, including cards requested via /queue. */
    private static function card(string $key, int $thread, array $link, int $message=0): void {
        $user=(int)$link['user_id'];
        $scope=(int)get_post_meta($thread,'_chat_assignee',true)?'assigned':'unassigned';
        if (!self::allowed($thread,$user,$scope)) return;
        if (!$message) {
            $ids=get_posts(['post_type'=>ConversationStore::MESSAGE,'post_status'=>'private','post_parent'=>$thread,'numberposts'=>1,'orderby'=>'ID','order'=>'DESC','fields'=>'ids','meta_query'=>[['key'=>'_chat_visibility','value'=>'public']]]);
            $message=(int)($ids[0]??0);
        }
        $post=get_post($thread);$owner=get_userdata((int)$post->post_author);
        $order_id=(int)get_post_meta($thread,'_chat_order',true);$order=$order_id?wc_get_order($order_id):false;
        switch_to_locale(get_user_locale($user));
        try {
            $header=sprintf(__('Conversation #%1$d · %2$s','pc-order-import-export'),$thread,$post->post_title)."\n".
                sprintf(__('Customer: %s','pc-order-import-export'),$owner?$owner->display_name:__('Customer','pc-order-import-export'));
            if ($order) $header.="\n".sprintf(__('Order #%s','pc-order-import-export'),$order->get_order_number());
            $header=mb_substr($header,0,300);
            $hint=$scope==='unassigned'?__('Unassigned request. Take it before replying.','pc-order-import-export'):__('Use Reply on this bot message to answer the customer. Your reply will be public.','pc-order-import-export');
            $rows=[];
            if ($scope==='unassigned') $rows[]=[['text'=>__('Take request','pc-order-import-export'),'callback_data'=>'claim:'.$thread]];
            $rows[]=[['text'=>__('Open on website','pc-order-import-export'),'url'=>Conversations::url(['chat_id'=>$thread],true)]];
            $body=$message?get_post($message)->post_content:'';
            $actor=$message && get_post_meta($message,'_chat_actor',true)==='manager'?__('Manager','pc-order-import-export'):__('Customer','pc-order-import-export');
            $parts=max(1,(int)ceil(mb_strlen($body)/1400));
            for ($i=0;$i<$parts;$i++) {
                $text=$header.($parts>1?' ('.($i+1).'/'.$parts.')':'')."\n\n".$actor.":\n".mb_substr($body,$i*1400,1400)."\n\n".$hint;
                TelegramBridge::job($key.':'.$user.':'.$i,$link['data']['chat'],['text'=>$text,'reply_markup'=>['inline_keyboard'=>$rows]],$link,$thread,$message,$scope);
            }
        } finally { restore_previous_locale(); }
    }

    private static function mapped(array $link, array $message): int {
        $id=(string)($message['message_id']??'');
        $map=TelegramStore::get('sent:'.$link['data']['bot'].':'.$link['data']['chat'].':'.$id);
        if (!$map || (int)$map['user_id']!==(int)$link['user_id'] || ($map['data']['generation']??'')!==$link['data']['generation']) return 0;
        return (int)$map['reference_id'];
    }

    /** Called inside the authenticated peer's webhook transaction and user/locale context. */
    public static function receive(string $key, string $text, array $message, ?array $callback, array $link, string $request): void {
        $user=(int)$link['user_id'];$chat=$link['data']['chat'];
        if (!ConversationStore::manager()) ConversationStore::deny();
        if ($callback) {
            $thread=self::mapped($link,$message);
            if (!$thread || ($callback['data']??'')!=='claim:'.$thread) {
                TelegramBridge::service($key,$chat,__('This request button is no longer available. Use /queue to refresh the queue.','pc-order-import-export'),$link);return;
            }
            $taken=ConversationStore::lock('thread:'.$thread,static function()use($thread,$user){
                clean_post_cache($thread);
                if (self::allowed($thread,$user)) return true;
                if (!self::allowed($thread,$user,'unassigned')) return false;
                $meta=ConversationStore::meta($thread);
                ConversationStore::assign($thread,$user,$meta['state'],$meta['revision']);
                return true;
            });
            TelegramBridge::service($key,$chat,$taken?sprintf(__('Conversation #%d is assigned to you. Use Reply on its bot message to answer the customer.','pc-order-import-export'),$thread):__('This request was taken by another manager or closed. Use /queue to refresh the queue.','pc-order-import-export'),$link,$taken?$thread:0);return;
        }
        if (in_array($text,['/start','/help','/threads','/queue'],true)) {
            TelegramBridge::service($key,$chat,__('Manager mode: /threads shows your open conversations; /queue shows unassigned requests. Take a request, then use Reply on its bot message. All replies are public. /stop disconnects your Telegram.','pc-order-import-export'),$link);
            $scope=$text==='/queue'?'unassigned':'assigned';
            $query=new \WP_Query(['post_type'=>ConversationStore::THREAD,'post_status'=>'private','posts_per_page'=>10,'orderby'=>['modified'=>'DESC','ID'=>'DESC'],
                'meta_query'=>[['key'=>'_chat_assignee','value'=>$scope==='unassigned'?0:$user,'type'=>'NUMERIC'],['key'=>'_chat_state','value'=>$scope==='unassigned'?'waiting_manager':'closed','compare'=>$scope==='unassigned'?'=':'!=']]]);
            foreach ($query->posts as $thread) self::card('manager-list:'.$key.':'.$thread->ID,$thread->ID,$link);
            if (!$query->posts) TelegramBridge::service($key.':empty',$chat,__('No requests in this queue. The full list is available on the website.','pc-order-import-export'),$link);
            return;
        }
        $thread=self::mapped($link,$message['reply_to_message']??[]);
        if ($text==='' || str_starts_with($text,'/') || mb_strlen($text)>5000 || !$thread) {
            TelegramBridge::service($key,$chat,__('Send text of 1–5000 characters using Reply on a specific bot message. Use /threads or /queue to find the request. Files and voice messages are not supported yet.','pc-order-import-export'),$link);return;
        }
        $saved=ConversationStore::lock('thread:'.$thread,static function()use($thread,$user,$text,$request){
            clean_post_cache($thread);
            if (!self::allowed($thread,$user)) return false;
            $id=ConversationStore::reply($thread,$text,false,$request);
            if (!update_post_meta($id,'_chat_channel','telegram')) throw new \RuntimeException('Channel metadata unavailable');
            return true;
        });
        TelegramBridge::service($key,$chat,$saved?sprintf(__('Reply saved in conversation #%d.','pc-order-import-export'),$thread):__('Reply not saved: this request is not assigned to you or is closed. Check /threads or take an unassigned request via /queue.','pc-order-import-export'),$link,$saved?$thread:0);
    }
}
