<?php
namespace PaintCore\PCOE;
defined('ABSPATH') || exit;

final class BroadcastStore {
    public const TYPE='pcoe-mailing';
    public static function register(): void { register_post_type(self::TYPE,['public'=>false,'show_ui'=>false,'show_in_rest'=>false,'supports'=>[]]); }
    public static function lock(callable $fn) {
        global $wpdb;$key='pcoe-mailings:'.substr(hash('sha256',DB_NAME.$wpdb->prefix),0,35);
        if((string)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,0)',$key))!=='1')throw new \RuntimeException(__('Mailings are busy. Please retry shortly.','pc-order-import-export'),423);
        try{return $fn();}finally{$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$key));}
    }
    public static function get(int $id): array {
        $p=get_post($id);
        if(!$p || $p->post_type!==self::TYPE || $p->post_status!=='private')throw new \RuntimeException(__('Mailing not found.','pc-order-import-export'));
        wp_cache_delete($id,'post_meta');$data=get_post_meta($id,'_pcoe_mailing',true);
        if(!is_array($data) || !isset($data['status'],$data['recipients'],$data['groups']))throw new \RuntimeException('Invalid mailing');
        return $data;
    }
    public static function save(int $id,array $data): void {
        $data['updated_at']=time();
        update_post_meta($id,'_pcoe_mailing',wp_slash($data));wp_cache_delete($id,'post_meta');
        if(get_post_meta($id,'_pcoe_mailing',true)!==$data)throw new \RuntimeException(__('Mailing state could not be saved.','pc-order-import-export'));
    }
    private static function file_key(int $id,string $group): string {
        if($id<=0 || !preg_match('/^[a-f0-9]{64}$/',$group))throw new \RuntimeException('Invalid group');
        return '_pcoe_mail_file_'.$id.'_'.$group;
    }
    public static function put_file(int $id,string $group,array $file): void {
        $key=self::file_key($id,$group);
        update_option($key,$file,false);
        if(get_option($key)!==$file)throw new \RuntimeException(__('Mailing state could not be saved.','pc-order-import-export'));
    }
    public static function delete_files(int $id): void {
        if(get_post_type($id)!==self::TYPE)return;
        $data=get_post_meta($id,'_pcoe_mailing',true);
        foreach(array_keys(is_array($data['groups']??null)?$data['groups']:[]) as $group)delete_option(self::file_key($id,$group));
    }
    public static function file(int $id,string $group): array {
        // Keep each blob separate: get_post_meta loads ALL campaign meta at once.
        $file=get_option(self::file_key($id,$group));
        if(!is_array($file))throw new \RuntimeException(__('The prepared file is unavailable. Create a new mailing.','pc-order-import-export'));
        return $file;
    }
    public static function temporary_file(): string {
        $path=tempnam(sys_get_temp_dir(),'pcoe-mail-');
        if(!$path)throw new \RuntimeException('Temporary file unavailable');
        if(str_starts_with(realpath($path),rtrim(realpath(ABSPATH),DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR)){
            unlink($path);throw new \RuntimeException('Private temporary directory required');
        }
        register_shutdown_function(static function()use($path){if(is_file($path))unlink($path);});
        return $path;
    }
    public static function bytes(array $file): string {
        $bytes=base64_decode($file['content']??'',true);
        if(!$bytes || !hash_equals($file['sha256']??'',hash('sha256',$bytes)))throw new \RuntimeException(__('The prepared file is unavailable. Create a new mailing.','pc-order-import-export'));
        return $bytes;
    }
}
