<?php
// [LTS] ANCHOR: guard
if (!defined('ABSPATH')) exit;

if (!defined('LTS_CAP')) define('LTS_CAP', 'manage_lavka_sync');

function lts_logs_table(): string {
    global $wpdb;
    return $wpdb->prefix . 'lts_sync_logs';
}

/**
 * Базовая запись в лог.
 * @param string $level  info|warn|error
 * @param string $action sync|upsert|draft|export|system
 * @param array  $data   ['sku'=>..., 'post_id'=>..., 'result'=>..., 'message'=>..., 'ctx'=>array|scalar]
 */
function lts_log_db(string $level, string $action, array $data = []): void {
    global $wpdb;
    $table = lts_logs_table();
    $row = [
        'ts'      => current_time('mysql'), // локальное WP время
        'level'   => substr($level, 0, 16),
        'action'  => substr($action, 0, 32),
        'sku'     => isset($data['sku']) ? substr((string)$data['sku'], 0, 191) : null,
        'post_id' => isset($data['post_id']) ? (int)$data['post_id'] : null,
        'result'  => isset($data['result']) ? substr((string)$data['result'], 0, 32) : null,
        'message' => isset($data['message']) ? (string)$data['message'] : null,
        'ctx'     => isset($data['ctx']) ? wp_json_encode($data['ctx']) : null,
    ];
    // защита от пустых ключей для wpdb->insert
    $format = ['%s','%s','%s','%s','%d','%s','%s','%s'];
    try {
        $wpdb->insert($table, $row, $format);
    } catch (Throwable $e) {
        // fallback в error_log — на случай если таблица ещё не создана
        error_log('[LTS][LOG_FAIL] '.$e->getMessage());
    }
}

/** Утилита для ротации (например, хранить 30 дней) */
function lts_logs_prune(int $days = 30): int {
    global $wpdb;
    $table = lts_logs_table();
    $lim = max(1, $days);
    return (int)$wpdb->query(
        $wpdb->prepare("DELETE FROM $table WHERE ts < (NOW() - INTERVAL %d DAY)", $lim)
    );
}