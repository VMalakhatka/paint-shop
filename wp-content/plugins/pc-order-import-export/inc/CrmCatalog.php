<?php
namespace PaintCore\PCOE;
defined('ABSPATH') || exit;

/** Independent customer-controlled purchase feed. No business-data writes or emails. */
final class CrmCatalog {
    const META='_pcoe_crm_catalog';
    const HOOK='pcoe_crm_catalog_tick';
    const MAX_AGE=7200;
    const REFRESH=1800;
    private const OFFER_FORMAT = 3;
    public static function hooks(): void {
        add_action('admin_post_pcoe_crm_catalog',[self::class,'post']);
        add_action('admin_post_pcoe_crm_feed',[self::class,'feed']);
        add_action('admin_post_nopriv_pcoe_crm_feed',[self::class,'feed']);
        add_action(self::HOOK,[self::class,'tick']);
        add_action('deleted_user',static function($user){wp_clear_scheduled_hook(self::HOOK,[(int)$user]);CrmCatalogStore::clear((int)$user);});
    }
    public static function config(int $user): array {$c=get_user_meta($user,self::META,true);return is_array($c)?$c:[];}
    public static function save(int $user,array $c): void {
        update_user_meta($user,self::META,wp_slash($c));
        if(self::config($user)!==$c)throw new \RuntimeException('Could not save CRM settings');
    }
    public static function key(int $user,array $c): string {return hash_hmac('sha256','crm-purchase:'.$user.':'.($c['seed']??''),wp_salt('auth'));}
    public static function authorized(int $user,array $c,string $key): bool {
        return PartnerCatalog::allowed($user) && !empty($c['enabled']) && !empty($c['seed'])
            && preg_match('/^[a-f0-9]{64}$/D',$key) && hash_equals(self::key($user,$c),$key);
    }
    public static function url(int $user,array $c): string {return add_query_arg(['action'=>'pcoe_crm_feed','user'=>$user,'key'=>self::key($user,$c)],admin_url('admin-post.php'));}
    public static function schedule(int $user,int $delay=10): void {
        $next=wp_next_scheduled(self::HOOK,[$user]);
        if($next && $next<=time()+$delay)return;
        if($next)wp_unschedule_event($next,self::HOOK,[$user]);
        if(!wp_schedule_single_event(time()+$delay,self::HOOK,[$user]))throw new \RuntimeException(__('Could not schedule the CRM export. Ask the administrator to check the scheduler.','pc-order-import-export'),400);
    }
    public static function ready(int $user,array $c,array $snapshot): bool {
        return !empty($snapshot['count']) && !empty($snapshot['hashes']) && ($snapshot['revision']??'')===($c['revision']??null)
            && time()-($snapshot['at']??0)<self::MAX_AGE
            && hash_equals($snapshot['context']??'',CrmCatalogData::context($user,$c['selection']));
    }
    public static function start(int $user,array $c): array {
        return ['offer_format'=>self::OFFER_FORMAT,'generation'=>bin2hex(random_bytes(16)),'revision'=>$c['revision'],'context'=>CrmCatalogData::context($user,$c['selection']),
            'after'=>0,'scanned'=>0,'count'=>0,'unpriced'=>0,'bytes'=>0,'hashes'=>[],'categories'=>[],'preview'=>[],'started'=>time(),
            'currency'=>get_woocommerce_currency(),'status'=>'preparing','error'=>''];
    }
    public static function tick(int $user): void {
        try {
            CrmCatalogStore::lock($user,static function()use($user){
                $c=self::config($user);$b=CrmCatalogStore::get($user,'build');
                if(!PartnerCatalog::allowed($user) || empty($c['selection']))return;
                if(!$b && empty($c['enabled']))return;
                if(!empty($b['generation']) && (CrmCatalogStore::get($user,'snapshot')['generation']??'')===$b['generation']){
                    // Publication is the commit point: never overwrite committed chunks on recovery.
                    CrmCatalogStore::put($user,'build',[]);wp_clear_scheduled_hook(self::HOOK,[$user]);
                    if(!empty($c['enabled']))self::schedule($user,self::REFRESH);
                    return;
                }
                if(($b['status']??'')==='failed')return;
                if(!$b){$b=self::start($user,$c);CrmCatalogStore::put($user,'build',$b);}
                self::schedule($user,60); // Recovery for process termination before the next durable checkpoint.
                try {
                    if (($b['offer_format'] ?? 1) !== self::OFFER_FORMAT
                        || $b['revision'] !== $c['revision']
                        || $b['context'] !== CrmCatalogData::context($user, $c['selection'])) {
                        CrmCatalogStore::discard($user,$b);$b=self::start($user,$c);CrmCatalogStore::put($user,'build',$b);
                    }
                    if(time()-$b['started']>3600)throw new \RuntimeException(__('CRM preparation took too long. Prepare it again or ask the manager to check the scheduler.','pc-order-import-export'),400);
                    $deadline=microtime(true)+15;
                    do {
                        $ids=CrmCatalogData::page($c['selection'],(int)$b['after']);
                        if($ids){
                            $data=CrmCatalogData::rows($user,$ids,$c['selection']);
                            $part = CrmCatalogFormat::part($data);
                            $xml = $part['xml'];
                            $b['unpriced'] = ($b['unpriced'] ?? 0) + $part['unpriced'];
                            $index = count($b['hashes']);
                            $b['scanned']+=count($ids);$b['count']+=count($data['rows']);$b['bytes']+=strlen($xml);
                            if($b['scanned']>CrmCatalogData::MAX_PRODUCTS || $b['bytes']>30*1024*1024)throw new \RuntimeException(__('The CRM selection exceeds 30,000 records or 30 MB. Narrow the brands; no partial feed was published.','pc-order-import-export'),400);
                            CrmCatalogStore::chunk($user,$b['generation'],$index,$xml);$b['hashes'][]=hash('sha256',$xml);
                            $b['categories']=$b['categories']+$data['categories'];
                            foreach($data['rows'] as $row)if(count($b['preview'])<10)$b['preview'][]=array_intersect_key($row,array_flip(['sku','name','cost','stock']));
                            $b['after']=max($ids);
                        }
                        if(count($ids)<CrmCatalogData::BATCH){
                            if(!$b['count'])throw new \RuntimeException(__('No exportable products. Check SKUs and filters.','pc-order-import-export'),400);
                            $b['at']=time();$b['status']='ready';$old=CrmCatalogStore::get($user,'snapshot');
                            CrmCatalogStore::put($user,'snapshot',$b);CrmCatalogStore::put($user,'build',[]);
                            if(($old['generation']??'')!==$b['generation'])CrmCatalogStore::discard($user,$old);
                            wp_clear_scheduled_hook(self::HOOK,[$user]);if(!empty($c['enabled']))self::schedule($user,self::REFRESH);
                            return;
                        }
                        CrmCatalogStore::put($user,'build',$b);
                        if(function_exists('wp_cache_flush_runtime') && wp_cache_supports('flush_runtime'))wp_cache_flush_runtime();
                    }while(microtime(true)<$deadline);
                    self::schedule($user,10);
                }catch(\Throwable $e){
                    $b['status']='failed';$b['error']=$e->getCode()===400?$e->getMessage():__('Could not prepare the CRM feed. Retry or contact the manager.','pc-order-import-export');
                    CrmCatalogStore::put($user,'build',$b);wp_clear_scheduled_hook(self::HOOK,[$user]);
                }
            });
        }catch(\Throwable $e){
            // Failure before acquiring an owner lock cannot mutate that owner's state.
            if($e->getCode()===423){self::schedule($user,60);return;}
            try{CrmCatalogStore::lock($user,static function()use($user){
                $b=CrmCatalogStore::get($user,'build');
                $b['status']='failed';$b['error']=__('Could not prepare the CRM feed. Retry or contact the manager.','pc-order-import-export');
                CrmCatalogStore::put($user,'build',$b);
            });}catch(\Throwable $ignored){}
        }
    }
    public static function post(): void {
        $user=get_current_user_id();if(!PartnerCatalog::allowed($user))wp_die('Access denied','',['response'=>403]);
        check_admin_referer('pcoe_crm_catalog');$op=is_string($_POST['operation']??null)?$_POST['operation']:'';
        $download=null;
        try{CrmCatalogStore::lock($user,static function()use($user,$op,&$download){
            $c=self::config($user);
            if($op==='save'){
                $selection=CrmCatalogData::selection(wp_unslash($_POST));
                $c=['selection'=>$selection,'seed'=>$c['seed']??bin2hex(random_bytes(32)),'revision'=>bin2hex(random_bytes(16)),'enabled'=>false];
                self::save($user,$c);CrmCatalogStore::clear($user);CrmCatalogStore::put($user,'build',self::start($user,$c));self::schedule($user);
            }elseif($op==='retry'){
                if(empty($c['selection']))throw new \RuntimeException(__('Select brands and warehouse groups for the CRM feed.','pc-order-import-export'),400);
                CrmCatalogStore::discard($user,CrmCatalogStore::get($user,'build'));CrmCatalogStore::put($user,'build',self::start($user,$c));self::schedule($user);
            }elseif($op==='enable'){
                if(empty($_POST['confirmed']))throw new \RuntimeException(__('Confirm sharing your purchase prices with your CRM.','pc-order-import-export'),400);
                if(!self::ready($user,$c,CrmCatalogStore::get($user,'snapshot')))throw new \RuntimeException(__('Wait for a complete, current CRM file before enabling the link.','pc-order-import-export'),400);
                $c['enabled']=true;self::save($user,$c);self::schedule($user,self::REFRESH);
            }elseif($op==='disable'){
                $c['enabled']=false;self::save($user,$c);wp_clear_scheduled_hook(self::HOOK,[$user]);CrmCatalogStore::discard($user,CrmCatalogStore::get($user,'build'));CrmCatalogStore::put($user,'build',[]);
            }elseif($op==='rotate'){
                if(empty($c['selection']))throw new \RuntimeException('CRM not configured');$c['seed']=bin2hex(random_bytes(32));self::save($user,$c);
            }elseif($op==='download'){
                $snapshot=CrmCatalogStore::get($user,'snapshot');
                if(!self::ready($user,$c,$snapshot))throw new \RuntimeException(__('There is no current CRM file to download. Refresh the file status. If the file has expired, prepare it again.','pc-order-import-export'),400);
                $download=CrmCatalogStore::file($user,$snapshot);
            }else throw new \RuntimeException(__('Unknown export action.','pc-order-import-export'),400);
        });}catch(\Throwable $e){set_transient('pcoe_crm_notice_'.$user,$e->getCode()===400?$e->getMessage():__('Could not complete the action. Check the CRM preparation status and try again.','pc-order-import-export'),60);}
        if($download)self::send($download,true);
        wp_safe_redirect(PartnerCatalog::url().'#pcoe-crm-catalog');exit;
    }
    private static function send(string $path,bool $download=false): void {
        nocache_headers();header('Content-Type: application/xml; charset=UTF-8');header('X-Content-Type-Options: nosniff');header('X-Robots-Tag: noindex, nofollow');header('Referrer-Policy: no-referrer');
        if($download)header('Content-Disposition: attachment; filename="purchase-catalogue.xml"');
        header('Content-Length: '.filesize($path));try{readfile($path);}finally{@unlink($path);}exit;
    }
    public static function feed(): void {
        nocache_headers();header('X-Content-Type-Options: nosniff');header('X-Robots-Tag: noindex, nofollow');header('Referrer-Policy: no-referrer');
        $user=is_scalar($_GET['user']??null)?absint($_GET['user']):0;$key=is_string($_GET['key']??null)?$_GET['key']:'';
        if(!self::authorized($user,self::config($user),$key)){status_header(403);exit('Feed unavailable');}
        try{$path=CrmCatalogStore::lock($user,static function()use($user,$key){
            $c=self::config($user);if(!self::authorized($user,$c,$key))throw new \RuntimeException('Feed unavailable');
            $snapshot=CrmCatalogStore::get($user,'snapshot');
            if(!self::ready($user,$c,$snapshot))throw new \RuntimeException('CRM file unavailable');
            return CrmCatalogStore::file($user,$snapshot);
        });self::send($path);}catch(\Throwable $e){status_header(503);header('Retry-After: 600');header('Content-Type: text/plain; charset=UTF-8');echo 'Catalogue temporarily unavailable; retain the previous import.';exit;}
    }
}
