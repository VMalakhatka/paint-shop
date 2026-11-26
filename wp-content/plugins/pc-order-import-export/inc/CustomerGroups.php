<?php
namespace PaintCore\PCOE;
defined('ABSPATH') || exit;

/** Manager's filing group. Price and permission roles never read this metadata. */
final class CustomerGroups {
    public const META = '_pcoe_customer_group';
    public const SCHOOLS = 'schools-studios';
    public const TAXONOMY = 'pcoe_customer_group';

    public static function labels(): array {
        $labels = [
            self::SCHOOLS => __('Schools and studios', 'pc-order-import-export'),
            'retail-store' => __('Retail store', 'pc-order-import-export'),
            'online-store' => __('Online store', 'pc-order-import-export'),
        ];
        $terms = get_terms(['taxonomy' => self::TAXONOMY, 'hide_empty' => false, 'orderby' => 'name']);
        if (!is_wp_error($terms)) {
            foreach ($terms as $term) {
                $labels['group-'.$term->term_id] = wp_specialchars_decode($term->name, ENT_QUOTES);
            }
        }
        return $labels;
    }

    public static function create(string $name): string {
        $name = trim(preg_replace('/\s+/u', ' ', sanitize_text_field($name)));
        if ($name === '' || mb_strlen($name) > 100) {
            throw new \RuntimeException(__('Enter a group name of up to 100 characters.', 'pc-order-import-export'));
        }
        $folded = mb_strtolower($name);
        foreach (self::labels() as $key => $label) {
            if (mb_strtolower($label) === $folded) return $key;
        }
        $term = wp_insert_term(wp_slash($name), self::TAXONOMY);
        if (is_wp_error($term)) {
            if ($term->get_error_code() === 'term_exists') {
                return 'group-'.(int)$term->get_error_data('term_exists');
            }
            throw new \RuntimeException(__('Could not save the customer group.', 'pc-order-import-export'));
        }
        return 'group-'.$term['term_id'];
    }

    public static function post(): void {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('You do not have permission to perform this action.', 'pc-order-import-export'), '', ['response' => 403]);
        }
        check_admin_referer('pcoe_create_customer_group');
        $name = $_POST['group_name'] ?? '';
        try {
            $key = self::create(is_string($name) ? wp_unslash($name) : '');
        } catch (\RuntimeException $error) {
            wp_die(esc_html($error->getMessage()), '', ['back_link' => true]);
        }
        $url = ($_POST['group_return'] ?? '') === 'import' && FolioCustomerImport::allowed()
            ? ContactImportUi::url() : ManagerWorkspace::url(0);
        wp_safe_redirect(add_query_arg('ready_group', $key, $url));
        exit;
    }

    public static function createForm(bool $import = false): void {
        if (!current_user_can('manage_woocommerce')) return;
        $key = sanitize_key($_GET['ready_group'] ?? '');
        $label = $key !== '' ? (self::labels()[$key] ?? '') : '';
        if ($label !== '') {
            echo '<p role="status">'.esc_html(sprintf(__('Group available: %s', 'pc-order-import-export'), $label)).'</p>';
        }
        echo '<details><summary>'.esc_html__('Create customer group', 'pc-order-import-export').'</summary>';
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
        echo '<input type="hidden" name="action" value="pcoe_create_customer_group">';
        echo '<input type="hidden" name="group_return" value="'.($import ? 'import' : 'customers').'">';
        wp_nonce_field('pcoe_create_customer_group');
        echo '<p><label>'.esc_html__('Group name', 'pc-order-import-export').' <input name="group_name" required maxlength="100"></label> ';
        echo '<button class="button" type="submit">'.esc_html__('Create customer group', 'pc-order-import-export').'</button></p></form></details>';
    }

    public static function select(string $name, string $value, bool $filter = false): void {
        echo '<label>'.esc_html__('Customer group','pc-order-import-export').' <select name="'.esc_attr($name).'"><option value="">';
        echo esc_html($filter ? __('All groups','pc-order-import-export') : __('No group','pc-order-import-export')).'</option>';
        foreach (self::labels() as $key=>$label) echo '<option value="'.esc_attr($key).'" '.selected($key,$value,false).'>'.esc_html($label).'</option>';
        echo '</select></label>';
    }

    public static function profile($user): void {
        if (!CustomerInternalNotes::allowed($user->ID)) return;
        wp_nonce_field('pcoe_group_'.$user->ID,'pcoe_group_nonce');
        self::select('pcoe_group', (string)get_user_meta($user->ID,self::META,true));
    }

    public static function save(int $user): void {
        if (!CustomerInternalNotes::allowed($user) || !isset($_POST['pcoe_group_nonce'],$_POST['pcoe_group'])
            || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['pcoe_group_nonce'])),'pcoe_group_'.$user)) return;
        $group = sanitize_key($_POST['pcoe_group']);
        if ($group !== '' && !isset(self::labels()[$group])) return;
        update_user_meta($user,self::META,$group);
        $card = (int)get_user_meta($user,'_pcoe_contact_id',true);
        if ($card && get_post_type($card) === ContactImport::CARD && (int)(get_post_meta($card,'_contact',true)['user_id'] ?? 0) === $user) {
            update_post_meta($card,self::META,$group);
        }
    }
}
