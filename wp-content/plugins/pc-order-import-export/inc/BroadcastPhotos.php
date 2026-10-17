<?php
namespace PaintCore\PCOE;
defined('ABSPATH') || exit;

/** Campaign-scoped thumbnails, shared by price groups. Called under the mailing lock. */
final class BroadcastPhotos {
    private static function key(int $product): string {return hash('sha256','photo-v1:'.$product);}

    public static function prepare(int $id,array &$data): bool {
        $ids=[];
        foreach($data['groups'] as $group)foreach($group['ids']??$data['ids'] as $product)$ids[(int)$product]=true;
        $ids=array_keys($ids);
        $data['photo_progress']=$data['photo_progress']??['done'=>[],'attempts'=>[], 'total'=>count($ids)];
        $progress=&$data['photo_progress'];$deadline=microtime(true)+15;$processed=0;
        foreach($ids as $product){
            if(isset($progress['done'][$product]))continue;
            if($processed>=40 || microtime(true)>=$deadline)break;
            // Persist each result before advancing. A killed request retries only its current image.
            $p=wc_get_product($product);$bytes=0;
            $image=$p?CommercialOffer::photo($p,microtime(true)+3,$bytes):null;
            $progress['attempts'][$product]=($progress['attempts'][$product]??0)+1;
            if($image){
                try{ob_start();imagejpeg($image,null,80);$jpeg=ob_get_clean();}
                finally{imagedestroy($image);}
                if(!$jpeg)throw new \RuntimeException('Photo encoding failed');
                BroadcastStore::put_file($id,self::key($product),['content'=>base64_encode($jpeg),'sha256'=>hash('sha256',$jpeg)]);
                $progress['done'][$product]='ready';
            }elseif($progress['attempts'][$product]>=2){
                $progress['done'][$product]='unavailable';
            }
            BroadcastStore::save($id,$data);$processed++;
        }
        return count($progress['done'])===count($ids);
    }

    public static function bytes(int $id,int $product,array $data): ?string {
        $status=$data['photo_progress']['done'][$product]??null;
        if($status==='unavailable')return null;
        if($status!=='ready')throw new \RuntimeException('Photo preparation is incomplete');
        return BroadcastStore::bytes(BroadcastStore::file($id,self::key($product)));
    }

    public static function delete(int $id,array $data): void {
        // Also covers a blob saved immediately before an interrupted progress write.
        $ids=$data['ids']??[];
        foreach($data['groups']??[] as $group)$ids=array_merge($ids,$group['ids']??[]);
        foreach(array_unique($ids) as $product)delete_option('_pcoe_mail_file_'.$id.'_'.self::key((int)$product));
    }
}
