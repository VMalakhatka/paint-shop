<?php
namespace PaintCore\PCOE;
defined('ABSPATH') || exit;

final class ContactImport {
    public const JOB = 'pcoe-xlsx-job';
    public const CARD = 'pcoe-contact';
    public const PAGE_SIZE = 25;

    public static function hooks(): void {
        add_action('init', static function () {
            register_taxonomy(CustomerGroups::TAXONOMY, self::CARD, [
                'public' => false, 'show_ui' => false, 'rewrite' => false, 'query_var' => false,
                'capabilities' => [
                    'manage_terms' => 'manage_woocommerce', 'edit_terms' => 'manage_woocommerce',
                    'assign_terms' => 'manage_woocommerce', 'delete_terms' => 'do_not_allow',
                ],
            ]);
            foreach ([self::JOB, self::CARD] as $type) register_post_type($type, ['public'=>false, 'show_ui'=>false, 'show_in_rest'=>false, 'supports'=>[]]);
        });
        add_action('admin_post_pcoe_create_customer_group', [CustomerGroups::class, 'post']);
        add_action('edit_user_profile',[CustomerGroups::class,'profile']);
        add_action('edit_user_profile_update',[CustomerGroups::class,'save']);
        add_action('admin_post_pcoe_contact_import', [ContactImportUi::class, 'post']);
        add_action('pcoe_contact_job_cleanup', static function ($id) {
            if (get_post_type($id) === self::JOB) wp_delete_post($id, true);
        });
    }

    private static function store(int $id, string $key, $value): void {
        update_post_meta($id, $key, wp_slash($value));
        if (get_post_meta($id, $key, true) !== $value) throw new \RuntimeException(__('Could not save import progress. Reload the page before continuing.', 'pc-order-import-export'));
    }

    public static function stage(array $rows, string $role, string $group = '', array $blockedEmails = []): int {
        FolioCustomerImport::authorize();
        self::checkRole($role);
        if ($group !== '' && !isset(CustomerGroups::labels()[$group])) throw new \RuntimeException(__('Unknown customer group.', 'pc-order-import-export'));
        $counts = [];
        foreach ($rows as $row) foreach (ContactWorkbook::emails($row) as $email) $counts[$email] = ($counts[$email] ?? 0) + 1;
        $job = wp_insert_post(['post_type'=>self::JOB, 'post_status'=>'private', 'post_title'=>'XLSX', 'post_author'=>get_current_user_id()], true);
        if (is_wp_error($job)) throw new \RuntimeException($job->get_error_message());
        wp_schedule_single_event(time()+7*DAY_IN_SECONDS, 'pcoe_contact_job_cleanup', [$job]);
        $countsByChannel = array_fill_keys(['Email','Телефон','Telegram','Viber','WhatsApp','Instagram','Facebook'], 0);
        $fromSite = 0;
        foreach ($rows as $row) {
            foreach ($countsByChannel as $channel=>$unused) {
                if (trim($row[$channel] ?? '') !== '') $countsByChannel[$channel]++;
            }
            if (($row['Качество источника'] ?? '') === 'Сайт организации: контакты опубликованы') $fromSite++;
        }
        self::store($job, '_summary', ['total'=>count($rows), 'channels'=>$countsByChannel, 'site'=>$fromSite]);
        self::store($job, '_group', $group);
        $pages = array_chunk($rows, self::PAGE_SIZE);
        foreach ($pages as $page => $records) {
            $entries = [];
            foreach ($records as $row) {
                $unique = array_values(array_filter(ContactWorkbook::emails($row), static fn($email) => $counts[$email] === 1 && !email_exists($email)));
                $known = self::existing($row);
                $entries[] = ['source'=>$row, 'role'=>$role, 'options'=>$unique, 'blocked_emails'=>array_values(array_filter($unique,static fn($email)=>isset($blockedEmails[$email]))), 'email'=>$unique[0] ?? '', 'result'=>$known];
            }
            self::store($job, '_page_'.$page, $entries);
        }
        self::store($job, '_pages', (string)count($pages));
        return $job;
    }

    private static function checkRole(string $role): void {
        if (!isset(FolioCustomerImport::roles()[$role])) throw new \RuntimeException(__('Choose a customer role.', 'pc-order-import-export'));
    }

    public static function page(int $job, int $page): array {
        FolioCustomerImport::authorize();
        $post = get_post($job);
        $total = (int)get_post_meta($job, '_pages', true);
        if (!$post || $post->post_type !== self::JOB || $post->post_status !== 'private' || ((int)$post->post_author !== get_current_user_id() && !current_user_can('manage_options')) || $page < 0 || $page >= $total)
            throw new \RuntimeException(__('Import not found or access denied.', 'pc-order-import-export'));
        $entries = get_post_meta($job, '_page_'.$page, true);
        if (!is_array($entries) || !$entries) throw new \RuntimeException(__('Import not found or access denied.', 'pc-order-import-export'));
        return $entries;
    }

    public static function apply(int $job, int $page, array $fields, bool $invite = false): void {
        FolioCustomerImport::authorize();
        FolioCustomerImport::locked(static function () use ($job, $page, $fields, $invite) {
            $entries = self::page($job, $page);
            $selected = array_filter($fields, static fn($field) => is_array($field) && ($field['selected'] ?? '') === '1');
            if (!$selected || array_diff_key($selected,$entries)) {
                throw new \RuntimeException(__('Select at least one organization on this page.', 'pc-order-import-export'));
            }
            $group = (string)get_post_meta($job,'_group',true);
            foreach ($entries as $i => &$entry) {
                if (!isset($selected[$i]) || $entry['result'] !== '') continue;
                $role = $fields[$i]['role'] ?? '';
                $email = $fields[$i]['email'] ?? null;
                self::checkRole($role);
                if ($email !== '' && !in_array($email, $entry['options'], true)) throw new \RuntimeException(__('Enter one valid customer email.', 'pc-order-import-export'));
                $entry['role'] = $role;
                $entry['email'] = $email;
            }
            unset($entry);
            foreach ($entries as $i => &$entry) {
                if (!isset($selected[$i]) || $entry['result'] !== '') continue;
                $entry['result'] = self::applyRow($entry, $group, $invite);
                self::store($job, '_page_'.$page, $entries);
            }
            unset($entry);
        });
    }

    private static function existing(array $row): string {
        $slug = 'xlsx-'.hash('sha256', $row['ID']);
        $name = $row[ContactWorkbook::ORGANIZATION];
        $card = get_page_by_path($slug, OBJECT, [self::CARD]);
        if ($card) {
            $state = get_post_meta($card->ID, '_contact', true);
            if (!is_array($state) || ($state['source'][ContactWorkbook::ORGANIZATION] ?? '') !== $name || $state['status'] === 'review') return 'review';
            return 'existing';
        }
        return '';
    }

    private static function applyRow(array &$entry, string $group, bool $invite): string {
        $row = $entry['source'];
        $name = $row[ContactWorkbook::ORGANIZATION];
        $slug = 'xlsx-'.hash('sha256', $row['ID']);
        $known = self::existing($row);
        if ($known !== '') return $known;
        $id = wp_insert_post(wp_slash(['post_type'=>self::CARD, 'post_status'=>'private', 'post_name'=>$slug, 'post_title'=>$name]), true);
        if (is_wp_error($id)) throw new \RuntimeException($id->get_error_message());
        $email = $entry['email'];
        $role = $entry['role'];
        $state = ['source'=>$row, 'email'=>$email, 'role'=>$role, 'status'=>'review', 'user_id'=>0];
        self::store($id, '_contact', $state);
        if ($group !== '') self::store($id, CustomerGroups::META, $group);
        if (!$email || email_exists($email)) {
            $state['status'] = 'contact';
        } else {
            self::checkRole($role);
            $mute = static fn() => true;
            add_filter('pre_wp_mail', $mute, PHP_INT_MAX);
            try {
                $user = wp_insert_user(wp_slash(['user_login'=>FolioCustomerImport::username($email), 'user_pass'=>wp_generate_password(40,true,true),
                    'user_email'=>$email, 'display_name'=>mb_substr($name,0,250), 'role'=>$role,
                    'meta_input'=>[CustomerGroups::META=>$group, '_pcoe_contact_id'=>$id, 'billing_company'=>$name, 'billing_email'=>$email, 'billing_phone'=>$row['Телефон'],
                        'billing_address_1'=>$row['Адрес из источника'] ?? '', 'billing_city'=>$row['Город / населённый пункт'] ?? '', 'billing_country'=>'UA']]));
            } finally { remove_filter('pre_wp_mail', $mute, PHP_INT_MAX); }
            if (!is_wp_error($user)) {
                $state['user_id'] = $user;
                $saved = get_userdata($user);
                if ($saved && CustomerPermissions::customer($saved) && $saved->roles === [$role] && $saved->user_email === $email && (int)get_user_meta($user,'_pcoe_contact_id',true) === $id) $state['status'] = 'created';
            }
        }
        self::store($id, '_contact', $state);
        if ($invite && $state['status'] === 'created') {
            $entry['invitation'] = ContactInvitation::send($id,$saved,$row,in_array($email,$entry['blocked_emails'] ?? [],true));
        }
        return $state['status'];
    }
}
