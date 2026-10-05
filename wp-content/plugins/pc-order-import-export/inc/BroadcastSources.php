<?php
namespace PaintCore\PCOE;
defined('ABSPATH') || exit;

/** Admin-only gateway. Receipt purchase prices never enter WordPress or the file. */
final class BroadcastSources {
    public static function request(string $path,array $params=[]): array {
        if(!current_user_can('manage_woocommerce'))throw new \RuntimeException('Forbidden');
        if(!function_exists('pc_folio_documents_java_get'))throw new \RuntimeException(__('The receipt service is unavailable.','pc-order-import-export'));
        $data=pc_folio_documents_java_get('/admin/folio/receipt-catalogue'.$path,$params);
        if(is_wp_error($data))throw new \RuntimeException(__('Could not load receipts. Check that the Java receipt service is deployed.','pc-order-import-export'));
        return $data;
    }
    public static function products(string $kind,array $input): array {
        if($kind==='text')return ['ids'=>[],'missing'=>[],'source'=>[]];
        if($kind==='price'){
            wp_raise_memory_limit('admin');wc_set_time_limit(180);
            $ids=[];foreach(PriceList::products() as $p)$ids[]=$p->get_id();
            return ['ids'=>$ids,'missing'=>[],'source'=>[]];
        }
        $warehouse=absint($input['warehouse']??0);$date=sanitize_text_field($input['date']??'');$doc=absint($input['document']??0);
        if(!$warehouse || !$doc || !preg_match('/^\d{4}-\d{2}-\d{2}$/',$date))throw new \RuntimeException(__('Select warehouse, date and receipt document.','pc-order-import-export'));
        $data=self::request('/'.$doc.'/skus',['warehouseId'=>$warehouse,'date'=>$date]);
        if((int)($data['documentId']??0)!==$doc || (int)($data['warehouseId']??0)!==$warehouse || ($data['date']??'')!==$date || empty($data['skus']) || !is_array($data['skus']) || count($data['skus'])>2000)throw new \RuntimeException('Receipt mismatch');
        if(!class_exists('PSU_Category_Menu'))throw new \RuntimeException('Category visibility unavailable');
        $visibility=\PSU_Category_Menu::index();
        if(is_wp_error($visibility))throw new \RuntimeException('Category visibility unavailable');
        $visible=array_diff_key($visibility['visible'],$visibility['blocked']);
        $ids=[];$missing=[];
        foreach(array_unique($data['skus']) as $sku){
            $id=wc_get_product_id_by_sku((string)$sku);$p=$id?wc_get_product($id):null;
            if($p && PriceList::eligible($p)){
                $categories=wp_get_object_terms($p->get_parent_id()?:$id,'product_cat',['fields'=>'ids']);
                if(is_wp_error($categories))throw new \RuntimeException('Product categories unavailable');
                if(array_intersect($categories,array_keys($visible))){$ids[]=$id;continue;}
            }
            $missing[]=sanitize_text_field($sku);
        }
        return ['ids'=>array_values(array_unique($ids)),'missing'=>$missing,
            'source'=>['warehouse'=>$warehouse,'date'=>$date,'document'=>$doc]];
    }
}
