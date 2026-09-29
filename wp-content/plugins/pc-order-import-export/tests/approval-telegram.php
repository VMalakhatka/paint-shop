<?php
/** Included by telegram.php with a mocked bot, temporary identities and blocked mail. */
use PaintCore\PCOE\ApprovalNotifications as Notices;
use PaintCore\PCOE\CustomerApproval as Approval;
use PaintCore\PCOE\TelegramStore as Store;
use PaintCore\PCOE\TelegramBridge as Bridge;
if(!defined('WP_CLI') || !WP_CLI || !isset($check,$users,$bot))throw new RuntimeException('Run through telegram.php');
$approval_id=0;$old_team=get_user_meta($customer,\PaintCore\PCOE\CustomerManagers::META,true);
try {
    update_user_meta($customer,\PaintCore\PCOE\CustomerManagers::META,['primary'=>$admin,'secondary'=>$manager]);
    $approval_data=['source'=>['customer_id'=>$customer,'order_id'=>0,'type'=>'ACCOUNT','document_id'=>42],'snapshot'=>['title'=>'Synthetic order confirmation','items'=>[],'total'=>'20','currency'=>'UAH'],
        'revision'=>hash('sha256','telegram-approval-test'),'status'=>'confirmed','confirmed_at'=>gmdate('c'),'requested_by'=>$admin,
        'preferences'=>['payment'=>['id'=>'bacs','label'=>'Bank'],'delivery'=>['id'=>'pickup','label'=>'Pickup'],'recipient'=>'Test','phone'=>'0000','destination'=>'Test address']];
    $approval_id=(new ReflectionMethod(Approval::class,'save'))->invoke(null,$approval_data);
    Notices::confirmed($approval_id);Notices::confirmed($approval_id);
    global $wpdb;$keys=$wpdb->get_col($wpdb->prepare('SELECT record_key FROM '.Store::table().' WHERE record_key LIKE %s','approval:'.$approval_id.':%'));
    $check(count($keys)===2,'One Telegram approval notice per assigned manager, including repeated confirmation');
    $admin_key=$manager_key='';foreach($keys as $key){$j=Store::get($key);if((int)$j['user_id']===$admin)$admin_key=$key;else $manager_key=$key;}
    $job=Store::get($admin_key);$check($job['data']['scope']==='approval' && $job['data']['context']['id']===$approval_id && str_contains($job['data']['payload']['reply_markup']['inline_keyboard'][0][0]['url'],'approval_id='.$approval_id),'Notification links to the saved approval with guarded context');
    $before=count($calls);Bridge::deliver($admin_key);Bridge::deliver($admin_key);
    $check(Store::get($admin_key)['status']==='sent' && count($calls)===$before+1,'Queued confirmation reaches mocked Telegram exactly once');
    update_user_meta($customer,\PaintCore\PCOE\CustomerManagers::META,['primary'=>$admin,'secondary'=>0]);
    $before=count($calls);Bridge::deliver($manager_key);
    $check(Store::get($manager_key)['status']==='cancelled' && count($calls)===$before,'Removed additional manager is blocked before transport');
    $copy=$job['data'];$copy['context']['revision']='obsolete';$key='approval-test-obsolete:'.$approval_id;Store::put($key,'job',$admin,'pending',$copy);
    Bridge::deliver($key);$check(Store::get($key)['status']==='cancelled','Old approval revision cannot be delivered');
} finally {
    if($approval_id)wp_delete_post($approval_id,true);
    if($old_team)update_user_meta($customer,\PaintCore\PCOE\CustomerManagers::META,$old_team);else delete_user_meta($customer,\PaintCore\PCOE\CustomerManagers::META);
}
