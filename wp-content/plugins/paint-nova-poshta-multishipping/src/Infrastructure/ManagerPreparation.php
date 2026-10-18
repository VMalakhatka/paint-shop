<?php
namespace Paint\NovaPoshta\Infrastructure;

use Paint\NovaPoshta\Domain\AllocationSnapshotBuilder;
use Paint\NovaPoshta\Domain\DeliveryPolicy;

defined('ABSPATH') || exit;

/** Order-only preparations. Deliberately has no carrier write or notification transport. */
final class ManagerPreparation
{
    public const STATUS = 'manager_draft';

    public static function allowed(): bool
    {
        return current_user_can('manage_woocommerce') && current_user_can('manage_pnpm_shipments');
    }

    public function order(int $id): \WC_Order
    {
        if (!self::allowed()) $this->fail(__('Shipment access denied.', 'paint-nova-poshta-multishipping'));
        $order = wc_get_order($id);
        if (!$order instanceof \WC_Order || $order instanceof \WC_Order_Refund) $this->fail(__('Order unavailable.', 'paint-nova-poshta-multishipping'));
        return $order;
    }

    public function context(\WC_Order $order): array
    {
        if (!self::allowed()) $this->fail(__('Shipment access denied.', 'paint-nova-poshta-multishipping'));
        // Children must never create a second plan for the same physical goods.
        if ($order->get_meta('_folio_parent_order_id') || $order->get_meta('_folio_split_from_order_id')) {
            $this->fail(__('Open the original order to prepare shipments for all warehouses.', 'paint-nova-poshta-multishipping'));
        }
        if ($order->has_status(['cancelled', 'refunded', 'failed', 'completed', 'pc-draft'])
            || $order->get_currency() !== 'UAH' || $order->get_refunds()) {
            $this->fail(__('Shipment preparation requires an active UAH order without refunds.', 'paint-nova-poshta-multishipping'));
        }
        $plan = (new AllocationSnapshotBuilder())->build($order);
        if ($plan['errors'] || !$plan['shipments']) $this->fail(__('Check the saved warehouse allocation before preparing shipments.', 'paint-nova-poshta-multishipping'));
        $rows = (new ShipmentRepository())->findByOrder($order->get_id());
        $saved = [];
        foreach ($rows as $row) {
            if ($row['source'] !== 'store_api' || $row['status'] !== self::STATUS || $row['ttn_number'] || $row['np_ref']) {
                $this->fail(__('This order already has external or locked shipments. Preparation cannot replace them.', 'paint-nova-poshta-multishipping'));
            }
            $location = (int)$row['location_id'];
            if (isset($saved[$location])) $this->fail(__('Check the saved warehouse allocation before preparing shipments.', 'paint-nova-poshta-multishipping'));
            $saved[$location] = $row;
        }
        $locations = array_column($plan['shipments'], 'location_id');
        if (array_diff(array_keys($saved), $locations)) $this->fail(__('Saved shipment warehouses changed. Administrator review is required.', 'paint-nova-poshta-multishipping'));

        $mappings = (array)get_option('pnpm_location_mappings', []);
        $recipient = (array)$order->get_meta('_pnpm_recipient');
        $recipient['name'] = trim($order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name())
            ?: trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name());
        $recipient['phone'] = $order->get_shipping_phone() ?: $order->get_billing_phone();
        $recipient['address'] = trim($order->get_shipping_address_1() . ' ' . $order->get_shipping_address_2());
        $recipient['city_label'] = $recipient['city_label'] ?? $order->get_shipping_city();
        $groups = [];
        foreach ($plan['shipments'] as $group) {
            $location = (int)$group['location_id'];
            $old = $saved[$location] ?? [];
            $draft = json_decode((string)($old['request_snapshot'] ?? ''), true) ?: [];
            $group['sender'] = array_intersect_key((array)($mappings[$location] ?? []), array_flip([
                'enabled', 'sender_type', 'counterparty_ref', 'city_ref', 'sender_address_ref',
                'handover_warehouse_ref', 'handover_warehouse_label', 'contact_ref', 'phone', 'customer_label',
            ]));
            $group['boxes'] = $draft['boxes'] ?? [['weight'=> '', 'length'=> '', 'width'=> '', 'height'=> '']];
            $group['payer'] = $draft['payer'] ?? 'Recipient';
            $group['saved_id'] = (int)($old['id'] ?? 0);
            $groups[$location] = $group;
        }
        // Include order data and every stored row in optimistic concurrency protection.
        $policy = DeliveryPolicy::load();
        $customer = $order->get_customer_id() ? get_userdata($order->get_customer_id()) : false;
        $role = $customer ? ($customer->roles[0] ?? 'customer') : 'guest';
        $profile = ($policy['role_segments'][$role] ?? 'retail') === 'partner' ? 'partner' : 'retail';
        $context = ['order_id'=>$order->get_id(), 'groups'=>$groups, 'recipient'=>$recipient,
            'payment'=>$order->get_payment_method(), 'total'=>(float)$order->get_total(),
            'paid'=>(bool)$order->get_date_paid(), 'profile'=>$policy['profiles'][$profile],
            'quote'=>(array)$order->get_meta('_pnpm_checkout_quote')];
        $context['revision'] = hash('sha256', wp_json_encode([$context, $rows, $order->get_status(),
            $order->get_customer_id(), $order->get_address('billing'), $order->get_address('shipping')]));
        if ($saved) $context['recipient'] = json_decode((string)reset($saved)['recipient_snapshot'], true) ?: $recipient;
        return $context;
    }

    /** Bounded, typed inputs only. No posted items, sender identities or COD amounts. */
    public function prepare(array $context, array $input): array
    {
        if (!hash_equals($context['revision'], (string)($input['revision'] ?? ''))) $this->fail(__('The order or preparation changed. Reload and review it again.', 'paint-nova-poshta-multishipping'));
        if (($input['agreed'] ?? '') !== '1') $this->fail(__('Confirm that recipient details and packaging were agreed and checked.', 'paint-nova-poshta-multishipping'));
        if (!is_array($input['recipient'] ?? null)) $this->fail(__('Check recipient details and phone number.', 'paint-nova-poshta-multishipping'));
        $recipient = [];
        foreach (['name','phone','city_label','city_ref','delivery_type','point_label','point_ref','address'] as $key) {
            $raw = $input['recipient'][$key] ?? '';
            if (!is_scalar($raw) || mb_strlen((string)$raw) > 300) $this->fail(__('Check recipient details and phone number.', 'paint-nova-poshta-multishipping'));
            $recipient[$key] = sanitize_text_field((string)$raw);
        }
        $recipient['phone'] = preg_replace('/[^0-9]/', '', $recipient['phone']);
        if (strlen($recipient['phone']) === 10 && str_starts_with($recipient['phone'], '0')) $recipient['phone'] = '38' . $recipient['phone'];
        if ($recipient['name'] === '' || !preg_match('/^380\d{9}$/', $recipient['phone'])
            || !self::ref($recipient['city_ref']) || !in_array($recipient['delivery_type'], ['branch','parcel_locker','address'], true)) {
            $this->fail(__('Check recipient details and phone number.', 'paint-nova-poshta-multishipping'));
        }
        if ($recipient['delivery_type'] === 'address') {
            if ($recipient['address'] === '') $this->fail(__('Check recipient details and phone number.', 'paint-nova-poshta-multishipping'));
            $recipient['point_ref'] = $recipient['point_label'] = '';
        } else {
            if (!self::ref($recipient['point_ref'])) $this->fail(__('Check recipient details and phone number.', 'paint-nova-poshta-multishipping'));
            $recipient['address'] = '';
        }
        $groups = [];
        if (!is_array($input['groups'] ?? null) || count($input['groups']) !== count($context['groups'])) $this->fail(__('Check all warehouse parcels.', 'paint-nova-poshta-multishipping'));
        foreach ($context['groups'] as $location => $group) {
            $value = $input['groups'][$location] ?? [];
            if (!is_array($value)) $this->fail(__('Check all warehouse parcels.', 'paint-nova-poshta-multishipping'));
            if (!in_array($value['payer'] ?? '', ['Sender','Recipient'], true)) $this->fail(__('Choose the delivery payer for each warehouse.', 'paint-nova-poshta-multishipping'));
            $boxes = $value['boxes'] ?? [];
            if (!is_array($boxes) || !$boxes || count($boxes) > 20) $this->fail(__('Enter between 1 and 20 packed boxes for each warehouse.', 'paint-nova-poshta-multishipping'));
            $clean = [];
            foreach ($boxes as $box) {
                if (!is_array($box)) $this->fail(__('Enter positive box weights in kg and dimensions in cm.', 'paint-nova-poshta-multishipping'));
                $dimensions = [];
                foreach (['weight','length','width','height'] as $key) {
                    $number = $box[$key] ?? null;
                    if (!is_scalar($number)) $this->fail(__('Enter positive box weights in kg and dimensions in cm.', 'paint-nova-poshta-multishipping'));
                    $number = str_replace(',', '.', trim((string)$number));
                    if (!is_numeric($number) || !is_finite((float)$number) || (float)$number <= 0 || (float)$number > 10000) {
                        $this->fail(__('Enter positive box weights in kg and dimensions in cm.', 'paint-nova-poshta-multishipping'));
                    }
                    $dimensions[$key] = (float)$number;
                }
                $clean[] = $dimensions;
            }
            $group['boxes'] = $clean;
            $group['payer'] = $value['payer'];
            $group['cod'] = $context['payment'] === 'cod' && !$context['paid'] && count($context['groups']) === 1 ? $context['total'] : 0.0;
            $groups[$location] = $group;
        }
        return ['recipient'=>$recipient, 'groups'=>$groups];
    }

    /** A preparation preview is not a carrier quote or permission to send. */
    public function blockers(array $context, array $prepared): array
    {
        $issues = [];
        foreach ($prepared['groups'] as $group) {
            $sender = $group['sender'];
            $required = ['counterparty_ref','city_ref','contact_ref', ($sender['sender_type'] ?? '') === 'warehouse' ? 'handover_warehouse_ref' : 'sender_address_ref'];
            if (($sender['enabled'] ?? '') !== 'yes' || !in_array($sender['sender_type'] ?? '', ['warehouse','doors'], true)
                || !preg_match('/^\+?380\d{9}$/', (string)($sender['phone'] ?? ''))) $issues[] = __('Check the warehouse sender mapping and phone in Nova Poshta settings.', 'paint-nova-poshta-multishipping');
            foreach ($required as $key) if (!self::ref((string)($sender[$key] ?? ''))) $issues[] = __('Check the warehouse sender mapping and phone in Nova Poshta settings.', 'paint-nova-poshta-multishipping');
        }
        if ($context['payment'] === 'cod' && !$context['paid']) {
            if (count($prepared['groups']) > 1) $issues[] = __('Multi-warehouse COD requires an approved allocation rule; no COD will be created.', 'paint-nova-poshta-multishipping');
            elseif (($context['profile']['cod']['single_shipment'] ?? 'no') !== 'yes') $issues[] = __('COD is disabled by the customer delivery policy.', 'paint-nova-poshta-multishipping');
        }
        $issues[] = __('Delivery payer and store subsidy still require carrier-contract verification; this preview does not calculate the final tariff.', 'paint-nova-poshta-multishipping');
        $issues[] = __('TTN creation and label printing are blocked pending verification of the official carrier API contract. No shipment or notification has been sent.', 'paint-nova-poshta-multishipping');
        return array_values(array_unique($issues));
    }

    /** All warehouse preparations/items/audit events commit together under the PCOE order lock. */
    public function save(int $order_id, array $input): array
    {
        global $wpdb;
        $order = $this->order($order_id);
        $lock = 'pcoe:' . substr(hash('sha256', $wpdb->prefix . 'order:' . $order_id), 0, 48);
        if ((string)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,0)', $lock)) !== '1') $this->fail(__('Another order operation is running. Try again after it finishes.', 'paint-nova-poshta-multishipping'));
        try {
            $order = $this->order($order_id);
            $context = $this->context($order);
            $prepared = $this->prepare($context, $input);
            $names = ['pnpm_shipments','pnpm_shipment_items','pnpm_shipment_events'];
            foreach ($names as $name) {
                $engine = $wpdb->get_var($wpdb->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', $wpdb->prefix . $name));
                if (strtolower((string)$engine) !== 'innodb') $this->fail(__('Transactional shipment storage is unavailable. Nothing was saved.', 'paint-nova-poshta-multishipping'));
            }
            if ($wpdb->query('START TRANSACTION') === false) $this->fail(__('Shipment preparation could not be saved.', 'paint-nova-poshta-multishipping'));
            try {
                $now = current_time('mysql', true);
                foreach ($prepared['groups'] as $location => $group) {
                    $id = $group['saved_id'];
                    $previous = $id ? $wpdb->get_row($wpdb->prepare("SELECT recipient_snapshot, request_snapshot, sender_snapshot FROM {$wpdb->prefix}pnpm_shipments WHERE id=%d", $id), ARRAY_A) : [];
                    $previous_recipient = json_decode((string)($previous['recipient_snapshot'] ?? ''), true) ?: [];
                    $previous_draft = json_decode((string)($previous['request_snapshot'] ?? ''), true) ?: [];
                    $changed = [];
                    foreach ($prepared['recipient'] as $field=>$value) if (($previous_recipient[$field] ?? null) !== $value) $changed[] = 'recipient.' . $field;
                    foreach (['boxes','payer'] as $field) if (($previous_draft[$field] ?? null) !== $group[$field]) $changed[] = $field;
                    if (($previous['sender_snapshot'] ?? '') !== wp_json_encode($group['sender'])) $changed[] = 'sender';
                    $record = ['recipient_snapshot'=>wp_json_encode($prepared['recipient']), 'sender_snapshot'=>wp_json_encode($group['sender']),
                        'pricing_snapshot'=>wp_json_encode(['payer'=>$group['payer'],'cod'=>$group['cod'],'policy'=>$context['profile'], 'verified'=>false]),
                        'request_snapshot'=>wp_json_encode(['schema'=>'manager-preparation-v1','revision'=>wp_generate_uuid4(),'boxes'=>$group['boxes'],
                            'payer'=>$group['payer'],'order_revision'=>$context['revision']]), 'updated_at'=>$now];
                    if ($id) $ok = $wpdb->update($wpdb->prefix . 'pnpm_shipments', $record, ['id'=>$id, 'source'=>'store_api', 'status'=>self::STATUS]);
                    else {
                        $ok = $wpdb->insert($wpdb->prefix . 'pnpm_shipments', $record + ['shipment_uuid'=>wp_generate_uuid4(), 'order_id'=>$order_id,
                            'location_id'=>$location, 'source'=>'store_api', 'status'=>self::STATUS,
                            'idempotency_key'=>hash('sha256', 'manager-preparation-v1:' . $order_id . ':' . $location),
                            'capabilities'=>wp_json_encode(['prepare'=>true,'create'=>false,'print'=>false]), 'submitted_by'=>get_current_user_id(),
                            'submitted_at'=>$now,'created_at'=>$now]);
                        $id = (int)$wpdb->insert_id;
                    }
                    if ($ok !== 1 || !$id) $this->fail(__('Shipment preparation could not be saved.', 'paint-nova-poshta-multishipping'));
                    if ($wpdb->delete($wpdb->prefix . 'pnpm_shipment_items', ['shipment_id'=>$id]) === false) $this->fail(__('Shipment preparation could not be saved.', 'paint-nova-poshta-multishipping'));
                    foreach ($group['items'] as $item) {
                        $line = array_intersect_key($item, array_flip(['order_item_id','product_id','variation_id','sku','quantity','line_subtotal','line_total']));
                        if ($wpdb->insert($wpdb->prefix . 'pnpm_shipment_items', ['shipment_id'=>$id] + $line) === false) $this->fail(__('Shipment preparation could not be saved.', 'paint-nova-poshta-multishipping'));
                    }
                    if ($wpdb->insert($wpdb->prefix . 'pnpm_shipment_events', ['shipment_id'=>$id,'event_type'=>'manager_preparation_saved',
                        'actor_id'=>get_current_user_id(),'correlation_id'=>wp_generate_uuid4(), 'created_at'=>$now,
                        'safe_context'=>wp_json_encode(['previous_revision'=>$context['revision'], 'snapshot_hash'=>hash('sha256',wp_json_encode($record)),
                            'changed_fields'=>$changed, 'checked_fields'=>['recipient','boxes','payer'], 'agreed'=>true])]) === false) $this->fail(__('Shipment preparation could not be saved.', 'paint-nova-poshta-multishipping'));
                }
                if ($wpdb->query('COMMIT') === false) $this->fail(__('Shipment preparation could not be saved.', 'paint-nova-poshta-multishipping'));
            } catch (\Throwable $e) { $wpdb->query('ROLLBACK'); throw $e; }
            return $this->context($this->order($order_id));
        } finally { $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock)); }
    }

    private static function ref(string $value): bool { return (bool)preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/i', $value); }
    private function fail(string $message): never { throw new \RuntimeException($message); }
}
