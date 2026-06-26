<?php
if (!defined('ABSPATH')) exit;

/**
 * Расписание для price-sync
 * Моды: daily | weekly | dates (без "каждые N минут")
 * Опция хранится в LPS_OPT_CRON:
 * [
 *   'enabled' => bool,
 *   'mode'    => 'daily'|'weekly'|'dates',
 *   'time'    => 'HH:MM',
 *   'days'    => ['mon','tue',...],   // для weekly
 *   'dates'   => ['2025-10-15 14:00', ...], // для dates (локальное время WP)
 *   'batch'   => 500
 * ]
 */

// Добавим еженедельный интервал
add_filter('cron_schedules', function ($s) {
    if (!isset($s['weekly'])) {
        $s['weekly'] = [
            'interval' => 7 * DAY_IN_SECONDS,
            'display'  => __('Once Weekly', 'lavka-price-sync'),
        ];
    }
    return $s;
});

// use define+guard to avoid "already defined" when file loads twice
if (!defined('LPS_CRON_HOOK')) {
    define('LPS_CRON_HOOK', 'lps_cron_price_sync');
}

// Основной хук
add_action(LPS_CRON_HOOK, function () {
    lps_run_price_sync_now('cron');
});

/** Запустить полный синк цен (постранично, без AJAX) */
function lps_run_price_sync_now(string $mode = 'cron'): array {
    if (!function_exists('lps_count_all_skus')) return ['ok'=>false,'error'=>'helpers_missing'];

    $mode = $mode === 'manual' ? 'manual' : 'cron';
    $lock_token = null;

    if (function_exists('lavka_ecosystem_lock_acquire')) {
        $lock = lavka_ecosystem_lock_acquire(
            'lavka-price-sync',
            'price_sync_all',
            $mode,
            __('Price synchronization', 'lavka-price-sync'),
            2 * HOUR_IN_SECONDS
        );

        if (empty($lock['ok'])) {
            if ($mode === 'cron' && function_exists('wp_schedule_single_event')) {
                wp_schedule_single_event(time() + LAVKA_ECOSYSTEM_LOCK_CRON_DELAY, LPS_CRON_HOOK);
            }

            return [
                'ok' => false,
                'error' => 'lavka_sync_locked',
                'message' => $lock['message'] ?? __('Synchronization is already running. Please try again later.', 'lavka-price-sync'),
                'lock' => $lock['lock'] ?? null,
            ];
        }

        $lock_token = $lock['token'] ?? null;
    }

    $o     = get_option(LPS_OPT_CRON, []);
    $batch = max(50, min(2000, (int)($o['batch'] ?? 500)));
    $log_id = function_exists('lps_log_start') ? lps_log_start($mode) : 0;
    $log_closed = false;

    if ($log_id && function_exists('lps_log_finish')) {
        register_shutdown_function(function () use ($log_id, &$log_closed, $lock_token) {
            if (!$log_closed) {
                $error = error_get_last();
                if ($error) {
                    lps_log_finish($log_id, [
                        'ok' => false,
                        'sample' => [[
                            'error' => 'fatal',
                            'message' => $error['message'] ?? '',
                            'file' => $error['file'] ?? '',
                            'line' => $error['line'] ?? '',
                        ]],
                    ]);
                    $log_closed = true;
                }
            }

            if ($lock_token && function_exists('lavka_ecosystem_lock_release')) {
                lavka_ecosystem_lock_release($lock_token);
            }
        });
    } elseif ($lock_token) {
        register_shutdown_function(function () use ($lock_token) {
            if (function_exists('lavka_ecosystem_lock_release')) {
                lavka_ecosystem_lock_release($lock_token);
            }
        });
    }

    $finish = function (array $result) use ($log_id, &$log_closed, $lock_token): array {
        if ($log_id && function_exists('lps_log_finish')) {
            lps_log_finish($log_id, $result);
            $log_closed = true;
        }

        if ($lock_token && function_exists('lavka_ecosystem_lock_release')) {
            lavka_ecosystem_lock_release($lock_token);
        }

        return $result;
    };

    if ($mode === 'cron' && $lock_token === null && function_exists('lavka_ecosystem_lock_acquire')) {
        return $finish([
            'ok' => false,
            'error' => 'lavka_sync_locked',
        ]);
    }

    if ($mode === 'cron' && function_exists('lavka_ecosystem_lock_get')) {
        $active_lock = lavka_ecosystem_lock_get();
        if ($active_lock && ($active_lock['token'] ?? null) !== $lock_token) {
            if (function_exists('wp_schedule_single_event')) {
                wp_schedule_single_event(time() + LAVKA_ECOSYSTEM_LOCK_CRON_DELAY, LPS_CRON_HOOK);
            }

            return $finish([
                'ok' => false,
                'error' => 'lavka_sync_locked',
                'sample' => [[
                    'message' => 'Price synchronization skipped because another Lavka synchronization is running.',
                    'lock' => $active_lock,
                ]],
            ]);
        }
    }

    try {
        $total = (int) lps_count_all_skus();
        $pages = (int) ceil(max(1,$total) / $batch);
        if ($log_id && function_exists('lps_log_progress')) {
            lps_log_progress($log_id, [
                'total' => $total,
                'sample' => [
                    'progress' => [
                        'page' => 0,
                        'pages' => $pages,
                        'batch' => $batch,
                        'last_page_at' => current_time('mysql'),
                    ],
                ],
            ]);
        }
    } catch (\Throwable $e) {
        return $finish([
            'ok' => false,
            'error' => $e->getMessage(),
            'sample' => [[
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]],
        ]);
    }

    $updatedRetail = 0;
    $updatedRoles  = 0;
    $notFound      = 0;
    $sample        = [];

        // anchor: LOGS-NF-ACCUM
    $notFoundSkus = [];

    for ($page=0; $page<$pages; $page++) {
        $skus = lps_get_skus_slice($page*$batch, $batch);
        if (!$skus) break;

        $j = lps_java_fetch_prices_multi($skus);
        if (empty($j['ok'])) {
            error_log('[LPS] price sync java_error: ' . ($j['error'] ?? 'unknown'));
            $result = [
                'ok' => false,
                'error' => $j['error'] ?? 'java_error',
                'total' => $total,
                'updated_retail' => $updatedRetail,
                'updated_roles' => $updatedRoles,
                'not_found' => $notFound,
                'sample' => $sample,
            ];
            return $finish($result);
        }

        $applied = lps_apply_prices_multi($j['items'], /*dry*/ false);
            // anchor: LOGS-COLLECT-NF
            foreach ($applied['items'] as $it) {
                if (empty($it['found']) && !empty($it['sku'])) {
                    $notFoundSkus[] = (string)$it['sku'];
                }
            }
        $updatedRetail += (int)$applied['updated_retail'];
        $updatedRoles  += (int)$applied['updated_roles'];
        $notFound      += (int)$applied['not_found'];

        if (count($sample) < 10) {
            $sample = array_merge($sample, array_slice($applied['items'], 0, 10 - count($sample)));
        }

        if ($log_id && function_exists('lps_log_progress')) {
            lps_log_progress($log_id, [
                'total' => $total,
                'updated_retail' => $updatedRetail,
                'updated_roles' => $updatedRoles,
                'not_found' => $notFound,
                'sample' => [
                    'progress' => [
                        'page' => $page + 1,
                        'pages' => $pages,
                        'batch' => $batch,
                        'last_page_at' => current_time('mysql'),
                    ],
                    'sample' => array_slice($sample, 0, 3),
                ],
            ]);
        }

        if ($lock_token && function_exists('lavka_ecosystem_lock_touch')) {
            lavka_ecosystem_lock_touch($lock_token, 2 * HOUR_IN_SECONDS, [
                'progress' => [
                    'page' => $page + 1,
                    'pages' => $pages,
                    'updated_retail' => $updatedRetail,
                    'updated_roles' => $updatedRoles,
                    'not_found' => $notFound,
                ],
            ]);
        }
    }
        // anchor: LOGS-FINISH
    $csvPath = null;
    if (!empty($notFoundSkus) && function_exists('lps_save_not_found_csv')) {
        $notFoundSkus = array_values(array_unique($notFoundSkus));
        $csvPath = lps_save_not_found_csv($notFoundSkus);
    }

    $result = [
        'ok' => true,
        'total' => $total,
        'updated_retail' => $updatedRetail,
        'updated_roles'  => $updatedRoles,
        'not_found'      => $notFound,
        'sample'         => $sample,
        'csv_path'       => $csvPath,
    ];

    return $finish($result);
}

/** Очистить все события нашего хука */
function lps_cron_clear_all(): void {
    while (wp_next_scheduled(LPS_CRON_HOOK)) {
        wp_clear_scheduled_hook(LPS_CRON_HOOK);
    }
}

/** Пересоздать расписание по текущей опции */
function lps_cron_reschedule_price(): void {
    lps_cron_clear_all();

    $opt = get_option(LPS_OPT_CRON, []);
    $enabled = !empty($opt['enabled']);
    if (!$enabled) return;

    $mode = $opt['mode'] ?? 'daily';
    $tz   = wp_timezone();
    $now  = new DateTime('now', $tz);

    // Парсим время HH:MM (для daily/weekly)
    $hhmm = (string)($opt['time'] ?? '03:30');
    if (!preg_match('/^(\d{1,2}):(\d{2})$/', $hhmm, $m)) $m = [0,3,30];
    $H = (int)$m[1]; $i = (int)$m[2];

    if ($mode === 'daily') {
        $run = (clone $now)->setTime($H, $i, 0);
        if ($run <= $now) $run->modify('+1 day');
        wp_schedule_event($run->getTimestamp(), 'daily', LPS_CRON_HOOK);

    } elseif ($mode === 'weekly') {
        $days = is_array($opt['days'] ?? null) ? $opt['days'] : [];
        $map  = ['mon'=>1,'tue'=>2,'wed'=>3,'thu'=>4,'fri'=>5,'sat'=>6,'sun'=>0]; // DateTime: 0=Sun..6=Sat
        foreach ($days as $code) {
            if (!isset($map[$code])) continue;
            // ближайший такой день недели
            $run = (clone $now)->setTime($H, $i, 0);
            $targetDow = $map[$code];
            while ((int)$run->format('w') !== $targetDow || $run <= $now) {
                $run->modify('+1 day');
            }
            // расписание раз в неделю от этой точки
            wp_schedule_event($run->getTimestamp(), 'weekly', LPS_CRON_HOOK);
        }

    } elseif ($mode === 'dates') {
        $dates = is_array($opt['dates'] ?? null) ? $opt['dates'] : [];
        foreach ($dates as $ds) {
            try {
                $dt = new DateTime((string)$ds, $tz);
                if ($dt > $now) {
                    wp_schedule_single_event($dt->getTimestamp(), LPS_CRON_HOOK);
                }
            } catch (\Throwable $e) {}
        }
    }
    error_log('[LPS] reschedule: mode='.($mode).' enabled=1; next='. lps_cron_next_ts());
}

/**
 * Вычислить плановый next-run из опции (локальная TZ сайта), не заглядывая в WP-Cron.
 * Возвращает Unix timestamp или null.
 */
function lps_cron_calc_next_from_option(?array $opt): ?int {
    if (!$opt || empty($opt['enabled'])) return null;

    $mode = $opt['mode'] ?? 'daily';
    $tz   = wp_timezone();
    $now  = new DateTime('now', $tz);

    // HH:MM
    $hhmm = (string)($opt['time'] ?? '03:30');
    if (!preg_match('/^(\d{1,2}):(\d{2})$/', $hhmm, $m)) $m = [0,3,30];
    $H = (int)$m[1]; $i = (int)$m[2];

    if ($mode === 'daily') {
        $run = (clone $now)->setTime($H, $i, 0);
        if ($run <= $now) $run->modify('+1 day');
        return $run->getTimestamp();
    }

    if ($mode === 'weekly') {
        $days = is_array($opt['days'] ?? null) ? $opt['days'] : [];
        if (!$days) return null;
        $map  = ['sun'=>0,'mon'=>1,'tue'=>2,'wed'=>3,'thu'=>4,'fri'=>5,'sat'=>6];
        $best = null;
        foreach ($days as $code) {
            if (!isset($map[$code])) continue;
            $run = (clone $now)->setTime($H,$i,0);
            $target = $map[$code];
            while ((int)$run->format('w') !== $target || $run <= $now) {
                $run->modify('+1 day');
            }
            if (!$best || $run < $best) $best = $run;
        }
        return $best ? $best->getTimestamp() : null;
    }

    if ($mode === 'dates') {
        $dates = is_array($opt['dates'] ?? null) ? $opt['dates'] : [];
        $best = null;
        foreach ($dates as $ds) {
            try {
                $dt = new DateTime((string)$ds, $tz);
                if ($dt > $now && (!$best || $dt < $best)) $best = $dt;
            } catch (\Throwable $e) {}
        }
        return $best ? $best->getTimestamp() : null;
    }

    return null;
}



function lps_cron_next_event(?bool $recurring_only = null): ?array {
    $crons = _get_cron_array();
    if (!is_array($crons)) return null;

    foreach ($crons as $time => $hooks) {
        if (empty($hooks[LPS_CRON_HOOK]) || !is_array($hooks[LPS_CRON_HOOK])) {
            continue;
        }

        foreach ($hooks[LPS_CRON_HOOK] as $event) {
            $schedule = isset($event['schedule']) ? (string)$event['schedule'] : '';
            $is_recurring = $schedule !== '';

            if ($recurring_only === true && !$is_recurring) {
                continue;
            }

            if ($recurring_only === false && $is_recurring) {
                continue;
            }

            return [
                'timestamp' => (int)$time,
                'schedule'  => $schedule,
            ];
        }
    }

    return null;
}

/**
 * Вернёт timestamp ближайшего события по нашему хуку.
 */
function lps_cron_next_ts(?bool $recurring_only = null): ?int {
    $event = lps_cron_next_event($recurring_only);

    return $event ? (int)$event['timestamp'] : null;
}

/**
 * Отформатировать дату в часовом поясе сайта.
 */
function lps_fmt_site_dt(int $ts): string {
    // WP хранит cron в Unix TS (локальное время интерпретируется как GMT),
    // поэтому приводим через date_i18n / wp_date.
    if (function_exists('wp_date')) {
        return wp_date('Y-m-d H:i:s', $ts, wp_timezone());
    }
    return date_i18n('Y-m-d H:i:s', $ts);
}

// Если включено, но события нет — перепланировать молча.
add_action('admin_init', function(){
    $opt = get_option(LPS_OPT_CRON, []);
    if (empty($opt['enabled'])) return;

    // событие уже есть?
    $event = wp_get_scheduled_event(LPS_CRON_HOOK);
    if ($event && (empty($event->schedule) || $event->schedule !== 'lps_hourly')) return;

    // self-heal
    lps_cron_reschedule_price();
});
