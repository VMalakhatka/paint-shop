<?php
namespace Paint\NovaPoshta\Infrastructure;

defined('ABSPATH') || exit;

/** Local view of saved courier preparations. Reading never calls the carrier. */
final class CourierQueue
{
    public const PAGE_SIZE = 50;

    public function page(int $page = 1): array
    {
        if (!ManagerPreparation::allowed()) throw new \RuntimeException(__('Shipment access denied.', 'paint-nova-poshta-multishipping'));
        global $wpdb;
        $page = max(1, $page);
        $where = "source='store_api' AND status='manager_draft' AND (ttn_number IS NULL OR ttn_number='') AND (np_ref IS NULL OR np_ref='') AND sender_snapshot LIKE %s";
        // Snapshots are written with wp_json_encode; validate again in project().
        $pattern = '%"sender_type":"doors"%';
        $count = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}pnpm_shipments WHERE $where", $pattern));
        $pages = max(1, (int)ceil($count/self::PAGE_SIZE));
        $page = min($page, $pages);
        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->prefix}pnpm_shipments WHERE $where ORDER BY updated_at DESC,id DESC LIMIT %d OFFSET %d", $pattern, self::PAGE_SIZE, ($page - 1) * self::PAGE_SIZE), ARRAY_A);
        return ['groups'=>self::project((array)$rows), 'total'=>$count, 'page'=>$page, 'pages'=>$pages];
    }

    public function createdPage(int $page = 1): array
    {
        if (!ManagerPreparation::allowed()) throw new \RuntimeException(__('Shipment access denied.', 'paint-nova-poshta-multishipping'));
        global $wpdb;
        if (!CourierStorage::ready()) return ['groups'=>[], 'total'=>0, 'page'=>1, 'pages'=>1];
        $where="s.source='store_api' AND s.status='np_created' AND s.sender_snapshot LIKE %s AND m.shipment_id IS NULL";
        $from="{$wpdb->prefix}pnpm_shipments s LEFT JOIN {$wpdb->prefix}pnpm_courier_members m ON m.shipment_id=s.id";
        $pattern='%"sender_type":"doors"%';
        $count=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $from WHERE $where",$pattern));
        $pages=max(1,(int)ceil($count/self::PAGE_SIZE)); $page=min(max(1,$page),$pages);
        $rows=$wpdb->get_results($wpdb->prepare("SELECT s.* FROM $from WHERE $where ORDER BY s.id DESC LIMIT %d OFFSET %d",$pattern,self::PAGE_SIZE,($page-1)*self::PAGE_SIZE),ARRAY_A);
        return ['groups'=>self::project((array)$rows,true),'total'=>$count,'page'=>$page,'pages'=>$pages];
    }

    /** Only exact saved origins are grouped. Labels/phones alone never establish an origin. */
    public static function project(array $rows, bool $created = false): array
    {
        $groups = [];
        foreach ($rows as $row) {
            if (($row['source'] ?? '') !== 'store_api') continue;
            if ($created) {
                if (($row['status'] ?? '') !== 'np_created' || !preg_match('/^\d{14}$/D', (string)($row['ttn_number'] ?? '')) || !ShipmentLifecycle::ref($row['np_ref'] ?? null)) continue;
            } elseif (($row['status'] ?? '') !== 'manager_draft' || !empty($row['ttn_number']) || !empty($row['np_ref'])) continue;
            $sender = json_decode((string)($row['sender_snapshot'] ?? ''), true) ?: [];
            $request = json_decode((string)($row['request_snapshot'] ?? ''), true) ?: [];
            if (!is_array($sender) || !is_array($request)) continue;
            if (($sender['sender_type'] ?? '') !== 'doors') continue;
            if ($created) {
                $carrier=$request['carrier'] ?? [];
                if (empty($request['courier_origin_label'])) continue;
                $sender['sender_address_label']=$request['courier_origin_label'];
                if (($carrier['ServiceType'] ?? '') !== 'DoorsWarehouse') continue;
                $matches=true;
                foreach (['Sender'=>'counterparty_ref','CitySender'=>'city_ref','SenderAddress'=>'sender_address_ref','ContactSender'=>'contact_ref'] as $key=>$field) {
                    if (empty($sender[$field]) || ($carrier[$key] ?? '') !== $sender[$field]) $matches=false;
                }
                if (!$matches || preg_replace('/\D/','',(string)($carrier['SendersPhone'] ?? '')) !== preg_replace('/\D/','',(string)($sender['phone'] ?? ''))) continue;
            }
            $origin = [];
            $valid = ($sender['enabled'] ?? '') === 'yes' && (bool)preg_match('/^\+?380\d{9}$/D', (string)($sender['phone'] ?? ''));
            foreach (['counterparty_ref','city_ref','sender_address_ref','contact_ref'] as $field) {
                $value = $sender[$field] ?? null;
                if (!ShipmentLifecycle::ref($value)) $valid = false;
                $origin[$field] = is_string($value) ? strtolower($value) : '';
            }
            // An incomplete mapping stays separate, even when several rows have empty references.
            $origin['phone'] = preg_replace('/\D/', '', (string)($sender['phone'] ?? ''));
            $key = $valid ? hash('sha256', wp_json_encode($origin)) : 'missing-' . (int)$row['id'];
            if (!isset($groups[$key])) $groups[$key] = ['sender'=>$sender,'complete'=>$valid,'rows'=>[], 'weight'=>0.0,'places'=>0,'totals_known'=>true];
            $boxes = $request['boxes'] ?? [];
            $weight = 0.0;
            $known = is_array($boxes) && count($boxes) > 0 && count($boxes) <= 20;
            foreach (is_array($boxes) ? $boxes : [] as $box) {
                $value = is_array($box) ? ($box['weight'] ?? null) : null;
                if (!is_numeric($value) || !is_finite((float)$value) || (float)$value <= 0) { $known = false; continue; }
                $weight += (float)$value;
            }
            $groups[$key]['rows'][] = $row + ['queue_weight'=>$known ? $weight : null,'queue_places'=>$known ? count($boxes) : null];
            $groups[$key]['totals_known'] = $groups[$key]['totals_known'] && $known;
            if ($known) { $groups[$key]['weight'] += $weight; $groups[$key]['places'] += count($boxes); }
        }
        return array_values($groups);
    }
}
