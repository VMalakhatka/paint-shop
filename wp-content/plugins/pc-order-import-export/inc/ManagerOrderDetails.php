<?php
namespace PaintCore\PCOE;
defined('ABSPATH') || exit;

/** Read-only summary for managers; never creates shipments or sends messages. */
final class ManagerOrderDetails {
    public static function source(\WC_Order $order): \WC_Order {
        $id = (int) ($order->get_meta('_folio_parent_order_id') ?: $order->get_meta('_folio_split_from_order_id'));
        if ($id && !$order->get_items('shipping')) {
            $parent = wc_get_order($id);
            if ($parent instanceof \WC_Order && (int)$parent->get_customer_id() === (int)$order->get_customer_id()
                && ($order->get_customer_id() || $parent->get_billing_email() === $order->get_billing_email())) return $parent;
        }
        return $order;
    }
    /** Saved checkout destination; never substitute it for an issued shipment snapshot. */
    public static function destination(\WC_Order $order): string {
        $recipient = self::source($order)->get_meta('_pnpm_recipient');
        if (!is_array($recipient)) return '';
        $text = static fn($key) => isset($recipient[$key]) && is_scalar($recipient[$key]) ? trim((string)$recipient[$key]) : '';
        $type = $text('delivery_type');
        $types = ['parcel_locker'=>__('Parcel locker', 'pc-order-import-export'),
            'branch'=>__('Nova Poshta branch', 'pc-order-import-export'),
            'address'=>__('Courier delivery', 'pc-order-import-export')];
        if (!$text('city_label') && !$text('point_label') && !isset($types[$type])) return '';
        $html = '<div class="pcoe-np-destination"><h3>'.esc_html__('Nova Poshta destination selected at checkout', 'pc-order-import-export').'</h3>';
        if (isset($types[$type])) $html .= '<p><strong>'.esc_html($types[$type]).'</strong></p>';
        if ($text('city_label')) $html .= '<p>'.esc_html($text('city_label')).'</p>';
        if ($type !== 'address') $html .= '<p><strong>'.esc_html($text('point_label') ?: __('Pickup point is not saved. Check before creating a shipment.', 'pc-order-import-export')).'</strong></p>';
        $html .= '<p>'.esc_html__('This is the choice saved with the order. Check the shipment preparation below for any later changes agreed with the customer.', 'pc-order-import-export').'</p></div>';
        return $html;
    }
    public static function render(\WC_Order $order): void {
        if (!current_user_can('manage_woocommerce')) return;
        $source = self::source($order);
        echo '<section class="pcoe-card"><h2 data-pcoe-help="delivery">' . esc_html__('Delivery and customer comments', 'pc-order-import-export') . '</h2>';
        if ($order->get_meta('_folio_document_type')==='waiting_transfer_account') echo '<p class="pcoe-warning">'.esc_html__('Awaiting transfer — not ready for picking','pc-order-import-export').'</p>';
        if ($source->get_id() !== $order->get_id()) echo '<p>' . esc_html(sprintf(__('Delivery from original order #%s (all warehouses).', 'pc-order-import-export'), $source->get_order_number())) . '</p>';
        $summary = class_exists('Paint\\NovaPoshta\\Email\\DeliverySummary') ? (new \Paint\NovaPoshta\Email\DeliverySummary())->render($order, true, false) : '';
        echo $summary ? wp_kses_post($summary) : '<p>' . esc_html($source->get_shipping_method() ?: __('Delivery method not specified.', 'pc-order-import-export')) . '</p>';
        $destination = self::destination($order);
        echo $destination;
        echo '<p><strong>' . esc_html($destination ? __('Recipient and address saved in WooCommerce', 'pc-order-import-export') : __('Recipient and address', 'pc-order-import-export')) . '</strong><br>' . wp_kses_post($source->get_formatted_shipping_address() ?: $source->get_formatted_billing_address() ?: '—') . '</p>';
        echo '<p>' . esc_html($source->get_shipping_phone() ?: $source->get_billing_phone()) . '<br>' . esc_html($source->get_billing_email()) . '</p>';
        if (class_exists('Paint\\NovaPoshta\\Infrastructure\\ShipmentRepository')) {
            $rows = (new \Paint\NovaPoshta\Infrastructure\ShipmentRepository())->findByOrder($source->get_id());
            if (!$rows) echo '<p>' . esc_html__('No saved shipments yet.', 'pc-order-import-export') . '</p>';
            foreach ($rows as $row) {
                $ttn = (string) ($row['ttn_number'] ?? '');
                if (!preg_match('/^\d{14}$/', $ttn)) continue;
                $tracking = json_decode((string) ($row['response_snapshot'] ?? ''), true);
                echo '<p><a target="_blank" rel="noopener noreferrer" href="' . esc_url('https://tracking.novaposhta.ua/#/uk/' . $ttn) . '">' . esc_html('TTN ' . $ttn) . '</a><br>';
                if (!empty($tracking['checked_at'])) echo esc_html(wp_date('d.m.Y H:i', (int)$tracking['checked_at']) . ' — ' . ($tracking['text'] ?? ''));
                else echo esc_html__('Tracking has not been checked yet.', 'pc-order-import-export');
                echo '</p>';
            }
        }
        echo '<h3 data-pcoe-help="delivery">' . esc_html__('Customer comment', 'pc-order-import-export') . '</h3><p>' . nl2br(esc_html($order->get_customer_note() ?: __('No customer comment.', 'pc-order-import-export'))) . '</p>';
        if ($source->get_id() !== $order->get_id() && $source->get_customer_note() && $source->get_customer_note() !== $order->get_customer_note()) {
            echo '<p><strong>' . esc_html__('Original order comment', 'pc-order-import-export') . '</strong><br>' . nl2br(esc_html($source->get_customer_note())) . '</p>';
        }
        echo '<h3 data-pcoe-help="delivery">' . esc_html__('Order history and notes', 'pc-order-import-export') . '</h3>';
        $page = max(1, absint($_GET['notes_page'] ?? 1));
        $notes = $order->get_id() ? wc_get_order_notes(['order_id'=>$order->get_id(), 'limit'=>21, 'offset'=>($page-1)*20, 'orderby'=>'comment_ID', 'order'=>'DESC']) : [];
        if (!$notes) echo '<p>' . esc_html__('No order notes.', 'pc-order-import-export') . '</p>';
        foreach (array_slice($notes,0,20) as $note) {
            echo '<div class="pcoe-order-note"><p><strong>' . esc_html(($note->date_created ? wc_format_datetime($note->date_created, 'd.m.Y H:i') : '') . ' · ' . ($note->customer_note ? __('Note to customer', 'pc-order-import-export') : __('Internal note', 'pc-order-import-export'))) . '</strong></p><p>' . wp_kses_post(wpautop($note->content)) . '</p></div>';
        }
        $url = ManagerWorkspace::url((int)$order->get_customer_id(), (int)$order->get_id());
        echo '<nav class="pcoe-actions">';
        if ($page>1) echo '<a class="button" href="' . esc_url(add_query_arg('notes_page',$page-1,$url)) . '">' . esc_html__('Previous page','pc-order-import-export') . '</a>';
        if (count($notes)>20) echo '<a class="button" href="' . esc_url(add_query_arg('notes_page',$page+1,$url)) . '">' . esc_html__('Next page','pc-order-import-export') . '</a>';
        echo '</nav></section>';
        // Shipment commands belong to the NP plugin, not to checkout or PCOE.
        do_action('pcoe_manager_order_shipments', $order);
    }
}
