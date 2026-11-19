<?php
namespace PaintCore\PCOE;
defined('ABSPATH') || exit;

/** Private option chunks, never public uploads. A complete snapshot is published atomically. */
final class CrmCatalogStore {
    private static function key(int $user,string $kind): string {return 'pcoe_crm_'.$kind.'_'.$user;}
    public static function get(int $user,string $kind): array {$v=get_option(self::key($user,$kind),[]);return is_array($v)?$v:[];}
    public static function put(int $user,string $kind,array $value): void {
        $key=self::key($user,$kind);update_option($key,$value,false);
        if(get_option($key)!==$value)throw new \RuntimeException('Could not persist CRM snapshot');
    }
    private static function chunkKey(int $user,string $generation,int $index): string {
        if(!preg_match('/^[a-f0-9]{32}$/D',$generation) || $index<0 || $index>301)throw new \RuntimeException('Invalid CRM chunk');
        return self::key($user,$generation.'_'.$index);
    }
    public static function chunk(int $user,string $generation,int $index,string $xml): void {
        if(strlen($xml)>2*1024*1024)throw new \RuntimeException(__('One CRM export batch is too large. Ask the manager to check product descriptions.','pc-order-import-export'),400);
        $key=self::chunkKey($user,$generation,$index);update_option($key,$xml,false);
        if(get_option($key)!==$xml)throw new \RuntimeException('Could not persist CRM chunk');
    }
    public static function discard(int $user,array $state,bool $includePublished=false): void {
        if(empty($state['generation']))return;
        // An interrupted build can still reference the already-published snapshot.
        if(!$includePublished && (self::get($user,'snapshot')['generation']??'')===$state['generation'])return;
        // Include a chunk written immediately before a worker interruption.
        for($i=0;$i<=count($state['hashes']??[]);$i++)delete_option(self::chunkKey($user,$state['generation'],$i));
    }
    public static function clear(int $user): void {
        foreach(['build','snapshot'] as $kind){self::discard($user,self::get($user,$kind),true);self::put($user,$kind,[]);}
    }
    public static function lock(int $user,callable $fn) {
        global $wpdb;$key='pcoe-crm:'.substr(hash('sha256',DB_NAME.$wpdb->prefix.':'.$user),0,40);
        if((string)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,0)',$key))!=='1')throw new \RuntimeException('CRM feed busy',423);
        try{return $fn();}finally{$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$key));}
    }
    /** Verify every chunk before sending HTTP 200, with bounded PHP memory. */
    public static function file(int $user,array $snapshot): string {
        $path=BroadcastStore::temporary_file();$f=fopen($path,'wb');
        if(!$f){@unlink($path);throw new \RuntimeException('CRM temporary file unavailable');}
        try {
            self::write($f,CrmCatalogFormat::header($snapshot));
            foreach($snapshot['hashes'] as $i=>$hash){
                $xml=get_option(self::chunkKey($user,$snapshot['generation'],(int)$i),null);
                if(!is_string($xml) || !hash_equals($hash,hash('sha256',$xml)))throw new \RuntimeException('CRM snapshot incomplete');
                self::write($f,$xml);
            }
            self::write($f,CrmCatalogFormat::footer());fclose($f);return $path;
        }catch(\Throwable $e){fclose($f);@unlink($path);throw $e;}
    }
    private static function write($file,string $bytes): void {if(fwrite($file,$bytes)!==strlen($bytes))throw new \RuntimeException('CRM temporary file write failed');}
}
