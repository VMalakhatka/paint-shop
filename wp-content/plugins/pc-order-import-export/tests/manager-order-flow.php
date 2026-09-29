<?php
/** Included by manager-workspace.php with temporary fixtures and blocked HTTP/mail. */
use PaintCore\PCOE\ManagerOrderFlow as Flow;
use PaintCore\PCOE\ManagerWorkspace as Workspace;
if(!defined('WP_CLI') || !WP_CLI || !isset($check,$with_shortage))throw new RuntimeException('Run through manager-workspace.php');
$check(Flow::non_accounting_only($non_order),'Completed non-accounting draft has a recovery action');
$check($non_preview['mode']==='non_accounting' && $non_preview['confirmation_label']===__('I understand that the whole list will be saved without checking or reserving stock and will remain a Woo draft.','pc-order-import-export'),'Non-accounting confirmation explains stock is not checked');
$expect_error(fn()=>Workspace::payload($non_order,$user,'non_accounting',['mode'=>'single','term_id'=>$terms[0]],wp_generate_uuid4()));
// One website group can contain several real Folio warehouses; preserve the entire group.
update_term_meta($terms[0],'lavka_folio_warehouses',[['id'=>'901','priority'=>1],['id'=>'903','priority'=>2]]);
$group=Workspace::payload($non_order,$user,'accounts',['mode'=>'single','term_id'=>$terms[0]],wp_generate_uuid4());
$check($group['folio_account_header']['accountingEnabled']===true && $group['woo_order']['status']==='on-hold','Order action sends accounting status independently of draft status');
foreach($group['items'] as $line)foreach($line['allocations'] as $allocation)$check($allocation['woo_location_id']===$terms[0] && array_column($allocation['folio_warehouses'],'id')===['901','903'],'Selected group retains both Folio warehouse candidates');
update_term_meta($terms[0],'lavka_folio_warehouses',[['id'=>'901','priority'=>1]]);
$GLOBALS['PC_ALLOW_STOCK_WRITE']=true;foreach($terms as $tid)update_post_meta($product->get_id(),'_stock_at_'.$tid,'10');unset($GLOBALS['PC_ALLOW_STOCK_WRITE']);
// Saved non-accounting work can be copied without altering the source or reusing its command.
$_POST=['request_key'=>wp_generate_uuid4()];$new=$invoke('copy',$non_order,$user);parse_str(wp_parse_url($new['url'],PHP_URL_QUERY),$ids);
$flow_id=(int)$ids['order_id'];$orders[]=$flow_id;$flow_order=wc_get_order($flow_id);
$check(Workspace::editable($flow_order) && (int)$flow_order->get_meta('_pcoe_source_order_id')===$non_order->get_id() && Flow::non_accounting_only(wc_get_order($non_order->get_id())),'Recovery copies lines and keeps saved operation intact');
$with_shortage=true;$_POST=['mode'=>'accounts','warehouse_mode'=>'auto','warehouse_id'=>0];
$flow_preview=$invoke('preview',$flow_order,$user);
$check($flow_preview['can_apply'] && $flow_preview['mode']==='accounts','Stock order has its own apply action');
$token=$flow_preview['token'];$flow_saved=get_transient('pcoe_manager_preview_'.$token);
$check(count($flow_saved['response']['documents'])===3,'Preview separates two reserved orders and shortage');
// No-stock previews may be viewed, but cannot be submitted as a reserved customer order.
$zero=$flow_saved;foreach($zero['response']['documents'] as &$doc)$doc['accounting_enabled']=false;unset($doc);
set_transient('pcoe_manager_preview_'.$token,$zero,15*MINUTE_IN_SECONDS);
$before_writes=$writes;$_POST=['mode'=>'accounts','token'=>$token,'confirmation'=>'1'];
$expect_error(fn()=>$invoke('apply',$flow_order,$user));
$check($writes===$before_writes && !Workspace::command(wc_get_order($flow_id)),'Zero reservation blocks before any financial write or command');
set_transient('pcoe_manager_preview_'.$token,$flow_saved,15*MINUTE_IN_SECONDS);
$invoke('apply',$flow_order,$user);$flow_order=wc_get_order($flow_id);$with_shortage=false;
$flow_children=(array)$flow_order->get_meta('_folio_child_order_ids');
foreach($flow_children as $child)$orders[]=$child;
$check(count($flow_children)===3 && $flow_order->has_status('pc-draft'),'Mixed result keeps parent and three real child orders');
ob_start();Flow::orders($flow_order,pc_folio_get_order_documents_result($flow_order));$flow_html=ob_get_clean();
$check(substr_count($flow_html,'page=pcoe-approvals')===2,'Only reserved orders offer customer confirmation');
foreach($flow_children as $child_id){$child=wc_get_order($child_id);if($child->get_meta('_folio_split_document_kind')==='missing_stock')$check(!str_contains($flow_html,'page=pcoe-approvals&amp;order_id='.$child_id),'Shortage is not presented as a ready-to-confirm reserved order');}
wp_set_current_user($customer_id);ob_start();Flow::orders($flow_order,pc_folio_get_order_documents_result($flow_order));$check(ob_get_clean()==='','Manager result actions are not exposed to customers');wp_set_current_user($manager_id);

if ($ui_fixture=getenv('PCOE_ORDER_FLOW_UI_FIXTURE')) {
    // Export only synthetic order forms and results for an offline browser check.
    $_POST=['request_key'=>wp_generate_uuid4()];$ui_copy=$invoke('copy',$non_order,$user);
    parse_str(wp_parse_url($ui_copy['url'],PHP_URL_QUERY),$ui_ids);$orders[]=(int)$ui_ids['order_id'];
    $_GET=['customer_id'=>$customer_id,'order_id'=>$ui_ids['order_id']];
    ob_start();Workspace::render();$ui_markup=ob_get_clean();
    $dom=new DOMDocument();libxml_use_internal_errors(true);$dom->loadHTML('<?xml encoding="UTF-8">'.$ui_markup);libxml_clear_errors();
    $section=(new DOMXPath($dom))->query('//*[@id="pcoe-place-order"]')->item(0);
    if(!$section)throw new RuntimeException('Missing real order preparation form');
    $ui_html='<div class="wrap pcoe-manager"><h1>Оформлення замовлення — тест</h1><div id="pcoe-manager-status" role="status" aria-live="polite"></div>'.$dom->saveHTML($section).'</div>';
    $ui_data=['accounts'=>$flow_preview,'non_accounting'=>$non_preview];
    foreach($ui_data as &$preview){$preview['token']='offline-preview';$preview['message']='Перегляд готовий';}unset($preview);
    file_put_contents($ui_fixture,wp_json_encode(['html'=>$ui_html,'responses'=>$ui_data,'result'=>$flow_html,'group_id'=>$terms[0]],JSON_UNESCAPED_UNICODE));

}
