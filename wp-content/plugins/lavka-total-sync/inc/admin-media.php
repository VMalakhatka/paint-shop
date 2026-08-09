<?php
if (!defined('ABSPATH')) exit;

/**
 * Админ-страница «Media Sync (images)» для Lavka Total Sync.
 *
 * Два сценария:
 *  1) По диапазону SKU (курсор)  → POST {JAVA}/admin/media/sync/range
 *  2) По списку SKU              → POST {JAVA}/admin/media/sync
 *
 * Используются настройки из основного плагина:
 *  - java_base_url
 *  - api_token
 *
 * Ничего не ломаем в admin-ui.php — это отдельная страница и отдельные AJAX-действия.
 */

/** Крон: хук для задачи синхронизации изображений по диапазону */
if (!defined('LTS_MEDIA_CRON_HOOK')) define('LTS_MEDIA_CRON_HOOK', 'lts_media_cron_event');
if (!defined('LTS_MEDIA_REINDEX_CRON_HOOK')) define('LTS_MEDIA_REINDEX_CRON_HOOK', 'lts_media_reindex_cron_event');

/**
 * Вычислить следующий запуск по настройкам (daily/weekly) в ЧАСОВОМ ПОЯСЕ САЙТА.
 * Возвращает int unix timestamp (UTC).
 */
function lts_media_cron_next_ts(array $o): ?int {
    $enabled = !empty($o['media_cron_enabled']);
    if (!$enabled) return null;
    $freq = ($o['media_cron_freq'] ?? 'daily'); // daily|weekly
    $hh = (int)($o['media_cron_h'] ?? 2);
    $mm = (int)($o['media_cron_m'] ?? 0);
    $dow = (int)($o['media_cron_dow'] ?? 1);   // 1=Mon..7=Sun

    $tz = function_exists('wp_timezone') ? wp_timezone() : new DateTimeZone(get_option('timezone_string') ?: 'UTC');
    $now = new DateTime('now', $tz);
    $next = clone $now;
    $next->setTime($hh, $mm, 0);

    if ($freq === 'daily') {
        if ($next <= $now) $next->modify('+1 day');
    } else { // weekly
        // PHP: 1(Mon)..7(Sun)
        $cur = (int)$now->format('N');
        if ($cur > $dow || ($cur === $dow && $next <= $now)) {
            // следующая неделя
            $days = 7 - $cur + $dow;
            $next->modify("+{$days} days");
        } elseif ($cur < $dow) {
            $days = $dow - $cur;
            $next->modify("+{$days} days");
        }
    }
    // вернуть в UTC
    $nextUtc = clone $next; $nextUtc->setTimezone(new DateTimeZone('UTC'));
    return $nextUtc->getTimestamp();
}

/** Снять все запланированные события этого плагина */
function lts_media_cron_unschedule_all(): void {
    while ($ts = wp_next_scheduled(LTS_MEDIA_CRON_HOOK)) {
        wp_unschedule_event($ts, LTS_MEDIA_CRON_HOOK);
    }
}

/** Запланировать следующий одиночный запуск в соответствии с настройками */
function lts_media_cron_schedule_next(): ?int {
    if (!function_exists('lts_get_options')) return null;
    $o = lts_get_options();
    if (empty($o['media_cron_enabled'])) return null;
    $ts = lts_media_cron_next_ts($o);
    if ($ts) {
        wp_schedule_single_event($ts, LTS_MEDIA_CRON_HOOK);
        // сохраним для отображения
        $o['media_cron_next_ts'] = $ts;
        update_option(defined('LTS_OPT') ? LTS_OPT : 'lts_options', $o, false);
    }
    return $ts;
}

function lts_media_reindex_cron_next_ts(array $o): ?int {
    if (empty($o['media_reindex_enabled'])) return null;

    $freq = in_array($o['media_reindex_freq'] ?? 'monthly', ['daily', 'weekly', 'monthly'], true)
        ? $o['media_reindex_freq']
        : 'monthly';
    $hh  = max(0, min(23, (int)($o['media_reindex_h'] ?? 3)));
    $mm  = max(0, min(59, (int)($o['media_reindex_m'] ?? 0)));
    $dow = max(1, min(7, (int)($o['media_reindex_dow'] ?? 1)));
    $dom = max(1, min(28, (int)($o['media_reindex_dom'] ?? 1)));

    $tz = function_exists('wp_timezone') ? wp_timezone() : new DateTimeZone(get_option('timezone_string') ?: 'UTC');
    $now = new DateTime('now', $tz);
    $next = clone $now;

    if ($freq === 'daily') {
        $next->setTime($hh, $mm, 0);
        if ($next <= $now) $next->modify('+1 day');
    } elseif ($freq === 'weekly') {
        $next->setTime($hh, $mm, 0);
        $cur = (int)$now->format('N');
        if ($cur > $dow || ($cur === $dow && $next <= $now)) {
            $next->modify('+' . (7 - $cur + $dow) . ' days');
        } elseif ($cur < $dow) {
            $next->modify('+' . ($dow - $cur) . ' days');
        }
    } else {
        $next->setDate((int)$now->format('Y'), (int)$now->format('n'), $dom);
        $next->setTime($hh, $mm, 0);
        if ($next <= $now) {
            $next->modify('first day of next month');
            $next->setDate((int)$next->format('Y'), (int)$next->format('n'), $dom);
            $next->setTime($hh, $mm, 0);
        }
    }

    $nextUtc = clone $next; $nextUtc->setTimezone(new DateTimeZone('UTC'));
    return $nextUtc->getTimestamp();
}

function lts_media_reindex_cron_unschedule_all(): void {
    while ($ts = wp_next_scheduled(LTS_MEDIA_REINDEX_CRON_HOOK)) {
        wp_unschedule_event($ts, LTS_MEDIA_REINDEX_CRON_HOOK);
    }
}

function lts_media_reindex_cron_schedule_next(): ?int {
    if (!function_exists('lts_get_options')) return null;
    $o = lts_get_options();
    if (empty($o['media_reindex_enabled'])) return null;
    $ts = lts_media_reindex_cron_next_ts($o);
    if ($ts) {
        wp_schedule_single_event($ts, LTS_MEDIA_REINDEX_CRON_HOOK);
        $o['media_reindex_next_ts'] = $ts;
        update_option(defined('LTS_OPT') ? LTS_OPT : 'lts_options', $o, false);
    }
    return $ts;
}

function lts_media_reindex_run(string $source): array {
    return lts_call_java_media_locked(
        '/admin/media/reindex',
        [],
        $source,
        'media_reindex'
    );
}

/**
 * Исполнитель крона: дергает Java /admin/media/sync/range c сохранёнными параметрами.
 * После выполнения сам перепланирует следующий запуск (если включено).
 */
add_action(LTS_MEDIA_CRON_HOOK, function(){
    if (!function_exists('lts_get_options')) return;
    $o = lts_get_options();
    if (empty($o['media_cron_enabled'])) return;

    $payload = [
        'fromSku'         => (string)($o['media_from_sku'] ?? ''),
        'toSku'           => (string)($o['media_to_sku'] ?? ''),
        'chunkSize'       => max(1, (int)($o['media_chunk'] ?? 500)),
        'mode'            => (string)($o['media_mode'] ?? 'both'),
        'galleryStartPos' => max(0, (int)($o['media_gstart'] ?? 1)),
        'limitPerSku'     => max(0, (int)($o['media_limit_per_sku'] ?? 100)),
        'dry'             => !empty($o['media_dry']) ? true : false,
    ];
    // Выполняем запрос
    $res = ['ok'=>false, 'error'=>'media_sync_failed'];
    try {
        $res = lts_call_java_media_locked(
            '/admin/media/sync/range',
            $payload,
            'cron',
            'media_range_sync'
        );
        if (function_exists('lts_log_db')) {
            lts_log_db(!empty($res['ok']) ? 'info' : 'error', 'media_cron', [
                'result' => !empty($res['ok']) ? 'ok' : 'fail',
                'http'   => $res['http'] ?? 200,
            ]);
        }
    } catch (Throwable $e) {
        $res = ['ok'=>false, 'error'=>$e->getMessage()];
        error_log('[LTS][media_cron] exception: '.$e->getMessage());
    }

    if (($res['error'] ?? '') === 'lavka_sync_locked') {
        lts_ecosystem_schedule_retry(
            LTS_MEDIA_CRON_HOOK,
            'media_range_sync',
            $res['lock'] ?? null
        );
    } else {
        // Перепланируем следующий одиночный запуск
        lts_media_cron_schedule_next();
    }
});

add_action(LTS_MEDIA_REINDEX_CRON_HOOK, function(){
    if (!function_exists('lts_get_options')) return;
    $o = lts_get_options();
    if (empty($o['media_reindex_enabled'])) return;

    $res = ['ok'=>false, 'error'=>'media_reindex_failed'];
    try {
        $res = lts_media_reindex_run('cron');
        if (function_exists('lts_log_db')) {
            lts_log_db(!empty($res['ok']) ? 'info' : 'error', 'media_reindex_cron', [
                'result' => !empty($res['ok']) ? 'ok' : 'fail',
                'http'   => $res['http'] ?? 200,
                'json'   => $res['json'] ?? null,
            ]);
        }
    } catch (Throwable $e) {
        $res = ['ok'=>false, 'error'=>$e->getMessage()];
        error_log('[LTS][media_reindex_cron] exception: '.$e->getMessage());
    }

    if (($res['error'] ?? '') === 'lavka_sync_locked') {
        lts_ecosystem_schedule_retry(
            LTS_MEDIA_REINDEX_CRON_HOOK,
            'media_reindex',
            $res['lock'] ?? null
        );
    } else {
        lts_media_reindex_cron_schedule_next();
    }
});

/** AJAX: сохранить настройки крона для Media Sync (range only) и перепланировать */
add_action('wp_ajax_lts_media_cron_save', function(){
    $cap = defined('LTS_CAP') ? LTS_CAP : 'manage_options';
    if (!current_user_can($cap)) wp_send_json_error(['error'=>'forbidden'], 403);
    check_ajax_referer('lts_admin_nonce','nonce');
    if (!function_exists('lts_get_options')) wp_send_json_error(['error'=>'missing_options']);

    $o = lts_get_options();
    $o['media_cron_enabled'] = !empty($_POST['enabled']) ? 1 : 0;
    $o['media_cron_freq']    = in_array($_POST['freq'] ?? 'daily', ['daily','weekly'], true) ? $_POST['freq'] : 'daily';
    $o['media_cron_h']       = max(0, min(23, (int)($_POST['hh'] ?? 2)));
    $o['media_cron_m']       = max(0, min(59, (int)($_POST['mm'] ?? 0)));
    $o['media_cron_dow']     = max(1, min(7, (int)($_POST['dow'] ?? 1)));

    // Параметры самого запуска по диапазону
    $o['media_from_sku']       = sanitize_text_field((string)($_POST['fromSku'] ?? ''));
    $o['media_to_sku']         = sanitize_text_field((string)($_POST['toSku'] ?? ''));
    $o['media_chunk']          = max(1, (int)($_POST['chunkSize'] ?? 500));
    $o['media_mode']           = sanitize_text_field((string)($_POST['mode'] ?? 'both'));
    $o['media_gstart']         = max(0, (int)($_POST['galleryStartPos'] ?? 1));
    $o['media_limit_per_sku']  = max(0, (int)($_POST['limitPerSku'] ?? 100));
    $o['media_dry']            = !empty($_POST['dry']) ? 1 : 0;

    update_option(defined('LTS_OPT') ? LTS_OPT : 'lts_options', $o, false);

    // пересоздаём расписание
    lts_media_cron_unschedule_all();
    $ts = lts_media_cron_schedule_next();

    $tz = function_exists('wp_timezone') ? wp_timezone() : new DateTimeZone('UTC');
    $siteStr = $ts ? (new DateTime('@'.$ts))->setTimezone($tz)->format('Y-m-d H:i:s') : '—';
    $utcStr  = $ts ? gmdate('Y-m-d H:i:s', $ts) : '—';

    wp_send_json_success(['ok'=>true,'next_ts'=>$ts,'site_time'=>$siteStr,'utc_time'=>$utcStr]);
});

/** AJAX: статус и "следующий запуск" */
add_action('wp_ajax_lts_media_cron_status', function(){
    $cap = defined('LTS_CAP') ? LTS_CAP : 'manage_options';
    if (!current_user_can($cap)) wp_send_json_error(['error'=>'forbidden'], 403);
    check_ajax_referer('lts_admin_nonce','nonce');

    $ts = wp_next_scheduled(LTS_MEDIA_CRON_HOOK);
    $tz = function_exists('wp_timezone') ? wp_timezone() : new DateTimeZone('UTC');
    $siteStr = $ts ? (new DateTime('@'.$ts))->setTimezone($tz)->format('Y-m-d H:i:s') : __('Not scheduled','lavka-total-sync');
    $utcStr  = $ts ? gmdate('Y-m-d H:i:s', $ts) : __('Not scheduled','lavka-total-sync');

    wp_send_json_success(['next_ts'=>$ts,'site_time'=>$siteStr,'utc_time'=>$utcStr]);
});

/** AJAX: выполнить Cron-задачу прямо сейчас (одиночный запуск) */
add_action('wp_ajax_lts_media_cron_run_now', function(){
    $cap = defined('LTS_CAP') ? LTS_CAP : 'manage_options';
    if (!current_user_can($cap)) wp_send_json_error(['error'=>'forbidden'], 403);
    check_ajax_referer('lts_admin_nonce','nonce');

    wp_schedule_single_event(time()+1, LTS_MEDIA_CRON_HOOK);
    wp_send_json_success(['ok'=>true]);
});

add_action('wp_ajax_lts_media_reindex_run', function(){
    $cap = defined('LTS_CAP') ? LTS_CAP : 'manage_options';
    if (!current_user_can($cap)) wp_send_json_error(['error'=>'forbidden'], 403);
    check_ajax_referer('lts_admin_nonce','nonce');

    $res = lts_media_reindex_run('manual');
    if (function_exists('lts_log_db')) {
        lts_log_db(!empty($res['ok']) ? 'info' : 'error', 'media_reindex_manual', [
            'result' => !empty($res['ok']) ? 'ok' : 'fail',
            'message' => $res['error'] ?? null,
            'ctx'    => [
                'http' => $res['http'] ?? 200,
                'json' => $res['json'] ?? null,
                'raw'  => $res['raw'] ?? null,
                'lock' => $res['lock'] ?? null,
            ],
        ]);
    }
    if (!empty($res['ok'])) wp_send_json_success($res);
    wp_send_json_error($res);
});

add_action('wp_ajax_lts_media_reindex_cron_save', function(){
    $cap = defined('LTS_CAP') ? LTS_CAP : 'manage_options';
    if (!current_user_can($cap)) wp_send_json_error(['error'=>'forbidden'], 403);
    check_ajax_referer('lts_admin_nonce','nonce');
    if (!function_exists('lts_get_options')) wp_send_json_error(['error'=>'missing_options']);

    $o = lts_get_options();
    $freq = $_POST['freq'] ?? 'monthly';
    $o['media_reindex_enabled'] = !empty($_POST['enabled']) ? 1 : 0;
    $o['media_reindex_freq'] = in_array($freq, ['daily', 'weekly', 'monthly'], true) ? $freq : 'monthly';
    $o['media_reindex_h'] = max(0, min(23, (int)($_POST['hh'] ?? 3)));
    $o['media_reindex_m'] = max(0, min(59, (int)($_POST['mm'] ?? 0)));
    $o['media_reindex_dow'] = max(1, min(7, (int)($_POST['dow'] ?? 1)));
    $o['media_reindex_dom'] = max(1, min(28, (int)($_POST['dom'] ?? 1)));

    update_option(defined('LTS_OPT') ? LTS_OPT : 'lts_options', $o, false);

    lts_media_reindex_cron_unschedule_all();
    $ts = lts_media_reindex_cron_schedule_next();

    $tz = function_exists('wp_timezone') ? wp_timezone() : new DateTimeZone('UTC');
    $siteStr = $ts ? (new DateTime('@'.$ts))->setTimezone($tz)->format('Y-m-d H:i:s') : '—';
    $utcStr  = $ts ? gmdate('Y-m-d H:i:s', $ts) : '—';

    wp_send_json_success(['ok'=>true,'next_ts'=>$ts,'site_time'=>$siteStr,'utc_time'=>$utcStr]);
});

add_action('wp_ajax_lts_media_reindex_cron_status', function(){
    $cap = defined('LTS_CAP') ? LTS_CAP : 'manage_options';
    if (!current_user_can($cap)) wp_send_json_error(['error'=>'forbidden'], 403);
    check_ajax_referer('lts_admin_nonce','nonce');

    $ts = wp_next_scheduled(LTS_MEDIA_REINDEX_CRON_HOOK);
    $tz = function_exists('wp_timezone') ? wp_timezone() : new DateTimeZone('UTC');
    $siteStr = $ts ? (new DateTime('@'.$ts))->setTimezone($tz)->format('Y-m-d H:i:s') : __('Not scheduled','lavka-total-sync');
    $utcStr  = $ts ? gmdate('Y-m-d H:i:s', $ts) : __('Not scheduled','lavka-total-sync');

    wp_send_json_success(['next_ts'=>$ts,'site_time'=>$siteStr,'utc_time'=>$utcStr]);
});

/** Добавляем подпункт меню под "Total Sync" */
add_action('admin_menu', function () {
    add_submenu_page(
        'lts-main',
        __('Media Sync (images)', 'lavka-total-sync'),
        __('Media Sync (images)', 'lavka-total-sync'),
        defined('LTS_CAP') ? LTS_CAP : 'manage_options',
        'lts-media',
        'lts_render_media_sync_page'
    );
});

/**
 * Вспомогательный вызов Java media endpoint.
 *
 * @param string $path  относительный путь (напр. '/admin/media/sync' или '/admin/media/sync/range')
 * @param array  $payload тело запроса (ассоц. массив)
 * @return array         ['ok'=>true,'json'=>..] | ['ok'=>false,'error'=>..] | ['ok'=>false,'http'=>code,'raw'=>..]
 */
if (!function_exists('lts_call_java_media')) {
    function lts_call_java_media(string $path, array $payload): array {
        if (!function_exists('lts_get_options')) {
            return ['ok'=>false,'error'=>'missing_options'];
        }
        $opts = lts_get_options();
        $base = rtrim((string)($opts['java_base_url'] ?? ''), '/');
        if ($base === '') return ['ok'=>false,'error'=>'java_base_url_missing'];

        // Нормализуем путь
        $path = '/' . ltrim($path, '/');
        $url  = $base . $path;

        $headers = [
            'Accept'       => 'application/json',
            'Content-Type' => 'application/json; charset=utf-8',
            'User-Agent'   => defined('LTS_USER_AGENT') ? LTS_USER_AGENT : 'Lavka-Total-Sync',
        ];
        if (!empty($opts['api_token'])) {
            $headers['Authorization'] = 'Bearer ' . $opts['api_token'];
        }

        $resp = wp_remote_post($url, [
            'timeout' => max(60, (int)($opts['timeout'] ?? 160)),
            'headers' => $headers,
            'body'    => wp_json_encode($payload),
        ]);

        if (is_wp_error($resp)) {
            return ['ok'=>false,'error'=>$resp->get_error_message()];
        }
        $code = wp_remote_retrieve_response_code($resp);
        $ct   = (string)wp_remote_retrieve_header($resp, 'content-type');
        $body = (string)wp_remote_retrieve_body($resp);

        if ($code < 200 || $code >= 300) {
            return ['ok'=>false,'http'=>$code,'raw'=>mb_substr($body, 0, 4000)];
        }
        if (stripos($ct, 'json') !== false) {
            $json = json_decode($body, true);
            return ['ok'=>true,'json'=>$json];
        }
        return ['ok'=>true,'raw'=>mb_substr($body, 0, 4000)];
    }
}

if (!function_exists('lts_call_java_media_locked')) {
    function lts_call_java_media_locked(
        string $path,
        array $payload,
        string $source,
        string $process
    ): array {
        $lock = lts_ecosystem_lock_acquire(
            $process,
            $source,
            __('Product media synchronization', 'lavka-total-sync')
        );
        if (empty($lock['ok'])) {
            return lts_ecosystem_lock_error($lock);
        }

        $lock_token = $lock['token'] ?? null;
        try {
            return lts_call_java_media($path, $payload);
        } finally {
            lts_ecosystem_lock_release($lock_token);
        }
    }
}

/** AJAX: по диапазону (курсор) */
add_action('wp_ajax_lts_media_sync_range', function () {
    $cap = defined('LTS_CAP') ? LTS_CAP : 'manage_options';
    if (!current_user_can($cap)) wp_send_json_error(['error'=>'forbidden'], 403);
    check_ajax_referer('lts_admin_nonce','nonce');

    // Читаем и санитизируем поля формы
    $fromSku        = isset($_POST['fromSku']) ? sanitize_text_field((string)$_POST['fromSku']) : '';
    $toSku          = isset($_POST['toSku'])   ? sanitize_text_field((string)$_POST['toSku'])   : '';
    $chunkSize      = isset($_POST['chunkSize']) ? max(1, (int)$_POST['chunkSize']) : 500;
    $mode           = isset($_POST['mode']) ? sanitize_text_field((string)$_POST['mode']) : '';
    $galleryStart   = isset($_POST['galleryStartPos']) ? max(0,(int)$_POST['galleryStartPos']) : 1;
    $limitPerSku    = isset($_POST['limitPerSku']) ? max(0,(int)$_POST['limitPerSku']) : 100;
    $dry            = !empty($_POST['dry']) ? true : false;

    $payload = [
        'fromSku'         => $fromSku,
        'toSku'           => $toSku,
        'chunkSize'       => $chunkSize,
        'mode'            => $mode ?: 'both',
        'galleryStartPos' => $galleryStart,
        'limitPerSku'     => $limitPerSku,
        'dry'             => $dry,
    ];

    $res = lts_call_java_media_locked(
        '/admin/media/sync/range',
        $payload,
        'manual',
        'media_range_sync'
    );
    if (!empty($res['ok'])) wp_send_json_success($res);
    wp_send_json_error($res);
});

/** AJAX: по списку SKU */
add_action('wp_ajax_lts_media_sync_list', function () {
    $cap = defined('LTS_CAP') ? LTS_CAP : 'manage_options';
    if (!current_user_can($cap)) wp_send_json_error(['error'=>'forbidden'], 403);
    check_ajax_referer('lts_admin_nonce','nonce');

    // Парсим список SKU из textarea (по строкам или ; )
    $raw = isset($_POST['skus']) ? (string)$_POST['skus'] : '';
    $delims = preg_split('~[\r\n;]+~u', $raw, -1, PREG_SPLIT_NO_EMPTY);
    $skus = array_values(array_unique(array_map('sanitize_text_field', $delims)));

    $mode           = isset($_POST['mode']) ? sanitize_text_field((string)$_POST['mode']) : '';
    $touchOnUpdate  = !empty($_POST['touchOnUpdate']) ? true : false;
    $galleryStart   = isset($_POST['galleryStartPos']) ? max(0,(int)$_POST['galleryStartPos']) : 1;
    $limitPerSku    = isset($_POST['limitPerSku']) ? max(0,(int)$_POST['limitPerSku']) : 30;
    $dry            = !empty($_POST['dry']) ? true : false;

    $payload = [
        'skus'            => $skus,
        'mode'            => $mode ?: 'both',
        'touchOnUpdate'   => $touchOnUpdate,
        'galleryStartPos' => $galleryStart,
        'limitPerSku'     => $limitPerSku,
        'dry'             => $dry,
    ];

    $res = lts_call_java_media_locked(
        '/admin/media/sync',
        $payload,
        'manual',
        'media_list_sync'
    );
    if (!empty($res['ok'])) wp_send_json_success($res);
    wp_send_json_error($res);
});

/** Normalize filters shared by the on-screen report and XLSX export. */
function lts_media_missing_images_report_args(array $source): array {
    $report_type = sanitize_key((string)($source['report_type'] ?? 'all'));
    $scope = sanitize_key((string)($source['scope'] ?? 'published'));
    $visibility = sanitize_key((string)($source['visibility'] ?? 'exclude_hidden'));

    if ($report_type !== 'featured') {
        $report_type = 'all';
    }
    if ($scope !== 'active') {
        $scope = 'published';
    }
    if (!in_array($visibility, ['exclude_hidden', 'all', 'hidden_only'], true)) {
        $visibility = 'exclude_hidden';
    }

    return [
        'report_type' => $report_type,
        'scope'       => $scope,
        'visibility'  => $visibility,
        'page'        => max(1, (int)($source['page'] ?? 1)),
        'per_page'    => max(10, min(200, (int)($source['per_page'] ?? 50))),
    ];
}

/** Run the read-only missing-image query. */
function lts_media_missing_images_report_data(array $args, bool $paginate = true): array {
    global $wpdb;

    $report_type = $args['report_type'];
    $scope = $args['scope'];
    $visibility = $args['visibility'];
    $page = $paginate ? $args['page'] : 1;
    $per_page = $args['per_page'];
    $offset = ($page - 1) * $per_page;

    $statuses = $scope === 'active'
        ? ['publish', 'draft', 'pending', 'private']
        : ['publish'];
    $status_placeholders = implode(',', array_fill(0, count($statuses), '%s'));

    // A product has a main image only when _thumbnail_id points to a live attachment.
    $missing_condition = "NOT EXISTS (
        SELECT 1
        FROM {$wpdb->postmeta} AS thumb
        INNER JOIN {$wpdb->posts} AS attachment
            ON attachment.ID = CAST(thumb.meta_value AS UNSIGNED)
           AND attachment.post_type = 'attachment'
           AND attachment.post_status <> 'trash'
        WHERE thumb.post_id = product.ID
          AND thumb.meta_key = '_thumbnail_id'
          AND CAST(thumb.meta_value AS UNSIGNED) > 0
    )";

    if ($report_type === 'all') {
        $missing_condition .= " AND NOT EXISTS (
            SELECT 1
            FROM {$wpdb->postmeta} AS gallery
            WHERE gallery.post_id = product.ID
              AND gallery.meta_key = '_product_image_gallery'
              AND TRIM(BOTH ',' FROM TRIM(gallery.meta_value)) <> ''
        )";
    }

    // WooCommerce "Hidden" means excluded from both the catalog and search.
    $hidden_condition = "EXISTS (
        SELECT 1
        FROM {$wpdb->term_relationships} AS hidden_catalog_rel
        INNER JOIN {$wpdb->term_taxonomy} AS hidden_catalog_tax
            ON hidden_catalog_tax.term_taxonomy_id = hidden_catalog_rel.term_taxonomy_id
           AND hidden_catalog_tax.taxonomy = 'product_visibility'
        INNER JOIN {$wpdb->terms} AS hidden_catalog_term
            ON hidden_catalog_term.term_id = hidden_catalog_tax.term_id
           AND hidden_catalog_term.slug = 'exclude-from-catalog'
        WHERE hidden_catalog_rel.object_id = product.ID
    ) AND EXISTS (
        SELECT 1
        FROM {$wpdb->term_relationships} AS hidden_search_rel
        INNER JOIN {$wpdb->term_taxonomy} AS hidden_search_tax
            ON hidden_search_tax.term_taxonomy_id = hidden_search_rel.term_taxonomy_id
           AND hidden_search_tax.taxonomy = 'product_visibility'
        INNER JOIN {$wpdb->terms} AS hidden_search_term
            ON hidden_search_term.term_id = hidden_search_tax.term_id
           AND hidden_search_term.slug = 'exclude-from-search'
        WHERE hidden_search_rel.object_id = product.ID
    )";

    $visibility_condition = '';
    if ($visibility === 'exclude_hidden') {
        $visibility_condition = "AND NOT ({$hidden_condition})";
    } elseif ($visibility === 'hidden_only') {
        $visibility_condition = "AND ({$hidden_condition})";
    }

    $base_where = "product.post_type = 'product'
        AND product.post_status IN ({$status_placeholders})
        AND {$missing_condition}
        {$visibility_condition}";

    $count_sql = "SELECT COUNT(*) FROM {$wpdb->posts} AS product WHERE {$base_where}";
    $total = (int)$wpdb->get_var($wpdb->prepare($count_sql, ...$statuses));
    $pages = max(1, (int)ceil($total / $per_page));

    if ($page > $pages) {
        $page = $pages;
        $offset = ($page - 1) * $per_page;
    }

    $rows_sql = "
        SELECT
            product.ID,
            product.post_title,
            product.post_status,
            (
                SELECT sku.meta_value
                FROM {$wpdb->postmeta} AS sku
                WHERE sku.post_id = product.ID AND sku.meta_key = '_sku'
                ORDER BY sku.meta_id DESC
                LIMIT 1
            ) AS sku,
            (
                SELECT thumb.meta_value
                FROM {$wpdb->postmeta} AS thumb
                WHERE thumb.post_id = product.ID AND thumb.meta_key = '_thumbnail_id'
                ORDER BY thumb.meta_id DESC
                LIMIT 1
            ) AS thumbnail_id,
            (
                SELECT gallery.meta_value
                FROM {$wpdb->postmeta} AS gallery
                WHERE gallery.post_id = product.ID AND gallery.meta_key = '_product_image_gallery'
                ORDER BY gallery.meta_id DESC
                LIMIT 1
            ) AS gallery_ids,
            CASE WHEN {$hidden_condition} THEN 1 ELSE 0 END AS is_hidden
        FROM {$wpdb->posts} AS product
        WHERE {$base_where}
        ORDER BY product.ID DESC
    ";

    $query_params = $statuses;
    if ($paginate) {
        $rows_sql .= ' LIMIT %d OFFSET %d';
        $query_params[] = $per_page;
        $query_params[] = $offset;
    }
    $db_rows = $wpdb->get_results($wpdb->prepare($rows_sql, ...$query_params), ARRAY_A);

    $rows = [];
    foreach ($db_rows as $row) {
        $thumbnail_id = absint($row['thumbnail_id'] ?? 0);
        $gallery_ids = array_values(array_unique(array_filter(array_map(
            'absint',
            explode(',', (string)($row['gallery_ids'] ?? ''))
        ))));
        $status_object = get_post_status_object((string)$row['post_status']);

        $rows[] = [
            'id'            => (int)$row['ID'],
            'sku'           => (string)($row['sku'] ?? ''),
            'title'         => (string)$row['post_title'],
            'status'        => (string)$row['post_status'],
            'status_label'  => $status_object ? $status_object->label : (string)$row['post_status'],
            'is_hidden'     => !empty($row['is_hidden']),
            'visibility_label' => !empty($row['is_hidden'])
                ? __('Hidden', 'lavka-total-sync')
                : __('Visible', 'lavka-total-sync'),
            'gallery_count' => count($gallery_ids),
            'reason'        => $thumbnail_id > 0 ? 'broken_attachment' : 'missing_featured',
            'reason_label'  => $thumbnail_id > 0
                ? __('The featured attachment no longer exists', 'lavka-total-sync')
                : __('Featured image is not assigned', 'lavka-total-sync'),
            'edit_url'      => get_edit_post_link((int)$row['ID'], 'raw'),
            'view_url'      => $row['post_status'] === 'publish'
                ? get_permalink((int)$row['ID'])
                : null,
        ];
    }

    return [
        'rows'       => $rows,
        'total'      => $total,
        'page'       => $page,
        'pages'      => $pages,
        'per_page'   => $per_page,
        'report_type'=> $report_type,
        'scope'      => $scope,
        'visibility' => $visibility,
    ];
}

/** AJAX: read-only report of products without linked WooCommerce images. */
add_action('wp_ajax_lts_media_missing_images_report', function () {
    $cap = defined('LTS_CAP') ? LTS_CAP : 'manage_options';
    if (!current_user_can($cap)) wp_send_json_error(['error' => 'forbidden'], 403);
    check_ajax_referer('lts_admin_nonce', 'nonce');

    wp_send_json_success(lts_media_missing_images_report_data(
        lts_media_missing_images_report_args(wp_unslash($_POST))
    ));
});

/** Download the complete filtered report as an XLSX workbook. */
add_action('admin_post_lts_media_missing_images_export', function () {
    $cap = defined('LTS_CAP') ? LTS_CAP : 'manage_options';
    if (!current_user_can($cap)) {
        wp_die(
            esc_html__('You do not have permission to export this report.', 'lavka-total-sync'),
            '',
            ['response' => 403]
        );
    }
    check_admin_referer('lts_media_missing_images_export');

    if (!class_exists(\PhpOffice\PhpSpreadsheet\Spreadsheet::class)) {
        $composer_autoload = WP_CONTENT_DIR . '/vendor/autoload.php';
        if (is_file($composer_autoload)) {
            require_once $composer_autoload;
        }
    }
    if (!class_exists(\PhpOffice\PhpSpreadsheet\Spreadsheet::class)) {
        wp_die(esc_html__('PhpSpreadsheet is unavailable.', 'lavka-total-sync'));
    }

    $args = lts_media_missing_images_report_args(wp_unslash($_GET));
    $data = lts_media_missing_images_report_data($args, false);

    $report_labels = [
        'all'      => __('No images at all', 'lavka-total-sync'),
        'featured' => __('No featured image', 'lavka-total-sync'),
    ];
    $scope_labels = [
        'published' => __('Published products only', 'lavka-total-sync'),
        'active'    => __('All active products', 'lavka-total-sync'),
    ];
    $visibility_labels = [
        'exclude_hidden' => __('Exclude hidden products', 'lavka-total-sync'),
        'all'            => __('Include hidden products', 'lavka-total-sync'),
        'hidden_only'    => __('Hidden products only', 'lavka-total-sync'),
    ];

    $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Missing images');
    $sheet->mergeCells('A1:I1');
    $sheet->setCellValue('A1', __('Products without images', 'lavka-total-sync'));
    $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);

    $sheet->setCellValue('A2', __('Report type', 'lavka-total-sync'));
    $sheet->setCellValue('B2', $report_labels[$args['report_type']]);
    $sheet->setCellValue('A3', __('Product scope', 'lavka-total-sync'));
    $sheet->setCellValue('B3', $scope_labels[$args['scope']]);
    $sheet->setCellValue('A4', __('Catalog visibility', 'lavka-total-sync'));
    $sheet->setCellValue('B4', $visibility_labels[$args['visibility']]);
    $sheet->setCellValue('A5', __('Generated at', 'lavka-total-sync'));
    $sheet->setCellValue('B5', current_time('Y-m-d H:i:s'));

    $header_row = 7;
    $headers = [
        __('ID', 'lavka-total-sync'),
        __('SKU', 'lavka-total-sync'),
        __('Product', 'lavka-total-sync'),
        __('Status', 'lavka-total-sync'),
        __('Catalog visibility', 'lavka-total-sync'),
        __('Gallery', 'lavka-total-sync'),
        __('Reason', 'lavka-total-sync'),
        __('Edit URL', 'lavka-total-sync'),
        __('Product URL', 'lavka-total-sync'),
    ];
    $sheet->fromArray($headers, null, 'A' . $header_row);
    $sheet->getStyle('A' . $header_row . ':I' . $header_row)->getFont()->setBold(true);
    $sheet->freezePane('A' . ($header_row + 1));

    $row_number = $header_row + 1;
    foreach ($data['rows'] as $row) {
        $sheet->setCellValue('A' . $row_number, $row['id']);
        $sheet->setCellValueExplicit(
            'B' . $row_number,
            $row['sku'],
            \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING
        );
        $sheet->setCellValueExplicit(
            'C' . $row_number,
            $row['title'],
            \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING
        );
        $sheet->setCellValue('D' . $row_number, $row['status_label']);
        $sheet->setCellValue('E' . $row_number, $row['visibility_label']);
        $sheet->setCellValue('F' . $row_number, $row['gallery_count']);
        $sheet->setCellValue('G' . $row_number, $row['reason_label']);
        $sheet->setCellValueExplicit(
            'H' . $row_number,
            (string)$row['edit_url'],
            \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING
        );
        $sheet->setCellValueExplicit(
            'I' . $row_number,
            (string)$row['view_url'],
            \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING
        );
        $row_number++;
    }

    $last_row = max($header_row, $row_number - 1);
    $sheet->setAutoFilter('A' . $header_row . ':I' . $last_row);
    if ($last_row > $header_row) {
        $sheet->getStyle('A' . ($header_row + 1) . ':I' . $last_row)
            ->getAlignment()
            ->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_TOP);
        $sheet->getStyle('C' . ($header_row + 1) . ':G' . $last_row)
            ->getAlignment()
            ->setWrapText(true);
    }

    foreach ([
        'A' => 10,
        'B' => 24,
        'C' => 60,
        'D' => 20,
        'E' => 24,
        'F' => 10,
        'G' => 38,
        'H' => 52,
        'I' => 52,
    ] as $column => $width) {
        $sheet->getColumnDimension($column)->setWidth($width);
    }

    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    nocache_headers();
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="products-without-images-' . gmdate('Ymd-His') . '.xlsx"');
    (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet))->save('php://output');
    $spreadsheet->disconnectWorksheets();
    exit;
});

/** Рендер страницы UI */
function lts_render_media_sync_page() {
    $cap = defined('LTS_CAP') ? LTS_CAP : 'manage_options';
    if (!current_user_can($cap)) return;

    $media_report_export_url = add_query_arg(
        [
            'action'      => 'lts_media_missing_images_export',
            'report_type' => 'all',
            'scope'       => 'published',
            'visibility'  => 'exclude_hidden',
            '_wpnonce'    => wp_create_nonce('lts_media_missing_images_export'),
        ],
        admin_url('admin-post.php')
    );

    // Гарантируем, что jQuery загружен для админ-страницы с инлайновыми обработчиками
    if (function_exists('wp_enqueue_script')) {
        wp_enqueue_script('jquery');
    }

    ?>
    <div class="wrap">
        <h1><?php echo esc_html__('Media Sync (images)', 'lavka-total-sync'); ?></h1>

        <p class="description">
            <?php
            echo esc_html__(
                'Синхронизация изображений по диапазону SKU (курсор) или по списку SKU. Отправляет запросы в Java API /admin/media/sync/range и /admin/media/sync.',
                'lavka-total-sync'
            );
            ?>
        </p>

        <hr class="wp-header-end" style="margin:1rem 0 1.25rem;">

        <!-- === Форма №1: По диапазону (курсор) === -->
        <h2 style="margin-top:0.5rem;"><?php _e('By range (cursor) → /admin/media/sync/range', 'lavka-total-sync'); ?></h2>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><label for="lts_ms_from"><?php _e('From SKU (inclusive)', 'lavka-total-sync'); ?></label></th>
                <td><input id="lts_ms_from" type="text" class="regular-text" placeholder="CR-CE0900056027"></td>
            </tr>
            <tr>
                <th scope="row"><label for="lts_ms_to"><?php _e('To SKU (inclusive)', 'lavka-total-sync'); ?></label></th>
                <td><input id="lts_ms_to" type="text" class="regular-text" placeholder="CR-CE0900056476"></td>
            </tr>
            <tr>
                <th scope="row"><label for="lts_ms_chunk"><?php _e('Chunk size', 'lavka-total-sync'); ?></label></th>
                <td><input id="lts_ms_chunk" type="number" min="1" step="1" class="small-text" value="500"></td>
            </tr>
            <tr>
                <th scope="row"><label for="lts_ms_mode"><?php _e('Mode', 'lavka-total-sync'); ?></label></th>
                <td>
                    <select id="lts_ms_mode">
                        <option value="both"><?php _e('both (featured + gallery)', 'lavka-total-sync'); ?></option>
                        <option value="featured"><?php _e('featured only', 'lavka-total-sync'); ?></option>
                        <option value="gallery"><?php _e('gallery only', 'lavka-total-sync'); ?></option>
                    </select>
                    <p class="description">
                        <?php _e('featured → только обложка; gallery → только галерея; both/пусто → и то, и другое.', 'lavka-total-sync'); ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="lts_ms_gpos"><?php _e('Gallery start position', 'lavka-total-sync'); ?></label></th>
                <td><input id="lts_ms_gpos" type="number" min="0" step="1" class="small-text" value="1"></td>
            </tr>
            <tr>
                <th scope="row"><label for="lts_ms_limit_sku"><?php _e('Limit per SKU (gallery)', 'lavka-total-sync'); ?></label></th>
                <td><input id="lts_ms_limit_sku" type="number" min="0" step="1" class="small-text" value="100"></td>
            </tr>
            <tr>
                <th scope="row"><?php _e('Dry run', 'lavka-total-sync'); ?></th>
                <td><label><input type="checkbox" id="lts_ms_dry"> <?php _e('Не писать в Woo, только расчёт/логика', 'lavka-total-sync'); ?></label></td>
            </tr>
        </table>
        <p>
            <button id="lts_btn_media_range" class="button button-primary"><?php _e('Run range sync', 'lavka-total-sync'); ?></button>
            <span id="lts_ms_range_status" style="margin-left:.6rem;color:#555;"></span>
        </p>

        <pre id="lts_ms_range_out" style="max-height:280px;overflow:auto;background:#111;color:#9fe;padding:10px;border-radius:6px;"></pre>

        <hr style="margin:1.25rem 0;">

        <!-- === Форма №2: По списку SKU === -->
        <h2><?php _e('By list of SKUs → /admin/media/sync', 'lavka-total-sync'); ?></h2>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><label for="lts_ms_skus"><?php _e("SKUs (one per line or ';')", 'lavka-total-sync'); ?></label></th>
                <td>
                    <textarea id="lts_ms_skus" rows="5" class="large-text" placeholder="CR-CE0900056027
CR-CE0900056045
CR-CE0900056100"></textarea>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="lts_ms_mode2"><?php _e('Mode', 'lavka-total-sync'); ?></label></th>
                <td>
                    <select id="lts_ms_mode2">
                        <option value="both"><?php _e('both (featured + gallery)', 'lavka-total-sync'); ?></option>
                        <option value="featured"><?php _e('featured only', 'lavka-total-sync'); ?></option>
                        <option value="gallery"><?php _e('gallery only', 'lavka-total-sync'); ?></option>
                    </select>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php _e('Touch on update', 'lavka-total-sync'); ?></th>
                <td><label><input type="checkbox" id="lts_ms_touch"> <?php _e('Обновлять метки времени при изменении', 'lavka-total-sync'); ?></label></td>
            </tr>
            <tr>
                <th scope="row"><label for="lts_ms_gpos2"><?php _e('Gallery start position', 'lavka-total-sync'); ?></label></th>
                <td><input id="lts_ms_gpos2" type="number" min="0" step="1" class="small-text" value="1"></td>
            </tr>
            <tr>
                <th scope="row"><label for="lts_ms_limit_sku2"><?php _e('Limit per SKU (gallery)', 'lavka-total-sync'); ?></label></th>
                <td><input id="lts_ms_limit_sku2" type="number" min="0" step="1" class="small-text" value="30"></td>
            </tr>
            <tr>
                <th scope="row"><?php _e('Dry run', 'lavka-total-sync'); ?></th>
                <td><label><input type="checkbox" id="lts_ms_dry2"> <?php _e('Не писать в Woo, только расчёт/логика', 'lavka-total-sync'); ?></label></td>
            </tr>
        </table>
        <p>
            <button id="lts_btn_media_list" class="button button-primary"><?php _e('Run list sync', 'lavka-total-sync'); ?></button>
            <span id="lts_ms_list_status" style="margin-left:.6rem;color:#555;"></span>
        </p>

        <pre id="lts_ms_list_out" style="max-height:280px;overflow:auto;background:#111;color:#9fe;padding:10px;border-radius:6px;"></pre>

        <hr style="margin:1.25rem 0;">

        <section id="lts_media_missing_report">
            <h2><?php _e('Products without images', 'lavka-total-sync'); ?></h2>
            <p class="description">
                <?php _e('The report checks WooCommerce product image links. Attachment parent fields in the Media Library do not affect this report.', 'lavka-total-sync'); ?>
            </p>
            <div class="lts-media-report-controls">
                <label for="lts_media_report_type">
                    <span><?php _e('Report type', 'lavka-total-sync'); ?></span>
                    <select id="lts_media_report_type">
                        <option value="all"><?php _e('No images at all', 'lavka-total-sync'); ?></option>
                        <option value="featured"><?php _e('No featured image', 'lavka-total-sync'); ?></option>
                    </select>
                </label>
                <label for="lts_media_report_scope">
                    <span><?php _e('Product scope', 'lavka-total-sync'); ?></span>
                    <select id="lts_media_report_scope">
                        <option value="published"><?php _e('Published products only', 'lavka-total-sync'); ?></option>
                        <option value="active"><?php _e('All active products', 'lavka-total-sync'); ?></option>
                    </select>
                </label>
                <label for="lts_media_report_visibility">
                    <span><?php _e('Catalog visibility', 'lavka-total-sync'); ?></span>
                    <select id="lts_media_report_visibility">
                        <option value="exclude_hidden"><?php _e('Exclude hidden products', 'lavka-total-sync'); ?></option>
                        <option value="all"><?php _e('Include hidden products', 'lavka-total-sync'); ?></option>
                        <option value="hidden_only"><?php _e('Hidden products only', 'lavka-total-sync'); ?></option>
                    </select>
                </label>
                <label for="lts_media_report_per_page">
                    <span><?php _e('Rows per page', 'lavka-total-sync'); ?></span>
                    <select id="lts_media_report_per_page">
                        <option value="25">25</option>
                        <option value="50" selected>50</option>
                        <option value="100">100</option>
                        <option value="200">200</option>
                    </select>
                </label>
                <button id="lts_btn_media_report" class="button button-primary">
                    <?php _e('Build report', 'lavka-total-sync'); ?>
                </button>
                <a
                    id="lts_btn_media_report_export"
                    class="button"
                    href="<?php echo esc_url($media_report_export_url); ?>"
                ><?php _e('Export XLSX', 'lavka-total-sync'); ?></a>
                <span id="lts_media_report_status" aria-live="polite"></span>
            </div>

            <div id="lts_media_report_result" hidden>
                <p id="lts_media_report_summary"></p>
                <div class="lts-media-report-table-wrap">
                    <table class="widefat striped" id="lts_media_report_table">
                        <thead>
                            <tr>
                                <th><?php _e('ID', 'lavka-total-sync'); ?></th>
                                <th><?php _e('SKU', 'lavka-total-sync'); ?></th>
                                <th><?php _e('Product', 'lavka-total-sync'); ?></th>
                                <th><?php _e('Status', 'lavka-total-sync'); ?></th>
                                <th><?php _e('Gallery', 'lavka-total-sync'); ?></th>
                                <th><?php _e('Reason', 'lavka-total-sync'); ?></th>
                                <th><?php _e('Actions', 'lavka-total-sync'); ?></th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                </div>
                <div class="lts-media-report-pagination">
                    <button type="button" id="lts_media_report_prev" class="button">
                        <?php _e('Previous'); ?>
                    </button>
                    <span id="lts_media_report_page"></span>
                    <button type="button" id="lts_media_report_next" class="button">
                        <?php _e('Next'); ?>
                    </button>
                </div>
            </div>
        </section>

        <style>
            .lts-media-report-controls {
                display: flex;
                flex-wrap: wrap;
                align-items: end;
                gap: 12px;
                margin: 16px 0;
            }
            .lts-media-report-controls label {
                display: grid;
                gap: 5px;
            }
            .lts-media-report-controls label > span {
                font-weight: 600;
            }
            #lts_media_report_status {
                min-height: 30px;
                display: inline-flex;
                align-items: center;
            }
            .lts-media-report-table-wrap {
                overflow-x: auto;
            }
            #lts_media_report_table .column-actions {
                display: flex;
                gap: 6px;
                white-space: nowrap;
            }
            .lts-media-report-pagination {
                display: flex;
                align-items: center;
                gap: 10px;
                margin-top: 12px;
            }
        </style>

        <hr style="margin:1.25rem 0;">
        <h2><?php _e('OVH media index', 'lavka-total-sync'); ?></h2>
        <?php $o = function_exists('lts_get_options') ? lts_get_options() : []; ?>
        <p class="description">
            <?php _e('Refresh the S3 media index after uploading new images, or run it by a separate schedule.', 'lavka-total-sync'); ?>
        </p>
        <p>
            <button id="lts_btn_media_reindex" class="button button-primary"><?php _e('Update OVH media index now', 'lavka-total-sync'); ?></button>
            <span id="lts_mr_status" style="margin-left:.6rem;color:#555;"></span>
        </p>
        <pre id="lts_mr_out" style="max-height:220px;overflow:auto;background:#111;color:#9fe;padding:10px;border-radius:6px;"></pre>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><?php _e('Enable index cron', 'lavka-total-sync'); ?></th>
                <td><label><input type="checkbox" id="lts_mr_enabled" <?php echo !empty($o['media_reindex_enabled']) ? 'checked' : '';?> > <?php _e('Run by schedule', 'lavka-total-sync'); ?></label></td>
            </tr>
            <tr>
                <th scope="row"><?php _e('Frequency', 'lavka-total-sync'); ?></th>
                <td>
                    <?php $rfreq = $o['media_reindex_freq'] ?? 'monthly'; ?>
                    <select id="lts_mr_freq">
                        <option value="daily" <?php selected($rfreq,'daily'); ?>><?php _e('Daily','lavka-total-sync'); ?></option>
                        <option value="weekly" <?php selected($rfreq,'weekly'); ?>><?php _e('Weekly','lavka-total-sync'); ?></option>
                        <option value="monthly" <?php selected($rfreq,'monthly'); ?>><?php _e('Monthly','lavka-total-sync'); ?></option>
                    </select>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php _e('Time (site local)', 'lavka-total-sync'); ?></th>
                <td>
                    <?php $rhh = (int)($o['media_reindex_h'] ?? 3); $rmm = (int)($o['media_reindex_m'] ?? 0); ?>
                    <input id="lts_mr_hh" type="number" min="0" max="23" step="1" class="small-text" value="<?php echo esc_attr($rhh);?>"> :
                    <input id="lts_mr_mm" type="number" min="0" max="59" step="1" class="small-text" value="<?php echo esc_attr($rmm);?>">
                    <p class="description"><?php _e('Uses the site timezone from Settings → General.', 'lavka-total-sync'); ?></p>
                </td>
            </tr>
            <tr id="lts_mr_row_dow" style="<?php echo (($o['media_reindex_freq'] ?? 'monthly')==='weekly')?'':'display:none';?>">
                <th scope="row"><?php _e('Day of week', 'lavka-total-sync'); ?></th>
                <td>
                    <?php $rdow = (int)($o['media_reindex_dow'] ?? 1); ?>
                    <select id="lts_mr_dow">
                        <option value="1" <?php selected($rdow,1); ?>><?php _e('Monday','lavka-total-sync'); ?></option>
                        <option value="2" <?php selected($rdow,2); ?>><?php _e('Tuesday','lavka-total-sync'); ?></option>
                        <option value="3" <?php selected($rdow,3); ?>><?php _e('Wednesday','lavka-total-sync'); ?></option>
                        <option value="4" <?php selected($rdow,4); ?>><?php _e('Thursday','lavka-total-sync'); ?></option>
                        <option value="5" <?php selected($rdow,5); ?>><?php _e('Friday','lavka-total-sync'); ?></option>
                        <option value="6" <?php selected($rdow,6); ?>><?php _e('Saturday','lavka-total-sync'); ?></option>
                        <option value="7" <?php selected($rdow,7); ?>><?php _e('Sunday','lavka-total-sync'); ?></option>
                    </select>
                </td>
            </tr>
            <tr id="lts_mr_row_dom" style="<?php echo (($o['media_reindex_freq'] ?? 'monthly')==='monthly')?'':'display:none';?>">
                <th scope="row"><?php _e('Day of month', 'lavka-total-sync'); ?></th>
                <td>
                    <input id="lts_mr_dom" type="number" min="1" max="28" step="1" class="small-text" value="<?php echo esc_attr((int)($o['media_reindex_dom'] ?? 1));?>">
                    <p class="description"><?php _e('Use days 1–28 so every month is valid.', 'lavka-total-sync'); ?></p>
                </td>
            </tr>
        </table>
        <p>
            <button id="lts_mr_save" class="button"><?php _e('Save index schedule','lavka-total-sync'); ?></button>
            <button id="lts_mr_stat" class="button"><?php _e('Refresh status','lavka-total-sync'); ?></button>
            <span id="lts_mr_info" style="margin-left:.6rem;color:#555;"></span>
        </p>
        <p id="lts_mr_next">
            <strong><?php _e('Next index run', 'lavka-total-sync'); ?>:</strong>
            <span id="lts_mr_site"></span>
            <br>
            <em>UTC/server:</em> <span id="lts_mr_utc"></span>
            <br>
            <em><?php _e('Your local (browser) time','lavka-total-sync'); ?>:</em> <span id="lts_mr_local"></span>
        </p>

        <hr style="margin:1.25rem 0;">
        <h2><?php _e('Media Sync Cron (range only)', 'lavka-total-sync'); ?></h2>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><?php _e('Enable cron', 'lavka-total-sync'); ?></th>
                <td><label><input type="checkbox" id="lts_mc_enabled" <?php echo !empty($o['media_cron_enabled']) ? 'checked' : '';?> > <?php _e('Run by schedule', 'lavka-total-sync'); ?></label></td>
            </tr>
            <tr>
                <th scope="row"><?php _e('Frequency', 'lavka-total-sync'); ?></th>
                <td>
                    <select id="lts_mc_freq">
                        <?php $freq = $o['media_cron_freq'] ?? 'daily'; ?>
                        <option value="daily" <?php selected($freq,'daily'); ?>><?php _e('Daily','lavka-total-sync'); ?></option>
                        <option value="weekly" <?php selected($freq,'weekly'); ?>><?php _e('Weekly','lavka-total-sync'); ?></option>
                    </select>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php _e('Time (site local)', 'lavka-total-sync'); ?></th>
                <td>
                    <?php $hh = (int)($o['media_cron_h'] ?? 2); $mm = (int)($o['media_cron_m'] ?? 0); ?>
                    <input id="lts_mc_hh" type="number" min="0" max="23" step="1" class="small-text" value="<?php echo esc_attr($hh);?>"> :
                    <input id="lts_mc_mm" type="number" min="0" max="59" step="1" class="small-text" value="<?php echo esc_attr($mm);?>">
                    <p class="description"><?php _e('Uses the site timezone from Settings → General.', 'lavka-total-sync'); ?></p>
                </td>
            </tr>
            <tr id="lts_mc_row_dow" style="<?php echo (($o['media_cron_freq'] ?? 'daily')==='weekly')?'':'display:none';?>">
                <th scope="row"><?php _e('Day of week', 'lavka-total-sync'); ?></th>
                <td>
                    <?php $dow = (int)($o['media_cron_dow'] ?? 1); ?>
                    <select id="lts_mc_dow">
                        <option value="1" <?php selected($dow,1); ?>><?php _e('Monday','lavka-total-sync'); ?></option>
                        <option value="2" <?php selected($dow,2); ?>><?php _e('Tuesday','lavka-total-sync'); ?></option>
                        <option value="3" <?php selected($dow,3); ?>><?php _e('Wednesday','lavka-total-sync'); ?></option>
                        <option value="4" <?php selected($dow,4); ?>><?php _e('Thursday','lavka-total-sync'); ?></option>
                        <option value="5" <?php selected($dow,5); ?>><?php _e('Friday','lavka-total-sync'); ?></option>
                        <option value="6" <?php selected($dow,6); ?>><?php _e('Saturday','lavka-total-sync'); ?></option>
                        <option value="7" <?php selected($dow,7); ?>><?php _e('Sunday','lavka-total-sync'); ?></option>
                    </select>
                </td>
            </tr>
            <tr><th><em><?php _e('Range payload', 'lavka-total-sync'); ?></em></th><td></td></tr>
            <tr>
                <th scope="row"><?php _e('From SKU (inclusive)', 'lavka-total-sync'); ?></th>
                <td><input id="lts_mc_from" type="text" class="regular-text" value="<?php echo esc_attr($o['media_from_sku'] ?? ''); ?>"></td>
            </tr>
            <tr>
                <th scope="row"><?php _e('To SKU (inclusive)', 'lavka-total-sync'); ?></th>
                <td><input id="lts_mc_to" type="text" class="regular-text" value="<?php echo esc_attr($o['media_to_sku'] ?? ''); ?>"></td>
            </tr>
            <tr>
                <th scope="row"><?php _e('Chunk size', 'lavka-total-sync'); ?></th>
                <td><input id="lts_mc_chunk" type="number" min="1" step="1" class="small-text" value="<?php echo esc_attr((int)($o['media_chunk'] ?? 500));?>"></td>
            </tr>
            <tr>
                <th scope="row"><?php _e('Mode', 'lavka-total-sync'); ?></th>
                <td>
                    <?php $mmode = $o['media_mode'] ?? 'both'; ?>
                    <select id="lts_mc_mode">
                        <option value="both" <?php selected($mmode,'both'); ?>><?php _e('both (featured + gallery)','lavka-total-sync'); ?></option>
                        <option value="featured" <?php selected($mmode,'featured'); ?>><?php _e('featured only','lavka-total-sync'); ?></option>
                        <option value="gallery" <?php selected($mmode,'gallery'); ?>><?php _e('gallery only','lavka-total-sync'); ?></option>
                    </select>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php _e('Gallery start position', 'lavka-total-sync'); ?></th>
                <td><input id="lts_mc_gpos" type="number" min="0" step="1" class="small-text" value="<?php echo esc_attr((int)($o['media_gstart'] ?? 1));?>"></td>
            </tr>
            <tr>
                <th scope="row"><?php _e('Limit per SKU (gallery)', 'lavka-total-sync'); ?></th>
                <td><input id="lts_mc_limit" type="number" min="0" step="1" class="small-text" value="<?php echo esc_attr((int)($o['media_limit_per_sku'] ?? 100));?>"></td>
            </tr>
            <tr>
                <th scope="row"><?php _e('Dry run', 'lavka-total-sync'); ?></th>
                <td><label><input type="checkbox" id="lts_mc_dry" <?php echo !empty($o['media_dry'])?'checked':'';?>> <?php _e('Не писать в Woo, только расчёт/логика','lavka-total-sync'); ?></label></td>
            </tr>
        </table>
        <p>
            <button id="lts_mc_save" class="button button-primary"><?php _e('Save & schedule','lavka-total-sync'); ?></button>
            <button id="lts_mc_run"  class="button"><?php _e('Run cron task now','lavka-total-sync'); ?></button>
            <button id="lts_mc_stat" class="button"><?php _e('Refresh status','lavka-total-sync'); ?></button>
            <span id="lts_mc_info" style="margin-left:.6rem;color:#555;"></span>
        </p>
        <p id="lts_mc_next">
            <strong><?php _e('Next run', 'lavka-total-sync'); ?>:</strong>
            <span id="lts_mc_site"></span>
            <br>
            <em>UTC/server:</em> <span id="lts_mc_utc"></span>
            <br>
            <em><?php _e('Your local (browser) time','lavka-total-sync'); ?>:</em> <span id="lts_mc_local"></span>
        </p>

        <script>
        (function($){
            const ajaxUrl = '<?php echo esc_js(admin_url('admin-ajax.php')); ?>';
            const nonce   = '<?php echo esc_js( wp_create_nonce('lts_admin_nonce') ); ?>';

            function updNext() {
                $.post(ajaxUrl, {action:'lts_media_cron_status', nonce:nonce}).done(function(res){
                    if (res && res.success) {
                        $('#lts_mc_site').text(res.data.site_time);
                        $('#lts_mc_utc').text(res.data.utc_time);
                        if (res.data.next_ts) {
                            const d = new Date(res.data.next_ts * 1000);
                            $('#lts_mc_local').text(d.toLocaleString());
                        } else {
                            $('#lts_mc_local').text('—');
                        }
                    }
                });
            }
            function updReindexNext() {
                $.post(ajaxUrl, {action:'lts_media_reindex_cron_status', nonce:nonce}).done(function(res){
                    if (res && res.success) {
                        $('#lts_mr_site').text(res.data.site_time);
                        $('#lts_mr_utc').text(res.data.utc_time);
                        if (res.data.next_ts) {
                            const d = new Date(res.data.next_ts * 1000);
                            $('#lts_mr_local').text(d.toLocaleString());
                        } else {
                            $('#lts_mr_local').text('—');
                        }
                    }
                });
            }
            function saveCron() {
                const freq = $('#lts_mc_freq').val();
                const payload = {
                    action:'lts_media_cron_save', nonce:nonce,
                    enabled: $('#lts_mc_enabled').is(':checked') ? 1 : 0,
                    freq: freq,
                    hh: parseInt($('#lts_mc_hh').val(),10)||0,
                    mm: parseInt($('#lts_mc_mm').val(),10)||0,
                    dow: parseInt($('#lts_mc_dow').val(),10)||1,
                    fromSku: $('#lts_mc_from').val(),
                    toSku: $('#lts_mc_to').val(),
                    chunkSize: parseInt($('#lts_mc_chunk').val(),10)||500,
                    mode: $('#lts_mc_mode').val(),
                    galleryStartPos: parseInt($('#lts_mc_gpos').val(),10)||1,
                    limitPerSku: parseInt($('#lts_mc_limit').val(),10)||100,
                    dry: $('#lts_mc_dry').is(':checked') ? 1 : 0
                };
                $('#lts_mc_info').text('<?php echo esc_js(__('Saving…','lavka-total-sync')); ?>');
                $.post(ajaxUrl, payload).done(function(res){
                    if (res && res.success) {
                        $('#lts_mc_info').text('<?php echo esc_js(__('Saved','lavka-total-sync')); ?>');
                        $('#lts_mc_site').text(res.data.site_time);
                        $('#lts_mc_utc').text(res.data.utc_time);
                        if (res.data.next_ts) {
                            const d = new Date(res.data.next_ts * 1000);
                            $('#lts_mc_local').text(d.toLocaleString());
                        } else {
                            $('#lts_mc_local').text('—');
                        }
                    } else {
                        $('#lts_mc_info').text('<?php echo esc_js(__('Error','lavka-total-sync')); ?>');
                    }
                }).fail(function(){
                    $('#lts_mc_info').text('<?php echo esc_js(__('Error','lavka-total-sync')); ?>');
                });
            }
            function syncReindexScheduleRows() {
                const freq = $('#lts_mr_freq').val();
                $('#lts_mr_row_dow').toggle(freq === 'weekly');
                $('#lts_mr_row_dom').toggle(freq === 'monthly');
            }
            function saveReindexCron() {
                const payload = {
                    action:'lts_media_reindex_cron_save', nonce:nonce,
                    enabled: $('#lts_mr_enabled').is(':checked') ? 1 : 0,
                    freq: $('#lts_mr_freq').val(),
                    hh: parseInt($('#lts_mr_hh').val(),10)||0,
                    mm: parseInt($('#lts_mr_mm').val(),10)||0,
                    dow: parseInt($('#lts_mr_dow').val(),10)||1,
                    dom: parseInt($('#lts_mr_dom').val(),10)||1
                };
                $('#lts_mr_info').text('<?php echo esc_js(__('Saving…','lavka-total-sync')); ?>');
                $.post(ajaxUrl, payload).done(function(res){
                    if (res && res.success) {
                        $('#lts_mr_info').text('<?php echo esc_js(__('Saved','lavka-total-sync')); ?>');
                        $('#lts_mr_site').text(res.data.site_time);
                        $('#lts_mr_utc').text(res.data.utc_time);
                        if (res.data.next_ts) {
                            const d = new Date(res.data.next_ts * 1000);
                            $('#lts_mr_local').text(d.toLocaleString());
                        } else {
                            $('#lts_mr_local').text('—');
                        }
                    } else {
                        $('#lts_mr_info').text('<?php echo esc_js(__('Error','lavka-total-sync')); ?>');
                    }
                }).fail(function(){
                    $('#lts_mr_info').text('<?php echo esc_js(__('Error','lavka-total-sync')); ?>');
                });
            }
            $('#lts_mc_freq').on('change', function(){
                if ($(this).val()==='weekly') $('#lts_mc_row_dow').show(); else $('#lts_mc_row_dow').hide();
            });
            $('#lts_mr_freq').on('change', syncReindexScheduleRows);
            $('#lts_mr_save').on('click', function(e){ e.preventDefault(); saveReindexCron(); });
            $('#lts_mr_stat').on('click', function(e){ e.preventDefault(); updReindexNext(); });
            $('#lts_mc_save').on('click', function(e){ e.preventDefault(); saveCron(); });
            $('#lts_mc_stat').on('click', function(e){ e.preventDefault(); updNext(); });
            $('#lts_mc_run').on('click', function(e){ e.preventDefault();
                $('#lts_mc_info').text('<?php echo esc_js(__('Working…','lavka-total-sync')); ?>');
                $.post(ajaxUrl, {action:'lts_media_cron_run_now', nonce:nonce}).done(function(){
                    $('#lts_mc_info').text('<?php echo esc_js(__('Scheduled','lavka-total-sync')); ?>');
                    updNext();
                }).fail(function(){
                    $('#lts_mc_info').text('<?php echo esc_js(__('Error','lavka-total-sync')); ?>');
                });
            });
            // init
            syncReindexScheduleRows();
            updReindexNext();
            updNext();
        })(jQuery);
        </script>

        <script>
        (function($){
            const ajaxUrl = '<?php echo esc_js(admin_url('admin-ajax.php')); ?>';
            const nonce   = '<?php echo esc_js( wp_create_nonce('lts_admin_nonce') ); ?>';
            const mediaReportExportBaseUrl = <?php echo wp_json_encode($media_report_export_url); ?>;

            function print(obj){ try{return JSON.stringify(obj,null,2);}catch(e){return String(obj);} }

            let mediaReportPage = 1;
            let mediaReportPages = 1;

            function updateMediaReportExportUrl() {
                const url = new URL(mediaReportExportBaseUrl, window.location.origin);
                url.searchParams.set('report_type', $('#lts_media_report_type').val());
                url.searchParams.set('scope', $('#lts_media_report_scope').val());
                url.searchParams.set('visibility', $('#lts_media_report_visibility').val());
                $('#lts_btn_media_report_export').attr('href', url.toString());
            }

            function renderMediaReport(data) {
                const $result = $('#lts_media_report_result');
                const $body = $('#lts_media_report_table tbody').empty();

                if (!data.rows.length) {
                    $('<tr>').append(
                        $('<td>', {colspan: 7}).text('<?php echo esc_js(__('No products found', 'lavka-total-sync')); ?>')
                    ).appendTo($body);
                } else {
                    data.rows.forEach(function(row) {
                        const $actions = $('<div>', {class: 'column-actions'});
                        if (row.edit_url) {
                            $('<a>', {class: 'button button-small', href: row.edit_url})
                                .text('<?php echo esc_js(__('Edit')); ?>')
                                .appendTo($actions);
                        }
                        if (row.view_url) {
                            $('<a>', {class: 'button button-small', href: row.view_url, target: '_blank', rel: 'noopener'})
                                .text('<?php echo esc_js(__('View')); ?>')
                                .appendTo($actions);
                        }

                        $('<tr>')
                            .append($('<td>').text(row.id))
                            .append($('<td>').append($('<code>').text(row.sku || '—')))
                            .append($('<td>').append($('<a>', {href: row.edit_url || '#'}).text(row.title || '—')))
                            .append($('<td>').text(row.status_label))
                            .append($('<td>').text(row.gallery_count))
                            .append($('<td>').text(row.reason_label))
                            .append($('<td>').append($actions))
                            .appendTo($body);
                    });
                }

                mediaReportPage = data.page;
                mediaReportPages = data.pages;
                $('#lts_media_report_summary').text(
                    '<?php echo esc_js(__('Found:', 'lavka-total-sync')); ?>' + ' ' + data.total
                );
                $('#lts_media_report_page').text(
                    '<?php echo esc_js(__('Page', 'lavka-total-sync')); ?>' + ' ' + data.page + ' / ' + data.pages
                );
                $('#lts_media_report_prev').prop('disabled', data.page <= 1);
                $('#lts_media_report_next').prop('disabled', data.page >= data.pages);
                $result.prop('hidden', false);
            }

            function loadMediaReport(page) {
                $('#lts_media_report_status').text('<?php echo esc_js(__('Working…','lavka-total-sync')); ?>');
                $.post(ajaxUrl, {
                    action: 'lts_media_missing_images_report',
                    nonce: nonce,
                    report_type: $('#lts_media_report_type').val(),
                    scope: $('#lts_media_report_scope').val(),
                    visibility: $('#lts_media_report_visibility').val(),
                    per_page: $('#lts_media_report_per_page').val(),
                    page: page
                }).done(function(res) {
                    if (!res || !res.success) {
                        $('#lts_media_report_status').text('<?php echo esc_js(__('Error','lavka-total-sync')); ?>');
                        return;
                    }
                    renderMediaReport(res.data);
                    $('#lts_media_report_status').text('<?php echo esc_js(__('Done.','lavka-total-sync')); ?>');
                }).fail(function() {
                    $('#lts_media_report_status').text('<?php echo esc_js(__('Error','lavka-total-sync')); ?>');
                });
            }

            $('#lts_btn_media_report').on('click', function(e) {
                e.preventDefault();
                loadMediaReport(1);
            });
            $('#lts_media_report_type, #lts_media_report_scope, #lts_media_report_visibility')
                .on('change', updateMediaReportExportUrl);
            $('#lts_media_report_prev').on('click', function() {
                if (mediaReportPage > 1) loadMediaReport(mediaReportPage - 1);
            });
            $('#lts_media_report_next').on('click', function() {
                if (mediaReportPage < mediaReportPages) loadMediaReport(mediaReportPage + 1);
            });
            updateMediaReportExportUrl();

            $('#lts_btn_media_reindex').on('click', async function(){
                $('#lts_mr_status').text('<?php echo esc_js(__('Working…','lavka-total-sync')); ?>');
                $('#lts_mr_out').text('');
                try{
                    const res = await $.post(ajaxUrl, {
                        action: 'lts_media_reindex_run',
                        nonce: nonce
                    });
                    if (!res || !res.success) {
                        $('#lts_mr_status').text('<?php echo esc_js(__('Error','lavka-total-sync')); ?>');
                        $('#lts_mr_out').text(print(res && res.data ? res.data : res));
                        return;
                    }
                    $('#lts_mr_status').text('<?php echo esc_js(__('Done.','lavka-total-sync')); ?>');
                    const d = res.data;
                    $('#lts_mr_out').text(d.json ? print(d.json) : (d.raw || '(no body)'));
                } catch(e){
                    $('#lts_mr_status').text('<?php echo esc_js(__('Error','lavka-total-sync')); ?>');
                    $('#lts_mr_out').text(String(e));
                }
            });

            // По диапазону
            $('#lts_btn_media_range').on('click', async function(){
                $('#lts_ms_range_status').text('<?php echo esc_js(__('Working…','lavka-total-sync')); ?>');
                $('#lts_ms_range_out').text('');
                const data = {
                    action: 'lts_media_sync_range',
                    nonce:  nonce,
                    fromSku:        $('#lts_ms_from').val(),
                    toSku:          $('#lts_ms_to').val(),
                    chunkSize:      parseInt($('#lts_ms_chunk').val(),10) || 500,
                    mode:           $('#lts_ms_mode').val(),
                    galleryStartPos:parseInt($('#lts_ms_gpos').val(),10) || 1,
                    limitPerSku:    parseInt($('#lts_ms_limit_sku').val(),10) || 100,
                    dry:            $('#lts_ms_dry').is(':checked') ? 1 : 0
                };
                try{
                    const res = await $.post(ajaxUrl, data);
                    if (!res || !res.success) {
                        $('#lts_ms_range_status').text('<?php echo esc_js(__('Error','lavka-total-sync')); ?>');
                        $('#lts_ms_range_out').text(print(res && res.data ? res.data : res));
                        return;
                    }
                    $('#lts_ms_range_status').text('<?php echo esc_js(__('Done.','lavka-total-sync')); ?>');
                    const d = res.data;
                    $('#lts_ms_range_out').text(d.json ? print(d.json) : (d.raw || '(no body)'));
                } catch(e){
                    $('#lts_ms_range_status').text('<?php echo esc_js(__('Error','lavka-total-sync')); ?>');
                    $('#lts_ms_range_out').text(String(e));
                }
            });

            // По списку
            $('#lts_btn_media_list').on('click', async function(){
                $('#lts_ms_list_status').text('<?php echo esc_js(__('Working…','lavka-total-sync')); ?>');
                $('#lts_ms_list_out').text('');
                const data = {
                    action: 'lts_media_sync_list',
                    nonce:  nonce,
                    skus:           $('#lts_ms_skus').val(),
                    mode:           $('#lts_ms_mode2').val(),
                    touchOnUpdate:  $('#lts_ms_touch').is(':checked') ? 1 : 0,
                    galleryStartPos:parseInt($('#lts_ms_gpos2').val(),10) || 1,
                    limitPerSku:    parseInt($('#lts_ms_limit_sku2').val(),10) || 30,
                    dry:            $('#lts_ms_dry2').is(':checked') ? 1 : 0
                };
                try{
                    const res = await $.post(ajaxUrl, data);
                    if (!res || !res.success) {
                        $('#lts_ms_list_status').text('<?php echo esc_js(__('Error','lavka-total-sync')); ?>');
                        $('#lts_ms_list_out').text(print(res && res.data ? res.data : res));
                        return;
                    }
                    $('#lts_ms_list_status').text('<?php echo esc_js(__('Done.','lavka-total-sync')); ?>');
                    const d = res.data;
                    $('#lts_ms_list_out').text(d.json ? print(d.json) : (d.raw || '(no body)'));
                } catch(e){
                    $('#lts_ms_list_status').text('<?php echo esc_js(__('Error','lavka-total-sync')); ?>');
                    $('#lts_ms_list_out').text(String(e));
                }
            });
        })(jQuery);
        </script>
    </div>
    <?php
}
