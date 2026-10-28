<?php
namespace PaintCore\PCOE;
defined('ABSPATH') || exit;

/** Explicit manager command. Queue retries never resend an uncertain Folio write. */
final class ManagerQuickOrder {
    const META='_pcoe_quick_order';
    const HISTORY='_pcoe_quick_order_history';
    const JOB='pcoe_quick_order_step';
    const GROUP='pcoe-quick-orders';
    public static function hooks(): void {
        add_action(self::JOB,[self::class,'run']);
        add_action('woocommerce_order_details_before_order_table',[ManagerDraftOrders::class,'customer_links'],8);
        foreach (['customer_processing_order','customer_on_hold_order'] as $type)
            add_filter('woocommerce_email_enabled_'.$type,[self::class,'standard_email'],20,2);
    }
    /** Initial status emails would bypass the manager's explicit confirmation-email choice. */
    public static function standard_email($enabled,$order): bool {
        if (!$order instanceof \WC_Order) return (bool)$enabled;
        if ($order->get_meta(ManagerDraftOrders::CHILD_KEY)) return false;
        $work=$order;
        if ($order->get_parent_id()) {
            $parent=wc_get_order($order->get_parent_id());
            if ($parent && $parent->get_customer_id()===$order->get_customer_id()) $work=$parent;
        }
        return $work->get_meta('_pcoe_execution_source_id') ? false : (bool)$enabled;
    }
    private static function fail(): void { throw new \RuntimeException(__('The operation needs review. Do not submit it again. Check the saved Folio documents and operation ID.','pc-order-import-export')); }
    public static function save(\WC_Order $order,array $job): void {
        $job['updated_at']=gmdate('c'); $order->update_meta_data(self::META,$job); $order->save_meta_data();
        ManagerWorkspace::require_saved_meta($order,self::META,$job);
    }
    private static function enqueue(int $id): void {
        if (!function_exists('as_enqueue_async_action') || !as_enqueue_async_action(self::JOB,[$id],self::GROUP)) self::fail();
    }
    public static function eligible(\WC_Order $order): bool {
        if (!$order->has_status('pc-draft') || $order->get_parent_id() || $order->get_meta('_pcoe_execution_source_id')) return false;
        return ManagerWorkspace::editable($order) || ManagerOrderFlow::non_accounting_only($order);
    }
    /** The create journal is durably saved before any mutating Folio POST. Never clear that journal. */
    public static function can_return_to_settings(\WC_Order $order): bool {
        $job=$order->get_meta(self::META,true);
        if (is_array($job) && ($job['flow']??'')===ManagerDraftOrders::FLOW) {
            return ($job['status']??'')==='review' && ($job['step']??'')==='preflight'
                && empty($job['write_started_at']) && empty($job['response']) && empty($job['children']) && empty($job['slots'])
                && empty($job['orders']) && empty($job['approvals']) && $order->has_status('pc-draft')
                && !$order->get_parent_id() && !$order->get_meta('_pcoe_execution_source_id');
        }
        if (!is_array($job) || ($job['status']??'')!=='review' || ($job['step']??'')!=='documents'
            || empty($job['work_order']) || !empty($job['orders']) || !empty($job['approvals'])
            || !$order->has_status('pc-draft') || $order->get_parent_id() || $order->get_meta('_pcoe_execution_source_id')) return false;
        $work=wc_get_order($job['work_order']);
        if (!$work || $work->get_customer_id()!==$order->get_customer_id()
            || (int)$work->get_meta('_pcoe_execution_source_id')!==$order->get_id()) return false;
        $work->read_meta_data(true);
        // Trashing an untouched preparation copy must not strand its source draft.
        // Check the operation journal independently of editability; never restore the copy itself.
        return $work->has_status(['pc-draft','trash']) && !$work->get_parent_id()
            && !$work->get_meta(self::META) && !pc_folio_order_has_saved_documents($work)
            && !$work->get_meta('_folio_auto_status')
            && !$work->get_meta('_folio_child_order_ids') && !$work->get_meta('_folio_create_attempted_at')
            && !$work->get_meta('_folio_document_payload_hash') && !$work->get_meta('_folio_split_status');
    }
    /** Explicit manager action, under the same source-then-work locks as the worker. No API call or enqueue. */
    public static function return_to_settings(\WC_Order $order,\WP_User $user,array $input): array {
        if (!current_user_can('manage_woocommerce') || $order->get_customer_id()!==$user->ID) self::fail();
        return ManagerWorkspace::lock('order:'.$order->get_id(),static function()use($order,$user,$input){
            $order=ManagerWorkspace::order($order->get_id(),$user->ID);$order->read_meta_data(true);
            $job=$order->get_meta(self::META,true);
            if (!self::can_return_to_settings($order) || !hash_equals((string)$job['key'],(string)($input['operation_key']??''))
                || !hash_equals(ManagerWorkspace::revision($order),(string)($input['revision']??''))) self::fail();
            $locked_id=(int)($job['work_order']?:$order->get_id());
            $result=ManagerWorkspace::lock('order:'.$locked_id,static function()use($order,$user,$job,$locked_id){
                return pc_folio_order_locked($locked_id,static function()use($order,$user,$job){
                    if (!self::can_return_to_settings($order)) self::fail();
                    $history=$order->get_meta(self::HISTORY,true);$history=is_array($history)?$history:[];
                    $history[$job['key']]=$job+['returned_by'=>get_current_user_id(),'returned_at'=>gmdate('c')];
                    $order->update_meta_data(self::HISTORY,$history);$order->save_meta_data();
                    ManagerWorkspace::require_saved_meta($order,self::HISTORY,$history);
                    $order->delete_meta_data(self::META);$order->save_meta_data();
                    ManagerWorkspace::require_saved_meta($order,self::META,'');
                    return ['url'=>ManagerWorkspace::url($user->ID,$order->get_id()).'#pcoe-place-order'];
                });
            });
            if (empty($result['url'])) self::fail();
            return $result;
        });
    }
    public static function start(\WC_Order $order,\WP_User $user,array $input): array {
        if (!current_user_can('manage_woocommerce') || $order->get_customer_id()!==$user->ID) self::fail();
        $old=$order->get_meta(self::META,true);
        if ($old) return ['url'=>ManagerWorkspace::url($user->ID,$order->get_id()).'#pcoe-quick-order'];
        if (!self::eligible($order) || !hash_equals(ManagerWorkspace::revision($order),(string)($input['revision']??''))) self::fail();
        // Existing PRD must be reconciled first: silently retaining it would double-count preorder obligations.
        if (DraftPurpose::current($order)==='preorder' && pc_folio_order_has_saved_documents($order)) throw new \RuntimeException(__('Reconcile the existing preorder account before automatic conversion.','pc-order-import-export'));
        if (!function_exists('as_enqueue_async_action')) self::fail();
        $preference=self::preferences($input);
        $pool=$preference['manager_pool'];
        if (!empty($input['send_confirmation']) && !is_email($user->user_email)) self::fail();
        $job=['key'=>wp_generate_uuid4(),'flow'=>ManagerDraftOrders::FLOW,'status'=>'queued','actor'=>get_current_user_id(),'customer'=>$user->ID,
            'folio'=>OrderExecution::context($order),'revision'=>ManagerWorkspace::revision($order),'pool'=>$pool,
            'preference'=>$preference,'send'=>!empty($input['send_confirmation']),'work_order'=>0,'orders'=>[],'approvals'=>[],'cursor'=>0,'started_at'=>gmdate('c')];
        if (!$job['folio']) self::fail();
        self::save($order,$job); self::enqueue($order->get_id());
        return ['url'=>ManagerWorkspace::url($user->ID,$order->get_id()).'#pcoe-quick-order'];
    }
    public static function run(int $id): void {
        global $wpdb;
        $lock='pcoe:'.substr(hash('sha256',$wpdb->prefix.'order:'.$id),0,48);
        if ((string)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,0)',$lock))!=='1') { as_schedule_single_action(time()+30,self::JOB,[$id],self::GROUP); return; }
        $actor=get_current_user_id(); $source=null; $job=[];
        try {
            $source=wc_get_order($id); if (!$source) return;
            $source->read_meta_data(true); $job=$source->get_meta(self::META,true);
            if (!is_array($job) || !in_array($job['status'],['queued','approvals'],true)) return;
            if (!user_can($job['actor'],'manage_woocommerce') || $source->get_customer_id()!==$job['customer'] || OrderExecution::context($source)!==$job['folio']) self::fail();
            wp_set_current_user($job['actor']); $user=ManagerWorkspace::customer($job['customer']);
            if ($job['status']==='queued') {
                if (!hash_equals($job['revision'],ManagerWorkspace::revision($source))) self::fail();
                if (($job['flow']??'')===ManagerDraftOrders::FLOW) {
                    ManagerDraftOrders::create($source,$user,$job);
                    $job['status']=$job['send'] && $job['orders']?'approvals':'complete';
                    self::save($source,$job);
                    if ($job['status']==='approvals') self::enqueue($id);
                    return;
                }
                $job['status']='running'; $job['step']='copy'; self::save($source,$job);
                $work=ManagerWorkspace::quick_copy($source,$user,$job['key']);
                $job['work_order']=$work->get_id(); self::save($source,$job);
                $work->update_meta_data('_pcoe_execution_source_id',(string)$id); $work->save_meta_data();
                $children=array_values(array_filter(array_unique(array_merge(array_map('intval',(array)$source->get_meta('_pcoe_execution_children')),[$work->get_id()]))));
                $source->update_meta_data('_pcoe_execution_children',$children); $source->save_meta_data();
                ManagerWorkspace::require_saved_meta($work,'_pcoe_execution_source_id',(string)$id);
                ManagerWorkspace::require_saved_meta($source,'_pcoe_execution_children',$children);
                $job['step']='documents'; self::save($source,$job);
                ManagerWorkspace::quick_create($work,$user,$job['pool'],$job['preference']??['mode'=>'auto','term_id'=>0]);
                $work=wc_get_order($work->get_id()); $work->read_meta_data(true);
                $result=pc_folio_get_order_documents_result($work); $accounted=[];
                foreach ($result['documents']??[] as $doc) if (($doc['accounting_enabled']??null)===true) $accounted[(int)$doc['document_id']]=true;
                $ids=array_values(array_filter(array_map('intval',(array)$work->get_meta('_folio_child_order_ids'))));
                if (!$ids) $ids=[$work->get_id()];
                foreach ($ids as $child_id) {
                    $child=ManagerWorkspace::order($child_id,$user->ID);
                    if (isset($accounted[(int)$child->get_meta('_folio_document_id')])) $job['orders'][]=$child_id;
                }
                $job['status']=$job['send'] && $job['orders']?'approvals':'complete';
                self::save($source,$job);
                if ($job['status']==='approvals') self::enqueue($id);
                return;
            }
            // Each mail is its own persisted step. Document creation is never entered again.
            $order_id=(int)($job['orders'][$job['cursor']]??0);
            if (!$order_id) { $job['status']='complete'; self::save($source,$job); return; }
            $order=ManagerWorkspace::order($order_id,$user->ID);
            if (OrderExecution::root($order)->get_id()!==$id) self::fail();
            $notice=$job['approvals'][$order_id]??['request'=>wp_generate_uuid4(),'status'=>'preparing'];
            $job['approvals'][$order_id]=$notice; self::save($source,$job);
            try {
                $approval=CustomerApproval::request_order($order_id); $data=ApprovalNotifications::data($approval);
                $notice['approval']=$approval; $notice['status']='sending';
                $job['approvals'][$order_id]=$notice; self::save($source,$job);
                $notice['status']=ApprovalNotifications::send_customer($approval,$data['revision'],$notice['request']);
            } catch (\Throwable $e) { $notice['status']='review'; $notice['error']=mb_substr(wp_strip_all_tags($e->getMessage()),0,600); }
            $job['approvals'][$order_id]=$notice; $job['cursor']++;
            if ($job['cursor']>=count($job['orders'])) $job['status']='complete';
            self::save($source,$job);
            if ($job['status']==='approvals') self::enqueue($id);
        } catch (\Throwable $e) {
            if ($source && $job) { $job['status']='review'; $job['error']=mb_substr(wp_strip_all_tags($e->getMessage()),0,600); try { self::save($source,$job); } catch (\Throwable $ignored) {} }
        } finally { wp_set_current_user($actor); $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock)); }
    }
    public static function locations(array $preference): array {
        $terms=get_terms(['taxonomy'=>'location','hide_empty'=>false]);
        if (is_wp_error($terms)) self::fail();
        $locations=[];
        foreach ($terms as $term) {
            $warehouses=pc_folio_get_location_warehouses_for_preview($term->term_id);
            if ($warehouses) $locations[$term->term_id]=['term'=>$term,'warehouses'=>$warehouses];
        }
        if (function_exists('slu_order_location_stocks_by_global_priority')) $locations=slu_order_location_stocks_by_global_priority($locations);
        if ($preference['mode']!=='auto') {
            $tid=$preference['term_id'];
            if (!isset($locations[$tid])) self::fail();
            $locations=$preference['mode']==='single'?[$tid=>$locations[$tid]]:[$tid=>$locations[$tid]]+$locations;
        }
        return $locations;
    }
    /** Target follows the existing allocation preference; it is never a separate override. */
    public static function preferences(array $input): array {
        $preference=['mode'=>sanitize_key($input['warehouse_mode']??'auto'),'term_id'=>absint($input['warehouse_id']??0)];
        if (!in_array($preference['mode'],['auto','manual','single'],true)) self::fail();
        if ($preference['mode']==='auto') $preference['term_id']=0;
        $locations=self::locations($preference);
        $first=reset($locations); $target=(int)($first['warehouses'][0]['id']??0);
        $ids=array_values(array_unique(array_filter(array_map('absint',(array)($input['auxiliary']??[])))));
        $pool=[];
        if ($ids) {
            $known=array_map('intval',array_column(BroadcastSources::request('/warehouses')['warehouses'],'code'));
            if (count($ids)>20 || !$target || in_array($target,$ids,true) || array_diff(array_merge([$target],$ids),$known)) self::fail();
            $pool=['targetWarehouseId'=>$target,'auxiliaryWarehouseIds'=>$ids];
        }
        $preference['manager_pool']=$pool;
        return $preference;
    }
    /** Java allocates current free stock in the selected mode, including a separate waiting account. */
    public static function pool_payload(array $payload,array $pool,array $locations,array $preference): array {
        if (!current_user_can('manage_woocommerce')) self::fail();
        $locations=self::locations($preference);
        $target=(int)($pool['targetWarehouseId']??0); $donors=array_map('intval',$pool['auxiliaryWarehouseIds']??[]);
        $first=reset($locations);
        if (!$target || !$donors || in_array($target,$donors,true) || (int)($first['warehouses'][0]['id']??0)!==$target) self::fail();
        $map=[]; $candidates=[]; $priority=0;
        foreach ($locations as $tid=>$location) foreach ($location['warehouses'] as $w) {
            $wid=(int)$w['id']; if (in_array($wid,$donors,true)) continue;
            if (isset($map[$wid]) && $map[$wid]!==$tid) self::fail();
            $map[$wid]=(int)$tid; $candidates[$wid]=['id'=>(string)$wid,'priority'=>(int)(++$priority)];
        }
        if (!isset($map[$target])) self::fail();
        $payload['manager_stock_pool']=$pool; $payload['manager_warehouse_locations']=$map;
        foreach ($payload['items'] as &$line) $line['allocations']=[['woo_location_id'=>0,'woo_location_slug'=>'manager-pool',
            'woo_location_name'=>'Manager stock pool','quantity'=>$line['quantity'],'allocation_source'=>'pcoe_manager_pool','folio_warehouses'=>array_values($candidates)]];
        unset($line); return $payload;
    }
    public static function preview(\WC_Order $order,\WP_User $user,array $input): array {
        if (!current_user_can('manage_woocommerce') || $order->get_customer_id()!==$user->ID || !self::eligible($order)
            || $order->get_meta(self::META) || !hash_equals(ManagerWorkspace::revision($order),(string)($input['revision']??''))) self::fail();
        if (DraftPurpose::current($order)==='preorder' && pc_folio_order_has_saved_documents($order)) self::fail();
        $preference=self::preferences($input);
        $response=ManagerWorkspace::send(ManagerWorkspace::payload($order,$user,'accounts',$preference,wp_generate_uuid4()),true);
        ob_start(); ManagerWorkspace::documents_table($response); $html=ob_get_clean();
        return ['quick_preview_html'=>$html,'message'=>__('Preview only. Creation checks prices and stock again.','pc-order-import-export')];
    }
    public static function render(\WC_Order $order): void {
        if (!current_user_can('manage_woocommerce')) return;
        $job=$order->get_meta(self::META,true);
        if (!$job && !self::eligible($order)) return;
        echo '<section id="pcoe-place-order" class="pcoe-card"><h2>'.esc_html__('Place customer order from this draft','pc-order-import-export').'</h2><span id="pcoe-quick-order"></span>';
        $history=$order->get_meta(self::HISTORY,true);
        if (is_array($history) && $history) {
            echo '<details><summary>'.esc_html__('Previous stopped attempts','pc-order-import-export').'</summary>';
            foreach ($history as $attempt) echo '<p>'.esc_html(sprintf(__('Operation ID: %s','pc-order-import-export'),$attempt['key'])).' · '.esc_html($attempt['error']??'').'</p>';
            echo '</details>';
        }
        if ($job) {
            $settings_available=self::can_return_to_settings($order);
            $labels=['queued'=>__('Queued','pc-order-import-export'),'running'=>__('Creating orders; do not submit again','pc-order-import-export'),'approvals'=>__('Sending confirmation requests','pc-order-import-export'),'complete'=>__('Order preparation finished','pc-order-import-export'),'review'=>__('Operation needs review','pc-order-import-export')];
            $stale=$job['status']==='running' && strtotime($job['updated_at'])<time()-15*MINUTE_IN_SECONDS;
            echo '<p><strong>'.esc_html($stale?$labels['review']:($labels[$job['status']]??$labels['review'])).'</strong></p>';
            if ($stale) echo '<p>'.esc_html__('The operation needs review. Do not submit it again. Check the saved Folio documents and operation ID.','pc-order-import-export').'</p>';
            if (!$stale && in_array($job['status'],['queued','running','approvals'],true)) echo '<p>'.esc_html__('Processing continues in the background. Refresh this page to see the result.','pc-order-import-export').'</p>';
            if (!empty($job['work_order']) && !$settings_available) echo '<p><a class="button" href="'.esc_url(ManagerWorkspace::url($order->get_customer_id(),$job['work_order'])).'">'.esc_html__('Open created orders and Folio documents','pc-order-import-export').'</a></p>';
            if (($job['flow']??'')===ManagerDraftOrders::FLOW) ManagerDraftOrders::render($order,$job);
            echo '<p>'.esc_html(sprintf(__('Operation ID: %s','pc-order-import-export'),$job['key'])).'</p>';
            if (!empty($job['error'])) echo '<p>'.esc_html($job['error']).'</p>';
            if ($settings_available) {
                echo '<p>'.esc_html__('The preliminary check stopped before document creation. Return to settings to select warehouses again. This does not create orders, reserve goods or send emails. The stopped attempt stays in history.','pc-order-import-export').'</p><form data-pcoe-manager>';
                foreach (['operation'=>'quick_settings','customer_id'=>$order->get_customer_id(),'order_id'=>$order->get_id(),'operation_key'=>$job['key'],'revision'=>ManagerWorkspace::revision($order)] as $k=>$v) echo '<input type="hidden" name="'.esc_attr($k).'" value="'.esc_attr($v).'">';
                echo '<button type="submit" class="button">'.esc_html__('Return to warehouse settings','pc-order-import-export').'</button></form>';
            }
            if (empty($job['send'])) echo '<p>'.esc_html__('Confirmation email was not selected.','pc-order-import-export').'</p>';
            foreach ($job['approvals']??[] as $id=>$notice) {
                echo '<p>#'.esc_html($id).' · '.esc_html(ApprovalNotifications::label($notice['status']??''));
                if (!empty($notice['approval'])) echo ' <a href="'.esc_url(CustomerApproval::url(['approval_id'=>$notice['approval']])).'">'.esc_html__('Customer confirmation','pc-order-import-export').'</a>';
                echo '</p>';
                if (!empty($notice['error'])) echo '<p>'.esc_html($notice['error']).'</p>';
            }
            echo '</section>'; return;
        }
        if (DraftPurpose::current($order)==='preorder' && pc_folio_order_has_saved_documents($order)) {
            echo '<p class="pcoe-warning">'.esc_html__('Reconcile the existing preorder account before automatic conversion.','pc-order-import-export').'</p></section>';
            return;
        }
        echo '<p>'.esc_html__('One action creates linked Woo orders and Folio accounts by warehouse, plus a separate non-accounting shortage document. The source draft remains in history.','pc-order-import-export').'</p>';
        echo '<p><a href="'.esc_url(add_query_arg(['page'=>'pcoe-customers','view'=>'help'],admin_url('admin.php')).'#quick-order').'" target="_blank">'.esc_html__('How it works','pc-order-import-export').'</a></p>';
        echo '<form data-pcoe-manager>';
        foreach (['operation'=>'quick_order','customer_id'=>$order->get_customer_id(),'order_id'=>$order->get_id(),'revision'=>ManagerWorkspace::revision($order)] as $k=>$v) echo '<input type="hidden" name="'.esc_attr($k).'" value="'.esc_attr($v).'">';
        echo '<div class="pcoe-actions"><label>'.esc_html__('Warehouse mode','pc-order-import-export').'<select name="warehouse_mode"><option value="auto">'.esc_html__('Automatic allocation','pc-order-import-export').'</option><option value="manual">'.esc_html__('Prioritize selected warehouse','pc-order-import-export').'</option><option value="single">'.esc_html__('Selected warehouse only','pc-order-import-export').'</option></select></label>';
        echo '<label>'.esc_html__('Website warehouse group','pc-order-import-export').'<select name="warehouse_id" disabled><option value="">—</option>';
        $locations=self::locations(['mode'=>'auto','term_id'=>0]); $first=reset($locations);
        foreach ($locations as $tid=>$location) echo '<option value="'.esc_attr($tid).'" data-primary="'.esc_attr($location['warehouses'][0]['id']).'" data-primary-label="'.esc_attr(pc_folio_warehouse_label($location['warehouses'][0]['id'])).'">'.esc_html($location['term']->name).'</option>';
        echo '</select></label></div>';
        echo '<p>'.esc_html__('Primary warehouse','pc-order-import-export').': <strong data-current-primary>'.esc_html(pc_folio_warehouse_label($first['warehouses'][0]['id']??0)).'</strong></p>';
        echo '<details data-default-primary="'.esc_attr($first['warehouses'][0]['id']??0).'" data-default-primary-label="'.esc_attr(pc_folio_warehouse_label($first['warehouses'][0]['id']??0)).'"><summary>'.esc_html__('Count additional warehouses without moving goods','pc-order-import-export').'</summary>';
        try {
            $warehouses=get_transient('pcoe_quick_warehouses');
            if (!is_array($warehouses)) { $warehouses=BroadcastSources::request('/warehouses')['warehouses']; set_transient('pcoe_quick_warehouses',$warehouses,3*MINUTE_IN_SECONDS); }
            echo '<p>'.esc_html__('Additional stock is assigned to the first warehouse in the selected allocation order. Quantities requiring a transfer receive a separate accounting account and Woo order awaiting transfer.','pc-order-import-export').'</p>';
            foreach ($warehouses as $w) echo '<label style="display:inline-block;margin:8px"><input type="checkbox" name="auxiliary[]" value="'.esc_attr($w['code']).'"> '.esc_html($w['name']).'</label>';
            echo '<p>'.esc_html__('Accounts reserve the target warehouse. Goods remain on the additional warehouses; their transfer must be completed before picking.','pc-order-import-export').'</p>';
        } catch (\Throwable $e) { echo '<p>'.esc_html__('Additional warehouses are unavailable. Standard stock allocation remains available.','pc-order-import-export').'</p>'; }
        echo '</details><p><label><input type="checkbox" name="send_confirmation" value="1" checked> '.esc_html__('Send the customer a confirmation email','pc-order-import-export').'</label></p>';
        echo '<div class="pcoe-actions"><button type="submit" class="button" data-quick-preview>'.esc_html__('Preview customer order and available stock','pc-order-import-export').'</button><button type="submit" class="button button-primary">'.esc_html__('Create orders','pc-order-import-export').'</button></div></form><div data-quick-preview-result hidden></div>';
        if (ManagerWorkspace::editable($order)) require __DIR__.'/manager-non-accounting.php';
        echo '</section>';
    }
}
