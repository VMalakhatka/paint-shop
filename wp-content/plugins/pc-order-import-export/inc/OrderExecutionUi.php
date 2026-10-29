<?php
namespace PaintCore\PCOE;
defined('ABSPATH') || exit;

final class OrderExecutionUi {
    public static function name(int $id): string {
        $user=$id?get_userdata($id):false;
        return $user?$user->display_name:__('Unassigned','pc-order-import-export');
    }
    public static function time(string $time): string { return $time?wp_date('d.m.Y H:i',strtotime($time)):'—'; }
    private static function form(\WC_Order $root,array $state,string $operation): void {
        echo '<form method="post" class="pcoe-execution-form" action="'.esc_url(admin_url('admin-post.php')).'">';
        wp_nonce_field('pcoe_execution');
        foreach (['action'=>'pcoe_execution','operation'=>$operation,'order_id'=>$root->get_id(),'revision'=>$state['revision']??''] as $key=>$value) echo '<input type="hidden" name="'.esc_attr($key).'" value="'.esc_attr($value).'">';
    }
    public static function summary(\WC_Order $order): void {
        if (!current_user_can('manage_woocommerce')) return;
        try { $root=OrderExecution::root($order); $state=OrderExecution::state($root); }
        catch (\Throwable $e) { echo esc_html__('Check order links','pc-order-import-export'); return; }
        if (!$state) { echo esc_html__('Execution control not started','pc-order-import-export'); return; }
        echo esc_html(self::name((int)$state['manager']));
        if (empty($state['roadmap']['closed_at'])) echo '<br>'.esc_html(OrderExecutionRoadmap::next_label($state)?:__('Specify the next action','pc-order-import-export'));
        if (!empty($state['roadmap']['closed_at'])) echo '<br><strong>'.esc_html__('Execution roadmap closed','pc-order-import-export').'</strong>';
        if (!empty($state['due'])) echo '<br>'.esc_html(self::time($state['due']));
        if (!empty($state['due']) && strtotime($state['due'])<time()) echo '<br><strong>'.esc_html__('Deadline passed — review required','pc-order-import-export').'</strong>';
        if (empty($state['active'])) echo '<br>'.esc_html__('Monitoring paused','pc-order-import-export');
    }
    public static function render(\WC_Order $order): void {
        if (!current_user_can('manage_woocommerce')) return;
        echo '<section id="pcoe-execution" class="pcoe-card">';
        echo '<h2>'.esc_html__('Order execution control','pc-order-import-export').'</h2>';
        ManagerHelp::link('execution-control');
        try { $root=OrderExecution::root($order); ManagerWorkspace::customer($root->get_customer_id()); $state=OrderExecution::state($root); }
        catch (\Throwable $e) { echo '<p>'.esc_html__('Check order links','pc-order-import-export').'</p></section>'; return; }
        if ($root->get_id()!==$order->get_id()) echo '<p><a href="'.esc_url(ManagerWorkspace::url($root->get_customer_id(),$root->get_id()).'#pcoe-execution').'">'.esc_html(sprintf(__('Shared execution card for order #%s','pc-order-import-export'),$root->get_order_number())).'</a></p>';
        echo '<p>'.esc_html__('Internal supervision. Folio documents, carrier handover and customer acceptance are separate facts. No payments, documents, messages or bonuses are created here.','pc-order-import-export').'</p>';
        if ($state && ($state['customer']!==$root->get_customer_id() || $state['folio']!==OrderExecution::context($root))) {
            echo '<p class="notice notice-error">'.esc_html__('Customer or Folio mapping changed. Monitoring is blocked; ask an administrator to reconcile the execution card.','pc-order-import-export').'</p></section>'; return;
        }
        $defaults=CustomerManagers::defaults($root->get_customer_id()); $manager=(int)($state['manager']??$defaults[0]);
        echo '<h3>'.esc_html__('Record current operation','pc-order-import-export').'</h3><p>'.esc_html__('Record the action performed and its result here. Plan the next task separately below.','pc-order-import-export').'</p>';
        self::form($root,$state,'record_operation');echo '<div class="pcoe-execution-fields">';
        if (!$state) CustomerManagers::select('manager',__('Responsible manager for this order','pc-order-import-export'),$manager?:get_current_user_id());
        OrderExecutionOperationsUi::fields($state);
        echo '</div><button type="submit" class="button button-primary">'.esc_html__('Record operation','pc-order-import-export').'</button></form>';
        OrderExecutionOperationsUi::latest($state);
        echo '<h3>'.esc_html__('Plan next action','pc-order-import-export').'</h3><p>'.esc_html__('Planning assigns the next task; it does not document a performed operation.','pc-order-import-export').'</p>';
        self::form($root,$state,'save');
        echo '<div class="pcoe-execution-fields">';
        if (!$state) CustomerManagers::select('manager',__('Responsible manager for this order','pc-order-import-export'),$manager);
        else echo '<p>'.esc_html__('Responsible manager for this order','pc-order-import-export').': <strong>'.esc_html(self::name($manager)).'</strong><input type="hidden" name="manager" value="'.esc_attr($manager).'"></p>';
        OrderExecutionRoadmapUi::fields($state);
        echo '<label>'.esc_html__('Agreed deadline','pc-order-import-export').'<input type="datetime-local" name="due" value="'.esc_attr(!empty($state['due'])?wp_date('Y-m-d\TH:i',strtotime($state['due'])):'').'"></label>';
        echo '<label>'.esc_html__('Reason or evidence for this change','pc-order-import-export').'<textarea name="reason" required maxlength="1000"></textarea></label></div>';
        if (!$state) echo '<p><label><input type="checkbox" name="active" value="1" checked> '.esc_html__('Monitor linked sources every five minutes','pc-order-import-export').'</label></p>';
        else echo '<input type="hidden" name="active" value="'.(!empty($state['active'])?'1':'0').'">';
        echo '<button type="submit" class="button button-primary">'.esc_html__('Save next action','pc-order-import-export').'</button></form>';
        OrderExecutionRoadmapUi::roadmap($state);
        echo '<p>'.esc_html__('Responsibility is fixed for this order. An administrator must approve a transfer. Moving the deadline keeps the original date and history; it does not approve an exception.','pc-order-import-export').'</p>';
        if (!$state) { echo '</section>'; return; }
        echo '<details><summary>'.esc_html__('Responsible manager and source checks','pc-order-import-export').'</summary>';
        self::form($root,$state,'configure');
        if (current_user_can('manage_options')) CustomerManagers::select('manager',__('Responsible manager for this order','pc-order-import-export'),$manager);
        else echo '<input type="hidden" name="manager" value="'.esc_attr($manager).'">';
        echo '<label><input type="checkbox" name="active" value="1" '.checked(!empty($state['active']),true,false).'> '.esc_html__('Monitor linked sources every five minutes','pc-order-import-export').'</label>';
        echo '<label>'.esc_html__('Reason or evidence for this change','pc-order-import-export').'<textarea name="reason" required maxlength="1000"></textarea></label>';
        echo '<button class="button">'.esc_html__('Save responsibility and monitoring','pc-order-import-export').'</button></form></details>';
        echo '<p>'.esc_html__('Original deadline','pc-order-import-export').': '.esc_html(self::time($state['original_due']??'')).' · '.esc_html__('Last source check','pc-order-import-export').': '.esc_html(self::time($state['checked']??'')).'</p>';
        $warnings=[];
        if (!$manager || !user_can($manager,'manage_woocommerce')) $warnings[]=__('Assign an available responsible manager','pc-order-import-export');
        if (empty($state['roadmap']['current']) && empty($state['roadmap']['closed_at'])) $warnings[]=__('Choose a roadmap stage for the next action','pc-order-import-export');
        if (empty($state['due']) && empty($state['roadmap']['closed_at'])) $warnings[]=__('Specify an agreed deadline','pc-order-import-export');
        elseif (!empty($state['due']) && strtotime($state['due'])<time()) $warnings[]=__('Deadline passed — review required','pc-order-import-export');
        if (empty($state['active'])) $warnings[]=__('Monitoring paused','pc-order-import-export');
        if (!empty($state['storage_error'])) $warnings[]=__('Order data could not be saved. Contact an administrator; do not repeat document creation.','pc-order-import-export');
        if (!empty($state['source_error']) || empty($state['checked']) || strtotime($state['checked'])<time()-900) $warnings[]=__('Sources are unavailable or stale. Last known data is shown; no cancellation or completion is inferred.','pc-order-import-export');
        if (!function_exists('as_has_scheduled_action') || (!empty($state['active']) && !as_has_scheduled_action(OrderExecution::JOB,[$root->get_id()],OrderExecution::GROUP))) $warnings[]=__('Background monitoring is not scheduled. Save the card again or contact an administrator.','pc-order-import-export');
        if (empty($state['observed']['refs']) && empty($state['links'])) $warnings[]=__('No verified document links yet','pc-order-import-export');
        if (empty($state['observed']['shipments'])) $warnings[]=__('No saved shipment in the linked orders; check delivery or pickup','pc-order-import-export');
        echo '<h3>'.esc_html__('Requires attention','pc-order-import-export').'</h3><ul>';
        foreach ($warnings as $warning) echo '<li>'.esc_html($warning).'</li>';
        echo '<li>'.esc_html__('Stage completion is a manager review record. Check the referenced documents and paper records separately; a roadmap mark does not create or sign them.','pc-order-import-export').'</li></ul>';
        if (!empty($state['active'])) {
            self::form($root,$state,'refresh'); echo '<button class="button">'.esc_html__('Check linked sources now','pc-order-import-export').'</button></form>';
        }
        echo '<p>'.esc_html__('Each check reads one linked Folio document in rotation and the saved Woo/NP data. This does not poll Nova Poshta directly. New unlinked documents require a verified manual link.','pc-order-import-export').'</p>';
        $observed=$state['observed']??[];
        echo '<h3>'.esc_html__('Related orders and confirmations','pc-order-import-export').'</h3><ul>';
        foreach ($observed['orders']??[] as $id=>$row) {
            echo '<li><a href="'.esc_url(ManagerWorkspace::url($root->get_customer_id(),(int)$id)).'">#'.esc_html($id).'</a> — '.esc_html(wc_get_order_status_name($row['status'])).' · '.esc_html($row['total'].' '.$row['currency']);
            echo ' · '.esc_html(!empty($observed['approvals'][$id])?__('Confirmation exists — open it to verify the current version','pc-order-import-export'):__('No separate customer confirmation recorded','pc-order-import-export'));
            echo ' <a href="'.esc_url(CustomerApproval::url(['order_id'=>$id])).'">'.esc_html__('Customer confirmation','pc-order-import-export').'</a></li>';
        }
        echo '</ul><p>'.esc_html__('Woo payment status is shown in the order. It is not a reconciliation of payments or the customer balance in Folio.','pc-order-import-export').'</p>';
        echo '<h3>'.esc_html__('Linked Folio documents','pc-order-import-export').'</h3><ul>';
        foreach (array_replace($observed['refs']??[],$state['links']??[]) as $key=>$ref) {
            $doc=$state['documents'][$key]??[]; $value=$doc['value']??[];
            echo '<li><strong>'.esc_html(self::document_key($key)).'</strong> · '.esc_html($value['documentNumber']??'—').' · '.esc_html($value['totalAmount']??'—').' · '.esc_html(self::time($doc['checked']??''));
            if (!empty($doc['error']) || !$value || empty($doc['checked']) || strtotime($doc['checked'])<time()-900) echo ' — <strong>'.esc_html__('Not verified; do not infer deletion','pc-order-import-export').'</strong>';
            echo '</li>';
        }
        echo '</ul>';
        if (!empty($state['links'])) {
            echo '<details><summary>'.esc_html__('Remove a manual supervision link','pc-order-import-export').'</summary>';
            self::form($root,$state,'unlink');
            echo '<select name="key">'; foreach ($state['links'] as $key=>$ref) echo '<option value="'.esc_attr($key).'">'.esc_html($key).'</option>';
            echo '</select><label>'.esc_html__('Reason or evidence for this change','pc-order-import-export').'<textarea name="reason" required maxlength="1000"></textarea></label><button class="button">'.esc_html__('Remove link only; keep documents and history','pc-order-import-export').'</button></form></details>';
        }
        echo '<details><summary>'.esc_html__('Link an existing Folio document','pc-order-import-export').'</summary>';
        echo '<p>'.esc_html__('Enter the internal document ID, not its printed number. The customer is checked against Folio. This link is for supervision and does not create or alter accounting documents.','pc-order-import-export').'</p>';
        self::form($root,$state,'link');
        echo '<label>'.esc_html__('Document type','pc-order-import-export').'<select name="type">';
        foreach (['ACCOUNT'=>__('Account','pc-order-import-export'),'EXPENSE'=>__('Expense document','pc-order-import-export'),'PAYMENT'=>__('Payment','pc-order-import-export')] as $type=>$label) echo '<option value="'.esc_attr($type).'">'.esc_html($label).'</option>';
        echo '</select></label><label>'.esc_html__('Document ID','pc-order-import-export').'<input type="number" min="1" name="document_id" required></label><label>'.esc_html__('Reason or evidence for this change','pc-order-import-export').'<textarea name="reason" required maxlength="1000"></textarea></label>';
        echo '<button class="button">'.esc_html__('Verify customer and link document','pc-order-import-export').'</button></form></details>';
        echo '<h3>'.esc_html__('Saved shipments','pc-order-import-export').'</h3><ul>';
        foreach ($observed['shipments']??[] as $row) {
            echo '<li>#'.esc_html($row['order']).' · '.esc_html($row['ttn']?:__('No TTN yet','pc-order-import-export')).' · '.esc_html(self::shipment_status($row['status']));
            $t=$row['tracking']??[];
            if (!empty($t['checked_at'])) echo ' · '.esc_html(wp_date('d.m.Y H:i',(int)$t['checked_at'])).' · '.esc_html($t['text']??'');
            else echo ' · '.esc_html__('Tracking has not been checked yet.','pc-order-import-export');
            echo '</li>';
        }
        echo '</ul><h3>'.esc_html__('Execution history','pc-order-import-export').'</h3>';
        $events=array_reverse($state['events']??[]); $page=max(1,absint($_GET['execution_page']??1));
        foreach (array_slice($events,($page-1)*20,20) as $event) {
            echo '<details><summary>'.esc_html(self::time($event['time']).' · '.($event['actor']?self::name($event['actor']):__('Source observation','pc-order-import-export')).' · '.self::event_label($event['type'])).'</summary>';
            $details=$event['details'];
            if (!empty($details['reason'])) echo '<p>'.nl2br(esc_html($details['reason'])).'</p>';
            if (!empty($details['stage_update'])) OrderExecutionRoadmapUi::event($details['stage_update']);
            if (!empty($details['operation'])) OrderExecutionOperationsUi::event($details['operation']);
            if (isset($details['key'])) echo '<p>'.esc_html(self::document_key($details['key'])).'</p>';
            foreach (['before'=>__('Before','pc-order-import-export'),'after'=>__('After','pc-order-import-export'),'value'=>__('Recorded document','pc-order-import-export')] as $field=>$label) if (!empty($details[$field])) {
                echo '<p><strong>'.esc_html($label).'</strong></p>'; self::event_value($details[$field]);
            }
            echo '</details>';
        }
        $base=ManagerWorkspace::url($root->get_customer_id(),$root->get_id());
        if ($page>1) echo '<a class="button" href="'.esc_url(add_query_arg('execution_page',$page-1,$base).'#pcoe-execution').'">'.esc_html__('Previous page','pc-order-import-export').'</a> ';
        if (count($events)>$page*20) echo '<a class="button" href="'.esc_url(add_query_arg('execution_page',$page+1,$base).'#pcoe-execution').'">'.esc_html__('Next page','pc-order-import-export').'</a>';
        echo '</section>';
    }
    public static function event_label(string $type): string {
        return ['started'=>__('Control started','pc-order-import-export'),'assignment'=>__('Responsibility or next action updated','pc-order-import-export'),'operation_recorded'=>__('Operation recorded','pc-order-import-export'),
            'sources_changed'=>__('Saved order or shipment data changed','pc-order-import-export'),'document_linked'=>__('Document linked by manager','pc-order-import-export'),'document_unlinked'=>__('Manual supervision link removed','pc-order-import-export'),
            'document_observed'=>__('Folio document read or changed','pc-order-import-export'),'document_unavailable'=>__('Folio document could not be verified','pc-order-import-export'),
            'storage_unavailable'=>__('Order data could not be saved','pc-order-import-export'),'source_unavailable'=>__('Sources unavailable','pc-order-import-export')][$type]??__('Requires review','pc-order-import-export');
    }
    public static function document_key(string $key): string {
        [$type,$id]=array_pad(explode(':',$key,2),2,'');
        return (['ACCOUNT'=>__('Account','pc-order-import-export'),'EXPENSE'=>__('Expense document','pc-order-import-export'),'PAYMENT'=>__('Payment','pc-order-import-export')][$type]??$type).' · ID '.$id;
    }
    public static function shipment_status(string $status): string {
        if ($status==='np_created') return __('TTN created; handover is not confirmed.','paint-nova-poshta-multishipping');
        if ($status==='approved') return __('Approved by the warehouse','paint-nova-poshta-multishipping');
        if ($status==='submitted') return __('Submitted by customer; awaiting warehouse review','paint-nova-poshta-multishipping');
        return __('Open shipment details to review its preparation or error','pc-order-import-export');
    }
    private static function event_value(array $data): void {
        echo '<ul>';
        if (array_key_exists('manager',$data)) echo '<li>'.esc_html(self::name((int)$data['manager']).' · '.self::time($data['due']??'').' · '.($data['next']??'')).'</li>';
        if (isset($data['documentId'])) echo '<li>'.esc_html(($data['documentType']??'').' #'.($data['documentNumber']??$data['documentId']).' · '.($data['totalAmount']??'')).'</li>';
        foreach ($data['items']??[] as $item) echo '<li>'.esc_html(($item['sku']??'').' · '.($item['quantity']??'').' × '.($item['price']??'').' = '.($item['amount']??'')).'</li>';
        if (array_key_exists('active',$data)) echo '<li>'.esc_html($data['active']?__('Monitoring active','pc-order-import-export'):__('Monitoring paused','pc-order-import-export')).'</li>';
        if (array_key_exists('accounted',$data)) echo '<li>'.esc_html($data['accounted']?__('Included in accounting','pc-order-import-export'):__('Not included in accounting','pc-order-import-export')).'</li>';
        foreach ($data['orders']??[] as $id=>$row) {
            echo '<li>#'.esc_html($id).' · '.esc_html(wc_get_order_status_name($row['status']).' · '.$row['total'].' '.$row['currency']).'<ul>';
            foreach ($row['lines']??[] as $line) echo '<li>'.esc_html(($line['sku']?:($line['name']??$line['id'])).' · '.$line['quantity'].' · '.$line['total']).'</li>';
            echo '</ul></li>';
        }
        foreach ($data['approvals']??[] as $id=>$row) echo '<li>'.esc_html(__('Customer confirmation','pc-order-import-export').' #'.$id.' · '.($row['confirmed_at']??'' )).'</li>';
        foreach ($data['shipments']??[] as $row) echo '<li>'.esc_html(($row['ttn']?:'—').' · '.self::shipment_status($row['status'])).'</li>';
        echo '</ul>';
    }
}
