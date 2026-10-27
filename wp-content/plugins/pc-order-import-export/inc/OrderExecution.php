<?php
namespace PaintCore\PCOE;
defined('ABSPATH') || exit;

/** Internal supervision only. Never writes Folio, shipment commands, payment or Woo status. */
final class OrderExecution {
    const META = '_pcoe_execution_v1';
    const JOB = 'pcoe_execution_observe';
    const GROUP = 'pcoe-execution';
    const STORAGE_ERROR = 71001;

    public static function hooks(): void {
        add_action('admin_post_pcoe_execution', [self::class, 'post']);
        add_action(self::JOB, [self::class, 'observe']);
    }
    public static function fail(): void { throw new \RuntimeException(__('Execution data changed or is unavailable. Reload the order and try again.', 'pc-order-import-export')); }
    public static function root($order): \WC_Order {
        if (!$order instanceof \WC_Order) self::fail();
        $seen=[];
        for ($n=0;$n<10;$n++) {
            if (isset($seen[$order->get_id()])) self::fail();
            $seen[$order->get_id()]=true;
            $parent=(int)($order->get_meta('_folio_parent_order_id') ?: $order->get_meta('_folio_split_from_order_id') ?: $order->get_meta('_pcoe_execution_source_id'));
            if (!$parent) return $order;
            $p=wc_get_order($parent);
            if (!$p instanceof \WC_Order || !$order->get_customer_id() || $p->get_customer_id()!==$order->get_customer_id()
                || ($order->get_parent_id()!==$p->get_id() && !in_array($order->get_id(),array_map('intval',array_merge((array)$p->get_meta('_folio_child_order_ids'),(array)$p->get_meta('_pcoe_execution_children'))),true))) self::fail();
            $order=$p;
        }
        self::fail();
    }
    public static function state(\WC_Order $order): array {
        $value=$order->get_meta(self::META,true);
        return is_array($value)?$value:[];
    }
    public static function context(\WC_Order $order): string {
        return function_exists('pc_folio_balance_user_context') ? (string)(pc_folio_balance_user_context($order->get_customer_id(),false)['short_name']??'') : '';
    }
    public static function family(\WC_Order $root): array {
        $orders=[$root->get_id()=>$root]; $queue=[$root];
        while ($queue) {
            $parent=array_shift($queue);
            foreach (array_unique(array_map('intval',array_merge((array)$parent->get_meta('_folio_child_order_ids'),(array)$parent->get_meta('_pcoe_execution_children')))) as $id) {
                if (!$id || isset($orders[$id])) continue;
                $child=wc_get_order($id);
                if (!$child instanceof \WC_Order || $child->get_customer_id()!==$root->get_customer_id()
                    || self::root($child)->get_id()!==$root->get_id()) self::fail();
                $orders[$id]=$child; $queue[]=$child;
                if (count($orders)>50) self::fail();
            }
        }
        ksort($orders); return $orders;
    }
    /** One shared order lock; no remote calls while held. One CRUD meta write contains state + audit. */
    public static function change(int $id,string $revision,callable $callback): array {
        global $wpdb;
        $key='pcoe:'.substr(hash('sha256',$wpdb->prefix.'order:'.$id),0,48);
        if ((string)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,0)',$key))!=='1') self::fail();
        try {
            $order=wc_get_order($id); if (!$order instanceof \WC_Order || self::root($order)->get_id()!==$id) self::fail();
            $order->read_meta_data(true); $old=self::state($order);
            if (!hash_equals((string)($old['revision']??''),$revision)) self::fail();
            if ($old && ((int)$old['customer']!==$order->get_customer_id() || $old['folio']!==self::context($order))) self::fail();
            $new=$callback($old,$order); if ($new===$old) return $old;
            $new['revision']=wp_generate_uuid4();
            $order->update_meta_data(self::META,$new); $order->save_meta_data();
            $order->read_meta_data(true);
            if (self::state($order)!==$new) throw new \RuntimeException(__('Order data could not be saved. Contact an administrator; do not repeat document creation.', 'pc-order-import-export'), self::STORAGE_ERROR);
            return $new;
        } finally { $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$key)); }
    }
    public static function event(array &$state,string $type,array $details,int $actor): void {
        $state['events'][]=['time'=>gmdate('c'),'actor'=>$actor,'type'=>$type,'details'=>$details];
    }
    public static function date(string $value): string {
        if ($value==='') return '';
        $d=\DateTimeImmutable::createFromFormat('!Y-m-d\TH:i',$value,wp_timezone());
        if (!$d || $d->format('Y-m-d\TH:i')!==$value) self::fail();
        return $d->setTimezone(new \DateTimeZone('UTC'))->format('c');
    }
    public static function schedule(int $id,bool $active): void {
        if (!function_exists('as_has_scheduled_action')) return;
        if (!$active) { as_unschedule_all_actions(self::JOB,[$id],self::GROUP); return; }
        if (!as_has_scheduled_action(self::JOB,[$id],self::GROUP) && !as_schedule_recurring_action(time()+30,300,self::JOB,[$id],self::GROUP,true)) self::fail();
    }
    public static function save(int $id,string $revision,array $input): array {
        if (!current_user_can('manage_woocommerce')) self::fail();
        $root=self::root(wc_get_order($id));
        ManagerWorkspace::customer($root->get_customer_id());
        $manager=absint($input['manager']??0);
        if ($manager && !user_can($manager,'manage_woocommerce')) self::fail();
        $next=sanitize_textarea_field($input['next']??''); $reason=sanitize_textarea_field($input['reason']??'');
        if (mb_strlen($next)>500 || mb_strlen($reason)>1000 || trim($reason)==='') self::fail();
        $due=self::date((string)($input['due']??'')); $actor=get_current_user_id();
        $result=self::change($root->get_id(),$revision,static function($state,$order)use($manager,$next,$reason,$due,$actor,$input){
            $initial=!$state;
            if (!$initial && $state['manager']!==$manager && !current_user_can('manage_options')) {
                throw new \RuntimeException(__('Only an administrator can transfer responsibility for an existing execution card.', 'pc-order-import-export'));
            }
            if (!$state) $state=['customer'=>$order->get_customer_id(),'folio'=>self::context($order),'manager'=>0,'due'=>'','original_due'=>'','next'=>'','active'=>false,'links'=>[],'documents'=>[],'events'=>[],'observed'=>[]];
            $before=array_intersect_key($state,array_flip(['manager','due','next','active']));
            $state['manager']=$manager; $state['due']=$due; $state['next']=$next; $state['active']=!empty($input['active']);
            if ($due && !$state['original_due']) $state['original_due']=$due;
            self::event($state,$initial?'started':'assignment',['before'=>$before,'after'=>array_intersect_key($state,$before),'reason'=>$reason],$actor);
            return $state;
        });
        self::schedule($root->get_id(),$result['active']); return $result;
    }
    /** Creation responses use lower-case account variants; the read API uses ACCOUNT. */
    public static function source_type(string $type): string {
        $type=strtoupper(trim($type));
        if (in_array($type,['ACCOUNT','NON_ACCOUNTING_ACCOUNT','MISSING_STOCK_ACCOUNT','WAITING_TRANSFER_ACCOUNT'],true)) return 'ACCOUNT';
        return in_array($type,['EXPENSE','PAYMENT'],true)?$type:'';
    }
    public static function reference(string $type,int $id): array {
        if (!in_array($type,['ACCOUNT','EXPENSE','PAYMENT'],true) || $id<1) self::fail();
        return ['type'=>$type,'id'=>$id];
    }
    public static function document(\WC_Order $order,array $ref): array {
        if (!function_exists('pc_folio_documents_fetch_detail') || !self::context($order)) self::fail();
        $ref=self::reference($ref['type'],$ref['id']);
        $timeout=static function($args,$url) { if (strpos((string)parse_url($url,PHP_URL_PATH),'/admin/folio/customer-documents/')!==false) $args['timeout']=20; return $args; };
        add_filter('http_request_args',$timeout,99,2);
        try { $result=pc_folio_documents_fetch_detail(pc_folio_balance_user_context($order->get_customer_id(),false),$ref['type'],$ref['id'],false); }
        finally { remove_filter('http_request_args',$timeout,99); }
        if (is_wp_error($result)) self::fail();
        $d=$result['document']??[];
        if (($d['documentType']??'')!==$ref['type'] || (int)($d['documentId']??0)!==$ref['id']) self::fail();
        if (!is_numeric($d['totalAmount']??null) || !is_array($d['items']??null)) self::fail();
        foreach ($d['items'] as $item) {
            if (!is_array($item)) self::fail();
            foreach (['quantity','price','amount'] as $field) if (!is_numeric($item[$field]??null)) self::fail();
        }
        // Fixed business fields only: no changing UI labels or temporary URLs in fingerprints.
        $row=array_intersect_key($d,array_flip(['documentId','documentType','documentNumber','documentDate','warehouseId','accounted','returnDocument','totalAmount','information','additionalInfo','operationType','operationKind','allocatedAmount']));
        $row['items']=[];
        foreach ((array)($d['items']??[]) as $item) $row['items'][]=array_intersect_key($item,array_flip(['lineId','sku','quantity','price','amount']));
        return $row;
    }
    public static function link(int $id,string $revision,array $ref,string $reason): void {
        if (!current_user_can('manage_woocommerce') || trim($reason)==='' || mb_strlen($reason)>1000) self::fail();
        $root=self::root(wc_get_order($id)); ManagerWorkspace::customer($root->get_customer_id());
        $document=self::document($root,$ref); $actor=get_current_user_id();
        self::change($root->get_id(),$revision,static function($state)use($ref,$reason,$document,$actor){
            if (!$state) self::fail(); $key=$ref['type'].':'.$ref['id'];
            if (isset($state['links'][$key])) return $state;
            if (count($state['links'])>=50) self::fail();
            $state['links'][$key]=$ref;
            $state['documents'][$key]=['value'=>$document,'checked'=>gmdate('c'),'error'=>false];
            self::event($state,'document_linked',['key'=>$key,'reason'=>sanitize_textarea_field($reason),'value'=>$document],$actor);
            return $state;
        });
    }
    public static function unlink(int $id,string $revision,string $key,string $reason): void {
        if (!current_user_can('manage_woocommerce') || trim($reason)==='' || mb_strlen($reason)>1000) self::fail();
        $root=self::root(wc_get_order($id)); ManagerWorkspace::customer($root->get_customer_id()); $actor=get_current_user_id();
        self::change($root->get_id(),$revision,static function($s)use($key,$reason,$actor){
            if (!isset($s['links'][$key])) self::fail();
            unset($s['links'][$key],$s['observed']['refs'][$key]);
            self::event($s,'document_unlinked',['key'=>$key,'reason'=>sanitize_textarea_field($reason)],$actor);
            return $s;
        });
    }
    public static function snapshot(\WC_Order $root,array $state): array {
        $data=['orders'=>[],'shipments'=>[],'approvals'=>[],'refs'=>$state['links']??[]];
        foreach (self::family($root) as $id=>$order) {
            $lines=[]; foreach ($order->get_items() as $item) { $product=$item->get_product(); $lines[]=['id'=>$item->get_id(),'sku'=>$product?$product->get_sku():'','name'=>$item->get_name(),'quantity'=>$item->get_quantity(),'total'=>$item->get_total(),'tax'=>$item->get_total_tax()]; }
            $data['orders'][$id]=['status'=>$order->get_status(),'total'=>$order->get_total(),'currency'=>$order->get_currency(),'payment'=>$order->get_payment_method(),'paid'=>$order->get_date_paid()? $order->get_date_paid()->date('c') : '', 'lines'=>$lines];
            $type=self::source_type((string)$order->get_meta('_folio_document_type')); $doc=(int)$order->get_meta('_folio_document_id');
            if ($doc && in_array($type,['ACCOUNT','EXPENSE','PAYMENT'],true)) $data['refs'][$type.':'.$doc]=self::reference($type,$doc);
            $result=(array)$order->get_meta('_folio_documents_result');
            foreach ($result['documents']??[] as $entry) {
                $t=self::source_type((string)($entry['document_type']??$entry['documentType']??'')); $d=(int)($entry['document_id']??$entry['documentId']??0);
                if ($d && in_array($t,['ACCOUNT','EXPENSE','PAYMENT'],true)) $data['refs'][$t.':'.$d]=self::reference($t,$d);
            }
            $posts=get_posts(['post_type'=>CustomerApproval::TYPE,'post_status'=>'private','name'=>CustomerApproval::key(['customer_id'=>$order->get_customer_id(),'order_id'=>$id]),'numberposts'=>1]);
            if ($posts) {
                $a=json_decode($posts[0]->post_content,true);
                if ((int)$posts[0]->post_author===$root->get_customer_id() && (int)($a['source']['order_id']??0)===$id) $data['approvals'][$id]=['status'=>$a['status']??'unknown','revision'=>$a['revision']??'','confirmed_at'=>$a['confirmed_at']??''];
            }
            if (class_exists('Paint\\NovaPoshta\\Infrastructure\\ShipmentRepository')) {
                global $wpdb;
                $rows=(new \Paint\NovaPoshta\Infrastructure\ShipmentRepository())->findByOrder($id);
                if ($wpdb->last_error) throw new \RuntimeException('Shipment source unavailable');
                foreach ($rows as $row) {
                    $tracking=json_decode((string)($row['response_snapshot']??''),true)?:[];
                    $data['shipments'][(int)$row['id']]=['order'=>$id,'status'=>$row['status'],'ttn'=>$row['ttn_number']??'','source'=>$row['source']??'',
                        'tracking'=>array_intersect_key($tracking,array_flip(['code','text','checked_at']))];
                }
            }
        }
        if (count($data['refs'])>100) self::fail();
        ksort($data['refs']); ksort($data['shipments']); return $data;
    }
    /** One Folio read per tick; no external mutations. Last verified data survives a failed read. */
    public static function observe(int $id): void {
        $root=wc_get_order($id); if (!$root instanceof \WC_Order) return;
        $state=self::state($root); if (!$state || empty($state['active'])) return;
        $revision=$state['revision']; $time=gmdate('c');
        try {
            if (self::root($root)->get_id()!==$id || $state['customer']!==$root->get_customer_id() || $state['folio']!==self::context($root)) { self::schedule($id,false); return; }
            $snapshot=self::snapshot($root,$state); $refs=$snapshot['refs']; $key=''; $document=null; $doc_error=false;
            if ($refs) {
                $keys=array_keys($refs); $key=$keys[((int)($state['cursor']??0))%count($keys)];
                try { $document=self::document($root,$refs[$key]); } catch (\Throwable $e) { $doc_error=true; }
            }
            self::change($id,$revision,static function($s)use($snapshot,$key,$document,$doc_error,$time){
                $before=$s['observed']??[];
                if ($before!==$snapshot) self::event($s,'sources_changed',['before'=>$before,'after'=>$snapshot],0);
                $s['observed']=$snapshot; $s['checked']=$time; $s['source_error']=false; $s['storage_error']=false;
                if ($key) {
                    $previous=$s['documents'][$key]??[];
                    if ($doc_error) {
                        if (empty($previous['error'])) self::event($s,'document_unavailable',['key'=>$key],0);
                        $s['documents'][$key]=$previous+['value'=>null]; $s['documents'][$key]['error']=true;
                        $s['documents'][$key]['attempted']=$time;
                    } else {
                        if (($previous['value']??null)!==$document || !empty($previous['error'])) self::event($s,'document_observed',['key'=>$key,'before'=>$previous['value']??null,'after'=>$document],0);
                        $s['documents'][$key]=['value'=>$document,'checked'=>$time,'error'=>false];
                    }
                    $s['cursor']=((int)($s['cursor']??0))+1;
                }
                return $s;
            });
        } catch (\Throwable $e) {
            // Do not apply an observation to a concurrent edit or changed customer mapping.
            $storage=$e->getCode()===self::STORAGE_ERROR;
            try { self::change($id,$revision,static function($s)use($time,$storage){
                $flag=$storage?'storage_error':'source_error';
                if (empty($s[$flag])) self::event($s,$storage?'storage_unavailable':'source_unavailable',[],0);
                $s[$flag]=true; $s['attempted']=$time; return $s;
            }); } catch (\Throwable $ignored) {}
        }
    }
    public static function post(): void {
        check_admin_referer('pcoe_execution');
        if (!current_user_can('manage_woocommerce')) self::fail();
        try {
            $id=absint($_POST['order_id']??0); $root=self::root(wc_get_order($id)); ManagerWorkspace::customer($root->get_customer_id());
            $input=wp_unslash($_POST); $revision=(string)($input['revision']??'');
            if (($input['operation']??'')==='link') self::link($root->get_id(),$revision,self::reference((string)($input['type']??''),absint($input['document_id']??0)),(string)($input['reason']??''));
            elseif (($input['operation']??'')==='unlink') self::unlink($root->get_id(),$revision,(string)($input['key']??''),(string)($input['reason']??''));
            elseif (($input['operation']??'')==='refresh') { if (!hash_equals((string)(self::state($root)['revision']??''),$revision)) self::fail(); self::observe($root->get_id()); }
            elseif (($input['operation']??'')==='save') self::save($id,$revision,$input);
            else self::fail();
            wp_safe_redirect(ManagerWorkspace::url($root->get_customer_id(),$root->get_id()).'#pcoe-execution'); exit;
        } catch (\Throwable $e) { wp_die(esc_html($e instanceof \RuntimeException?$e->getMessage():__('Execution data changed or is unavailable. Reload the order and try again.','pc-order-import-export')),'',['response'=>400,'back_link'=>true]); }
    }
}
