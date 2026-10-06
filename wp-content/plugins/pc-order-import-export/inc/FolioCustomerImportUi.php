<?php
namespace PaintCore\PCOE;
defined('ABSPATH') || exit;

final class FolioCustomerImportUi {
    public static function url(int $job=0): string { return add_query_arg(['view'=>'customer-import','import_id'=>$job],ManagerWorkspace::url(0)); }
    public static function post(): void {
        $id=absint($_POST['import_id']??0);
        try {
            FolioCustomerImport::authorize(); check_admin_referer('pcoe_client_import');
            $operation=sanitize_key($_POST['operation']??'');
            if ($operation==='select') $id=FolioCustomerImport::create(array_map('sanitize_text_field',(array)wp_unslash($_POST['clients']??[])));
            elseif ($operation==='preview') FolioCustomerImport::preview($id,(array)wp_unslash($_POST['fields']??[]));
            else throw new \RuntimeException('Invalid operation');
        } catch (\Throwable $e) {set_transient('pcoe_import_error_'.get_current_user_id(),$e->getMessage(),60);}
        wp_safe_redirect(self::url($id));exit;
    }
    public static function step(): void {
        try {
            FolioCustomerImport::authorize();check_ajax_referer('pcoe_client_import','nonce');
            $result=FolioCustomerImport::step(absint($_POST['import_id']??0),($_POST['confirm']??'')==='1',($_POST['send']??'')==='1');
            wp_send_json_success($result);
        } catch (\Throwable $e) {wp_send_json_error(['message'=>$e->getMessage()],400);}
    }
    private static function fields(string $operation,int $id=0): void {
        wp_nonce_field('pcoe_client_import');
        echo '<input type="hidden" name="action" value="pcoe_client_import"><input type="hidden" name="operation" value="'.esc_attr($operation).'"><input type="hidden" name="import_id" value="'.esc_attr($id).'">';
    }
    public static function render(): void {
        FolioCustomerImport::authorize();
        wp_enqueue_script('pcoe-customer-import',PCOE_URL.'assets/customer-import.js',[],filemtime(PCOE_DIR.'/assets/customer-import.js'),true);
        wp_localize_script('pcoe-customer-import','pcoeCustomerImport',['url'=>admin_url('admin-ajax.php'),'nonce'=>wp_create_nonce('pcoe_client_import'),
            'busy'=>__('Creating customers… Keep this page open.','pc-order-import-export'),
            'error'=>__('The request was interrupted. Reload this page to check progress before continuing.','pc-order-import-export')]);
        echo '<section class="pcoe-card"><h2>'.esc_html__('Import customers from Folio','pc-order-import-export').'</h2>';
        ManagerHelp::link('register-wholesale');
        echo '<p>'.esc_html__('Select up to 25 customers per import. Existing accounts are skipped. Contact details and price roles must be checked before creating accounts.','pc-order-import-export').'</p>';
        $error=get_transient('pcoe_import_error_'.get_current_user_id());
        if ($error) {delete_transient('pcoe_import_error_'.get_current_user_id());echo '<p role="alert">'.esc_html($error).'</p>';}
        try {
            $id=absint($_GET['import_id']??0);
            if ($id) self::job($id,FolioCustomerImport::get($id)); else self::search();
        } catch (\Throwable $e) {echo '<p role="alert">'.esc_html($e->getMessage()).'</p>';}
        echo '</section>';
    }
    private static function search(): void {
        $q=sanitize_text_field(wp_unslash($_GET['folio_search']??''));$type=sanitize_text_field(wp_unslash($_GET['folio_type']??''));
        $types=array_intersect_key(ManagerWorkspace::folio_organization_types(),array_flip(['П','Д','К','H']));
        if (!isset($types[$type])) $type='';
        $page=max(1,absint($_GET['folio_page']??1));
        echo '<form method="get" class="pcoe-directory-filters"><input type="hidden" name="page" value="pcoe-customers"><input type="hidden" name="view" value="customer-import">';
        echo '<label>'.esc_html__('Folio customer:','pc-order-import-export').'<input name="folio_search" value="'.esc_attr($q).'"></label><label>'.esc_html__('Folio organization type','pc-order-import-export').'<select name="folio_type"><option value="">'.esc_html__('All organization types','pc-order-import-export').'</option>';
        foreach($types as $key=>$label)echo '<option value="'.esc_attr($key).'" '.selected($key,$type,false).'>'.esc_html($label).'</option>';
        echo '</select></label><button class="button">'.esc_html__('Show Folio customers','pc-order-import-export').'</button></form>';
        if (!function_exists('lps_java_get')) throw new \RuntimeException(__('Folio service is unavailable.','pc-order-import-export'));
        $response=lps_java_get(add_query_arg(['q'=>$q,'types'=>$type?:'П,Д,К,H','limit'=>25,'offset'=>($page-1)*25],'/admin/folio/partners'),['timeout'=>20]);
        $data=is_wp_error($response)?null:json_decode(wp_remote_retrieve_body($response),true);
        if(is_wp_error($response)||wp_remote_retrieve_response_code($response)!==200||empty($data['ok'])||!isset($data['items']))throw new \RuntimeException(__('Folio service is unavailable.','pc-order-import-export'));
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';self::fields('select');
        echo '<p><label><input type="checkbox" data-import-select-all> '.esc_html__('Select this page','pc-order-import-export').'</label></p><div class="pcoe-scroll"><table class="widefat striped"><thead><tr><th>'.esc_html__('Select','pc-order-import-export').'</th><th>'.esc_html__('Folio customer:','pc-order-import-export').'</th><th>'.esc_html__('Folio organization type','pc-order-import-export').'</th></tr></thead><tbody>';
        foreach ($data['items'] as $row) {
            if(!in_array($row['type']??'',array_keys($types),true))continue;
            echo '<tr><td><input type="checkbox" name="clients[]" value="'.esc_attr($row['id']).'" aria-label="'.esc_attr($row['name']??$row['id']).'"></td><td>'.esc_html($row['name']??'').'<br><code>'.esc_html($row['id']).'</code></td><td>'.esc_html($types[$row['type']]).'</td></tr>';
        }
        echo '</tbody></table></div><button class="button button-primary">'.esc_html__('Load selected customer details','pc-order-import-export').'</button></form><p class="pcoe-actions">';
        $url=add_query_arg(['folio_search'=>$q,'folio_type'=>$type],self::url());
        if($page>1)echo '<a class="button" href="'.esc_url(add_query_arg('folio_page',$page-1,$url)).'">'.esc_html__('Previous page','pc-order-import-export').'</a>';
        if($page*25<(int)$data['total'])echo '<a class="button" href="'.esc_url(add_query_arg('folio_page',$page+1,$url)).'">'.esc_html__('Next page','pc-order-import-export').'</a>';
        echo '</p>';
        $recent=get_posts(['post_type'=>FolioCustomerImport::TYPE,'post_status'=>'private','author'=>get_current_user_id(),'numberposts'=>10]);
        if($recent){echo '<h3>'.esc_html__('Recent imports','pc-order-import-export').'</h3><ul>';foreach($recent as $job)echo '<li><a href="'.esc_url(self::url($job->ID)).'">#'.esc_html($job->ID).' · '.esc_html($job->post_date).'</a></li>';echo '</ul>';}
    }
    private static function job(int $id,array $data): void {
        $editable=in_array($data['phase'],['edit','preview'],true);
        echo '<p><a href="'.esc_url(self::url()).'">'.esc_html__('Select other Folio customers','pc-order-import-export').'</a></p>';
        if($editable){
            echo '<p>'.esc_html__('Folio price contracts are not supplied by this customer directory. Choose the agreed site price role for each customer. Personal Folio discounts are shown for review and are not applied automatically.','pc-order-import-export').'</p>';
            echo '<form method="post" novalidate action="'.esc_url(admin_url('admin-post.php')).'">';self::fields('preview',$id);
        }
        $labels=['email'=>__('Email','pc-order-import-export'),'first_name'=>__('First name','pc-order-import-export'),'last_name'=>__('Last name','pc-order-import-export'),
            'phone'=>__('Phone','pc-order-import-export'),'country'=>__('Billing country','pc-order-import-export'),'city'=>__('City','pc-order-import-export'),
            'address'=>__('Billing address','pc-order-import-export'),'postcode'=>__('Postcode','pc-order-import-export'),
            'shipping_country'=>__('Shipping country','pc-order-import-export'),'shipping_city'=>__('Shipping city','pc-order-import-export'),
            'shipping_address'=>__('Shipping address','pc-order-import-export'),'shipping_postcode'=>__('Shipping postcode','pc-order-import-export')];
        $roles=FolioCustomerImport::roles();$map=function_exists('lps_get_role_contract_map')?lps_get_role_contract_map():[];
        $statuses=['edit'=>__('Check details','pc-order-import-export'),'ready'=>__('Ready to create','pc-order-import-export'),'skipped'=>__('Skipped','pc-order-import-export'),
            'created'=>__('Customer created.','pc-order-import-export'),'creating'=>__('Needs review','pc-order-import-export'),'review'=>__('Needs review','pc-order-import-export'),'failed'=>__('Failed','pc-order-import-export')];
        $invites=['none'=>__('Invitation not sent','pc-order-import-export'),'accepted'=>__('Accepted by the mail service','pc-order-import-export'),
            'failed'=>__('Invitation failed; send it manually from the customer profile.','pc-order-import-export'),'sending'=>__('Invitation outcome needs review; check the mail log.','pc-order-import-export'),'review'=>__('Invitation outcome needs review; check the mail log.','pc-order-import-export')];
        foreach($data['rows'] as $i=>$row){
            echo '<fieldset class="pcoe-card"><legend><strong>'.esc_html(($row['source']['name']??'').' · '.$row['source']['id']).'</strong></legend>';
            echo '<p><strong>'.esc_html($statuses[$row['status']]??$row['status']).'</strong> '.esc_html($row['message']).'</p>';
            if($editable){
                echo '<p>'.esc_html__('Folio discount, %:','pc-order-import-export').' '.esc_html((string)($row['source']['discountPercent']??0)).' · '.esc_html__('Alternate phone:','pc-order-import-export').' '.esc_html($row['source']['alternatePhone']??'').'</p><div class="pcoe-directory-filters">';
                foreach($labels as $name=>$label){
                    echo '<label>'.esc_html($label);
                    if(in_array($name,['country','shipping_country'],true)){
                        echo '<select name="fields['.$i.']['.esc_attr($name).']"><option value="">—</option>';
                        foreach(WC()->countries->get_countries() as $code=>$country)echo '<option value="'.esc_attr($code).'" '.selected($row['fields'][$name],$code,false).'>'.esc_html($country).'</option>';
                        echo '</select>';
                    } else echo '<input type="'.($name==='email'?'email':'text').'" maxlength="200" name="fields['.$i.']['.esc_attr($name).']" value="'.esc_attr($row['fields'][$name]).'">';
                    echo '</label>';
                }
                echo '<label>'.esc_html__('Price role:','pc-order-import-export').'<select name="fields['.$i.'][role]"><option value="">—</option>';
                foreach($roles as $role=>$label)if(!empty($map[$role]))echo '<option value="'.esc_attr($role).'" '.selected($row['fields']['role'],$role,false).'>'.esc_html($label.' · '.$map[$role]).'</option>';
                echo '</select></label></div>';
            } else {
                echo '<p>'.esc_html($row['fields']['email'].' · '.($roles[$row['fields']['role']]??$row['fields']['role'])).'</p><p>'.esc_html($invites[$row['invite']]??'').'</p>';
                if($row['user_id'] && current_user_can('edit_user',$row['user_id']))echo '<a href="'.esc_url(get_edit_user_link($row['user_id'])).'">'.esc_html__('Edit User').'</a>';
                if($row['status']==='creating')echo '<p>'.esc_html__('Creation was interrupted. Check Users before creating this customer again.','pc-order-import-export').'</p>';
            }
            echo '</fieldset>';
        }
        if($editable)echo '<button class="button">'.esc_html__('Check import preview','pc-order-import-export').'</button></form>';
        $ready=count(array_filter($data['rows'],static fn($r)=>$r['status']==='ready'));
        if($data['phase']==='preview' && $ready){
            echo '<p>'.esc_html(sprintf(__('Ready to create: %d. Other rows will be skipped.','pc-order-import-export'),$ready)).'</p>';
            echo '<p>'.esc_html__('The preview contains the last saved details. After editing any field, check the preview again.','pc-order-import-export').'</p>';
        }
        if(($data['phase']==='preview' && $ready)||$data['phase']==='running'){
            echo '<form data-customer-import-run data-import-id="'.esc_attr($id).'">';
            if($data['phase']==='preview')echo '<label><input type="checkbox" name="confirm" value="1" required> '.esc_html__('I checked emails, Folio links, addresses and agreed price roles. Create the ready customers.','pc-order-import-export').'</label><label><input type="checkbox" name="send" value="1"> '.esc_html__('Email password-setting links to newly created customers','pc-order-import-export').'</label>';
            echo '<button class="button button-primary">'.esc_html($data['phase']==='preview'?__('Create selected customers','pc-order-import-export'):__('Continue import','pc-order-import-export')).'</button></form>';
        }
        echo '<p data-customer-import-status role="status" aria-live="polite"></p><p>'.esc_html__('Import reports are kept for seven days. Invitations can also be sent manually from the customer profile. Mail service acceptance does not confirm inbox delivery.','pc-order-import-export').'</p>';
    }
}
