<?php
namespace Paint\NovaPoshta\Admin;

use Paint\NovaPoshta\Infrastructure\ManagerPreparation;
use Paint\NovaPoshta\Infrastructure\ShipmentRepository;
use Paint\NovaPoshta\Infrastructure\ShipmentLifecycle;
use Paint\NovaPoshta\Infrastructure\RecipientIdentity;

defined('ABSPATH') || exit;

final class ShipmentLifecyclePanel
{
    public function hooks(): void
    {
        add_action('pcoe_manager_order_shipments',[$this,'render'],20);
        add_action('admin_enqueue_scripts',[$this,'assets']);
        add_action('wp_ajax_pnpm_shipment_command',[$this,'ajax']);
        add_action('admin_post_pnpm_shipment_pdf',[$this,'pdf']);
    }

    public function assets(): void
    {
        if (!ManagerPreparation::allowed() || ($_GET['page'] ?? '')!=='pcoe-customers' || empty($_GET['order_id'])) return;
        wp_enqueue_script('pnpm-lifecycle',PNPM_URL.'assets/shipment-lifecycle.js',['jquery'],PNPM_VERSION,true);
        wp_localize_script('pnpm-lifecycle','pnpmLifecycle',['url'=>admin_url('admin-ajax.php'),'nonce'=>wp_create_nonce('pnpm_shipment_command'),
            'busy'=>__('Checking shipment…','paint-nova-poshta-multishipping'),
            'failed'=>__('Result not confirmed. Reload the page; do not repeat creation or email.','paint-nova-poshta-multishipping'),
            'dirty'=>__('Save preparation and reload this page before creating a TTN.','paint-nova-poshta-multishipping'),
            'cost'=>__('Declared value / estimated delivery, UAH','paint-nova-poshta-multishipping'),
            'payer'=>__('Delivery payer','paint-nova-poshta-multishipping'),
            'Sender'=>__('Sender','paint-nova-poshta-multishipping'),'Recipient'=>__('Recipient','paint-nova-poshta-multishipping'),
            'box'=>__('Weight, kg / dimensions, cm','paint-nova-poshta-multishipping'),
            'disabled'=>__('Real Nova Poshta write operations are disabled.','paint-nova-poshta-multishipping')]);
    }

    public function render(\WC_Order $order): void
    {
        if (!ManagerPreparation::allowed() || $order->get_meta('_folio_parent_order_id') || $order->get_meta('_folio_split_from_order_id')) return;
        $rows=(new ShipmentRepository())->findByOrder($order->get_id());
        if (!$rows) return;
        $service=new ShipmentLifecycle();
        echo '<section class="pcoe-card pnpm-lifecycle"><h2 data-pcoe-help="np-preparation">'.esc_html__('TTN, PDF and customer email','paint-nova-poshta-multishipping').'</h2>';
        echo '<p>'.esc_html__('Only saved preparation is used. Save and reload after editing. Create each warehouse TTN separately; printing and customer email are separate actions.','paint-nova-poshta-multishipping').'</p>';
        echo '<details><summary>'.esc_html__('How this works and current limits','paint-nova-poshta-multishipping').'</summary><p>'.esc_html__('Supported: drop-off at a branch, delivery to a branch or parcel locker, no COD, cash payment for carrier services. Enter the legal recipient name in Ukrainian; it will be recorded in the Nova Poshta recipient directory, not in the store customer profile.','paint-nova-poshta-multishipping').'</p><p>'.esc_html__('The estimate is not a final invoice. Unknown point limits must be checked with Nova Poshta. Confirm payer and any store subsidy manually. A created TTN does not mean that goods have been handed over.','paint-nova-poshta-multishipping').'</p><p>'.esc_html__('After a timeout or rejection, do not create another TTN: reconcile the result in the Nova Poshta business account with an administrator. Automatic retries and automatic Telegram messages are not enabled.','paint-nova-poshta-multishipping').'</p><p>'.esc_html__('Nova Poshta checks are paced and may take several seconds. Only a rate-limited read can retry once; recipient and TTN creation are never retried automatically. If the message says creation was not started, wait 10 seconds, reload and run a new check. An unknown creation result still requires administrator review.','paint-nova-poshta-multishipping').'</p></details>';
        foreach ($rows as $row) {
            if ($row['source']!=='store_api') continue;
            $location=get_term((int)$row['location_id'],'location');
            echo '<article class="pnpm-shipment" data-order="'.esc_attr($order->get_id()).'" data-id="'.esc_attr($row['id']).'"><h3>'.esc_html($location && !is_wp_error($location) ? $location->name : '#'.$row['location_id']).'</h3>';
            if (in_array($row['status'], ['manager_draft','np_recipient_ready'],true)) {
                $recipient = json_decode($row['recipient_snapshot'], true) ?: [];
                $defaults = RecipientIdentity::defaults($order, $recipient);
                $recovered = $row['status']==='np_recipient_ready';
                if ($recovered) {
                    $snapshot = json_decode($row['request_snapshot'],true) ?: [];
                    $defaults = $snapshot['person'] ?? $defaults;
                    echo '<p role="status">'.esc_html__('The existing Nova Poshta contact was verified by an administrator. Preparation is frozen. Run a fresh check and confirm TTN creation; the recipient will not be created again.','paint-nova-poshta-multishipping').'</p>';
                }
                echo '<p>'.esc_html__('Names are suggested from the order, then the customer card, only when the saved recipient name and phone match. Review them; patronymic is optional. A different recipient must be entered manually.','paint-nova-poshta-multishipping').'</p>';
                echo '<p>'.esc_html__('Saved recipient phone:','paint-nova-poshta-multishipping').' <strong>'.esc_html($recipient['phone'] ?? '').'</strong></p>';
                echo '<div class="pnpm-legal-name">';
                foreach (['FirstName'=>__('First name','paint-nova-poshta-multishipping'),'LastName'=>__('Last name','paint-nova-poshta-multishipping'),'MiddleName'=>__('Patronymic (optional)','paint-nova-poshta-multishipping')] as $key=>$label) {
                    echo '<label>'.esc_html($label).' <input data-person="'.esc_attr($key).'" value="'.esc_attr($defaults[$key]).'" maxlength="36" autocomplete="off"'.($recovered ? ' readonly' : '').'></label> ';
                }
                echo '</div><p><button type="button" class="button" data-shipment-command="preview">'.esc_html__('Check saved shipment with Nova Poshta','paint-nova-poshta-multishipping').'</button></p><div class="pnpm-carrier-preview"></div>';
                echo '<p>'.esc_html__('This preview does not identify a person by phone. On creation, returned Nova Poshta contact details must match before a TTN is requested. Missing or different details stop creation for manual review.','paint-nova-poshta-multishipping').'</p>';
                echo '<p><label><input type="checkbox" class="pnpm-create-agreed" disabled> '.esc_html__('I checked the recipient, boxes, declared value, delivery payer, cash payment and store subsidy. Create this real TTN.','paint-nova-poshta-multishipping').'</label></p><button type="button" class="button button-primary" data-shipment-command="create" disabled>'.esc_html__('Create TTN','paint-nova-poshta-multishipping').'</button>';
            } elseif ($row['status']==='np_created') {
                echo '<p>'.esc_html__('TTN created; handover is not confirmed.','paint-nova-poshta-multishipping').' <strong>'.esc_html($row['ttn_number']).'</strong></p>';
                foreach (['document'=>__('Download TTN PDF','paint-nova-poshta-multishipping'),'a4'=>__('Download A4 label PDF','paint-nova-poshta-multishipping'),'zebra'=>__('Download Zebra label PDF','paint-nova-poshta-multishipping')] as $format=>$label) {
                    echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" style="display:inline-block;margin-right:8px"><input type="hidden" name="action" value="pnpm_shipment_pdf"><input type="hidden" name="order_id" value="'.esc_attr($order->get_id()).'"><input type="hidden" name="id" value="'.esc_attr($row['id']).'"><input type="hidden" name="format" value="'.esc_attr($format).'">';
                    wp_nonce_field('pnpm_pdf_'.$order->get_id().'_'.$row['id']);
                    echo '<button class="button" type="submit">'.esc_html($label).'</button></form>';
                }
                $email=$order->get_billing_email();
                echo '<p>'.esc_html__('Email goes to the order customer, not to the parcel recipient:','paint-nova-poshta-multishipping').' '.esc_html($email).'</p>';
                $status=$service->emailStatus((int)$row['id']);
                echo '<button type="button" class="button" data-shipment-command="email" data-email="'.esc_attr(hash('sha256',$email)).'"'.disabled($status!=='' || !is_email($email),true,false).'>'.esc_html__('Send TTN to customer by email','paint-nova-poshta-multishipping').'</button>';
                if ($status) echo '<p>'.esc_html($status==='np_email_accepted'
                    ? __('Email accepted by the mail transport; delivery is not confirmed.','paint-nova-poshta-multishipping')
                    : __('Email failed or its result is unknown. Check mail logs before any manual resend.','paint-nova-poshta-multishipping')).'</p>';
            } elseif ($row['status']==='np_recipient_mismatch') {
                echo '<p role="alert">'.esc_html__('Nova Poshta returned different or incomplete recipient details. No TTN was requested. The recipient may have been saved in Nova Poshta; ask an administrator to reconcile it. Do not repeat creation.','paint-nova-poshta-multishipping').'</p>';
                $saved = json_decode($row['response_snapshot'],true) ?: [];
                $issues = $saved['identity_issues'] ?? [];
                $messages = [];
                foreach ($issues as $issue) {
                    $messages[] = match ($issue) {
                        'Phones_missing' => __('The contact directory did not return a phone number.','paint-nova-poshta-multishipping'),
                        'Phones_mismatch' => __('The contact phone does not match the saved recipient phone.','paint-nova-poshta-multishipping'),
                        'FirstName_missing','LastName_missing' => __('The contact directory did not return the first or last name.','paint-nova-poshta-multishipping'),
                        'FirstName_mismatch','LastName_mismatch','MiddleName_mismatch' => __('The contact name does not match the confirmed recipient name.','paint-nova-poshta-multishipping'),
                        'contact_read_unavailable','contact_read_invalid' => __('The contact directory could not be read. No TTN was requested.','paint-nova-poshta-multishipping'),
                        default => __('The exact contact could not be uniquely verified in the recipient directory.','paint-nova-poshta-multishipping'),
                    };
                }
                if (!$messages) $messages[] = __('The earlier version did not save verification details. Administrator reconciliation is required.','paint-nova-poshta-multishipping');
                foreach (array_unique($messages) as $message) echo '<p>'.esc_html($message).'</p>';
            } else {
                echo '<p role="alert">'.esc_html__('Creation is locked: the result needs manual review in the Nova Poshta business account. Do not repeat the shipment.','paint-nova-poshta-multishipping').'</p>';
            }
            echo '<div class="pnpm-lifecycle-status" role="status" aria-live="polite"></div></article>';
        }
        echo '</section>';
    }

    public function ajax(): void
    {
        if (!ManagerPreparation::allowed()) wp_send_json_error(['message'=>__('Shipment access denied.','paint-nova-poshta-multishipping')],403);
        check_ajax_referer('pnpm_shipment_command','nonce');
        try {
            $order=absint($_POST['order_id'] ?? 0); $id=absint($_POST['id'] ?? 0);
            $command=sanitize_key($_POST['command'] ?? '');
            $service=new ShipmentLifecycle();
            if ($command==='preview') {
                $names=wp_unslash($_POST['person'] ?? []);
                if (!is_array($names)) throw new \RuntimeException(__('Invalid preparation request.','paint-nova-poshta-multishipping'));
                wp_send_json_success(['preview'=>$service->preview($order,$id,$names)]);
            }
            if ($command==='create' && ($_POST['agreed'] ?? '')==='1') wp_send_json_success($service->create($order,$id,sanitize_text_field($_POST['token'] ?? '')));
            if ($command==='email') wp_send_json_success(['email'=>$service->email($order,$id,sanitize_text_field($_POST['recipient_hash'] ?? ''))]);
            throw new \RuntimeException(__('Invalid preparation request.','paint-nova-poshta-multishipping'));
        } catch (\Throwable $e) { wp_send_json_error(['message'=>$e->getMessage()],409); }
    }

    public function pdf(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '')!=='POST' || !ManagerPreparation::allowed()) wp_die(esc_html__('Shipment access denied.','paint-nova-poshta-multishipping'),'',403);
        $order=absint($_POST['order_id'] ?? 0); $id=absint($_POST['id'] ?? 0);
        check_admin_referer('pnpm_pdf_'.$order.'_'.$id);
        try { $pdf=(new ShipmentLifecycle())->pdf($order,$id,sanitize_key($_POST['format'] ?? '')); }
        catch (\Throwable $e) { wp_die(esc_html($e->getMessage()),'',409); }
        if (headers_sent()) wp_die(esc_html__('Label printing is unavailable.','paint-nova-poshta-multishipping'));
        while (ob_get_level()>0) ob_end_clean();
        nocache_headers();
        header('Cache-Control: private, no-store, max-age=0');
        header('Content-Type: application/pdf');
        header('X-Content-Type-Options: nosniff');
        header('Content-Disposition: attachment; filename="nova-poshta-'.$id.'.pdf"');
        header('Content-Length: '.strlen($pdf));
        echo $pdf; // Validated server-side bytes, never a redirect to a secret-bearing carrier URL.
        exit;
    }
}
