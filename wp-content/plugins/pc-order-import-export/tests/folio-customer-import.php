<?php
/** Local integration with mocked read-only Folio and blocked mail. */
use PaintCore\PCOE\FolioCustomerImport as Import;
if (!defined('WP_CLI') || !WP_CLI || wp_parse_url(home_url(),PHP_URL_HOST)!=='paint.local') throw new RuntimeException('Local paint test only');
require_once ABSPATH.'wp-admin/includes/user.php';
$actor=get_current_user_id();$users=[];$jobs=[];$checks=0;$mail=0;$failMail=false;$sources=[];$batchDown=false;
$oldmap=get_option('lps_role_contract_map');
$tag='import-'.strtolower(wp_generate_password(8,false));
$check=static function($ok,$why)use(&$checks){if(!$ok)throw new RuntimeException($why);$checks++;};
$mailFilter=static function()use(&$mail,&$failMail){$mail++;return !$failMail;};add_filter('pre_wp_mail',$mailFilter,PHP_INT_MAX);
$http=static function($pre,$args,$url)use(&$sources,&$batchDown){
    $q=[];parse_str(wp_parse_url($url,PHP_URL_QUERY)??'',$q);
    if(str_contains($url,'/partners/registration-emails')) {
        if($batchDown)return new WP_Error('test_batch_down','Batch unavailable');
        $emails=[];foreach(json_decode($args['body'],true) as $key)if(isset($sources[$key]))$emails[$key]=$sources[$key]['email'];
        return ['headers'=>[],'body'=>wp_json_encode($emails),'response'=>['code'=>200,'message'=>'OK'],'cookies'=>[]];
    }
    if(str_contains($url,'/partners/registration') && isset($sources[$q['id']??'']))return ['headers'=>[],'body'=>wp_json_encode($sources[$q['id']]),'response'=>['code'=>200,'message'=>'OK'],'cookies'=>[]];
    if(str_ends_with(wp_parse_url($url,PHP_URL_PATH)??'', '/partners'))return ['headers'=>[],'body'=>wp_json_encode(['ok'=>true,'items'=>array_values($sources),'total'=>count($sources)]),'response'=>['code'=>200,'message'=>'OK'],'cookies'=>[]];
    return new WP_Error('test_http_block','External HTTP blocked');
};add_filter('pre_http_request',$http,PHP_INT_MAX,3);
$create=static function($role,$suffix)use(&$users,$tag){$id=wp_insert_user(['user_login'=>$tag.$suffix,'user_email'=>$tag.$suffix.'@example.invalid','user_pass'=>wp_generate_password(30),'role'=>$role]);if(is_wp_error($id))throw new RuntimeException('Fixture failed');$users[]=$id;return $id;};
$job=static function($ids)use(&$jobs){$id=Import::create($ids);$jobs[]=$id;return $id;};
$prepare=static function($id,$override=[])use($tag){$data=Import::get($id);$fields=[];foreach($data['rows'] as $i=>$row)$fields[$i]=array_merge($row['fields'],['email'=>$tag.'-'.$row['source']['id'].'@example.invalid','role'=>'partner','first_name'=>'Test','last_name'=>'Customer','city'=>'Kyiv','country'=>'UA'],$override);Import::preview($id,$fields);};
try {
    $manager=$create('shop_manager','manager');$other=$create('shop_manager','other');$customer=$create('customer','customer');
    wp_set_current_user($manager);update_option('lps_role_contract_map',['partner'=>'TEST-CONTRACT','customer'=>'RETAIL']);
    for($i=1;$i<=10;$i++)$sources['TEST'.$i]=['id'=>'TEST'.$i,'name'=>'Test company '.$i,'type'=>'H','email'=>'','phone'=>'+380000000000','alternatePhone'=>null,'address'=>'Billing address','postcode'=>'00123','deliveryAddress'=>'Delivery address','discountPercent'=>5,'bankCity'=>'Test city','contactType'=>'TEST WHOLESALE','note'=>'Reference note','additionalInfo'=>"Contact one\nContact two"];
    $id=$job(['TEST1']);$check(Import::get($id)['phase']==='edit','Staging creates no customers');
    $defaults=Import::get($id)['rows'][0]['fields'];
    $check($defaults['country']==='UA' && $defaults['shipping_country']==='UA','Ukraine defaults');
    $check($defaults['city']==='Test city' && $defaults['shipping_city']==='Test city','Bank city defaults');
    $check($defaults['shipping_address']===$defaults['address'] && $defaults['shipping_postcode']===$defaults['postcode'],'Shipping initially copies billing');
    $check($defaults['internal_note']==="Reference note\n\nContact one\nContact two",'Folio notes retain line breaks');
    wp_set_current_user($other);try{Import::get($id);throw new LogicException('Other manager allowed');}catch(RuntimeException $e){$check(true,'Owner isolation');}
    wp_set_current_user($customer);$check(!Import::allowed(),'Customer cannot import');wp_set_current_user($manager);
    $prepare($id);$check(Import::get($id)['rows'][0]['status']==='ready','Explicit safe price role accepted');
    $before=$mail;Import::step($id,true,false);$data=Import::get($id);$uid=$data['rows'][0]['user_id'];$users[]=$uid;
    $check($uid>0 && $data['phase']==='complete','New customer created');
    $expectedLogin=strtolower($tag.'-TEST1');
    $check(get_userdata($uid)->user_login===$expectedLogin,'Login comes from email local part');
    $check(Import::username($expectedLogin.'@other.invalid')===$expectedLogin.'2','Occupied local part gets numeric suffix');
    $check(strlen(Import::username(str_repeat('a',64).'@example.invalid'))<=60,'Long email local part fits WordPress login');
    $blockLogin=static fn($names)=>array_merge($names,[$tag.'reserved']);add_filter('illegal_user_logins',$blockLogin);
    try{$check(Import::username($tag.'reserved@example.invalid')===$tag.'reserved2','Blocked login gets safe suffix');}finally{remove_filter('illegal_user_logins',$blockLogin);}

    $check(get_userdata($uid)->roles===['partner'],'Selected price role applied');
    $check(get_user_meta($uid,'_folio_partner_short_name',true)==='TEST1' && get_user_meta($uid,'_folio_partner_id',true)==='TEST1','Default Internet mapping replaced consistently');
    $check(get_user_meta($uid,'billing_postcode',true)==='00123' && get_user_meta($uid,'shipping_address_1',true)==='Billing address','Contact values and distinct addresses preserved');
    $check(get_user_meta($uid,\PaintCore\PCOE\CustomerInternalNotes::META,true)===$defaults['internal_note'],'Private note saved during import');
    $_POST=['pcoe_customer_note'=>wp_slash("Manager's note\nSecond line"),'pcoe_customer_note_nonce'=>wp_create_nonce('pcoe_customer_note_'.$uid)];
    \PaintCore\PCOE\CustomerInternalNotes::save($uid);
    $check(get_user_meta($uid,\PaintCore\PCOE\CustomerInternalNotes::META,true)==="Manager's note\nSecond line",'Manager can edit multiline note');
    wp_set_current_user($uid);$_POST['pcoe_customer_note']='Forbidden';\PaintCore\PCOE\CustomerInternalNotes::save($uid);
    ob_start();\PaintCore\PCOE\CustomerInternalNotes::summary($uid);$hidden=ob_get_clean();
    $check($hidden==='' && get_user_meta($uid,\PaintCore\PCOE\CustomerInternalNotes::META,true)==="Manager's note\nSecond line",'Customer cannot read rendered note or change it');
    $check(get_user_meta($uid,'description',true)==='','Public biography stays empty');
    wp_set_current_user($manager);$_POST=[];
    $check($mail===$before,'Invitations off by default');Import::step($id,true,true);$check($mail===$before,'Repeated completed request never sends invitations');
    $dup=$job(['TEST1']);$prepare($dup);$check(Import::get($dup)['rows'][0]['status']==='skipped','Existing Folio/email skipped');
    $bad=$job(['TEST2']);$prepare($bad,['role'=>'administrator']);$check(Import::get($bad)['rows'][0]['status']==='skipped','Staff role rejected');
    $prepare($bad,['email'=>'invalid']);$check(Import::get($bad)['rows'][0]['status']==='skipped','Invalid email rejected');
    $batch=$job(['TEST2','TEST3']);$prepare($batch,['email'=>$tag.'same@example.invalid']);$check(count(array_filter(Import::get($batch)['rows'],fn($r)=>$r['status']==='skipped'))===2,'Both duplicate email rows skipped');
    $changed=$job(['TEST4']);$prepare($changed);$sources['TEST4']['email']='changed@example.invalid';Import::step($changed,true,false);$check(Import::get($changed)['rows'][0]['status']==='skipped','Changed source blocks creation');
    $inv=$job(['TEST5']);$prepare($inv);$before=$mail;Import::step($inv,true,true);$row=Import::get($inv)['rows'][0];$users[]=$row['user_id'];
    $check($row['invite']==='accepted' && $mail===$before+1,'Explicit invitation sent once after creation');Import::step($inv,true,true);$check($mail===$before+1,'Invitation not repeated');
    $stale=$job(['TEST6']);$prepare($stale);$data=Import::get($stale);$data['phase']='running';$data['rows'][0]['status']='creating';Import::save($stale,$data);Import::step($stale,false,false);$check(Import::get($stale)['rows'][0]['status']==='review','Unknown creation is not retried');
    $expired=$job(['TEST7']);$prepare($expired);$data=Import::get($expired);$data['expires']=time()-1;Import::save($expired,$data);
    try{Import::step($expired,true,false);throw new LogicException('Expired preview applied');}catch(RuntimeException $e){$check(Import::get($expired)['phase']==='preview','Expired preview rejected');}
    $locked=$job(['TEST8']);$prepare($locked);$lock=lavka_ecosystem_lock_acquire('test','import-test','manual','Test lock',120);
    try{Import::step($locked,true,false);throw new LogicException('Busy lock ignored');}catch(RuntimeException $e){$check(Import::get($locked)['rows'][0]['status']==='ready','Shared lock respected');}finally{lavka_ecosystem_lock_release($lock['token']);}
    $prepare($locked);update_option('lps_role_contract_map',['partner'=>'CHANGED']);Import::step($locked,true,false);$check(Import::get($locked)['rows'][0]['status']==='skipped','Price mapping change blocks stale preview');
    update_option('lps_role_contract_map',['partner'=>'TEST-CONTRACT','customer'=>'RETAIL']);
    $failedMailJob=$job(['TEST9']);$prepare($failedMailJob);$failMail=true;$before=$mail;Import::step($failedMailJob,true,true);$failMail=false;
    $failedRow=Import::get($failedMailJob)['rows'][0];$users[]=$failedRow['user_id'];
    $check($failedRow['status']==='created' && $failedRow['invite']==='failed','Mail failure preserves created account and reports failure');
    Import::step($failedMailJob,true,true);$check($mail===$before+1,'Failed invitation is not automatically resent');
    $emailDup=$job(['TEST10']);$prepare($emailDup,['email'=>get_userdata($uid)->user_email]);$check(Import::get($emailDup)['rows'][0]['status']==='skipped','Existing email cannot be rebound to another Folio customer');
    $data=Import::get($inv);$data['phase']='running';$data['rows'][0]['invite']='sending';Import::save($inv,$data);$before=$mail;Import::step($inv,false,true);
    $check(Import::get($inv)['rows'][0]['invite']==='review' && $mail===$before,'Unknown email outcome is not resent');
    $sources['TEST1']['email']=strtoupper(get_userdata($uid)->user_email);
    $sources['TEST2']['email']=get_userdata($uid)->user_email;
    update_user_meta($customer,'_folio_partner_id','TEST3');
    update_user_meta($other,'_folio_partner_short_name','TEST2');
    $directory=\PaintCore\PCOE\FolioCustomerDirectory::inspect(array_values($sources));
    $check(isset($directory['TEST1']['email_users'][$uid],$directory['TEST1']['folio_users'][$uid]),'Directory finds email case-insensitively and deduplicates Folio meta matches');
    $check(count($directory['TEST1']['folio_users'])===1,'Both Folio meta keys show one account');
    $check(isset($directory['TEST2']['email_users'][$uid],$directory['TEST2']['folio_users'][$other]),'Directory keeps conflicting email and Folio accounts separate');
    $check(isset($directory['TEST3']['folio_users'][$customer]) && $directory['TEST3']['known'] && $directory['TEST3']['email']==='','Missing Folio email differs from unavailable check');
    $batchDown=true;$unavailable=\PaintCore\PCOE\FolioCustomerDirectory::inspect(array_values($sources));$batchDown=false;
    $check(!$unavailable['TEST1']['known'] && isset($unavailable['TEST1']['folio_users'][$uid]),'Email outage still reports existing Folio link');
    wp_set_current_user($customer);try{\PaintCore\PCOE\FolioCustomerDirectory::inspect(array_values($sources));throw new LogicException('Customer directory allowed');}catch(RuntimeException $e){$check(true,'Customer cannot inspect registration directory');}wp_set_current_user($manager);
    if($qa=getenv('PCOE_IMPORT_QA_DIR')){
        update_option('lps_role_contract_map',['partner'=>'TEST-CONTRACT','customer'=>'RETAIL']);
        $qaJob=$job(['TEST2','TEST3']);
        if(!is_dir($qa))mkdir($qa,0700,true);
        $render=static function($name,$jobId)use($qa){
            $_GET=['page'=>'pcoe-customers','view'=>'customer-import','import_id'=>$jobId];
            ob_start(); \PaintCore\PCOE\ManagerWorkspace::render();$body=ob_get_clean();
            file_put_contents($qa.'/'.$name.'.html','<!doctype html><html lang="uk"><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Customer import QA</title><link rel="stylesheet" href="/admin.css"><link rel="stylesheet" href="/manager.css"><body class="wp-admin wp-core-ui"><main>'.$body.'</main><script src="/config.js"></script><script src="/customer-import.js"></script></body></html>');
        };
        $render('search',0);$render('edit',$qaJob);$prepare($qaJob);$render('preview',$qaJob);$render('complete',$id);
    }
    echo "PASS: $checks customer import checks\n";
} finally {
    wp_set_current_user($actor);
    foreach($jobs as $id){$d=get_post_meta($id,'_pcoe_client_import',true);foreach(($d['rows']??[]) as $r)if(!empty($r['user_id']))$users[]=$r['user_id'];wp_clear_scheduled_hook('pcoe_client_import_cleanup',[$id]);wp_delete_post($id,true);}
    foreach(array_unique($users) as $id)if($id)wp_delete_user($id);
    if($oldmap===false)delete_option('lps_role_contract_map');else update_option('lps_role_contract_map',$oldmap);
    remove_filter('pre_http_request',$http,PHP_INT_MAX);remove_filter('pre_wp_mail',$mailFilter,PHP_INT_MAX);
}
