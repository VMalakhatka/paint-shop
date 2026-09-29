<?php
/** Local integration: mock all HTTP/mail; disposable orders/products/users/locations only. */
use PaintCore\PCOE\CustomerApproval as Approval;
use PaintCore\PCOE\ApprovalDelivery as Delivery;
use Paint\NovaPoshta\Infrastructure\RecipientDirectory;
use Paint\NovaPoshta\Infrastructure\ApiClient;
use Paint\NovaPoshta\Infrastructure\ApprovalQuoteService;
use Paint\NovaPoshta\Domain\DocumentShipmentBuilder;
if (!defined('WP_CLI') || !WP_CLI || wp_parse_url(home_url(),PHP_URL_HOST)!=='paint.local') throw new RuntimeException('Local only');
$users=[];$orders=[];$terms=[];$product=null;$id=0;$tokens=[];$cache=[];$calls=[];$original=get_current_user_id();$checks=0;
$uuid=wp_generate_uuid4();$city=wp_generate_uuid4();$point=wp_generate_uuid4();$sender=wp_generate_uuid4();
$mappings=[];$kind='Branch';$status='Working';$weightLimit=30;$apiFail=false;
$gateway=WC()->payment_gateways()->payment_gateways()['bacs'];$gatewayEnabled=$gateway->enabled;$gateway->enabled='yes';
add_filter('pre_option_pnpm_settings',static fn()=>['checkout_enabled'=>'yes','weight_mode'=>'grams','fallback_item_weight_kg'=>0.25]);
add_filter('pre_option_pnpm_location_mappings',static function()use(&$mappings){return $mappings;});
add_filter('pre_wp_mail','__return_true',PHP_INT_MAX);
add_filter('pre_http_request',static function($pre,$args,$url)use(&$calls,&$cache,$city,$point,&$kind,&$status,&$weightLimit,&$apiFail){
    if (!str_starts_with($url,'https://api.novaposhta.ua/')) return new WP_Error('blocked','External HTTP blocked');
    $request=json_decode($args['body'],true);$method=$request['calledMethod'];$p=$request['methodProperties'];$calls[]=$method;
    if (!in_array($method,['searchSettlements','getWarehouses','getDocumentPrice'],true)) throw new RuntimeException('Mutation blocked');
    if ($apiFail) return new WP_Error('offline','Simulated API failure');
    $data=match($method){
        'searchSettlements'=>[['Addresses'=>[['DeliveryCity'=>$city,'Present'=>'Тестове місто','Ref'=>$city]]]],
        'getWarehouses'=>[['Ref'=>$point,'CityRef'=>$city,'Description'=>'Відділення №7','WarehouseStatus'=>$status,'CategoryOfWarehouse'=>$kind,'PlaceMaxWeightAllowed'=>$weightLimit,'TotalMaxWeightAllowed'=>100,'MaxDeclaredCost'=>10000]],
        'getDocumentPrice'=>[['Cost'=>($p['ServiceType']==='WarehouseDoors'?120:80)]],
    };
    if($method==='getDocumentPrice')$cache[]='pnpm_price_'.md5(wp_json_encode($p));
    return ['response'=>['code'=>200],'body'=>wp_json_encode(['success'=>true,'data'=>$data])];
},PHP_INT_MAX,3);
if(!defined('PNPM_NOVA_POSHTA_API_KEY') && !getenv('PNPM_NOVA_POSHTA_API_KEY'))putenv('PNPM_NOVA_POSHTA_API_KEY=mock-only');
$check=static function($ok,$why)use(&$checks){if(!$ok)throw new RuntimeException($why);$checks++;};
$deny=static function($fn,$why)use($check){try{$fn();}catch(Throwable $e){$check(true,$why);return;}throw new RuntimeException('Expected denial: '.$why);};
try {
    foreach(['opt','opt'] as $role){$tag=wp_generate_uuid4();$u=wp_insert_user(['user_login'=>'np-approval-'.$tag,'user_email'=>$tag.'@example.invalid','role'=>$role,'user_pass'=>wp_generate_password(30)]);if(is_wp_error($u))throw new RuntimeException('User fixture');$users[]=$u;}
    foreach(['A','B'] as $name){$term=wp_insert_term('Approval '.$name.' '.$uuid,'location');if(is_wp_error($term))throw new RuntimeException('Location fixture');$terms[]=$term['term_id'];$mappings[$term['term_id']]=['enabled'=>'yes','city_ref'=>$sender,'sender_type'=>'warehouse','customer_label'=>'Тестовий склад '.$name];}
    update_term_meta($terms[0],'lavka_folio_warehouses',[['id'=>'test-folio-'.$uuid,'priority'=>10]]);
    $product=new WC_Product_Simple();$product->set_name('Approval NP test');$product->set_sku('approval-np-'.$uuid);$product->set_regular_price(100);$product->set_weight(1000);$product->save();
    wp_set_current_user($users[0]);
    $order=wc_create_order(['customer_id'=>$users[0],'status'=>'pc-draft']);$orders[]=$order->get_id();
    $item=new WC_Order_Item_Product();$item->set_product($product);$item->set_quantity(2);$item->set_subtotal(200);$item->set_total(200);$item->add_meta_data('_folio_warehouse_id','test-folio-'.$uuid);$order->add_item($item);$order->calculate_totals();$order->save();
    $source=['customer_id'=>$users[0],'order_id'=>$order->get_id(),'type'=>'','document_id'=>0];$snapshot=Approval::snapshot($source);$revision=Approval::revision($snapshot);
    $data=['source'=>$source,'snapshot'=>$snapshot,'revision'=>$revision,'status'=>'pending','history'=>[]];
    $id=(new ReflectionMethod(Approval::class,'save'))->invoke(null,$data);
    $methods=array_filter(array_keys(Approval::choices()['delivery']),[Delivery::class,'selected']);$method=reset($methods);$check((bool)$method,'Enabled maintained NP method is available');
    $plan=(new DocumentShipmentBuilder())->build($order,$snapshot);
    $check(count($plan['shipments'])===1 && $plan['shipments'][0]['location_id']===$terms[0] && $plan['shipments'][0]['weight_kg']===2.0,'Folio child warehouse maps to its physical sender and saved quantities');
    $folio=['currency'=>'UAH','warehouseId'=>'test-folio-'.$uuid,'items'=>[['sku'=>$product->get_sku(),'quantity'=>2,'amount'=>200]]];
    $check((new DocumentShipmentBuilder())->build(null,$folio)['shipments'][0]['declared_cost']===200.0,'Folio-only document quotes its own quantities and amounts');
    $directory=new RecipientDirectory(new ApiClient());$directory->searchCities('test-'.$uuid);$cache[]='pnpm_recipient_cities_'.md5('test-'.$uuid);$cache[]='pnpm_city_'.md5($city);
    $input=['delivery'=>$method,'pnpm_city_ref'=>$city,'pnpm_point_ref'=>$point,'pnpm_delivery_type'=>'branch','pnpm_city_label'=>'FORGED','pnpm_point_label'=>'FORGED','payment'=>'bacs'];
    $quote=Delivery::quote($id,$revision,$input);$tokens[]=$quote['token'];
    $check($quote['recipient']['city_label']==='Тестове місто' && $quote['recipient']['point_label']==='Відділення №7','Labels come from official responses, not POST');
    $check(str_contains($quote['summary'],'80') && count($quote['parcels'])===1,'Read-only tariff and parcel total are shown');
    $input['delivery_quote_token']=$quote['token'];$saved=Delivery::validate($id,$data,$snapshot,$input);
    $check($saved['policy']['total']===80.0 && Delivery::destination($saved)==='Тестове місто, Відділення №7','Receipt preserves validated destination and policy');
    $deny(fn()=>Delivery::validate($id+1,$data,$snapshot,$input),'Receipt belongs to one approval');
    $deny(fn()=>Delivery::validate($id,$data,$snapshot,array_replace($input,['pnpm_point_ref'=>wp_generate_uuid4()])),'Changing destination invalidates quote');
    $deny(fn()=>Delivery::quote($id,'stale',$input),'Stale revision cannot quote');
    wp_set_current_user($users[1]);$deny(fn()=>Delivery::quote($id,$revision,$input),'Foreign customer cannot quote');$deny(fn()=>Delivery::validate($id,$data,$snapshot,$input),'Foreign customer cannot replay receipt');wp_set_current_user($users[0]);
    $status='Closed';$deny(fn()=>Delivery::quote($id,$revision,$input),'Closed point rejected');$status='Working';
    $weightLimit=1;$deny(fn()=>Delivery::quote($id,$revision,$input),'Per-place overweight rejected');$weightLimit=30;
    $deny(fn()=>Delivery::quote($id,$revision,array_replace($input,['pnpm_delivery_type'=>'parcel_locker'])),'Branch cannot masquerade as locker');
    $kind='Postomat';$locker=Delivery::quote($id,$revision,array_replace($input,['pnpm_delivery_type'=>'parcel_locker']));$tokens[]=$locker['token'];$check(str_contains($locker['summary'],'90'),'Locker uses existing configured surcharge');$kind='Branch';
    $courierInput=array_replace($input,['pnpm_delivery_type'=>'address','pnpm_point_ref'=>'','pnpm_address'=>'Тестова, 12']);
    $courier=Delivery::quote($id,$revision,$courierInput);$tokens[]=$courier['token'];$check(str_contains($courier['summary'],'120'),'Address uses carrier door service');
    $deny(fn()=>Delivery::validate($id,$data,$snapshot,array_replace($courierInput,['delivery_quote_token'=>$courier['token'],'pnpm_address'=>'Other street'])),'Address changes invalidate receipt');
    $item->update_meta_data('_slw_data',[$terms[0]=>['quantity_subtracted'=>1],$terms[1]=>['quantity_subtracted'=>1]]);$item->save();
    $order=wc_get_order($order->get_id());$plan=(new DocumentShipmentBuilder())->build($order,$snapshot);
    $check(count($plan['shipments'])===2 && array_sum(array_column($plan['shipments'],'quantity'))===2.0,'Saved split, including one item on two warehouses, is preserved');
    $deny(fn()=>Delivery::validate($id,$data,$snapshot,$input),'Saved allocation change invalidates receipt');
    $multi=Delivery::quote($id,$revision,$input);$tokens[]=$multi['token'];$check(count($multi['parcels'])===2 && !$multi['cod_allowed'],'Two parcels and current multi-parcel COD restriction');
    $deny(fn()=>Delivery::validate($id,$data,$snapshot,array_replace($input,['delivery_quote_token'=>$multi['token'],'payment'=>'cod'])),'COD restriction enforced server-side');
    $mappings[$terms[0]]['enabled']='no';$deny(fn()=>Delivery::quote($id,$revision,$input),'Unconfigured sender does not return zero delivery');$mappings[$terms[0]]['enabled']='yes';
    $apiFail=true;$deny(fn()=>Delivery::quote($id,$revision,$input),'API failure cannot create an estimate');$apiFail=false;
    $receiptKey='pcoe_np_quote_'.hash('sha256',$multi['token']);$receipt=get_transient($receiptKey);$receipt['expires']=time()-1;set_transient($receiptKey,$receipt,600);
    $deny(fn()=>Delivery::validate($id,$data,$snapshot,array_replace($input,['delivery_quote_token'=>$multi['token']])),'Expired estimate rejected');
    $check(json_decode(get_post($id)->post_content,true)['status']==='pending' && wc_get_order($order->get_id())->get_total()==200 && wc_get_order($order->get_id())->has_status('pc-draft'),'Quotes do not confirm, change totals or change order status');
    $check(!wc_get_order($order->get_id())->get_meta('_pnpm_recipient'),'Quotes do not overwrite shipping metadata');
    $check(!array_diff($calls,['searchSettlements','getWarehouses','getDocumentPrice']),'Only read-only NP methods used');
    if($file=getenv('PCOE_APPROVAL_NP_FIXTURE')){
        ob_start();(new ReflectionMethod(Approval::class,'details'))->invoke(null,get_post($id),false);$html=ob_get_clean();
        Delivery::assets();
        file_put_contents($file,wp_json_encode(['html'=>$html,'pnpm'=>wp_scripts()->get_data('pnpm-checkout','data'),'approval'=>wp_scripts()->get_data('pcoe-approval-delivery','data'),'method'=>$method,'quote'=>$quote,'city'=>$city,'point'=>$point]));
    }
    // Exercise the real confirmation controller. Intercept its terminal redirect/die
    // so the CLI process can inspect the saved consent and remove its fixtures.
    $fresh=Delivery::quote($id,$revision,$input);$tokens[]=$fresh['token'];
    $post=array_replace($input,['action'=>'pcoe_approval_confirm','approval_id'=>$id,'revision'=>$revision,
        'consent'=>'1','recipient'=>'Test recipient','phone'=>'+380500001122','destination'=>'FORGED destination',
        'delivery_quote_token'=>$fresh['token'],'_wpnonce'=>wp_create_nonce('pcoe_approval_confirm')]);
    $redirect=static function(){throw new RuntimeException('Test redirect intercepted');};
    $die=static fn()=>static function($message){throw new RuntimeException((string)$message);};
    add_filter('wp_redirect',$redirect);add_filter('wp_die_handler',$die);
    $oldPost=$_POST;$oldRequest=$_REQUEST;
    try {
        $_POST=$_REQUEST=array_replace($post,['delivery_quote_token'=>'']);
        try{Approval::confirm();}catch(Throwable $e){}
        clean_post_cache($id);$check(json_decode(get_post($id)->post_content,true)['status']==='pending','Real controller rejects unquoted NP confirmation');
        $_POST=$_REQUEST=$post;
        try{Approval::confirm();}catch(Throwable $e){}
        clean_post_cache($id);$confirmed=json_decode(get_post($id)->post_content,true);
        $check($confirmed['status']==='confirmed' && $confirmed['preferences']['destination']==='Тестове місто, Відділення №7'
            && isset($confirmed['nova_poshta']['policy'],$confirmed['preferences']['delivery_quote']),'Real controller saves canonical destination and reviewed estimate for manager');
        try{Approval::confirm();}catch(Throwable $e){}
        clean_post_cache($id);$check(json_decode(get_post($id)->post_content,true)===$confirmed,'Confirmation replay preserves the original estimate and consent');
    }finally{$_POST=$oldPost;$_REQUEST=$oldRequest;remove_filter('wp_redirect',$redirect);remove_filter('wp_die_handler',$die);}
    echo "PASS: $checks approval Nova Poshta checks; external HTTP/mail intercepted.\n";
} finally {
    foreach($tokens as $token)delete_transient('pcoe_np_quote_'.hash('sha256',$token));foreach(array_unique($cache) as $key)delete_transient($key);
    if($id)wp_delete_post($id,true);foreach($orders as $oid){$o=wc_get_order($oid);if($o)$o->delete(true);}if($product)$product->delete(true);
    foreach($terms as $tid)wp_delete_term($tid,'location');wp_set_current_user($original);require_once ABSPATH.'wp-admin/includes/user.php';foreach($users as $u)wp_delete_user($u);
    $gateway->enabled=$gatewayEnabled;
}
