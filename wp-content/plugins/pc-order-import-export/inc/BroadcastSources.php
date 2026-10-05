<?php
namespace PaintCore\PCOE;
defined('ABSPATH') || exit;

/** Admin-only gateway. Source document prices never enter WordPress or the file. */
final class BroadcastSources {
    public static function document_type(array $input): string {
        $type=(string)($input['document_type']??'receipt');
        if(!in_array($type,['all','receipt','invoice'],true))throw new \RuntimeException(__('Select a receipt or invoice document type.','pc-order-import-export'));
        return $type;
    }
    public static function request(string $path,array $params=[]): array {
        if(!current_user_can('manage_woocommerce'))throw new \RuntimeException('Forbidden');
        if(!function_exists('lps_java_get'))throw new \RuntimeException(__('The Folio document service is unavailable.','pc-order-import-export'));
        $warehouses=$path==='/warehouses';
        // Reuse the established warehouse directory independently of the newer document picker.
        $response=lps_java_get(add_query_arg($params,$warehouses?'/ref/warehouses':'/admin/folio/receipt-catalogue'.$path),['timeout'=>25]);
        if(is_wp_error($response))throw new \RuntimeException(__('Could not connect to Folio. Try loading again.','pc-order-import-export'));
        $code=(int)wp_remote_retrieve_response_code($response);
        $data=json_decode((string)wp_remote_retrieve_body($response),true);
        if($code===404 && !$warehouses)throw new \RuntimeException(__('The Folio document service needs an update, or the selected document is no longer available. Reload the document list.','pc-order-import-export'));
        if($code<200 || $code>=300)throw new \RuntimeException(sprintf(__('Folio request failed (HTTP %s). Try loading again.','pc-order-import-export'),$code));
        if(!is_array($data))throw new \RuntimeException(__('Folio returned an invalid document response.','pc-order-import-export'));
        if($warehouses){
            if(!array_is_list($data))throw new \RuntimeException(__('Folio returned an invalid warehouse list.','pc-order-import-export'));
            foreach($data as $row)if(!is_array($row) || empty($row['code']) || !isset($row['name']))throw new \RuntimeException(__('Folio returned an invalid warehouse list.','pc-order-import-export'));
            return ['ok'=>true,'warehouses'=>$data];
        }
        if(($data['ok']??false)!==true)throw new \RuntimeException(__('Folio returned an invalid document response.','pc-order-import-export'));
        if(($params['documentType']??'receipt')!=='receipt' && !in_array('invoice',$data['documentTypes']??[],true))throw new \RuntimeException(__('Update the Folio service to select invoices for mailings.','pc-order-import-export'));
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
        $type=self::document_type($input);
        if(!$warehouse || !$doc || !preg_match('/^\d{4}-\d{2}-\d{2}$/',$date))throw new \RuntimeException(__('Select warehouse, date and a receipt or invoice.','pc-order-import-export'));
        $data=self::request('/'.$doc.'/skus',['warehouseId'=>$warehouse,'date'=>$date,'documentType'=>$type]);
        if(($data['documentType']??'receipt')!==$type)throw new \RuntimeException('Document type mismatch');
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
            'source'=>['warehouse'=>$warehouse,'date'=>$date,'document'=>$doc,'document_type'=>$type]];
    }
}
