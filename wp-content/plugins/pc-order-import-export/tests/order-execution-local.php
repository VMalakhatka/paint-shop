<?php
/** Local synthetic fixtures. No external writes/mail; HTTP is intercepted. */
use PaintCore\PCOE\OrderExecution as Execution;
use PaintCore\PCOE\OrderExecutionUi as Ui;
if (!defined('WP_CLI') || !WP_CLI || wp_parse_url(home_url(),PHP_URL_HOST)!=='paint.local') throw new RuntimeException('Local only');
$checks=0;$users=[];$orders=[];$calls=[];$mails=0;$mode='ok';$original=get_current_user_id();$shipment=0;
$check=static function($value,$label)use(&$checks){if(!$value)throw new RuntimeException($label);$checks++;};
$deny=static function($fn)use($check){try{$fn();}catch(Throwable $e){$check(true,'Rejected');return;}throw new RuntimeException('Expected rejection');};
add_filter('pre_option_'.LPS_OPT_MAIN,static fn()=>['java_base_url'=>'https://folio.invalid','api_token'=>'fixture']);
add_filter('pre_wp_mail',static function()use(&$mails){$mails++;return false;},PHP_INT_MAX);
add_filter('pre_http_request',static function($pre,$args,$url)use(&$mode,&$calls){
    $calls[]=['method'=>$args['method']??'GET','url'=>$url,'timeout'=>$args['timeout']??0];
    if (($args['method']??'GET')!=='GET' || !preg_match('~/customer-documents/(ACCOUNT|EXPENSE|PAYMENT)/(41|42)~',$url,$m)) return new WP_Error('blocked','Unexpected HTTP');
    if ($mode==='offline') return new WP_Error('offline','Unavailable');
    return ['response'=>['code'=>200],'headers'=>[],'body'=>wp_json_encode(['ok'=>true,'partner'=>['shortName'=>$mode==='foreign'?'OTHER':'TEST'],
        'document'=>['documentType'=>$m[1],'documentId'=>$mode==='wrong-id'?99:(int)$m[2],'documentNumber'=>'TEST-'.$m[2],'documentDate'=>[2026,10,26],
            'accounted'=>true,'totalAmount'=>$mode==='malformed'?'invalid':($mode==='changed'?7:10),'items'=>[['sku'=>'TEST','quantity'=>$mode==='changed'?7:10,'price'=>1,'amount'=>$mode==='changed'?7:10]]]])];
},PHP_INT_MAX,3);
$makeUser=static function($role)use(&$users){$id=wp_insert_user(['user_login'=>'execution-'.wp_generate_uuid4(),'user_pass'=>wp_generate_password(),'role'=>$role]);if(is_wp_error($id))throw new RuntimeException('User fixture failed');$users[]=$id;return $id;};
$makeOrder=static function($customer)use(&$orders){$o=new WC_Order();$o->set_customer_id($customer);$o->set_created_via('execution-fixture');$o->save();$orders[]=$o->get_id();return $o;};
$state=static function($id){$o=wc_get_order($id);$o->read_meta_data(true);return Execution::state($o);};
try {
    $admin=$makeUser('administrator');$manager=$makeUser('shop_manager');$customer=$makeUser('partner');$other=$makeUser('partner');
    update_user_meta($customer,'_folio_partner_short_name','TEST');
    $root=$makeOrder($customer);$child=$makeOrder($customer);$foreign=$makeOrder($other);
    $child->set_parent_id($root->get_id());$child->update_meta_data('_folio_parent_order_id',$root->get_id());$child->save();
    $root->update_meta_data('_folio_child_order_ids',[$child->get_id()]);$root->save_meta_data();
    $check(Execution::root($child)->get_id()===$root->get_id(),'Child resolves to common execution');
    $check(count(Execution::family($root))===2,'Family includes original and child');
    $foreign->update_meta_data('_folio_parent_order_id',$root->get_id());$foreign->save_meta_data();$deny(fn()=>Execution::root($foreign));
    wp_set_current_user($customer);$deny(fn()=>Execution::save($root->get_id(),'',[]));
    wp_set_current_user($manager);
    $input=['manager'=>$manager,'next'=>'Check paper packing record','due'=>'2026-11-01T15:00','reason'=>'Agreed with customer','active'=>1];
    $s=Execution::save($root->get_id(),'',$input);
    $check($s['manager']===$manager && count($s['events'])===1,'Responsibility and initial event saved');
    $check(as_has_scheduled_action(Execution::JOB,[$root->get_id()],Execution::GROUP),'Monitoring scheduled');
    $deny(fn()=>Execution::save($root->get_id(),'',$input));
    $check(count($state($root->get_id())['events'])===1,'Stale request does not overwrite history');
    $deny(fn()=>Execution::save($root->get_id(),$s['revision'],array_merge($input,['manager'=>$admin])));
    $deny(fn()=>Execution::date('2026-02-30T10:00'));
    Execution::link($root->get_id(),$s['revision'],['type'=>'ACCOUNT','id'=>41],'Confirmed source document');
    $s=$state($root->get_id());$check(count($s['links'])===1,'Verified document linked');
    $mode='foreign';$deny(fn()=>Execution::link($root->get_id(),$s['revision'],['type'=>'EXPENSE','id'=>42],'Test'));
    $mode='wrong-id';$deny(fn()=>Execution::link($root->get_id(),$s['revision'],['type'=>'EXPENSE','id'=>42],'Test'));
    $mode='malformed';$deny(fn()=>Execution::link($root->get_id(),$s['revision'],['type'=>'EXPENSE','id'=>42],'Test'));
    global $wpdb;
    $wpdb->insert($wpdb->prefix.'pnpm_shipments',['shipment_uuid'=>wp_generate_uuid4(),'order_id'=>$child->get_id(),'location_id'=>0,'source'=>'store_api','status'=>'np_created','idempotency_key'=>hash('sha256',wp_generate_uuid4()),'created_at'=>gmdate('Y-m-d H:i:s'),'updated_at'=>gmdate('Y-m-d H:i:s')]);
    $shipment=(int)$wpdb->insert_id;$check($shipment>0,'Shipment fixture stored');
    $mode='ok';wp_set_current_user(0);Execution::observe($root->get_id());$s=$state($root->get_id());
    $check(isset($s['observed']['orders'][$child->get_id()]),'Background worker reads family without impersonating manager');
    $check(($s['observed']['shipments'][$shipment]['status']??'')==='np_created','Saved NP lifecycle observed without creating a TTN');
    $check(!array_key_exists('completed',$s),'Created shipment cannot complete execution');
    $n=count($s['events']);Execution::observe($root->get_id());$s=$state($root->get_id());
    $check(count($s['events'])===$n,'Repeat observation does not duplicate events');
    $mode='changed';Execution::observe($root->get_id());$s=$state($root->get_id());
    $check($s['documents']['ACCOUNT:41']['value']['totalAmount']===7,'Changed source values observed');
    $check(count($s['events'])===$n+1,'One event for changed document');
    $mode='offline';Execution::observe($root->get_id());$s=$state($root->get_id());
    $check($s['documents']['ACCOUNT:41']['value']['totalAmount']===7 && $s['documents']['ACCOUNT:41']['error'],'Outage preserves last good values');
    $n=count($s['events']);Execution::observe($root->get_id());$check(count($state($root->get_id())['events'])===$n,'Repeated outage does not flood audit');
    $mode='ok';wp_set_current_user($manager);$s=$state($root->get_id());
    $s=Execution::save($root->get_id(),$s['revision'],array_merge($input,['due'=>'2026-11-02T15:00','reason'=>'Proposed revised deadline']));
    $check($s['original_due']!==$s['due'],'Original deadline retained');
    wp_set_current_user($admin);$s=Execution::save($root->get_id(),$s['revision'],array_merge($input,['manager'=>$admin,'reason'=>'Approved handover']));
    $check($s['manager']===$admin && end($s['events'])['actor']===$admin,'Authorized responsibility transfer audited');
    $s=Execution::save($root->get_id(),$s['revision'],array_merge($input,['manager'=>$admin,'active'=>0,'reason'=>'Pause monitoring']));
    $check(!as_has_scheduled_action(Execution::JOB,[$root->get_id()],Execution::GROUP),'Pause removes scheduled monitoring');
    $n=count($calls);Execution::observe($root->get_id());$check(count($calls)===$n,'Paused worker makes no source requests');
    ob_start();Ui::render(wc_get_order($root->get_id()));$html=ob_get_clean();
    $check(str_contains($html,'pcoe_execution') && str_contains($html,'name="revision"') && str_contains($html,'name="_wpnonce"'),'UI has protected action forms');
    $dir=getenv('PCOE_EXECUTION_FIXTURE_DIR');if($dir){wp_mkdir_p($dir);file_put_contents($dir.'/execution.html','<!doctype html><html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><style>body{font:16px system-ui;background:#f0f0f1;margin:18px}button{padding:8px}textarea{box-sizing:border-box}input,select{max-width:100%}</style><style>'.file_get_contents(PCOE_DIR.'/assets/manager.css').'</style><div class="pcoe-manager">'.$html.'</div></html>');}
    wp_set_current_user($customer);ob_start();Ui::render($root);$check(ob_get_clean()==='','Customer cannot see internal control');
    wp_set_current_user($admin);Execution::unlink($root->get_id(),$s['revision'],'ACCOUNT:41','Incorrect manual relation');$s=$state($root->get_id());
    $check(!$s['links'] && !empty($s['documents']['ACCOUNT:41']),'Unlink preserves document observation/history');
    $check(wc_get_order($root->get_id())->has_status('pending'),'No business status transition');
    update_user_meta($customer,'_folio_partner_short_name','OTHER');$deny(fn()=>Execution::save($root->get_id(),$s['revision'],$input));
    $beforeRequests=count($calls);Execution::observe($root->get_id());$check(count($calls)===$beforeRequests,'Changed customer mapping cannot read another customer');
    $check(!$mails,'No emails');$check(!array_filter($calls,fn($r)=>$r['method']!=='GET'),'Only read requests');
    $check(!array_filter($calls,fn($r)=>$r['timeout']>20),'Bounded source timeout');
    echo "PASS: $checks execution checks\n";
} finally {
    if($shipment){global $wpdb;$wpdb->delete($wpdb->prefix.'pnpm_shipments',['id'=>$shipment]);}
    foreach($orders as $id){Execution::schedule($id,false);$o=wc_get_order($id);if($o)$o->delete(true);}
    wp_set_current_user($original);require_once ABSPATH.'wp-admin/includes/user.php';foreach($users as $id)wp_delete_user($id);
}
