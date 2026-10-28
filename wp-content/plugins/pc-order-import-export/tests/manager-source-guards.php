<?php
// Included by manager-workspace.php; no external calls, production data or mail.
$source_guard = wc_create_order(['status'=>'pc-draft','customer_id'=>$user->ID]);
$orders[]=$source_guard->get_id();
$source_guard_actor=get_current_user_id();
$source_guard_require=new ReflectionMethod(\PaintCore\PCOE\DraftFolioWorkflow::class,'require_draft');
$source_guard_die=static fn()=>static function($message,$title='',$args=[]){
    throw new RuntimeException((string)$message,(int)($args['response']??0));
};
add_filter('wp_die_handler',$source_guard_die,PHP_INT_MAX);
try {
    wp_set_current_user($user->ID);
    $check($source_guard_require->invoke(null,$source_guard->get_id())->get_id()===$source_guard->get_id(),'Fresh source draft remains available to its owner');
    foreach(['queued','running','review','approvals','complete'] as $source_guard_status){
        $source_guard->update_meta_data('_pcoe_quick_order',['status'=>$source_guard_status]);$source_guard->save_meta_data();
        $source_guard_denied=false;
        try{$source_guard_require->invoke(null,$source_guard->get_id());}
        catch(RuntimeException $e){if($e->getCode()!==409)throw $e;$source_guard_denied=true;}
        $check($source_guard_denied,'Customer cart/remainder and non-accounting actions reject quick source status '.$source_guard_status);
        ob_start();\PaintCore\PCOE\DraftFolioWorkflow::render($source_guard);$source_guard_html=ob_get_clean();
        $check(!str_contains($source_guard_html,'value="pcoe_draft_folio_preview"'),'Customer source view hides processing forms for '.$source_guard_status);
        $source_guard_actions=apply_filters('woocommerce_my_account_my_orders_actions',[],$source_guard);
        $check(!isset($source_guard_actions['pcoe-draft-to-cart']),'Customer order list hides processing shortcut for '.$source_guard_status);
    }
    $source_guard->update_meta_data('_pcoe_quick_order_history',['test'=>['status'=>'review']]);
    $source_guard->delete_meta_data('_pcoe_quick_order');$source_guard->save_meta_data();
    $check($source_guard_require->invoke(null,$source_guard->get_id())->get_id()===$source_guard->get_id(),'Archived preflight history alone does not block an unchanged draft');
    ob_start();\PaintCore\PCOE\DraftFolioWorkflow::render($source_guard);$source_guard_html=ob_get_clean();
    $check(str_contains($source_guard_html,'value="pcoe_draft_folio_preview"'),'Processing forms return after explicit safe preflight reset');
    $source_guard_actions=apply_filters('woocommerce_my_account_my_orders_actions',[],$source_guard);
    $check(isset($source_guard_actions['pcoe-draft-to-cart']),'Customer processing shortcut returns after explicit safe reset');
} finally {
    remove_filter('wp_die_handler',$source_guard_die,PHP_INT_MAX);
    wp_set_current_user($source_guard_actor);
}
