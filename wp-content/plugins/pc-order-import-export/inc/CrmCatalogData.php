<?php
namespace PaintCore\PCOE;
defined('ABSPATH') || exit;

/** Site warehouse groups and public product brands. No supplier IDs or inferred brands. */
final class CrmCatalogData {
    const BATCH = 100;
    const MAX_PRODUCTS = 30000;
    public static function locations(): array {
        $allowed=PriceList::location_ids();$result=[];
        foreach((array)(function_exists('lavka_get_locations_mapping_for_java')?lavka_get_locations_mapping_for_java():[]) as $m){
            if(!in_array((int)$m['id'],$allowed,true))continue;
            $t=get_term((int)$m['id'],'location');if(!$t || is_wp_error($t))continue;
            $codes=array_values(array_unique(array_map('strval',$m['codes'])));sort($codes,SORT_STRING);
            $result[(int)$m['id']]=['name'=>$t->name,'codes'=>$codes];
        }
        return $result;
    }
    public static function selection(array $input): array {
        $s=[];
        foreach(['brands','locations'] as $key){
            $values=$input[$key]??[];
            if(!is_array($values) || !$values || count($values)>50)throw new \RuntimeException(__('Select brands and warehouse groups for the CRM feed.','pc-order-import-export'),400);
            foreach($values as $v)if(!is_scalar($v) || !ctype_digit((string)$v) || (int)$v<1)throw new \RuntimeException(__('Invalid selection.','pc-order-import-export'),400);
            $s[$key]=array_values(array_unique(array_map('intval',$values)));sort($s[$key],SORT_NUMERIC);
        }
        foreach($s['brands'] as $id)if(!term_exists($id,'product_brand'))throw new \RuntimeException(__('Invalid selection.','pc-order-import-export'),400);
        $available=self::locations();$covered=[];
        foreach($s['locations'] as $id){
            if(!isset($available[$id]) || array_intersect($covered,$available[$id]['codes']))throw new \RuntimeException(__('Warehouse groups are unavailable or overlap. Ask the manager to check the mapping.','pc-order-import-export'),400);
            $covered=array_merge($covered,$available[$id]['codes']);
        }
        return $s;
    }
    public static function context(int $user,array $selection): string {
        $selection=self::selection($selection);$all=self::locations();$mapping=[];
        foreach($selection['locations'] as $id)$mapping[$id]=$all[$id];
        return hash('sha256',wp_json_encode(['crm-v1',BroadcastPricing::group($user),$selection,$mapping]));
    }
    /** Keyset scan: bounded memory, no unstable OFFSET during catalogue changes. */
    public static function page(array $selection,int $after): array {
        global $wpdb;
        $brands=implode(',',array_map('intval',$selection['brands']));
        $sql=$wpdb->prepare("SELECT p.ID FROM {$wpdb->posts} p WHERE p.ID > %d
            AND p.post_status = 'publish' AND p.post_type IN ('product','product_variation')
            AND EXISTS (SELECT 1 FROM {$wpdb->term_relationships} tr
                JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
                WHERE tr.object_id = CASE WHEN p.post_type = 'product_variation' THEN p.post_parent ELSE p.ID END
                AND tt.taxonomy = 'product_brand' AND tt.term_id IN ($brands))
            ORDER BY p.ID ASC LIMIT %d",$after,self::BATCH);
        $ids=$wpdb->get_col($sql);
        if($wpdb->last_error)throw new \RuntimeException(__('Could not read the catalogue. Try preparing the CRM feed again.','pc-order-import-export'),400);
        return array_map('intval',$ids);
    }
    public static function rows(int $user,array $ids,array $s): array {
        // Recheck membership before projection, including a variation's parent.
        $ids=array_values(array_filter($ids,static function($id)use($s){$p=wc_get_product($id);return $p && has_term($s['brands'],'product_brand',$p->get_parent_id()?:$id);}));
        return PartnerCatalogData::build_ids($user,$ids,['skus'=>[],'category'=>0,'brand'=>0],$s['locations'],[],false,false);
    }
}
