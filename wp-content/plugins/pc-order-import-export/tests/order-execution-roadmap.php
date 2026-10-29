<?php
// Included by order-execution-local.php; same synthetic users/orders and HTTP/mail interception.
use PaintCore\PCOE\OrderExecutionRoadmap as Roadmap;
use PaintCore\PCOE\OrderExecutionOperations as Operations;
use PaintCore\PCOE\OrderExecution as Execution;
use PaintCore\PCOE\OrderExecutionUi as Ui;
wp_set_current_user($admin);
$read_count=count($calls);$mail_count=$mails;
$route_input=['manager'=>$admin,'stage'=>'intake','stage_status'=>'in_progress','stage_detail'=>'Warehouse A','due'=>'2026-11-01T15:00','reason'=>'Verified source record','active'=>0];
$actual_time=wp_date('Y-m-d\TH:i');
$operation_input=['operation_choice'=>'custom','operation_status'=>'completed','operation_title'=>'Telephone discussion','operation_result'=>'Delivery details agreed with customer','performed_at'=>$actual_time];
$last_operation=static fn(array $s)=>end($s['events'])['details']['operation'];
$record=static function(int $id,array $s,string $stage,string $status='completed',array $more=[])use($operation_input){
    $choice=isset(Operations::planned($s)[$stage])?'planned:'.$stage:'stage:'.$stage;
    return Operations::record($id,$s['revision'],array_merge($operation_input,['operation_choice'=>$choice,'operation_status'=>$status],$more));
};

// A real operation may be recorded before the first future task is planned.
$first=$makeOrder($customer);$first_id=$first->get_id();
wp_set_current_user($customer);$deny(fn()=>Operations::record($first_id,'',$operation_input));
wp_set_current_user($admin);
foreach ([['operation_choice'=>'planned:intake'],['operation_choice'=>'planned:invented'],['operation_choice'=>'stage:invented'],['operation_choice'=>'invalid'],['operation_result'=>''],['operation_result'=>str_repeat('x',2001)],['operation_title'=>''],['operation_title'=>str_repeat('x',161)],['performed_at'=>''],['performed_at'=>'2026-02-30T10:00'],['performed_at'=>wp_date('Y-m-d\TH:i',time()+600)],['operation_status'=>'skipped'],['operation_status'=>'in_progress']] as $bad) {
    $deny(fn()=>Operations::record($first_id,'',array_merge($operation_input,$bad)));
}
$f=Operations::record($first_id,'',$operation_input);
$check($f['manager']===$admin && empty($f['active']) && empty($f['roadmap']),'First custom fact starts an audit card without planning or enabling monitoring');
$fact=$last_operation($f);
$check($fact['source']==='custom' && $fact['title']==='Telephone discussion' && $fact['result']===$operation_input['operation_result'] && !empty($fact['performed_at']),'Custom title, result and actual time are recorded');
$check(end($f['events'])['type']==='operation_recorded' && end($f['events'])['actor']===$admin && !empty(end($f['events'])['time']),'Fact has independent recorded time and author');
$before=$f;$deny(fn()=>Operations::record($first_id,'',$operation_input));
$check($state($first_id)===$before,'Replaying an operation with a stale revision cannot duplicate history');
$before=$f;update_user_meta($customer,'_folio_partner_short_name','OTHER');
$deny(fn()=>Operations::record($first_id,$f['revision'],$operation_input));update_user_meta($customer,'_folio_partner_short_name','TEST');
$check($state($first_id)===$before,'Changed customer mapping cannot write an operation');

// Cards saved before plans were separated still expose their outstanding scheduled stage.
$old_plan_order=$makeOrder($customer);
$old_plan_state=['customer'=>$customer,'folio'=>'TEST','manager'=>$admin,'due'=>'2026-11-01T12:00:00+00:00','original_due'=>'2026-11-01T12:00:00+00:00','next'=>Roadmap::label('wait_stock'),'active'=>false,'links'=>[],'documents'=>[],'events'=>[],'observed'=>[],'revision'=>wp_generate_uuid4(),
    'roadmap'=>['version'=>1,'current'=>'wait_stock','stages'=>['wait_stock'=>['status'=>'in_progress','detail'=>'Expected goods on warehouse A','manager'=>$admin,'actor'=>$admin,'at'=>gmdate('c',time()-3600),'due'=>'2026-11-01T12:00:00+00:00','original_due'=>'2026-11-01T12:00:00+00:00','reason'=>'Old scheduled task']]]];
$old_plan_order->update_meta_data(Execution::META,$old_plan_state);$old_plan_order->save_meta_data();
$check(isset(Operations::planned($old_plan_state)['wait_stock']),'Pre-upgrade in-progress stage remains a selectable prior plan');
$old_record=Operations::record($old_plan_order->get_id(),$old_plan_state['revision'],array_merge($operation_input,['operation_choice'=>'planned:wait_stock']));
$check($last_operation($old_record)['planned']['detail']==='Expected goods on warehouse A' && $old_record['next']==='','Old plan is completed with its original details preserved in the fact');

$route=$makeOrder($customer);$route_id=$route->get_id();
$deny(fn()=>Execution::save($route_id,'',array_merge($route_input,['stage'=>''])));
$deny(fn()=>Execution::save($route_id,'',array_merge($route_input,['stage'=>'invented-stage'])));
$deny(fn()=>Execution::save($route_id,'',array_merge($route_input,['reason'=>''])));
foreach(['invented-status','completed','skipped'] as $status) $deny(fn()=>Execution::save($route_id,'',array_merge($route_input,['stage_status'=>$status])));
$r=Execution::save($route_id,'',$route_input);
$check($r['roadmap']['current']==='intake' && $r['roadmap']['stages']['intake']['status']==='in_progress','Planning stores a stable stage ID without completing it');
$check(isset(Operations::planned($r)['intake']),'Explicit plan is available for recording its outcome');
$check($r['next']===Roadmap::label('intake') && end($r['events'])['details']['stage_update']['stage']==='intake','Summary and planning audit identify the stage');
$deny(fn()=>Operations::record($route_id,$r['revision'],array_merge($operation_input,['operation_choice'=>'stage:close'])));
$deny(fn()=>Operations::record($route_id,$r['revision'],array_merge($operation_input,['operation_choice'=>'planned:packing'])));
$deny(fn()=>Operations::record($route_id,$r['revision'],array_merge($operation_input,['operation_choice'=>'planned:intake','operation_status'=>'skipped'])));

// Document a previously planned operation while a separate later task is current.
$intake_plan=Operations::planned($r)['intake'];
$r=Execution::save($route_id,$r['revision'],array_merge($route_input,['stage'=>'packing','stage_detail'=>'Pack in warehouse B','due'=>'2026-11-02T15:00']));
$next_before=[$r['roadmap']['current'],$r['next'],$r['due']];$stale=$r['revision'];
$r=$record($route_id,$r,'intake');$fact=$last_operation($r);
$check([$r['roadmap']['current'],$r['next'],$r['due']]===$next_before,'Recording an old task preserves the distinct next action and deadline');
$check($r['roadmap']['stages']['intake']['status']==='completed' && !isset(Operations::planned($r)['intake']),'Completed plan leaves the outstanding planned list');
$check($fact['source']==='planned' && $fact['stage']==='intake' && $fact['planned']===$intake_plan,'Operation keeps the exact server-side plan snapshot');
$check(strtotime($fact['performed_at'])!==strtotime($fact['planned']['due']),'Actual operation time is separate from its agreed deadline');
$deny(fn()=>Operations::record($route_id,$stale,array_merge($operation_input,['operation_choice'=>'planned:intake'])));
$deny(fn()=>Operations::record($route_id,$r['revision'],array_merge($operation_input,['operation_choice'=>'planned:intake'])));
$unchanged=$r;
$deny(fn()=>Operations::record($route_id,$r['revision'],array_merge($operation_input,['operation_choice'=>'stage:intake','operation_status'=>'in_progress','performed_at'=>wp_date('Y-m-d\TH:i',time()-600)])));
$check($state($route_id)===$unchanged,'Backdated earlier result cannot regress a later recorded stage');
$r=$record($route_id,$r,'packing');
$check(empty($r['roadmap']['current']) && $r['next']==='' && $r['due']==='','Completing the current plan clears it without inventing an agreed next task');
$check($r['roadmap']['stages']['packing']['due']===$next_before[2],'Completed stage retains its prior agreed deadline');

// A custom fact never closes a roadmap stage or overwrites an unrelated plan.
$r=Execution::save($route_id,$r['revision'],array_merge($route_input,['stage'=>'wait_stock']));
$roadmap_before=$r['roadmap'];$next_before=[$r['next'],$r['due']];
$r=Operations::record($route_id,$r['revision'],$operation_input);
$check($r['roadmap']===$roadmap_before && [$r['next'],$r['due']]===$next_before,'Unplanned custom operation preserves the entire roadmap and next task');
$r=$record($route_id,$r,'wait_stock');
$r=$record($route_id,$r,'shipment','skipped',['operation_result'=>'Customer pickup']);
$check($r['roadmap']['stages']['shipment']['status']==='skipped' && $last_operation($r)['result']==='Customer pickup','Optional skip records its specific explanation');
$r=$record($route_id,$r,'claim','in_progress');
$check(!isset(Operations::planned($r)['claim']),'Manual in-progress observation does not invent a previous plan');
foreach(Roadmap::stages() as $id=>$stage) if (!$stage['optional'] && $id!=='close' && ($r['roadmap']['stages'][$id]['status']??'')!=='completed') $r=$record($route_id,$r,$id);
$deny(fn()=>Operations::record($route_id,$r['revision'],array_merge($operation_input,['operation_choice'=>'stage:close'])));
$r=$record($route_id,$r,'claim','completed',['operation_result'=>'Resolution completed; correction linked']);
$r=$record($route_id,$r,'close');
$check(!empty($r['roadmap']['closed_at']) && $r['roadmap']['stages']['close']['status']==='completed','Closure requires completed core stages and resolved opened branches');
$check(!isset($r['roadmap']['stages']['return']) && !isset($r['roadmap']['stages']['box_passports']),'Unused optional stages need no fabricated completion');
$closed_control=[$r['roadmap'],$r['next'],$r['due']];$closed_revision=$r['revision'];
$configure=['manager'=>$admin,'active'=>0,'reason'=>'Pause source monitoring after closure'];
$r=Execution::configure($route_id,$r['revision'],$configure);
$check([$r['roadmap'],$r['next'],$r['due']]===$closed_control && empty($r['active']),'Monitoring can be paused after closure without inventing or reopening a next task');
$deny(fn()=>Execution::configure($route_id,$closed_revision,$configure));
$deny(fn()=>Execution::configure($route_id,$r['revision'],array_merge($configure,['reason'=>''])));
wp_set_current_user($manager);$deny(fn()=>Execution::configure($route_id,$r['revision'],array_merge($configure,['manager'=>$manager])));wp_set_current_user($admin);
$check($state($route_id)['manager']===$admin,'Separate monitoring settings cannot bypass responsibility-transfer permissions');
$config_empty=$makeOrder($customer);$deny(fn()=>Execution::configure($config_empty->get_id(),'',$configure));
$closed=$r['roadmap'];$event_count=count($r['events']);
$r=Operations::record($route_id,$r['revision'],array_merge($operation_input,['operation_title'=>'Customer thanked the team']));
$check($r['roadmap']===$closed && count($r['events'])===$event_count+1,'Custom note after closure does not reopen or rewrite the roadmap');
$r=$record($route_id,$r,'return','in_progress',['operation_result'=>'Customer requested return']);
$check(empty($r['roadmap']['closed_at']) && empty($r['roadmap']['stages']['close']) && ($r['roadmap']['current']??'')!=='close','Reopening a recorded stage invalidates closure while preserving audit');
$check(wc_get_order($route_id)->has_status('pending') && count($calls)===$read_count && $mails===$mail_count,'Recording and planning do not change Woo status, call external services or send messages');

// Stable fixture for offline form tests: current planned return, old planned waiting, intake already performed.
$r=Execution::save($route_id,$r['revision'],array_merge($route_input,['stage'=>'wait_stock','stage_detail'=>'Expected receipt in warehouse A']));
$r=Execution::save($route_id,$r['revision'],array_merge($route_input,['stage'=>'return','stage_detail'=>'Check return quantities','active'=>1]));
$before=$r['roadmap'];wp_set_current_user(0);Execution::observe($route_id);$r=$state($route_id);
$check($r['roadmap']===$before,'Source observation does not complete manual stages');wp_set_current_user($admin);
ob_start();Ui::render(wc_get_order($route_id));$route_html=ob_get_clean();
$check(str_contains($route_html,'name="stage"') && !str_contains($route_html,'name="next"'),'Planning keeps the fixed stage selector');
$check(str_contains($route_html,'name="operation_choice"') && str_contains($route_html,'name="operation_result"'),'Operation recording is a separate form with a required result');

// Existing free-text tasks stay readable and can be documented without guessing their stage ID.
$legacy=$makeOrder($customer);$legacy_state=['customer'=>$customer,'folio'=>'TEST','manager'=>$admin,'due'=>'2026-11-01T12:00:00+00:00','original_due'=>'2026-11-01T12:00:00+00:00','next'=>'Wait for warehouse receipt on Thursday','active'=>false,'links'=>[],'documents'=>[],'events'=>[],'observed'=>[],'revision'=>wp_generate_uuid4()];
$legacy->update_meta_data(Execution::META,$legacy_state);$legacy->save_meta_data();
ob_start();Ui::render($legacy);$legacy_html=ob_get_clean();
$check(str_contains($legacy_html,$legacy_state['next']) && !isset($state($legacy->get_id())['roadmap']),'Viewing an old card does not migrate or guess its stage');
$l=Operations::record($legacy->get_id(),$legacy_state['revision'],array_merge($operation_input,['operation_choice'=>'planned:legacy']));
$check($l['legacy_next']===$legacy_state['next'] && $l['next']==='' && $l['due']==='' && empty($l['roadmap']),'Recording a legacy task retains its text without inventing a roadmap stage');
$check($last_operation($l)['source']==='legacy' && $last_operation($l)['title']===$legacy_state['next'],'Legacy operation audit identifies the old free-text task');
$deny(fn()=>Operations::record($legacy->get_id(),$l['revision'],array_merge($operation_input,['operation_choice'=>'planned:legacy'])));
$l=Execution::save($legacy->get_id(),$l['revision'],array_merge($route_input,['stage'=>'wait_stock']));
$check($l['legacy_next']===$legacy_state['next'] && $l['roadmap']['current']==='wait_stock','Planning after a legacy fact keeps the historical text');

// A named actual operation must not strand an unrelated free-text task from the old card.
$legacy_mixed=$makeOrder($customer);$mixed_state=$legacy_state;$mixed_state['revision']=wp_generate_uuid4();
$legacy_mixed->update_meta_data(Execution::META,$mixed_state);$legacy_mixed->save_meta_data();
$m=Operations::record($legacy_mixed->get_id(),$mixed_state['revision'],array_merge($operation_input,['operation_choice'=>'stage:picking','operation_status'=>'in_progress']));
$check($m['next']===$mixed_state['next'] && $m['due']===$mixed_state['due'] && !empty(Operations::legacy_plan($m)),'Named fact preserves an outstanding legacy task as a selectable plan');
$m=Execution::save($legacy_mixed->get_id(),$m['revision'],array_merge($route_input,['stage'=>'wait_stock','due'=>'2026-11-03T12:00']));
$mixed_next=[$m['roadmap']['current'],$m['next'],$m['due']];
$m=Operations::record($legacy_mixed->get_id(),$m['revision'],array_merge($operation_input,['operation_choice'=>'planned:legacy']));
$check([$m['roadmap']['current'],$m['next'],$m['due']]===$mixed_next && empty(Operations::legacy_plan($m)),'Recording the old free-text task leaves a separately planned newer stage and deadline intact');
$check($last_operation($m)['planned']['due']===$mixed_state['due'] && $last_operation($m)['title']===$mixed_state['next'],'Legacy fact keeps original deadline and title after roadmap migration');

// A migrated free-text task must also be resolved before otherwise complete execution is closed.
$legacy_blocked=$makeOrder($customer);$blocked=$mixed_state;$blocked['revision']=wp_generate_uuid4();
$blocked['roadmap']=$closed;unset($blocked['roadmap']['closed_at'],$blocked['roadmap']['stages']['close']);
$blocked['legacy_plan']=Operations::legacy_plan($mixed_state);
$legacy_blocked->update_meta_data(Execution::META,$blocked);$legacy_blocked->save_meta_data();
$deny(fn()=>Operations::record($legacy_blocked->get_id(),$blocked['revision'],array_merge($operation_input,['operation_choice'=>'stage:close'])));
$blocked=Operations::record($legacy_blocked->get_id(),$blocked['revision'],array_merge($operation_input,['operation_choice'=>'planned:legacy']));
$blocked=$record($legacy_blocked->get_id(),$blocked,'close');
$check(!empty($blocked['roadmap']['closed_at']),'Resolving the last legacy task permits closure of an otherwise completed route');
if ($dir=getenv('PCOE_EXECUTION_FIXTURE_DIR')) {
    file_put_contents($dir.'/roadmap.json',wp_json_encode(['html'=>$route_html,'legacy_html'=>$legacy_html,'stages'=>array_keys(Roadmap::stages())]));
}
