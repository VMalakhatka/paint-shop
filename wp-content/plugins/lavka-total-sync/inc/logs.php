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

// === Live progress polling ===============================================

add_action('wp_ajax_lts_logs_progress', 'lts_ajax_logs_progress');

function lts_ajax_logs_progress() {
    if ( ! current_user_can( defined('LTS_CAP') ? LTS_CAP : 'manage_options') ) {
        wp_send_json_error(['error' => 'forbidden'], 403);
    }

    global $wpdb;
    $table   = $wpdb->prefix . 'lts_logs';
    $run     = isset($_GET['run']) ? (string) $_GET['run'] : '';
    $sinceId = isset($_GET['since_id']) ? (int) $_GET['since_id'] : 0;

    if ($run === '') {
        wp_send_json_success(['rows' => [], 'last_id' => $sinceId]);
    }

    // Тянем только наши "прогресс"-строки текущего прогона
    // level='progress', tag='progress', ctx->>'$.run' = $run
    $sql = "
        SELECT id, created_at, ctx
        FROM {$table}
        WHERE level = 'progress'
          AND tag   = 'progress'
          AND id    > %d
          AND JSON_EXTRACT(ctx, '$.run') = %s
        ORDER BY id ASC
        LIMIT 500
    ";

    $rows = $wpdb->get_results( $wpdb->prepare($sql, $sinceId, wp_json_encode($run)), ARRAY_A );
    $out  = [];
    $last = $sinceId;

    foreach ($rows as $r) {
        $ctx = json_decode($r['ctx'] ?? '{}', true) ?: [];
        $line = isset($ctx['line']) ? (string)$ctx['line'] : '';
        if ($line !== '') {
            $out[] = $line;
        }
        $last = (int)$r['id'];
    }

    wp_send_json_success(['rows' => $out, 'last_id' => $last]);
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