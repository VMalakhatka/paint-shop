<?php
namespace Paint\NovaPoshta\Admin;
use Paint\NovaPoshta\Infrastructure\{CourierQueue,CourierPickup,CourierStorage,ManagerPreparation};
defined('ABSPATH') || exit;

final class CourierPage
{
    private static function e(string $text): string { return esc_html__($text,'paint-nova-poshta-multishipping'); }
    public function hooks(): void
    {
        add_action('admin_menu',function () {
            if (ManagerPreparation::allowed()) add_submenu_page('woocommerce',__('Nova Poshta courier queue','paint-nova-poshta-multishipping'),__('Nova Poshta courier queue','paint-nova-poshta-multishipping'),'manage_pnpm_shipments','pnpm-courier',[$this,'render']);
        });
        add_action('admin_post_pnpm_courier_setup',[$this,'setup']);
        add_action('wp_ajax_pnpm_courier',[$this,'ajax']);
        add_action('admin_enqueue_scripts',function () {
            if (!ManagerPreparation::allowed() || ($_GET['page'] ?? '')!=='pnpm-courier') return;
            wp_enqueue_script('pnpm-courier',PNPM_URL.'assets/courier.js',['jquery'],PNPM_VERSION,true);
            wp_localize_script('pnpm-courier','pnpmCourier',['url'=>admin_url('admin-ajax.php'),'nonce'=>wp_create_nonce('pnpm_courier'),
                'busy'=>__('Checking courier data…','paint-nova-poshta-multishipping'),
                'failed'=>__('Result not confirmed. Reload the page and check the call history. Do not repeat creation.','paint-nova-poshta-multishipping'),
                'choose'=>__('Choose a pickup interval','paint-nova-poshta-multishipping'),
                'empty'=>__('No pickup intervals are available for this date.','paint-nova-poshta-multishipping'),
                'disabled'=>__('Real Nova Poshta write operations are disabled.','paint-nova-poshta-multishipping'),
                'expired'=>__('The preview expired. Request a new preview.','paint-nova-poshta-multishipping'),
                'address'=>__('Pickup address:','paint-nova-poshta-multishipping'),
                'weight'=>__('Planned weight, kg','paint-nova-poshta-multishipping'),
                'places'=>__('Places','paint-nova-poshta-multishipping')]);
        });
    }

    public function setup(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '')!=='POST' || !ManagerPreparation::allowed() || !current_user_can('manage_options')) wp_die(self::e('Shipment access denied.'),'',403);
        check_admin_referer('pnpm_courier_setup');
        try {
            if (($_POST['command'] ?? '')==='install') CourierStorage::install();
            elseif (CourierStorage::ready() && in_array($_POST['command'] ?? '',['enable','disable'],true)) {
                $enable=$_POST['command']==='enable';
                if ($enable && ($_POST['agreed'] ?? '')!=='1') throw new \RuntimeException(__('Confirm activation with Nova Poshta first.','paint-nova-poshta-multishipping'));
                (new CourierPickup())->enroll(sanitize_text_field(wp_unslash($_POST['sender'] ?? '')),$enable);
            } else throw new \RuntimeException(__('Invalid preparation request.','paint-nova-poshta-multishipping'));
        } catch (\Throwable $e) { wp_die(esc_html($e->getMessage()),'',409); }
        wp_safe_redirect(admin_url('admin.php?page=pnpm-courier')); exit;
    }

    public function ajax(): void
    {
        if (!ManagerPreparation::allowed() || !CourierStorage::ready()) wp_send_json_error(['message'=>__('Shipment access denied.','paint-nova-poshta-multishipping')],403);
        check_ajax_referer('pnpm_courier','nonce');
        try {
            $s=new CourierPickup(); $command=sanitize_key($_POST['command'] ?? '');
            $ids=$_POST['ids'] ?? []; if (!is_array($ids)) $ids=[];
            $date=sanitize_text_field(wp_unslash($_POST['date'] ?? ''));
            if ($command==='intervals') wp_send_json_success(['slots'=>$s->intervals($ids,$date)]);
            if ($command==='preview') wp_send_json_success(['preview'=>$s->preview($ids,$date,sanitize_text_field(wp_unslash($_POST['interval'] ?? '')),sanitize_text_field(wp_unslash($_POST['weight'] ?? '')))]);
            if ($command==='create' && ($_POST['agreed'] ?? '')==='1') { $row=$s->create(sanitize_text_field($_POST['token'] ?? '')); wp_send_json_success(['id'=>(int)$row['id']]); }
            if ($command==='refresh') { $s->refresh(absint($_POST['id'] ?? 0)); wp_send_json_success(['read'=>true]); }
            throw new \RuntimeException(__('Invalid preparation request.','paint-nova-poshta-multishipping'));
        } catch (\Throwable $e) { wp_send_json_error(['message'=>$e->getMessage()],409); }
    }

    private function setupForm(string $command): void
    {
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="pnpm_courier_setup"><input type="hidden" name="command" value="'.esc_attr($command).'">';
        wp_nonce_field('pnpm_courier_setup');
    }

    public function render(): void
    {
        if (!ManagerPreparation::allowed()) wp_die(self::e('Shipment access denied.'),'',403);
        echo '<div class="wrap pnpm-courier"><h1>'.self::e('Nova Poshta courier queue').'</h1>';
        echo '<details><summary>'.self::e('How the courier queue works').'</summary><ol><li>'.self::e('Ask your Nova Poshta manager to enable Courier Call and disable automatic individual calls for address-origin TTNs. An administrator then confirms this below for the exact sender.').'</li><li>'.self::e('In an order, choose courier pickup, save preparation, check the shipment and create its TTN. Print labels in the order. Creating a TTN does not call a courier.').'</li><li>'.self::e('Select ready TTNs from one sender address, choose a date and available interval, check planned weight and confirm one courier call. Different addresses require separate calls.').'</li></ol><p>'.self::e('The call and adding TTNs are two separate carrier operations. A partial or unknown result stays locked; check the saved call and Nova Poshta account before any further action.').'</p><p>'.self::e('The final pickup price and accepted weight are determined by Nova Poshta. Order payments, COD, recipients and Folio links are not changed.').'</p></details>';
        if (!CourierStorage::ready()) {
            echo '<p>'.self::e('Initialize courier storage before continuing.').'</p>';
            if (current_user_can('manage_options')) { $this->setupForm('install'); echo '<button class="button button-primary">'.self::e('Initialize courier storage').'</button></form>'; }
        } else {
            $this->settings();
            echo '<h2>'.self::e('TTNs ready for a shared courier call').'</h2>';
            $ready=(new CourierQueue())->createdPage(absint($_GET['ready_page'] ?? 1));
            $this->groups($ready,true);
            $this->pagination($ready,'ready_page');
            $this->history();
        }
        echo '<details><summary>'.self::e('Saved courier preparations:').'</summary>';
        $drafts=(new CourierQueue())->page(absint($_GET['paged'] ?? 1));
        echo '<p>'.esc_html((string)$drafts['total']).'</p>';
        $this->groups($drafts,false); $this->pagination($drafts,'paged');
        echo '</details><p><a href="'.esc_url(admin_url('admin.php?page=pnpm-settings')).'">'.self::e('Check sender settings').'</a></p></div>';
    }

    private function settings(): void
    {
        if (!current_user_can('manage_options')) return;
        echo '<details><summary>'.self::e('Courier service activation').'</summary><p>'.self::e('This confirmation records the arrangement with Nova Poshta. It does not enable the service at the carrier.').'</p>';
        $senders=[];
        foreach ((array)get_option('pnpm_location_mappings',[]) as $m) if (!empty($m['counterparty_ref'])) $senders[$m['counterparty_ref']]=$m['customer_label'] ?? $m['counterparty_ref'];
        $s=new CourierPickup();
        foreach ($senders as $ref=>$label) {
            $active=$s->enabled($ref); $this->setupForm($active?'disable':'enable');
            echo '<p><strong>'.esc_html($label).'</strong> <code>'.esc_html($ref).'</code></p><input type="hidden" name="sender" value="'.esc_attr($ref).'">';
            if (!$active) echo '<p><label><input type="checkbox" name="agreed" value="1" required> '.self::e('Nova Poshta confirmed Courier Call is enabled and automatic individual calls are disabled for this sender.').'</label></p>';
            echo '<p><button class="button">'.self::e($active?'Disable courier creation for this sender':'Confirm courier activation').'</button></p></form>';
        }
        echo '</details>';
    }

    private function groups(array $page,bool $ready): void
    {
        if (!$page['groups']) echo '<p>'.self::e($ready?'No created TTNs awaiting a courier call.':'No saved courier preparations on this page.').'</p>';
        foreach ($page['groups'] as $group) {
            echo '<section class="card pnpm-courier-group" style="max-width:none"><h3>'.esc_html($group['sender']['customer_label'] ?? '').'</h3><p>'.self::e('Pickup address:').' '.esc_html(($group['sender']['sender_address_label'] ?? '') ?: __('Check the registered address in sender settings.','paint-nova-poshta-multishipping')).'</p><p>'.self::e('Contact phone:').' '.esc_html($group['sender']['phone'] ?? '').'</p>';
            if (!$group['complete']) echo '<p>'.self::e('Sender settings are incomplete. This preparation cannot be grouped with others.').'</p>';
            echo '<div style="overflow:auto"><table class="widefat striped"><thead><tr>';
            foreach (['TTN','Order','Stock Location','Recipient','Places','Weight, kg'] as $label) echo '<th>'.self::e($label).'</th>';
            echo '</tr></thead><tbody>';
            foreach ($group['rows'] as $row) {
                $r=json_decode((string)$row['recipient_snapshot'],true) ?: [];
                $order=wc_get_order((int)$row['order_id']); $location=get_term((int)$row['location_id'],'location');
                $url=$order && class_exists('PaintCore\\PCOE\\ManagerWorkspace') ? \PaintCore\PCOE\ManagerWorkspace::url((int)$order->get_customer_id(),(int)$row['order_id']) : ($order ? $order->get_edit_order_url() : '');
                echo '<tr><td>';
                if ($ready) echo '<label><input type="checkbox" class="pnpm-courier-select" value="'.esc_attr($row['id']).'" data-weight="'.esc_attr($row['queue_weight'] ?? '').'"> '.esc_html($row['ttn_number']).'</label>';
                else echo self::e('Preparation');
                echo '</td><td>'.($url?'<a href="'.esc_url($url).'">#'.esc_html($order->get_order_number()).'</a>':self::e('Order unavailable.')).'</td><td>'.esc_html($location && !is_wp_error($location)?$location->name:'#'.$row['location_id']).'</td><td>'.esc_html(($r['name'] ?? '').' · '.($r['city_label'] ?? '')).'</td><td>'.esc_html($row['queue_places'] ?? '—').'</td><td>'.esc_html($row['queue_weight']===null?'—':number_format_i18n($row['queue_weight'],3)).'</td></tr>';
            }
            echo '</tbody></table></div>';
            if ($ready) {
                echo '<p><label>'.self::e('Pickup date').' <input type="date" class="pnpm-pickup-date" value="'.esc_attr(wp_date('Y-m-d',null,new \DateTimeZone('Europe/Kyiv'))).'"></label> <button type="button" class="button" data-courier-command="intervals">'.self::e('Load pickup intervals').'</button></p>';
                echo '<p><label>'.self::e('Pickup interval').' <select class="pnpm-pickup-interval"><option value="">—</option></select></label> <label>'.self::e('Planned weight, kg').' <input type="number" class="pnpm-pickup-weight" min="0.1" max="100000" step="0.001"></label></p>';
                echo '<p>'.self::e('The selected boxes supply the initial planned weight. Review the total packed load, including bulky parcels, before confirming.').'</p><button type="button" class="button" data-courier-command="preview">'.self::e('Review shared courier call').'</button><div class="pnpm-pickup-preview"></div>';
                echo '<p><label><input type="checkbox" class="pnpm-pickup-agreed" disabled> '.self::e('I checked the address, selected TTNs, pickup time and planned weight. Order one real courier pickup.').'</label></p><button type="button" class="button button-primary" data-courier-command="create" disabled>'.self::e('Confirm and call courier').'</button><p class="pnpm-courier-status" role="status" aria-live="polite"></p>';
            }
            echo '</section>';
        }
    }

    private function history(): void
    {
        global $wpdb;
        $rows=$wpdb->get_results("SELECT * FROM {$wpdb->prefix}pnpm_courier_calls ORDER BY id DESC LIMIT 30",ARRAY_A);
        echo '<h2>'.self::e('Courier call history (latest 30)').'</h2>';
        if (!$rows) echo '<p>'.self::e('No courier calls have been recorded.').'</p>';
        foreach ($rows as $row) {
            $request=json_decode($row['request_snapshot'],true) ?: []; $response=json_decode($row['response_snapshot'],true) ?: [];
            $read=$response['read'] ?? null;
            echo '<section class="card" style="max-width:none"><h3>#'.esc_html($row['id']).' · '.esc_html($row['barcode'] ?: __('Number not confirmed','paint-nova-poshta-multishipping')).'</h3><p>'.esc_html(($request['date'] ?? '').' · '.($request['slot']['label'] ?? '').' · '.($request['selection']['sender']['sender_address_label'] ?? '')).'</p><p>'.self::e('Planned weight, kg').': '.esc_html($request['payload']['PlanedWeight'] ?? '—').'</p><p>'.self::e('TTN').': '.esc_html(implode(', ',$request['selection']['ttns'] ?? [])).'</p>';
            if ($read) {
                echo '<p>'.self::e('Carrier status:').' '.esc_html($read['call']['StatusDescription'] ?? __('Not returned in the seven-day carrier list.','paint-nova-poshta-multishipping')).'</p><p>'.self::e($read['matches']?'The carrier document list matches the selected TTNs.':'The carrier document list differs. Reconcile missing or extra TTNs in the Nova Poshta account.').'</p>';
                foreach (['missing'=>'Missing TTNs:','extra'=>'Extra TTNs:'] as $key=>$label) if (!empty($read[$key])) echo '<p>'.self::e($label).' '.esc_html(implode(', ',$read[$key])).'</p>';
                echo '<p>'.self::e('Last checked (UTC):').' '.esc_html($read['at']).'</p>';
            } else echo '<p>'.self::e($row['status']==='documents_added'?'Nova Poshta confirmed the call number and all selected TTNs. Pickup is not yet confirmed.':'The operation is incomplete or its result is unknown. Do not create another call. Reconcile it with Nova Poshta.').'</p>';
            if ($row['barcode']) echo '<button type="button" class="button" data-courier-command="refresh" data-id="'.esc_attr($row['id']).'">'.self::e('Check call and TTNs with Nova Poshta').'</button><p class="pnpm-courier-status" role="status"></p>';
            echo '</section>';
        }
    }

    private function pagination(array $page,string $key): void
    {
        echo '<p>'.esc_html(sprintf(__('Page %1$d of %2$d','paint-nova-poshta-multishipping'),$page['page'],$page['pages'])).'</p>';
        foreach ([-1=>'Previous page',1=>'Next page'] as $step=>$label) {
            $target=$page['page']+$step;
            if ($target>0 && $target<=$page['pages']) echo '<a class="button" href="'.esc_url(add_query_arg(['page'=>'pnpm-courier',$key=>$target],admin_url('admin.php'))).'">'.self::e($label).'</a> ';
        }
    }
}
