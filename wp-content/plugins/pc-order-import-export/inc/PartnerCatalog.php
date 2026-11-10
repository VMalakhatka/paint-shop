<?php
namespace PaintCore\PCOE;
defined('ABSPATH') || exit;

/** Per-owner, revocable retail feed and private manual exports. No order or stock writes. */
final class PartnerCatalog {
    const META='_pcoe_partner_catalog';
    public static function allowed(int $user): bool {
        $u=get_userdata($user);
        return $u && function_exists('pc_wholesale_customer_can_access') && pc_wholesale_customer_can_access($u);
    }
    public static function hooks(): void {
        add_action('wp_enqueue_scripts',static function(){if(is_account_page() && isset($_GET['pcoe_catalog']))wp_enqueue_style('pcoe-partner-catalog',PCOE_URL.'assets/partner-catalog.css',[],filemtime(PCOE_DIR.'/assets/partner-catalog.css'));});
        add_filter('woocommerce_account_menu_items',static function($items){if(self::allowed(get_current_user_id())){$logout=$items['customer-logout']??null;unset($items['customer-logout']);$items['partner-catalog']=__('Data and photos for your website','pc-order-import-export');if($logout)$items['customer-logout']=$logout;}return $items;});
        add_filter('woocommerce_get_endpoint_url',static fn($url,$endpoint)=>$endpoint==='partner-catalog'?self::url():$url,10,2);
        add_filter('woocommerce_account_menu_item_classes',static function($classes,$endpoint){if(isset($_GET['pcoe_catalog'])){$classes=array_diff($classes,['is-active']);if($endpoint==='partner-catalog')$classes[]='is-active';}return $classes;},10,2);
        add_action('template_redirect',static function(){if(is_account_page() && isset($_GET['pcoe_catalog']) && is_user_logged_in()){nocache_headers();remove_action('woocommerce_account_content','woocommerce_account_content');add_action('woocommerce_account_content',[PartnerCatalogUi::class,'render']);}},5);
        add_action('admin_post_pcoe_partner_catalog',[self::class,'post']);
        add_action('admin_post_pcoe_partner_feed',[self::class,'feed']);
        add_action('admin_post_nopriv_pcoe_partner_feed',[self::class,'feed']);
    }
    public static function url(): string {return add_query_arg('pcoe_catalog','1',wc_get_page_permalink('myaccount'));}
    public static function config(int $user): array {$d=get_user_meta($user,self::META,true);return is_array($d)?$d:[];}
    public static function key(int $user,array $config): string {return hash_hmac('sha256',$user.':'.($config['seed']??''),wp_salt('auth'));}
    public static function validKey(int $user,array $config,string $key): bool {return !empty($config['seed']) && preg_match('/^[a-f0-9]{64}$/D',$key) && hash_equals(self::key($user,$config),$key);}
    public static function feedUrl(int $user,array $c): string {return add_query_arg(['action'=>'pcoe_partner_feed','user'=>$user,'key'=>self::key($user,$c)],admin_url('admin-post.php'));}
    public static function data(int $user,array $c): array {
        if(!self::allowed($user) || empty($c['selection']))throw new \RuntimeException(__('Export access is unavailable.','pc-order-import-export'));
        $signature=hash('sha256',wp_json_encode([$c['selection'],BroadcastPricing::group($user)]));
        $cache=get_transient('pcoe_catalog_'.$user);
        if(is_array($cache) && ($cache['signature']??'')===$signature)return $cache['data'];
        $data=PartnerCatalogData::build($user,$c['selection']);
        set_transient('pcoe_catalog_'.$user,['signature'=>$signature,'data'=>$data],10*MINUTE_IN_SECONDS);
        return $data;
    }
    public static function feed(): void {
        nocache_headers();header('X-Content-Type-Options: nosniff');header('X-Robots-Tag: noindex, nofollow');header('Referrer-Policy: no-referrer');
        $user=isset($_GET['user']) && is_scalar($_GET['user'])?absint($_GET['user']):0;
        $key=isset($_GET['key']) && is_string($_GET['key'])?$_GET['key']:'';$c=self::config($user);
        if(!self::allowed($user) || empty($c['enabled']) || !self::validKey($user,$c,$key)){status_header(403);exit('Feed unavailable');}
        try{$xml=PartnerCatalogFormats::yml(self::data($user,$c));header('Content-Type: application/xml; charset=UTF-8');echo $xml;}
        catch(\Throwable $e){status_header(503);header('Retry-After: 600');header('Content-Type: text/plain; charset=UTF-8');echo 'Catalogue temporarily unavailable; retain the previous import.';}
        exit;
    }
    public static function post(): void {
        $user=get_current_user_id();if(!self::allowed($user))wp_die('Access denied','',['response'=>403]);
        check_admin_referer('pcoe_partner_catalog');$operation=is_string($_POST['operation']??null)?$_POST['operation']:'';
        global $wpdb;
        $lock='pcoe-catalog:'.substr(hash('sha256',DB_NAME.$wpdb->prefix.':'.$user),0,40);
        if((string)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,0)',$lock))!=='1')wp_die(esc_html__('Export is busy. Please retry shortly.','pc-order-import-export'),'',['response'=>423]);
        $release=static function()use($wpdb,$lock){$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));};
        register_shutdown_function($release);
        try {
            $c=self::config($user);
            if($operation==='save'){
                $input=wp_unslash($_POST);
                if(!empty($_FILES['sku_file']['name']))$input['skus']=PartnerCatalogUpload::read($_FILES['sku_file']);
                $s=PartnerCatalogData::selection($input);$data=PartnerCatalogData::build($user,$s);
                // Saving a selection does not publish a feed. Explicit enable below is required.
                $c['selection']=$s;$c['seed']=$c['seed']??bin2hex(random_bytes(32));$c['enabled']=false;
                update_user_meta($user,self::META,wp_slash($c));delete_transient('pcoe_catalog_'.$user);
                set_transient('pcoe_catalog_notice_'.$user,__('Selection saved. Review the preview before enabling the Prom link.','pc-order-import-export'),60);
            } elseif(in_array($operation,['enable','rotate','disable'],true)) {
                if($operation==='enable'){if(empty($_POST['confirmed']))throw new \RuntimeException(__('Confirm the retail prices and selection.','pc-order-import-export'));PartnerCatalogFormats::yml(self::data($user,$c));$c['enabled']=true;}
                if($operation==='disable')$c['enabled']=false;
                if($operation==='rotate')$c['seed']=bin2hex(random_bytes(32));
                update_user_meta($user,self::META,wp_slash($c));
            } elseif(in_array($operation,['xlsx','csv','woo','yml','zip'],true)) {
                $data=self::data($user,$c);
                $bytes=match($operation){'xlsx'=>PartnerCatalogFormats::xlsx($data),'csv'=>PartnerCatalogFormats::csv($data),'woo'=>PartnerCatalogFormats::csv($data,true),'yml'=>PartnerCatalogFormats::yml($data),'zip'=>PartnerCatalogPhotos::zip($data)};
                $ext=match($operation){'woo'=>'csv','yml'=>'xml',default=>$operation};
                $mime=['xlsx'=>'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet','csv'=>'text/csv; charset=UTF-8','xml'=>'application/xml; charset=UTF-8','zip'=>'application/zip'][$ext];
                nocache_headers();header('X-Content-Type-Options: nosniff');header('Content-Type: '.$mime);header('Content-Disposition: attachment; filename="catalogue.'.$ext.'"');echo $bytes;exit;
            } else throw new \RuntimeException(__('Unknown export action.','pc-order-import-export'));
        } catch(\Throwable $e){set_transient('pcoe_catalog_notice_'.$user,$e instanceof \RuntimeException?$e->getMessage():__('Could not prepare the export. Please retry or contact the manager.','pc-order-import-export'),60);}
        $release();wp_safe_redirect(self::url());exit;
    }
}
