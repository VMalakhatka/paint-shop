<?php
namespace Paint\NovaPoshta\Infrastructure;
defined('ABSPATH') || exit;

/** Verified CarCallGeneral contract. Every mutation is explicit and single-attempt. */
final class CourierPickup
{
    public function __construct(private readonly ApiClient $api = new ApiClient()) {}
    private static function fail(): never { throw new \RuntimeException(__('Courier data changed or could not be verified. Check the selection and request a new preview.','paint-nova-poshta-multishipping')); }
    private static function access(): void { if (!ManagerPreparation::allowed()) throw new \RuntimeException(__('Shipment access denied.','paint-nova-poshta-multishipping')); }

    /** Enrollment is an administrator attestation, not an inferred API capability. */
    public function enabled(string $sender): bool
    {
        $entry=((array)get_option('pnpm_courier_enrollment',[]))[$sender] ?? [];
        return isset($entry['account']) && hash_equals($this->api->accountFingerprint(),(string)$entry['account']);
    }

    public function requireEnrollment(string $sender): void
    {
        if (!$this->enabled($sender)) throw new \RuntimeException(__('Confirm courier service activation and disabled automatic individual calls for this sender in the courier queue settings.','paint-nova-poshta-multishipping'));
    }

    public function enroll(string $sender,bool $enabled): void
    {
        self::access();
        if (!current_user_can('manage_options') || !ShipmentLifecycle::ref($sender)) self::fail();
        $known=false;
        foreach ((array)get_option('pnpm_location_mappings',[]) as $mapping) if (($mapping['counterparty_ref'] ?? '')===$sender) $known=true;
        if (!$known) self::fail();
        $this->locked(function () use ($sender,$enabled) {
            $entries=(array)get_option('pnpm_courier_enrollment',[]);
            if ($enabled) $entries[$sender]=['account'=>$this->api->accountFingerprint(),'actor'=>get_current_user_id(),'time'=>current_time('mysql',true)];
            else unset($entries[$sender]);
            update_option('pnpm_courier_enrollment',$entries,false);
            CourierStorage::event(0,$enabled?'enrollment_enabled':'enrollment_disabled',['sender'=>$sender]);
        });
    }

    private function locked(callable $work)
    {
        self::access(); global $wpdb;
        $lock='pnpm_courier:'.substr(hash('sha256',$wpdb->prefix),0,40);
        if ((string)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,0)',$lock))!=='1') throw new \RuntimeException(__('Another courier operation is running. Wait until it finishes.','paint-nova-poshta-multishipping'));
        try { return $work(); } finally { $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock)); }
    }

    private function data($response): array
    {
        if (is_wp_error($response) || ($response['success'] ?? null)!==true || !empty($response['errors']) || !is_array($response['data'] ?? null)) self::fail();
        return $response['data'];
    }

    public static function date(string $date): string
    {
        $zone=new \DateTimeZone('Europe/Kyiv');
        $parsed=\DateTimeImmutable::createFromFormat('!Y-m-d',$date,$zone);
        $today=new \DateTimeImmutable('today',$zone);
        if (!$parsed || $parsed->format('Y-m-d')!==$date || $parsed<$today || $parsed>$today->modify('+30 days')) self::fail();
        return $parsed->format('d.m.Y');
    }

    /** Exact created snapshots only. Never infer an address or include external TTNs. */
    public function selection(array $ids): array
    {
        self::access(); global $wpdb;
        if (!$ids || count($ids)>50) self::fail();
        $ids=array_values(array_unique(array_map('absint',$ids))); sort($ids);
        if (!$ids[0]) self::fail();
        $rows=$wpdb->get_results("SELECT * FROM {$wpdb->prefix}pnpm_shipments WHERE id IN (".implode(',',$ids).') ORDER BY id',ARRAY_A);
        if (count($rows)!==count($ids)) self::fail();
        $groups=CourierQueue::project($rows,true);
        if (count($groups)!==1 || count($groups[0]['rows'])!==count($ids) || !$groups[0]['complete'] || !$groups[0]['totals_known']) self::fail();
        $group=$groups[0];
        $this->requireEnrollment($group['sender']['counterparty_ref']);
        foreach ($rows as $row) {
            if ($wpdb->get_var($wpdb->prepare("SELECT call_id FROM {$wpdb->prefix}pnpm_courier_members WHERE shipment_id=%d",$row['id']))) {
                throw new \RuntimeException(__('A selected TTN already belongs to a courier operation. Check the call history; do not create another call.','paint-nova-poshta-multishipping'));
            }
            $request=json_decode($row['request_snapshot'],true);
            if (!hash_equals($this->api->accountFingerprint(),(string)($request['courier_account'] ?? ''))) self::fail();
            (new ManagerPreparation())->order((int)$row['order_id']);
        }
        return ['ids'=>$ids,'sender'=>$group['sender'],'weight'=>round($group['weight'],3),'places'=>$group['places'],
            'ttns'=>array_column($rows,'ttn_number'),'hash'=>hash('sha256',wp_json_encode($rows))];
    }

    public function intervals(array $ids,string $date): array
    {
        $selection=$this->selection($ids);
        return $this->readIntervals($selection['sender']['city_ref'],$date);
    }

    private function readIntervals(string $city,string $date): array
    {
        $data=$this->data($this->api->call('CommonGeneral','getPickupTimeIntervals',['SenderCityRef'=>$city,'DateTime'=>self::date($date)]));
        $slots=[];
        foreach ($data as $row) {
            if (!is_array($row) || !preg_match('/^[A-Za-z0-9_-]{1,64}$/D',(string)($row['Number'] ?? ''))
                || !preg_match('/^\d{2}:\d{2}$/D',(string)($row['Start'] ?? '')) || !preg_match('/^\d{2}:\d{2}$/D',(string)($row['End'] ?? ''))) self::fail();
            $end=\DateTimeImmutable::createFromFormat('!Y-m-d H:i',$date.' '.$row['End'],new \DateTimeZone('Europe/Kyiv'));
            if ($end && $end->getTimestamp()>time()) $slots[$row['Number']]=['number'=>$row['Number'],'label'=>$row['Start'].' – '.$row['End']];
        }
        return array_values($slots);
    }

    public function preview(array $ids,string $date,string $interval,string $weight): array
    {
        return $this->locked(function () use ($ids,$date,$interval,$weight) {
            CourierStorage::engine();
            $s=$this->selection($ids);
            $weight=str_replace(',','.',$weight);
            if (!is_numeric($weight) || !is_finite((float)$weight) || (float)$weight<=0 || (float)$weight<$s['weight'] || (float)$weight>100000) self::fail();
            $slots=$this->readIntervals($s['sender']['city_ref'],$date);
            $slot=array_values(array_filter($slots,static fn($slot)=>$slot['number']===$interval));
            if (count($slot)!==1) self::fail();
            $payload=['ContactSenderRef'=>$s['sender']['contact_ref'],'PreferredDeliveryDate'=>self::date($date),
                'PlanedWeight'=>(string)(float)$weight,'TimeInterval'=>$interval,'CounterpartySender'=>$s['sender']['counterparty_ref'],
                'AddressSenderRef'=>$s['sender']['sender_address_ref']];
            // Same physical pickup/time is one operation, even with a new preview or disjoint TTNs.
            $pickupKey=hash('sha256',wp_json_encode([$this->api->accountFingerprint(),$payload['CounterpartySender'],$payload['AddressSenderRef'],$date,$interval]));
            global $wpdb;
            if ($wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}pnpm_courier_calls WHERE pickup_key=%s",$pickupKey))) throw new \RuntimeException(__('A courier operation already exists for this address and interval. Check the call history.', 'paint-nova-poshta-multishipping'));
            $token=bin2hex(random_bytes(24));
            $cache=['user'=>get_current_user_id(),'account'=>$this->api->accountFingerprint(),'expires'=>time()+300,
                'pickup_key'=>$pickupKey,'selection'=>$s,'payload'=>$payload,'date'=>$date,'slot'=>$slot[0]];
            if (!set_transient('pnpm_pickup_'.$token,$cache,300)) self::fail();
            return ['token'=>$token,'date'=>$date,'time'=>$slot[0]['label'],'weight'=>(float)$weight,'places'=>$s['places'],
                'address'=>$s['sender']['sender_address_label'] ?? '', 'phone'=>$s['sender']['phone'],'ttns'=>$s['ttns'],'enabled'=>$this->api->writesEnabled()];
        });
    }

    public function create(string $token): array
    {
        return $this->locked(function () use ($token) {
            global $wpdb;
            if (!preg_match('/^[a-f0-9]{48}$/D',$token)) self::fail();
            CourierStorage::engine();
            $existing=$wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}pnpm_courier_calls WHERE token_hash=%s",hash('sha256',$token)));
            if ($existing) return $this->owned((int)$existing);
            $cache=get_transient('pnpm_pickup_'.$token);
            if (!is_array($cache) || $cache['user']!==get_current_user_id() || $cache['expires']<time()
                || !hash_equals($cache['account'],$this->api->accountFingerprint()) || !$this->api->writesEnabled()) self::fail();
            $s=$this->selection($cache['selection']['ids']);
            if (!hash_equals($cache['selection']['hash'],$s['hash'])) self::fail();
            $slots=$this->readIntervals($s['sender']['city_ref'],$cache['date']);
            if (!in_array($cache['slot'],$slots,true)) self::fail();
            $id=CourierStorage::transaction(function () use ($wpdb,$token,$cache,$s) {
                $now=current_time('mysql',true);
                if ($wpdb->insert($wpdb->prefix.'pnpm_courier_calls',['token_hash'=>hash('sha256',$token),'account_hash'=>$cache['account'],
                    'pickup_key'=>$cache['pickup_key'],'status'=>'creating','request_snapshot'=>wp_json_encode($cache),'response_snapshot'=>'{}','actor_id'=>get_current_user_id(),
                    'created_at'=>$now,'updated_at'=>$now])!==1) CourierStorage::fail();
                $id=(int)$wpdb->insert_id;
                foreach ($s['ids'] as $i=>$shipment) if ($wpdb->insert($wpdb->prefix.'pnpm_courier_members',['shipment_id'=>$shipment,'call_id'=>$id,'ttn'=>$s['ttns'][$i]])!==1) CourierStorage::fail();
                CourierStorage::event($id,'creating',['ttns'=>$s['ttns']]);
                return $id;
            });
            delete_transient('pnpm_pickup_'.$token);
            $response=$this->api->call('CarCallGeneral','saveCourierCall',$cache['payload'],true);
            $result=['create'=>self::safeResponse($response)];
            $number=is_array($response) ? ($response['data'][0]['Barcode'] ?? '') : '';
            if (!is_array($response) || ($response['success'] ?? null)!==true || !empty($response['errors']) || !empty($response['warnings'])
                || count((array)($response['data'] ?? []))!==1 || !self::number($number)) {
                // Keep a returned number for read-only reconciliation, but never proceed on an ambiguous envelope.
                return CourierStorage::change($id,'create_unconfirmed',$result,self::number($number)?$number:null);
            }
            // Persist the number before the second remote write. Any crash stays locked.
            CourierStorage::change($id,'adding_documents',$result,$number);
            $added=$this->api->call('CarCallGeneral','insertDocuments',['LinkedDocuments'=>$s['ttns'],'Number'=>$number],true);
            $result['insert']=self::safeResponse($added);
            $ok=self::insertConfirmed($added,$number,$s['ttns']);
            return CourierStorage::change($id,$ok?'documents_added':'documents_unconfirmed',$result);
        });
    }

    public static function number($value): bool { return is_string($value) && (bool)preg_match('/^\d{1,6}-\d{1,20}$/D',$value); }
    public static function insertConfirmed($response,string $number,array $ttns): bool
    {
        if (!is_array($response) || ($response['success'] ?? null)!==true || !empty($response['errors']) || !empty($response['warnings'])
            || ($response['data'][0]['Barcode'] ?? '')!==$number || !is_array($response['data'][0]['succeeded'] ?? null)) return false;
        $actual=array_map('strval',$response['data'][0]['succeeded']); sort($actual); sort($ttns);
        return $actual===$ttns;
    }
    private static function safeResponse($response): array
    {
        return is_wp_error($response) ? ['transport_error'=>$response->get_error_code()] : (array)$response;
    }
    private function owned(int $id): array
    {
        self::access(); $row=CourierStorage::record($id);
        if (!hash_equals($row['account_hash'],$this->api->accountFingerprint())) self::fail();
        return $row;
    }

    /** Reading cannot unlock or repeat a mutation, including after a seven-day list gap. */
    public function refresh(int $id): array
    {
        return $this->locked(function () use ($id) {
            $row=$this->owned($id);
            if (!self::number($row['barcode'])) throw new \RuntimeException(__('The call number was not confirmed. Reconcile it in the Nova Poshta account with an administrator; do not create another call.','paint-nova-poshta-multishipping'));
            $calls=$this->data($this->api->call('CarCallGeneral','getOrdersListCourierCall'));
            $found=array_values(array_filter($calls,static fn($call)=>is_array($call) && ($call['Number'] ?? '')===$row['barcode']));
            if (count($found)>1) self::fail();
            $documents=[]; $complete=false;
            for ($page=1;$page<=20;$page++) {
                $data=$this->data($this->api->call('InternetDocumentGeneral','getEWTemplateList',[
                    'Page'=>(string)$page,'Limit'=>'100','PickupNumber'=>$row['barcode'],'State'=>'00000000-0000-0000-0000-000000000000','IsPickupTable'=>'true']));
                if (!$data) { $complete=true; break; }
                foreach ($data as $doc) {
                    $ttn=(string)($doc['IntDocNumber'] ?? $doc['Number'] ?? '');
                    if (!preg_match('/^\d{14}$/D',$ttn) || isset($documents[$ttn])) self::fail();
                    $documents[$ttn]=$doc;
                }
            }
            if (!$complete) self::fail();
            $request=json_decode($row['request_snapshot'],true);
            $expected=$request['selection']['ttns']; sort($expected);
            $actual=array_map('strval',array_keys($documents)); sort($actual);
            $result=json_decode($row['response_snapshot'],true) ?: [];
            $result['read']=['at'=>current_time('mysql',true),'call'=>$found[0] ?? null,'documents'=>array_values($documents),
                'matches'=>$expected===$actual,'missing'=>array_values(array_diff($expected,$actual)),'extra'=>array_values(array_diff($actual,$expected))];
            return CourierStorage::change($id,'checked',$result);
        });
    }
}
