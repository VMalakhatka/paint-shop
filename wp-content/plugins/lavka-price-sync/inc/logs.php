<?php
if (!defined('ABSPATH')) exit;

function lps_logs_table(): string {
    global $wpdb; return $wpdb->prefix.'lps_price_logs';
}

/** Старт лога */
function lps_log_start(string $mode='manual'): int {
    global $wpdb;
    $wpdb->insert(lps_logs_table(), [
        'started_at'   => current_time('mysql'),
        'mode'         => $mode,
        'triggered_by' => get_current_user_id() ?: null,
    ], ['%s','%s','%d']);
    return (int)$wpdb->insert_id;
}

/** Финиш лога + опционально CSV путь */
function lps_log_finish(int $log_id, array $data): void {
    global $wpdb;
    $row = [
        'finished_at'   => current_time('mysql'),
        'ok'            => empty($data['ok']) ? 0 : 1,
        'total'         => (int)($data['total'] ?? 0),
        'updated_retail'=> (int)($data['updated_retail'] ?? 0),
        'updated_roles' => (int)($data['updated_roles'] ?? 0),
        'not_found'     => (int)($data['not_found'] ?? 0),
        'csv_path'      => !empty($data['csv_path']) ? (string)$data['csv_path'] : null,
        'sample_json'   => !empty($data['sample']) ? wp_json_encode($data['sample']) : null,
    ];
    $wpdb->update(lps_logs_table(), $row, ['id'=>$log_id],
        ['%s','%d','%d','%d','%d','%s','%s'], ['%d']);
}

/** Сохранить CSV с не найденными SKU в uploads и вернуть путь */
function lps_save_not_found_csv(array $skus): ?string {
    if (!$skus) return null;
    $upload = wp_upload_dir();
    if (!empty($upload['error'])) return null;

    $dir = trailingslashit($upload['basedir']).'lps-logs/'.date('Y-m');
    wp_mkdir_p($dir);
    $file = $dir.'/not-found-'.date('Ymd-His').'.csv';

    $fh = fopen($file, 'w');
    if (!$fh) return null;
    // заголовок
    fputcsv($fh, ['sku']);
    foreach ($skus as $sku) fputcsv($fh, [ (string)$sku ]);
    fclose($fh);

    return $file;
}