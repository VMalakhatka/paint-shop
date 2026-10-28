<?php
// Included by order-execution-local.php; same synthetic users/orders and HTTP/mail interception.
use PaintCore\PCOE\OrderExecutionRoadmap as Roadmap;
use PaintCore\PCOE\OrderExecution as Execution;
use PaintCore\PCOE\OrderExecutionUi as Ui;
wp_set_current_user($admin);
$route=$makeOrder($customer);$route_id=$route->get_id();$read_count=count($calls);$mail_count=$mails;
$route_input=['manager'=>$admin,'stage'=>'intake','stage_status'=>'in_progress','stage_detail'=>'Warehouse A','due'=>'2026-11-01T15:00','reason'=>'Verified source record','active'=>0];
$deny(fn()=>Execution::save($route_id,'',array_merge($route_input,['stage'=>''])));
$deny(fn()=>Execution::save($route_id,'',array_merge($route_input,['stage'=>'invented-stage'])));
$deny(fn()=>Execution::save($route_id,'',array_merge($route_input,['stage_status'=>'invented-status'])));
$deny(fn()=>Execution::save($route_id,'',array_merge($route_input,['reason'=>''])));
$deny(fn()=>Execution::save($route_id,'',array_merge($route_input,['stage_status'=>'skipped'])));
$r=Execution::save($route_id,'',$route_input);
$check($r['roadmap']['current']==='intake' && $r['roadmap']['stages']['intake']['status']==='in_progress','Selected stage is stored as a stable ID');
$check($r['next']===Roadmap::label('intake') && end($r['events'])['details']['stage_update']['stage']==='intake','Summary and audit identify the stage');
$deny(fn()=>Execution::save($route_id,$r['revision'],array_merge($route_input,['stage'=>'close','stage_status'=>'completed'])));
$r=Execution::save($route_id,$r['revision'],array_merge($route_input,['stage'=>'packing']));
$check($r['roadmap']['stages']['intake']['status']==='in_progress' && !isset($r['roadmap']['stages']['picking']),'Jumping to a later stage does not complete earlier stages');
$r=Execution::save($route_id,$r['revision'],array_merge($route_input,['stage'=>'wait_stock']));$stale=$r['revision'];
$r=Execution::save($route_id,$r['revision'],array_merge($route_input,['stage'=>'wait_stock','stage_status'=>'completed']));
$check($r['roadmap']['current']==='prepare_orders' && $r['due']==='' && $r['roadmap']['stages']['wait_stock']['due']!=='' ,'Completion suggests the next required stage and requires its own agreed deadline');
$deny(fn()=>Execution::save($route_id,$stale,array_merge($route_input,['stage'=>'return'])));
$r=Execution::save($route_id,$r['revision'],array_merge($route_input,['stage'=>'shipment','stage_status'=>'skipped','reason'=>'Customer pickup']));
$check($r['roadmap']['stages']['shipment']['status']==='skipped' && $r['roadmap']['stages']['shipment']['reason']==='Customer pickup','Optional skip preserves its specific reason');
$r=Execution::save($route_id,$r['revision'],array_merge($route_input,['stage'=>'claim']));
foreach(Roadmap::stages() as $id=>$stage) if (!$stage['optional'] && $id!=='close') $r=Execution::save($route_id,$r['revision'],array_merge($route_input,['stage'=>$id,'stage_status'=>'completed']));
$deny(fn()=>Execution::save($route_id,$r['revision'],array_merge($route_input,['stage'=>'close','stage_status'=>'completed'])));
$r=Execution::save($route_id,$r['revision'],array_merge($route_input,['stage'=>'claim','stage_status'=>'completed','reason'=>'Resolution completed; correction linked']));
$r=Execution::save($route_id,$r['revision'],array_merge($route_input,['stage'=>'close','stage_status'=>'completed']));
$check(!empty($r['roadmap']['closed_at']) && $r['roadmap']['stages']['close']['status']==='completed','Closure requires completed core stages and resolved opened branches');
$check(!isset($r['roadmap']['stages']['return']) && !isset($r['roadmap']['stages']['box_passports']),'Unused optional stages do not require fabricated completion');
$check(wc_get_order($route_id)->has_status('pending') && count($calls)===$read_count && $mails===$mail_count,'Roadmap marks do not alter Woo status, call external services or send messages');
$old_events=count($r['events']);$r=Execution::save($route_id,$r['revision'],array_merge($route_input,['stage'=>'return','reason'=>'Customer requested return']));
$check(empty($r['roadmap']['closed_at']) && empty($r['roadmap']['stages']['close']) && count($r['events'])===$old_events+1,'Reopening a route preserves audit and requires a new closure');
$before=$r['roadmap'];$r=Execution::save($route_id,$r['revision'],array_merge($route_input,['stage'=>'return','active'=>1]));
$before=$r['roadmap'];wp_set_current_user(0);Execution::observe($route_id);$r=$state($route_id);
$check($r['roadmap']===$before,'Source observation does not automatically complete manual stages');
wp_set_current_user($admin);
ob_start();Ui::render(wc_get_order($route_id));$route_html=ob_get_clean();
$check(str_contains($route_html,'name="stage"') && !str_contains($route_html,'name="next"') && str_contains($route_html,'data-roadmap-scenario="return"'),'UI uses a stage selector with scenario guidance, not a free-form next action');
$check(str_contains($route_html,'data-roadmap-select="claim"') && str_contains($route_html,'name="stage_detail"'),'Full roadmap and stage detail are available');
// Existing free-text cards are not interpreted as a guessed completed stage.
$legacy=$makeOrder($customer);$legacy_state=['customer'=>$customer,'folio'=>'TEST','manager'=>$admin,'due'=>'','original_due'=>'','next'=>'Wait for warehouse receipt on Thursday','active'=>false,'links'=>[],'documents'=>[],'events'=>[],'observed'=>[],'revision'=>wp_generate_uuid4()];
$legacy->update_meta_data(Execution::META,$legacy_state);$legacy->save_meta_data();
ob_start();Ui::render($legacy);$legacy_html=ob_get_clean();
$check(str_contains($legacy_html,$legacy_state['next']) && !isset($state($legacy->get_id())['roadmap']),'Viewing an old card keeps its text without automatic migration');
$l=Execution::save($legacy->get_id(),$legacy_state['revision'],array_merge($route_input,['stage'=>'wait_stock']));
$check($l['legacy_next']===$legacy_state['next'] && $l['roadmap']['current']==='wait_stock','First explicit stage selection preserves the legacy next action');
if ($dir=getenv('PCOE_EXECUTION_FIXTURE_DIR')) {
    file_put_contents($dir.'/roadmap.json',wp_json_encode(['html'=>$route_html,'legacy_html'=>$legacy_html,'stages'=>array_keys(Roadmap::stages())]));
}
