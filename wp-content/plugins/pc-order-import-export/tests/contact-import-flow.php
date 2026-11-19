<?php
/** Run on a disposable WordPress site with the customer roles and import lock available. */
use PaintCore\PCOE\ContactImport;
use PaintCore\PCOE\ContactWorkbook;
use PaintCore\PCOE\ContactImportUi;
if (!defined('WP_CLI') || !WP_CLI || home_url() !== 'http://qa.invalid') throw new RuntimeException('Disposable QA site only');
$check = static function ($condition, $message) { if (!$condition) throw new RuntimeException($message); };
$tag = wp_generate_uuid4();
$rows = [];
foreach (['one','two','three','four','five','six'] as $i=>$name) {
    $email = [ $tag.'a@example.invalid', $tag.'b@example.invalid', '', $tag.'shared@example.invalid', $tag.'shared@example.invalid', 'tester@example.invalid'][$i];
    $rows[] = ['ID'=>$tag.$i, ContactWorkbook::ORGANIZATION=>$name,'Email'=>$email,'Телефон'=>'00123','Telegram'=>'qa telegram'];
}
$job = ContactImport::stage($rows,'schule');
$staged = ContactImport::page($job,0);
$check(array_column($staged,'role') === array_fill(0,6,'schule'),'Default role on every row');
$_GET = ['job'=>$job,'part'=>0];
ob_start(); ContactImportUi::render(); $html = ob_get_clean();
$check(preg_match_all('/value="schule"\s+selected/', $html) === 6,'Per-row review controls have the default');
$fields = [];
foreach ($staged as $i=>$row) $fields[$i]=['selected'=>'1','role'=>$i===1?'partner':'schule','email'=>$row['email']];
$bad = $fields; $bad[1]['role']='administrator';
try { ContactImport::apply($job,0,$bad); throw new LogicException('Privileged role accepted'); } catch (RuntimeException $e) {}
$check(!email_exists($rows[0]['Email']),'Reject invalid page before any creation');
$partial = $fields;
foreach ($partial as &$input) unset($input['selected']);
unset($input);
try { ContactImport::apply($job,0,$partial,true); throw new LogicException('Empty selection accepted'); } catch (RuntimeException $e) {}
$partial[1]['selected']='1';
ContactImport::apply($job,0,$partial);
$check(array_column(ContactImport::page($job,0),'result') === ['', 'created','','','',''], 'Only checked row saved');
$check(!email_exists($rows[0]['Email']), 'Unchecked account not created');
ContactImport::apply($job,0,$fields);
$check(array_column(ContactImport::page($job,0),'result') === ['created','created','contact','contact','contact','contact'],'Registration and contact-only outcomes');
foreach ([0=>'schule',1=>'partner'] as $i=>$role) {
    $user = get_user_by('email',$rows[$i]['Email']);
    $check($user && $user->roles === [$role],'Effective selected role');
    $card = get_user_meta($user->ID,'_pcoe_contact_id',true);
    $check(get_post_meta($card,'_contact',true)['source'] === $rows[$i],'Every source field retained');
}
$check(!email_exists($rows[3]['Email']),'Shared address did not register');
$check(get_user_by('email','tester@example.invalid')->has_cap('manage_options'),'Existing account preserved');
ContactImport::apply($job,0,$fields);
$repeat = ContactImport::stage($rows,'opt');
$repeatRows=ContactImport::page($repeat,0);
foreach ($repeatRows as $i=>$row) $fields[$i]=['selected'=>'1','role'=>'opt','email'=>$row['email']];
ContactImport::apply($repeat,0,$fields);
$check(array_column(ContactImport::page($repeat,0),'result')===array_fill(0,6,'existing'),'Repeat import deduplicated');
$check(get_user_by('email',$rows[0]['Email'])->roles===['schule'],'Repeated default does not overwrite existing role');
echo "Verified staged roles, review form, validation, applied roles, preserved contacts, occupied/shared addresses and repeat import.\n";

$invited = []; $checks = [];
foreach (['accept','fail','blocked-row','blocked-email','contact-only'] as $i=>$name) {
    $checks[] = ['ID'=>$tag.'invite'.$i,ContactWorkbook::ORGANIZATION=>$name,'Телефон'=>'0123',
        'Статус обращения'=>$i === 2 ? 'Не писать' : '', 'Email'=>$i === 4 ? '' : $tag.$i.'@example.invalid'];
}
$mail = static function ($pre,$args) use (&$invited,$checks) {
    $invited[] = $args;
    return $args['to'] !== $checks[1]['Email'];
};
add_filter('pre_wp_mail',$mail,99,2);
try {
    $group = \PaintCore\PCOE\CustomerGroups::SCHOOLS;
    $job = ContactImport::stage($checks,'schule',$group,[$checks[3]['Email']=>true]);
    $fields = [];
    foreach (ContactImport::page($job,0) as $i=>$row) $fields[$i]=['selected'=>'1','role'=>$i===1?'partner':'schule','email'=>$row['email']];
    $subset = $fields;
    unset($subset[1]['selected']);
    ContactImport::apply($job,0,$subset,true);
    $check(count($invited)===1 && !email_exists($checks[1]['Email']), 'Unchecked address neither registered nor invited');
    ContactImport::apply($job,0,$fields,true);
    $result = ContactImport::page($job,0);
    $check(array_column($result,'invitation') === ['accepted','failed','blocked','blocked'],'Per-recipient invitation outcomes');
    $check(array_column($invited,'to') === [$checks[0]['Email'],$checks[1]['Email']],'Only eligible recipients');
    preg_match('~https?://\S+action=rp\S+~',$invited[0]['message'],$match);
    parse_str(parse_url($match[0],PHP_URL_QUERY),$link);
    $owner = check_password_reset_key($link['key'],$link['login']);
    $check(!is_wp_error($owner) && $owner->user_email === $checks[0]['Email'],'Valid personal link');
    $all = \PaintCore\PCOE\ManagerWorkspace::directory($tag,'','',1,25,'',$group);
    $partners = \PaintCore\PCOE\ManagerWorkspace::directory($tag,'partner','',1,25,'',$group);
    $check((int)$all['total'] === 4 && (int)$partners['total'] === 1,'Group filter independent of price role');
    $card = get_page_by_path('xlsx-'.hash('sha256',$checks[4]['ID']),OBJECT,[ContactImport::CARD]);
    $check($card && get_post_meta($card->ID,\PaintCore\PCOE\CustomerGroups::META,true)===$group,'Contact-only row grouped');
    ContactImport::apply($job,0,$fields,true);
    $check(count($invited)===2,'No repeat mail after accepted or failed send');
} finally { remove_filter('pre_wp_mail',$mail,99); }
echo "Verified group filters, customer roles, personal invitation link, blocked addresses, transport failure and repeat submission.\n";
