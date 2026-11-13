<?php
namespace Paint\NovaPoshta\Admin;

use Paint\NovaPoshta\Infrastructure\{CourierQueue,ManagerPreparation};

defined('ABSPATH') || exit;

/** Preparations only until the new courier API contract has been verified. */
final class CourierPage
{
    private static function e(string $text): string { return esc_html__($text,'paint-nova-poshta-multishipping'); }

    public function hooks(): void
    {
        add_action('admin_menu', function () {
            if (ManagerPreparation::allowed()) add_submenu_page('woocommerce',__('Nova Poshta courier queue','paint-nova-poshta-multishipping'),__('Nova Poshta courier queue','paint-nova-poshta-multishipping'),'manage_pnpm_shipments','pnpm-courier',[$this,'render']);
        });
    }

    public function render(): void
    {
        if (!ManagerPreparation::allowed()) wp_die(self::e('Shipment access denied.'),'',403);
        $page = (new CourierQueue())->page(absint($_GET['paged'] ?? 1));
        echo '<div class="wrap"><h1>'.self::e('Nova Poshta courier queue').'</h1>';
        echo '<div class="notice notice-warning inline"><p>'.self::e('Saved preparations only. No courier has been called and no pickup time is reserved. Shared courier requests through the site are not enabled yet.').'</p></div>';
        echo '<details><summary>'.self::e('How the courier queue works').'</summary><ol><li>'.self::e('In an order, choose courier pickup for the warehouse and save preparation. The choice applies only to this shipment.').'</li><li>'.self::e('Preparations with the same sender, city, address and contact appear together. Different origins require separate pickups.').'</li><li>'.self::e('Before enabling address-origin TTNs, confirm with Nova Poshta that automatic individual courier calls are disabled for this sender.').'</li></ol><p>'.self::e('This list does not change TTNs, customer shipments, payment, WooCommerce orders or Folio links.').'</p></details>';
        echo '<p>'.self::e('Saved courier preparations:').' <strong>'.esc_html((string)$page['total']).'</strong></p>';
        if (!$page['groups']) echo '<p>'.self::e('No saved courier preparations on this page.').'</p>';
        foreach ($page['groups'] as $group) {
            echo '<section class="card" style="max-width:none"><h2>'.esc_html($group['sender']['customer_label'] ?? '').'</h2><p>'.self::e('Pickup address:').' '.esc_html(($group['sender']['sender_address_label'] ?? '') ?: __('Check the registered address in sender settings.','paint-nova-poshta-multishipping')).'</p>';
            echo '<p>'.self::e('Contact phone:').' '.esc_html($group['sender']['phone'] ?? '').'</p>';
            if (!$group['complete']) echo '<p>'.self::e('Sender settings are incomplete. This preparation cannot be grouped with others.').'</p>';
            echo '<p>'.self::e('Places / packed weight on this page:').' '.esc_html($group['totals_known'] ? $group['places'].' / '.number_format_i18n($group['weight'],3).' '.__('kg','paint-nova-poshta-multishipping') : '—').'</p>';
            echo '<div style="overflow:auto"><table class="widefat striped"><thead><tr>';
            foreach (['Order','Stock Location','Recipient','Places','Weight, kg','Updated (UTC)'] as $label) echo '<th>'.self::e($label).'</th>';
            echo '</tr></thead><tbody>';
            foreach ($group['rows'] as $row) {
                $recipient = json_decode((string)$row['recipient_snapshot'],true) ?: [];
                $order = wc_get_order((int)$row['order_id']);
                $location = get_term((int)$row['location_id'],'location');
                $url = $order && class_exists('PaintCore\\PCOE\\ManagerWorkspace') ? \PaintCore\PCOE\ManagerWorkspace::url((int)$order->get_customer_id(),(int)$row['order_id']) : ($order ? $order->get_edit_order_url() : '');
                echo '<tr><td>'.($url ? '<a href="'.esc_url($url).'">#'.esc_html($order->get_order_number()).'</a>' : self::e('Order unavailable.')).'</td><td>'.esc_html($location && !is_wp_error($location) ? $location->name : '#'.$row['location_id']).'</td><td>'.esc_html(($recipient['name'] ?? '').' · '.($recipient['city_label'] ?? '')).'</td><td>'.esc_html($row['queue_places'] ?? '—').'</td><td>'.esc_html($row['queue_weight'] === null ? '—' : number_format_i18n($row['queue_weight'],3)).'</td><td>'.esc_html($row['updated_at']).'</td></tr>';
            }
            echo '</tbody></table></div></section>';
        }
        echo '<p>'.esc_html(sprintf(__('Page %1$d of %2$d','paint-nova-poshta-multishipping'),$page['page'],$page['pages'])).'</p>';
        foreach ([-1=>'Previous page',1=>'Next page'] as $step=>$label) {
            $target = $page['page']+$step;
            if ($target > 0 && $target <= $page['pages']) echo '<a class="button" href="'.esc_url(add_query_arg(['page'=>'pnpm-courier','paged'=>$target],admin_url('admin.php'))).'">'.self::e($label).'</a> ';
        }
        echo '<p><a href="'.esc_url(admin_url('admin.php?page=pnpm-settings')).'">'.self::e('Check sender settings').'</a></p></div>';
    }
}
