<?php
namespace PaintCore\PCOE;
defined('ABSPATH') || exit;

/** Approval notices only. Mail acceptance is recorded before any repeat is allowed. */
final class ApprovalNotifications {
    public static function data(int $id): array {
        $post=get_post($id);$data=$post?json_decode($post->post_content,true):null;
        if(!$post || $post->post_type!==CustomerApproval::TYPE || $post->post_status!=='private' || !is_array($data)
            || (int)$post->post_author!==(int)($data['source']['customer_id']??0))throw new \RuntimeException(__('The order is unavailable for confirmation.','pc-order-import-export'));
        return $data;
    }
    public static function managers(array $data): array {
        $ids=CustomerManagers::defaults((int)$data['source']['customer_id']);$ids[]=(int)($data['requested_by']??0);
        return array_values(array_unique(array_filter($ids,static fn($id)=>$id && user_can($id,'manage_woocommerce'))));
    }
    private static function event(array $data,int $user): string {
        return 'confirmed:'.hash('sha256',$data['revision'].':'.($data['confirmed_at']??'').':'.$user);
    }
    private static function meta(string $event): string { return '_pcoe_notice_'.substr(hash('sha256',$event),0,32); }
    private static function address(string $email): string { return !preg_match('/[\r\n]/',$email) && is_email($email)?$email:''; }

    private static function mail(int $id,string $event,string $to,string $subject,string $body,string $reply=''): string {
        global $wpdb;$meta=self::meta($event);$lock='pcoe-mail:'.hash('sha256',$id.':'.$meta);
        $lock=substr($lock,0,64);
        if((string)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,0)',$lock))!=='1')return 'sending';
        try {
            wp_cache_delete($id,'post_meta');$old=get_post_meta($id,$meta,true);
            if($old)return (string)$old['status'];
            if(!self::address($to))return 'failed';
            $record=['status'=>'sending','at'=>gmdate('c'),'recipient'=>$to];
            if(!update_post_meta($id,$meta,$record))throw new \RuntimeException(__('Email could not be recorded. Nothing was sent.','pc-order-import-export'));
            $headers=['Content-Type: text/plain; charset=UTF-8'];if($reply=self::address($reply))$headers[]='Reply-To: '.$reply;
            try {$record['status']=wp_mail($to,sanitize_text_field($subject),$body,$headers)?'accepted':'failed';}
            catch(\Throwable $e){$record['status']='unknown';}
            // An unsuccessful journal write leaves "sending" and prevents duplicate delivery.
            update_post_meta($id,$meta,$record);
            return $record['status'];
        } finally {$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));}
    }

    private static function summary(array $data): string {
        $s=$data['snapshot'];$lines=[$s['title'],sprintf(__('Total: %1$s %2$s','pc-order-import-export'),$s['total'],$s['currency'])];
        $order=!empty($data['source']['order_id'])?wc_get_order($data['source']['order_id']):false;
        if($order)foreach(ManagerNotifications::documents($order) as $doc)$lines[]=__('Folio account','pc-order-import-export').' #'.$doc['number'].' · '.($doc['date']?:__('Document date unavailable','pc-order-import-export'));
        else if(!empty($s['warehouse']))$lines[]=$s['warehouse'];
        return implode("\n",$lines);
    }

    public static function send_customer(int $id,string $revision,string $request): string {
        if(!current_user_can('manage_woocommerce'))throw new \RuntimeException('Forbidden');
        $data=self::data($id);
        if(!preg_match('/^[a-f0-9-]{36}$/i',$request) || $data['status']!=='pending' || !hash_equals($data['revision'],$revision)
            || !hash_equals($revision,CustomerApproval::revision(CustomerApproval::snapshot($data['source']))))
            throw new \RuntimeException(__('The order changed. Review it again before continuing.','pc-order-import-export'));
        $last=(string)get_post_meta($id,'_pcoe_customer_email_last',true);
        $record=$last?get_post_meta($id,self::meta($last),true):[];
        if(in_array($record['status']??'', ['sending','unknown'],true))throw new \RuntimeException(__('Email delivery is uncertain. Check the mail log before sending again.','pc-order-import-export'));
        $customer=get_userdata((int)$data['source']['customer_id']);$to=$customer?self::address($customer->user_email):'';
        if(!$to)throw new \RuntimeException(__('The customer profile has no valid email address.','pc-order-import-export'));
        $event='customer:'.$request;update_post_meta($id,'_pcoe_customer_email_last',$event);
        $switched=switch_to_locale(get_user_locale($customer));
        try {
            $subject=sprintf(__('Please confirm %s','pc-order-import-export'),$data['snapshot']['title']);
            $body=__('Please review your order, choose delivery and payment preferences, and confirm it in your account. Sign in with the account that owns this order.','pc-order-import-export')."\n\n".
                self::summary($data)."\n\n".CustomerApproval::customer_url($id)."\n\n".
                __('Confirmation saves your choices for the manager; it does not make a payment or start shipment.','pc-order-import-export');
            return self::mail($id,$event,$to,$subject,$body,wp_get_current_user()->user_email);
        } finally {if($switched)restore_previous_locale();}
    }

    public static function post_email(): void {
        check_admin_referer('pcoe_approval_email');
        try {
            $id=absint($_POST['approval_id']??0);
            self::send_customer($id,(string)($_POST['revision']??''),(string)($_POST['request_key']??''));
            wp_safe_redirect(CustomerApproval::url(['approval_id'=>$id]));exit;
        } catch(\Throwable $e){wp_die(esc_html($e->getMessage()),'', ['response'=>409,'back_link'=>true]);}
    }

    /** Safe on a repeated confirm POST: one mail/job per saved confirmation and manager. */
    public static function confirmed(int $id): void {
        $data=self::data($id);if($data['status']!=='confirmed')return;
        $customer=get_userdata((int)$data['source']['customer_id']);
        foreach(self::managers($data) as $user){
            $manager=get_userdata($user);$event=self::event($data,$user);$switched=switch_to_locale(get_user_locale($manager));
            try {
                $subject=sprintf(__('Customer confirmed %s','pc-order-import-export'),$data['snapshot']['title']);
                $body=$subject."\n".($customer?$customer->display_name:'')."\n".self::summary($data)."\n".$data['confirmed_at'];
                foreach($data['preferences'] as $key=>$value)$body.="\n".CustomerApproval::field_label($key).': '.(is_array($value)?$value['label']:$value);
                $url=CustomerApproval::url(['approval_id'=>$id]);
                self::mail($id,$event,$manager->user_email,$subject,$body."\n\n".$url,$customer?$customer->user_email:'');
                if(TelegramSettings::enabled() && TelegramStore::ready()){
                    $link=TelegramStore::active($user);
                    if($link && ($link['data']['audience']??'customer')==='manager')TelegramBridge::job('approval:'.$id.':'.$event,$link['data']['chat'],[
                        'text'=>$subject."\n".($customer?$customer->display_name:'')."\n".self::summary($data),
                        'reply_markup'=>['inline_keyboard'=>[[['text'=>__('Open confirmation','pc-order-import-export'),'url'=>$url]]]]
                    ],$link,0,0,'approval',['id'=>$id,'revision'=>$data['revision'],'confirmed_at'=>$data['confirmed_at']]);
                }
            } finally {if($switched)restore_previous_locale();}
        }
    }

    /** Recheck the saved confirmation and current manager membership before Telegram delivery. */
    public static function telegram_allowed(int $user,array $context): bool {
        try {$data=self::data((int)($context['id']??0));}
        catch(\Throwable $e){return false;}
        return $data['status']==='confirmed' && $data['revision']===($context['revision']??'')
            && ($data['confirmed_at']??'')===($context['confirmed_at']??'') && in_array($user,self::managers($data),true);
    }

    private static function label(string $status): string {
        return ['accepted'=>__('Accepted by the mail service','pc-order-import-export'),'failed'=>__('Email sending failed','pc-order-import-export'),
            'sending'=>__('Email delivery is uncertain; check the mail log','pc-order-import-export'),'unknown'=>__('Email delivery is uncertain; check the mail log','pc-order-import-export')][$status]??__('Not sent','pc-order-import-export');
    }

    public static function panel(int $id,array $data,bool $fresh): void {
        if(!current_user_can('manage_woocommerce'))return;
        $customer=get_userdata((int)$data['source']['customer_id']);$last=(string)get_post_meta($id,'_pcoe_customer_email_last',true);
        $record=$last?get_post_meta($id,self::meta($last),true):[];
        echo '<p>'.esc_html__('Email to customer:','pc-order-import-export').' '.esc_html($customer?$customer->user_email:'').' — '.esc_html(self::label($record['status']??'')).'</p>';
        if($fresh && $data['status']==='pending' && !in_array($record['status']??'', ['sending','unknown'],true)){
            echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';wp_nonce_field('pcoe_approval_email');
            foreach(['action'=>'pcoe_approval_email','approval_id'=>$id,'revision'=>$data['revision'],'request_key'=>wp_generate_uuid4()] as $key=>$value)echo '<input type="hidden" name="'.esc_attr($key).'" value="'.esc_attr($value).'">';
            echo '<button class="button">'.esc_html(($record['status']??'')==='accepted'?__('Email confirmation link again','pc-order-import-export'):__('Email confirmation link to customer','pc-order-import-export')).'</button></form>';
        }
        if($data['status']==='confirmed'){
            foreach(self::managers($data) as $user){$record=get_post_meta($id,self::meta(self::event($data,$user)),true);$manager=get_userdata($user);
                echo '<p>'.esc_html(sprintf(__('Manager notification — %1$s: %2$s','pc-order-import-export'),$manager->display_name,self::label($record['status']??''))).'</p>';
            }
            if(get_post_meta($id,'_pcoe_approval_notify_error',true))echo '<p role="alert">'.esc_html__('Confirmation is saved, but a manager notification needs checking.','pc-order-import-export').'</p>';
        }
    }
}
