<?php
namespace PaintCore\PCOE;
defined('ABSPATH') || exit;

/** Explicit preview/start; durable per-recipient outcomes. Never sends on a GET. */
final class Broadcasts {
    public const HOOK='pcoe_broadcast_tick';
    public const OPT_OUT='_pcoe_broadcast_opt_out';
    public static function hooks(): void {
        add_action('init',[BroadcastStore::class,'register']);
        add_action('before_delete_post',[BroadcastStore::class,'delete_files']);
        add_action(self::HOOK,[self::class,'tick']);
        add_action('admin_post_pcoe_broadcast',[BroadcastUi::class,'post']);
        add_action('wp_ajax_pcoe_broadcast_receipts',[BroadcastUi::class,'receipts']);
        add_action('admin_post_pcoe_broadcast_file',[BroadcastUi::class,'download']);
        add_action('woocommerce_edit_account_form',[self::class,'preferences']);
        add_action('woocommerce_save_account_details',[self::class,'save_preferences']);
    }
    public static function allowed(int $user): bool {
        $u=get_userdata($user);
        return $u && array_intersect(ManagerWorkspace::customer_roles(),$u->roles)
            && !user_can($user,'manage_woocommerce') && !get_user_meta($user,self::OPT_OUT,true);
    }
    private static function email(string $email): bool { return !preg_match('/[\r\n]/',$email) && is_email($email); }
    public static function recipients(array $input): array {
        $role=sanitize_key($input['role']??'');
        if($role && !in_array($role,ManagerWorkspace::customer_roles(),true))throw new \RuntimeException('Invalid customer role');
        $directory=ManagerWorkspace::directory(sanitize_text_field($input['search']??''),sanitize_key($input['role']??''),sanitize_text_field($input['city']??''),1,2001);
        if($directory['total']>2000)throw new \RuntimeException(__('Narrow the filters to no more than 2000 customers.','pc-order-import-export'));
        $selected=array_map('absint',(array)($input['users']??[]));$all=($input['all_filtered']??'')==='1';
        $rows=[];$seen=[];$skipped=0;
        foreach($directory['users'] as $u){
            if(!$all && !in_array($u->ID,$selected,true))continue;
            if(!self::allowed($u->ID) || !self::email($u->user_email)){$skipped++;continue;}
            $address=strtolower($u->user_email);
            if(isset($seen[$address])){if(isset($rows[$seen[$address]])){unset($rows[$seen[$address]]);$skipped++;}$skipped++;continue;}
            $seen[$address]=$u->ID;
            $rows[$u->ID]=['email'=>$u->user_email,'name'=>$u->display_name,'status'=>'pending'];
        }
        if(!$rows)throw new \RuntimeException(__('Select customers with valid email addresses who receive mailings.','pc-order-import-export'));
        return ['rows'=>$rows,'skipped'=>$skipped];
    }
    public static function create(array $input): int {
        if(!current_user_can('manage_woocommerce'))throw new \RuntimeException('Forbidden');
        $key=(string)($input['request_key']??'');
        if(!preg_match('/^[a-f0-9-]{36}$/i',$key))throw new \RuntimeException('Invalid request');
        return BroadcastStore::lock(static function()use($input,$key){
            $old=get_posts(['post_type'=>BroadcastStore::TYPE,'post_status'=>'private','numberposts'=>1,'author'=>get_current_user_id(),'meta_key'=>'_pcoe_broadcast_request','meta_value'=>$key]);
            if($old)return $old[0]->ID;
            $kind=(string)($input['kind']??'');$subject=trim(sanitize_text_field($input['subject']??''));$text=trim(sanitize_textarea_field($input['message']??''));
            if(!in_array($kind,['text','price','arrival'],true) || !$subject || !$text || mb_strlen($subject)>180 || mb_strlen($text)>10000)throw new \RuntimeException(__('Enter a subject and message, and select the mailing type.','pc-order-import-export'));
            $options=CommercialOffer::options($kind==='text'?[]:$input);
            $recipients=self::recipients($input);$catalogue=BroadcastSources::products($kind,$input);$groups=[];
            if($kind!=='text' && !$catalogue['ids'])throw new \RuntimeException(__('No published products are available for this mailing.','pc-order-import-export'));
            foreach($recipients['rows'] as $user=>&$recipient){
                $keyGroup=$kind==='text'?'':BroadcastPricing::group($user);$recipient['group']=$keyGroup;$recipient['pricing_context']=$keyGroup;
                if($keyGroup && !isset($groups[$keyGroup]))$groups[$keyGroup]=['user'=>$user,'count'=>0,'ready'=>false,
                    'label'=>implode(', ',get_userdata($user)->roles).' / '.get_user_locale($user)];
                if($keyGroup)$groups[$keyGroup]['count']++;
            }unset($recipient);
            $data=array_merge($catalogue,$options,['status'=>$groups?'preparing':'ready','subject'=>$subject,'message'=>$text,'kind'=>$kind,
                'actor'=>get_current_user_id(),'created_at'=>time(),'ready_at'=>$groups?0:time(),'recipients'=>$recipients['rows'],'skipped'=>$recipients['skipped'],'groups'=>$groups,'error'=>'']);
            $id=wp_insert_post(['post_type'=>BroadcastStore::TYPE,'post_status'=>'private','post_author'=>get_current_user_id(),'post_title'=>wp_slash($subject)],true);
            if(is_wp_error($id))throw new \RuntimeException(__('Mailing state could not be saved.','pc-order-import-export'));
            BroadcastStore::save($id,$data);update_post_meta($id,'_pcoe_broadcast_request',$key);
            if($groups)self::schedule($id);
            return $id;
        });
    }
    public static function schedule(int $id,int $delay=10): void {
        if(!wp_next_scheduled(self::HOOK,[$id]))wp_schedule_single_event(time()+$delay,self::HOOK,[$id]);
    }
    public static function control(int $id,string $operation): void {
        if(!current_user_can('manage_woocommerce'))throw new \RuntimeException('Forbidden');
        BroadcastStore::lock(static function()use($id,$operation){
            $d=BroadcastStore::get($id);
            if($operation==='pause' && $d['status']==='running')$d['status']='paused';
            elseif($operation==='cancel' && !in_array($d['status'],['finished','cancelled'],true))$d['status']='cancelled';
            elseif(in_array($operation,['start','resume','retry'],true)){
                $expected=['start'=>'ready','resume'=>'paused','retry'=>'finished'][$operation];
                if($d['status']!==$expected || time()-$d['ready_at']>DAY_IN_SECONDS)throw new \RuntimeException(__('The preview expired or the mailing changed. Create a new mailing.','pc-order-import-export'));
                if($operation==='retry'){
                    foreach($d['recipients'] as &$r)if($r['status']==='failed')$r['status']='pending';unset($r);
                }
                $d['status']='running';
            }else throw new \RuntimeException(__('This action is unavailable for the current mailing state.','pc-order-import-export'));
            BroadcastStore::save($id,$d);if($d['status']==='running')self::schedule($id);
        });
    }
    public static function tick(int $id): void {
        try{BroadcastStore::lock(static function()use($id){
            $d=BroadcastStore::get($id);
            if(!in_array($d['status'],['preparing','running'],true))return;
            // Schedule recovery before work: a fatal error must not strand the queue.
            self::schedule($id,60);
            if(!user_can($d['actor'],'manage_woocommerce')){$d['status']='paused';$d['error']=__('The sending manager no longer has access.','pc-order-import-export');BroadcastStore::save($id,$d);return;}
            if($d['status']==='preparing'){
                $started=microtime(true);$checked=0;$prepared=[];
                foreach($d['groups'] as $key=>$group)if($group['ready'] && !empty($group['signature']))$prepared[$group['signature']]=$key;
                foreach(array_keys($d['groups']) as $key){
                    $group=$d['groups'][$key];
                    if($group['ready'])continue;
                    if(!empty($group['building']))throw new \RuntimeException(__('File preparation was interrupted. Create a new mailing.','pc-order-import-export'));
                    $d['groups'][$key]['building']=true;BroadcastStore::save($id,$d);
                    if(!self::allowed($group['user']) || BroadcastPricing::group($group['user'])!==$key)throw new \RuntimeException(__('Customer pricing conditions changed. Create a new mailing.','pc-order-import-export'));
                    $file=BroadcastPricing::file($group['user'],$d['ids'],$d,$prepared);
                    if(isset($file['reuse'])){
                        $target=$file['reuse'];
                        // Preserve each recipient's context for the independent pre-send check.
                        foreach($d['recipients'] as &$recipient)if($recipient['group']===$key){
                            $recipient['pricing_context']=$recipient['pricing_context']??$key;$recipient['group']=$target;
                        }unset($recipient);
                        $d['groups'][$target]['count']+=$group['count'];unset($d['groups'][$key]);
                    }else{
                        BroadcastStore::put_file($id,$key,$file);
                        $d['groups'][$key]=array_merge($group,['building'=>false,'ready'=>true,'products'=>$file['count'],
                            'photos'=>$file['photos'],'at'=>$file['at'],'signature'=>$file['signature']]);
                        $prepared[$file['signature']]=$key;
                    }
                    BroadcastStore::save($id,$d);
                    if(++$checked>=10 || microtime(true)-$started>=20)break;
                }
                if(!array_filter($d['groups'],static fn($g)=>!$g['ready'])){$d['status']='ready';$d['ready_at']=time();}
                BroadcastStore::save($id,$d);
                if($d['status']==='preparing')self::schedule($id);
                return;
            }
            self::deliver($id,$d);
        });}catch(\Throwable $e){
            if($e->getCode()===423){self::schedule($id,60);return;}
            // Never erase a sending claim after an unknown failure. A later worker
            // will turn it into unknown instead of automatically repeating the mail.
            try{BroadcastStore::lock(static function()use($id,$e){
                $d=BroadcastStore::get($id);$d['error']=sanitize_text_field($e->getMessage());
                if($d['status']==='preparing')$d['status']='error';
                elseif($d['status']==='running')$d['status']='paused';
                BroadcastStore::save($id,$d);
            });}catch(\Throwable $ignored){self::schedule($id,60);}
        }
    }
    private static function deliver(int $id,array $d): void {
        $paths=[];$quota=get_option('_pcoe_broadcast_quota',[]);$window=(int)floor(time()/60);
        if(($quota['window']??0)!==$window)$quota=['window'=>$window,'used'=>0];
        try {
            foreach($d['recipients'] as $user=>&$recipient){
                if($recipient['status']==='sending'){$recipient['status']='unknown';continue;}
                if($recipient['status']!=='pending')continue;
                if($quota['used']>=5)break;
                $u=get_userdata($user);
                if(!self::allowed($user) || !$u || $u->user_email!==$recipient['email'] || ($recipient['group'] && BroadcastPricing::group($user)!==($recipient['pricing_context']??$recipient['group']))){
                    $recipient['status']='skipped';continue;
                }
                $attachments=[];
                if($recipient['group']){
                    $group=$recipient['group'];
                    if(!isset($paths[$group])){
                        $bytes=BroadcastStore::bytes(BroadcastStore::file($id,$group));
                        $path=BroadcastStore::temporary_file();
                        $paths[$group]=$path;
                        if(file_put_contents($path,$bytes)!==strlen($bytes))throw new \RuntimeException('Temporary file unavailable');
                    }
                    $attachments[(($d['format']??'price')==='offer'?'commercial-offer':($d['kind']==='arrival'?'arrival':'price-list')).'-'.gmdate('Y-m-d',$d['ready_at']).'.xlsx']=$paths[$group];
                }
                $headers=['Content-Type: text/plain; charset=UTF-8'];$manager=get_userdata($d['actor']);
                if($manager && self::email($manager->user_email))$headers[]='Reply-To: '.$manager->user_email;
                $switched=switch_to_locale(get_user_locale($user));
                try {
                    $body=$d['message'];
                    if($attachments)$body.="\n\n".__('Prices and stock in the attachment were captured when the mailing was prepared. Check current availability before ordering.','pc-order-import-export');
                    $body.="\n\n".__('Mailing preferences: sign in to your account to stop receiving news and price lists.','pc-order-import-export')."\n".wc_get_account_endpoint_url('edit-account').'#pcoe-mail-preferences';
                    $recipient['status']='sending';$recipient['at']=gmdate('c');BroadcastStore::save($id,$d);
                    $quota['used']++;update_option('_pcoe_broadcast_quota',$quota,false);
                    try{$recipient['status']=wp_mail($recipient['email'],$d['subject'],$body,$headers,$attachments)?'accepted':'failed';}
                    catch(\Throwable $e){$recipient['status']='unknown';}
                    BroadcastStore::save($id,$d);
                } finally {if($switched)restore_previous_locale();}
            }unset($recipient);
            if(!array_filter($d['recipients'],static fn($r)=>in_array($r['status'],['pending','sending'],true)))$d['status']='finished';
            BroadcastStore::save($id,$d);if($d['status']==='running')self::schedule($id,60);
        }finally{foreach($paths as $path)if(is_file($path))unlink($path);}
    }
    public static function preferences(): void {
        echo '<fieldset id="pcoe-mail-preferences"><legend>'.esc_html__('News and price lists','pc-order-import-export').'</legend><input type="hidden" name="pcoe_mail_preferences" value="1"><label><input type="checkbox" name="pcoe_mail_opt_out" value="1" '.checked((bool)get_user_meta(get_current_user_id(),self::OPT_OUT,true),true,false).'> '.esc_html__('Do not email me news and price lists. Order messages remain enabled.','pc-order-import-export').'</label></fieldset>';
    }
    public static function save_preferences(int $user): void {
        if($user===get_current_user_id() && isset($_POST['pcoe_mail_preferences']))update_user_meta($user,self::OPT_OUT,isset($_POST['pcoe_mail_opt_out'])?'1':'');
    }
}
