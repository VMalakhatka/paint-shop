<?php
if (!defined('WP_CLI') || !WP_CLI || !defined('PCOE_ISOLATED_WAITLIST_TEST') || !PCOE_ISOLATED_WAITLIST_TEST
    || DB_NAME !== 'pcoe_isolated' || strpos(ABSPATH, '/private/tmp/pcoe-waitlist-') !== 0) throw new RuntimeException('Disposable database only');
add_filter('pre_http_request', static fn() => new WP_Error('offline', 'HTTP blocked'), PHP_INT_MAX);
use PaintCore\PCOE\Waitlist;
use PaintCore\PCOE\WaitlistStore as Store;
use PaintCore\PCOE\WaitlistMail as Mail;
function mail_check($ok, $label) { if (!$ok) throw new RuntimeException($label); }
Store::install();
add_role('partner', 'Partner', ['read'=>true]);
$user=wp_insert_user(['user_login'=>'mail_'.wp_generate_password(8,false),'user_pass'=>wp_generate_password(),'user_email'=>'isolated'.wp_generate_password(6,false).'@example.invalid','role'=>'partner']);
mail_check(!is_wp_error($user),'Synthetic customer');
update_option('pcoe_waitlist_pilot_user_id',$user,false);
update_option('pcoe_waitlist_enabled','yes',false);
update_option('pcoe_waitlist_mail_enabled','yes',false);
update_option('lavka_sync_last_to',gmdate('c'),false);
$product=new WC_Product_Simple();$product->set_name('Synthetic product');$product->set_sku('MAIL-'.$user);$product->set_status('publish');$product->set_regular_price('10');$product->save();
update_post_meta($product->get_id(),'_stock_at_101',2);update_post_meta($product->get_id(),'_stock_at_102',0);
$state=Store::read($user);
$state['entries']=['manual'=>['product_id'=>$product->get_id(),'quantity'=>5,'status'=>'active','created_at'=>time()]];
mail_check(Store::save($user,$state),'Seed own request');
$count=0;$mode='ok';$last=[];
remove_all_filters('pre_wp_mail');
add_filter('pre_wp_mail',static function($return,$args)use(&$count,&$mode,&$last){
 $count++;$last=$args;
 Mail::run(); // A simultaneous invocation must see the durable claim and not send.
 if($mode==='throw') throw new RuntimeException('Transport outcome unknown');
 return $mode==='ok';
},PHP_INT_MAX,2);
wp_set_current_user(0);
Mail::run();mail_check($count===0,'No implicit consent');
$state=Store::read($user);$state['email_enabled']=true;Store::save($user,$state);
Mail::run();mail_check($count===1,'One background partial-stock email without login');
mail_check($last['to']===get_user_by('id',$user)->user_email,'Only pilot recipient');
mail_check(str_contains($last['message'],'5')&&str_contains($last['message'],'2'),'Requested and available quantities');
$state=Store::read($user);mail_check(current($state['mail_log'])['status']==='accepted','Accepted journal');
Mail::run();mail_check($count===1,'No duplicate run');
class MailTestSession extends WC_Session {}
class MailPreferenceRedirect extends Exception {}
WC()->session=new MailTestSession();WC()->cart=new WC_Cart();
wp_set_current_user($user);
remove_all_filters('wp_redirect');
add_filter('wp_redirect',static function(){throw new MailPreferenceRedirect();});
$_SERVER['REQUEST_METHOD']='POST';
$_POST=['operation'=>'email_preferences','revision'=>(string)Store::read($user)['revision']];
$_REQUEST=$_POST+['_wpnonce'=>wp_create_nonce('pcoe_waitlist')];
try{Waitlist::handle();}catch(MailPreferenceRedirect $expected){}
mail_check(Store::read($user)['email_enabled']===false,'Customer opt-out endpoint');
mail_check(count(Store::read($user)['mail_log'])===1,'Preference preserves journal');
Mail::run();mail_check($count===1,'Opt-out stops scan');
$_POST=['operation'=>'email_preferences','email_enabled'=>'1','revision'=>(string)Store::read($user)['revision']];
$_REQUEST=$_POST+['_wpnonce'=>wp_create_nonce('pcoe_waitlist')];
try{Waitlist::handle();}catch(MailPreferenceRedirect $expected){}
mail_check(Store::read($user)['email_enabled']===true,'Customer opt-in endpoint');
wp_set_current_user(0);

$state=Store::read($user);$state['mail_observed']=[];$state['entries']['manual']['snooze_until']=time()+100;Store::save($user,$state);
Mail::run();mail_check($count===1,'Snoozed source excluded');
$state=Store::read($user);unset($state['entries']['manual']['snooze_until']);Store::save($user,$state);
update_option('lavka_sync_last_to',gmdate('c',time()-90000),false);
Mail::run();mail_check($count===1,'Stale stock excluded');
update_option('lavka_sync_last_to',gmdate('c'),false);
$mode='fail';Mail::run();mail_check($count===2,'Failed attempt invoked once');
Mail::run();mail_check($count===2,'Failed attempt not retried');
$state=Store::read($user);$state['mail_log']=[];$state['mail_observed']=[];Store::save($user,$state);
$mode='throw';Mail::run();Mail::run();mail_check($count===3,'Unknown outcome not retried');
$state=Store::read($user);mail_check(current($state['mail_log'])['status']==='claimed','Unknown claim retained');
$state['mail_log']=[];$state['mail_observed']=[];$state['entries']=[];Store::save($user,$state);
$mode='ok';Mail::run();mail_check($count===3,'Removed request excluded');
Mail::send_test();mail_check($count===4,'Explicit transport test');
$rejected=false;try{Mail::send_test();}catch(RuntimeException $e){$rejected=true;}mail_check($rejected&&$count===4,'Test replay rejected');
Mail::schedule();mail_check((bool)wp_next_scheduled(Mail::HOOK),'Schedule created');
update_option('pcoe_waitlist_mail_enabled','no',false);Mail::schedule();mail_check(!wp_next_scheduled(Mail::HOOK),'Kill switch clears schedule');
echo "PASS: background consent, pilot isolation, partial stock, duplicate/concurrent suppression, snooze, stale stock, removal, failed/unknown outcomes, test replay and schedule. No real email sent.\n";
