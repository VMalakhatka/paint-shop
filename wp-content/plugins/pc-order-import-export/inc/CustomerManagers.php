<?php
namespace PaintCore\PCOE;
defined('ABSPATH') || exit;

/** Customer defaults are copied to threads; later changes apply to open threads only by choice. */
final class CustomerManagers {
    const META='_pcoe_chat_team';

    public static function get(int $customer): array {
        $value=(array)get_user_meta($customer,self::META,true);
        return ['primary'=>(int)($value['primary']??0),'secondary'=>(int)($value['secondary']??0),'revision'=>(string)($value['revision']??'')];
    }

    public static function validate(int $primary,int $secondary): void {
        if ($primary<0 || $secondary<0 || ($secondary && (!$primary || $secondary===$primary))) {
            throw new \RuntimeException(__('Choose a primary manager and a different additional manager, or leave the additional manager empty.','pc-order-import-export'));
        }
        foreach ([$primary,$secondary] as $manager) if ($manager && !user_can($manager,'manage_woocommerce')) {
            throw new \RuntimeException(__('Select an active manager.','pc-order-import-export'));
        }
    }

    public static function defaults(int $customer): array {
        $team=self::get($customer);
        // Do not silently promote the deputy when the responsible manager loses access.
        if (!$team['primary'] || !user_can($team['primary'],'manage_woocommerce')) return [0,0];
        return [$team['primary'],$team['secondary'] && $team['secondary']!==$team['primary'] && user_can($team['secondary'],'manage_woocommerce')?$team['secondary']:0];
    }

    public static function save(int $customer,int $primary,int $secondary,string $revision,bool $apply): void {
        ManagerWorkspace::customer($customer);
        if (!ConversationStore::storage_ready()) ConversationStore::deny();
        global $wpdb;
        if (strtolower((string)$wpdb->get_var($wpdb->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',$wpdb->usermeta)))!=='innodb') ConversationStore::deny();
        try {
            ConversationStore::lock('customer-team:'.$customer,static function()use($customer,$primary,$secondary,$revision,$apply){
                wp_cache_delete($customer,'user_meta');
                self::validate($primary,$secondary);$old=self::get($customer);
                if (!hash_equals($old['revision'],$revision)) throw new \RuntimeException(__('Customer managers changed. Refresh the customer card before saving.','pc-order-import-export'));
                $ids=$apply?get_posts(['post_type'=>ConversationStore::THREAD,'post_status'=>'private','author'=>$customer,'numberposts'=>201,'fields'=>'ids','orderby'=>'ID','order'=>'ASC','meta_query'=>[['key'=>'_chat_state','value'=>'closed','compare'=>'!=']]]):[];
                if (count($ids)>200) throw new \RuntimeException(__('More than 200 open conversations. Save defaults without bulk application and update conversations separately.','pc-order-import-export'));
                $team=['primary'=>$primary,'secondary'=>$secondary,'revision'=>wp_generate_uuid4()];
                if (!update_user_meta($customer,self::META,$team)) ConversationStore::deny();
                foreach ($ids as $id) ConversationStore::lock('thread:'.$id,static function()use($id,$primary,$secondary){
                    clean_post_cache($id);$meta=ConversationStore::meta($id);
                    if ($meta['state']!=='closed') ConversationStore::assign($id,$primary,$meta['state'],$meta['revision'],$secondary);
                });
            });
        } finally { wp_cache_delete($customer,'user_meta'); }
    }

    public static function select(string $name,string $label,int $selected): void {
        echo '<label>'.esc_html($label).'<select name="'.esc_attr($name).'"><option value="0">'.esc_html__('Unassigned','pc-order-import-export').'</option>';
        $found=!$selected;
        foreach (get_users(['capability'=>'manage_woocommerce','orderby'=>'display_name']) as $user) {
            if ((int)$user->ID===$selected) $found=true;
            echo '<option value="'.esc_attr($user->ID).'" '.selected($selected,$user->ID,false).'>'.esc_html($user->display_name).'</option>';
        }
        if (!$found) echo '<option value="'.esc_attr($selected).'" selected>'.esc_html__('Unavailable manager — choose a replacement','pc-order-import-export').'</option>';
        echo '</select></label>';
    }

    public static function render(int $customer): void {
        ManagerWorkspace::customer($customer);$team=self::get($customer);
        echo '<section class="pcoe-card pcoe-customer-team"><h2>'.esc_html__('Customer managers','pc-order-import-export').'</h2>';
        echo '<p>'.esc_html__('New conversations inherit this pair. Both managers receive customer messages and public replies from their colleague in their connected Telegram. The primary manager remains responsible.','pc-order-import-export').'</p>';
        if (isset($_GET['team_saved'])) echo '<p role="status">'.esc_html__('Customer managers saved.','pc-order-import-export').'</p>';
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
        echo '<input type="hidden" name="action" value="pcoe_customer_managers"><input type="hidden" name="customer_id" value="'.esc_attr($customer).'"><input type="hidden" name="revision" value="'.esc_attr($team['revision']).'">';
        wp_nonce_field('pcoe_customer_managers');
        self::select('primary',__('Primary manager','pc-order-import-export'),$team['primary']);
        self::select('secondary',__('Additional manager','pc-order-import-export'),$team['secondary']);
        echo '<label class="pcoe-team-apply"><input type="checkbox" name="apply_open" value="1"> '.esc_html__('Also replace managers in all existing open conversations for this customer. Closed conversations keep their assignment.','pc-order-import-export').'</label>';
        echo '<button class="button button-primary">'.esc_html__('Save customer managers','pc-order-import-export').'</button></form></section>';
    }

    public static function post(): void {
        check_admin_referer('pcoe_customer_managers');
        try {
            $customer=absint($_POST['customer_id']??0);
            self::save($customer,absint($_POST['primary']??0),absint($_POST['secondary']??0),(string)($_POST['revision']??''),($_POST['apply_open']??'')==='1');
            wp_safe_redirect(add_query_arg('team_saved','1',ManagerWorkspace::url($customer)));exit;
        } catch (\Throwable $e) { wp_die(esc_html($e->getMessage()),esc_html__('Customer managers','pc-order-import-export'),['response'=>400,'back_link'=>true]); }
    }
}
