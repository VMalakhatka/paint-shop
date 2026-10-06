<?php
namespace PaintCore\PCOE;
defined('ABSPATH') || exit;

/** Registration hints only; preview/apply remain authoritative for duplicates. */
final class FolioCustomerDirectory {
    public static function inspect(array $items): array {
        FolioCustomerImport::authorize();
        $ids=array_values(array_unique(array_filter(array_map(static fn($r)=>(string)($r['id']??''),$items),static fn($id)=>$id!=='')));
        if (!$ids || count($ids)>25) return [];
        $emails=[];
        if (function_exists('lps_java_post')) {
            $response=lps_java_post('/admin/folio/partners/registration-emails',$ids,['timeout'=>20]);
            if (!is_wp_error($response) && wp_remote_retrieve_response_code($response)===200) {
                $decoded=json_decode(wp_remote_retrieve_body($response),true);
                if(is_array($decoded)) $emails=$decoded;
            }
        }
        global $wpdb;
        $slots=implode(',',array_fill(0,count($ids),'%s'));
        $linked=$wpdb->get_results($wpdb->prepare("SELECT DISTINCT u.ID,u.user_login,u.display_name,m.meta_value FROM {$wpdb->users} u INNER JOIN {$wpdb->usermeta} m ON m.user_id=u.ID WHERE m.meta_key IN ('_folio_partner_id','_folio_partner_short_name') AND m.meta_value IN ($slots)",...$ids),ARRAY_A);
        $lookup=[];
        foreach($ids as $id) if(isset($emails[$id]) && is_string($emails[$id]) && is_email(trim($emails[$id]))) $lookup[]=trim($emails[$id]);
        $matches=[];
        if($lookup){
            $slots=implode(',',array_fill(0,count($lookup),'%s'));
            $matches=$wpdb->get_results($wpdb->prepare("SELECT ID,user_login,display_name,user_email FROM {$wpdb->users} WHERE user_email IN ($slots)",...$lookup),ARRAY_A);
        }
        if($linked===null || $matches===null) throw new \RuntimeException(__('Customer account check unavailable.', 'pc-order-import-export'));
        $result=[];
        foreach($ids as $id){
            $known=array_key_exists($id,$emails) && (is_string($emails[$id]) || $emails[$id]===null);
            $email=$known?trim((string)$emails[$id]):'';
            $result[$id]=['email'=>$email,'known'=>$known,'email_users'=>[],'folio_users'=>[]];
            foreach($matches as $u) if(strcasecmp($u['user_email'],$email)===0) $result[$id]['email_users'][(int)$u['ID']]=$u;
            foreach($linked as $u) if(mb_strtolower(trim($u['meta_value']))===mb_strtolower($id)) $result[$id]['folio_users'][(int)$u['ID']]=$u;
        }
        return $result;
    }
    private static function users(array $users): void {
        foreach($users as $user){
            echo '<br>';
            if(current_user_can('edit_user',(int)$user['ID'])) echo '<a target="_blank" rel="noopener" href="'.esc_url(get_edit_user_link((int)$user['ID'])).'">'.esc_html($user['display_name'].' · '.$user['user_login']).'</a>';
            else echo esc_html__('Existing account','pc-order-import-export');
        }
    }
    public static function cells(array $status): void {
        echo '<td>';
        if(empty($status['known'])) echo esc_html__('Email check unavailable','pc-order-import-export');
        elseif($status['email']==='') echo esc_html__('No email in Folio','pc-order-import-export');
        else {
            echo esc_html($status['email']).'<br><strong>';
            echo esc_html($status['email_users']?__('Email already registered','pc-order-import-export'):(is_email($status['email'])?__('Email not registered','pc-order-import-export'):__('Check email','pc-order-import-export'))).'</strong>';
            self::users($status['email_users']);
        }
        echo '</td><td><strong>'.esc_html($status['folio_users']?__('Folio customer already linked','pc-order-import-export'):__('No Folio link found','pc-order-import-export')).'</strong>';
        self::users($status['folio_users']);echo '</td>';
    }
}
