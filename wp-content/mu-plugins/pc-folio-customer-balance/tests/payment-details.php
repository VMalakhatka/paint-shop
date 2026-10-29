<?php
/** Run with wp eval-file on paint.local only. Synthetic bank data; no real transfers or outgoing mail. */
defined('ABSPATH') || exit;
if (!defined('WP_CLI') || !WP_CLI || wp_parse_url(home_url(), PHP_URL_HOST) !== 'paint.local') throw new RuntimeException('Local fixture only.');
require_once __DIR__ . '/payment-fixture.php';
$checks=0;$orders=[];$http_calls=0;$mail_calls=0;
$assert=static function($ok,string $label)use(&$checks){if(!$ok)throw new RuntimeException($label);$checks++;};
$capture=static function(callable $fn):string{ob_start();try{$fn();return ob_get_contents();}finally{ob_end_clean();}};
$fake_iban=static function(string $body):string{
    $remainder=0;foreach(str_split($body.'301000') as $digit)$remainder=($remainder*10+(int)$digit)%97;
    return 'UA'.str_pad((string)(98-$remainder),2,'0',STR_PAD_LEFT).$body;
};
// All-zero bank code is a deliberate nonproduction fixture, even though its checksum is valid.
$canonical_iban=$fake_iban(str_repeat('0',25));$legacy_iban=$fake_iban(str_repeat('0',24).'9');
$settings=['name'=>'Fixture recipient — not a real account','tax_id'=>'00000000','iban'=>$canonical_iban,'bank'=>'Fixture bank','mfo'=>'000000','confirmed'=>true,'logo_id'=>0];
$valid=$settings;
$legacy_account=['account_name'=>'LEGACY PAYEE','account_number'=>'LEGACY ACCOUNT','bank_name'=>'LEGACY BANK','sort_code'=>'999999','iban'=>$legacy_iban,'bic'=>'LEGACY BIC'];
$legacy_settings=$legacy_account+['enabled'=>'yes','title'=>'Fixture bank transfer','description'=>'LEGACY DESCRIPTION '.$legacy_iban,'instructions'=>'LEGACY INSTRUCTIONS '.$legacy_iban];
$raw_accounts_override=null;
$canonical_filter=static function()use(&$settings,&$raw_accounts_override){return $raw_accounts_override ?? [pc_payment_fixture_account($settings)];};
$legacy_filter=static function()use($legacy_account){return ['name'=>$legacy_account['account_name'],'tax_id'=>'00000000','iban'=>$legacy_account['iban'],'bank'=>$legacy_account['bank_name'],'mfo'=>'999999','confirmed'=>true,'logo_id'=>73];};
$old_accounts_filter=static function()use($legacy_account){return [$legacy_account];};
$old_settings_filter=static function()use($legacy_settings){return $legacy_settings;};
$http_filter=static function()use(&$http_calls){$http_calls++;return new WP_Error('fixture_external_block','No external requests permitted');};
$mail_filter=static function()use(&$mail_calls){$mail_calls++;return false;};
add_filter('pre_option_pc_folio_invoice_settings',$legacy_filter,PHP_INT_MAX);
add_filter('pre_option_woocommerce_bacs_accounts',$canonical_filter,PHP_INT_MAX);
add_filter('woocommerce_bacs_accounts',$old_accounts_filter,PHP_INT_MAX-1);
add_filter('option_woocommerce_bacs_settings',$old_settings_filter,PHP_INT_MAX-1);
add_filter('pre_http_request',$http_filter,PHP_INT_MAX,3);
add_filter('pre_wp_mail',$mail_filter,PHP_INT_MAX,2);
$registry=WC()->payment_gateways();$gateway=$registry->payment_gateways()['bacs']??null;
$assert($gateway instanceof WC_Gateway_BACS,'Native BACS gateway is available for renderer regression');
$old_gateway=[$gateway->instructions,$gateway->account_details];
$old_get=$_GET;$old_user=get_current_user_id();
try {
    $assert(pc_folio_invoice_validate_settings($settings)===true,'Synthetic canonical fixture passes the actual invoice validator');
    $order=new WC_Order();$order->set_payment_method('bacs');$order->set_billing_country('UA');$order->set_created_via('payment-details-fixture');$order->save();$orders[]=$order->get_id();
    $order->set_status('on-hold'); // Email view only; do not save a transition that could trigger notifications.
    $other=new WC_Order();$other->set_payment_method('cod');$other->set_created_via('payment-details-fixture');$other->save();$orders[]=$other->get_id();
    $baseline_mail=$mail_calls;$baseline_http=$http_calls;
    $native=static function()use($gateway,$legacy_account){
        $gateway->instructions='LEGACY INSTRUCTIONS '.$legacy_account['iban'];$gateway->account_details=[$legacy_account];
        add_action('woocommerce_email_before_order_table',[$gateway,'email_instructions'],10,3);
        add_action('woocommerce_thankyou_bacs',[$gateway,'thankyou_page'],10);
    };
    $email=static function($order,bool $admin=false,bool $plain=false)use($capture,$native):string{
        $native();return $capture(static fn()=>do_action('woocommerce_email_before_order_table',$order,$admin,$plain,null));
    };
    $thankyou=static function(int $id)use($capture,$native):string{
        $native();return $capture(static fn()=>do_action('woocommerce_thankyou_bacs',$id));
    };
    $absent=static function(string $output)use($assert,$legacy_iban){
        $assert(!str_contains($output,$legacy_iban) && !str_contains($output,'LEGACY'),'Legacy account and instructions never leak into current output');
    };

    $accounts=get_option('woocommerce_bacs_accounts');
    $assert(count($accounts)===1 && $accounts[0]['iban']===$canonical_iban && $accounts[0]['account_name']===$settings['name'],'Direct accounts option supplies only the canonical recipient');
    $assert($accounts[0]['account_number']==='' && $accounts[0]['bic']==='','Deprecated number and BIC are empty, with no legacy fallback');
    $assert(apply_filters('woocommerce_bacs_accounts',[$legacy_account],$order->get_id())===pc_folio_bacs_accounts(),'Runtime account filter replaces cached gateway details');
    $projection=pc_folio_invoice_settings();
    $assert($projection['iban']===$canonical_iban && $projection['name']===$settings['name'] && $projection['logo_id']===73,'Invoices project finance from Woo and only the logo from legacy settings');
    $blocks=apply_filters('option_woocommerce_bacs_settings',$legacy_settings);
    $assert($blocks['iban']===$canonical_iban && $blocks['account_name']===$settings['name'] && $blocks['enabled']==='yes' && $blocks['title']===$legacy_settings['title'],'Raw settings filter used by Blocks normalizes account fields while preserving payment configuration');
    $absent(wp_json_encode($blocks));
    $read_settings=get_option('woocommerce_bacs_settings',[]);
    if ($read_settings) {$assert($read_settings['iban']===$canonical_iban,'Direct existing BACS settings read uses the adapter');$absent(wp_json_encode($read_settings));}
    $absent(apply_filters('woocommerce_gateway_description','LEGACY '.$legacy_iban,'bacs'));
    $assert(apply_filters('woocommerce_gateway_description','Other gateway description','cod')==='Other gateway description','Other gateway descriptions are unchanged');
    $native();do_action('wc_payment_gateways_initialized',$registry);
    $assert(has_action('woocommerce_email_before_order_table',[$gateway,'email_instructions'])===false && has_action('woocommerce_thankyou_bacs',[$gateway,'thankyou_page'])===false,'Gateway initialization removes the two native BACS renderers');

    $html=$email($order);$absent($html);
    $assert(substr_count($html,'class="woocommerce-bacs-bank-details"')===1 && substr_count($html,$canonical_iban)===1,'Customer HTML email contains one canonical bank block despite a late native registration');
    $assert(str_contains($html,$settings['tax_id']) && str_contains($html,$settings['mfo']),'Email includes canonical tax ID and optional bank code');
    $plain=$email($order,false,true);$absent($plain);
    $assert(substr_count($plain,$canonical_iban)===1 && !str_contains($plain,'<section') && !str_contains($plain,'<li>'),'Plain-text email has readable canonical details without HTML bank markup');
    $html=$thankyou($order->get_id());$absent($html);
    $assert(substr_count($html,'class="woocommerce-bacs-bank-details"')===1 && substr_count($html,$canonical_iban)===1,'Thank-you page contains one canonical block without stale native instructions');
    $assert(!str_contains($email($order,true),$canonical_iban),'Admin email has no customer bank-transfer instruction block');
    $assert(!str_contains($email($other),$canonical_iban) && !str_contains($thankyou($other->get_id()),$canonical_iban),'Non-BACS orders have no bank-transfer block');
    $order->set_status('processing');$assert(!str_contains($email($order),$canonical_iban),'Paid/processing email does not receive default on-hold payment instructions');
    $status_filter=static fn()=>'processing';add_filter('woocommerce_bacs_email_instructions_order_status',$status_filter);
    $assert(str_contains($email($order),$canonical_iban),'Native configurable instruction-status filter is respected');remove_filter('woocommerce_bacs_email_instructions_order_status',$status_filter);
    $order->set_status('on-hold');
    $settings['iban']=strtolower(implode(' ',str_split($canonical_iban,4)));
    $assert(pc_folio_bacs_accounts()[0]['iban']===$canonical_iban,'Canonical IBAN whitespace and case are normalized');$settings=$valid;
    $settings['name']='Fixture & <b>recipient</b>';
    $safe=$capture(static fn()=>pc_folio_bacs_render_details());
    $assert(str_contains($safe,'Fixture &amp; &lt;b&gt;recipient&lt;/b&gt;') && !str_contains($safe,'<b>recipient</b>'),'HTML recipient details are escaped');
    $safe=$capture(static fn()=>pc_folio_bacs_render_details(true));
    $assert(str_contains($safe,'Fixture & recipient') && !str_contains($safe,'<b>'),'Plain-text recipient removes HTML tags');$settings=$valid;
    $settings['mfo']='';$no_mfo=$capture(static fn()=>pc_folio_bacs_render_details(true));
    $assert(!str_contains($no_mfo,__('Bank code (MFO)','pc-folio-customer-balance')),'Optional empty MFO is omitted');$settings=$valid;

    $assert(shortcode_exists('pc_folio_payment_details'),'Contacts shortcode is registered');
    $contact=do_shortcode('[pc_folio_payment_details]');$absent($contact);
    $assert(substr_count($contact,$canonical_iban)===1 && !str_contains($contact,pc_folio_bacs_instruction()),'Contacts shortcode shares canonical data without order-payment instructions');

    foreach ([['confirmed'=>false],['confirmed'=>'false'],['confirmed'=>[]],['iban'=>'UA00'.str_repeat('0',25)],['iban'=>[]],['name'=>(object)['bad'=>'value']],['tax_id'=>'000'],['bank'=>''],['mfo'=>[]]] as $bad) {
        $settings=array_merge($valid,$bad);
        $assert(pc_folio_payment_details()===[] && pc_folio_bacs_accounts()===[] && apply_filters('woocommerce_bacs_accounts',[$legacy_account])===[],'Invalid or unconfirmed canonical details fail closed without a legacy account');
        $blocked=apply_filters('option_woocommerce_bacs_settings',$legacy_settings);
        foreach(['account_name','account_number','bank_name','sort_code','iban','bic'] as $key)$assert($blocked[$key]==='','Invalid canonical settings clear deprecated bank field '.$key);
        foreach([$email($order),$email($order,false,true),$thankyou($order->get_id()),do_shortcode('[pc_folio_payment_details]'),apply_filters('woocommerce_gateway_description','LEGACY '.$legacy_iban,'bacs'),wp_json_encode($blocked)] as $output) {
            $absent($output);$assert(!str_contains($output,$canonical_iban),'Invalid settings never expose even a previously valid canonical IBAN');
        }
        $warning=$capture(static fn()=>pc_folio_bacs_render_details(true));
        $assert(str_contains($warning,pc_folio_bacs_unavailable_message()),'Unavailable payment destination is explained explicitly');
    }
    $settings=$valid;
    $signed=pc_payment_fixture_account($valid);
    foreach (['account_name','account_number','bank_name','sort_code','iban','bic','tax_id'] as $field) {
        $changed=$signed;$changed[$field].='1';$raw_accounts_override=[$changed];
        $assert(pc_folio_payment_details()===[],'Changing signed field invalidates prior confirmation: '.$field);
        $absent($email($order));
    }
    foreach ([[],[$signed,$signed],['bad'],[array_replace($signed,['pc_folio_confirmed_hash'=>''])],[array_replace($signed,['pc_folio_confirmed_hash'=>[]])],[array_replace($signed,['pc_folio_confirmed_hash'=>str_repeat('0',64)])]] as $raw) {
        $raw_accounts_override=$raw;
        $assert(pc_folio_payment_details()===[] && pc_folio_bacs_accounts()===[],'Missing, ambiguous or unconfirmed Woo account fails closed');
        foreach ([$email($order),$thankyou($order->get_id()),do_shortcode('[pc_folio_payment_details]'),wp_json_encode(apply_filters('option_woocommerce_bacs_settings',$legacy_settings))] as $out) {
            $absent($out);$assert(!str_contains($out,$canonical_iban),'Missing or ambiguous Woo account never falls back to legacy bank details');
        }
    }
    $raw_accounts_override=null;
    for($i=0;$i<20;$i++) {
        $blocks=apply_filters('option_woocommerce_bacs_settings',$legacy_settings);
        $assert($blocks['iban']===$canonical_iban && pc_folio_payment_details()['iban']===$canonical_iban,'Repeated Blocks settings and invoice projection reads do not recurse or mutate source');
    }
    $assert(get_option('woocommerce_bacs_accounts')===[pc_payment_fixture_account($valid)],'Read adapters leave raw Woo account and its confirmation metadata intact');
    $assert($http_calls===$baseline_http && $mail_calls===$baseline_mail,'Rendering and option adaptation make no HTTP requests and send no email');
    echo "PASS: $checks canonical payment-detail checks; synthetic fixtures only\n";
} finally {
    foreach($orders as $id){$fixture_order=wc_get_order($id);if($fixture_order)$fixture_order->delete(true);}
    $gateway->instructions=$old_gateway[0];$gateway->account_details=$old_gateway[1];pc_folio_bacs_remove_native_output($registry);
    remove_filter('pre_option_pc_folio_invoice_settings',$legacy_filter,PHP_INT_MAX);
    remove_filter('pre_option_woocommerce_bacs_accounts',$canonical_filter,PHP_INT_MAX);
    remove_filter('woocommerce_bacs_accounts',$old_accounts_filter,PHP_INT_MAX-1);
    remove_filter('option_woocommerce_bacs_settings',$old_settings_filter,PHP_INT_MAX-1);
    remove_filter('pre_http_request',$http_filter,PHP_INT_MAX);
    remove_filter('pre_wp_mail',$mail_filter,PHP_INT_MAX);
    $_GET=$old_get;wp_set_current_user($old_user);
}
