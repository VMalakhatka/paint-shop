<?php
namespace PaintCore\PCOE;
defined('ABSPATH') || exit;

/** Present the saved operation and its actual Woo results without creating anything. */
final class ManagerOrderFlow {
    public static function labels(string $mode): array {
        return $mode==='accounts'
            ? [__('Create customer order with Folio reservation','pc-order-import-export'), __('I checked the available quantities, shortages, customer prices and warehouses and confirm this customer order.','pc-order-import-export')]
            : [__('Save only the non-accounting Folio document','pc-order-import-export'), __('I understand that the whole list will be saved without checking or reserving stock and will remain a Woo draft.','pc-order-import-export')];
    }

    public static function can_apply(array $response,string $mode): bool {
        if ($mode==='non_accounting') return true;
        foreach ($response['documents']??[] as $doc) if (($doc['accounting_enabled']??false)===true) return true;
        return false;
    }

    public static function summary(array $response,string $mode,array $preference): void {
        [$title]=self::labels($mode);
        echo '<h3>'.esc_html($title).'</h3>';
        if ($mode==='accounts') {
            $term=!empty($preference['term_id'])?get_term($preference['term_id'],'location'):null;
            if ($term && !is_wp_error($term) && $preference['mode']!=='auto') echo '<p>'.esc_html(sprintf(__('Website warehouse group: %s','pc-order-import-export'),$term->name)).'</p>';
            echo '<p>'.esc_html__('Reserved quantities become customer Woo orders linked to accounting Folio accounts. Shortages stay separate and are not ready for assembly. After creation, request customer confirmation from the reserved orders below.','pc-order-import-export').'</p>';
            if (!self::can_apply($response,$mode)) echo '<p class="pcoe-warning">'.esc_html__('No quantities are available for reservation. Customer order creation is unavailable; check stock or change the selected warehouse group and preview again.','pc-order-import-export').'</p>';
        } else echo '<p class="pcoe-warning">'.esc_html__('This action saves the entire list in one non-accounting document on the configured warehouse. It does not check stock, split by availability or create a reserved Woo order.','pc-order-import-export').'</p>';
        ManagerWorkspace::documents_table($response);
    }

    public static function non_accounting_only(\WC_Order $order): bool {
        $command=ManagerWorkspace::command($order);
        return ($command['status']??'')==='complete' && ($command['payload']['folio_account_header']['accountingEnabled']??null)===false;
    }

    public static function orders(\WC_Order $order,array $result): void {
        if (!current_user_can('manage_woocommerce')) return;
        $command=ManagerWorkspace::command($order);
        if ($command && ($command['status']??'')!=='complete') return;
        $keys=pc_folio_order_documents_meta_keys();
        $ids=array_filter(array_map('absint',(array)$order->get_meta($keys['child_order_ids'])));
        if (!$ids) $ids=[$order->get_id()];
        $documents=[];foreach ($result['documents']??[] as $doc) $documents[(int)($doc['document_id']??0)]=$doc;
        $rows=[];
        foreach ($ids as $id) {
            $child=wc_get_order($id);
            if (!$child || (int)$child->get_customer_id()!==(int)$order->get_customer_id() || ($id!==$order->get_id() && (int)$child->get_parent_id()!==$order->get_id())) continue;
            $link=pc_folio_get_order_document_link($child);$doc=$documents[(int)($link['document_id']??0)]??null;
            if (!$doc) continue;
            $reserved=($doc['accounting_enabled']??false)===true;
            $rows[]=[$child,$doc,$reserved];
        }
        if (!$rows) return;
        echo '<h3>'.esc_html__('Customer orders and confirmation','pc-order-import-export').'</h3>';
        echo '<p>'.esc_html__('Open each reserved order to request customer confirmation. The customer chooses delivery and payment preferences in that confirmation; nothing is sent automatically here.','pc-order-import-export').'</p>';
        echo '<div class="pcoe-scroll"><table class="widefat striped"><thead><tr><th>'.esc_html__('Woo order','pc-order-import-export').'</th><th>'.esc_html__('Folio warehouse','pc-order-import-export').'</th><th>'.esc_html__('Result','pc-order-import-export').'</th><th>'.esc_html__('Next action','pc-order-import-export').'</th></tr></thead><tbody>';
        foreach ($rows as [$child,$doc,$reserved]) {
            echo '<tr><td><a href="'.esc_url(ManagerWorkspace::url($child->get_customer_id(),$child->get_id())).'">#'.esc_html($child->get_order_number()).'</a><br>'.esc_html(wc_get_order_status_name($child->get_status())).'</td><td>'.esc_html(pc_folio_warehouse_label($doc['folio_warehouse_id']??0)).'</td><td>'.esc_html($reserved?__('Reserved stock','pc-order-import-export'):__('Shortage — not ready for assembly','pc-order-import-export')).'</td><td>';
            if ($reserved && $child->has_status('processing')) echo '<a class="button" href="'.esc_url(CustomerApproval::url(['order_id'=>$child->get_id()])).'">'.esc_html__('Request customer confirmation','pc-order-import-export').'</a>';
            else echo esc_html__('Review this order separately','pc-order-import-export');
            echo '</td></tr>';
        }
        echo '</tbody></table></div>';
    }
}
