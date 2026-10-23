<?php
namespace PaintCore\PCOE;
defined('ABSPATH') || exit;

/** Operator-only migration of the prepared Customers XLSX. Never updates existing accounts. */
final class OpenCartCustomerImport {
    public const HEADERS = ['email','first_name','last_name','phone','role','source_sites','source_ids','country','city','address_1','address_2','postcode','company','addresses_json'];
    public static function excludedPhone(string $value): bool {
        $digits=preg_replace('/\D/u','',$value);
        return preg_match('/\+\s*7/u',$value) || str_starts_with($digits,'007')
            || (strlen($digits)===11 && in_array($digits[0],['7','8'],true));
    }
    public static function validate(array $r): void {
        if (!is_email($r['email']??'') || strlen($r['email'])>100) throw new \RuntimeException('Invalid email');
        if (!in_array($r['role']??'', ['customer','opt','partner','schule'],true)) throw new \RuntimeException('Invalid migration role');
        if (self::excludedPhone($r['phone']??'')) throw new \RuntimeException('Excluded phone');
        $sites=explode(';',$r['source_sites']??'');
        if (!$sites || array_diff($sites,['paint.dn.ua','1lavka.com'])) throw new \RuntimeException('Invalid source site');
        foreach ($r as $v) if (!is_string($v) || strlen($v)>30000 || str_contains($v,"\0")) throw new \RuntimeException('Invalid field');
        foreach (['first_name','last_name','phone','city','address_1','address_2','postcode','company'] as $k)
            if (mb_strlen($r[$k])>200) throw new \RuntimeException('Field too long');
        if (!in_array($r['country'],['','UA','AL'],true)) throw new \RuntimeException('Unexpected country');
        $addresses=json_decode($r['addresses_json'],true);
        if (!is_array($addresses) || count($addresses)>30) throw new \RuntimeException('Invalid address list');
        foreach ($addresses as $a) if (!is_array($a) || preg_match('/russia|росси/iu',(string)($a['country']??''))) throw new \RuntimeException('Excluded address');
    }
    public static function read(string $path): array {
        if (!is_file($path) || filesize($path)>5*1024*1024) throw new \RuntimeException('Invalid XLSX file');
        $reader=new \PhpOffice\PhpSpreadsheet\Reader\Xlsx();
        $reader->setReadDataOnly(true);$reader->setLoadSheetsOnly(['Customers']);
        $book=$reader->load($path);$sheet=$book->getSheetByName('Customers');
        if (!$sheet || $sheet->getHighestRow()>2001) throw new \RuntimeException('Invalid Customers sheet');
        $grid=$sheet->toArray(null,false,false,false);$header=array_shift($grid);
        if ($header!==self::HEADERS) throw new \RuntimeException('Unexpected column headers');
        $rows=[];$seen=[];
        foreach ($grid as $line) {
            if (!array_filter($line,static fn($v)=>$v!==null && $v!=='')) continue;
            $r=array_combine(self::HEADERS,array_map(static fn($v)=>trim((string)$v),$line));
            foreach (['first_name','last_name','phone','city','address_1','address_2','postcode','company'] as $field)
                $r[$field]=trim(strip_tags(html_entity_decode($r[$field],ENT_QUOTES|ENT_HTML5,'UTF-8')));
            $r['email']=strtolower($r['email']); self::validate($r);
            if (isset($seen[$r['email']])) throw new \RuntimeException('Duplicate email in file');
            $seen[$r['email']]=true;$rows[]=$r;
        }
        if (!$rows) throw new \RuntimeException('Empty file');
        return $rows;
    }
    private static function save(string $key,array $job): void {
        update_option($key,$job,false);wp_cache_delete($key,'options');
        if (get_option($key)!==$job) throw new \RuntimeException('Could not persist progress');
    }
    public static function invitation(array $r,string $url): string {
        $sites=implode(' та ',explode(';',$r['source_sites']));
        return "Вітаємо!\n\nВи були клієнтом {$sites}. Ми оновлюємо сайт та інтерфейс, щоб замовляти художні матеріали було зручніше.\n\nВаш особистий кабінет уже створено на kreul.com.ua. Запрошуємо познайомитися з оновленим магазином!\n\nУ кабінеті ви можете переглядати актуальні ціни та залишки, зручно оформлювати замовлення й бачити свої замовлення на новому сайті.\n\nЩоб почати користуватися кабінетом, установіть пароль за особистим посиланням:\n{$url}\n\nВаш логін для входу — ця електронна адреса. Старий пароль використовувати не потрібно. Якщо посилання втратило чинність, натисніть «Забули пароль?» у кабінеті.\n\nВідкрити сайт: https://kreul.com.ua/\nОсобистий кабінет: https://kreul.com.ua/my-account/\n\nПотрібна допомога зі входом або замовленням? Напишіть нам у відповідь на цей лист — ми допоможемо.\n\nЗ повагою,\nКоманда «Лавка художника»\n";
    }
    public static function run(string $path,bool $apply=false,bool $send=false): array {
        if (!defined('WP_CLI') || !WP_CLI) throw new \RuntimeException('CLI only');
        $rows=self::read($path);$hash=hash('sha256',wp_json_encode($rows));$key='pcoe_oc_import_'.$hash;
        $preview=['total'=>count($rows),'new'=>0,'existing'=>0,'hash'=>$hash];
        foreach ($rows as $r) $preview[email_exists($r['email'])?'existing':'new']++;
        if (!$apply) return $preview;
        foreach (array_unique(array_column($rows,'role')) as $role) {
            $def=get_role($role);
            if (!$def || array_diff(array_keys(array_filter($def->capabilities)),['read','level_0'])) throw new \RuntimeException('Unsafe customer role');
        }
        if (!function_exists('lavka_ecosystem_lock_acquire')) throw new \RuntimeException('Synchronization lock unavailable');
        foreach ($rows as $i=>$r) {
            $lock=lavka_ecosystem_lock_acquire('pc-order-import-export','customer_import','manual','OpenCart customer import',600);
            if (empty($lock['ok'])) throw new \RuntimeException('Another synchronization is running; resume the same file later');
            try {
                wp_cache_delete($key,'options');$job=get_option($key,['hash'=>$hash,'send'=>$send,'rows'=>[]]);
                if ($job['send']!==$send) throw new \RuntimeException('Invitation setting differs from original job');
                if (isset($job['rows'][$i])) continue; // creating/sending remain unresolved, never repeat blindly.
                if (email_exists($r['email'])) {$job['rows'][$i]=['status'=>'skipped','invite'=>'none'];self::save($key,$job);continue;}
                $login=FolioCustomerImport::username($r['email']);
                $job['rows'][$i]=['status'=>'creating','invite'=>'none','login'=>$login];self::save($key,$job);
                $mute=static fn()=>true;add_filter('pre_wp_mail',$mute,PHP_INT_MAX);
                try {
                    $id=wp_insert_user(['user_login'=>$login,'user_email'=>$r['email'],'user_pass'=>wp_generate_password(40,true,true),
                        'first_name'=>$r['first_name'],'last_name'=>$r['last_name'],'display_name'=>trim($r['first_name'].' '.$r['last_name']), 'role'=>$r['role']]);
                } finally {remove_filter('pre_wp_mail',$mute,PHP_INT_MAX);}
                if (is_wp_error($id)) {$job['rows'][$i]['status']='failed';$job['rows'][$i]['error']=$id->get_error_code();self::save($key,$job);continue;}
                $job['rows'][$i]['user_id']=$id;self::save($key,$job);
                $fields=['locale'=>'uk','_pcoe_oc_import'=>$hash,'_pcoe_oc_sources'=>$r['source_sites'],'_pcoe_oc_source_ids'=>$r['source_ids'],
                    '_pcoe_oc_addresses'=>$r['addresses_json'],'billing_email'=>$r['email'],'billing_phone'=>$r['phone'],'shipping_phone'=>$r['phone']];
                foreach (['billing','shipping'] as $prefix) foreach (['first_name','last_name','country','city','address_1','address_2','postcode','company'] as $f) $fields[$prefix.'_'.$f]=$r[$f];
                if ($r['role']==='customer') $fields+=['_folio_partner_id'=>'ИНЕТ КЛ','_folio_partner_short_name'=>'ИНЕТ КЛ','_folio_partner_name'=>'Интернет клиент','_folio_partner_type'=>'К'];
                foreach ($fields as $k=>$v) update_user_meta($id,$k,wp_slash($v));
                foreach ($fields as $k=>$v) if ((string)get_user_meta($id,$k,true)!==$v) throw new \RuntimeException('Profile persistence needs reconciliation');
                $user=get_userdata($id);
                if (!$user || $user->user_email!==$r['email'] || $user->roles!==[$r['role']]) throw new \RuntimeException('User verification failed');
                $job['rows'][$i]['status']='created';self::save($key,$job);
                if ($send) {
                    $job['rows'][$i]['invite']='sending';self::save($key,$job);
                    $reset=get_password_reset_key($user);
                    if (is_wp_error($reset)) $job['rows'][$i]['invite']='failed';
                    else {
                        $url=network_site_url('wp-login.php?action=rp&key='.rawurlencode($reset).'&login='.rawurlencode($login),'login');
                        $ok=wp_mail($r['email'],'Ваш новий кабінет у «Лавці художника» — kreul.com.ua',self::invitation($r,$url),['Content-Type: text/plain; charset=UTF-8']);
                        $job['rows'][$i]['invite']=$ok?'accepted':'failed';
                    }
                    self::save($key,$job);
                }
                if (($i+1)%25===0) \WP_CLI::log('Processed '.($i+1).' / '.count($rows));
            } finally {lavka_ecosystem_lock_release($lock['token']);}
        }
        $job=get_option($key);return ['hash'=>$hash,'total'=>count($rows),'status'=>array_count_values(array_column($job['rows'],'status')),'invitations'=>array_count_values(array_column($job['rows'],'invite'))];
    }
}
