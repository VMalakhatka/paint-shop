<?php
/** Local-only handler regression. All option writes are intercepted in memory; no live financial config is changed. */
defined('ABSPATH') || exit;
if (!defined('WP_CLI') || !WP_CLI || wp_parse_url(home_url(), PHP_URL_HOST) !== 'paint.local') throw new RuntimeException('Local fixture only.');
require_once __DIR__ . '/payment-fixture.php';
if (!class_exists('WC_Admin_Settings')) require_once WC_ABSPATH . 'includes/admin/class-wc-admin-settings.php';
class PCOEBacsFixtureStop extends RuntimeException {}
$checks=0;$users=[];$writes=[];$accept_write=true;$http_calls=0;$mail_calls=0;
$assert=static function($condition,string $label)use(&$checks){if(!$condition)throw new RuntimeException($label);$checks++;};
$original_user=get_current_user_id();$original_post=$_POST;$original_request=$_REQUEST;
$fixture=['name'=>'Synthetic recipient','tax_id'=>'00000000','iban'=>'UA89'.str_repeat('0',25),'bank'=>'Synthetic bank','mfo'=>'000000','confirmed'=>true];
$account=pc_payment_fixture_account($fixture);
$state=[
    'woocommerce_bacs_accounts'=>[$account],
    'woocommerce_bacs_settings'=>['enabled'=>'yes','title'=>'Synthetic transfer','instructions'=>'','description'=>''],
    'pc_folio_invoice_settings'=>['name'=>'Legacy recipient','tax_id'=>'11111111','iban'=>'LEGACY IBAN','bank'=>'Legacy bank','mfo'=>'111111','confirmed'=>true,'logo_id'=>0],
];
$get=static function($pre,$option)use(&$state){return array_key_exists($option,$state)?$state[$option]:$pre;};
$block_updates=static function($value,$option,$old)use(&$state,&$writes,&$accept_write){
    $writes[]=['option'=>$option,'value'=>$value];
    if($accept_write && array_key_exists($option,$state))$state[$option]=$value;
    return $old; // WordPress exits update_option without writing SQL when this equals the old value.
};
$no_http=static function()use(&$http_calls){$http_calls++;return new WP_Error('fixture_external_block','External requests blocked');};
$no_mail=static function()use(&$mail_calls){$mail_calls++;return false;};
$die=static fn()=>static function(){throw new PCOEBacsFixtureStop('Stopped local wp_die');};
$redirect=static function(){throw new PCOEBacsFixtureStop('Stopped local redirect');};
add_filter('pre_http_request',$no_http,PHP_INT_MAX,3);add_filter('pre_wp_mail',$no_mail,PHP_INT_MAX,2);
try {
    foreach(['administrator','shop_manager','customer'] as $role){
        $uid=wp_insert_user(['user_login'=>'bacs-fixture-'.wp_generate_uuid4(),'user_pass'=>wp_generate_password(30),'user_email'=>wp_generate_uuid4().'@example.invalid','role'=>$role]);
        if(is_wp_error($uid))throw new RuntimeException('Cannot create local test identity');$users[]=$uid;
    }
    [$admin,$manager,$customer]=$users;
    add_filter('pre_option',$get,PHP_INT_MAX,3);add_filter('pre_update_option',$block_updates,PHP_INT_MAX,3);
    add_filter('wp_die_handler',$die,PHP_INT_MAX);add_filter('wp_redirect',$redirect,-999);
    $registry=WC()->payment_gateways();$gateway=$registry->payment_gateways()['bacs'];
    $assert(!has_action('woocommerce_update_options_payment_gateways_bacs',[$gateway,'save_account_details']),'Native table save is detached before it can discard tax ID and proof');
    $assert(has_action('woocommerce_update_options_payment_gateways_bacs',[$gateway,'process_admin_options'])!==false,'Native gateway enable/title settings still use Woo save');
    $assert(has_action('woocommerce_update_options_payment_gateways_bacs','pc_folio_bacs_save_account')===5,'Canonical complete-row save is registered once before Woo settings save');
    $post=static function()use($account,&$state):array{return [
        'pc_folio_bank_details_present'=>'1','pc_folio_bank_account'=>array_replace($account,['account_name'=>'Updated synthetic recipient']),
        'pc_folio_bank_revision'=>pc_folio_bacs_accounts_revision($state['woocommerce_bacs_accounts']),
        'pc_folio_bank_confirmed'=>'1','_pc_folio_bank_nonce'=>wp_create_nonce('pc_folio_bacs_recipient'),'_wpnonce'=>wp_create_nonce('woocommerce-settings'),
    ];};
    $invoke=static function(array $input,bool $full_hook=false)use(&$writes){
        $_POST=$input;$_REQUEST=$input;$writes=[];
        try {if($full_hook)do_action('woocommerce_update_options_payment_gateways_bacs');else return pc_folio_bacs_save_account();}
        catch(PCOEBacsFixtureStop $stop){}
    };
    $account_writes=static function()use(&$writes){return array_values(array_filter($writes,static fn($write)=>$write['option']==='woocommerce_bacs_accounts'));};
    foreach([$manager,$customer,0] as $actor){
        wp_set_current_user($actor);$before=$state;$invoke($post());
        $assert($state===$before && $account_writes()===[],'Non-administrator cannot update the payment recipient');
    }
    wp_set_current_user($admin);
    ob_start();$gateway->admin_options();$admin_html=ob_get_clean();
    $assert(str_contains($admin_html,'pc_folio_bank_account[tax_id]') && str_contains($admin_html,'pc_folio_bank_revision') && str_contains($admin_html,'_pc_folio_bank_nonce'),'Actual Woo gateway form renders canonical fields, revision and nonce');
    $assert(!str_contains($admin_html,'name="bacs_account_name[]"') && !str_contains($admin_html,'name="bacs_iban[]"'),'Old native bank account table is absent from the actual Woo form');
    ob_start();pc_folio_invoice_render_settings();$logo_html=ob_get_clean();
    $assert(str_contains($logo_html,'name="logo_id"') && !preg_match('/name="(?:name|tax_id|iban|bank|mfo|confirmed)"/',$logo_html),'Legacy invoice form edits only its logo');
    if($dir=getenv('PCOE_BACS_PREVIEW')) {
        if(!is_dir($dir))wp_mkdir_p($dir);
        $head='<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><style>body{font:14px/1.5 -apple-system,BlinkMacSystemFont,Segoe UI,sans-serif;margin:24px;background:#f0f0f1;color:#1d2327}.wrap{max-width:1100px}h2{font-size:22px}.form-table{width:100%;border-collapse:collapse}.form-table th{width:210px;text-align:left;vertical-align:top;padding:20px 10px 20px 0}.form-table td{padding:15px 10px}input[type=text],textarea,select{font:inherit;border:1px solid #8c8f94;border-radius:4px;padding:6px;box-sizing:border-box;max-width:100%;background:white}.regular-text{width:25em}.button-primary{background:#2271b1;border:1px solid #2271b1;color:white;border-radius:3px;padding:7px 14px}.description{color:#646970}a{color:#2271b1}@media(max-width:600px){body{margin:16px}.form-table th,.form-table td{display:block;width:auto;padding:8px 0}.regular-text{width:100%}}</style>';
        ob_start();wp_nonce_field('woocommerce-settings');submit_button(__('Save changes','woocommerce'),'primary','save');$save_html=ob_get_clean();
        file_put_contents($dir.'/bacs-admin.html',$head.'<form class="wrap" method="post">'.$admin_html.$save_html.'</form>');
        file_put_contents($dir.'/invoice-logo-admin.html',$head.$logo_html);
    }
    foreach(['_pc_folio_bank_nonce','_wpnonce'] as $nonce_key){
        $input=$post();$input[$nonce_key]='invalid';$before=$state;$invoke($input);
        $assert($state===$before && $account_writes()===[],'Invalid nonce leaves all bank fields and confirmation unchanged: '.$nonce_key);
        unset($input[$nonce_key]);$invoke($input);
        $assert($state===$before && $account_writes()===[],'Missing nonce cannot save canonical account: '.$nonce_key);
    }
    $input=$post();$input['pc_folio_bank_revision']='outdated';$before=$state;$invoke($input);
    $assert($state===$before && $account_writes()===[],'Stale form revision cannot replace a newer confirmed account');
    $input=$post();unset($input['pc_folio_bank_details_present']);$before=$state;$invoke($input);
    $assert($state===$before && $account_writes()===[],'Unrelated settings submission cannot erase or replace bank details');
    foreach([
        ['iban'=>'UA00'.str_repeat('0',25)],['tax_id'=>'123'],['account_name'=>''],['bank_name'=>''],
        ['account_name'=>['malformed']],['iban'=>['malformed']],['tax_id'=>['malformed']],['sort_code'=>['malformed']],
    ] as $bad){
        $input=$post();$input['pc_folio_bank_account']=array_replace($input['pc_folio_bank_account'],$bad);$before=$state;$invoke($input);
        $assert($state===$before && $account_writes()===[],'Invalid confirmed recipient produces no partial option writes');
    }
    $second_db=new wpdb(DB_USER,DB_PASSWORD,DB_NAME,DB_HOST);
    try {
        $locked=$second_db->get_var($second_db->prepare('SELECT GET_LOCK(%s,0)',pc_folio_bacs_lock_name()));
        $assert((string)$locked==='1','Independent connection acquires the recipient write lock');
        $before=$state;$result=$invoke($post());
        $assert(is_wp_error($result) && $result->get_error_code()==='bacs_busy' && $state===$before && $account_writes()===[],'Concurrent administrator save is rejected before any financial write');
    } finally {
        $second_db->get_var($second_db->prepare('SELECT RELEASE_LOCK(%s)',pc_folio_bacs_lock_name()));$second_db->close();
    }
    $accept_write=false;$before=$state;$result=$invoke($post());$accept_write=true;
    $assert(is_wp_error($result) && $result->get_error_code()==='bacs_save' && $state===$before,'Failed write/readback leaves prior account and legacy data intact');
    $assert(count($writes)===1 && $writes[0]['option']==='woocommerce_bacs_accounts','Failed canonical save never begins legacy cleanup');
    $input=$post();$invoke($input,true);$saved=$state['woocommerce_bacs_accounts'];$only=$account_writes();
    $assert(count($only)===1 && count($saved)===1,'One save writes the complete canonical account once');
    $assert($saved[0]['tax_id']==='00000000' && $saved[0]['account_name']==='Updated synthetic recipient' && $saved[0]['pc_folio_confirmed_hash']===pc_folio_bacs_confirmation_hash($saved[0]),'Native Woo save retains tax ID and confirmation proof');
    $assert(pc_folio_payment_details()['name']==='Updated synthetic recipient','Saved Woo account immediately supplies invoices and customer output');
    $input=$post();unset($input['pc_folio_bank_confirmed']);$invoke($input);
    $assert($state['woocommerce_bacs_accounts']===$saved && pc_folio_payment_details()!==[],'Unchanged account keeps its verified proof when other Woo settings are saved');
    $input=$post();unset($input['pc_folio_bank_confirmed']);$input['pc_folio_bank_account']['account_name']='Unconfirmed new recipient';$invoke($input);
    $assert(count($account_writes())===1 && $state['woocommerce_bacs_accounts'][0]['pc_folio_confirmed_hash']==='' && pc_folio_payment_details()===[],'Removing confirmation atomically makes even valid bank details unavailable');
    $invoke($post());$bank_before=$state['woocommerce_bacs_accounts'];$legacy_before=$state['pc_folio_invoice_settings'];
    $_POST=['_wpnonce'=>wp_create_nonce('pc_folio_invoice_settings'),'name'=>'Attempted alternate payee','iban'=>'ATTEMPTED ALTERNATE IBAN','tax_id'=>'22222222','bank'=>'Attempted bank','confirmed'=>'1','logo_id'=>'73'];$_REQUEST=$_POST;$writes=[];
    try{do_action('admin_post_pc_folio_invoice_settings');}catch(PCOEBacsFixtureStop $stop){}
    $assert($state['woocommerce_bacs_accounts']===$bank_before && $account_writes()===[],'Legacy invoice submission cannot change canonical finance');
    $expected=$legacy_before;$expected['logo_id']=73;
    $assert($state['pc_folio_invoice_settings']===$expected,'Legacy invoice save changes only the logo and ignores posted finance');
    $assert(pc_folio_invoice_settings()['logo_id']===73 && pc_folio_invoice_settings()['name']===$bank_before[0]['account_name'],'Invoice projection combines Woo finance with the saved logo');
    $assert($http_calls===0 && $mail_calls===0,'Settings handlers never call external APIs or send email');
    echo "PASS: $checks Woo BACS settings checks; all option writes intercepted\n";
} finally {
    remove_filter('pre_option',$get,PHP_INT_MAX);remove_filter('pre_update_option',$block_updates,PHP_INT_MAX);
    remove_filter('wp_die_handler',$die,PHP_INT_MAX);remove_filter('wp_redirect',$redirect,-999);
    $_POST=$original_post;$_REQUEST=$original_request;wp_set_current_user($original_user);
    require_once ABSPATH.'wp-admin/includes/user.php';foreach($users as $uid)wp_delete_user($uid);
    remove_filter('pre_http_request',$no_http,PHP_INT_MAX);remove_filter('pre_wp_mail',$no_mail,PHP_INT_MAX);
}
