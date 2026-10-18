<?php
/** Local synthetic orders only; all HTTP/mail blocked, test records removed in finally. */
if (!defined('WP_CLI') || !WP_CLI || wp_get_environment_type() !== 'local') throw new RuntimeException('Local CLI only');
$http = $mail = 0;
add_filter('pre_http_request', static function () use (&$http) { $http++; return new WP_Error('blocked', 'Test HTTP blocked'); }, PHP_INT_MAX);
add_filter('pre_wp_mail', static function () use (&$mail) { $mail++; return true; }, PHP_INT_MAX);
use Paint\NovaPoshta\Infrastructure\ManagerPreparation;
use Paint\NovaPoshta\Infrastructure\ShipmentRepository;
use PaintCore\PCOE\CustomerApproval;
$checks = 0;
$check = static function ($value, $message) use (&$checks) { if (!$value) throw new RuntimeException($message); $checks++; };
$reject = static function (callable $work, $message) use ($check) { try { $work(); } catch (RuntimeException $e) { $check(true, $message); return; } throw new RuntimeException('Expected rejection: ' . $message); };
$terms = get_terms(['taxonomy'=>'location','hide_empty'=>false]);
if (is_wp_error($terms) || count($terms) < 2) throw new RuntimeException('Two local locations required');
$locations = [(int)$terms[0]->term_id, (int)$terms[1]->term_id];
$ref = '00000000-0000-0000-0000-000000000001';
$mapping = ['enabled'=>'yes','sender_type'=>'warehouse','city_ref'=>$ref,'counterparty_ref'=>$ref,'contact_ref'=>$ref,
    'sender_address_ref'=>$ref,'handover_warehouse_ref'=>$ref,'phone'=>'380000000000','customer_label'=>'Synthetic sender','handover_warehouse_label'=>'Synthetic branch'];
add_filter('pre_option_pnpm_location_mappings', static fn() => array_fill_keys($locations, $mapping));
add_filter('pre_option_pnpm_delivery_policy_v1', static fn() => Paint\NovaPoshta\Domain\DeliveryPolicy::defaults());
$old_user = $GLOBALS['current_user'];
$manager = new WP_User(); $manager->ID = PHP_INT_MAX; $manager->roles = ['administrator'];
$manager->allcaps = ['manage_woocommerce'=>true,'manage_pnpm_shipments'=>true]; $GLOBALS['current_user'] = $manager;
$order = $child = null; $customer_id = $approval_id = 0;
$service = new ManagerPreparation();
try {
    $customer_id = wp_insert_user(['user_login'=>'pnpm-test-' . wp_generate_uuid4(),'user_email'=>wp_generate_uuid4().'@example.invalid','user_pass'=>wp_generate_password(40),'role'=>'opt']);
    if (is_wp_error($customer_id)) throw new RuntimeException('Synthetic customer unavailable');
    $profile_before = get_user_meta($customer_id);
    $order = new WC_Order(); $order->set_customer_id($customer_id); $order->set_currency('UAH'); $order->set_payment_method('bacs');
    $order->set_shipping_first_name('Synthetic'); $order->set_shipping_last_name('Recipient'); $order->set_shipping_phone('380000000000');
    $order->update_meta_data('_pnpm_recipient',['city_ref'=>$ref,'city_label'=>'Synthetic city','point_ref'=>$ref,'point_label'=>'Synthetic point','delivery_type'=>'branch']);
    $item = new WC_Order_Item_Product(); $item->set_name('Synthetic item'); $item->set_quantity(3); $item->set_subtotal(300); $item->set_total(300);
    $item->add_meta_data('_slw_data',[$locations[0]=>['quantity_subtracted'=>1],$locations[1]=>['quantity_subtracted'=>2]]);
    $order->add_item($item); $order->set_total(300); $order->save();
    $mail_before_preparation = $mail;
    $context = $service->context($order);
    $check(count($context['groups']) === 2, 'Physical warehouses separated');
    $check($context['groups'][$locations[1]]['items'][0]['quantity'] === 2.0, 'Split line quantity retained');
    $input = ['revision'=>$context['revision'],'agreed'=>'1','recipient'=>$context['recipient'],'groups'=>[]];
    foreach ($locations as $location) $input['groups'][$location] = ['payer'=>'Recipient','boxes'=>[['weight'=>'2.5','length'=>'20','width'=>'15','height'=>'10']]];
    $input['recipient']['name'] = 'Agreed recipient';
    $input['recipient']['phone'] = '0000000000';
    $prepared = $service->prepare($context, $input);
    $check($prepared['recipient']['phone'] === '380000000000', 'Phone normalization');
    $check(array_sum(array_column($prepared['groups'],'cod')) === 0.0, 'Prepayment has no COD');
    $check(count($service->blockers($context,$prepared)) >= 2, 'Preparation preview is not permission to create');
    $check((new ShipmentRepository())->findByOrder($order->get_id()) === [], 'Preview does not persist');
    $context = $service->save($order->get_id(),$input);
    $rows = (new ShipmentRepository())->findByOrder($order->get_id());
    $check(count($rows) === 2, 'One saved preparation per warehouse');
    $check($rows[0]['status'] === 'manager_draft' && !$rows[0]['ttn_number'], 'Preparation not a created TTN');
    $check($context['recipient']['name'] === 'Agreed recipient', 'Order-only override reloaded');
    $check(wc_get_order($order->get_id())->get_shipping_first_name() === 'Synthetic', 'Woo address not silently overwritten');
    $check(get_user_meta($customer_id) === $profile_before, 'Customer profile unchanged');
    $check($mail === $mail_before_preparation, 'Saving preparation does not invoke mail');
    $reject(fn()=>$service->save($order->get_id(),$input),'Double click stale revision');
    $input['revision']=$context['revision'];
    $input['groups'][$locations[0]]['boxes'][]=['weight'=>1,'length'=>10,'width'=>10,'height'=>10];
    $context=$service->save($order->get_id(),$input);
    $check(count($context['groups'][$locations[0]]['boxes']) === 2, 'Multiple boxes saved');
    $check(count((new ShipmentRepository())->findByOrder($order->get_id())) === 2, 'Save does not duplicate shipments');
    global $wpdb;
    $count=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}pnpm_shipment_events e JOIN {$wpdb->prefix}pnpm_shipments s ON s.id=e.shipment_id WHERE s.order_id=%d",$order->get_id()));
    $check($count===4,'Audited every warehouse save');
    $input['revision']=$context['revision'];
    foreach (['weight'=>0,'length'=>-1,'width'=>'bad','height'=>[]] as $key=>$value) {
        $bad=$input; $bad['groups'][$locations[0]]['boxes'][0][$key]=$value;
        $reject(fn()=>$service->prepare($context,$bad),'Bad box '.$key);
    }
    $bad=$input; $bad['recipient']['point_ref']='bad'; $reject(fn()=>$service->prepare($context,$bad),'Invalid point');
    $bad=$input; $bad['agreed']=''; $reject(fn()=>$service->prepare($context,$bad),'Agreement required');
    $bad=$input; unset($bad['groups'][$locations[1]]); $reject(fn()=>$service->prepare($context,$bad),'Missing warehouse');
    $bad=$input; $bad['groups'][$locations[0]]['boxes']=array_fill(0,21,['weight'=>1,'length'=>1,'width'=>1,'height'=>1]); $reject(fn()=>$service->prepare($context,$bad),'Bounded boxes');
    $cod=$context; $cod['payment']='cod'; $cod['paid']=false; $p=$service->prepare($cod,$input);
    $check(array_sum(array_column($p['groups'],'cod'))===0.0,'Multiwarehouse COD never duplicated');
    $check(count($service->blockers($cod,$p))===3,'Multiwarehouse COD blocked explicitly');
    unset($cod['groups'][$locations[1]]); $single=$input; unset($single['groups'][$locations[1]]);
    $check($service->prepare($cod,$single)['groups'][$locations[0]]['cod']===300.0,'Single COD uses server total');
    $cod['paid']=true; $check($service->prepare($cod,$single)['groups'][$locations[0]]['cod']===0.0,'Paid COD order does not collect twice');
    // A journal write failure rolls back all updates, including earlier warehouses/items.
    $before=(new ShipmentRepository())->findByOrder($order->get_id());
    $break=static fn($q)=>str_starts_with($q,"INSERT INTO `{$wpdb->prefix}pnpm_shipment_events`") ? 'INVALID SYNTHETIC TEST QUERY' : $q;
    $old_suppress=$wpdb->suppress_errors(true); add_filter('query',$break);
    try { $reject(fn()=>$service->save($order->get_id(),$input),'Atomic audit failure'); }
    finally { remove_filter('query',$break); $wpdb->suppress_errors($old_suppress); }
    $check((new ShipmentRepository())->findByOrder($order->get_id())===$before,'Rollback restored all shipment rows');
    $rowid=(int)$before[0]['id'];
    foreach (['customer_external','store_api'] as $source) {
        $wpdb->update($wpdb->prefix.'pnpm_shipments',['source'=>$source,'status'=>$source==='store_api'?'creating':'submitted'],['id'=>$rowid]);
        $reject(fn()=>$service->context(wc_get_order($order->get_id())),'External/creating protected');
    }
    $wpdb->update($wpdb->prefix.'pnpm_shipments',['source'=>'store_api','status'=>'manager_draft'],['id'=>$rowid]);
    $child=new WC_Order(); $child->set_customer_id($customer_id); $child->update_meta_data('_folio_parent_order_id',$order->get_id()); $child->save();
    $reject(fn()=>$service->context($child),'Child cannot duplicate plan');
    // Current approval import, exact customer/order/revision. No writes on import.
    $source=['customer_id'=>$customer_id,'order_id'=>$order->get_id()];
    $snapshot=CustomerApproval::snapshot($source);
    $data=['source'=>$source,'snapshot'=>$snapshot,'revision'=>CustomerApproval::revision($snapshot),'status'=>'confirmed',
        'preferences'=>['delivery'=>['id'=>'pnpm_nova_poshta:1'],'recipient'=>'Confirmed recipient','phone'=>'380000000000'],
        'nova_poshta'=>['recipient'=>$context['recipient']]];
    $approval_id=wp_insert_post(['post_type'=>CustomerApproval::TYPE,'post_status'=>'private','post_author'=>$customer_id,
        'post_name'=>CustomerApproval::key($source),'post_title'=>'Synthetic confirmation','post_content'=>wp_slash(wp_json_encode($data))]);
    $check(CustomerApproval::shipment_preferences(null,$order)['name']==='Confirmed recipient','Fresh approval imported');
    $order->set_customer_note('Changed'); $order->save();
    $reject(fn()=>CustomerApproval::shipment_preferences(null,$order),'Stale confirmation rejected');
    $manager->allcaps=[];
    $reject(fn()=>$service->save($order->get_id(),$input),'Unauthorized save');
    $manager->allcaps=['manage_woocommerce'=>true];
    $reject(fn()=>$service->order($order->get_id()),'Shipment capability required');
    $manager->allcaps=['manage_woocommerce'=>true,'manage_pnpm_shipments'=>true];
    ob_start(); (new Paint\NovaPoshta\Admin\ManagerPreparationPanel())->render($order); $html=ob_get_clean();
    $check(str_contains($html,'pnpm-manager-form') && str_contains($html,'data-pnpm-command="preview"'),'Manager panel renders');
    $check(!str_contains($html,'data-shipment-command="create"'),'Preparation form has no carrier create action');
    if ($dir=getenv('PNPM_MANAGER_FIXTURE_DIR')) {
        switch_to_locale('uk');
        ob_start(); (new Paint\NovaPoshta\Admin\ManagerPreparationPanel())->render($order); $html=ob_get_clean();
        $card=['manager'=>true,'point'=>Paint\NovaPoshta\Checkout\PointCard::labels(),'ajaxUrl'=>'https://pnpm-test.invalid/ajax','nonce'=>'test','searching'=>'Loading','requestFailed'=>'Unavailable'];
        $manager_config=['url'=>'https://pnpm-test.invalid/ajax','nonce'=>'test','loading'=>'Checking','failure'=>'Failed','dirty'=>'Unsaved'];
        foreach (['kg'=>'kg','cm'=>'cm','currency'=>'UAH','cod'=>'Cash on delivery','payer'=>'Delivery payer','Sender'=>'Sender','Recipient'=>'Recipient'] as $key=>$text) $manager_config[$key]=__($text,'paint-nova-poshta-multishipping');
        $page='<!doctype html><html lang="uk"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><style>body{font:16px/1.5 system-ui;margin:16px}input,select,button{font:inherit;padding:8px}*{box-sizing:border-box}button{cursor:pointer}input[type=checkbox]{width:auto}h2{font-size:24px}</style><style>'.file_get_contents(PNPM_DIR.'assets/checkout.css').file_get_contents(PNPM_DIR.'assets/manager-preparation.css').'</style>'.$html
            .'<script>'.file_get_contents(ABSPATH.'wp-includes/js/jquery/jquery.min.js').'</script><script>var pnpmCheckout='.wp_json_encode($card).';var pnpmManager='.wp_json_encode($manager_config).';</script><script>'.file_get_contents(PNPM_DIR.'assets/checkout.js').'</script><script>'.file_get_contents(PNPM_DIR.'assets/manager-preparation.js').'</script></html>';
        file_put_contents($dir.'/manager-preparation.html',$page); restore_previous_locale();
    }
    $check($http===0,'No carrier/Java HTTP during preparation');
    // Woo creation hooks may call intercepted mail; preparation must never add its own messages.
    echo "Manager preparation: {$checks} checks passed; HTTP blocked, mail intercepted, synthetic records cleaned.\n";
} finally {
    global $wpdb;
    if ($order) foreach ((new ShipmentRepository())->findByOrder($order->get_id()) as $row) {
        foreach (['pnpm_shipment_events','pnpm_shipment_items'] as $table) $wpdb->delete($wpdb->prefix.$table,['shipment_id'=>$row['id']]);
        $wpdb->delete($wpdb->prefix.'pnpm_shipments',['id'=>$row['id']]);
    }
    if ($approval_id) wp_delete_post($approval_id,true);
    if ($child) $child->delete(true);
    if ($order) $order->delete(true);
    if ($customer_id && !is_wp_error($customer_id)) { require_once ABSPATH.'wp-admin/includes/user.php'; wp_delete_user($customer_id); }
    $GLOBALS['current_user']=$old_user;
}
