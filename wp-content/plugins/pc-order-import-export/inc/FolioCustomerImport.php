<?php
namespace PaintCore\PCOE;
defined('ABSPATH') || exit;

/** Manager-selected registration. Folio is read-only; only new Woo customers are created. */
final class FolioCustomerImport {
    public const TYPE = 'pcoe-client-import';
    private const META = '_pcoe_client_import';
    public const LIMIT = 25;

    public static function hooks(): void {
        add_action('init', static function () { register_post_type(self::TYPE, ['public'=>false,'show_ui'=>false,'show_in_rest'=>false,'supports'=>[]]); });
        add_action('admin_post_pcoe_client_import', [FolioCustomerImportUi::class, 'post']);
        add_action('wp_ajax_pcoe_client_import_step', [FolioCustomerImportUi::class, 'step']);
        add_action('pcoe_client_import_cleanup', static function ($id) {
            if (get_post_type($id) === self::TYPE) wp_delete_post($id, true);
        });
    }

    public static function allowed(): bool {
        $actor = wp_get_current_user();
        return current_user_can('manage_woocommerce') && (current_user_can('create_users')
            || (in_array('shop_manager', $actor->roles, true) && current_user_can('promote_users')));
    }
    public static function authorize(): void {
        if (!self::allowed()) throw new \RuntimeException(__('You do not have permission to perform this action.', 'pc-order-import-export'));
    }
    public static function roles(): array {
        $out = [];
        foreach (ManagerWorkspace::customer_roles() as $role) {
            $definition = get_role($role);
            if ($definition && !array_diff(array_keys(array_filter($definition->capabilities)), ['read','level_0']))
                $out[$role] = translate_user_role(wp_roles()->get_names()[$role] ?? $role);
        }
        return $out;
    }
    public static function source(string $id): array {
        if (!function_exists('lps_java_get')) throw new \RuntimeException(__('Folio service is unavailable.', 'pc-order-import-export'));
        $response = lps_java_get(add_query_arg('id', $id, '/admin/folio/partners/registration'), ['timeout'=>20]);
        $data = is_wp_error($response) ? null : json_decode(wp_remote_retrieve_body($response), true);
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200 || !is_array($data)
            || ($data['id'] ?? '') !== $id || !in_array($data['type'] ?? '', ['П','Д','К','H'], true)
            || !array_key_exists('email', $data)) {
            throw new \RuntimeException(__('Could not read Folio registration data. Ask the administrator to check the backend update and import access token.', 'pc-order-import-export'));
        }
        // Only the documented contact fields enter job storage.
        return array_intersect_key($data, array_flip(['id','name','type','email','phone','alternatePhone','address','postcode','deliveryAddress','discountPercent']));
    }
    public static function get(int $id): array {
        self::authorize(); $post = get_post($id);
        if (!$post || $post->post_type !== self::TYPE || $post->post_status !== 'private'
            || ((int)$post->post_author !== get_current_user_id() && !current_user_can('manage_options')))
            throw new \RuntimeException(__('Import not found or access denied.', 'pc-order-import-export'));
        wp_cache_delete($id, 'post_meta'); $data = get_post_meta($id, self::META, true);
        if (!is_array($data) || !isset($data['rows'], $data['phase'])) throw new \RuntimeException('Invalid import');
        return $data;
    }
    public static function save(int $id, array $data): void {
        update_post_meta($id, self::META, wp_slash($data)); wp_cache_delete($id, 'post_meta');
        if (get_post_meta($id, self::META, true) !== $data) throw new \RuntimeException(__('Could not save import progress. Reload the page before continuing.', 'pc-order-import-export'));
    }
    public static function create(array $ids): int {
        self::authorize(); $ids = array_values(array_unique(array_map('trim', $ids)));
        if (!$ids || count($ids) > self::LIMIT) throw new \RuntimeException(__('Select up to 25 Folio customers.', 'pc-order-import-export'));
        $rows = [];
        foreach ($ids as $key) {
            if ($key === '' || mb_strlen($key) > 8) throw new \RuntimeException('Invalid Folio key');
            $source = self::source($key);
            $rows[] = ['source'=>$source, 'fields'=>[
                'email'=>$source['email'] ?? '', 'first_name'=>'', 'last_name'=>'', 'role'=>'',
                'phone'=>$source['phone'] ?? '', 'country'=>'', 'city'=>'',
                'address'=>$source['address'] ?? '', 'postcode'=>$source['postcode'] ?? '',
                'shipping_address'=>$source['deliveryAddress'] ?? '', 'shipping_city'=>'',
                'shipping_country'=>'', 'shipping_postcode'=>'',
            ], 'status'=>'edit', 'message'=>'', 'user_id'=>0, 'invite'=>'none'];
        }
        $id = wp_insert_post(['post_type'=>self::TYPE,'post_status'=>'private','post_author'=>get_current_user_id(),
            'post_title'=>__('Folio customer import', 'pc-order-import-export')], true);
        if (is_wp_error($id)) throw new \RuntimeException(__('Could not create the import.', 'pc-order-import-export'));
        self::save($id, ['rows'=>$rows,'phase'=>'edit','created'=>time(),'expires'=>0,'send'=>false]);
        wp_schedule_single_event(time()+7*DAY_IN_SECONDS, 'pcoe_client_import_cleanup', [$id]);
        return $id;
    }
    public static function conflict(array $row): string {
        global $wpdb;
        $key = $row['source']['id'];
        $mapped = $wpdb->get_col($wpdb->prepare("SELECT DISTINCT user_id FROM {$wpdb->usermeta} WHERE meta_key IN ('_folio_partner_id','_folio_partner_short_name') AND meta_value = %s", $key));
        $email_id = email_exists($row['fields']['email']);
        if ($mapped || $email_id) return __('An account already uses this email or Folio link. Skipped; review the existing customer separately.', 'pc-order-import-export');
        return '';
    }
    public static function preview(int $id, array $input): void {
        self::authorize();
        self::locked(static function () use ($id, $input) { self::prepare($id, $input); });
    }
    private static function prepare(int $id, array $input): void {
        $data = self::get($id);
        if (!in_array($data['phase'], ['edit','preview'], true)) throw new \RuntimeException(__('This import has already started.', 'pc-order-import-export'));
        $roles = self::roles(); $seen = [];
        $map = function_exists('lps_get_role_contract_map') ? lps_get_role_contract_map() : [];
        foreach ($data['rows'] as $i=>&$row) {
            foreach ($row['fields'] as $name=>$_) {
                $value = trim(sanitize_text_field($input[$i][$name] ?? ''));
                if (mb_strlen($value) > 200) throw new \RuntimeException(__('A customer field is too long (maximum 200 characters).', 'pc-order-import-export'));
                $row['fields'][$name] = $value;
            }
            $f = $row['fields']; $error = '';
            if (strlen($f['email']) > 100 || !is_email($f['email']) || preg_match('/[\r\n]/', $f['email'])) $error = __('Enter one valid customer email.', 'pc-order-import-export');
            elseif (!isset($roles[$f['role']]) || empty($map[$f['role']])) $error = __('Choose a customer price role with a configured Folio contract.', 'pc-order-import-export');
            elseif (($f['country'] && !isset(WC()->countries->get_countries()[$f['country']])) || ($f['shipping_country'] && !isset(WC()->countries->get_countries()[$f['shipping_country']]))) $error = __('Choose a valid country.', 'pc-order-import-export');
            else $error = self::conflict($row);
            $email = strtolower($f['email']);
            if (isset($seen[$email])) {
                $error = __('Duplicate email in this import. Check both customers.', 'pc-order-import-export');
                $data['rows'][$seen[$email]]['status']='skipped'; $data['rows'][$seen[$email]]['message']=$error;
            }
            $seen[$email]=$i;
            $row['contract'] = (string)($map[$f['role']] ?? '');
            $row['message']=$error; $row['status']=$error ? 'skipped' : 'ready';
        }
        unset($row);
        $data['phase']='preview'; $data['expires']=time()+30*MINUTE_IN_SECONDS;
        self::save($id, $data);
    }
    /** One customer per request; durable 'creating' / 'sending' stop unsafe retries after interruption. */
    public static function step(int $id, bool $confirm, bool $send): array {
        self::authorize();
        return self::locked(static function () use ($id, $confirm, $send): array {
            $data=self::get($id);
            if ($data['phase']==='preview') {
                if (!$confirm || time()>$data['expires']) throw new \RuntimeException(__('Confirm a fresh import preview before starting.', 'pc-order-import-export'));
                $data['phase']='running'; $data['send']=$send; self::save($id,$data);
            }
            if (!in_array($data['phase'],['running','complete'],true)) throw new \RuntimeException(__('Prepare the import preview first.', 'pc-order-import-export'));
            foreach ($data['rows'] as &$stale) {
                if ($stale['status']==='creating') {$stale['status']='review'; $stale['message']=__('Creation was interrupted. Check Users before creating this customer again.', 'pc-order-import-export');}
                if ($stale['invite']==='sending') $stale['invite']='review';
            } unset($stale);
            foreach ($data['rows'] as $i=>&$row) {
                if ($row['status']!=='ready') continue;
                $roles=self::roles(); $map=function_exists('lps_get_role_contract_map') ? lps_get_role_contract_map() : [];
                $error=self::conflict($row);
                if (!isset($roles['customer'], $roles[$row['fields']['role']]) || ($map[$row['fields']['role']] ?? '')!==$row['contract']) $error=__('Price settings changed. Prepare a new import.', 'pc-order-import-export');
                if ($error) {$row['status']='skipped';$row['message']=$error;break;}
                $source=self::source($row['source']['id']);
                if ($source!==$row['source']) {$row['status']='skipped';$row['message']=__('Folio details changed. Prepare a new import.', 'pc-order-import-export');break;}
                $f=$row['fields'];
                $row['login']='folio-'.substr(hash('sha256', $id.':'.$i.':'.wp_salt()),0,24);
                $row['status']='creating'; self::save($id,$data);
                $mute=static fn()=>true; add_filter('pre_wp_mail',$mute,PHP_INT_MAX);
                try { $uid=wp_insert_user(['user_login'=>$row['login'],'user_pass'=>wp_generate_password(40,true,true),
                    'user_email'=>$f['email'],'first_name'=>$f['first_name'],'last_name'=>$f['last_name'],
                    'display_name'=>mb_substr(trim($f['first_name'].' '.$f['last_name']) ?: (string)$source['name'],0,250), 'role'=>'customer']);
                } finally { remove_filter('pre_wp_mail',$mute,PHP_INT_MAX); }
                if (is_wp_error($uid)) {$row['status']='failed';$row['message']=$uid->get_error_message();break;}
                $row['user_id']=$uid; self::save($id,$data);
                $meta=['_folio_partner_id'=>$source['id'],'_folio_partner_short_name'=>$source['id'],
                    '_folio_partner_name'=>$source['name'],'_folio_partner_type'=>$source['type'],
                    '_pcoe_import_job'=>$id, 'billing_email'=>$f['email'], 'billing_first_name'=>$f['first_name'],
                    'billing_last_name'=>$f['last_name'],'billing_company'=>$source['name'],'billing_phone'=>$f['phone'],
                    'billing_country'=>$f['country'],'billing_city'=>$f['city'],'billing_address_1'=>$f['address'],
                    'billing_postcode'=>$f['postcode'],'shipping_first_name'=>$f['first_name'],'shipping_last_name'=>$f['last_name'],
                    'shipping_company'=>$source['name'],'shipping_phone'=>$f['phone'],'shipping_address_1'=>$f['shipping_address'],
                    'shipping_city'=>$f['shipping_city'],'shipping_country'=>$f['shipping_country'],'shipping_postcode'=>$f['shipping_postcode']];
                foreach ($meta as $key=>$value) update_user_meta($uid,$key,wp_slash($value));
                foreach ($meta as $key=>$value) if ((string)get_user_meta($uid,$key,true)!==(string)$value) throw new \RuntimeException(__('Customer creation needs review. Check the profile before sending an invitation.', 'pc-order-import-export'));
                (new \WP_User($uid))->set_role($f['role']);
                $saved=get_userdata($uid);
                if (!$saved || !CustomerPermissions::customer($saved) || $saved->roles!==[$f['role']] || $saved->user_email!==$f['email']
                    || get_user_meta($uid,'_folio_partner_short_name',true)!==$source['id'] || get_user_meta($uid,'_folio_partner_id',true)!==$source['id']) {
                    (new \WP_User($uid))->set_role('customer');
                    throw new \RuntimeException(__('Customer creation needs review. Check the profile before sending an invitation.', 'pc-order-import-export'));
                }
                $row['status']='created';$row['message']=__('Customer created.', 'pc-order-import-export');self::save($id,$data);
                if ($data['send']) {
                    $row['invite']='sending';self::save($id,$data);
                    $result=retrieve_password($row['login']);
                    $row['invite']=is_wp_error($result) ? 'failed' : 'accepted';
                }
                break;
            } unset($row);
            if (!array_filter($data['rows'], static fn($r)=>$r['status']==='ready')) $data['phase']='complete';
            self::save($id,$data);
            return ['complete'=>$data['phase']==='complete','processed'=>count(array_filter($data['rows'], static fn($r)=>$r['status']!=='ready')),'total'=>count($data['rows'])];
        });
    }
    private static function locked(callable $work) {
        if (!function_exists('lavka_ecosystem_lock_acquire')) throw new \RuntimeException(__('The synchronization lock is unavailable.', 'pc-order-import-export'));
        $lock = lavka_ecosystem_lock_acquire('pc-order-import-export','customer_import','manual',__('Folio customer import','pc-order-import-export'),600);
        if (empty($lock['ok'])) throw new \RuntimeException(__('Another synchronization is running. Retry when it finishes.', 'pc-order-import-export'));
        try { return $work(); } finally { lavka_ecosystem_lock_release($lock['token']); }
    }
}
