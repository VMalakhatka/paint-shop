<?php
// inc/lavka-logs-csv.php

if (!defined('ABSPATH')) exit;

/**
 * Сохранить CSV в uploads/lavka-sync/logs и вернуть ['path'=>..., 'url'=>...]
 */
function lavka_save_csv(string $name, array $rows): ?array {
    $u = wp_upload_dir();
    if (!empty($u['error'])) return null;

    $dir = trailingslashit($u['basedir']) . 'lavka-sync/logs';
    if (!wp_mkdir_p($dir)) return null;

    $path = trailingslashit($dir) . $name;
    $fh = fopen($path, 'w');
    if (!$fh) return null;

    foreach ($rows as $r) fputcsv($fh, $r);
    fclose($fh);

    $url = trailingslashit($u['baseurl']) . 'lavka-sync/logs/' . $name;
    return ['path' => $path, 'url' => $url];
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

/** Плановая чистка старых CSV (14 дней) */
function lavka_cleanup_old_csv($days = 14) {
    $u = wp_upload_dir();
    $dir = trailingslashit($u['basedir']) . 'lavka-sync/logs';
    if (!is_dir($dir)) return;
    $cut = time() - $days*86400;
    foreach (glob($dir.'/*.csv') as $f) {
        if (@filemtime($f) < $cut) @unlink($f);
    }
}
add_action('lavka_cleanup_logs', function(){ lavka_cleanup_old_csv(14); });
if (!wp_next_scheduled('lavka_cleanup_logs')) {
    wp_schedule_event(time()+3600, 'daily', 'lavka_cleanup_logs');
}