<?php
if (!defined('ABSPATH')) exit;

const LTS_JOB_OPT = 'lts_running_job';
const LTS_JOB_HOOK = 'lts_job_tick';

// Запуск задачи
function lts_job_start(array $args = []) {
    // Сохраняем состояние
    $job = [
        'status'   => 'running',
        'started'  => time(),
        'updated'  => time(),
        'after'    => $args['after'] ?? null,
        'limit'    => (int)($args['limit'] ?? 500),
        'max_sec'  => (int)($args['max_seconds'] ?? 10),
        'max_items'=> (int)($args['max_items'] ?? 200), // limit items per tick
        'reason'   => null, // last tick stop reason
        'draft_sec'=> isset($args['draft_stale_seconds']) ? (int)$args['draft_stale_seconds'] : null,
        'done'     => 0, 'created'=>0, 'updated'=>0, 'drafted'=>0,
        'last'     => false,
        'error'    => null,
    ];
    update_option(LTS_JOB_OPT, $job, false);
    // Запланировать первый тик сразу
    if (!wp_next_scheduled(LTS_JOB_HOOK)) {
        wp_schedule_single_event(time(), LTS_JOB_HOOK);
    }
    return $job;
}

// Остановка
function lts_job_stop($status = 'stopped', $error = null) {
    $job = get_option(LTS_JOB_OPT, []);
    $job['status'] = $status;
    $job['updated'] = time();
    if ($error) $job['error'] = (string)$error;
    update_option(LTS_JOB_OPT, $job, false);
}

// Тикер
add_action(LTS_JOB_HOOK, function () {
    $job = get_option(LTS_JOB_OPT, null);
    if (!$job || $job['status'] !== 'running') return;

    // Безопасность: ограничим один тик по времени
    $args = [
        'limit'               => max(50, min(1000, (int)$job['limit'])),
        'after'               => $job['after'] ?: null,
        'max_seconds'         => max(5, min(30, (int)$job['max_sec'])),   // deadline per tick
        'max'                 => max(1, (int)($job['max_items'] ?? 200)), // items cap per tick
        'draft_stale_seconds' => $job['draft_sec'] ?: null,
    ];

    try {
        $res = lts_sync_goods_run($args);
        if (empty($res['ok'])) {
            lts_job_stop('error', $res['error'] ?? 'unknown');
            return;
        }
        // накапливаем счётчики и курсор
        $job['after']   = $res['after'] ?? $job['after'];
        $job['done']   += (int)($res['done'] ?? 0);
        $job['created']+= (int)($res['created'] ?? 0);
        $job['updated']+= (int)($res['updated'] ?? 0);
        $job['drafted']+= (int)($res['drafted'] ?? 0);
        $job['last']    = !empty($res['last']);
        $job['reason']  = $res['reason'] ?? null;
        $job['updated'] = time();

        if ($job['last']) {
            $job['status'] = 'done';
            update_option(LTS_JOB_OPT, $job, false);
            return;
        }

        // Продолжим: сохраняем и планируем следующий тик через 3 сек
        update_option(LTS_JOB_OPT, $job, false);
        wp_schedule_single_event(time()+3, LTS_JOB_HOOK);

    } catch (\Throwable $e) {
        lts_job_stop('error', $e->getMessage());
        return;
    }
});

add_action('wp_ajax_lts_job_start', function(){
    if (!current_user_can('manage_lavka_sync')) wp_send_json_error(['error'=>'cap']);
    check_ajax_referer('lts_admin_nonce','nonce');

    $limit  = isset($_POST['limit']) ? (int)$_POST['limit'] : 500;
    $after  = isset($_POST['after']) ? sanitize_text_field((string)$_POST['after']) : null;
    $maxSec = isset($_POST['max_seconds']) ? (int)$_POST['max_seconds'] : 10;
    $maxIt  = isset($_POST['max_items']) ? (int)$_POST['max_items'] : 200;
    $draft  = isset($_POST['draft_stale_seconds']) ? (int)$_POST['draft_stale_seconds'] : 0;

    $job = lts_job_start([
        'limit' => $limit,
        'after' => $after,
        'max_seconds' => $maxSec,
        'max_items' => $maxIt,
        'draft_stale_seconds' => $draft ?: null,
    ]);
    wp_send_json_success(['job'=>$job]);
});

add_action('wp_ajax_lts_job_status', function(){
    if (!current_user_can('manage_lavka_sync')) wp_send_json_error(['error'=>'cap']);
    check_ajax_referer('lts_admin_nonce','nonce');

    $job = get_option(LTS_JOB_OPT, null);
    wp_send_json_success(['job'=>$job]);
});

add_action('wp_ajax_lts_job_cancel', function(){
    if (!current_user_can('manage_lavka_sync')) wp_send_json_error(['error'=>'cap']);
    check_ajax_referer('lts_admin_nonce','nonce');

    lts_job_stop('canceled');
    wp_send_json_success(['ok'=>true]);
});