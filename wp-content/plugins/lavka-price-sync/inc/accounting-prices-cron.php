<?php
if (!defined('ABSPATH')) exit;

const LPS_ACCOUNTING_PRICES_NATIVE_FULL_PATH = '/admin/folio/accounting-prices/recalculate/native-full';
const LPS_ACCOUNTING_PRICES_NATIVE_STATUS_PATH = '/admin/folio/accounting-prices/recalculate/native-full/status';
const LPS_ACCOUNTING_PRICES_NATIVE_CRON_OPTION = 'lps_accounting_prices_native_cron';
const LPS_ACCOUNTING_PRICES_NATIVE_JOB_OPTION = 'lps_accounting_prices_native_job';
const LPS_ACCOUNTING_PRICES_NATIVE_BATCH_OPTION = 'lps_accounting_prices_native_batch';
const LPS_ACCOUNTING_PRICES_NATIVE_CRON_HOOK = 'lps_accounting_prices_native_cron';
const LPS_ACCOUNTING_PRICES_NATIVE_RETRY_HOOK = 'lps_accounting_prices_native_retry';
const LPS_ACCOUNTING_PRICES_NATIVE_POLL_HOOK = 'lps_accounting_prices_native_poll';
const LPS_ACCOUNTING_PRICES_NATIVE_BATCH_NEXT_HOOK = 'lps_accounting_prices_native_batch_next';
const LPS_ACCOUNTING_PRICES_NATIVE_LOCK_PROCESS = 'accounting_prices_native_full';
const LPS_ACCOUNTING_PRICES_NATIVE_POLL_OUTAGE_LIMIT = 2 * HOUR_IN_SECONDS;

function lps_accounting_prices_native_cron_options(): array {
    $options = get_option(LPS_ACCOUNTING_PRICES_NATIVE_CRON_OPTION, []);
    $options = wp_parse_args(is_array($options) ? $options : [], [
        'enabled' => false,
        'warehouse_id' => 0,
        'warehouse_ids' => [],
        'weekday' => 'sun',
        'time' => '03:30',
        'automatic_apply_confirmed' => false,
        'paused_reason' => '',
    ]);

    $warehouse_ids = lps_accounting_prices_native_normalize_warehouse_ids($options['warehouse_ids']);
    if (!$warehouse_ids && absint($options['warehouse_id']) > 0) {
        $warehouse_ids = [absint($options['warehouse_id'])];
    }
    $options['warehouse_ids'] = $warehouse_ids;
    $options['warehouse_id'] = $warehouse_ids[0] ?? 0;

    return $options;
}

function lps_accounting_prices_native_normalize_warehouse_ids($warehouse_ids): array {
    if (!is_array($warehouse_ids)) $warehouse_ids = [$warehouse_ids];

    $normalized = [];
    foreach ($warehouse_ids as $warehouse_id) {
        $warehouse_id = absint($warehouse_id);
        if ($warehouse_id > 0) $normalized[$warehouse_id] = $warehouse_id;
    }

    return array_values($normalized);
}

function lps_accounting_prices_native_job_state(): array {
    $state = get_option(LPS_ACCOUNTING_PRICES_NATIVE_JOB_OPTION, []);
    return is_array($state) ? $state : [];
}

function lps_accounting_prices_native_store_job(array $state): void {
    update_option(LPS_ACCOUNTING_PRICES_NATIVE_JOB_OPTION, $state, false);
}

function lps_accounting_prices_native_batch_state(): array {
    $state = get_option(LPS_ACCOUNTING_PRICES_NATIVE_BATCH_OPTION, []);
    return is_array($state) ? $state : [];
}

function lps_accounting_prices_native_store_batch(array $state): void {
    update_option(LPS_ACCOUNTING_PRICES_NATIVE_BATCH_OPTION, $state, false);
}

function lps_accounting_prices_native_log(string $event, array $data = []): void {
    if (!function_exists('lavka_ecosystem_log_event')) return;

    lavka_ecosystem_log_event($event, wp_parse_args($data, [
        'owner' => 'lavka-price-sync',
        'process' => LPS_ACCOUNTING_PRICES_NATIVE_LOCK_PROCESS,
        'source' => 'cron',
        'user_id' => get_current_user_id(),
    ]));
}

function lps_accounting_prices_native_decode_response($response): array {
    if (is_wp_error($response)) {
        return [
            'ok' => false,
            'httpStatus' => 0,
            'body' => ['message' => $response->get_error_message()],
        ];
    }

    $http_status = (int)wp_remote_retrieve_response_code($response);
    $raw = (string)wp_remote_retrieve_body($response);
    $body = json_decode($raw, true);
    if (!is_array($body)) {
        $body = ['message' => $raw !== '' ? $raw : __('Java API returned an empty response.', 'lavka-price-sync')];
    }

    return [
        'ok' => $http_status >= 200 && $http_status < 300,
        'httpStatus' => $http_status,
        'body' => $body,
    ];
}

function lps_accounting_prices_native_sanitize_report_value($value, int $depth = 0) {
    if ($depth > 5) return null;
    if ($value === null || is_bool($value) || is_int($value) || is_float($value)) return $value;
    if (is_string($value)) {
        $value = sanitize_textarea_field($value);
        return function_exists('mb_substr') ? mb_substr($value, 0, 4000) : substr($value, 0, 4000);
    }
    if (!is_array($value)) return sanitize_text_field((string)$value);

    $result = [];
    $count = 0;
    foreach ($value as $key => $item) {
        if (++$count > 100) break;
        $safe_key = is_int($key)
            ? $key
            : preg_replace('/[^A-Za-z0-9_.-]/', '', (string)$key);
        if ($safe_key === '') continue;
        $result[$safe_key] = lps_accounting_prices_native_sanitize_report_value($item, $depth + 1);
    }
    return $result;
}

function lps_accounting_prices_native_sanitize_issues($issues): array {
    if (!is_array($issues)) return [];

    $result = [];
    foreach (array_slice($issues, 0, 500) as $issue) {
        if (!is_array($issue)) $issue = ['message' => (string)$issue];
        $result[] = lps_accounting_prices_native_sanitize_report_value($issue);
    }
    return $result;
}

function lps_accounting_prices_native_state_from_body(array $body, array $state = []): array {
    $request = isset($body['request']) && is_array($body['request']) ? $body['request'] : [];
    $status = sanitize_key((string)($body['status'] ?? ($state['status'] ?? '')));

    return array_merge($state, [
        'job_id' => sanitize_text_field((string)($body['jobId'] ?? ($state['job_id'] ?? ''))),
        'status' => $status,
        'phase' => sanitize_key((string)($body['phase'] ?? ($state['phase'] ?? ''))),
        'running' => !empty($body['running']) || strtoupper($status) === 'QUEUED',
        'warehouse_id' => absint($request['warehouseId'] ?? ($state['warehouse_id'] ?? 0)),
        'preview_only' => isset($request['previewOnly']) ? (bool)$request['previewOnly'] : ($state['preview_only'] ?? null),
        'started_at' => sanitize_text_field((string)($body['startedAt'] ?? ($state['started_at'] ?? ''))),
        'completed_at' => sanitize_text_field((string)($body['completedAt'] ?? ($state['completed_at'] ?? ''))),
        'procedure_calls' => absint($body['procedureCalls'] ?? 0),
        'preflight_chunks' => absint($body['preflightChunks'] ?? 0),
        'committed_chunks' => absint($body['committedChunks'] ?? 0),
        'progress_units' => absint($body['progressUnits'] ?? 0),
        'total_units' => absint($body['totalUnits'] ?? 0),
        'progress_percent' => isset($body['progressPercent']) ? max(0, min(100, (int)$body['progressPercent'])) : null,
        'current_art' => sanitize_text_field((string)($body['currentArt'] ?? '')),
        'next_art' => sanitize_text_field((string)($body['nextArt'] ?? '')),
        'last_committed_art' => sanitize_text_field((string)($body['lastCommittedArt'] ?? '')),
        'warning_count' => absint($body['warningCount'] ?? ($state['warning_count'] ?? 0)),
        'warnings_truncated' => array_key_exists('warningsTruncated', $body)
            ? !empty($body['warningsTruncated'])
            : !empty($state['warnings_truncated']),
        'warnings' => lps_accounting_prices_native_sanitize_issues($body['warnings'] ?? ($state['warnings'] ?? [])),
        'errors' => lps_accounting_prices_native_sanitize_issues($body['errors'] ?? ($state['errors'] ?? [])),
        'failed_chunk' => isset($body['failedChunk']) && is_array($body['failedChunk'])
            ? lps_accounting_prices_native_sanitize_report_value($body['failedChunk'])
            : ($state['failed_chunk'] ?? []),
        'error' => sanitize_textarea_field((string)($body['error'] ?? '')),
        'last_checked_at' => current_time('mysql'),
        'last_checked_at_gmt' => current_time('mysql', true),
    ]);
}

function lps_accounting_prices_native_calculate_next(array $options, ?int $from = null): ?int {
    if (empty($options['enabled']) || empty($options['automatic_apply_confirmed'])) return null;

    $weekday_map = ['sun' => 0, 'mon' => 1, 'tue' => 2, 'wed' => 3, 'thu' => 4, 'fri' => 5, 'sat' => 6];
    $weekday = sanitize_key((string)($options['weekday'] ?? 'sun'));
    if (!isset($weekday_map[$weekday])) $weekday = 'sun';

    $time = (string)($options['time'] ?? '03:30');
    if (!preg_match('/^(\d{1,2}):(\d{2})$/', $time, $matches)) {
        $matches = [0, '03', '30'];
    }
    $hour = max(0, min(23, (int)$matches[1]));
    $minute = max(0, min(59, (int)$matches[2]));

    $now = new DateTimeImmutable('@' . ($from ?: time()));
    $now = $now->setTimezone(wp_timezone());
    $run = $now->setTime($hour, $minute, 0);
    $days = ($weekday_map[$weekday] - (int)$run->format('w') + 7) % 7;
    if ($days === 0 && $run <= $now) $days = 7;
    if ($days > 0) $run = $run->modify('+' . $days . ' days');

    return $run->getTimestamp();
}

function lps_accounting_prices_native_clear_regular_schedule(): void {
    wp_clear_scheduled_hook(LPS_ACCOUNTING_PRICES_NATIVE_CRON_HOOK);
}

function lps_accounting_prices_native_reschedule(): ?int {
    lps_accounting_prices_native_clear_regular_schedule();
    $options = lps_accounting_prices_native_cron_options();
    $timestamp = lps_accounting_prices_native_calculate_next($options);
    if (!$timestamp) return null;

    $scheduled = wp_schedule_single_event($timestamp, LPS_ACCOUNTING_PRICES_NATIVE_CRON_HOOK);
    if (!$scheduled) {
        lps_accounting_prices_native_log('cron_schedule_failed', [
            'level' => 'error',
            'message' => 'Native Folio accounting-price cron could not be scheduled.',
            'scheduled_at' => $timestamp,
        ]);
        return null;
    }

    lps_accounting_prices_native_log('cron_scheduled', [
        'message' => 'Native Folio accounting-price cron scheduled.',
        'scheduled_at' => $timestamp,
        'context' => ['warehouse_ids' => $options['warehouse_ids']],
    ]);

    return $timestamp;
}

function lps_accounting_prices_native_schedule_poll(int $delay = 20): ?int {
    $existing = wp_next_scheduled(LPS_ACCOUNTING_PRICES_NATIVE_POLL_HOOK);
    if ($existing) return (int)$existing;

    $timestamp = time() + max(10, $delay);
    return wp_schedule_single_event($timestamp, LPS_ACCOUNTING_PRICES_NATIVE_POLL_HOOK)
        ? $timestamp
        : null;
}

function lps_accounting_prices_native_schedule_retry(?array $blocking_lock = null): ?int {
    $existing = wp_next_scheduled(LPS_ACCOUNTING_PRICES_NATIVE_RETRY_HOOK);
    if ($existing) return (int)$existing;

    $delay = defined('LAVKA_ECOSYSTEM_LOCK_CRON_DELAY')
        ? (int)LAVKA_ECOSYSTEM_LOCK_CRON_DELAY
        : 10 * MINUTE_IN_SECONDS;
    $timestamp = time() + max(MINUTE_IN_SECONDS, $delay);
    $scheduled = wp_schedule_single_event($timestamp, LPS_ACCOUNTING_PRICES_NATIVE_RETRY_HOOK);

    lps_accounting_prices_native_log($scheduled ? 'cron_rescheduled' : 'cron_reschedule_failed', [
        'level' => $scheduled ? 'warning' : 'error',
        'message' => $scheduled
            ? 'Native Folio accounting-price cron was postponed because another process is running.'
            : 'Native Folio accounting-price cron retry could not be scheduled.',
        'scheduled_at' => $timestamp,
        'context' => ['blocking_lock' => $blocking_lock],
    ]);

    return $scheduled ? $timestamp : null;
}

function lps_accounting_prices_native_schedule_batch_next(int $delay = 10): ?int {
    $existing = wp_next_scheduled(LPS_ACCOUNTING_PRICES_NATIVE_BATCH_NEXT_HOOK);
    if ($existing) return (int)$existing;

    $timestamp = time() + max(5, $delay);
    return wp_schedule_single_event($timestamp, LPS_ACCOUNTING_PRICES_NATIVE_BATCH_NEXT_HOOK)
        ? $timestamp
        : null;
}

function lps_accounting_prices_native_pause_schedule(string $reason): void {
    $options = lps_accounting_prices_native_cron_options();
    $options['enabled'] = false;
    $options['paused_reason'] = sanitize_textarea_field($reason);
    update_option(LPS_ACCOUNTING_PRICES_NATIVE_CRON_OPTION, $options, false);
    wp_clear_scheduled_hook(LPS_ACCOUNTING_PRICES_NATIVE_CRON_HOOK);
    wp_clear_scheduled_hook(LPS_ACCOUNTING_PRICES_NATIVE_RETRY_HOOK);
    wp_clear_scheduled_hook(LPS_ACCOUNTING_PRICES_NATIVE_BATCH_NEXT_HOOK);
}

function lps_accounting_prices_native_acquire_lock(string $source, int $warehouse_id): array {
    if (!function_exists('lavka_ecosystem_lock_acquire')) {
        return [
            'ok' => false,
            'token' => null,
            'lock' => null,
            'message' => __('The global Lavka lock is unavailable. The recalculation was not started.', 'lavka-price-sync'),
        ];
    }

    return lavka_ecosystem_lock_acquire(
        'lavka-price-sync',
        LPS_ACCOUNTING_PRICES_NATIVE_LOCK_PROCESS,
        $source,
        __('Full Folio accounting-price recalculation', 'lavka-price-sync'),
        2 * HOUR_IN_SECONDS,
        ['warehouse_id' => $warehouse_id]
    );
}

function lps_accounting_prices_native_release_lock(array $state): void {
    $token = (string)($state['lock_token'] ?? '');
    if ($token !== '' && function_exists('lavka_ecosystem_lock_release')) {
        lavka_ecosystem_lock_release($token);
    }
}

function lps_accounting_prices_native_terminal_statuses(): array {
    return [
        'PREVIEW_READY',
        'PREVIEW_READY_WITH_WARNINGS',
        'BLOCKED_NEGATIVE_STOCK',
        'COMPLETED',
        'COMPLETED_WITH_WARNINGS',
        'STOPPED_ON_NEGATIVE_STOCK',
        'FAILED',
        'FAILED_PARTIAL',
        'OUTCOME_UNKNOWN',
    ];
}

function lps_accounting_prices_native_ensure_job_lock(array $state): array {
    if (!function_exists('lavka_ecosystem_lock_get')) {
        return [
            'ok' => false,
            'state' => $state,
            'message' => __('The global Lavka lock became unavailable while the recalculation was running.', 'lavka-price-sync'),
        ];
    }

    $lock = lavka_ecosystem_lock_get();
    $token = (string)($state['lock_token'] ?? '');
    if ($lock && $token !== '' && !empty($lock['token']) && hash_equals((string)$lock['token'], $token)) {
        return ['ok' => true, 'state' => $state, 'message' => ''];
    }

    if ($lock) {
        return [
            'ok' => false,
            'state' => $state,
            'message' => __('The recalculation lost its global lock while another Lavka process is active. Manual review is required.', 'lavka-price-sync'),
        ];
    }

    $recovered = lps_accounting_prices_native_acquire_lock('recovery', absint($state['warehouse_id'] ?? 0));
    if (empty($recovered['ok'])) {
        return [
            'ok' => false,
            'state' => $state,
            'message' => __('The recalculation global lock could not be restored. Manual review is required.', 'lavka-price-sync'),
        ];
    }

    $state['lock_token'] = (string)($recovered['token'] ?? '');
    lps_accounting_prices_native_store_job($state);
    return ['ok' => true, 'state' => $state, 'message' => ''];
}

function lps_accounting_prices_native_create_batch(array $warehouse_ids): array {
    $warehouse_ids = lps_accounting_prices_native_normalize_warehouse_ids($warehouse_ids);
    $state = [
        'batch_id' => wp_generate_uuid4(),
        'active' => true,
        'status' => 'queued',
        'stage' => 'preview',
        'warehouse_ids' => $warehouse_ids,
        'pending_warehouse_ids' => $warehouse_ids,
        'approved_warehouse_ids' => [],
        'current_warehouse_id' => 0,
        'results' => [],
        'started_at' => current_time('mysql'),
        'started_at_gmt' => current_time('mysql', true),
        'completed_at' => '',
        'error' => '',
    ];
    lps_accounting_prices_native_store_batch($state);

    lps_accounting_prices_native_log('accounting_recalculation_batch_created', [
        'message' => 'Scheduled native Folio accounting-price batch created.',
        'context' => [
            'batch_id' => $state['batch_id'],
            'warehouse_ids' => $warehouse_ids,
        ],
    ]);

    return $state;
}

function lps_accounting_prices_native_batch_final_status(array $batch): string {
    $has_errors = false;
    $has_warnings = false;
    foreach ((array)($batch['results'] ?? []) as $result) {
        if (!is_array($result)) continue;
        $outcome = sanitize_key((string)($result['outcome'] ?? ''));
        if ($outcome === 'error') $has_errors = true;
        if ($outcome === 'warning' || absint($result['warning_count'] ?? 0) > 0) $has_warnings = true;
    }

    if ($has_errors) return 'completed_with_errors';
    if ($has_warnings) return 'completed_with_warnings';
    return 'completed';
}

function lps_accounting_prices_native_finish_batch(array $batch): array {
    $batch['active'] = false;
    $batch['status'] = lps_accounting_prices_native_batch_final_status($batch);
    $batch['completed_at'] = current_time('mysql');
    $batch['completed_at_gmt'] = current_time('mysql', true);
    $batch['current_warehouse_id'] = 0;
    lps_accounting_prices_native_store_batch($batch);
    wp_clear_scheduled_hook(LPS_ACCOUNTING_PRICES_NATIVE_BATCH_NEXT_HOOK);
    return $batch;
}

function lps_accounting_prices_native_advance_batch(array $batch): array {
    if (!empty($batch['pending_warehouse_ids'])) {
        $batch['status'] = 'waiting_next';
        lps_accounting_prices_native_store_batch($batch);
        lps_accounting_prices_native_schedule_batch_next();
        return $batch;
    }

    if (sanitize_key((string)($batch['stage'] ?? 'apply')) === 'preview') {
        $batch['stage'] = 'apply';
        $batch['pending_warehouse_ids'] = lps_accounting_prices_native_normalize_warehouse_ids(
            $batch['approved_warehouse_ids'] ?? []
        );
        if (!$batch['pending_warehouse_ids']) return lps_accounting_prices_native_finish_batch($batch);

        $batch['status'] = 'waiting_next';
        lps_accounting_prices_native_store_batch($batch);
        lps_accounting_prices_native_schedule_batch_next();
        return $batch;
    }

    return lps_accounting_prices_native_finish_batch($batch);
}

function lps_accounting_prices_native_batch_result(
    array $job_state,
    array $body,
    string $stage,
    string $status,
    string $outcome,
    string $message = ''
): array {
    return [
        'stage' => $stage,
        'warehouse_id' => absint($job_state['warehouse_id'] ?? 0),
        'job_id' => sanitize_text_field((string)($job_state['job_id'] ?? '')),
        'status' => sanitize_key($status),
        'phase' => sanitize_key((string)($body['phase'] ?? ($job_state['phase'] ?? ''))),
        'outcome' => sanitize_key($outcome),
        'warning_count' => absint($body['warningCount'] ?? ($job_state['warning_count'] ?? 0)),
        'warnings_truncated' => !empty($body['warningsTruncated']) || !empty($job_state['warnings_truncated']),
        'warnings' => lps_accounting_prices_native_sanitize_issues($body['warnings'] ?? ($job_state['warnings'] ?? [])),
        'errors' => lps_accounting_prices_native_sanitize_issues($body['errors'] ?? ($job_state['errors'] ?? [])),
        'failed_chunk' => isset($body['failedChunk']) && is_array($body['failedChunk'])
            ? lps_accounting_prices_native_sanitize_report_value($body['failedChunk'])
            : ($job_state['failed_chunk'] ?? []),
        'error' => sanitize_textarea_field($message !== '' ? $message : (string)($body['error'] ?? ($job_state['error'] ?? ''))),
        'started_at' => sanitize_text_field((string)($body['startedAt'] ?? ($job_state['started_at'] ?? ''))),
        'completed_at' => sanitize_text_field((string)($body['completedAt'] ?? current_time('mysql'))),
        'procedure_calls' => absint($body['procedureCalls'] ?? ($job_state['procedure_calls'] ?? 0)),
        'preflight_chunks' => absint($body['preflightChunks'] ?? ($job_state['preflight_chunks'] ?? 0)),
        'committed_chunks' => absint($body['committedChunks'] ?? ($job_state['committed_chunks'] ?? 0)),
        'progress_units' => absint($body['progressUnits'] ?? ($job_state['progress_units'] ?? 0)),
        'total_units' => absint($body['totalUnits'] ?? ($job_state['total_units'] ?? 0)),
    ];
}

function lps_accounting_prices_native_stop_batch(string $status, string $message, bool $pause_schedule = false): array {
    $batch = lps_accounting_prices_native_batch_state();
    if (!$batch) return [];

    $batch['active'] = false;
    $batch['status'] = sanitize_key($status);
    $batch['error'] = sanitize_textarea_field($message);
    $batch['completed_at'] = current_time('mysql');
    $batch['completed_at_gmt'] = current_time('mysql', true);
    lps_accounting_prices_native_store_batch($batch);
    wp_clear_scheduled_hook(LPS_ACCOUNTING_PRICES_NATIVE_BATCH_NEXT_HOOK);

    if ($pause_schedule) lps_accounting_prices_native_pause_schedule($message);

    return $batch;
}

function lps_accounting_prices_native_complete_batch_warehouse(array $job_state, string $status, array $body = []): void {
    $status = strtoupper(trim($status));
    $batch_id = sanitize_text_field((string)($job_state['batch_id'] ?? ''));
    if ($batch_id === '') return;

    $batch = lps_accounting_prices_native_batch_state();
    if (empty($batch['active']) || !hash_equals((string)($batch['batch_id'] ?? ''), $batch_id)) return;

    $warehouse_id = absint($job_state['warehouse_id'] ?? 0);
    if ($warehouse_id < 1 || absint($batch['current_warehouse_id'] ?? 0) !== $warehouse_id) {
        lps_accounting_prices_native_stop_batch(
            'outcome_unknown',
            __('The scheduled warehouse queue no longer matches the completed Java job. Check Folio manually before restarting it.', 'lavka-price-sync'),
            true
        );
        return;
    }

    $stage = sanitize_key((string)($batch['stage'] ?? 'apply'));
    if (!in_array($stage, ['preview', 'apply'], true)) {
        lps_accounting_prices_native_stop_batch(
            'outcome_unknown',
            __('The scheduled warehouse queue has an unknown stage. Check Folio manually before restarting it.', 'lavka-price-sync'),
            true
        );
        return;
    }

    $phase = strtoupper((string)($body['phase'] ?? ($job_state['phase'] ?? '')));
    $error = trim((string)($body['error'] ?? ($job_state['error'] ?? '')));
    $errors = isset($body['errors']) && is_array($body['errors']) ? $body['errors'] : [];
    $not_running = array_key_exists('running', $body) && $body['running'] === false;
    $request = isset($body['request']) && is_array($body['request']) ? $body['request'] : [];
    $response_preview_only = array_key_exists('previewOnly', $request) ? (bool)$request['previewOnly'] : null;
    $preview_is_safe = in_array($status, ['PREVIEW_READY', 'PREVIEW_READY_WITH_WARNINGS'], true)
        && $phase === 'PRECHECK_COMPLETED'
        && $not_running
        && $response_preview_only === true
        && absint($body['committedChunks'] ?? ($job_state['committed_chunks'] ?? 0)) === 0
        && $error === ''
        && !$errors;
    $apply_is_complete = in_array($status, ['COMPLETED', 'COMPLETED_WITH_WARNINGS'], true)
        && $phase === 'APPLY_COMPLETED'
        && $not_running
        && $response_preview_only === false
        && $error === ''
        && !$errors;

    $successful = $stage === 'preview' ? $preview_is_safe : $apply_is_complete;
    $fatal = in_array($status, ['FAILED_PARTIAL', 'OUTCOME_UNKNOWN'], true);

    if ($successful) {
        $outcome = absint($body['warningCount'] ?? ($job_state['warning_count'] ?? 0)) > 0
            || substr($status, -14) === '_WITH_WARNINGS'
            ? 'warning'
            : 'success';
        $batch['results'][] = lps_accounting_prices_native_batch_result(
            $job_state,
            $body,
            $stage,
            $status,
            $outcome
        );
        if ($stage === 'preview') {
            $approved = lps_accounting_prices_native_normalize_warehouse_ids($batch['approved_warehouse_ids'] ?? []);
            $approved[] = $warehouse_id;
            $batch['approved_warehouse_ids'] = lps_accounting_prices_native_normalize_warehouse_ids($approved);
        }
        $batch['current_warehouse_id'] = 0;
        lps_accounting_prices_native_advance_batch($batch);
        return;
    }

    if ($fatal) {
        $messages = [
            'FAILED_PARTIAL' => __('A warehouse recalculation ended after partial commits. The remaining warehouse queue is stopped until Folio is reviewed manually.', 'lavka-price-sync'),
            'OUTCOME_UNKNOWN' => __('A warehouse recalculation has an unknown outcome. The remaining warehouse queue is stopped until Folio is reviewed manually.', 'lavka-price-sync'),
        ];
        $message = $messages[$status];
        $batch['results'][] = lps_accounting_prices_native_batch_result($job_state, $body, $stage, $status, 'fatal', $message);
        $batch['current_warehouse_id'] = 0;

        lps_accounting_prices_native_store_batch($batch);
        lps_accounting_prices_native_stop_batch(
            strtolower($status ?: 'outcome_unknown'),
            $message,
            true
        );
        return;
    }

    $messages = [
        'BLOCKED_NEGATIVE_STOCK' => __('The warehouse preview found negative chronological stock. Problem products were recorded and this warehouse was skipped; the queue continues.', 'lavka-price-sync'),
        'STOPPED_ON_NEGATIVE_STOCK' => __('The warehouse recalculation stopped on negative chronological stock. The error was recorded and the queue continues with the next warehouse.', 'lavka-price-sync'),
        'FAILED' => __('The warehouse recalculation failed. The error was recorded and the queue continues with the next warehouse.', 'lavka-price-sync'),
    ];
    if ($stage === 'preview' && in_array($status, ['PREVIEW_READY', 'PREVIEW_READY_WITH_WARNINGS'], true)) {
        $message = __('The warehouse preview returned a success status without all required completion fields. The warehouse was skipped and the queue continues.', 'lavka-price-sync');
    } elseif ($stage === 'apply' && in_array($status, ['COMPLETED', 'COMPLETED_WITH_WARNINGS'], true)) {
        $message = __('The warehouse recalculation returned a success status without all required completion fields. The result was recorded and the queue continues.', 'lavka-price-sync');
    } else {
        $message = $messages[$status]
            ?? __('The warehouse returned a terminal error. It was recorded and the queue continues with the next warehouse.', 'lavka-price-sync');
    }

    $batch['results'][] = lps_accounting_prices_native_batch_result($job_state, $body, $stage, $status, 'error', $message);
    $batch['current_warehouse_id'] = 0;
    lps_accounting_prices_native_advance_batch($batch);
}

function lps_accounting_prices_native_start(int $warehouse_id, bool $preview_only, string $source = 'manual', string $batch_id = ''): array {
    if ($warehouse_id < 1) {
        return [
            'ok' => false,
            'httpStatus' => 400,
            'body' => ['message' => __('Folio warehouse is required.', 'lavka-price-sync')],
        ];
    }

    $existing = lps_accounting_prices_native_job_state();
    if (!empty($existing['running'])) {
        $latest = lps_accounting_prices_native_poll(false);
        $existing = lps_accounting_prices_native_job_state();
        if (!empty($existing['running'])) {
            $retry_scheduled = false;
            if (in_array($source, ['cron', 'retry'], true)) {
                $blocking_lock = function_exists('lavka_ecosystem_lock_get') ? lavka_ecosystem_lock_get() : null;
                $retry_scheduled = (bool)lps_accounting_prices_native_schedule_retry($blocking_lock);
            }
            return [
                'ok' => false,
                'httpStatus' => 409,
                'body' => $latest['body'] ?? [
                    'status' => 'BUSY',
                    'running' => true,
                    'message' => __('A full Folio accounting-price recalculation is already running.', 'lavka-price-sync'),
                ],
                'retryScheduled' => $retry_scheduled,
            ];
        }
    }

    $source = in_array($source, ['manual', 'cron', 'retry', 'recovery'], true) ? $source : 'manual';
    $lock = lps_accounting_prices_native_acquire_lock($source, $warehouse_id);
    if (empty($lock['ok'])) {
        $retry_scheduled = false;
        if (in_array($source, ['cron', 'retry'], true)) {
            $retry_scheduled = (bool)lps_accounting_prices_native_schedule_retry($lock['lock'] ?? null);
        }

        return [
            'ok' => false,
            'httpStatus' => 409,
            'body' => [
                'status' => 'BUSY',
                'running' => false,
                'message' => $lock['message'] ?? __('Another Lavka synchronization is running.', 'lavka-price-sync'),
                'lock' => $lock['lock'] ?? null,
            ],
            'retryScheduled' => $retry_scheduled,
        ];
    }

    $token = (string)($lock['token'] ?? '');
    $response = lps_java_post(LPS_ACCOUNTING_PRICES_NATIVE_FULL_PATH, [
        'warehouseId' => $warehouse_id,
        'previewOnly' => $preview_only,
        'confirmApply' => !$preview_only,
    ], ['timeout' => 30]);
    $result = lps_accounting_prices_native_decode_response($response);
    $body = $result['body'];
    $accepted = $result['httpStatus'] === 202 && !empty($body['accepted']) && !empty($body['jobId']);

    if (!$accepted) {
        if ($token !== '' && function_exists('lavka_ecosystem_lock_release')) {
            lavka_ecosystem_lock_release($token);
        }
        lps_accounting_prices_native_log('accounting_recalculation_start_failed', [
            'level' => 'error',
            'source' => $source,
            'message' => 'Native Folio accounting-price recalculation was not accepted.',
            'context' => [
                'warehouse_id' => $warehouse_id,
                'preview_only' => $preview_only,
                'http_status' => $result['httpStatus'],
                'status' => $body['status'] ?? null,
                'request_id' => $body['reqId'] ?? null,
            ],
        ]);
        return $result;
    }

    $state = lps_accounting_prices_native_state_from_body($body, [
        'lock_token' => $token,
        'source' => $source,
        'warehouse_id' => $warehouse_id,
        'preview_only' => $preview_only,
        'running' => !empty($body['running']),
        'poll_errors' => 0,
        'accepted_at' => current_time('mysql'),
        'accepted_at_gmt' => current_time('mysql', true),
        'batch_id' => sanitize_text_field($batch_id),
    ]);
    // A 202 response means Java owns an asynchronous job even if its first state is still QUEUED.
    $state['running'] = true;
    lps_accounting_prices_native_store_job($state);
    $result['body']['running'] = true;

    if (!empty($state['running'])) {
        if (function_exists('lavka_ecosystem_lock_touch')) {
            lavka_ecosystem_lock_touch($token, 2 * HOUR_IN_SECONDS, [
                'job_id' => $state['job_id'],
                'warehouse_id' => $warehouse_id,
                'progress' => ['status' => $state['status'], 'phase' => $state['phase']],
            ]);
        }
        lps_accounting_prices_native_schedule_poll();
    } else {
        lps_accounting_prices_native_release_lock($state);
    }

    lps_accounting_prices_native_log('accounting_recalculation_started', [
        'source' => $source,
        'token' => $token,
        'message' => 'Native Folio accounting-price recalculation accepted.',
        'context' => [
            'job_id' => $state['job_id'],
            'warehouse_id' => $warehouse_id,
            'preview_only' => $preview_only,
        ],
    ]);

    return $result;
}

function lps_accounting_prices_native_mark_unknown(array $state, string $message, array $body = []): array {
    $state['running'] = false;
    $state['status'] = 'outcome_unknown';
    $state['phase'] = 'manual_review';
    $state['error'] = sanitize_textarea_field($message);
    $state['completed_at'] = current_time('mysql');
    $state['last_checked_at'] = current_time('mysql');
    $state['manual_review_required'] = true;
    lps_accounting_prices_native_store_job($state);
    lps_accounting_prices_native_pause_schedule($message);
    wp_clear_scheduled_hook(LPS_ACCOUNTING_PRICES_NATIVE_POLL_HOOK);
    if (!empty($state['batch_id'])) {
        lps_accounting_prices_native_complete_batch_warehouse($state, 'OUTCOME_UNKNOWN', $body);
    }

    lps_accounting_prices_native_log('accounting_recalculation_outcome_unknown', [
        'level' => 'error',
        'source' => $state['source'] ?? 'cron',
        'token' => $state['lock_token'] ?? '',
        'message' => $message,
        'context' => [
            'job_id' => $state['job_id'] ?? '',
            'warehouse_id' => $state['warehouse_id'] ?? 0,
        ],
    ]);

    return [
        'ok' => false,
        'httpStatus' => 409,
        'body' => array_merge($body, [
            'ok' => false,
            'running' => false,
            'status' => 'OUTCOME_UNKNOWN',
            'phase' => 'MANUAL_REVIEW',
            'jobId' => $state['job_id'] ?? '',
            'error' => $message,
        ]),
    ];
}

function lps_accounting_prices_native_poll(bool $schedule_next = true): array {
    $state = lps_accounting_prices_native_job_state();

    if (($state['status'] ?? '') === 'outcome_unknown' && !empty($state['manual_review_required'])) {
        return [
            'ok' => false,
            'httpStatus' => 409,
            'body' => [
                'ok' => false,
                'running' => false,
                'jobId' => $state['job_id'] ?? '',
                'status' => 'OUTCOME_UNKNOWN',
                'phase' => 'MANUAL_REVIEW',
                'request' => [
                    'warehouseId' => absint($state['warehouse_id'] ?? 0),
                    'previewOnly' => isset($state['preview_only']) ? (bool)$state['preview_only'] : null,
                ],
                'error' => $state['error'] ?? __('The recalculation outcome is unknown. Manual review is required.', 'lavka-price-sync'),
            ],
        ];
    }

    $response = lps_java_get(LPS_ACCOUNTING_PRICES_NATIVE_STATUS_PATH, ['timeout' => 30]);
    $result = lps_accounting_prices_native_decode_response($response);

    if (!$result['ok']) {
        if (!empty($state['running'])) {
            $lock_check = lps_accounting_prices_native_ensure_job_lock($state);
            $state = $lock_check['state'];
            if (empty($lock_check['ok'])) {
                return lps_accounting_prices_native_mark_unknown($state, (string)$lock_check['message'], $result['body']);
            }

            $first_error_at = absint($state['first_poll_error_at'] ?? 0);
            if ($first_error_at < 1) $first_error_at = time();
            $state['poll_errors'] = absint($state['poll_errors'] ?? 0) + 1;
            $state['first_poll_error_at'] = $first_error_at;
            $state['last_poll_error'] = sanitize_textarea_field((string)($result['body']['message'] ?? __('The server request failed.', 'lavka-price-sync')));
            $state['last_checked_at'] = current_time('mysql');
            lps_accounting_prices_native_store_job($state);

            if (time() - $first_error_at >= LPS_ACCOUNTING_PRICES_NATIVE_POLL_OUTAGE_LIMIT) {
                return lps_accounting_prices_native_mark_unknown(
                    $state,
                    __('The Java status endpoint remained unavailable for two hours. The recalculation outcome must be checked manually before any retry.', 'lavka-price-sync'),
                    $result['body']
                );
            }

            if (function_exists('lavka_ecosystem_lock_touch')) {
                lavka_ecosystem_lock_touch($state['lock_token'] ?? '', 2 * HOUR_IN_SECONDS, [
                    'progress' => ['status' => 'poll_error', 'errors' => $state['poll_errors']],
                ]);
            }
            if ($schedule_next) lps_accounting_prices_native_schedule_poll(60);
        }
        return $result;
    }

    $body = $result['body'];
    $response_job_id = sanitize_text_field((string)($body['jobId'] ?? ''));
    $stored_job_id = sanitize_text_field((string)($state['job_id'] ?? ''));

    if (!empty($state['running']) && $stored_job_id !== '' && $response_job_id !== '' && $stored_job_id !== $response_job_id) {
        return lps_accounting_prices_native_mark_unknown(
            $state,
            __('The Java service returned a different recalculation job. Automatic retries are disabled until manual review.', 'lavka-price-sync'),
            $body
        );
    }

    if (empty($state['running']) && !empty($body['running'])) {
        $warehouse_id = absint($body['request']['warehouseId'] ?? 0);
        $lock = lps_accounting_prices_native_acquire_lock('recovery', $warehouse_id);
        if (!empty($lock['ok'])) {
            $state = [
                'lock_token' => $lock['token'] ?? '',
                'source' => 'recovery',
                'warehouse_id' => $warehouse_id,
                'accepted_at' => current_time('mysql'),
                'accepted_at_gmt' => current_time('mysql', true),
            ];
        } else {
            lps_accounting_prices_native_log('accounting_recalc_recovery_blocked', [
                'level' => 'error',
                'source' => 'recovery',
                'message' => 'A running native Folio recalculation could not acquire the global recovery lock.',
                'context' => [
                    'job_id' => $response_job_id,
                    'warehouse_id' => $warehouse_id,
                    'blocking_lock' => $lock['lock'] ?? null,
                ],
            ]);
        }
    }

    if (!$state) return $result;

    if (!empty($state['running']) && empty($body['running']) && strtoupper((string)($body['status'] ?? '')) === 'IDLE') {
        return lps_accounting_prices_native_mark_unknown(
            $state,
            __('The Java service restarted before the recalculation outcome was confirmed. Automatic retries are disabled until manual review.', 'lavka-price-sync'),
            $body
        );
    }

    $state = lps_accounting_prices_native_state_from_body($body, $state);
    $state['poll_errors'] = 0;
    $state['first_poll_error_at'] = 0;
    $state['last_poll_error'] = '';
    $state['last_http_status'] = $result['httpStatus'];
    lps_accounting_prices_native_store_job($state);

    if (!empty($state['running']) || strtoupper((string)($body['status'] ?? '')) === 'QUEUED') {
        $lock_check = lps_accounting_prices_native_ensure_job_lock($state);
        $state = $lock_check['state'];
        if (empty($lock_check['ok'])) {
            return lps_accounting_prices_native_mark_unknown($state, (string)$lock_check['message'], $body);
        }

        if (function_exists('lavka_ecosystem_lock_touch')) {
            lavka_ecosystem_lock_touch($state['lock_token'] ?? '', 2 * HOUR_IN_SECONDS, [
                'job_id' => $state['job_id'] ?? '',
                'warehouse_id' => $state['warehouse_id'] ?? 0,
                'progress' => [
                    'status' => $state['status'] ?? '',
                    'phase' => $state['phase'] ?? '',
                    'progress_percent' => $state['progress_percent'] ?? null,
                    'current_art' => $state['current_art'] ?? '',
                ],
            ]);
        }
        if ($schedule_next) lps_accounting_prices_native_schedule_poll(30);
        return $result;
    }

    $status = strtoupper((string)($body['status'] ?? ''));
    if ($status === 'OUTCOME_UNKNOWN') {
        return lps_accounting_prices_native_mark_unknown(
            $state,
            (string)($body['error'] ?? __('The recalculation outcome is unknown. Manual review is required.', 'lavka-price-sync')),
            $body
        );
    }

    if (!in_array($status, lps_accounting_prices_native_terminal_statuses(), true)) {
        return lps_accounting_prices_native_mark_unknown(
            $state,
            __('The Java service returned an unrecognized final status. Automatic retries are disabled until manual review.', 'lavka-price-sync'),
            $body
        );
    }

    wp_clear_scheduled_hook(LPS_ACCOUNTING_PRICES_NATIVE_POLL_HOOK);
    lps_accounting_prices_native_release_lock($state);
    lps_accounting_prices_native_complete_batch_warehouse($state, $status, $body);
    if ($status === 'FAILED_PARTIAL' && empty($state['batch_id'])) {
        lps_accounting_prices_native_pause_schedule(
            __('The previous scheduled recalculation ended after partial commits. Review Folio before enabling the schedule again.', 'lavka-price-sync')
        );
    }

    lps_accounting_prices_native_log('accounting_recalculation_finished', [
        'level' => in_array($status, ['COMPLETED', 'COMPLETED_WITH_WARNINGS', 'PREVIEW_READY', 'PREVIEW_READY_WITH_WARNINGS'], true) ? 'info' : 'warning',
        'source' => $state['source'] ?? 'cron',
        'token' => $state['lock_token'] ?? '',
        'message' => 'Native Folio accounting-price recalculation finished.',
        'context' => [
            'job_id' => $state['job_id'] ?? '',
            'warehouse_id' => $state['warehouse_id'] ?? 0,
            'status' => $status,
            'phase' => $state['phase'] ?? '',
            'committed_chunks' => $state['committed_chunks'] ?? 0,
            'warning_count' => $state['warning_count'] ?? 0,
        ],
    ]);

    return $result;
}

function lps_accounting_prices_native_continue_batch(string $source = 'cron'): array {
    $batch = lps_accounting_prices_native_batch_state();
    if (empty($batch['active']) || empty($batch['batch_id'])) {
        return ['ok' => false, 'httpStatus' => 409, 'body' => ['message' => 'batch_not_active']];
    }

    $job = lps_accounting_prices_native_job_state();
    if (!empty($job['running'])) {
        return ['ok' => false, 'httpStatus' => 409, 'body' => ['message' => 'job_running']];
    }

    $warehouse_id = absint($batch['current_warehouse_id'] ?? 0);
    if ($warehouse_id < 1) {
        $pending = lps_accounting_prices_native_normalize_warehouse_ids($batch['pending_warehouse_ids'] ?? []);
        $warehouse_id = absint(array_shift($pending));
        if ($warehouse_id < 1) {
            if (sanitize_key((string)($batch['stage'] ?? 'apply')) === 'preview') {
                $batch['stage'] = 'apply';
                $batch['pending_warehouse_ids'] = lps_accounting_prices_native_normalize_warehouse_ids(
                    $batch['approved_warehouse_ids'] ?? []
                );
                if (!$batch['pending_warehouse_ids']) {
                    lps_accounting_prices_native_finish_batch($batch);
                    return ['ok' => true, 'httpStatus' => 200, 'body' => ['status' => 'batch_completed_with_errors']];
                }
                $batch['status'] = 'waiting_next';
                lps_accounting_prices_native_store_batch($batch);
                lps_accounting_prices_native_schedule_batch_next();
                return ['ok' => true, 'httpStatus' => 202, 'body' => ['status' => 'apply_stage_queued']];
            }

            lps_accounting_prices_native_finish_batch($batch);
            return ['ok' => true, 'httpStatus' => 200, 'body' => ['status' => 'batch_completed']];
        }

        $batch['current_warehouse_id'] = $warehouse_id;
        $batch['pending_warehouse_ids'] = $pending;
    }

    $batch['status'] = 'starting';
    $batch['last_attempt_at'] = current_time('mysql');
    lps_accounting_prices_native_store_batch($batch);

    $result = lps_accounting_prices_native_start(
        $warehouse_id,
        sanitize_key((string)($batch['stage'] ?? 'apply')) === 'preview',
        $source === 'retry' ? 'retry' : 'cron',
        (string)$batch['batch_id']
    );

    if (!empty($result['ok']) && (int)($result['httpStatus'] ?? 0) === 202) {
        $batch = lps_accounting_prices_native_batch_state();
        $batch['status'] = 'running';
        lps_accounting_prices_native_store_batch($batch);
        return $result;
    }

    if (!empty($result['retryScheduled'])) {
        $batch = lps_accounting_prices_native_batch_state();
        $batch['status'] = 'waiting_lock';
        lps_accounting_prices_native_store_batch($batch);
        return $result;
    }

    $message = sanitize_textarea_field((string)($result['body']['message'] ?? __('The Java service did not accept the scheduled warehouse recalculation.', 'lavka-price-sync')));
    $batch = lps_accounting_prices_native_batch_state();
    if (!empty($batch['active']) && absint($batch['current_warehouse_id'] ?? 0) === $warehouse_id) {
        $stage = sanitize_key((string)($batch['stage'] ?? 'preview'));
        $start_body = is_array($result['body'] ?? null) ? $result['body'] : [];
        $start_body['errors'] = [[
            'code' => 'START_FAILED',
            'message' => $message,
            'details' => [
                'httpStatus' => absint($result['httpStatus'] ?? 0),
                'requestId' => sanitize_text_field((string)($start_body['reqId'] ?? '')),
            ],
        ]];
        $batch['results'][] = lps_accounting_prices_native_batch_result(
            [
                'warehouse_id' => $warehouse_id,
                'job_id' => '',
                'started_at' => $batch['last_attempt_at'] ?? current_time('mysql'),
            ],
            $start_body,
            $stage,
            'START_FAILED',
            'error',
            $message
        );
        $batch['current_warehouse_id'] = 0;
        lps_accounting_prices_native_advance_batch($batch);
    }
    return $result;
}

function lps_accounting_prices_native_run_scheduled(string $source = 'cron'): array {
    $options = lps_accounting_prices_native_cron_options();
    if ($source === 'cron') lps_accounting_prices_native_reschedule();

    if (empty($options['enabled']) || empty($options['automatic_apply_confirmed'])) {
        return ['ok' => false, 'httpStatus' => 400, 'body' => ['message' => 'schedule_disabled']];
    }

    $warehouse_ids = lps_accounting_prices_native_normalize_warehouse_ids($options['warehouse_ids'] ?? []);
    if (!$warehouse_ids) {
        lps_accounting_prices_native_pause_schedule(__('The scheduled Folio recalculation has no valid warehouses.', 'lavka-price-sync'));
        return ['ok' => false, 'httpStatus' => 400, 'body' => ['message' => 'warehouses_required']];
    }

    $batch = lps_accounting_prices_native_batch_state();
    if ($source === 'cron') {
        if (!empty($batch['active'])) {
            lps_accounting_prices_native_log('accounting_recalculation_batch_skipped', [
                'level' => 'warning',
                'message' => 'A new scheduled batch was skipped because the previous batch is still active.',
                'context' => ['batch_id' => $batch['batch_id'] ?? ''],
            ]);
            return ['ok' => false, 'httpStatus' => 409, 'body' => ['message' => 'batch_already_active']];
        }
        lps_accounting_prices_native_create_batch($warehouse_ids);
    } elseif (empty($batch['active'])) {
        return ['ok' => false, 'httpStatus' => 409, 'body' => ['message' => 'batch_not_active']];
    }

    return lps_accounting_prices_native_continue_batch($source);
}

add_action(LPS_ACCOUNTING_PRICES_NATIVE_CRON_HOOK, function () {
    lps_accounting_prices_native_run_scheduled('cron');
});

add_action(LPS_ACCOUNTING_PRICES_NATIVE_RETRY_HOOK, function () {
    lps_accounting_prices_native_run_scheduled('retry');
});

add_action(LPS_ACCOUNTING_PRICES_NATIVE_POLL_HOOK, function () {
    lps_accounting_prices_native_poll(true);
});

add_action(LPS_ACCOUNTING_PRICES_NATIVE_BATCH_NEXT_HOOK, function () {
    lps_accounting_prices_native_continue_batch('cron');
});

function lps_accounting_prices_native_maybe_schedule(): void {
    $options = lps_accounting_prices_native_cron_options();
    if (!empty($options['enabled']) && !wp_next_scheduled(LPS_ACCOUNTING_PRICES_NATIVE_CRON_HOOK)) {
        lps_accounting_prices_native_reschedule();
    }

    $state = lps_accounting_prices_native_job_state();
    if (empty($state['running'])) {
        $batch = lps_accounting_prices_native_batch_state();
        if (!empty($batch['active'])
            && !wp_next_scheduled(LPS_ACCOUNTING_PRICES_NATIVE_RETRY_HOOK)
            && !wp_next_scheduled(LPS_ACCOUNTING_PRICES_NATIVE_BATCH_NEXT_HOOK)) {
            lps_accounting_prices_native_schedule_batch_next(10);
        }
        return;
    }

    $lock = function_exists('lavka_ecosystem_lock_get') ? lavka_ecosystem_lock_get() : null;
    $token = (string)($state['lock_token'] ?? '');
    if ($lock && $token !== '' && isset($lock['token']) && hash_equals((string)$lock['token'], $token)) {
        if ((int)($lock['expires_at'] ?? 0) < time() + 10 * MINUTE_IN_SECONDS && function_exists('lavka_ecosystem_lock_touch')) {
            lavka_ecosystem_lock_touch($token, 2 * HOUR_IN_SECONDS);
        }
    } elseif (!$lock) {
        $recovered = lps_accounting_prices_native_acquire_lock('recovery', absint($state['warehouse_id'] ?? 0));
        if (!empty($recovered['ok'])) {
            $state['lock_token'] = $recovered['token'] ?? '';
            lps_accounting_prices_native_store_job($state);
        }
    }

    if (!wp_next_scheduled(LPS_ACCOUNTING_PRICES_NATIVE_POLL_HOOK)) {
        lps_accounting_prices_native_schedule_poll(10);
    }
}
add_action('init', 'lps_accounting_prices_native_maybe_schedule', 30);

add_action('admin_post_lps_accounting_prices_save_cron', function () {
    if (!current_user_can(LPS_CAP)) wp_die(esc_html__('You do not have permission to perform this operation.', 'lavka-price-sync'));
    check_admin_referer('lps_accounting_prices_save_cron');

    $enabled = !empty($_POST['enabled']);
    $warehouse_ids = lps_accounting_prices_native_normalize_warehouse_ids(
        isset($_POST['warehouse_ids']) ? (array)wp_unslash($_POST['warehouse_ids']) : []
    );
    $weekday = sanitize_key(wp_unslash($_POST['weekday'] ?? 'sun'));
    $allowed_days = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];
    if (!in_array($weekday, $allowed_days, true)) $weekday = 'sun';
    $time = sanitize_text_field(wp_unslash($_POST['time'] ?? '03:30'));
    if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $time)) $time = '03:30';
    $confirmed = !empty($_POST['automatic_apply_confirmed']);

    $error = '';
    if ($enabled && !$warehouse_ids) {
        $error = 'warehouse';
    } elseif ($enabled && !$confirmed) {
        $error = 'confirmation';
    }

    if ($error === '') {
        update_option(LPS_ACCOUNTING_PRICES_NATIVE_CRON_OPTION, [
            'enabled' => $enabled,
            'warehouse_id' => $warehouse_ids[0] ?? 0,
            'warehouse_ids' => $warehouse_ids,
            'weekday' => $weekday,
            'time' => $time,
            'automatic_apply_confirmed' => $confirmed,
            'paused_reason' => '',
        ], false);
        wp_clear_scheduled_hook(LPS_ACCOUNTING_PRICES_NATIVE_RETRY_HOOK);
        wp_clear_scheduled_hook(LPS_ACCOUNTING_PRICES_NATIVE_BATCH_NEXT_HOOK);
        if (!$enabled) {
            $batch = lps_accounting_prices_native_batch_state();
            if (!empty($batch['active'])) {
                $batch['active'] = false;
                $batch['status'] = 'disabled';
                $batch['completed_at'] = current_time('mysql');
                lps_accounting_prices_native_store_batch($batch);
            }
        }
        lps_accounting_prices_native_reschedule();
    }

    $url = add_query_arg([
        'page' => 'lps-accounting-prices',
        'cron_saved' => $error === '' ? '1' : '0',
        'cron_error' => $error,
    ], admin_url('admin.php'));
    wp_safe_redirect($url);
    exit;
});

function lps_accounting_prices_native_clear_schedules(): void {
    wp_clear_scheduled_hook(LPS_ACCOUNTING_PRICES_NATIVE_CRON_HOOK);
    wp_clear_scheduled_hook(LPS_ACCOUNTING_PRICES_NATIVE_RETRY_HOOK);
    wp_clear_scheduled_hook(LPS_ACCOUNTING_PRICES_NATIVE_POLL_HOOK);
    wp_clear_scheduled_hook(LPS_ACCOUNTING_PRICES_NATIVE_BATCH_NEXT_HOOK);
}
