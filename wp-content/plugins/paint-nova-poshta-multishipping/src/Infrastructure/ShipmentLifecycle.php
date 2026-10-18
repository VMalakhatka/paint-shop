<?php
namespace Paint\NovaPoshta\Infrastructure;

defined('ABSPATH') || exit;

/** Explicit manager commands. No background creation, retry or automatic order completion. */
final class ShipmentLifecycle
{
    public function __construct(private readonly ApiClient $api = new ApiClient()) {}

    public function row(int $order_id, int $id): array
    {
        (new ManagerPreparation())->order($order_id);
        foreach ((new ShipmentRepository())->findByOrder($order_id) as $row) {
            if ((int)$row['id'] === $id && $row['source'] === 'store_api') return $row;
        }
        throw new \RuntimeException(__('Shipment access denied.', 'paint-nova-poshta-multishipping'));
    }

    public static function ref($value): bool
    {
        return is_string($value) && $value !== '00000000-0000-0000-0000-000000000000'
            && (bool)preg_match('/^[a-f0-9]{8}(-[a-f0-9]{4}){3}-[a-f0-9]{12}$/iD', $value);
    }

    private static function fail(): never
    {
        throw new \RuntimeException(__('Shipment data could not be verified. Check the saved preparation and sender settings; no automatic retry is allowed.', 'paint-nova-poshta-multishipping'));
    }

    /** Called only in preflight, before any durable create claim or carrier write. */
    private static function checkApiAvailability($result): void
    {
        if (is_wp_error($result) && in_array($result->get_error_code(), ['pnpm_api_rate_limited','pnpm_api_busy'], true)) {
            throw new \RuntimeException(__('Nova Poshta is busy or has limited requests. No shipment creation was started. Wait 10 seconds, reload the order and check the saved shipment again.', 'paint-nova-poshta-multishipping'));
        }
    }

    /** Serialize with preparation and PCOE order edits; never hold a SQL transaction over HTTP. */
    private function locked(int $order_id, callable $work)
    {
        global $wpdb;
        $lock = 'pcoe:' . substr(hash('sha256', $wpdb->prefix . 'order:' . $order_id), 0, 48);
        if ((string)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,0)', $lock)) !== '1') {
            throw new \RuntimeException(__('Another order operation is running. Try again after it finishes.', 'paint-nova-poshta-multishipping'));
        }
        try { return $work(); }
        finally { $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock)); }
    }

    private function engine(): void
    {
        global $wpdb;
        foreach (['pnpm_shipments','pnpm_shipment_events'] as $suffix) {
            if (strtolower((string)$wpdb->get_var($wpdb->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', $wpdb->prefix.$suffix))) !== 'innodb') self::fail();
        }
    }

    private function transition(array $row, string $status, array $fields = [], array $audit = []): void
    {
        global $wpdb;
        $this->engine();
        if ($wpdb->query('START TRANSACTION') === false) self::fail();
        try {
            if ($wpdb->update($wpdb->prefix.'pnpm_shipments', $fields + ['status'=>$status,'updated_at'=>current_time('mysql', true)],
                ['id'=>$row['id'],'status'=>$row['status'],'source'=>'store_api']) !== 1) self::fail();
            if ($wpdb->insert($wpdb->prefix.'pnpm_shipment_events', ['shipment_id'=>$row['id'],'event_type'=>$status,
                'actor_id'=>get_current_user_id(),'correlation_id'=>wp_generate_uuid4(),'safe_context'=>wp_json_encode($audit),
                'created_at'=>current_time('mysql', true)]) !== 1) self::fail();
            if ($wpdb->query('COMMIT') === false) self::fail();
        } catch (\Throwable $e) { $wpdb->query('ROLLBACK'); throw $e; }
    }

    /** Known limits only; an absent bound is unknown and is explicitly disclosed in the preview. */
    public static function checkPoint(array $point, array $boxes, float $cost, string $direction): void
    {
        if (empty($point['selectable'])) self::fail();
        $weight = array_sum(array_column($boxes, 'weight'));
        if (($point['totalWeight'] ?? null) && $weight > $point['totalWeight']) self::fail();
        if (($point['declaredValue'] ?? null) && $cost > $point['declaredValue']) self::fail();
        $locker = ($point['kind'] ?? '') === 'postomat';
        if ($locker && (count($boxes) !== 1 || $weight > 20 || $cost > 29000)) self::fail();
        foreach ($boxes as $box) {
            if (($point['placeWeight'] ?? null) && $box['weight'] > $point['placeWeight']) self::fail();
            foreach (['length','width','height'] as $i=>$key) {
                $bound = $point[$direction.'Dimensions'][$i] ?? null;
                if ($bound && $box[$key] > $bound) self::fail();
                if ($locker && $box[$key] > [60,40,30][$i]) self::fail();
            }
        }
    }

    private function compile(\WC_Order $order, array $row, array $names): array
    {
        $service = new ManagerPreparation();
        $context = $service->context($order, true);
        if ($row['status'] !== ManagerPreparation::STATUS || $row['ttn_number'] || $row['np_ref']) self::fail();
        if ($context['payment'] === 'cod' && !$context['paid']) {
            throw new \RuntimeException(__('COD creation is not supported in this release. Do not change the payment method just to bypass this check.', 'paint-nova-poshta-multishipping'));
        }
        foreach ((new ShipmentRepository())->findByOrder($order->get_id()) as $other) {
            $snapshot = json_decode($other['request_snapshot'], true) ?: [];
            if (isset($snapshot['plan_hash']) && !hash_equals($snapshot['plan_hash'], $context['order_fingerprint'])) self::fail();
        }
        $group = $context['groups'][(int)$row['location_id']] ?? null;
        if (!$group || $group['saved_id'] !== (int)$row['id']) self::fail();
        $sender = $group['sender'];
        if (($sender['enabled'] ?? '') !== 'yes' || ($sender['sender_type'] ?? '') !== 'warehouse') {
            throw new \RuntimeException(__('This release supports drop-off at a branch and delivery to a branch or parcel locker only.', 'paint-nova-poshta-multishipping'));
        }
        $recipient = json_decode($row['recipient_snapshot'], true) ?: [];
        if (!in_array($recipient['delivery_type'] ?? '', ['branch','parcel_locker'], true)) self::fail();
        foreach (['counterparty_ref','city_ref','contact_ref','handover_warehouse_ref'] as $key) if (!self::ref($sender[$key] ?? null)) self::fail();
        if (!self::ref($recipient['city_ref'] ?? null) || !self::ref($recipient['point_ref'] ?? null)
            || !preg_match('/^380\d{9}$/D', (string)($recipient['phone'] ?? ''))
            || !preg_match('/^\+?380\d{9}$/D', (string)($sender['phone'] ?? ''))) self::fail();
        $person = RecipientIdentity::names($names);
        $person += ['Phone'=>$recipient['phone'],'CounterpartyType'=>'PrivatePerson','CounterpartyProperty'=>'Recipient'];
        $directory = (new SenderDirectory($this->api))->load(true);
        self::checkApiAvailability($directory);
        if (is_wp_error($directory)) self::fail();
        $owned = false;
        foreach ($directory['counterparties'] as $party) {
            if ($party['ref'] !== $sender['counterparty_ref']) continue;
            foreach ($party['contacts'] as $contact) {
                $phones = array_map(static fn($p)=>preg_replace('/\D/','',$p), preg_split('/[,;]/', $contact['phones']));
                if ($contact['ref'] === $sender['contact_ref'] && in_array(preg_replace('/\D/', '', $sender['phone']), $phones, true)) $owned = true;
            }
        }
        if (!$owned) self::fail();
        $boxes = $group['boxes'];
        if (!$boxes || count($boxes)>20 || !in_array($group['payer'], ['Sender','Recipient'], true)) self::fail();
        $seats = [];
        foreach ($boxes as $box) {
            foreach (['weight','length','width','height'] as $key) {
                if (!isset($box[$key]) || !is_numeric($box[$key]) || !is_finite((float)$box[$key]) || $box[$key]<=0 || $box[$key]>10000) self::fail();
            }
            if ($box['weight'] < 0.1) self::fail();
            $seats[] = ['weight'=>$box['weight'],'volumetricLength'=>$box['length'],'volumetricWidth'=>$box['width'],
                'volumetricHeight'=>$box['height'],'volumetricVolume'=>$box['length']*$box['width']*$box['height']/1000000];
        }
        // Discounted goods only, including their proportional tax. Shipping/fees are not goods.
        $declared = 0.0;
        foreach ($group['items'] as $allocated) {
            $item = $order->get_item($allocated['order_item_id']);
            if (!$item instanceof \WC_Order_Item_Product || $item->get_quantity()<=0) self::fail();
            $declared += (float)$allocated['line_total'] + (float)$item->get_total_tax()*$allocated['quantity']/$item->get_quantity();
        }
        $declared = max(1, (int)ceil(round($declared, 2)));
        $points = new WarehouseDirectory($this->api);
        $origin = $points->find($sender['city_ref'], $sender['handover_warehouse_ref']);
        self::checkApiAvailability($origin);
        if (is_wp_error($origin)) self::fail();
        $destination = $points->find($recipient['city_ref'], $recipient['point_ref']);
        self::checkApiAvailability($destination);
        if (is_wp_error($origin) || is_wp_error($destination) || $origin['kind'] !== 'branch'
            || $destination['kind'] !== ($recipient['delivery_type'] === 'parcel_locker' ? 'postomat' : 'branch')) self::fail();
        self::checkPoint($origin, $boxes, $declared, 'sending');
        self::checkPoint($destination, $boxes, $declared, 'receiving');
        $payload = ['PayerType'=>$group['payer'],'PaymentMethod'=>'Cash','DateTime'=>wp_date('d.m.Y', null, new \DateTimeZone('Europe/Kyiv')),
            'CargoType'=>$destination['kind'] === 'postomat' ? 'Parcel' : 'Cargo','Weight'=>array_sum(array_column($boxes,'weight')),
            'ServiceType'=>'WarehouseWarehouse','SeatsAmount'=>count($boxes),'Description'=>'Художні матеріали','Cost'=>$declared,
            'CitySender'=>$sender['city_ref'],'Sender'=>$sender['counterparty_ref'],'SenderAddress'=>$sender['handover_warehouse_ref'],
            'ContactSender'=>$sender['contact_ref'],'SendersPhone'=>$sender['phone'],
            'CityRecipient'=>$recipient['city_ref'],'RecipientAddress'=>$recipient['point_ref'],'RecipientsPhone'=>$recipient['phone'],'OptionsSeat'=>$seats];
        return ['payload'=>$payload,'person'=>$person,'context'=>$context,'origin'=>$origin['label'],'destination'=>$destination['label']];
    }

    public function preview(int $order_id, int $id, array $names): array
    {
        return $this->locked($order_id, function () use ($order_id,$id,$names) {
            $row = $this->row($order_id,$id);
            $plan = $this->compile((new ManagerPreparation())->order($order_id), $row, $names);
            $quote_fields = array_intersect_key($plan['payload'], array_flip(['CitySender','CityRecipient','Weight','ServiceType','Cost','CargoType','SeatsAmount','OptionsSeat']));
            $quote = $this->api->call('InternetDocumentGeneral', 'getDocumentPrice', $quote_fields);
            self::checkApiAvailability($quote);
            if (is_wp_error($quote) || ($quote['success'] ?? false)!==true || !is_numeric($quote['data'][0]['Cost'] ?? null)
                || !is_finite((float)$quote['data'][0]['Cost']) || $quote['data'][0]['Cost']<0) self::fail();
            $token = bin2hex(random_bytes(24));
            $cache = ['user'=>get_current_user_id(),'order'=>$order_id,'id'=>$id,'row_hash'=>hash('sha256',wp_json_encode($row)),
                'plan_hash'=>$plan['context']['order_fingerprint'],'payload'=>$plan['payload'],'person'=>$plan['person'],'expires'=>time()+300];
            if (!set_transient('pnpm_create_'.$token, $cache, 300)) self::fail();
            return ['token'=>$token,'enabled'=>$this->api->writesEnabled(), 'recipient'=>implode(' ', array_intersect_key($plan['person'], array_flip(['FirstName','LastName','MiddleName']))),
                'phone'=>$plan['person']['Phone'],'origin'=>$plan['origin'],'destination'=>$plan['destination'],
                'payload'=>array_intersect_key($plan['payload'], array_flip(['Cost','Weight','SeatsAmount','PayerType','PaymentMethod','OptionsSeat'])),
                'estimate'=>(float)$quote['data'][0]['Cost']];
        });
    }

    public function create(int $order_id, int $id, string $token): array
    {
        return $this->locked($order_id, function () use ($order_id,$id,$token) {
            $row = $this->row($order_id,$id);
            if ($row['status'] === 'np_created') return ['ttn'=>$row['ttn_number']];
            if (!$this->api->writesEnabled() || !$this->api->apiKeyConfigured()) {
                throw new \RuntimeException(__('Real Nova Poshta write operations are disabled.', 'paint-nova-poshta-multishipping'));
            }
            if (!preg_match('/^[a-f0-9]{48}$/D', $token)) self::fail();
            $cache = get_transient('pnpm_create_'.$token);
            if (!is_array($cache) || $cache['expires']<time() || $cache['user']!==get_current_user_id() || $cache['order']!==$order_id || $cache['id']!==$id
                || !hash_equals($cache['row_hash'], hash('sha256',wp_json_encode($row)))) self::fail();
            $plan = $this->compile((new ManagerPreparation())->order($order_id), $row, $cache['person']);
            if (!hash_equals($cache['plan_hash'], $plan['context']['order_fingerprint']) || $cache['payload'] !== $plan['payload']) self::fail();
            $snapshot = json_decode($row['request_snapshot'],true) ?: [];
            $snapshot += ['plan_hash'=>$cache['plan_hash'],'carrier'=>$plan['payload'],'person'=>$plan['person']];
            // A durable claim precedes BOTH writes. Process death also stays non-retryable.
            $this->transition($row, 'np_creating', ['request_snapshot'=>wp_json_encode($snapshot)], ['phase'=>'recipient','request_hash'=>hash('sha256',wp_json_encode($snapshot))]);
            $row['status']='np_creating';
            delete_transient('pnpm_create_'.$token);
            try {
                $party = $this->api->call('CounterpartyGeneral','save',$plan['person'],true);
                $recipient = self::recipientRefs($party);
                if (!$recipient) {
                    $this->transition($row,'np_unknown',[],['phase'=>'recipient']);
                    return ['unknown'=>true];
                }
                if (!RecipientIdentity::matches($party, $plan['person'])) {
                    $this->transition($row,'np_recipient_mismatch',[],['phase'=>'recipient_identity']);
                    return ['recipient_mismatch'=>true];
                }
                $payload = $plan['payload'] + $recipient;
                // Persist the exact final payload before the document write; failed persistence stops here.
                global $wpdb;
                $snapshot['carrier']=$payload;
                if ($wpdb->update($wpdb->prefix.'pnpm_shipments',['request_snapshot'=>wp_json_encode($snapshot)],['id'=>$id,'status'=>'np_creating'])!==1) self::fail();
                $response = $this->api->call('InternetDocumentGeneral','save',$payload,true);
                $data = self::createdDocument($response);
                if (!$data) {
                    $rejected = is_array($response) && ($response['success'] ?? null)===false && !empty($response['errors']) && empty($response['data']);
                    $this->transition($row, $rejected ? 'np_rejected' : 'np_unknown', [], ['phase'=>'document']);
                    return ['unknown'=>true];
                }
                $this->transition($row,'np_created',['np_ref'=>$data['Ref'],'ttn_number'=>$data['IntDocNumber'],
                    'response_snapshot'=>wp_json_encode($data),'capabilities'=>wp_json_encode(['prepare'=>false,'create'=>false,'print'=>true])],['phase'=>'document','warnings_present'=>!empty($response['warnings'])]);
                return ['ttn'=>$data['IntDocNumber']];
            } catch (\Throwable $e) {
                // Do not try another write, even when storing the result failed. The committed claim survives.
                return ['unknown'=>true];
            }
        });
    }

    public static function recipientRefs($response): ?array
    {
        if (!is_array($response) || ($response['success'] ?? false)!==true || !is_array($response['data'] ?? null) || count($response['data'])!==1) return null;
        $data=$response['data'][0];
        $contact=$data['ContactPerson'] ?? [];
        if (isset($contact[0])) { if (count($contact)!==1) return null; $contact=$contact[0]; }
        if (!is_array($contact) || ($contact['success'] ?? false)!==true || !is_array($contact['data'] ?? null) || count($contact['data'])!==1) return null;
        $ref=$contact['data'][0]['Ref'] ?? null;
        return self::ref($data['Ref'] ?? null) && self::ref($ref) ? ['Recipient'=>$data['Ref'],'ContactRecipient'=>$ref] : null;
    }

    public static function createdDocument($response): ?array
    {
        if (!is_array($response) || ($response['success'] ?? false)!==true || !is_array($response['data'] ?? null) || count($response['data'])!==1) return null;
        $data=$response['data'][0];
        if (!self::ref($data['Ref'] ?? null) || !is_string($data['IntDocNumber'] ?? null) || !preg_match('/^\d{14}$/D',$data['IntDocNumber'])) return null;
        $result=array_intersect_key($data,array_flip(['Ref','IntDocNumber','CostOnSite','EstimatedDeliveryDate','TypeDocument']));
        foreach ($result as $key=>$value) if (!is_scalar($value) || strlen((string)$value)>100) unset($result[$key]);
        return $result;
    }

    public function pdf(int $order_id, int $id, string $format): string
    {
        $row=$this->row($order_id,$id);
        if ($row['status']!=='np_created' || !self::ref($row['np_ref'])) self::fail();
        $pdf=$this->api->marking($row['np_ref'],$format);
        if (is_wp_error($pdf)) throw new \RuntimeException($pdf->get_error_message());
        return $pdf;
    }

    public function emailStatus(int $id): string
    {
        global $wpdb;
        return (string)$wpdb->get_var($wpdb->prepare("SELECT event_type FROM {$wpdb->prefix}pnpm_shipment_events WHERE shipment_id=%d AND event_type IN ('np_email_claimed','np_email_accepted','np_email_failed','np_email_unknown') ORDER BY id DESC LIMIT 1",$id));
    }

    /** At-most-once service mail, directed to the ORDER customer, never to a dropship recipient. */
    public function email(int $order_id, int $id, string $recipient_hash): string
    {
        return $this->locked($order_id, function () use ($order_id,$id,$recipient_hash) {
            global $wpdb;
            $row=$this->row($order_id,$id);
            if ($row['status']!=='np_created' || !preg_match('/^\d{14}$/D',(string)$row['ttn_number'])) self::fail();
            $order=(new ManagerPreparation())->order($order_id);
            if ($order->has_status(['cancelled','refunded','failed']) || $order->get_refunds()) self::fail();
            $email=$order->get_billing_email();
            if (!is_email($email) || !hash_equals(hash('sha256',$email),$recipient_hash)) self::fail();
            if ($status=$this->emailStatus($id)) return $status;
            $this->engine();
            $event=['shipment_id'=>$id,'event_type'=>'np_email_claimed','actor_id'=>get_current_user_id(),
                'correlation_id'=>wp_generate_uuid4(),'safe_context'=>wp_json_encode(['recipient_hash'=>hash('sha256',$email)]),'created_at'=>current_time('mysql',true)];
            if ($wpdb->query('START TRANSACTION')===false) self::fail();
            try {
                if ($wpdb->insert($wpdb->prefix.'pnpm_shipment_events',$event)!==1) self::fail();
                $event_id=(int)$wpdb->insert_id;
                if ($wpdb->query('COMMIT')===false) self::fail();
            } catch (\Throwable $e) {$wpdb->query('ROLLBACK');throw $e;}
            $locale=$order->get_customer_id() ? get_user_locale($order->get_customer_id()) : get_locale();
            switch_to_locale($locale);
            try {
                $subject=sprintf(__('Nova Poshta TTN for order #%s', 'paint-nova-poshta-multishipping'), $order->get_order_number());
                $body=sprintf(__('TTN %s has been created for your order. This does not yet confirm handover to Nova Poshta.', 'paint-nova-poshta-multishipping'),$row['ttn_number']) . "\n\n" . self::tracking($row['ttn_number']);
                $sender=json_decode($row['sender_snapshot'],true) ?: [];
                if (!empty($sender['customer_label'])) $body.="\n\n".sanitize_text_field($sender['customer_label']);
                $status=wp_mail($email,$subject,$body) ? 'np_email_accepted' : 'np_email_failed';
            } catch (\Throwable $e) { $status='np_email_unknown'; }
            finally { restore_previous_locale(); }
            if ($wpdb->update($wpdb->prefix.'pnpm_shipment_events',['event_type'=>$status],['id'=>$event_id,'event_type'=>'np_email_claimed'])!==1) return 'np_email_claimed';
            return $status;
        });
    }

    public static function tracking(string $ttn): string
    {
        return 'https://novaposhta.ua/tracking/?cargo_number='.rawurlencode($ttn);
    }
}
