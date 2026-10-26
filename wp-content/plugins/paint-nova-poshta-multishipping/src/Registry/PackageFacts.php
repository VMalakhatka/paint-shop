<?php
namespace Paint\NovaPoshta\Registry;
defined('ABSPATH') || exit;

/** Projections over existing durable snapshots. Carrier measurements and our entry stay separate. */
final class PackageFacts
{
    public static function carrier(array $observations): ?array
    {
        $fallback=null;
        foreach($observations as $observation) {
            if (!in_array($observation['source'],['np_documents','np_tracking'],true)) continue;
            $data=json_decode($observation['payload'],true)?:[];
            $set=self::normalize($data['OptionsSeat']??[],false);
            $set+=['source'=>$observation['source'],'captured_at_utc'=>$observation['observed_at'],
                'total_weight_kg'=>self::number($data['Weight']??null),'place_count'=>self::count($data['SeatsAmount']??null)];
            if ($set['places']) return $set;
            if ($fallback===null && ($set['total_weight_kg']!==null || $set['place_count']!==null)) $fallback=$set;
        }
        return $fallback;
    }

    public static function local(array $row): array
    {
        $data=json_decode($row['request_snapshot']??'',true)?:[];
        // Exact final carrier payload wins over the preparation copy, when present.
        $carrier=$data['carrier']??[];
        $set=isset($carrier['OptionsSeat'])?self::normalize($carrier['OptionsSeat'],false):self::normalize($data['boxes']??[],true);
        return $set+['source'=>'site_package_entry','shipment_id'=>(int)$row['id'],'order_id'=>(int)$row['order_id'],
            'total_weight_kg'=>self::number($carrier['Weight']??null),'place_count'=>self::count($carrier['SeatsAmount']??null)];
    }

    public static function savedLocal(string $ttn): array
    {
        global $wpdb;
        $table=$wpdb->prefix.'pnpm_shipments';
        if (!$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->esc_like($table)))) return [];
        $rows=$wpdb->get_results($wpdb->prepare("SELECT id,order_id,request_snapshot FROM $table WHERE ttn_number=%s AND source=%s ORDER BY id LIMIT 5",$ttn,'store_api'),ARRAY_A)?:[];
        return array_map([self::class,'local'],$rows);
    }

    private static function normalize($boxes,bool $local): array
    {
        if (!is_array($boxes) || !array_is_list($boxes)) return ['places'=>[]];
        $places=[];
        foreach(array_slice($boxes,0,1000) as $i=>$box) {
            if(!is_array($box)) continue;
            $places[]=['place_number'=>$i+1,'weight_kg'=>self::number($box['weight']??null),
                'length_cm'=>self::number($box[$local?'length':'volumetricLength']??null),
                'width_cm'=>self::number($box[$local?'width':'volumetricWidth']??null),
                'height_cm'=>self::number($box[$local?'height':'volumetricHeight']??null),
                'volumetric_weight_kg'=>self::number($box['volumetricWeight']??null),
                'volume_m3'=>self::number($box['volumetricVolume']??null)];
        }
        return ['places'=>$places];
    }
    private static function number($value): ?string
    {
        return is_scalar($value) && !is_bool($value) && preg_match('/^\d{1,10}(\.\d{1,6})?$/D',(string)$value) && (float)$value>0 ? (string)$value : null;
    }
    private static function count($value): ?int
    {
        return is_scalar($value) && preg_match('/^[1-9]\d{0,3}$/D',(string)$value)?(int)$value:null;
    }
}
