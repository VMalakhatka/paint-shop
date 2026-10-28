<?php
// Included by manager-workspace.php: disposable local fixtures; every HTTP/mail mocked.
use PaintCore\PCOE\ManagerQuickOrder as Quick;
use PaintCore\PCOE\OrderExecution as Execution;
use PaintCore\PCOE\ManagerWorkspace;
$quick_sources=[];
// Exercise persisted jobs from the old release without exposing a production flow selector.
$legacy_start=static function($draft,$customer,$input){
    $result=Quick::start($draft,$customer,$input);
    $fresh=wc_get_order($draft->get_id());$fresh->read_meta_data(true);
    $job=$fresh->get_meta(Quick::META,true);
    if(is_array($job) && isset($job['flow'])) {unset($job['flow']);$fresh->update_meta_data(Quick::META,$job);$fresh->save_meta_data();}
    return $result;
};
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
    wp_set_current_user($user->ID);$expect_error(fn()=>$legacy_start($draft,$user,$input));
    $check(!$quick_job($draft->get_id()),'Customer cannot start automatic processing');wp_set_current_user($manager_id);
    $expect_error(fn()=>$legacy_start($draft,$user,['revision'=>'old']));
    ob_start();Quick::render($draft);$html=ob_get_clean();
    if(getenv('PCOE_QUICK_UI_FIXTURE')) file_put_contents(getenv('PCOE_QUICK_UI_FIXTURE'),wp_json_encode(['html'=>$html]));
    $check(str_contains($html,'name="send_confirmation" value="1" checked'),'Confirmation checkbox is checked by default');
    $check(strpos($html,'name="send_confirmation"')<strpos($html,'button-primary'),'Confirmation checkbox precedes creation button');
    $sent=count($test_mails);$written=$writes;
    $legacy_start($draft,$user,$input);$first=$quick_job($draft->get_id());
    $legacy_start(wc_get_order($draft->get_id()),$user,$input);
    $check($quick_job($draft->get_id())['key']===$first['key'],'Double click keeps one operation');
    Quick::run($draft->get_id());$job=$quick_collect($draft->get_id());
    $check($job['status']==='complete','Unchecked preparation completes: '.wp_json_encode($job));
    $check($writes===$written+1 && count($test_mails)===$sent,'Unchecked confirmation sends no email and writes documents once: '.wp_json_encode(['writes'=>$writes-$written,'mails'=>array_column(array_slice($test_mails,$sent),'subject')]));
    Quick::run($draft->get_id());$check($writes===$written+1,'Repeated worker cannot create more documents');
    $family=Execution::family(wc_get_order($draft->get_id()));
    $check(count($family)>=3 && Execution::root(wc_get_order($job['orders'][0]))->get_id()===$draft->get_id(),'Original execution card includes work copy and split orders');
    $work=wc_get_order($job['work_order']);$check(!$work->get_meta('_folio_parent_order_id'),'Execution link does not fabricate a native accounting parent');

    $draft=$quick_fixture(); $written=$writes;$with_shortage=true;$mail_accept=false;
    $legacy_start($draft,$user,['revision'=>ManagerWorkspace::revision($draft),'send_confirmation'=>'1','warehouse_mode'=>'single','warehouse_id'=>$terms[0],'auxiliary'=>[903]]);
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

    $draft=$quick_fixture();$legacy_start($draft,$user,['revision'=>ManagerWorkspace::revision($draft),'send_confirmation'=>'1']);
    Quick::run($draft->get_id());$job=$quick_collect($draft->get_id());
    $sent=count($test_mails);$written=$writes;
    for($i=0;$i<count($job['orders'])+1;$i++)Quick::run($draft->get_id());
    $job=$quick_job($draft->get_id());
    $check($job['status']==='complete' && count($test_mails)===$sent+count($job['orders']),'One confirmation email per reserved order: '.wp_json_encode([$job,'count'=>count($test_mails)-$sent]));
    foreach($job['approvals'] as $notice)$check($notice['status']==='accepted','Mail service acceptance saved');
    Quick::run($draft->get_id());$check($writes===$written && count($test_mails)===$sent+count($job['orders']),'Repeat worker cannot resend completed mail');
    ob_start();Quick::render(wc_get_order($draft->get_id()));$html=ob_get_clean();$check(str_contains($html,'pcoe-quick-order'),'Completed result renders safely');

    $draft=$quick_fixture();$all_shortage=true;$sent=count($test_mails);
    $legacy_start($draft,$user,['revision'=>ManagerWorkspace::revision($draft),'send_confirmation'=>'1']);Quick::run($draft->get_id());
    $job=$quick_collect($draft->get_id());$check($job['status']==='complete' && !$job['orders'] && count($test_mails)===$sent,'All unavailable quantities create shortage without approval email: '.wp_json_encode($job));$all_shortage=false;

    $draft=$quick_fixture();$legacy_start($draft,$user,['revision'=>ManagerWorkspace::revision($draft)]);
    $draft->set_customer_note('changed');$draft->save();$written=$writes;Quick::run($draft->get_id());
    $check($quick_job($draft->get_id())['status']==='review' && $writes===$written,'Changed draft blocks processing');

    $draft=$quick_fixture();$http_failure=true;
    $legacy_start($draft,$user,['revision'=>ManagerWorkspace::revision($draft),'send_confirmation'=>'1']);Quick::run($draft->get_id());$job=$quick_collect($draft->get_id());
    $written=$writes;Quick::run($draft->get_id());
    $check($job['status']==='review' && $writes===$written,'Unknown document result never automatically retried');$http_failure=false;

    $draft=$quick_fixture();$legacy_start($draft,$user,['revision'=>ManagerWorkspace::revision($draft)]);
    $job=$quick_job($draft->get_id());$job['actor']=$user->ID;$draft->update_meta_data(Quick::META,$job);$draft->save_meta_data();
    $written=$writes;Quick::run($draft->get_id());$check($quick_job($draft->get_id())['status']==='review' && $writes===$written,'Revoked manager authority prevents execution');
    $draft=$quick_fixture();$legacy_start($draft,$user,['revision'=>ManagerWorkspace::revision($draft)]);
    update_user_meta($user->ID,'_folio_partner_short_name','OTHER');Quick::run($draft->get_id());update_user_meta($user->ID,'_folio_partner_short_name','TEST');
    $check($quick_job($draft->get_id())['status']==='review','Changed Folio link prevents execution');
    $non_order->update_meta_data('_pcoe_draft_purpose','preorder');$non_order->save_meta_data();
    $expect_error(fn()=>$legacy_start($non_order,$user,['revision'=>ManagerWorkspace::revision($non_order)]));
    ob_start();Quick::render($non_order);$preorder_html=ob_get_clean();
    $check(!str_contains($preorder_html,'value="quick_order"') && str_contains($preorder_html,esc_html__('Reconcile the existing preorder account before automatic conversion.','pc-order-import-export')),'Saved preorder explains reconciliation before showing a creation form');
    $non_order->update_meta_data('_pcoe_draft_purpose','draft');$non_order->save_meta_data();
    $check(Quick::eligible($non_order),'Existing ordinary non-accounting source remains eligible');
    $draft=$quick_fixture();$expect_error(fn()=>$legacy_start($draft,$user,['revision'=>ManagerWorkspace::revision($draft),'warehouse_mode'=>'single','warehouse_id'=>$terms[0],'auxiliary'=>[901]]));
    $expect_error(fn()=>$legacy_start($draft,$user,['revision'=>ManagerWorkspace::revision($draft),'warehouse_mode'=>'single','warehouse_id'=>$terms[0],'auxiliary'=>[999999]]));
    $check(!$quick_job($draft->get_id()),'Invalid warehouse choice is rejected before queue');
    $draft=$quick_fixture();$sent=count($test_mails);$written=$writes;
    $settings=['warehouse_mode'=>'manual','warehouse_id'=>$terms[1],'auxiliary'=>[903]];
    $prefs=Quick::preferences($settings);
    $check($prefs['manager_pool']['targetWarehouseId']===902,'Manual priority determines pool target');
    $payload=ManagerWorkspace::payload($draft,$user,'accounts',$prefs,wp_generate_uuid4());
    $check((int)$payload['items'][0]['allocations'][0]['folio_warehouses'][0]['id']===902,'Selected group remains first, not the configured shortage warehouse');
    $single=Quick::preferences(['warehouse_mode'=>'single','warehouse_id'=>$terms[1],'auxiliary'=>[903]]);
    $payload=ManagerWorkspace::payload($draft,$user,'accounts',$single,wp_generate_uuid4());
    $check(count($payload['items'][0]['allocations'][0]['folio_warehouses'])===1,'Single mode excludes other destinations');
    $preview=Quick::preview($draft,$user,$settings+['revision'=>ManagerWorkspace::revision($draft)]);
    $check(!empty($preview['quick_preview_html']) && !$quick_job($draft->get_id()) && $writes===$written && count($test_mails)===$sent,'Preview does not copy, queue, write or email');

    $draft=$quick_fixture();$with_waiting=true;
    $legacy_start($draft,$user,['revision'=>ManagerWorkspace::revision($draft),'warehouse_mode'=>'single','warehouse_id'=>$terms[0],'auxiliary'=>[903],'send_confirmation'=>'1']);
    Quick::run($draft->get_id());$job=$quick_collect($draft->get_id());
    $waiting=null;foreach($job['orders'] as $id) { $o=wc_get_order($id);if($o->get_meta('_folio_document_type')==='waiting_transfer_account')$waiting=$o; }
    $check(count($job['orders'])===2 && $waiting && $waiting->has_status('on-hold'),'Ready and waiting create separate Woo orders on the same warehouse');
    foreach($waiting->get_items() as $item)$check((bool)$item->get_meta('_pc_alloc_plan'),'Waiting order keeps target stock allocation');
    $check(Execution::source_type('waiting_transfer_account')==='ACCOUNT','Waiting account remains readable by execution control');
    for($i=0;$i<3;$i++)Quick::run($draft->get_id());
    $job=$quick_job($draft->get_id());
    $check($job['approvals'][$waiting->get_id()]['status']==='accepted','Waiting order can request confirmation without marking it ready');
    $data=(new ReflectionMethod(\PaintCore\PCOE\CustomerApproval::class,'data'))->invoke(null,get_post($job['approvals'][$waiting->get_id()]['approval']));
    $check(!empty($data['snapshot']['awaiting_transfer']),'Customer approval snapshot explicitly records waiting');
    $with_waiting=false;
    // Authenticated manager pool uses Lavka settings, not the independent checkout transport.
    $lavka_options=static fn()=>['java_base_url'=>'https://manager-test.invalid','api_token'=>'manager-test-token'];
    add_filter('pre_option_lps_options',$lavka_options);
    try {
        $draft=$quick_fixture();$preview_failure=true;$sent=count($test_mails);$written=$writes;
        $legacy_start($draft,$user,['revision'=>ManagerWorkspace::revision($draft),'warehouse_mode'=>'single','warehouse_id'=>$terms[0],'auxiliary'=>[903]]);
        Quick::run($draft->get_id());$job=$quick_collect($draft->get_id());$draft=wc_get_order($draft->get_id());
        $check(str_starts_with($last_manager_http['url'],'https://manager-test.invalid/') && $last_manager_http['token']==='manager-test-token','Pool uses configured Lavka authentication');
        $check(Quick::can_return_to_settings($draft) && $writes===$written && count($test_mails)===$sent,'Preflight failure may return to settings without writes or mail');
        $input=['revision'=>ManagerWorkspace::revision($draft),'operation_key'=>$job['key']];
        ob_start();Quick::render($draft);$recovery_html=ob_get_clean();
        $check(str_contains($recovery_html,'value="quick_settings"') && !str_contains($recovery_html,'value="quick_order"'),'Stopped preview offers settings, not another create');
        if(getenv('PCOE_QUICK_RECOVERY_FIXTURE'))file_put_contents(getenv('PCOE_QUICK_RECOVERY_FIXTURE'),wp_json_encode(['html'=>$recovery_html]));
        wp_set_current_user($user->ID);$expect_error(fn()=>Quick::return_to_settings($draft,$user,$input));wp_set_current_user($manager_id);
        $expect_error(fn()=>Quick::return_to_settings($draft,$user,array_merge($input,['operation_key'=>'stale'])));
        $work=wc_get_order($job['work_order']);$work->update_meta_data('_pcoe_manager_command',['status'=>'unknown']);$work->save_meta_data();
        $check(!Quick::can_return_to_settings($draft),'Attempted document command blocks settings recovery');
        $expect_error(fn()=>Quick::return_to_settings($draft,$user,$input));
        $work->delete_meta_data('_pcoe_manager_command');$work->update_meta_data('_folio_create_attempted_at',gmdate('c'));$work->save_meta_data();
        $check(!Quick::can_return_to_settings($draft),'Legacy attempted Folio write also blocks recovery');
        $work->delete_meta_data('_folio_create_attempted_at');$work->save_meta_data();
        $work->delete(false);$work=wc_get_order($job['work_order']);
        $check($work->has_status('trash') && !ManagerWorkspace::editable($work) && Quick::can_return_to_settings($draft),'Trashed untouched work copy allows source recovery without making the copy editable');
        $work->update_meta_data('_pcoe_manager_command',['status'=>'unknown']);$work->save_meta_data();
        $check(!Quick::can_return_to_settings($draft),'Trashing a work copy does not bypass an uncertain create command');
        $expect_error(fn()=>Quick::return_to_settings($draft,$user,$input));
        $work->delete_meta_data('_pcoe_manager_command');$work->update_meta_data('_folio_document_id',999991);$work->save_meta_data();
        $check(!Quick::can_return_to_settings($draft),'Trashed work copy with a saved Folio link stays blocked');
        $work->delete_meta_data('_folio_document_id');$work->save_meta_data();
        ob_start();Quick::render($draft);$recovery_html=ob_get_clean();
        $check(str_contains($recovery_html,'value="quick_settings"'),'Recovery button remains available after trashing an untouched work copy');
        if(getenv('PCOE_QUICK_RECOVERY_FIXTURE'))file_put_contents(getenv('PCOE_QUICK_RECOVERY_FIXTURE'),wp_json_encode(['html'=>$recovery_html]));
        Quick::return_to_settings($draft,$user,$input);$draft=wc_get_order($draft->get_id());
        $check(!$draft->get_meta(Quick::META) && isset($draft->get_meta(Quick::HISTORY)[$job['key']]),'Returning archives the exact stopped attempt before unlocking');
        $check(Quick::eligible($draft) && $writes===$written && count($test_mails)===$sent && wc_get_order($job['work_order']),'Settings restored without API writes, mail or deleting the work copy');
        $check(wc_get_order($job['work_order'])->has_status('trash'),'Recovery leaves discarded work copy in the trash');
        ob_start();Quick::render($draft);$restored_html=ob_get_clean();
        $check(str_contains($restored_html,'name="auxiliary[]"') && str_contains($restored_html,'value="quick_order"'),'Original draft regains warehouse selection after explicit recovery');
        Quick::run($draft->get_id());$check(!$draft->get_meta(Quick::META) && $writes===$written,'Old worker cannot restart after returning to settings');
    } finally {remove_filter('pre_option_lps_options',$lavka_options);$preview_failure=false;}
} finally {
    foreach($quick_sources as $id){
        $job=$quick_collect($id);
        foreach($job['approvals']??[] as $notice)if(!empty($notice['approval']))wp_delete_post($notice['approval'],true);
        as_unschedule_all_actions(Quick::JOB,[$id],Quick::GROUP);
    }
}
