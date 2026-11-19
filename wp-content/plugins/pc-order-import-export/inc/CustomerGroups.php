<?php
namespace PaintCore\PCOE;
defined('ABSPATH') || exit;

/** Manager's filing group. Price and permission roles never read this metadata. */
final class CustomerGroups {
    public const META = '_pcoe_customer_group';
    public const SCHOOLS = 'schools-studios';

    public static function labels(): array {
        return [self::SCHOOLS => __('Schools and studios', 'pc-order-import-export')];
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
