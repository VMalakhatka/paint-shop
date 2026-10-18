<?php
/** Local synthetic fixtures only. Every HTTP request and email is intercepted before transport. */
if (!defined('WP_CLI') || !WP_CLI || wp_get_environment_type()!=='local') throw new RuntimeException('Local CLI only');
use Paint\NovaPoshta\Infrastructure\ManagerPreparation;
use Paint\NovaPoshta\Infrastructure\ShipmentLifecycle;
use Paint\NovaPoshta\Infrastructure\ShipmentRepository;
use Paint\NovaPoshta\Infrastructure\RecipientIdentity;
$checks=0;
$check=static function ($ok,$name) use (&$checks) { if (!$ok) throw new RuntimeException($name); $checks++; };
$reject=static function ($work,$name) use ($check) { try {$work();} catch (RuntimeException $e) {$check(true,$name);return;} throw new RuntimeException('Expected rejection: '.$name); };
$refs=[]; foreach (range(1,8) as $i) $refs[$i]=sprintf('00000000-0000-0000-0000-%012d',$i);
$terms=get_terms(['taxonomy'=>'location','hide_empty'=>false]);
if (is_wp_error($terms) || count($terms)<2) throw new RuntimeException('Two fixture locations required');
$locations=[(int)$terms[0]->term_id,(int)$terms[1]->term_id];
$mapping=['enabled'=>'yes','sender_type'=>'warehouse','city_ref'=>$refs[1],'counterparty_ref'=>$refs[2],
    'contact_ref'=>$refs[3],'sender_address_ref'=>$refs[4],'handover_warehouse_ref'=>$refs[4],
    'phone'=>'380000000000','customer_label'=>'Fixture warehouse','handover_warehouse_label'=>'Fixture origin'];
$enabled=true; $mode='success'; $writes=0; $mails=[]; $requests=[]; $pdfMode='pdf';
add_filter('pre_option_pnpm_location_mappings',static fn()=>array_fill_keys($locations,$mapping));
add_filter('pre_option_pnpm_settings',static function () use (&$enabled) {return ['writes_enabled'=>$enabled?'yes':'no'];});
if (!defined('PNPM_ALLOW_REAL_TTN')) define('PNPM_ALLOW_REAL_TTN',true);
if (!defined('PNPM_NOVA_POSHTA_API_KEY')) define('PNPM_NOVA_POSHTA_API_KEY','synthetic-test-key');
add_filter('pre_wp_mail',static function ($result,$args) use (&$mails) {$mails[]=$args;return true;},PHP_INT_MAX,2);
add_filter('pre_http_request',static function ($pre,$args,$url) use (&$writes,&$mode,&$requests,&$pdfMode,$refs) {
    if (str_starts_with($url,'https://my.novaposhta.ua/orders/')) {
        if (($args['redirection'] ?? null)!==0 || ($args['limit_response_size'] ?? 0)!==10485761) throw new RuntimeException('Unsafe PDF options');
        return ['response'=>['code'=>200],'headers'=>['content-type'=>$pdfMode==='pdf'?'application/pdf':'text/html'],'body'=>$pdfMode==='pdf'?"%PDF-1.4\nsynthetic fixture":'<html>invalid</html>'];
    }
    if ($url!=='https://api.novaposhta.ua/v2.0/json/') return new WP_Error('blocked','All non-NP HTTP blocked');
    $p=json_decode($args['body'],true); unset($p['apiKey']); $requests[]=$p;
    $m=$p['calledMethod']; $data=[];
    if ($m==='getWarehouses' && in_array($mode,['read_limited','read_limit_once'],true)) {
        if ($mode==='read_limit_once') $mode='success';
        return ['response'=>['code'=>200],'body'=>json_encode(['success'=>false,'errors'=>['To many requests'],'errorCodes'=>['20000401501']])];
    }
    if ($m==='getCounterparties') $data=[['Ref'=>$refs[2],'Description'=>'Fixture sender']];
    elseif ($m==='getCounterpartyAddresses') $data=[['Ref'=>$refs[4],'CityRef'=>$refs[1]]];
    elseif ($m==='getCounterpartyContactPersons') $data=[['Ref'=>$refs[3],'Phones'=>'380000000000']];
    elseif ($m==='getWarehouses') {
        $ref=$p['methodProperties']['Ref'];
        $data=[['Ref'=>$ref,'CityRef'=>$refs[1],'Description'=>$ref===$refs[4]?'Fixture origin':'Fixture locker','Number'=>'1',
            'CategoryOfWarehouse'=>$ref===$refs[4]?'Branch':'Postomat','WarehouseStatus'=>'Working','PlaceMaxWeightAllowed'=>20,'TotalMaxWeightAllowed'=>20,
            'MaxDeclaredCost'=>29000,'ReceivingLimitationsOnDimensions'=>['Length'=>60,'Width'=>40,'Height'=>30],'SendingLimitationsOnDimensions'=>['Length'=>120,'Width'=>120,'Height'=>120]]];
    } elseif ($m==='getDocumentPrice') $data=[['Cost'=>'80.00']];
    elseif ($m==='save') {
        $writes++;
        if ($mode==='write_limited') return ['response'=>['code'=>429],'body'=>'{}'];
        if ($p['modelName']==='CounterpartyGeneral') {
            $person=$p['methodProperties'];
            $person['Phones']=$person['Phone'];unset($person['Phone']);
            if ($mode==='identity_mismatch') $person['LastName']='Інший';
            if ($mode==='identity_missing') unset($person['Phones']);
            $data=[['Ref'=>$refs[6],'ContactPerson'=>['success'=>true,'data'=>[['Ref'=>$refs[7]]+$person]]]];
        }
        elseif ($mode==='timeout') return new WP_Error('timeout','Synthetic timeout');
        elseif ($mode==='rejected') return ['response'=>['code'=>200],'body'=>json_encode(['success'=>false,'data'=>[],'errors'=>['test rejection']])];
        else $data=[['Ref'=>sprintf('00000000-0000-0000-0000-%012d',100+$writes),'IntDocNumber'=>str_pad((string)(100+$writes),14,'2',STR_PAD_LEFT),'CostOnSite'=>'80','EstimatedDeliveryDate'=>'20.10.2026']];
    } else throw new RuntimeException('Unexpected carrier method: '.$m);
    return ['response'=>['code'=>200],'body'=>json_encode(['success'=>true,'data'=>$data,'errors'=>[],'warnings'=>$m==='save'?['synthetic warning']:[]])];
},PHP_INT_MAX,3);
$old=$GLOBALS['current_user']; $manager=new WP_User();$manager->ID=PHP_INT_MAX;$manager->allcaps=['manage_woocommerce'=>true,'manage_pnpm_shipments'=>true];$GLOBALS['current_user']=$manager;
$noWait=new class extends Paint\NovaPoshta\Infrastructure\ApiRequestThrottle {
    public function run(string $key, callable $request) { return $request(); }
};
$orders=[]; $tokens=[]; $customerId=0; $service=new ShipmentLifecycle(new Paint\NovaPoshta\Infrastructure\ApiClient($noWait)); $prep=new ManagerPreparation();
$names=['FirstName'=>'Тест','LastName'=>'Приклад','MiddleName'=>'Тестович'];
$make=static function ($two=false) use (&$orders,$locations,$refs,$prep) {
    $order=new WC_Order();$order->set_currency('UAH');$order->set_payment_method('bacs');$order->set_billing_email('np-fixture@example.invalid');
    $order->set_shipping_first_name('Тест');$order->set_shipping_last_name('Приклад');$order->set_shipping_phone('380000000000');
    $order->update_meta_data('_pnpm_recipient',['city_ref'=>$refs[1],'city_label'=>'Fixture city','point_ref'=>$refs[5],'point_label'=>'Fixture locker','delivery_type'=>'parcel_locker']);
    $item=new WC_Order_Item_Product();$item->set_name('Fixture goods');$item->set_quantity($two?2:1);$item->set_subtotal($two?200:100);$item->set_total($two?200:100);
    $alloc=[$locations[0]=>['quantity_subtracted'=>1]];if($two)$alloc[$locations[1]]=['quantity_subtracted'=>1];$item->add_meta_data('_slw_data',$alloc);$order->add_item($item);$order->set_total($two?200:100);$order->save();$orders[]=$order;
    $context=$prep->context($order);$input=['revision'=>$context['revision'],'agreed'=>'1','recipient'=>$context['recipient'],'groups'=>[]];
    foreach ($context['groups'] as $id=>$group)$input['groups'][$id]=['payer'=>'Recipient','boxes'=>[['weight'=>0.1,'length'=>18,'width'=>2,'height'=>3]]];
    $prep->save($order->get_id(),$input);
    return $order;
};
try {
    $withoutMiddle=['FirstName'=>'Тест','LastName'=>'Приклад','MiddleName'=>''];
    $check(RecipientIdentity::names($withoutMiddle)===$withoutMiddle,'Empty patronymic accepted');
    $check(RecipientIdentity::names(['FirstName'=>'Тест','LastName'=>'Приклад'])===$withoutMiddle,'Absent patronymic serialized empty');
    foreach (['', '   ', str_repeat('А',37), '<script>', []] as $bad) {
        $reject(fn()=>RecipientIdentity::names(['FirstName'=>$bad,'LastName'=>'Приклад']),'Invalid mandatory name');
    }
    $reject(fn()=>RecipientIdentity::names(['FirstName'=>'Тест','LastName'=>'Приклад','MiddleName'=>[]]),'Invalid optional value');
    $suggest=new WC_Order();$suggest->set_shipping_first_name('Тест');$suggest->set_shipping_last_name('Приклад');$suggest->set_shipping_phone('+38 (000) 000-00-00');
    $saved=['name'=>'Приклад Тест','phone'=>'380000000000'];
    $check(RecipientIdentity::defaults($suggest,$saved)===$withoutMiddle,'Structured order names with normalized phone, surname-first snapshot');
    $empty=['FirstName'=>'','LastName'=>'','MiddleName'=>''];
    $check(RecipientIdentity::defaults($suggest,['name'=>'Інший Тест','phone'=>$saved['phone']])===$empty,'Never replace different recipient');
    $check(RecipientIdentity::defaults($suggest,['name'=>$saved['name'],'phone'=>'380000000001'])===$empty,'Never fill on mismatched phone');
    $suggest->set_shipping_first_name('');$suggest->set_shipping_last_name('');
    $suggest->set_billing_first_name('Тест');$suggest->set_billing_last_name('Приклад');$suggest->set_billing_phone('0000000000');
    $check(RecipientIdentity::defaults($suggest,$saved)===$withoutMiddle,'Billing fallback with matching identity');
    $customer=new WC_Customer();$customer->set_username('np-identity-fixture-'.wp_generate_uuid4());
    $customer->set_email('np-identity-'.wp_generate_uuid4().'@example.invalid');
    $customer->set_billing_first_name('Тест');$customer->set_billing_last_name('Приклад');$customer->set_billing_phone($saved['phone']);
    $customerId=$customer->save();$suggest=new WC_Order();$suggest->set_customer_id($customerId);
    $check(RecipientIdentity::defaults($suggest,$saved)===$withoutMiddle,'Customer-card fallback');
    $check(RecipientIdentity::defaults($suggest,['name'=>'Інший Тест','phone'=>$saved['phone']])===$empty,'Customer card cannot override another recipient');
    $check((new WC_Customer($customerId))->get_billing_last_name()==='Приклад','Suggestions do not modify customer card');
    $order=$make(true);$rows=(new ShipmentRepository())->findByOrder($order->get_id());$id=(int)$rows[0]['id'];$oid=$order->get_id();
    ob_start();(new Paint\NovaPoshta\Admin\ShipmentLifecyclePanel())->render($order);$draftPanel=ob_get_clean();
    $check(str_contains($draftPanel,'data-person="FirstName" value="Тест"') && str_contains($draftPanel,'data-person="LastName" value="Приклад"'),'Rendered automatic suggestions');
    $reject(fn()=>$service->row($oid+100000,$id),'Cross-order access');
    $manager->allcaps=[];$reject(fn()=>$service->row($oid,$id),'Permission denied');$manager->allcaps=['manage_woocommerce'=>true,'manage_pnpm_shipments'=>true];
    $preview=$service->preview($oid,$id,$withoutMiddle);$tokens[]=$preview['token'];
    $check($writes===0,'Preview never mutates carrier');
    $check($preview['payload']['Cost']===100 && $preview['payload']['OptionsSeat'][0]['volumetricVolume']===0.000108,'Per-warehouse goods and cubic metres');
    $check($preview['estimate']===80.0,'Live estimate returned');
    $enabled=false;$reject(fn()=>$service->create($oid,$id,$preview['token']),'Write gate');$enabled=true;
    $beforeMail=count($mails);$result=$service->create($oid,$id,$preview['token']);
    $check(!empty($result['ttn']) && $writes===2,'One recipient write and one TTN write');
    $sentPerson=array_values(array_filter($requests,static fn($p)=>$p['modelName']==='CounterpartyGeneral' && $p['calledMethod']==='save'))[0]['methodProperties'];
    $check($sentPerson['MiddleName']==='','Blank patronymic reaches carrier unchanged');
    $check(count($mails)===$beforeMail,'Create does not notify');
    $check($service->create($oid,$id,$preview['token'])===$result && $writes===2,'Duplicate create returns saved result');
    $reject(fn()=>$prep->context(wc_get_order($oid)),'Preparation freezes after create');
    $check(str_starts_with($service->pdf($oid,$id,'a4'),'%PDF-'),'Server-side PDF');
    $check(str_starts_with($service->pdf($oid,$id,'document'),'%PDF-'),'Document PDF');
    $check(str_starts_with($service->pdf($oid,$id,'zebra'),'%PDF-'),'Zebra PDF');
    $pdfMode='html';$reject(fn()=>$service->pdf($oid,$id,'a4'),'Reject non-PDF');$pdfMode='pdf';
    $reject(fn()=>$service->pdf($oid,$id,'../../bad'),'Allowlisted format');
    $reject(fn()=>$service->email($oid,$id,hash('sha256','wrong@example.invalid')),'Stale notification destination');
    $hash=hash('sha256',$order->get_billing_email());
    $check($service->email($oid,$id,$hash)==='np_email_accepted','Mail acceptance persisted');
    $service->email($oid,$id,$hash);$check(count($mails)===$beforeMail+1,'Email deduplicated');
    $check(end($mails)['to']==='np-fixture@example.invalid' && str_contains(end($mails)['message'],$result['ttn']),'Mail to order customer with TTN');
    $second=(int)$rows[1]['id'];$p=$service->preview($oid,$second,$names);$tokens[]=$p['token'];$r=$service->create($oid,$second,$p['token']);
    $check(!empty($r['ttn']) && $r['ttn']!==$result['ttn'],'Second warehouse has separate TTN');
    $check(wc_get_order($oid)->get_status()==='pending','Order status not advanced');
    $mailOrder=$make();$mailId=(int)(new ShipmentRepository())->findByOrder($mailOrder->get_id())[0]['id'];
    $mp=$service->preview($mailOrder->get_id(),$mailId,$names);$tokens[]=$mp['token'];$service->create($mailOrder->get_id(),$mailId,$mp['token']);
    $failMail=static fn()=>false;add_filter('pre_wp_mail',$failMail,PHP_INT_MAX);
    try {
        $check($service->email($mailOrder->get_id(),$mailId,hash('sha256',$mailOrder->get_billing_email()))==='np_email_failed','Mail failure persisted');
        $sent=count($mails);$service->email($mailOrder->get_id(),$mailId,hash('sha256',$mailOrder->get_billing_email()));
        $check(count($mails)===$sent,'Failed email not retried');
    } finally {remove_filter('pre_wp_mail',$failMail,PHP_INT_MAX);}
    foreach (['timeout','rejected','write_limited'] as $next) {
        $o=$make();$row=(new ShipmentRepository())->findByOrder($o->get_id())[0];$sid=(int)$row['id'];$p=$service->preview($o->get_id(),$sid,$names);$tokens[]=$p['token'];$mode=$next;
        $check($service->create($o->get_id(),$sid,$p['token'])===['unknown'=>true],$next.' fail closed');$n=$writes;
        $reject(fn()=>$service->create($o->get_id(),$sid,$p['token']),$next.' replay denied');
        $check($writes===$n,$next.' no retry');$mode='success';
    }
    $rateOrder=$make();$rateId=(int)(new ShipmentRepository())->findByOrder($rateOrder->get_id())[0]['id'];
    $p=$service->preview($rateOrder->get_id(),$rateId,$names);$tokens[]=$p['token'];$before=$writes;$mode='read_limited';
    try { $service->create($rateOrder->get_id(),$rateId,$p['token']); throw new LogicException('Expected rate rejection'); }
    catch (RuntimeException $e) { $check(str_contains($e->getMessage(),'No shipment creation was started'),'Actionable pre-claim rate message'); }
    $check($writes===$before && $service->row($rateOrder->get_id(),$rateId)['status']==='manager_draft','Rate failure leaves preparation unchanged without writes');
    $mode='read_limit_once';$p=$service->preview($rateOrder->get_id(),$rateId,$names);$tokens[]=$p['token'];
    $check($p['enabled'] && $writes===$before,'Single read rate retry recovers preview without writes');
    foreach (['identity_mismatch','identity_missing'] as $next) {
        $o=$make();$sid=(int)(new ShipmentRepository())->findByOrder($o->get_id())[0]['id'];
        $p=$service->preview($o->get_id(),$sid,$names);$tokens[]=$p['token'];$mode=$next;$before=$writes;
        $check($service->create($o->get_id(),$sid,$p['token'])===['recipient_mismatch'=>true],$next.' reported');
        $check($writes===$before+1 && $service->row($o->get_id(),$sid)['status']==='np_recipient_mismatch',$next.' no TTN write');
        $reject(fn()=>$service->create($o->get_id(),$sid,$p['token']),$next.' no automatic retry');$mode='success';
    }
    $o=$make();$row=(new ShipmentRepository())->findByOrder($o->get_id())[0];$sid=(int)$row['id'];$p=$service->preview($o->get_id(),$sid,$names);$tokens[]=$p['token'];
    $o->set_total(101);$o->save();$n=$writes;$reject(fn()=>$service->create($o->get_id(),$sid,$p['token']),'Stale order');$check($writes===$n,'Stale order no writes');
    $o->set_payment_method('cod');$o->save();$reject(fn()=>$service->preview($o->get_id(),$sid,$names),'COD gate');
    $o->set_payment_method('bacs');$o->save();
    global $wpdb;
    $p=$service->preview($o->get_id(),$sid,$names);$tokens[]=$p['token'];
    $expired=get_transient('pnpm_create_'.$p['token']);$expired['expires']=time()-1;set_transient('pnpm_create_'.$p['token'],$expired,300);
    $reject(fn()=>$service->create($o->get_id(),$sid,$p['token']),'Expired preview');
    $p=$service->preview($o->get_id(),$sid,$names);$tokens[]=$p['token'];
    $break=static fn($q)=>str_starts_with($q,"INSERT INTO `{$wpdb->prefix}pnpm_shipment_events`")?'INVALID FIXTURE QUERY':$q;
    $suppress=$wpdb->suppress_errors(true);add_filter('query',$break);
    try {$reject(fn()=>$service->create($o->get_id(),$sid,$p['token']),'Claim audit failure');}finally{remove_filter('query',$break);$wpdb->suppress_errors($suppress);}
    $check($writes===$n,'No external write on failed durable claim');
    $check($service->row($o->get_id(),$sid)['status']==='manager_draft','Claim rolled back');
    $wpdb->update($wpdb->prefix.'pnpm_shipments',['source'=>'customer_external'],['id'=>$sid]);
    $reject(fn()=>$service->pdf($o->get_id(),$sid,'a4'),'No external TTN printing');
    $point=['selectable'=>true,'kind'=>'postomat'];
    $reject(fn()=>ShipmentLifecycle::checkPoint($point,[['weight'=>21,'length'=>18,'width'=>2,'height'=>3]],100,'receiving'),'Locker weight limit');
    $reject(fn()=>ShipmentLifecycle::checkPoint($point,[['weight'=>1,'length'=>61,'width'=>2,'height'=>3]],100,'receiving'),'Locker dimensions');
    $check(ShipmentLifecycle::createdDocument(['success'=>true,'data'=>[['Ref'=>$refs[8],'IntDocNumber'=>'not-ttn']]])===null,'Malformed success unknown');
    ob_start();(new Paint\NovaPoshta\Admin\ShipmentLifecyclePanel())->render(wc_get_order($oid));$html=ob_get_clean();
    $check(str_contains($html,'pnpm_shipment_pdf') && str_contains($html,'data-shipment-command="email"') && !str_contains($html,'apiKey/'),'Manager controls without carrier secret URL');
    if ($dir=getenv('PNPM_LIFECYCLE_FIXTURE_DIR')) {
        if (!is_dir($dir) || !str_starts_with(realpath($dir),'/private/tmp/')) throw new RuntimeException('Fixture directory must be under /private/tmp');
        switch_to_locale('uk');
        ob_start();(new Paint\NovaPoshta\Admin\ShipmentLifecyclePanel())->render(wc_get_order($oid));$created=ob_get_clean();
        $draft=$make();
        ob_start();(new Paint\NovaPoshta\Admin\ShipmentLifecyclePanel())->render($draft);$draftHtml=ob_get_clean();
        $page='<!doctype html><html lang="uk"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>НП — синтетична локальна перевірка</title><style>body{font:16px/1.5 system-ui;max-width:1050px;margin:24px auto;padding:16px}*{box-sizing:border-box}input,button{font:inherit;padding:8px;max-width:100%}.pnpm-legal-name{display:flex;gap:12px;flex-wrap:wrap}.pnpm-legal-name label{display:flex;flex-direction:column;flex:1;min-width:200px}.pcoe-card{border:1px solid #ddd;border-radius:8px;padding:18px;margin:20px 0}.pnpm-shipment{border-top:1px solid #ddd;padding:16px 0}button{cursor:pointer}button:disabled{cursor:not-allowed;color:#777}details{background:#f5f5f5;padding:10px}h2{font-size:23px}</style><h1>НП — синтетична локальна перевірка</h1><p>Жодної реальної ТТН чи повідомлення. Кнопки транспорту в цьому статичному прикладі не виконують запити.</p>'.$draftHtml.$created.'<script>document.querySelectorAll("form").forEach(function(f){f.addEventListener("submit",function(e){e.preventDefault()})});</script></html>';
        $panel=new Paint\NovaPoshta\Admin\ShipmentLifecyclePanel();
        $previous_get=$_GET;$_GET=['page'=>'pcoe-customers','order_id'=>$draft->get_id()];$panel->assets();$_GET=$previous_get;
        $config=wp_scripts()->get_data('pnpm-lifecycle','data');
        $uiPreview=$preview;$uiPreview['enabled']=true;
        $scripts='<script>'.file_get_contents(ABSPATH.'wp-includes/js/jquery/jquery.min.js').'</script><script>'.$config.';jQuery.post=function(){var d=jQuery.Deferred();setTimeout(function(){d.resolve({success:true,data:{preview:'.wp_json_encode($uiPreview).'}})},50);return d.promise()};</script><script>'.file_get_contents(PNPM_DIR.'assets/shipment-lifecycle.js').'</script>';
        $page=str_replace('</html>',$scripts.'</html>',$page);
        file_put_contents($dir.'/lifecycle.html',$page);restore_previous_locale();
    }
    echo "Shipment lifecycle: {$checks} checks passed. All HTTP/mail intercepted; synthetic fixtures cleaned.\n";
} finally {
    global $wpdb;
    foreach ($orders as $order) {
        foreach ((new ShipmentRepository())->findByOrder($order->get_id()) as $row) {
            foreach(['pnpm_shipment_items','pnpm_shipment_events'] as $table)$wpdb->delete($wpdb->prefix.$table,['shipment_id'=>$row['id']]);
            $wpdb->delete($wpdb->prefix.'pnpm_shipments',['id'=>$row['id']]);
        }
        $order->delete(true);
    }
    foreach($tokens as $token)delete_transient('pnpm_create_'.$token);
    delete_transient('pnpm_sender_directory_v1');
    if ($customerId) { require_once ABSPATH.'wp-admin/includes/user.php'; wp_delete_user($customerId); }
    $GLOBALS['current_user']=$old;
}
