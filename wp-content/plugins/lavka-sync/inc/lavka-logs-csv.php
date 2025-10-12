<?php
// inc/lavka-logs-csv.php

if (!defined('ABSPATH')) exit;

/**
 * Константы (можно задать в wp-config.php):
 * define('LAVKA_LOGS_DIR', '/absolute/path/outside/uploads');
 */

function lavka__logs_base(): array {
    // 1) Если задан свой каталог — используем его, без URL
   /* if (defined('LAVKA_LOGS_DIR') && LAVKA_LOGS_DIR) {
        return ['dir' => rtrim((string)LAVKA_LOGS_DIR, '/'), 'url' => null];
    }

    // 2) дефолт: uploads/lavka-sync/logs
    $u = wp_upload_dir();
    $dir = trailingslashit($u['basedir']) . 'lavka-sync/logs';
    $url = trailingslashit($u['baseurl']) . 'lavka-sync/logs';

    // 3) возможность переопределить фильтром
    $base = apply_filters('lavka_logs_base', ['dir'=>$dir, 'url'=>$url]);
    $base['dir'] = rtrim($base['dir'] ?? $dir, '/');
    $base['url'] = $base['url'] ?? null;
    return $base;*/
}

/**
 * Сохранить CSV и вернуть ['path'=>..., 'url'=>null|url]
 */
function lavka_save_csv(string $name, array $rows): ?array {
   /* $base = lavka__logs_base();
    $dir  = $base['dir'];
    $urlb = $base['url'];

    if (!wp_mkdir_p($dir)) return null;

    $path = $dir . '/' . $name;
    $fh = @fopen($path, 'w');
    if (!$fh) return null;

    foreach ($rows as $r) fputcsv($fh, $r);
    fclose($fh);

    // если URL не задан — просто вернём путь
    return ['path' => $path, 'url' => $urlb ? $urlb . '/' . rawurlencode($name) : null];*/
}

/**
 * Дописать текст к колонке message для лога по ID.
 */
function lavka_log_append_message(int $log_id, string $suffix) {
    global $wpdb;
    $t = $wpdb->prefix . 'lavka_sync_logs';
    $wpdb->query( $wpdb->prepare(
        "UPDATE $t SET message = CONCAT(IFNULL(message,''), %s) WHERE id=%d",
        ' ' . $suffix, $log_id
    ));
}

/**
 * Плановая чистка старых CSV (по умолчанию 14 дней)
 */
function lavka_cleanup_old_csv($days = 14) {
   /* $base = lavka__logs_base();
    $dir  = $base['dir'];
    if (!is_dir($dir)) return;
    $cut = time() - $days*86400;
    foreach (glob($dir.'/*.csv') as $f) {
        if (@filemtime($f) < $cut) @unlink($f);
    }*/
}

add_action('lavka_cleanup_logs', function(){ lavka_cleanup_old_csv(14); });
if (!wp_next_scheduled('lavka_cleanup_logs')) {
    wp_schedule_event(time()+3600, 'daily', 'lavka_cleanup_logs');
}