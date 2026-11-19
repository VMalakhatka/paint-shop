<?php
/** Synthetic local fixtures; bootstrap blocks all HTTP/mail before WordPress loads. */
if (!defined('WP_CLI') || !WP_CLI || wp_get_environment_type()!=='local' || PNPM_NOVA_POSHTA_API_KEY!=='synthetic-test-key') throw new RuntimeException('Intercepted local CLI only');
use Paint\NovaPoshta\Infrastructure\{ApiClient,ApiRequestThrottle,CourierPickup,CourierStorage,CourierQueue,ManagerPreparation,ShipmentLifecycle,ShipmentRepository};
$checks=0;
$check=static function($ok,$name) use (&$checks) { if (!$ok) throw new RuntimeException($name); ++$checks; };
$reject=static function($fn,$name) use ($check) { try { $fn(); } catch (RuntimeException $e) { $check(true,$name); return; } throw new RuntimeException('Expected rejection: '.$name); };
$refs=[]; foreach(range(1,9) as $n) $refs[$n]=sprintf('00000000-0000-0000-0000-%012d',$n);
$terms=get_terms(['taxonomy'=>'location','hide_empty'=>false]); if (is_wp_error($terms) || !$terms) throw new RuntimeException('Fixture location needed');
$location=(int)$terms[0]->term_id;
$mapping=['enabled'=>'yes','sender_type'=>'doors','city_ref'=>$refs[1],'counterparty_ref'=>$refs[2],'contact_ref'=>$refs[3],'sender_address_ref'=>$refs[4],'phone'=>'380000000000','customer_label'=>'Synthetic pickup','sender_address_label'=>'Fixture pickup address'];
add_filter('pre_option_pnpm_location_mappings',static fn()=>[$location=>$mapping]);
$writeEnabled=true; add_filter('pre_option_pnpm_settings',static function() use (&$writeEnabled) { return ['writes_enabled'=>$writeEnabled?'yes':'no']; });
$enrolled=false; $noWait=new class extends ApiRequestThrottle { public function run(string $key,callable $request) { return $request(); } };
$api=new ApiClient($noWait); $s=new CourierPickup($api); $lifecycle=new ShipmentLifecycle($api); $prep=new ManagerPreparation();
add_filter('pre_option_pnpm_courier_enrollment',static function() use (&$enrolled,$api,$refs) { return $enrolled?[$refs[2]=>['account'=>$api->accountFingerprint()]]:[]; });
$old=$GLOBALS['current_user']; $manager=new WP_User();$manager->ID=PHP_INT_MAX;$manager->allcaps=['manage_woocommerce'=>true,'manage_pnpm_shipments'=>true,'manage_options'=>true];$GLOBALS['current_user']=$manager;
$mode='ok';$writes=[];$calls=[];$person=[];$ttn=22000000000100;$callNo=100;$attached=[];$addressValid=true;
add_filter('pre_http_request',static function($pre,$args,$url) use (&$mode,&$writes,&$calls,&$person,&$ttn,&$callNo,&$attached,&$addressValid,$refs) {
    if ($url!=='https://api.novaposhta.ua/v2.0/json/') return new WP_Error('fixture_blocked','All other HTTP blocked');
    $p=json_decode($args['body'],true);unset($p['apiKey']);$calls[]=$p;$m=$p['calledMethod'];$prop=$p['methodProperties'];$data=[];
    if (in_array($m,['save','saveCourierCall','insertDocuments'],true)) $writes[]=$p;
    if ($m==='getCounterparties') $data=[['Ref'=>$refs[2],'Description'=>'Synthetic sender']];
    elseif ($m==='getCounterpartyAddresses') $data=$addressValid?[['Ref'=>$refs[4],'CityRef'=>$refs[1],'Description'=>'Fixture pickup address']]:[];
    elseif ($m==='getCounterpartyContactPersons') $data=$prop['Ref']===$refs[2]?[['Ref'=>$refs[3],'Phones'=>'380000000000']]:[['Ref'=>$refs[7],'Phones'=>$person['Phone']]+$person];
    elseif ($m==='getWarehouses') $data=[['Ref'=>$refs[5],'CityRef'=>$refs[1],'Description'=>'Fixture destination','Number'=>'1','CategoryOfWarehouse'=>'Branch','WarehouseStatus'=>'Working','PlaceMaxWeightAllowed'=>30,'TotalMaxWeightAllowed'=>30]];
    elseif ($m==='getDocumentPrice') $data=[['Cost'=>100]];
    elseif ($m==='save' && $p['modelName']==='CounterpartyGeneral') { $person=$prop;$data=[['Ref'=>$refs[6],'ContactPerson'=>['success'=>true,'data'=>[['Ref'=>$refs[7]]+$person]]]]; }
    elseif ($m==='save') $data=[['Ref'=>sprintf('00000000-0000-0000-0000-%012d',++$ttn - 22000000000000),'IntDocNumber'=>(string)$ttn,'CostOnSite'=>100]];
    elseif ($m==='getPickupTimeIntervals') $data=$mode==='no_slots'?[]:[['Number'=>'CityPickingTimeInterval3','Start'=>'08:00','End'=>'18:00','BoundaryTime'=>'15:00']];
    elseif ($m==='saveCourierCall') {
        if ($mode==='create_timeout') return new WP_Error('timeout','Synthetic timeout');
        if ($mode==='create_reject') return ['response'=>['code'=>200],'body'=>wp_json_encode(['success'=>false,'errors'=>['Synthetic rejection'],'data'=>[]])];
        $data=[['Barcode'=>'102-'.str_pad((string)++$callNo,8,'0',STR_PAD_LEFT)]];
        if ($mode==='create_warning') return ['response'=>['code'=>200],'body'=>wp_json_encode(['success'=>true,'warnings'=>['Synthetic ambiguous result'],'data'=>$data])];
    } elseif ($m==='insertDocuments') {
        $attached[$prop['Number']]=$prop['LinkedDocuments'];
        if ($mode==='insert_timeout') return new WP_Error('timeout','Synthetic timeout after write');
        if ($mode==='partial') $attached[$prop['Number']]=array_slice($prop['LinkedDocuments'],0,1);
        $data=[['Barcode'=>$prop['Number'],'succeeded'=>$attached[$prop['Number']]]];
    } elseif ($m==='getOrdersListCourierCall') {
        foreach($attached as $number=>$docs) $data[]=['Number'=>$number,'Status'=>'ReadyToDone','StatusDescription'=>'Готово до виконання','Ref'=>$refs[9]];
        if ($mode==='old_list') $data=[];
    } elseif ($m==='getEWTemplateList') {
        if ($prop['Page']==='1') foreach($attached[$prop['PickupNumber']] ?? [] as $number) $data[]=['IntDocNumber'=>$number];
        if ($mode==='repeat_page') $data=[['IntDocNumber'=>$attached[$prop['PickupNumber']][0]]];
    } else throw new RuntimeException('Unexpected method: '.$m);
    return ['response'=>['code'=>200],'body'=>wp_json_encode(['success'=>true,'data'=>$data,'errors'=>[],'warnings'=>[]])];
},PHP_INT_MAX,3);
$orders=[];$callIds=[];$tokens=[];$shipments=[];
$names=['FirstName'=>'Тест','LastName'=>'Кур’єр','MiddleName'=>''];
$make=static function() use (&$orders,&$shipments,$location,$refs,$prep,$lifecycle,$names,&$tokens) {
    $o=new WC_Order();$o->set_currency('UAH');$o->set_shipping_first_name($names['FirstName']);$o->set_shipping_last_name($names['LastName']);$o->set_shipping_phone('380000000000');
    $o->update_meta_data('_pnpm_recipient',['city_ref'=>$refs[1],'city_label'=>'Fixture city','point_ref'=>$refs[5],'point_label'=>'Fixture destination','delivery_type'=>'branch']);
    $item=new WC_Order_Item_Product();$item->set_name('Courier fixture goods');$item->set_quantity(1);$item->set_subtotal(100);$item->set_total(100);$item->add_meta_data('_slw_data',[$location=>['quantity_subtracted'=>1]]);$o->add_item($item);$o->set_total(100);$o->save();$orders[]=$o;
    $ctx=$prep->context($o);$prep->save($o->get_id(),['revision'=>$ctx['revision'],'agreed'=>'1','recipient'=>$ctx['recipient'],'cod_settings'=>['enabled'=>false],'groups'=>[$location=>['handover'=>'doors','payer'=>'Recipient','boxes'=>[['weight'=>1.5,'length'=>20,'width'=>15,'height'=>10]]]]]);
    $row=(new ShipmentRepository())->findByOrder($o->get_id())[0];$shipments[]=(int)$row['id'];
    return [$o,(int)$row['id']];
};
$createShipment=static function() use ($make,$lifecycle,$names,&$tokens,$check) {
    [$o,$id]=$make();$p=$lifecycle->preview($o->get_id(),$id,$names);$tokens[]='pnpm_create_'.$p['token'];
    $lifecycle->create($o->get_id(),$id,$p['token']);
    return $id;
};
$tomorrow=wp_date('Y-m-d',time()+86400,new DateTimeZone('Europe/Kyiv'));
$preview=static function($ids,$offset=0) use ($s,$tomorrow,&$tokens) {
    $date=(new DateTimeImmutable($tomorrow))->modify('+'.$offset.' days')->format('Y-m-d');
    $p=$s->preview($ids,$date,'CityPickingTimeInterval3',(string)(count($ids)*1.5));$tokens[]='pnpm_pickup_'.$p['token'];return $p;
};
try {
    CourierStorage::install();$check(CourierStorage::ready(),'Schema ready');
    [$o,$id]=$make();$reject(fn()=>$lifecycle->preview($o->get_id(),$id,$names),'Missing enrollment blocks address TTN');
    $check(!$writes,'No calls without enrollment');$enrolled=true;
    $addressValid=false;$reject(fn()=>$lifecycle->preview($o->get_id(),$id,$names),'Unowned address rejected');$addressValid=true;
    $p=$lifecycle->preview($o->get_id(),$id,$names);$tokens[]='pnpm_create_'.$p['token'];$lifecycle->create($o->get_id(),$id,$p['token']);
    $carrier=end($writes);$check($carrier['calledMethod']==='save' && $carrier['methodProperties']['ServiceType']==='DoorsWarehouse' && $carrier['methodProperties']['SenderAddress']===$refs[4],'Address-origin TTN payload');
    $check(count($writes)===2,'Creating TTN does not call courier');
    $id2=$createShipment();$ids=[$id,$id2];
    $check(count($s->selection($ids)['ttns'])===2,'Two created TTNs eligible');
    $slots=$s->intervals($ids,$tomorrow);$check(count($slots)===1 && $slots[0]['number']==='CityPickingTimeInterval3','Official interval contract');
    $reject(fn()=>$s->preview($ids,$tomorrow,'invented','3'),'Unlisted interval rejected');
    $reject(fn()=>$s->preview($ids,$tomorrow,'CityPickingTimeInterval3','2'),'Underweight rejected');
    $reject(fn()=>$s->preview($ids,'2000-01-01','CityPickingTimeInterval3','3'),'Past date rejected');
    $p=$preview($ids);$before=count($writes);$writeEnabled=false;$reject(fn()=>$s->create($p['token']),'Write-disabled create blocked');$writeEnabled=true;
    $check(count($writes)===$before,'Gate blocks remote calls');
    $expired=$preview($ids);$cache=get_transient('pnpm_pickup_'.$expired['token']);$cache['expires']=time()-1;set_transient('pnpm_pickup_'.$expired['token'],$cache,300);
    $reject(fn()=>$s->create($expired['token']),'Expired preview rejected');
    $changed=$preview($ids);$cache=get_transient('pnpm_pickup_'.$changed['token']);$cache['selection']['hash']=str_repeat('0',64);set_transient('pnpm_pickup_'.$changed['token'],$cache,300);
    $reject(fn()=>$s->create($changed['token']),'Changed shipment snapshot rejected');
    $foreign=$preview($ids);$cache=get_transient('pnpm_pickup_'.$foreign['token']);$cache['account']=str_repeat('0',64);set_transient('pnpm_pickup_'.$foreign['token'],$cache,300);
    $reject(fn()=>$s->create($foreign['token']),'Changed API account rejected');
    $check(count($writes)===$before,'Invalid previews make no writes');
    $row=$s->create($p['token']);$callIds[]=(int)$row['id'];$check($row['status']==='documents_added','Call plus all documents confirmed');
    $check(count($writes)===$before+2,'Exactly two pickup writes');
    $last=array_slice($writes,-2);$check(array_keys($last[0]['methodProperties'])===['ContactSenderRef','PreferredDeliveryDate','PlanedWeight','TimeInterval','CounterpartySender','AddressSenderRef'],'Exact create contract fields');
    $check(count($last[1]['methodProperties']['LinkedDocuments'])===2 && $last[1]['methodProperties']['Number']===$row['barcode'],'TTN numbers added as one batch');
    $s->create($p['token']);$check(count($writes)===$before+2,'Double submit idempotent');
    $reject(fn()=>$preview($ids),'Members cannot join another call');
    $new=$createShipment();$reject(fn()=>$preview([$new]),'Same address interval with different TTNs rejected');
    $checked=$s->refresh((int)$row['id']);$r=json_decode($checked['response_snapshot'],true);$check($r['read']['matches']===true && $r['read']['call']['Status']==='ReadyToDone','Fresh carrier membership and status');
    $mode='old_list';$checked=$s->refresh((int)$row['id']);$r=json_decode($checked['response_snapshot'],true);$check($r['read']['call']===null && $r['read']['matches'],'Missing seven-day list does not erase membership');$mode='ok';
    foreach(['create_timeout','create_reject','insert_timeout','partial','create_warning'] as $i=>$failure) {
        $a=$createShipment();$b=$createShipment();$p=$preview([$a,$b],$i+1);$mode=$failure;$before=count($writes);
        $row=$s->create($p['token']);$callIds[]=(int)$row['id'];$check($row['status']!=='documents_added',$failure.' is not success');
        if ($failure==='create_warning') $check(CourierPickup::number($row['barcode']),'Ambiguous create preserves number for reconciliation');
        $s->create($p['token']);$check(count($writes)===$before+(str_starts_with($failure,'create_')?1:2),$failure.' not retried');
        $reject(fn()=>$preview([$a,$b],$i+7),$failure.' reserves members');
        if ($failure==='insert_timeout' || $failure==='partial') {
            $mode='ok';$checked=$s->refresh((int)$row['id']);$r=json_decode($checked['response_snapshot'],true);
            $check($r['read']['matches']===($failure==='insert_timeout'),'Read reconciles '.$failure);
        }
        $mode='ok';
    }
    $manager->allcaps=[];$reject(fn()=>$s->intervals([$new],$tomorrow),'Capability checks');$manager->allcaps=['manage_woocommerce'=>true,'manage_pnpm_shipments'=>true,'manage_options'=>true];
    $before=count($writes);foreach(['saveCourierCall','insertDocuments','updateCourierCall','deleteCourierCall','removeDocuments','unknownFutureMutation'] as $method) $check(is_wp_error($api->call('CarCallGeneral',$method,[])),'Fail-closed write gate '.$method);
    $check(count($writes)===$before,'No write bypass');
    $check(!CourierPickup::insertConfirmed(['success'=>true,'data'=>[['Barcode'=>'102-1','succeeded'=>['22000000000001']]]],'102-2',['22000000000001']),'Wrong barcode blocked');
    $check(!CourierPickup::insertConfirmed(['success'=>true,'data'=>[['Barcode'=>'102-1','succeeded'=>['22000000000001','22000000000001']]]],'102-1',['22000000000001']),'Duplicate success rows blocked');
    if ($dir=getenv('PNPM_COURIER_FIXTURE_DIR')) {
        if (!is_dir($dir)) mkdir($dir,0700,true);
        switch_to_locale('uk');load_textdomain('paint-nova-poshta-multishipping',dirname(__DIR__).'/languages/paint-nova-poshta-multishipping-uk.mo');
        ob_start();(new Paint\NovaPoshta\Admin\CourierPage())->render();$html=ob_get_clean();restore_previous_locale();
        file_put_contents($dir.'/courier-full.html','<!doctype html><html lang="uk"><meta charset="utf-8"><style>body{font:16px/1.5 system-ui;background:#f0f0f1;margin:24px}.card{background:white;border:1px solid #ccc;padding:20px;margin:16px 0}table{width:100%;text-align:left}th,td{padding:10px}button,input,select{font:inherit;padding:6px}a{color:#3858e9}</style>'.$html.'</html>');
        $check(str_contains($html,'pnpm-courier-select') && str_contains($html,'data-courier-command="refresh"'),'Ready selection and durable history rendered');
    }
    echo "Courier pickup: $checks checks passed; no real HTTP/mail\n";
} finally {
    global $wpdb;
    foreach($tokens as $token) delete_transient($token);
    foreach($callIds as $id) foreach(['events','members','calls'] as $suffix) $wpdb->delete($wpdb->prefix.'pnpm_courier_'.$suffix,[$suffix==='calls'?'id':'call_id'=>$id]);
    foreach($shipments as $id) foreach(['pnpm_shipment_events','pnpm_shipment_items','pnpm_shipments'] as $table) $wpdb->delete($wpdb->prefix.$table,[$table==='pnpm_shipments'?'id':'shipment_id'=>$id]);
    foreach($orders as $o) $o->delete(true);
    delete_transient('pnpm_sender_directory_v1');$GLOBALS['current_user']=$old;
}
