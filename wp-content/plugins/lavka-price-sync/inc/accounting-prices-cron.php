<?php
if (!defined('ABSPATH')) exit;

const LPS_ACCOUNTING_PRICES_NATIVE_FULL_PATH = '/admin/folio/accounting-prices/recalculate/native-full';
const LPS_ACCOUNTING_PRICES_NATIVE_STATUS_PATH = '/admin/folio/accounting-prices/recalculate/native-full/status';
const LPS_ACCOUNTING_PRICES_NATIVE_CRON_OPTION = 'lps_accounting_prices_native_cron';
const LPS_ACCOUNTING_PRICES_NATIVE_JOB_OPTION = 'lps_accounting_prices_native_job';
const LPS_ACCOUNTING_PRICES_NATIVE_CRON_HOOK = 'lps_accounting_prices_native_cron';
const LPS_ACCOUNTING_PRICES_NATIVE_RETRY_HOOK = 'lps_accounting_prices_native_retry';
const LPS_ACCOUNTING_PRICES_NATIVE_POLL_HOOK = 'lps_accounting_prices_native_poll';
const LPS_ACCOUNTING_PRICES_NATIVE_LOCK_PROCESS = 'accounting_prices_native_full';
const LPS_ACCOUNTING_PRICES_NATIVE_POLL_OUTAGE_LIMIT = 2 * HOUR_IN_SECONDS;

function lps_accounting_prices_native_cron_options(): array {
    $options = get_option(LPS_ACCOUNTING_PRICES_NATIVE_CRON_OPTION, []);

    return wp_parse_args(is_array($options) ? $options : [], [
        'enabled' => false,
        'warehouse_id' => 0,
        'weekday' => 'sun',
        'time' => '03:30',
        'automatic_apply_confirmed' => false,
        'paused_reason' => '',
    ]);
}

function lps_accounting_prices_native_job_state(): array {
    $state = get_option(LPS_ACCOUNTING_PRICES_NATIVE_JOB_OPTION, []);
    return is_array($state) ? $state : [];
}

function lps_accounting_prices_native_store_job(array $state): void {
    update_option(LPS_ACCOUNTING_PRICES_NATIVE_JOB_OPTION, $state, false);
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
        'last_committed_art' => sanitize_text_field((string)($body['lastCommittedArt'] ?? '')),
        'warning_count' => absint($body['warningCount'] ?? 0),
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
        'context' => ['warehouse_id' => absint($options['warehouse_id'] ?? 0)],
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

function lps_accounting_prices_native_pause_schedule(string $reason): void {
    $options = lps_accounting_prices_native_cron_options();
    $options['enabled'] = false;
    $options['paused_reason'] = sanitize_textarea_field($reason);
    update_option(LPS_ACCOUNTING_PRICES_NATIVE_CRON_OPTION, $options, false);
    wp_clear_scheduled_hook(LPS_ACCOUNTING_PRICES_NATIVE_CRON_HOOK);
    wp_clear_scheduled_hook(LPS_ACCOUNTING_PRICES_NATIVE_RETRY_HOOK);
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
        'BLOCKED_NEGATIVE_STOCK',
        'COMPLETED',
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

function lps_accounting_prices_native_start(int $warehouse_id, bool $preview_only, string $source = 'manual'): array {
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
            if (in_array($source, ['cron', 'retry'], true)) {
                $blocking_lock = function_exists('lavka_ecosystem_lock_get') ? lavka_ecosystem_lock_get() : null;
                lps_accounting_prices_native_schedule_retry($blocking_lock);
            }
            return [
                'ok' => false,
                'httpStatus' => 409,
                'body' => $latest['body'] ?? [
                    'status' => 'BUSY',
                    'running' => true,
                    'message' => __('A full Folio accounting-price recalculation is already running.', 'lavka-price-sync'),
                ],
            ];
        }
    }

    $source = in_array($source, ['manual', 'cron', 'retry', 'recovery'], true) ? $source : 'manual';
    $lock = lps_accounting_prices_native_acquire_lock($source, $warehouse_id);
    if (empty($lock['ok'])) {
        if (in_array($source, ['cron', 'retry'], true)) {
            lps_accounting_prices_native_schedule_retry($lock['lock'] ?? null);
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
    if ($status === 'FAILED_PARTIAL') {
        lps_accounting_prices_native_pause_schedule(
            __('The previous scheduled recalculation ended after partial commits. Review Folio before enabling the schedule again.', 'lavka-price-sync')
        );
    }

    lps_accounting_prices_native_log('accounting_recalculation_finished', [
        'level' => in_array($status, ['COMPLETED', 'PREVIEW_READY'], true) ? 'info' : 'warning',
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

function lps_accounting_prices_native_run_scheduled(string $source = 'cron'): array {
    $options = lps_accounting_prices_native_cron_options();
    if ($source === 'cron') lps_accounting_prices_native_reschedule();

    if (empty($options['enabled']) || empty($options['automatic_apply_confirmed'])) {
        return ['ok' => false, 'httpStatus' => 400, 'body' => ['message' => 'schedule_disabled']];
    }

    $warehouse_id = absint($options['warehouse_id'] ?? 0);
    if ($warehouse_id < 1) {
        lps_accounting_prices_native_pause_schedule(__('The scheduled Folio recalculation has no valid warehouse.', 'lavka-price-sync'));
        return ['ok' => false, 'httpStatus' => 400, 'body' => ['message' => 'warehouse_required']];
    }

    return lps_accounting_prices_native_start($warehouse_id, false, $source === 'retry' ? 'retry' : 'cron');
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

function lps_accounting_prices_native_maybe_schedule(): void {
    $options = lps_accounting_prices_native_cron_options();
    if (!empty($options['enabled']) && !wp_next_scheduled(LPS_ACCOUNTING_PRICES_NATIVE_CRON_HOOK)) {
        lps_accounting_prices_native_reschedule();
    }

    $state = lps_accounting_prices_native_job_state();
    if (empty($state['running'])) return;

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
    $warehouse_id = absint($_POST['warehouse_id'] ?? 0);
    $weekday = sanitize_key(wp_unslash($_POST['weekday'] ?? 'sun'));
    $allowed_days = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];
    if (!in_array($weekday, $allowed_days, true)) $weekday = 'sun';
    $time = sanitize_text_field(wp_unslash($_POST['time'] ?? '03:30'));
    if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $time)) $time = '03:30';
    $confirmed = !empty($_POST['automatic_apply_confirmed']);

    $error = '';
    if ($enabled && $warehouse_id < 1) {
        $error = 'warehouse';
    } elseif ($enabled && !$confirmed) {
        $error = 'confirmation';
    }

    if ($error === '') {
        update_option(LPS_ACCOUNTING_PRICES_NATIVE_CRON_OPTION, [
            'enabled' => $enabled,
            'warehouse_id' => $warehouse_id,
            'weekday' => $weekday,
            'time' => $time,
            'automatic_apply_confirmed' => $confirmed,
            'paused_reason' => '',
        ], false);
        wp_clear_scheduled_hook(LPS_ACCOUNTING_PRICES_NATIVE_RETRY_HOOK);
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
}
