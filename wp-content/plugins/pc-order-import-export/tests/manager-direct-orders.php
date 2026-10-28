<?php
/** Included by manager-workspace.php: disposable customers, intercepted HTTP and email only. */
use PaintCore\PCOE\ManagerQuickOrder as DirectQuick;
use PaintCore\PCOE\ManagerWorkspace as DirectWorkspace;
use PaintCore\PCOE\OrderExecution as DirectExecution;
use PaintCore\PCOE\ManagerDraftOrders as DirectOrders;
if (!defined('WP_CLI') || !WP_CLI || !isset($check,$user,$writes)) throw new RuntimeException('Run through manager-workspace.php');

$direct_sources=[];
$direct_fixture=static function()use(&$orders,&$direct_sources,$order,$user){
    $draft=DirectWorkspace::quick_copy($order,$user,wp_generate_uuid4());
    $orders[]=$draft->get_id();$direct_sources[]=$draft->get_id();
    return $draft;
};
$direct_job=static function(int $id):array {
    $draft=wc_get_order($id);$draft->read_meta_data(true);
    $job=$draft->get_meta(DirectQuick::META,true);
    return is_array($job)?$job:[];
};
$direct_ids=static function()use($user):array {
    $ids=array_map('intval',wc_get_orders(['customer_id'=>$user->ID,'limit'=>-1,'return'=>'ids','status'=>array_keys(wc_get_order_statuses())]));
    sort($ids);return $ids;
};
$direct_snapshot=static function(WC_Order $draft):array {
    $draft->read_meta_data(true);$rows=[];
    foreach($draft->get_items() as $id=>$item)$rows[$id]=[$item->get_product_id(),$item->get_quantity(),$item->get_subtotal(),$item->get_total(),$item->get_meta('_pc_alloc_plan')];
    $saved=[];
    foreach(['_pcoe_manager_command','_folio_documents_result','_folio_document_id','_folio_document_number','_folio_document_payload_hash','_folio_create_payload','_folio_split_status','_folio_split_created_at'] as $key)$saved[$key]=$draft->get_meta($key,true);
    return [$draft->get_status(),$draft->get_parent_id(),$draft->get_meta('_pc_draft_title'),$draft->get_customer_note(),$draft->get_total(),$rows,$saved];
};
$direct_children=static function(array $job)use(&$orders):array {
    $ids=array_values(array_map('intval',$job['children']??[]));
    foreach($ids as $id)$orders[]=$id;
    return $ids;
};
$direct_input=static fn(WC_Order $draft,array $extra=[])=>$extra+['revision'=>DirectWorkspace::revision($draft),'warehouse_mode'=>'single','warehouse_id'=>$terms[0]];

try {
    // Even a single account must be a real child: the original request remains a draft.
    $draft=$direct_fixture();$before=$direct_snapshot($draft);$before_ids=$direct_ids();$written=$writes;$sent=count($test_mails);
    DirectQuick::start($draft,$user,$direct_input($draft));$initial=$direct_job($draft->get_id());
    DirectQuick::start(wc_get_order($draft->get_id()),$user,$direct_input($draft));
    $check(($initial['flow']??'')==='direct_children' && $direct_job($draft->get_id())['key']===$initial['key'],'New direct operation has one stable key after a double click');
    DirectQuick::run($draft->get_id());$job=$direct_job($draft->get_id());$children=$direct_children($job);
    $check($job['status']==='complete' && empty($job['work_order']) && count($children)===1,'Single warehouse produces one real child and no preparation copy');
    $created=array_values(array_diff($direct_ids(),$before_ids));sort($created);$sorted=$children;sort($sorted);
    $check($created===$sorted,'Only actual result orders appear in the customer register');
    $child=wc_get_order($children[0]);
    $check($child->has_status('processing') && $child->get_parent_id()===$draft->get_id() && $child->get_customer_id()===$user->ID,'Reserved order belongs directly to the original draft and customer');
    $check((int)$child->get_meta('_folio_document_id')===(int)$job['response']['documents'][0]['document_id'],'Reserved child links to its exact Folio account');
    $check($direct_snapshot(wc_get_order($draft->get_id()))===$before,'Original source lines, total, title, status and Folio metadata remain unchanged');
    $check($writes===$written+1 && count($test_mails)===$sent,'Creation writes once without ordinary Woo status emails');
    $check(count(DirectExecution::family(wc_get_order($draft->get_id())))===2 && DirectExecution::root($child)->get_id()===$draft->get_id(),'Execution tree contains only original request and actual result');
    foreach($child->get_items() as $item)$check((bool)$item->get_meta('_pc_alloc_plan'),'Direct reserved child keeps its warehouse stock plan');
    $after_ids=$direct_ids();DirectQuick::run($draft->get_id());
    $check($writes===$written+1 && $direct_ids()===$after_ids && count($test_mails)===$sent,'Repeated completed worker creates no duplicate orders, accounts or email');
    $guarded_source=wc_get_order($draft->get_id());$guarded_source->set_status('processing');
    $manual_result=pc_folio_create_documents_for_order_unlocked($guarded_source);
    add_filter('pc_folio_auto_checkout_enabled','__return_true',PHP_INT_MAX);
    try {pc_folio_auto_process_checkout_order_unlocked($guarded_source);}finally{remove_filter('pc_folio_auto_checkout_enabled','__return_true',PHP_INT_MAX);}
    $check(($manual_result['ok']??null)===false && $writes===$written+1 && !$guarded_source->get_meta('_folio_create_attempted_at') && !$guarded_source->get_meta('_folio_auto_status'),'Legacy manual and automatic creation reject a direct-operation source even if its status changes');
    $check($direct_snapshot(wc_get_order($draft->get_id()))===$before && $direct_ids()===$after_ids,'Shared legacy guard leaves source metadata and child register unchanged');

    // Source can already have an ordinary non-accounting document; never replace that evidence.
    $source=wc_get_order($non_order->get_id());$direct_sources[]=$source->get_id();$before=$direct_snapshot($source);
    DirectQuick::start($source,$user,$direct_input($source));DirectQuick::run($source->get_id());$job=$direct_job($source->get_id());$children=$direct_children($job);
    $check($job['status']==='complete' && count($children)===1 && empty($job['work_order']),'Ordinary non-accounting source can create direct reserved orders without copying');
    $check($direct_snapshot(wc_get_order($source->get_id()))===$before,'Existing non-accounting source document and original journal are preserved');

    // Ready, waiting-transfer and shortage results are distinct and all attach directly to the source.
    $draft=$direct_fixture();$with_shortage=true;$with_waiting=true;$before_ids=$direct_ids();$sent=count($test_mails);$written=$writes;
    DirectQuick::start($draft,$user,$direct_input($draft,['auxiliary'=>[903],'send_confirmation'=>'1']));
    DirectQuick::run($draft->get_id());$job=$direct_job($draft->get_id());$children=$direct_children($job);
    $check($job['status']==='approvals' && count($children)===3 && count($job['orders'])===2 && empty($job['work_order']),'Mixed result creates two real orders and one shortage draft, without intermediate copy');
    $check(count(array_diff($direct_ids(),$before_ids))===3 && count($test_mails)===$sent,'Only three result entries are created and no automatic Woo email bypasses confirmation');
    $docs=[];foreach($job['response']['documents'] as $doc)$docs[(int)$doc['document_id']]=$doc;
    $statuses=[];
    foreach($children as $id){
        $child=wc_get_order($id);$doc=$docs[(int)$child->get_meta('_folio_document_id')]??null;
        $check($doc && $child->get_parent_id()===$draft->get_id() && DirectExecution::root($child)->get_id()===$draft->get_id(),'Each result order has an exact account and direct source link');
        $kind=$doc['document_type'];$statuses[$kind]=$child->get_status();
        $expected=$doc['accounting_enabled']?(pc_folio_is_waiting_transfer_document($doc)?'on-hold':'processing'):'pc-draft';
        $check($child->has_status($expected),'Result status distinguishes ready, waiting transfer and shortage');
        $check(abs((float)$child->get_total()-array_sum(array_column($doc['items'],'amount')))<0.001,'Child totals contain only that account quantities');
        if(!$doc['accounting_enabled'])$check(!in_array($id,$job['orders'],true),'Shortage draft is excluded from customer confirmation queue');
    }
    $check($statuses['account']==='processing' && $statuses['waiting_transfer_account']==='on-hold' && $statuses['missing_stock_account']==='pc-draft','All three business document kinds have explicit statuses');
    $check((int)$job['payload']['folio_account_header']['warehouseId']===904 && $job['payload']['manager_stock_pool']['auxiliaryWarehouseIds']===[903],'Direct flow preserves configured shortage warehouse and additional stock selection');
    for($i=0;$i<3;$i++)DirectQuick::run($draft->get_id());$job=$direct_job($draft->get_id());
    $check($job['status']==='complete' && count($test_mails)===$sent+2 && count($job['approvals'])===2,'Only two accounted orders receive the requested customer confirmations');
    foreach($job['approvals'] as $id=>$notice){
        $check(in_array((int)$id,$job['orders'],true) && $notice['status']==='accepted','Confirmation result belongs to a real accounted child');
    }
    $mixed_source=wc_get_order($draft->get_id());
    wp_set_current_user($user->ID);
    try {
        ob_start();DirectOrders::customer_links($mixed_source);$customer_links=ob_get_clean();
        foreach($children as $id)$check(str_contains($customer_links,esc_url(wc_get_order($id)->get_view_order_url())),'Owner sees each direct result through a customer-account link');
        $check(!str_contains($customer_links,'wp-admin'),'Customer result links do not expose manager URLs');
        $child=wc_get_order($children[0]);ob_start();DirectOrders::customer_links($child);$source_link=ob_get_clean();
        $check(str_contains($source_link,esc_url($mixed_source->get_view_order_url())) && !str_contains($source_link,'wp-admin'),'Owner sees a customer-account backlink from real order to original draft');
        wp_set_current_user($other_id);ob_start();DirectOrders::customer_links($mixed_source);DirectOrders::customer_links($child);$foreign_links=ob_get_clean();
        $check($foreign_links==='','Another customer sees neither direct children nor source backlink');
        wp_set_current_user(0);ob_start();DirectOrders::customer_links($mixed_source);DirectOrders::customer_links($child);$guest_links=ob_get_clean();
        $check($guest_links==='','Anonymous visitor sees no customer order relationships');
    } finally {wp_set_current_user($manager_id);}
    if($fixture=getenv('PCOE_DIRECT_UI_FIXTURE')) {
        ob_start();DirectQuick::render($mixed_source);$manager_html=ob_get_clean();
        file_put_contents($fixture,wp_json_encode(['html'=>$manager_html,'customer_html'=>$customer_links,'source_id'=>$mixed_source->get_id(),'children'=>$children],JSON_UNESCAPED_UNICODE));
    }
    DirectQuick::run($draft->get_id());$check($writes===$written+1 && count($test_mails)===$sent+2,'Finished direct workflow cannot repeat its write or confirmation emails');
    $with_shortage=false;$with_waiting=false;

    $draft=$direct_fixture();$all_shortage=true;$before_ids=$direct_ids();$sent=count($test_mails);
    DirectQuick::start($draft,$user,$direct_input($draft,['send_confirmation'=>'1']));DirectQuick::run($draft->get_id());
    $job=$direct_job($draft->get_id());$children=$direct_children($job);
    $check($job['status']==='complete' && count($children)===1 && empty($job['orders']) && wc_get_order($children[0])->has_status('pc-draft'),'All-shortage result creates only a directly linked shortage draft');
    $check(count(array_diff($direct_ids(),$before_ids))===1 && count($test_mails)===$sent,'Unavailable goods do not create a reserved order or confirmation email');
    $all_shortage=false;

    // A rejected read-only preflight must leave no extra Woo documents at all.
    $draft=$direct_fixture();$preview_failure=true;$before_ids=$direct_ids();$written=$writes;$sent=count($test_mails);
    DirectQuick::start($draft,$user,$direct_input($draft,['auxiliary'=>[903]]));DirectQuick::run($draft->get_id());
    $job=$direct_job($draft->get_id());$draft=wc_get_order($draft->get_id());$direct_children($job);
    $check($job['status']==='review' && empty($job['work_order']) && empty($job['children']) && $direct_ids()===$before_ids,'Rejected preflight creates neither service copy nor result orders');
    $check(DirectQuick::can_return_to_settings($draft) && $writes===$written && count($test_mails)===$sent,'Direct preflight failure can safely return to settings');
    DirectQuick::return_to_settings($draft,$user,['revision'=>DirectWorkspace::revision($draft),'operation_key'=>$job['key']]);
    $draft=wc_get_order($draft->get_id());
    $check(!$draft->get_meta(DirectQuick::META) && isset($draft->get_meta(DirectQuick::HISTORY)[$job['key']]) && $direct_ids()===$before_ids,'Returning archives failed direct check without manufacturing or deleting Woo records');
    $preview_failure=false;

    // Unknown accounting write is never reinterpreted as a preflight failure.
    $draft=$direct_fixture();$http_failure=true;$before_ids=$direct_ids();$written=$writes;$sent=count($test_mails);
    DirectQuick::start($draft,$user,$direct_input($draft,['send_confirmation'=>'1']));DirectQuick::run($draft->get_id());
    $job=$direct_job($draft->get_id());$draft=wc_get_order($draft->get_id());$direct_children($job);
    $check($job['status']==='review' && $writes===$written+1 && $direct_ids()===$before_ids,'Unknown create result is saved without inventing successful Woo children');
    $check(!DirectQuick::can_return_to_settings($draft),'Unknown Folio write cannot clear the source operation');
    $expect_error(fn()=>DirectQuick::return_to_settings($draft,$user,['revision'=>DirectWorkspace::revision($draft),'operation_key'=>$job['key']]));
    DirectQuick::run($draft->get_id());DirectQuick::start($draft,$user,$direct_input($draft));DirectQuick::run($draft->get_id());
    $check($writes===$written+1 && count($test_mails)===$sent && $direct_job($draft->get_id())['key']===$job['key'],'Retrying unknown operation cannot resend accounts or email');
    $http_failure=false;

    // If Woo saved an ID and then failed, retain the actual child and stop rather than retrying Folio.
    $draft=$direct_fixture();$before=$direct_snapshot($draft);$before_ids=$direct_ids();$written=$writes;$sent=count($test_mails);$failed_child_id=0;
    $fail_local_save=static function($saved)use(&$failed_child_id){
        if(!$failed_child_id && $saved instanceof WC_Order && $saved->get_id() && $saved->get_created_via()==='pcoe-manager-order') {
            // Woo catches Exception inside save(); Error simulates an interrupted worker after persistence.
            $failed_child_id=$saved->get_id();throw new Error('Simulated failure after first direct child save');
        }
    };
    add_action('woocommerce_after_order_object_save',$fail_local_save,PHP_INT_MAX);
    try {
        DirectQuick::start($draft,$user,$direct_input($draft,['send_confirmation'=>'1']));DirectQuick::run($draft->get_id());
    } finally {remove_action('woocommerce_after_order_object_save',$fail_local_save,PHP_INT_MAX);}
    $job=$direct_job($draft->get_id());$draft=wc_get_order($draft->get_id());$children=$direct_children($job);
    $check($failed_child_id>0 && $job['status']==='review' && !empty($job['response']) && $writes===$written+1,'Local save failure retains the known Folio result for review: '.wp_json_encode(['failed_child'=>$failed_child_id,'status'=>$job['status']??null,'response'=>!empty($job['response']),'writes'=>$writes-$written,'error'=>$job['error']??null]));
    $check($children===[$failed_child_id] && array_values(array_diff($direct_ids(),$before_ids))===[$failed_child_id],'Saved child ID is journaled instead of leaving an unidentified duplicate');
    $failed_child=wc_get_order($failed_child_id);
    $check($failed_child->get_parent_id()===$draft->get_id() && (int)$failed_child->get_meta('_folio_document_id')===(int)$job['response']['documents'][0]['document_id'],'Partially finalized child retains its source and exact Folio account');
    $check(DirectExecution::root($failed_child)->get_id()===$draft->get_id() && $direct_snapshot($draft)===$before,'Partial local failure preserves source data and execution family');
    $check(!DirectQuick::can_return_to_settings($draft) && count($test_mails)===$sent,'Known accounting write with local failure cannot reset settings or email as completed');
    $after_ids=$direct_ids();DirectQuick::run($draft->get_id());DirectQuick::start($draft,$user,$direct_input($draft));DirectQuick::run($draft->get_id());
    $check($writes===$written+1 && $direct_ids()===$after_ids && count($test_mails)===$sent,'Review after partial child save never repeats Folio or creates another child');

    // Recheck customer pricing after preflight, before the durable write marker and accounting request.
    $draft=$direct_fixture();$before_ids=$direct_ids();$written=$writes;$price_raced=false;
    $race_price=static function($response,$args,$url)use(&$price_raced,$product){
        if(!$price_raced && (str_contains($url,'/admin/folio/order-accounts') || str_contains($url,'/admin/folio/manager-order-accounts'))) {
            $payload=json_decode($args['body']??'',true);$payload=$payload['order']??$payload;
            if(!empty($payload['preview_only'])) {$price_raced=true;update_post_meta($product->get_id(),'_wpc_price_role_opt','76');}
        }
        return $response;
    };
    add_filter('pre_http_request',$race_price,PHP_INT_MAX,3);
    try {
        DirectQuick::start($draft,$user,$direct_input($draft));DirectQuick::run($draft->get_id());
    } finally {remove_filter('pre_http_request',$race_price,PHP_INT_MAX);update_post_meta($product->get_id(),'_wpc_price_role_opt','75');}
    $job=$direct_job($draft->get_id());$direct_children($job);
    $check($price_raced && $job['status']==='review' && empty($job['write_started_at']) && $writes===$written && $direct_ids()===$before_ids,'Price change during preflight stops before any new account or Woo order');
} finally {
    $preview_failure=false;$http_failure=false;$with_shortage=false;$with_waiting=false;$all_shortage=false;
    // Collect all synthetic customer orders, including any child saved just before a failed assertion.
    foreach($direct_ids() as $id)$orders[]=$id;
    foreach($direct_sources as $id){
        $job=$direct_job($id);$direct_children($job);
        foreach($job['approvals']??[] as $notice)if(!empty($notice['approval']))wp_delete_post($notice['approval'],true);
        as_unschedule_all_actions(DirectQuick::JOB,[$id],DirectQuick::GROUP);
    }
}
