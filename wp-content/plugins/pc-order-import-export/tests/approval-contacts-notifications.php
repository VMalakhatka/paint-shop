<?php
/** Local fixtures only; HTTP and mail intercepted, no external delivery. */
use PaintCore\PCOE\CustomerApproval as Approval;
use PaintCore\PCOE\ApprovalContacts as Contacts;
use PaintCore\PCOE\ApprovalNotifications as Notices;
use PaintCore\PCOE\CustomerManagers as Team;
if(!defined('WP_CLI') || !WP_CLI || wp_parse_url(home_url(),PHP_URL_HOST)!=='paint.local')throw new RuntimeException('Local only');
$users=[];$orders=[];$id=0;$mails=[];$mail_ok=true;$mail_throw=false;$checks=0;$original=get_current_user_id();
add_filter('pre_http_request',static fn()=>new WP_Error('blocked','External HTTP blocked'),PHP_INT_MAX);
add_filter('pre_wp_mail',static function($pre,$args)use(&$mails,&$mail_ok,&$mail_throw){$mails[]=$args;if($mail_throw)throw new RuntimeException('Simulated uncertain mail');return $mail_ok;},PHP_INT_MAX,2);
$check=static function($value,$message)use(&$checks){if(!$value)throw new RuntimeException($message);$checks++;};
$deny=static function($fn)use($check){try{$fn();}catch(Throwable $e){$check(true,'Rejected');return;}throw new RuntimeException('Expected rejection');};
$save=static fn($data,$id=0)=>(new ReflectionMethod(Approval::class,'save'))->invoke(null,$data,$id);
try {
    foreach(['opt','opt','shop_manager','shop_manager'] as $i=>$role){$tag=wp_generate_uuid4();$u=wp_insert_user(['user_login'=>'approval-notice-'.$tag,'user_email'=>'approval-'.$tag.'@example.invalid','user_pass'=>wp_generate_password(30),'role'=>$role]);if(is_wp_error($u))throw new RuntimeException('Fixture');$users[]=$u;}
    [$owner,$other,$manager,$secondary]=$users;wp_set_current_user($owner);
    $c=new WC_Customer($owner);$c->set_billing_first_name('Saved');$c->set_billing_last_name('Customer');$c->set_billing_phone('+380501111111');$c->set_billing_city('Billing city');$c->set_billing_address_1('Billing street');
    $c->set_shipping_first_name('Saved');$c->set_shipping_last_name('Recipient');$c->set_shipping_phone('+380502222222');$c->set_shipping_city('Saved city');$c->set_shipping_address_1('Saved street 10');$c->save();
    $new_order=static function($customer,$city)use(&$orders){$o=wc_create_order(['customer_id'=>$customer,'status'=>'pc-draft']);$orders[]=$o->get_id();$o->set_shipping_first_name('Order recipient');$o->set_shipping_phone('+380503333333');$o->set_shipping_city($city);$o->set_shipping_address_1('Order street 20');$i=new WC_Order_Item_Product();$i->set_name('Approval synthetic item');$i->set_quantity(2);$i->set_total(20);$i->set_subtotal(20);$o->add_item($i);$o->calculate_totals();$o->save();return $o;};
    $order=$new_order($owner,'Order city');$foreign=$new_order($other,'PRIVATE OTHER CUSTOMER');
    $source=['customer_id'=>$owner,'order_id'=>$order->get_id(),'type'=>'','document_id'=>0];
    $options=Contacts::options($source);$json=wp_json_encode($options);
    $check(count($options)===3 && str_contains($json,'Saved street 10') && str_contains($json,'Order street 20') && str_contains($json,'Billing street'),'Order and saved Woo profile addresses available');
    $check($options[0]['values']['phone']==='+380503333333','Current order is the first editable default');
    $forged=Contacts::options(array_replace($source,['order_id'=>$foreign->get_id()]));$check(!str_contains(wp_json_encode($forged),'PRIVATE OTHER CUSTOMER'),'Foreign order cannot supply an address');
    $order->update_meta_data('_pnpm_recipient',['city_label'=>'Saved NP city','point_label'=>'Saved branch 3','delivery_type'=>'branch']);$order->save();
    $check(Contacts::options($source)[0]['values']['destination']==='Saved NP city, Saved branch 3','Saved Nova Poshta destination is reused without an API call');
    wp_set_current_user($other);$check(Contacts::options($source)===[],'Other customer cannot load saved contacts');wp_set_current_user($owner);
    $choices=['payment'=>['bacs'=>'Bank'],'delivery'=>['local_pickup:1'=>'Pickup']];
    ob_start();Contacts::render($source,$choices);$html=ob_get_clean();
    $check(str_contains($html,'type="tel"') && str_contains($html,'autocomplete="shipping tel"') && str_contains($html,'data-saved-contact') && substr_count($html,'required="required"')===5,'Checkout-style controls include autofill and native required validation');
    $snapshot=Approval::snapshot($source);$data=['source'=>$source,'snapshot'=>$snapshot,'revision'=>Approval::revision($snapshot),'status'=>'pending','history'=>[],'requested_by'=>$manager,'requested_at'=>gmdate('c')];$id=$save($data);
    update_user_meta($owner,Team::META,['primary'=>$manager,'secondary'=>$secondary,'revision'=>wp_generate_uuid4()]);
    wp_set_current_user($manager);$mails=[];$key=wp_generate_uuid4();
    $check(Notices::send_customer($id,$data['revision'],$key)==='accepted','Manager can email the pending request');
    $check(count($mails)===1 && $mails[0]['to']===get_userdata($owner)->user_email && str_contains($mails[0]['message'],'pcoe_approval='.$id),'Customer email targets account owner and exact approval URL');
    Notices::send_customer($id,$data['revision'],$key);$check(count($mails)===1,'Repeated email POST does not send twice');
    $deny(fn()=>Notices::send_customer($id,'stale',wp_generate_uuid4()));
    wp_set_current_user($owner);$deny(fn()=>Notices::send_customer($id,$data['revision'],wp_generate_uuid4()));wp_set_current_user($manager);
    $mail_ok=false;$failed=wp_generate_uuid4();$check(Notices::send_customer($id,$data['revision'],$failed)==='failed','Mail failure is explicit');Notices::send_customer($id,$data['revision'],$failed);$check(count($mails)===2,'Failed request is not retried by replay');
    $mail_ok=true;$check(Notices::send_customer($id,$data['revision'],wp_generate_uuid4())==='accepted','Explicit new send can retry a known failure');
    $mail_throw=true;$check(Notices::send_customer($id,$data['revision'],wp_generate_uuid4())==='unknown','Interrupted mail has an uncertain outcome');$mail_throw=false;
    $deny(fn()=>Notices::send_customer($id,$data['revision'],wp_generate_uuid4()));
    $input=['revision'=>$data['revision'],'consent'=>'1','payment'=>'bacs','delivery'=>'local_pickup:1','recipient'=>'Chosen recipient','phone'=>'+380504444444','destination'=>'Chosen destination'];
    $data=Approval::confirmed($data,$owner,$data['revision'],$input,$choices);$save($data,$id);$mails=[];
    wp_set_current_user($owner);Notices::confirmed($id);Notices::confirmed($id);
    $check(count($mails)===2 && count(array_unique(array_column($mails,'to')))===2,'Assigned pair and requesting manager are deduplicated across repeated confirmation');
    $check(str_contains($mails[0]['message'],'Chosen destination') && str_contains($mails[0]['message'],'approval_id='.$id),'Manager gets chosen conditions and the review link');
    $context=['id'=>$id,'revision'=>$data['revision'],'confirmed_at'=>$data['confirmed_at']];
    $check(Notices::telegram_allowed($secondary,$context),'Assigned second manager may receive confirmation in Telegram');
    update_user_meta($owner,Team::META,['primary'=>$manager,'secondary'=>0,'revision'=>wp_generate_uuid4()]);
    $check(!Notices::telegram_allowed($secondary,$context),'Removing second manager invalidates queued notice');
    $check(!Notices::telegram_allowed($manager,array_replace($context,['revision'=>'outdated'])),'Old confirmation job is rejected');
    (new WP_User($manager))->set_role('customer');$check(!Notices::telegram_allowed($manager,$context),'Role loss prevents queued manager delivery');
    $check(wc_get_order($order->get_id())->has_status('pc-draft') && !(new WC_Customer($owner))->get_meta('_pcoe_payment'),'No checkout, payment or order status transition');
    $check((new WC_Customer($owner))->get_shipping_address_1()==='Saved street 10','Confirmation does not overwrite saved addresses');
    echo "PASS: $checks approval contact/email/manager isolation checks; external delivery blocked.\n";
} finally {
    if($id)wp_delete_post($id,true);foreach($orders as $oid){$o=wc_get_order($oid);if($o)$o->delete(true);}
    wp_set_current_user($original);require_once ABSPATH.'wp-admin/includes/user.php';foreach($users as $u)wp_delete_user($u);
}
