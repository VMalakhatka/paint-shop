<?php
namespace PaintCore\PCOE;
defined('ABSPATH') || exit;
final class PartnerCatalogPhotos {
    /** Bounded download; every missing image is reported in manifest.csv, never silently discarded. */
    public static function zip(array $data): string {
        $count=array_sum(array_map(static fn($r)=>count($r['images']),$data['rows']));
        if($count>100)throw new \RuntimeException(__('Photo ZIP supports up to 100 images. Narrow the selection or use the image links.','pc-order-import-export'));
        if(!class_exists('ZipArchive'))throw new \RuntimeException(__('ZIP export is unavailable.','pc-order-import-export'));
        $path=BroadcastStore::temporary_file();$zip=new \ZipArchive();if($zip->open($path,\ZipArchive::OVERWRITE)!==true)throw new \RuntimeException(__('ZIP export is unavailable.','pc-order-import-export'));
        $manifest=fopen('php://temp','w+');fputcsv($manifest,['SKU','Image URL','File','Result'],',','"','');$bytes=0;$deadline=microtime(true)+25;
        try {
            foreach($data['rows'] as $r){
                if(!$r['images'])fputcsv($manifest,[PartnerCatalogFormats::csvSafe($r['sku']),'','','No images'],',','"','');
                foreach($r['images'] as $n=>$url){$file='';$result='Not downloaded: time or size limit';
                    if(microtime(true)<$deadline && $bytes<50*1024*1024){
                        $response=wp_safe_remote_get($url,['timeout'=>min(3,max(.1,$deadline-microtime(true))),'redirection'=>0,'limit_response_size'=>5*1024*1024+1]);
                        $body=is_wp_error($response)?'':wp_remote_retrieve_body($response);$info=$body?@getimagesizefromstring($body):false;
                        if(!is_wp_error($response) && wp_remote_retrieve_response_code($response)===200 && $info && strlen($body)<=5*1024*1024 && $bytes+strlen($body)<=50*1024*1024 && in_array($info[2],[IMAGETYPE_JPEG,IMAGETYPE_PNG,IMAGETYPE_WEBP,IMAGETYPE_GIF],true)){
                            $file=$r['id'].'-'.sanitize_file_name($r['sku']).'/'.($n+1).image_type_to_extension($info[2]);
                            if(!$zip->addFromString($file,$body))throw new \RuntimeException(__('ZIP export is unavailable.','pc-order-import-export'));
                            $bytes+=strlen($body);$result='OK';
                        }else $result='Image unavailable or unsupported';
                    }
                    fputcsv($manifest,[PartnerCatalogFormats::csvSafe($r['sku']),$url,$file,$result],',','"','');
                }
            }
            rewind($manifest);$zip->addFromString('manifest.csv',stream_get_contents($manifest));
            if(!$zip->close())throw new \RuntimeException(__('ZIP export is unavailable.','pc-order-import-export'));
            return file_get_contents($path);
        } finally{fclose($manifest);@unlink($path);}
    }
}
