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

const LPS_CRON_HOOK = 'lps_cron_sync_prices';

// Основной хук
add_action(LPS_CRON_HOOK, function () {
    lps_run_price_sync_now();
});

/** Запустить полный синк цен (постранично, без AJAX) */
function lps_run_price_sync_now(): array {
    if (!function_exists('lps_count_all_skus')) return ['ok'=>false,'error'=>'helpers_missing'];

    $o     = get_option(LPS_OPT_CRON, []);
    $batch = max(50, min(2000, (int)($o['batch'] ?? 500)));

    $total = (int) lps_count_all_skus();
    $pages = (int) ceil(max(1,$total) / $batch);

    $updatedRetail = 0;
    $updatedRoles  = 0;
    $notFound      = 0;
    $sample        = [];

    for ($page=0; $page<$pages; $page++) {
        $skus = lps_get_skus_slice($page*$batch, $batch);
        if (!$skus) break;

        $j = lps_java_fetch_prices_multi($skus);
        if (empty($j['ok'])) {
            error_log('[LPS] cron java_error: ' . ($j['error'] ?? 'unknown'));
            return ['ok'=>false, 'error'=>$j['error'] ?? 'java_error'];
        }

        $applied = lps_apply_prices_multi($j['items'], /*dry*/ false);
        $updatedRetail += (int)$applied['updated_retail'];
        $updatedRoles  += (int)$applied['updated_roles'];
        $notFound      += (int)$applied['not_found'];

        if (count($sample) < 10) {
            $sample = array_merge($sample, array_slice($applied['items'], 0, 10 - count($sample)));
        }
    }

    return [
        'ok' => true,
        'total' => $total,
        'updated_retail' => $updatedRetail,
        'updated_roles'  => $updatedRoles,
        'not_found'      => $notFound,
        'sample'         => $sample,
    ];
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
}