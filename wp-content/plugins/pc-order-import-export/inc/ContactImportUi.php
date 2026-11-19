<?php
namespace PaintCore\PCOE;
defined('ABSPATH') || exit;

final class ContactImportUi {
    public static function url(array $args = []): string {
        return add_query_arg(['view'=>'excel-contacts'] + $args, ManagerWorkspace::url(0));
    }

    public static function post(): void {
        FolioCustomerImport::authorize();
        check_admin_referer('pcoe_contact_import');
        $job = absint($_POST['job'] ?? 0);
        $page = absint($_POST['part'] ?? 0);
        try {
            if (!$job) {
                $file = $_FILES['contacts'] ?? [];
                if (($file['error'] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name']) || strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)) !== 'xlsx')
                    throw new \RuntimeException(__('Invalid XLSX file.', 'pc-order-import-export'));
                $blockedEmails = [];
                $rows = ContactWorkbook::read($file['tmp_name'], $blockedEmails);
                $job = ContactImport::stage($rows, sanitize_key($_POST['role'] ?? ''), sanitize_key($_POST['customer_group'] ?? ''), $blockedEmails);
                $page = 0;
            } else {
                ContactImport::apply($job, $page, (array)wp_unslash($_POST['fields'] ?? []), ($_POST['operation'] ?? '') === 'invite');
            }
        } catch (\Throwable $error) {
            set_transient('pcoe_contact_error_'.get_current_user_id(), $error->getMessage(), 60);
        }
        wp_safe_redirect(self::url(['job'=>$job,'part'=>$page]));
        exit;
    }

    private static function startForm(): void {
        echo '<form method="post" enctype="multipart/form-data" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="pcoe_contact_import">';
        wp_nonce_field('pcoe_contact_import');
    }

    private static function role(string $field, string $current): void {
        echo '<select required aria-label="'.esc_attr__('Customer role','pc-order-import-export').'" name="'.esc_attr($field).'"><option value="">'.esc_html__('Choose a customer role.', 'pc-order-import-export').'</option>';
        foreach (FolioCustomerImport::roles() as $key=>$label) echo '<option value="'.esc_attr($key).'" '.selected($key,$current,false).'>'.esc_html($label).'</option>';
        echo '</select>';
    }

    private static function details(array $row): void {
        echo '<details><summary>'.esc_html($row[ContactWorkbook::ORGANIZATION]).'</summary><dl>';
        foreach ($row as $label=>$value) if ($value !== '') echo '<dt><strong>'.esc_html($label).'</strong></dt><dd>'.nl2br(esc_html($value)).'</dd>';
        echo '</dl></details>';
    }

    private static function result(string $status): string {
        $labels = ['existing'=>__('Previously imported', 'pc-order-import-export'), 'review'=>__('Needs review', 'pc-order-import-export'),
            'created'=>__('Customer created.', 'pc-order-import-export'), 'contact'=>__('Contact only', 'pc-order-import-export')];
        return $labels[$status] ?? '';
    }

    public static function render(): void {
        FolioCustomerImport::authorize();
        echo '<section class="pcoe-card"><h2>'.esc_html__('Excel contacts', 'pc-order-import-export').'</h2>';
        $key = 'pcoe_contact_error_'.get_current_user_id();
        if ($error = get_transient($key)) { delete_transient($key); echo '<p role="alert">'.esc_html($error).'</p>'; }
        $job = absint($_GET['job'] ?? 0);
        if ($job) {
            try { self::review($job, absint($_GET['part'] ?? 0)); }
            catch (\Throwable $error) { echo '<p role="alert">'.esc_html($error->getMessage()).'</p>'; }
            echo '<p><a href="'.esc_url(self::url()).'">'.esc_html__('Excel contacts', 'pc-order-import-export').'</a></p>';
        } else {
            self::startForm();
            echo '<p><label>'.esc_html__('Default role for this workbook', 'pc-order-import-export').' ';
            self::role('role','');
            echo '</label></p><p>';
            CustomerGroups::select('customer_group', CustomerGroups::SCHOOLS);
            echo '</p><p><input type="file" name="contacts" accept=".xlsx" required></p><button class="button button-primary">'.esc_html__('Review workbook', 'pc-order-import-export').'</button></form>';
            $jobs = get_posts(['post_type'=>ContactImport::JOB,'post_status'=>'private','author'=>get_current_user_id(),'numberposts'=>10]);
            if ($jobs) echo '<h3>'.esc_html__('Recent imports','pc-order-import-export').'</h3>';
            foreach ($jobs as $recent) echo '<p><a href="'.esc_url(self::url(['job'=>$recent->ID])).'">#'.esc_html($recent->ID.' · '.$recent->post_date).'</a></p>';
            self::directory();
        }
        echo '</section>';
    }

    private static function review(int $job, int $page): void {
        $rows = ContactImport::page($job,$page);
        $pages = (int)get_post_meta($job,'_pages',true);
        $summary = get_post_meta($job,'_summary',true);
        $counts = [];
        foreach ($summary['channels'] as $label=>$count) {
            if ($label === 'Телефон') $label = __('Phone','pc-order-import-export');
            $counts[] = $label.' '.(int)$count;
        }
        $counts[] = __('Contacts published on the organization website','pc-order-import-export').' '.(int)$summary['site'];
        echo '<p><strong>'.esc_html(sprintf(__('Organizations in workbook: %d','pc-order-import-export'),$summary['total'])).'</strong><br>'.esc_html(implode(' · ', $counts)).'</p>';
        echo '<p>'.esc_html__('Check the organizations to process. Invitations go to the selected registration email. Contact-only rows save a card without creating an account or sending a message.', 'pc-order-import-export').'</p>';
        $group = get_post_meta($job,'_group',true);
        if (isset(CustomerGroups::labels()[$group])) echo '<p>'.esc_html__('Customer group','pc-order-import-export').' · '.esc_html(CustomerGroups::labels()[$group]).'</p>';
        wp_enqueue_script('pcoe-contact-selection', PCOE_URL.'assets/contact-selection.js', [], filemtime(PCOE_DIR.'/assets/contact-selection.js'), true);
        $pending = in_array('', array_column($rows,'result'), true);
        self::startForm();
        echo '<input type="hidden" name="job" value="'.esc_attr($job).'"><input type="hidden" name="part" value="'.esc_attr($page).'"><table class="widefat striped"><thead><tr><th>';
        if ($pending) echo '<label><input type="checkbox" data-contact-select-all> '.esc_html__('Select all on this page','pc-order-import-export').'</label>';
        echo '</th><th>'.esc_html__('Organization','pc-order-import-export').'</th><th>'.esc_html__('Customer role','pc-order-import-export').'</th><th>'.esc_html__('Registration email','pc-order-import-export').'</th></tr></thead><tbody>';
        foreach ($rows as $index=>$entry) {
            echo '<tr><td>';
            if ($entry['result'] === '') {
                echo '<input type="checkbox" data-contact-select name="fields['.(int)$index.'][selected]" value="1" aria-label="'.esc_attr(sprintf(__('Select %s','pc-order-import-export'),$entry['source'][ContactWorkbook::ORGANIZATION])).'">';
            }
            echo '</td><td>'; self::details($entry['source']); echo '</td><td>';
            if ($entry['result']) {
                $unchanged = in_array($entry['result'], ['existing','review'], true);
                $label = $unchanged ? '—' : (FolioCustomerImport::roles()[$entry['role']] ?? $entry['role']);
                echo esc_html($label).'</td><td>'.esc_html(trim(self::result($entry['result']).' '.($unchanged ? '' : $entry['email'])));
                if (!empty($entry['invitation'])) echo '<br>'.esc_html(ContactInvitation::label($entry['invitation']));
            } else {
                self::role('fields['.$index.'][role]', $entry['role']);
                echo '</td><td><select aria-label="'.esc_attr__('Registration email','pc-order-import-export').'" name="fields['.(int)$index.'][email]"><option value="">'.esc_html__('Contact card only (no account or email)','pc-order-import-export').'</option>';
                foreach ($entry['options'] as $email) echo '<option '.selected($entry['email'],$email,false).' value="'.esc_attr($email).'">'.esc_html($email.(in_array($email,$entry['blocked_emails'] ?? [],true) ? ' · '.ContactInvitation::label('blocked') : '')).'</option>';
                echo '</select>';
                if (ContactInvitation::blocked($entry['source'])) echo '<br>'.esc_html(ContactInvitation::label('blocked'));
            }
            echo '</td></tr>';
        }
        echo '</tbody></table>';
        if ($pending) echo '<p><button class="button">'.esc_html__('Save selected rows without invitations', 'pc-order-import-export').'</button> <button class="button button-primary" name="operation" value="invite">'.esc_html__('Register and send invitations', 'pc-order-import-export').'</button></p>';
        echo '</form>';
        self::navigation($page,$pages,['job'=>$job], 'part');
    }

    private static function directory(): void {
        $page = absint($_GET['list_page'] ?? 0);
        $group = sanitize_key($_GET['customer_group'] ?? '');
        $args = ['post_type'=>ContactImport::CARD,'post_status'=>'private','posts_per_page'=>25,'paged'=>$page+1,'orderby'=>'ID','order'=>'DESC'];
        echo '<form method="get"><input type="hidden" name="page" value="pcoe-customers"><input type="hidden" name="view" value="excel-contacts">';
        CustomerGroups::select('customer_group',$group,true);
        echo ' <button class="button">'.esc_html__('Filter customers','pc-order-import-export').'</button></form>';
        if (isset(CustomerGroups::labels()[$group])) $args += ['meta_key'=>CustomerGroups::META,'meta_value'=>$group];
        $query = new \WP_Query($args);
        foreach ($query->posts as $card) {
            $state = get_post_meta($card->ID,'_contact',true);
            if (empty($state['source'])) continue;
            self::details($state['source']);
            $invitation = ContactInvitation::label((string)get_post_meta($card->ID,ContactInvitation::META,true));
            if ($invitation !== '') echo '<p>'.esc_html($invitation).'</p>';
            echo '<p>'.esc_html((FolioCustomerImport::roles()[$state['role']] ?? $state['role']).' · '.self::result($state['status'])).'</p>';
        }
        self::navigation($page,(int)$query->max_num_pages,['customer_group'=>$group],'list_page');
    }

    private static function navigation(int $page, int $pages, array $args, string $param): void {
        if (!$pages) return;
        echo '<p>';
        if ($page > 0) echo '<a class="button" href="'.esc_url(self::url($args+[$param=>$page-1])).'">'.esc_html__('Previous page','pc-order-import-export').'</a> ';
        echo esc_html(($page+1).' / '.$pages);
        if ($page+1 < $pages) echo ' <a class="button" href="'.esc_url(self::url($args+[$param=>$page+1])).'">'.esc_html__('Next page','pc-order-import-export').'</a>';
        echo '</p>';
    }
}
