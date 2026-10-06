<?php
namespace PaintCore\PCOE;
defined('ABSPATH') || exit;

/** Private manager notes, deliberately separate from public user biography. */
final class CustomerInternalNotes {
    public const META = '_pcoe_customer_internal_note';
    public static function hooks(): void {
        add_action('edit_user_profile', [self::class, 'profile']);
        add_action('edit_user_profile_update', [self::class, 'save']);
    }
    public static function allowed(int $id): bool {
        return current_user_can('manage_woocommerce') && current_user_can('edit_user', $id)
            && CustomerPermissions::customer(get_userdata($id));
    }
    public static function summary(int $id): void {
        if (!self::allowed($id)) return;
        $note=(string)get_user_meta($id,self::META,true);
        if ($note==='') return;
        echo '<section class="pcoe-card"><h3>'.esc_html__('Internal customer note','pc-order-import-export').'</h3><p style="white-space:pre-wrap;overflow-wrap:anywhere">'.esc_html($note).'</p></section>';
    }
    public static function profile($user): void {
        if (!self::allowed((int)$user->ID)) return;
        echo '<h2>'.esc_html__('Internal customer note','pc-order-import-export').'</h2>';
        wp_nonce_field('pcoe_customer_note_'.$user->ID,'pcoe_customer_note_nonce');
        echo '<textarea class="large-text" rows="6" maxlength="20000" name="pcoe_customer_note" aria-label="'.esc_attr__('Internal customer note','pc-order-import-export').'">'.esc_textarea(get_user_meta($user->ID,self::META,true)).'</textarea>';
        echo '<p>'.esc_html__('Visible to managers only. Not included in customer emails.','pc-order-import-export').'</p>';
    }
    public static function save(int $id): void {
        if (!self::allowed($id) || !isset($_POST['pcoe_customer_note'],$_POST['pcoe_customer_note_nonce'])
            || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['pcoe_customer_note_nonce'])),'pcoe_customer_note_'.$id)) return;
        $note=sanitize_textarea_field(wp_unslash($_POST['pcoe_customer_note']));
        if (mb_strlen($note)>20000) wp_die(esc_html__('Internal customer note is too long (maximum 20000 characters).','pc-order-import-export'));
        update_user_meta($id,self::META,wp_slash($note));
    }
}
