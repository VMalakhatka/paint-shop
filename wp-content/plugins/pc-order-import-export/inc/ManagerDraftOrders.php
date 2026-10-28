<?php
namespace PaintCore\PCOE;
defined('ABSPATH') || exit;

/** Materialize real documents as direct children; the source draft is never a work copy. */
final class ManagerDraftOrders {
    const FLOW = 'direct_children';
    const CHILD_KEY = '_pcoe_direct_order_key';

    public static function active(\WC_Order $source): bool {
        $job=$source->get_meta(ManagerQuickOrder::META,true);
        return is_array($job) && ($job['flow']??'')===self::FLOW;
    }

    /** Called under the source lock. The write marker is verified before the only Folio create POST. */
    public static function create(\WC_Order $source,\WP_User $user,array &$job): void {
        if (!empty($job['write_started_at']) || !empty($job['children']) || !empty($job['slots'])) self::fail();
        $job['status']='running';$job['step']='preflight';ManagerQuickOrder::save($source,$job);
        $payload=ManagerWorkspace::payload($source,$user,'accounts',$job['preference'],$job['key']);
        ManagerWorkspace::send($payload,true);
        $fresh=wc_get_order($source->get_id());$fresh->read_meta_data(true);
        if (!hash_equals($job['revision'],ManagerWorkspace::revision($fresh)) || OrderExecution::context($fresh)!==$job['folio']
            || wp_json_encode($payload)!==wp_json_encode(ManagerWorkspace::payload($fresh,$user,'accounts',$job['preference'],$job['key']))) self::fail();
        $job['payload']=$payload;$job['step']='documents';$job['write_started_at']=gmdate('c');
        ManagerQuickOrder::save($source,$job);
        $response=ManagerWorkspace::send($payload,false,static function(array $observed)use($source,&$job):void{
            $job['observed_response']=$observed;ManagerQuickOrder::save($source,$job);
        });
        $job['response']=$response;$job['step']='children';ManagerQuickOrder::save($source,$job);
        $seen=[];
        foreach($response['documents'] as $document) {
            $id=(int)$document['document_id'];
            if (!$id || isset($seen[$id])) self::fail();
            $seen[$id]=true;
        }
        foreach($response['documents'] as $document) self::child($source,$document,$job);
    }

    private static function fail(): void {
        throw new \RuntimeException(__('The operation needs review. Do not submit it again. Check the saved Folio documents and operation ID.','pc-order-import-export'));
    }

    private static function child(\WC_Order $source,array $document,array &$job): void {
        $document_id=(int)$document['document_id'];
        // A slot without a known ID is an unknown local save, never permission to create another order.
        if (isset($job['slots'][$document_id]) || isset($job['children'][$document_id])) self::fail();
        $job['slots'][$document_id]='creating';ManagerQuickOrder::save($source,$job);
        $child=new \WC_Order();
        $child->set_created_via('pcoe-manager-order');$child->set_parent_id($source->get_id());
        pc_folio_copy_order_customer_data($source,$child);
        $child->set_status('pending');
        $child->update_meta_data(self::CHILD_KEY,$job['key']);
        $child->update_meta_data('_folio_split_from_order_id',$source->get_id());
        $child->update_meta_data('_folio_parent_order_id',$source->get_id());
        $reserved=$document['accounting_enabled']===true;
        $waiting=$reserved && pc_folio_is_waiting_transfer_document($document);
        $child->update_meta_data('_folio_split_document_kind',$reserved?($waiting?'awaiting_transfer':'account'):'missing_stock');
        if (!$reserved) $child->update_meta_data(DraftPurpose::META,'draft');
        $child->update_meta_data('_pc_draft_title',sprintf(
            $reserved?($waiting?__('Awaiting transfer from draft #%s','pc-order-import-export'):__('Order from draft #%s','pc-order-import-export')):__('Shortage from draft #%s','pc-order-import-export'),
            $source->get_order_number()));
        // Prepare the link before the first save, so even a partial local failure is attributable.
        $link=pc_folio_get_single_document_link($document);
        foreach(pc_folio_order_link_meta_keys() as $field=>$key) if(isset($link[$field]) && is_scalar($link[$field])) $child->update_meta_data($key,trim((string)$link[$field]));
        foreach($document['items'] as $line) pc_folio_create_child_order_item($source,$child,$line);
        try {
            $child->calculate_totals(false);
            $child->save();
        } finally {
            if ($child->get_id()) {
                $job['children'][$document_id]=$child->get_id();ManagerQuickOrder::save($source,$job);
                $ids=array_values(array_unique(array_filter(array_merge(array_map('intval',(array)$source->get_meta('_pcoe_execution_children')),[$child->get_id()]))));
                $source->update_meta_data('_pcoe_execution_children',$ids);$source->save_meta_data();
                ManagerWorkspace::require_saved_meta($source,'_pcoe_execution_children',$ids);
            }
        }
        if (!$child->get_id()) self::fail();
        ManagerWorkspace::require_saved_meta($child,self::CHILD_KEY,$job['key']);
        $fresh=wc_get_order($child->get_id());
        if (!$fresh || $fresh->get_parent_id()!==$source->get_id() || $fresh->get_customer_id()!==$source->get_customer_id()
            || (int)$fresh->get_meta('_folio_document_id')!==$document_id || count($fresh->get_items())!==count($document['items'])) self::fail();
        pc_folio_set_order_customer_notice($child,pc_folio_build_child_order_notice($document));
        $status=$reserved?($waiting?'on-hold':'processing'):'pc-draft';
        $child->update_status($status,__('Created from the manager workspace.','pc-order-import-export'));
        $child->save();
        $fresh=wc_get_order($child->get_id());
        if (!$fresh || !$fresh->has_status($status) || abs((float)$fresh->get_total()-array_sum(array_column($document['items'],'amount')))>0.02) self::fail();
        $job['slots'][$document_id]='complete';
        if ($reserved) $job['orders'][]=$child->get_id();
        ManagerQuickOrder::save($source,$job);
    }

    /** Only real child orders are shown here; technical attempts stay in the source journal. */
    public static function render(\WC_Order $source,array $job,bool $customer=false): void {
        $documents=[];foreach($job['response']['documents']??[] as $doc)$documents[(int)$doc['document_id']]=$doc;
        if (!$documents) return;
        echo '<h3>'.esc_html__('Orders created from this draft','pc-order-import-export').'</h3>';
        foreach($documents as $id=>$doc) {
            $child=wc_get_order((int)($job['children'][$id]??0));
            $linked=$child && $child->get_customer_id()===$source->get_customer_id() && $child->get_parent_id()===$source->get_id();
            if ($customer && (!$linked || $child->has_status('trash'))) continue;
            $label=$doc['accounting_enabled']?(pc_folio_is_waiting_transfer_document($doc)?__('Awaiting transfer — not ready for picking','pc-order-import-export'):__('Reserved stock','pc-order-import-export')):__('Shortage — not ready for assembly','pc-order-import-export');
            echo '<p><strong>'.esc_html($label).'</strong> · '.esc_html(pc_folio_warehouse_label($doc['folio_warehouse_id'])).' · '.esc_html(sprintf(__('Folio account #%s','pc-order-import-export'),$doc['document_number']));
            if($linked) {
                echo ' · <a href="'.esc_url($customer?$child->get_view_order_url():ManagerWorkspace::url($source->get_customer_id(),$child->get_id())).'">#'.esc_html($child->get_order_number()).'</a> · '.esc_html(wc_get_order_status_name($child->get_status()));
            } else echo ' · '.esc_html__('Woo order creation needs review. Do not create the Folio document again.','pc-order-import-export');
            echo '</p>';
        }
    }

    public static function customer_links($order): void {
        if (!$order instanceof \WC_Order || !get_current_user_id() || $order->get_customer_id()!==get_current_user_id()) return;
        if (self::active($order)) {
            self::render($order,$order->get_meta(ManagerQuickOrder::META,true),true);
        } elseif ($order->get_meta(self::CHILD_KEY) && $order->get_parent_id()) {
            $source=wc_get_order($order->get_parent_id());
            if ($source && $source->get_customer_id()===$order->get_customer_id()) echo '<p>'.esc_html__('Source order:','pc-order-import-export').' <a href="'.esc_url($source->get_view_order_url()).'">#'.esc_html($source->get_order_number()).'</a></p>';
        }
    }
}
