<?php
namespace Paint\NovaPoshta\Infrastructure;
defined('ABSPATH') || exit;

/** Durable pickup claims; never keep a database transaction open during carrier HTTP. */
final class CourierStorage
{
    public static function ready(): bool { return get_option('pnpm_courier_schema') === '1'; }

    public static function install(): void
    {
        global $wpdb;
        require_once ABSPATH.'wp-admin/includes/upgrade.php';
        $tables = [
            'calls'=>"id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                token_hash CHAR(64) NOT NULL,
                pickup_key CHAR(64) NOT NULL,
                account_hash CHAR(64) NOT NULL,
                status VARCHAR(32) NOT NULL,
                barcode VARCHAR(36) NULL,
                request_snapshot LONGTEXT NOT NULL,
                response_snapshot LONGTEXT NOT NULL,
                actor_id BIGINT UNSIGNED NOT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY token_hash (token_hash),
                UNIQUE KEY pickup_key (pickup_key),
                UNIQUE KEY barcode (barcode)",
            'members'=>"shipment_id BIGINT UNSIGNED NOT NULL,
                call_id BIGINT UNSIGNED NOT NULL,
                ttn CHAR(14) NOT NULL,
                PRIMARY KEY (shipment_id),
                UNIQUE KEY ttn (ttn),
                KEY call_id (call_id)",
            'events'=>"id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                call_id BIGINT UNSIGNED NOT NULL,
                actor_id BIGINT UNSIGNED NOT NULL,
                status VARCHAR(32) NOT NULL,
                context LONGTEXT NOT NULL,
                created_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                KEY call_id (call_id)",
        ];
        foreach ($tables as $suffix=>$definition) dbDelta('CREATE TABLE '.$wpdb->prefix.'pnpm_courier_'.$suffix." (\n$definition\n) ENGINE=InnoDB ".$wpdb->get_charset_collate().';');
        self::engine();
        update_option('pnpm_courier_schema','1',false);
    }

    public static function engine(): void
    {
        global $wpdb;
        foreach (['calls','members','events'] as $suffix) {
            if (strtolower((string)$wpdb->get_var($wpdb->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',$wpdb->prefix.'pnpm_courier_'.$suffix))) !== 'innodb') {
                throw new \RuntimeException(__('Initialize courier storage before continuing.','paint-nova-poshta-multishipping'));
            }
        }
    }

    public static function transaction(callable $work)
    {
        global $wpdb;
        self::engine();
        if ($wpdb->query('START TRANSACTION') === false) self::fail();
        try { $result=$work(); if ($wpdb->query('COMMIT')===false) self::fail(); return $result; }
        catch (\Throwable $e) { $wpdb->query('ROLLBACK'); throw $e; }
    }

    public static function fail(): never { throw new \RuntimeException(__('Courier operation could not be saved. Check the call history before continuing.','paint-nova-poshta-multishipping')); }

    public static function event(int $id,string $status,array $context): void
    {
        global $wpdb;
        if ($wpdb->insert($wpdb->prefix.'pnpm_courier_events',['call_id'=>$id,'status'=>$status,'actor_id'=>get_current_user_id(),'context'=>wp_json_encode($context),'created_at'=>current_time('mysql',true)])!==1) self::fail();
    }

    public static function record(int $id): array
    {
        global $wpdb;
        $row=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}pnpm_courier_calls WHERE id=%d",$id),ARRAY_A);
        if (!$row) self::fail();
        return $row;
    }

    public static function change(int $id,string $status,array $result,?string $barcode=null): array
    {
        global $wpdb;
        self::transaction(function () use ($wpdb,$id,$status,$result,$barcode) {
            $fields=['status'=>$status,'response_snapshot'=>wp_json_encode($result),'updated_at'=>current_time('mysql',true)];
            if ($barcode!==null) $fields['barcode']=$barcode;
            if ($wpdb->update($wpdb->prefix.'pnpm_courier_calls',$fields,['id'=>$id])===false) self::fail();
            self::event($id,$status,$result);
        });
        return self::record($id);
    }
}
