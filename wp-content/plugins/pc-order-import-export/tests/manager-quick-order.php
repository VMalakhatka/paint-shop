<?php
// Included by manager-workspace.php: disposable local fixtures; every HTTP/mail mocked.
use PaintCore\PCOE\ManagerQuickOrder as Quick;
use PaintCore\PCOE\OrderExecution as Execution;
use PaintCore\PCOE\ManagerWorkspace;
$quick_sources=[];
$quick_fixture=static function()use(&$orders,&$quick_sources,$user,$order){
    $draft=ManagerWorkspace::quick_copy($order,$user,wp_generate_uuid4());
    $orders[]=$draft->get_id();$quick_sources[]=$draft->get_id();return $draft;
};
$quick_job=static function($id){$o=wc_get_order($id);$o->read_meta_data(true);return $o->get_meta(Quick::META,true);};
$quick_collect=static function($id)use(&$orders,$quick_job){
    $job=$quick_job($id);if(!empty($job['work_order'])){
        $orders[]=$job['work_order'];$o=wc_get_order($job['work_order']);
        foreach((array)$o->get_meta('_folio_child_order_ids') as $child)$orders[]=(int)$child;
    }return $job;
};
try {
    $draft=$quick_fixture(); $input=['revision'=>ManagerWorkspace::revision($draft)];
    wp_set_current_user($user->ID);$expect_error(fn()=>Quick::start($draft,$user,$input));
    $check(!$quick_job($draft->get_id()),'Customer cannot start automatic processing');wp_set_current_user($manager_id);
    $expect_error(fn()=>Quick::start($draft,$user,['revision'=>'old']));
    ob_start();Quick::render($draft);$html=ob_get_clean();
    if(getenv('PCOE_QUICK_UI_FIXTURE')) file_put_contents(getenv('PCOE_QUICK_UI_FIXTURE'),wp_json_encode(['html'=>$html]));
    $check(str_contains($html,'name="send_confirmation" value="1" checked'),'Confirmation checkbox is checked by default');
    $check(strpos($html,'name="send_confirmation"')<strpos($html,'button-primary'),'Confirmation checkbox precedes creation button');
    $sent=count($test_mails);$written=$writes;
    Quick::start($draft,$user,$input);$first=$quick_job($draft->get_id());
    Quick::start(wc_get_order($draft->get_id()),$user,$input);
    $check($quick_job($draft->get_id())['key']===$first['key'],'Double click keeps one operation');
    Quick::run($draft->get_id());$job=$quick_collect($draft->get_id());
    $check($job['status']==='complete','Unchecked preparation completes: '.wp_json_encode($job));
    $check($writes===$written+1 && count($test_mails)===$sent,'Unchecked confirmation sends no email and writes documents once: '.wp_json_encode(['writes'=>$writes-$written,'mails'=>array_column(array_slice($test_mails,$sent),'subject')]));
    Quick::run($draft->get_id());$check($writes===$written+1,'Repeated worker cannot create more documents');
    $family=Execution::family(wc_get_order($draft->get_id()));
    $check(count($family)>=3 && Execution::root(wc_get_order($job['orders'][0]))->get_id()===$draft->get_id(),'Original execution card includes work copy and split orders');
    $work=wc_get_order($job['work_order']);$check(!$work->get_meta('_folio_parent_order_id'),'Execution link does not fabricate a native accounting parent');

    $draft=$quick_fixture(); $written=$writes;$with_shortage=true;$mail_accept=false;
    Quick::start($draft,$user,['revision'=>ManagerWorkspace::revision($draft),'send_confirmation'=>'1','pool_target'=>901,'auxiliary'=>[903]]);
    Quick::run($draft->get_id());$job=$quick_collect($draft->get_id());
    $check($job['status']==='approvals' && count($job['orders'])===1,'Only reserved pooled child enters approval queue: '.wp_json_encode($job));
    $command=wc_get_order($job['work_order'])->get_meta('_pcoe_manager_command');
    $check($command['payload']['manager_stock_pool']['auxiliaryWarehouseIds']===[903],'Selected pool reaches dedicated Java command');
    $check((int)$command['payload']['folio_account_header']['warehouseId']===904,'Shortage retains configured non-accounting warehouse');
    Quick::run($draft->get_id());$job=$quick_job($draft->get_id());
    $notice=reset($job['approvals']);
    $check($notice['status']==='failed','Mail failure recorded independently: '.wp_json_encode($job));
    $check($writes===$written+1,'Mail failure does not recreate documents');
    $mail_accept=true;$with_shortage=false;

    $draft=$quick_fixture();Quick::start($draft,$user,['revision'=>ManagerWorkspace::revision($draft),'send_confirmation'=>'1']);
    Quick::run($draft->get_id());$job=$quick_collect($draft->get_id());
    $sent=count($test_mails);$written=$writes;
    for($i=0;$i<count($job['orders'])+1;$i++)Quick::run($draft->get_id());
    $job=$quick_job($draft->get_id());
    $check($job['status']==='complete' && count($test_mails)===$sent+count($job['orders']),'One confirmation email per reserved order: '.wp_json_encode([$job,'count'=>count($test_mails)-$sent]));
    foreach($job['approvals'] as $notice)$check($notice['status']==='accepted','Mail service acceptance saved');
    Quick::run($draft->get_id());$check($writes===$written && count($test_mails)===$sent+count($job['orders']),'Repeat worker cannot resend completed mail');
    ob_start();Quick::render(wc_get_order($draft->get_id()));$html=ob_get_clean();$check(str_contains($html,'pcoe-quick-order'),'Completed result renders safely');

    $draft=$quick_fixture();$all_shortage=true;$sent=count($test_mails);
    Quick::start($draft,$user,['revision'=>ManagerWorkspace::revision($draft),'send_confirmation'=>'1']);Quick::run($draft->get_id());
    $job=$quick_collect($draft->get_id());$check($job['status']==='complete' && !$job['orders'] && count($test_mails)===$sent,'All unavailable quantities create shortage without approval email: '.wp_json_encode($job));$all_shortage=false;

    $draft=$quick_fixture();Quick::start($draft,$user,['revision'=>ManagerWorkspace::revision($draft)]);
    $draft->set_customer_note('changed');$draft->save();$written=$writes;Quick::run($draft->get_id());
    $check($quick_job($draft->get_id())['status']==='review' && $writes===$written,'Changed draft blocks processing');

    $draft=$quick_fixture();$http_failure=true;
    Quick::start($draft,$user,['revision'=>ManagerWorkspace::revision($draft),'send_confirmation'=>'1']);Quick::run($draft->get_id());$job=$quick_collect($draft->get_id());
    $written=$writes;Quick::run($draft->get_id());
    $check($job['status']==='review' && $writes===$written,'Unknown document result never automatically retried');$http_failure=false;

    $draft=$quick_fixture();Quick::start($draft,$user,['revision'=>ManagerWorkspace::revision($draft)]);
    $job=$quick_job($draft->get_id());$job['actor']=$user->ID;$draft->update_meta_data(Quick::META,$job);$draft->save_meta_data();
    $written=$writes;Quick::run($draft->get_id());$check($quick_job($draft->get_id())['status']==='review' && $writes===$written,'Revoked manager authority prevents execution');
    $draft=$quick_fixture();Quick::start($draft,$user,['revision'=>ManagerWorkspace::revision($draft)]);
    update_user_meta($user->ID,'_folio_partner_short_name','OTHER');Quick::run($draft->get_id());update_user_meta($user->ID,'_folio_partner_short_name','TEST');
    $check($quick_job($draft->get_id())['status']==='review','Changed Folio link prevents execution');
    $non_order->update_meta_data('_pcoe_draft_purpose','preorder');$non_order->save_meta_data();
    $expect_error(fn()=>Quick::start($non_order,$user,['revision'=>ManagerWorkspace::revision($non_order)]));
    $non_order->update_meta_data('_pcoe_draft_purpose','draft');$non_order->save_meta_data();
    $check(Quick::eligible($non_order),'Existing ordinary non-accounting source remains eligible');
    $draft=$quick_fixture();$expect_error(fn()=>Quick::start($draft,$user,['revision'=>ManagerWorkspace::revision($draft),'pool_target'=>901,'auxiliary'=>[901]]));
    $expect_error(fn()=>Quick::start($draft,$user,['revision'=>ManagerWorkspace::revision($draft),'pool_target'=>901,'auxiliary'=>[999999]]));
    $check(!$quick_job($draft->get_id()),'Invalid warehouse choice is rejected before queue');
} finally {
    foreach($quick_sources as $id){
        $job=$quick_collect($id);
        foreach($job['approvals']??[] as $notice)if(!empty($notice['approval']))wp_delete_post($notice['approval'],true);
        as_unschedule_all_actions(Quick::JOB,[$id],Quick::GROUP);
    }
}
