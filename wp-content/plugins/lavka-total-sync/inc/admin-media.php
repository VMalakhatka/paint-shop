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

/** Send an authenticated JSON request to the configured Java API. */
if (!function_exists('lts_call_java_media_request')) {
    function lts_call_java_media_request(string $method, string $path, array $data = []): array {
        if (!function_exists('lts_get_options')) {
            return ['ok'=>false,'error'=>'missing_options'];
        }
        $opts = lts_get_options();
        $base = rtrim((string)($opts['java_base_url'] ?? ''), '/');
        if ($base === '') return ['ok'=>false,'error'=>'java_base_url_missing'];

        // Нормализуем путь
        $path = '/' . ltrim($path, '/');
        $url  = $base . $path;

        $method = strtoupper($method);
        $headers = [
            'Accept'     => 'application/json',
            'User-Agent' => defined('LTS_USER_AGENT') ? LTS_USER_AGENT : 'Lavka-Total-Sync',
        ];
        if (!empty($opts['api_token'])) {
            $headers['Authorization'] = 'Bearer ' . $opts['api_token'];
        }

        $request_args = [
            'method'  => $method,
            'timeout' => max(60, (int)($opts['timeout'] ?? 160)),
            'headers' => $headers,
        ];
        if ($method === 'GET') {
            $url = add_query_arg($data, $url);
        } else {
            $request_args['headers']['Content-Type'] = 'application/json; charset=utf-8';
            $request_args['body'] = wp_json_encode($data, JSON_UNESCAPED_UNICODE);
        }

        $resp = wp_remote_request($url, $request_args);

        if (is_wp_error($resp)) {
            return ['ok'=>false,'error'=>$resp->get_error_message()];
        }
        $code = wp_remote_retrieve_response_code($resp);
        $body = (string)wp_remote_retrieve_body($resp);

        if ($code < 200 || $code >= 300) {
            return ['ok'=>false,'http'=>$code,'raw'=>mb_substr($body, 0, 4000)];
        }
        $json = json_decode($body, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($json)) {
            return ['ok'=>true,'http'=>$code,'json'=>$json];
        }
        return ['ok'=>false,'http'=>$code,'error'=>'invalid_json','raw'=>mb_substr($body, 0, 4000)];
    }
}

if (!function_exists('lts_call_java_media')) {
    function lts_call_java_media(string $path, array $payload): array {
        return lts_call_java_media_request('POST', $path, $payload);
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

/** Resolve the Java media mismatch log without accepting a path from the request. */
function lts_media_mismatch_log_path(): string {
    $path = defined('LTS_MEDIA_MISMATCH_LOG_PATH')
        ? (string)LTS_MEDIA_MISMATCH_LOG_PATH
        : '/mnt/backup/backups_kreul/synck_logs/sync-mismatch.log';

    return (string)apply_filters('lts_media_mismatch_log_path', $path);
}

/** Normalize filters for the Java mismatch log report. */
function lts_media_mismatch_report_args(array $source): array {
    $type = sanitize_key((string)($source['type'] ?? 'all'));
    $visibility = sanitize_key((string)($source['visibility'] ?? 'exclude_hidden'));
    if (!in_array($type, ['all', 'featured', 'gallery'], true)) {
        $type = 'all';
    }
    if (!in_array($visibility, ['exclude_hidden', 'all', 'hidden_only'], true)) {
        $visibility = 'exclude_hidden';
    }

    return [
        'type'       => $type,
        'visibility' => $visibility,
        'query'      => sanitize_text_field((string)($source['query'] ?? '')),
        'page'       => max(1, (int)($source['page'] ?? 1)),
        'per_page'   => max(10, min(200, (int)($source['per_page'] ?? 50))),
    ];
}

/** Return product IDs whose Woo catalog visibility is explicitly hidden. */
function lts_media_hidden_product_ids(array $product_ids): array {
    $product_ids = array_values(array_unique(array_filter(array_map('absint', $product_ids))));
    if (!$product_ids) {
        return [];
    }

    $visibility_terms = [];
    foreach (array_chunk($product_ids, 500) as $product_ids_chunk) {
        $terms = wp_get_object_terms($product_ids_chunk, 'product_visibility', [
            'fields' => 'all_with_object_id',
        ]);
        if (is_wp_error($terms)) {
            continue;
        }

        foreach ($terms as $term) {
            $object_id = absint($term->object_id ?? 0);
            $slug = (string)($term->slug ?? '');
            if ($object_id > 0 && in_array($slug, ['exclude-from-catalog', 'exclude-from-search'], true)) {
                $visibility_terms[$object_id][$slug] = true;
            }
        }
    }

    $hidden_ids = [];
    foreach ($visibility_terms as $product_id => $slugs) {
        if (!empty($slugs['exclude-from-catalog']) && !empty($slugs['exclude-from-search'])) {
            $hidden_ids[(int)$product_id] = true;
        }
    }

    return $hidden_ids;
}

/** Read only the newest part of a potentially large Java log file. */
function lts_media_mismatch_log_tail(string $path, int $max_bytes = 8388608): array {
    $size = (int)@filesize($path);
    $offset = max(0, $size - $max_bytes);
    $handle = @fopen($path, 'rb');
    if (!$handle) {
        return ['content' => '', 'size' => $size, 'truncated' => false];
    }

    if ($offset > 0) {
        fseek($handle, $offset);
    }
    $content = (string)stream_get_contents($handle);
    fclose($handle);

    if ($offset > 0) {
        $first_newline = strpos($content, "\n");
        $content = $first_newline === false ? '' : substr($content, $first_newline + 1);
    }

    return [
        'content'   => $content,
        'size'      => $size,
        'truncated' => $offset > 0,
    ];
}

/** Turn a Java mismatch message into an operator-facing explanation. */
function lts_media_mismatch_explanation(string $message, string $file): array {
    if (stripos($message, 'Not found in s3_media_index:') !== false) {
        $hint = '';
        $basename = wp_basename($file);
        $extension = strtolower((string)pathinfo($basename, PATHINFO_EXTENSION));

        if ($extension === '') {
            $hint = __('The filename has no extension. Check the image name stored in Folio.', 'lavka-total-sync');
        } elseif ($extension === 'ipg') {
            $hint = __('The .ipg extension looks like a .jpg typo. Correct the image name in Folio or in storage.', 'lavka-total-sync');
        } elseif (preg_match('/\s+\.[^.]+$/u', $basename)) {
            $hint = __('There is a space before the file extension. The name must match OVH/S3 exactly.', 'lavka-total-sync');
        }

        return [
            'code'        => 'not_in_s3_index',
            'label'       => __('File is missing from the OVH/S3 media index', 'lavka-total-sync'),
            'explanation' => __('Folio references this image, but Java could not find the exact filename in the current OVH/S3 media index.', 'lavka-total-sync'),
            'action'      => __('Upload the file with exactly this name or correct the name in Folio, update the OVH media index, and rerun media synchronization.', 'lavka-total-sync'),
            'hint'        => $hint,
        ];
    }

    return [
        'code'        => 'sync_error',
        'label'       => __('Java media synchronization error', 'lavka-total-sync'),
        'explanation' => $message,
        'action'      => __('Check the technical message and rerun media synchronization after correcting its cause.', 'lavka-total-sync'),
        'hint'        => '',
    ];
}

/** Resolve the media index table used by Java. */
function lts_media_mismatch_s3_index_table(): string {
    static $resolved = null;
    if ($resolved !== null) {
        return $resolved;
    }

    global $wpdb;
    foreach (array_unique([$wpdb->prefix . 's3_media_index', 's3_media_index']) as $candidate) {
        $found = $wpdb->get_var($wpdb->prepare(
            'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
            $candidate
        ));
        if ((string)$found === $candidate) {
            $resolved = $candidate;
            return $resolved;
        }
    }

    $resolved = '';
    return $resolved;
}

/** Build deterministic filename variants that WordPress commonly creates. */
function lts_media_mismatch_filename_candidates(string $file): array {
    $basename = wp_basename(str_replace('\\', '/', trim($file)));
    if ($basename === '') {
        return [];
    }

    $lower = function_exists('mb_strtolower')
        ? mb_strtolower($basename, 'UTF-8')
        : strtolower($basename);
    $normalized = sanitize_file_name($lower);
    $space_normalized = preg_replace('/\s+/u', '-', $lower) ?: $lower;
    $space_normalized = preg_replace('/-+/', '-', $space_normalized) ?: $space_normalized;
    $base_names = array_values(array_unique(array_filter([$lower, $normalized, $space_normalized])));
    $candidates = $base_names;

    foreach ($base_names as $base_name) {
        $extension = (string)pathinfo($base_name, PATHINFO_EXTENSION);
        $stem = (string)pathinfo($base_name, PATHINFO_FILENAME);
        if ($stem === '' || $extension === '') {
            continue;
        }
        for ($suffix = 1; $suffix <= 20; $suffix++) {
            $candidates[] = $stem . '-' . $suffix . '.' . $extension;
        }
    }

    return array_values(array_unique($candidates));
}

/** Find current exact/normalized candidates in the OVH/S3 media index in one query. */
function lts_media_mismatch_s3_suggestions(array $rows): array {
    global $wpdb;

    $table = lts_media_mismatch_s3_index_table();
    if ($table === '' || !$rows) {
        return [];
    }

    $candidate_rows = [];
    foreach ($rows as $index => $row) {
        foreach (lts_media_mismatch_filename_candidates((string)($row['file'] ?? '')) as $candidate) {
            $candidate_rows[$candidate][] = (int)$index;
        }
    }
    if (!$candidate_rows) {
        return [];
    }

    $filenames = array_keys($candidate_rows);
    $placeholders = implode(', ', array_fill(0, count($filenames), '%s'));
    $sql = "
        SELECT filename_lower, full_key, size_bytes, etag, last_modified
        FROM `{$table}`
        WHERE filename_lower IN ({$placeholders})
        ORDER BY filename_lower, last_modified DESC, size_bytes DESC
    ";
    $matches = $wpdb->get_results($wpdb->prepare($sql, ...$filenames), ARRAY_A);
    if (!is_array($matches)) {
        return [];
    }

    $suggestions = [];
    foreach ($matches as $match) {
        $filename = function_exists('mb_strtolower')
            ? mb_strtolower((string)($match['filename_lower'] ?? ''), 'UTF-8')
            : strtolower((string)($match['filename_lower'] ?? ''));
        foreach ($candidate_rows[$filename] ?? [] as $index) {
            $full_key = (string)($match['full_key'] ?? '');
            $suggestions[$index][$filename . '|' . $full_key] = [
                'filename'      => (string)($match['filename_lower'] ?? ''),
                'full_key'      => $full_key,
                'size_bytes'    => isset($match['size_bytes']) ? (int)$match['size_bytes'] : null,
                'etag'          => (string)($match['etag'] ?? ''),
                'last_modified' => (string)($match['last_modified'] ?? ''),
            ];
        }
    }

    foreach ($suggestions as $index => $items) {
        $suggestions[$index] = array_values($items);
    }
    return $suggestions;
}

/** Return one exact S3 index row as trusted proof for a Folio media change. */
function lts_media_folio_s3_proof(string $filename, string $full_key): ?array {
    global $wpdb;

    $table = lts_media_mismatch_s3_index_table();
    if ($table === '' || $filename === '' || $full_key === '') {
        return null;
    }

    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT filename_lower, full_key, size_bytes, etag
         FROM `{$table}`
         WHERE filename_lower = %s AND full_key = %s
         LIMIT 1",
        $filename,
        $full_key
    ), ARRAY_A);
    if (!is_array($row) || empty($row['filename_lower']) || empty($row['full_key'])) {
        return null;
    }

    return [
        'filename'  => (string)$row['filename_lower'],
        'fullKey'   => (string)$row['full_key'],
        'sizeBytes' => (int)($row['size_bytes'] ?? 0),
        'etag'      => (string)($row['etag'] ?? ''),
    ];
}

/** Validate that a Folio write target is an exact basename, without normalizing it. */
function lts_media_folio_is_basename(string $filename): bool {
    if ($filename === '' || preg_match('~[\\\\/]~', $filename)) {
        return false;
    }
    if (filter_var($filename, FILTER_VALIDATE_URL)) {
        return false;
    }
    return wp_basename($filename) === $filename;
}

/** Extract one result status from a one-change Java response. */
function lts_media_folio_change_status(array $response): string {
    $results = isset($response['results']) && is_array($response['results'])
        ? $response['results']
        : [];
    return isset($results[0]['status']) ? sanitize_key((string)$results[0]['status']) : '';
}

function lts_media_folio_preview_transient_key(string $token): string {
    return 'lts_folio_media_' . md5($token);
}

/** Build a single safe repair operation from the mismatch report and current Java search data. */
function lts_media_folio_build_repair(array $input): array {
    $sku = trim(sanitize_text_field((string)($input['sku'] ?? '')));
    $report_type = sanitize_key((string)($input['report_type'] ?? ''));
    $expected_filename = trim(sanitize_text_field((string)($input['expected_filename'] ?? '')));
    $candidate_filename = trim(sanitize_text_field((string)($input['candidate_filename'] ?? '')));
    $candidate_full_key = trim(sanitize_text_field((string)($input['candidate_full_key'] ?? '')));

    if ($sku === '' || $expected_filename === '' || !in_array($report_type, ['featured', 'gallery'], true)) {
        return ['ok' => false, 'error' => 'invalid_repair_context'];
    }
    if (!lts_media_folio_is_basename($candidate_filename)) {
        return ['ok' => false, 'error' => 'invalid_target_filename'];
    }

    $proof = lts_media_folio_s3_proof($candidate_filename, $candidate_full_key);
    if (!$proof || $proof['etag'] === '') {
        return ['ok' => false, 'error' => 's3_proof_unavailable'];
    }

    // Use the exact filename from MariaDB, never a browser-normalized value.
    $candidate_filename = $proof['filename'];
    $role = $report_type === 'featured' ? 'main' : 'gallery';
    $search = lts_call_java_media_request('GET', '/admin/folio/product-media', [
        'sku'      => $sku,
        'filename' => $expected_filename,
        'role'     => $role,
        'match'    => 'normalized',
        'limit'    => 50,
        'offset'   => 0,
    ]);
    if (empty($search['ok']) || empty($search['json']) || empty($search['json']['ok'])) {
        return ['ok' => false, 'error' => 'folio_search_failed', 'java' => $search];
    }

    $items = isset($search['json']['items']) && is_array($search['json']['items'])
        ? array_values(array_filter($search['json']['items'], static function ($item) use ($sku, $role) {
            return is_array($item)
                && (string)($item['sku'] ?? '') === $sku
                && (string)($item['role'] ?? '') === $role;
        }))
        : [];
    if (count($items) !== 1) {
        return [
            'ok'    => false,
            'error' => count($items) ? 'folio_record_ambiguous' : 'folio_record_not_found',
            'search'=> $search['json'],
        ];
    }

    $item = $items[0];
    $operation = $role === 'main' ? 'set_main' : 'update_gallery';
    $record_id = null;
    if ($operation === 'update_gallery') {
        $record_id = (string)($item['recordId']['key'] ?? '');
        if ($record_id === '') {
            return ['ok' => false, 'error' => 'folio_gallery_record_id_missing', 'search' => $search['json']];
        }
    }

    $change = [
        'operation'            => $operation,
        'sku'                  => $sku,
        'recordId'             => $record_id,
        'expectedOldFilename'  => array_key_exists('filename', $item) ? $item['filename'] : null,
        'expectedOldSortOrder' => array_key_exists('sortOrder', $item) ? $item['sortOrder'] : null,
        'filename'             => $candidate_filename,
        'sortOrder'            => $operation === 'update_gallery' ? ($item['sortOrder'] ?? null) : null,
        's3Proof'              => [
            'fullKey'   => $proof['fullKey'],
            'sizeBytes' => $proof['sizeBytes'],
            'etag'      => $proof['etag'],
        ],
    ];

    return [
        'ok'        => true,
        'search'    => $search['json'],
        'item'      => $item,
        'change'    => $change,
        's3_proof'  => $proof,
    ];
}

/** AJAX: search current Folio row and send one read-only repair preview to Java. */
add_action('wp_ajax_lts_folio_media_repair_preview', function () {
    $cap = defined('LTS_CAP') ? LTS_CAP : 'manage_options';
    if (!current_user_can($cap)) wp_send_json_error(['error' => 'forbidden'], 403);
    check_ajax_referer('lts_admin_nonce', 'nonce');

    $built = lts_media_folio_build_repair(wp_unslash($_POST));
    if (empty($built['ok'])) {
        wp_send_json_error($built, 422);
    }

    $token = wp_generate_uuid4();
    $payload = [
        'externalRequestId' => 'woo-media-repair:' . $token,
        'previewOnly'       => true,
        'source'            => 'woo_media_repair',
        'changes'           => [$built['change']],
    ];
    $preview = lts_call_java_media('/admin/folio/product-media/changes', $payload);
    if (empty($preview['ok']) || empty($preview['json'])) {
        wp_send_json_error(['error' => 'folio_preview_failed', 'java' => $preview], 502);
    }

    $status = lts_media_folio_change_status($preview['json']);
    $can_apply = !empty($preview['json']['ok']) && $status === 'ready';
    if ($can_apply) {
        $apply_payload = $payload;
        $apply_payload['previewOnly'] = false;
        set_transient(lts_media_folio_preview_transient_key($token), [
            'user_id'    => get_current_user_id(),
            'created_at' => time(),
            'payload'    => $apply_payload,
            'preview'    => $preview['json'],
            'context'    => [
                'sku'        => (string)$built['change']['sku'],
                'product_id' => absint($_POST['product_id'] ?? 0),
            ],
        ], 30 * MINUTE_IN_SECONDS);
    }

    wp_send_json_success([
        'token'     => $can_apply ? $token : '',
        'can_apply' => $can_apply,
        'status'    => $status,
        'search'    => $built['search'],
        'change'    => $built['change'],
        'preview'   => $preview['json'],
    ]);
});

/** AJAX: apply the exact server-side request that previously returned ready. */
add_action('wp_ajax_lts_folio_media_repair_apply', function () {
    $cap = defined('LTS_CAP') ? LTS_CAP : 'manage_options';
    if (!current_user_can($cap)) wp_send_json_error(['error' => 'forbidden'], 403);
    check_ajax_referer('lts_admin_nonce', 'nonce');

    $token = sanitize_text_field((string)($_POST['token'] ?? ''));
    $stored = $token !== '' ? get_transient(lts_media_folio_preview_transient_key($token)) : false;
    if (!is_array($stored) || (int)($stored['user_id'] ?? 0) !== get_current_user_id()) {
        wp_send_json_error(['error' => 'preview_expired'], 410);
    }
    if (empty($stored['payload']) || !is_array($stored['payload'])) {
        wp_send_json_error(['error' => 'preview_payload_missing'], 422);
    }

    $result = lts_call_java_media_locked(
        '/admin/folio/product-media/changes',
        $stored['payload'],
        'manual',
        'folio_media_repair'
    );
    if (empty($result['ok']) || empty($result['json'])) {
        wp_send_json_error(['error' => 'folio_apply_failed', 'java' => $result], 502);
    }

    $status = lts_media_folio_change_status($result['json']);
    $applied = !empty($result['json']['ok']) && in_array($status, ['applied', 'noop'], true);
    if (function_exists('lts_log_db')) {
        lts_log_db($applied ? 'info' : 'error', 'folio_media_repair', [
            'sku'     => (string)($stored['context']['sku'] ?? ''),
            'post_id' => (int)($stored['context']['product_id'] ?? 0),
            'result'  => $status ?: 'unknown',
            'message' => $applied ? 'Folio product media reference updated.' : 'Folio product media update was blocked.',
            'ctx'     => [
                'external_request_id' => (string)($stored['payload']['externalRequestId'] ?? ''),
                'java_ok'             => !empty($result['json']['ok']),
            ],
        ]);
    }

    wp_send_json_success([
        'applied' => $applied,
        'status'  => $status,
        'result'  => $result['json'],
    ]);
});

/** Parse recent standalone mismatch events; Java summary blocks are intentionally ignored. */
function lts_media_mismatch_report_data(array $args): array {
    $path = lts_media_mismatch_log_path();
    if (!is_file($path) || !is_readable($path)) {
        return [
            'available' => false,
            'path'      => $path,
            'error'     => is_file($path)
                ? __('The mismatch log exists but the web server cannot read it.', 'lavka-total-sync')
                : __('The mismatch log was not found at the configured path.', 'lavka-total-sync'),
            'rows'      => [],
            'total'     => 0,
            'page'      => 1,
            'pages'     => 1,
        ];
    }

    $tail = lts_media_mismatch_log_tail($path);
    $events = [];
    $lines = explode("\n", str_replace("\r\n", "\n", $tail['content']));
    foreach ($lines as $line) {
        if (strpos($line, '[sync.mismatch]') === false) {
            continue;
        }

        $matched = preg_match(
            '/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(?:\.\d+)?)'
            . '.*?\[sync\.mismatch\]\s+(featured_failed|gallery_failed)'
            . '\s+sku=(.*?)\s+pid=(\d+)'
            . '(?:\s+file=(.*?))?\s+msg=(.+)$/u',
            trim($line),
            $match
        );
        if (!$matched) {
            continue;
        }

        $event_type = $match[2] === 'featured_failed' ? 'featured' : 'gallery';
        if ($args['type'] !== 'all' && $args['type'] !== $event_type) {
            continue;
        }

        $message = trim((string)$match[6]);
        $file = trim((string)($match[5] ?? ''));
        if ($file === '' && preg_match('/Not found in s3_media_index:\s*(.+)$/iu', $message, $file_match)) {
            $file = trim((string)$file_match[1]);
        }

        $sku = trim((string)$match[3]);
        $product_id = (int)$match[4];
        if ($args['query'] !== '') {
            $haystack = $sku . ' ' . $product_id . ' ' . $file . ' ' . $message;
            $position = function_exists('mb_stripos')
                ? mb_stripos($haystack, $args['query'])
                : stripos($haystack, $args['query']);
            if ($position === false) {
                continue;
            }
        }

        $events[] = [
            'time'       => (string)$match[1],
            'type'       => $event_type,
            'sku'        => $sku,
            'product_id' => $product_id,
            'file'       => $file,
            'message'    => $message,
        ];
    }

    $events = array_reverse($events);
    $hidden_product_ids = lts_media_hidden_product_ids(array_column($events, 'product_id'));
    foreach ($events as &$event) {
        $event['is_hidden'] = isset($hidden_product_ids[(int)$event['product_id']]);
    }
    unset($event);

    if ($args['visibility'] === 'exclude_hidden') {
        $events = array_values(array_filter($events, static function (array $event): bool {
            return empty($event['is_hidden']);
        }));
    } elseif ($args['visibility'] === 'hidden_only') {
        $events = array_values(array_filter($events, static function (array $event): bool {
            return !empty($event['is_hidden']);
        }));
    }

    $total = count($events);
    $pages = max(1, (int)ceil($total / $args['per_page']));
    $page = min($args['page'], $pages);
    $offset = ($page - 1) * $args['per_page'];
    $rows = array_slice($events, $offset, $args['per_page']);
    $s3_suggestions = lts_media_mismatch_s3_suggestions($rows);

    foreach ($rows as $row_index => &$row) {
        $explanation = lts_media_mismatch_explanation($row['message'], $row['file']);
        $row['type_label'] = $row['type'] === 'featured'
            ? __('Featured image', 'lavka-total-sync')
            : __('Gallery image', 'lavka-total-sync');
        $row['product_title'] = get_the_title($row['product_id']);
        $row['edit_url'] = get_edit_post_link($row['product_id'], 'raw');
        $row['cause_label'] = $explanation['label'];
        $row['explanation'] = $explanation['explanation'];
        $row['recommended_action'] = $explanation['action'];
        $row['hint'] = $explanation['hint'];
        $row['suggested_files'] = $s3_suggestions[$row_index] ?? [];
    }
    unset($row);

    return [
        'available'   => true,
        'path'        => $path,
        'modified_at' => wp_date('Y-m-d H:i:s', (int)filemtime($path)),
        'file_size'   => size_format((int)$tail['size'], 1),
        'truncated'   => !empty($tail['truncated']),
        'rows'        => $rows,
        'total'       => $total,
        'page'        => $page,
        'pages'       => $pages,
        'per_page'    => $args['per_page'],
        'visibility'  => $args['visibility'],
    ];
}

/** AJAX: read-only report of recent Java media mismatch events. */
add_action('wp_ajax_lts_media_mismatch_report', function () {
    $cap = defined('LTS_CAP') ? LTS_CAP : 'manage_options';
    if (!current_user_can($cap)) wp_send_json_error(['error' => 'forbidden'], 403);
    check_ajax_referer('lts_admin_nonce', 'nonce');

    wp_send_json_success(lts_media_mismatch_report_data(
        lts_media_mismatch_report_args(wp_unslash($_POST))
    ));
});

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

        <hr style="margin:1.25rem 0;">

        <section id="lts_media_mismatch_report">
            <h2><?php _e('Recent failed media links from Java', 'lavka-total-sync'); ?></h2>
            <p class="description">
                <?php _e('This is a historical view of sync-mismatch.log. An entry can remain after the image has been fixed; rerun media synchronization to get current results.', 'lavka-total-sync'); ?>
                <?php _e('A suggested Folio correction is always previewed first and is written only after separate confirmation.', 'lavka-total-sync'); ?>
            </p>
            <div class="lts-media-report-controls">
                <label for="lts_media_mismatch_type">
                    <span><?php _e('Image type', 'lavka-total-sync'); ?></span>
                    <select id="lts_media_mismatch_type">
                        <option value="all"><?php _e('All failures', 'lavka-total-sync'); ?></option>
                        <option value="featured"><?php _e('Featured images', 'lavka-total-sync'); ?></option>
                        <option value="gallery"><?php _e('Gallery images', 'lavka-total-sync'); ?></option>
                    </select>
                </label>
                <label for="lts_media_mismatch_query">
                    <span><?php _e('Search', 'lavka-total-sync'); ?></span>
                    <input id="lts_media_mismatch_query" type="search" placeholder="<?php echo esc_attr__('SKU, product ID or filename', 'lavka-total-sync'); ?>">
                </label>
                <label for="lts_media_mismatch_visibility">
                    <span><?php _e('Catalog visibility', 'lavka-total-sync'); ?></span>
                    <select id="lts_media_mismatch_visibility">
                        <option value="exclude_hidden"><?php _e('Exclude hidden products', 'lavka-total-sync'); ?></option>
                        <option value="all"><?php _e('Include hidden products', 'lavka-total-sync'); ?></option>
                        <option value="hidden_only"><?php _e('Hidden products only', 'lavka-total-sync'); ?></option>
                    </select>
                </label>
                <label for="lts_media_mismatch_per_page">
                    <span><?php _e('Rows per page', 'lavka-total-sync'); ?></span>
                    <select id="lts_media_mismatch_per_page">
                        <option value="25">25</option>
                        <option value="50" selected>50</option>
                        <option value="100">100</option>
                        <option value="200">200</option>
                    </select>
                </label>
                <button id="lts_btn_media_mismatch" class="button button-primary">
                    <?php _e('Read mismatch log', 'lavka-total-sync'); ?>
                </button>
                <span id="lts_media_mismatch_status" aria-live="polite"></span>
            </div>

            <div id="lts_media_mismatch_result" hidden>
                <p id="lts_media_mismatch_summary"></p>
                <div class="lts-media-report-table-wrap">
                    <table class="widefat striped" id="lts_media_mismatch_table">
                        <thead>
                            <tr>
                                <th><?php _e('Time', 'lavka-total-sync'); ?></th>
                                <th><?php _e('Type', 'lavka-total-sync'); ?></th>
                                <th><?php _e('Product', 'lavka-total-sync'); ?></th>
                                <th><?php _e('Expected file', 'lavka-total-sync'); ?></th>
                                <th><?php _e('What went wrong', 'lavka-total-sync'); ?></th>
                                <th><?php _e('What to do', 'lavka-total-sync'); ?></th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                </div>
                <div class="lts-media-report-pagination">
                    <button type="button" id="lts_media_mismatch_prev" class="button">
                        <?php _e('Previous'); ?>
                    </button>
                    <span id="lts_media_mismatch_page"></span>
                    <button type="button" id="lts_media_mismatch_next" class="button">
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
            #lts_media_mismatch_status {
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
            #lts_media_mismatch_table td {
                vertical-align: top;
            }
            #lts_media_mismatch_table .lts-mismatch-product,
            #lts_media_mismatch_table .lts-mismatch-details {
                display: grid;
                gap: 4px;
            }
            #lts_media_mismatch_table .lts-mismatch-hint {
                color: #8a4b08;
                font-weight: 600;
            }
            #lts_media_mismatch_table .lts-mismatch-suggestions {
                display: grid;
                gap: 5px;
                margin-top: 8px;
                padding: 8px 10px;
                border-left: 3px solid #dba617;
                background: #fff8e5;
            }
            #lts_media_mismatch_table .lts-mismatch-suggestions code {
                display: block;
                overflow-wrap: anywhere;
            }
            #lts_media_mismatch_table .lts-mismatch-suggestion {
                display: grid;
                gap: 2px;
            }
            #lts_media_mismatch_table .lts-folio-repair-actions {
                display: flex;
                flex-wrap: wrap;
                align-items: center;
                gap: 8px;
                margin-top: 4px;
            }
            #lts_media_mismatch_table .lts-folio-repair-result {
                display: grid;
                gap: 5px;
                margin-top: 6px;
                padding: 8px 10px;
                border-left: 3px solid #2271b1;
                background: #f0f6fc;
            }
            #lts_media_mismatch_table .lts-folio-repair-result.is-error {
                border-left-color: #d63638;
                background: #fcf0f1;
            }
            #lts_media_mismatch_table .lts-folio-repair-result.is-success {
                border-left-color: #00a32a;
                background: #edfaef;
            }
            #lts_media_mismatch_table .lts-folio-repair-messages {
                margin: 0 0 0 18px;
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
            let mediaMismatchPage = 1;
            let mediaMismatchPages = 1;

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

            function folioRepairStatusLabel(status) {
                const labels = {
                    ready: '<?php echo esc_js(__('Ready to apply', 'lavka-total-sync')); ?>',
                    applied: '<?php echo esc_js(__('Applied', 'lavka-total-sync')); ?>',
                    noop: '<?php echo esc_js(__('No change required', 'lavka-total-sync')); ?>',
                    blocked: '<?php echo esc_js(__('Blocked', 'lavka-total-sync')); ?>'
                };
                return labels[status] || status || '<?php echo esc_js(__('Unknown', 'lavka-total-sync')); ?>';
            }

            function folioRepairErrorLabel(code) {
                const labels = {
                    invalid_repair_context: '<?php echo esc_js(__('The report row does not contain enough data for a Folio correction.', 'lavka-total-sync')); ?>',
                    invalid_target_filename: '<?php echo esc_js(__('The target must be an exact filename without a path or URL.', 'lavka-total-sync')); ?>',
                    s3_proof_unavailable: '<?php echo esc_js(__('The exact S3 object, size or ETag could not be confirmed in the current media index.', 'lavka-total-sync')); ?>',
                    folio_search_failed: '<?php echo esc_js(__('Java could not search the current Folio media record.', 'lavka-total-sync')); ?>',
                    folio_record_ambiguous: '<?php echo esc_js(__('Several Folio records match this image. Automatic selection is unsafe.', 'lavka-total-sync')); ?>',
                    folio_record_not_found: '<?php echo esc_js(__('The current Folio media record was not found. The historical log may be outdated.', 'lavka-total-sync')); ?>',
                    folio_gallery_record_id_missing: '<?php echo esc_js(__('The Folio gallery row has no stable record ID.', 'lavka-total-sync')); ?>',
                    folio_preview_failed: '<?php echo esc_js(__('Java did not return a valid correction preview.', 'lavka-total-sync')); ?>',
                    preview_expired: '<?php echo esc_js(__('The preview expired. Run the preview again before applying.', 'lavka-total-sync')); ?>',
                    preview_payload_missing: '<?php echo esc_js(__('The preview expired. Run the preview again before applying.', 'lavka-total-sync')); ?>',
                    folio_apply_failed: '<?php echo esc_js(__('Java did not return a valid apply response.', 'lavka-total-sync')); ?>'
                };
                return labels[code] || code || '<?php echo esc_js(__('Request failed.', 'lavka-total-sync')); ?>';
            }

            function appendFolioMessages($target, title, messages) {
                if (!Array.isArray(messages) || !messages.length) return;
                $('<strong>').text(title).appendTo($target);
                const $list = $('<ul>', {class: 'lts-folio-repair-messages'}).appendTo($target);
                messages.forEach(function(message) {
                    const code = message && message.code ? message.code + ': ' : '';
                    $('<li>').text(code + (message && message.message ? message.message : '')).appendTo($list);
                });
            }

            function renderFolioRepairPreview($target, data) {
                $target.empty().removeClass('is-error is-success');
                const preview = data && data.preview ? data.preview : {};
                const result = Array.isArray(preview.results) && preview.results.length ? preview.results[0] : {};
                const before = result.before || {};
                const after = result.after || {};
                const status = data.status || result.status || '';

                $target.addClass(data.can_apply || status === 'noop' ? 'is-success' : 'is-error');
                $('<strong>').text('<?php echo esc_js(__('Folio correction preview', 'lavka-total-sync')); ?>').appendTo($target);
                $('<span>').text(
                    '<?php echo esc_js(__('Current filename:', 'lavka-total-sync')); ?>' + ' ' + (before.filename || '—')
                ).appendTo($target);
                $('<span>').text(
                    '<?php echo esc_js(__('New filename:', 'lavka-total-sync')); ?>' + ' ' + (after.filename || '—')
                ).appendTo($target);
                $('<span>').text(
                    '<?php echo esc_js(__('Java status:', 'lavka-total-sync')); ?>' + ' ' + folioRepairStatusLabel(status)
                ).appendTo($target);
                appendFolioMessages($target, '<?php echo esc_js(__('Warnings', 'lavka-total-sync')); ?>', result.warnings);
                appendFolioMessages($target, '<?php echo esc_js(__('Errors', 'lavka-total-sync')); ?>', result.errors);
                appendFolioMessages($target, '<?php echo esc_js(__('Warnings', 'lavka-total-sync')); ?>', preview.warnings);
                appendFolioMessages($target, '<?php echo esc_js(__('Errors', 'lavka-total-sync')); ?>', preview.errors);

                if (data.can_apply && data.token) {
                    $('<button>', {
                        type: 'button',
                        class: 'button button-primary lts-folio-repair-apply',
                        text: '<?php echo esc_js(__('Apply correction in Folio', 'lavka-total-sync')); ?>'
                    }).data('token', data.token).appendTo($target);
                }
            }

            function renderFolioRepairFailure($target, payload) {
                const data = payload && payload.data ? payload.data : payload;
                const code = data && data.error ? data.error : '';
                $target.empty().removeClass('is-success').addClass('is-error');
                $('<strong>').text('<?php echo esc_js(__('Folio correction was not prepared', 'lavka-total-sync')); ?>').appendTo($target);
                $('<span>').text(folioRepairErrorLabel(code)).appendTo($target);
                const java = data && data.java ? data.java : {};
                const technical = java.message || java.error || java.raw || '';
                if (technical) {
                    $('<details>')
                        .append($('<summary>').text('<?php echo esc_js(__('Technical message', 'lavka-total-sync')); ?>'))
                        .append($('<pre>').text(technical))
                        .appendTo($target);
                }
            }

            function renderMediaMismatchReport(data) {
                const $result = $('#lts_media_mismatch_result').prop('hidden', false);
                const $body = $('#lts_media_mismatch_table tbody').empty();
                const $summary = $('#lts_media_mismatch_summary').empty();

                if (!data.available) {
                    $('<strong>').text(data.error || '<?php echo esc_js(__('Mismatch log is unavailable.', 'lavka-total-sync')); ?>').appendTo($summary);
                    $summary.append(document.createTextNode(' '));
                    $('<code>').text(data.path || '').appendTo($summary);
                    $('<tr>').append(
                        $('<td>', {colspan: 6}).text(data.error || '<?php echo esc_js(__('Mismatch log is unavailable.', 'lavka-total-sync')); ?>')
                    ).appendTo($body);
                    $('#lts_media_mismatch_status').text('<?php echo esc_js(__('Unavailable', 'lavka-total-sync')); ?>');
                    return;
                }

                $summary
                    .append(document.createTextNode('<?php echo esc_js(__('Found:', 'lavka-total-sync')); ?>' + ' ' + data.total + '. '))
                    .append(document.createTextNode('<?php echo esc_js(__('Updated:', 'lavka-total-sync')); ?>' + ' ' + data.modified_at + '. '))
                    .append(document.createTextNode('<?php echo esc_js(__('File:', 'lavka-total-sync')); ?>' + ' ' + data.file_size + ' '))
                    .append($('<code>').text(data.path));
                if (data.truncated) {
                    $summary.append($('<span>', {class: 'description'}).text(
                        ' <?php echo esc_js(__('Only the newest part of the large log was read.', 'lavka-total-sync')); ?>'
                    ));
                }

                if (!data.rows.length) {
                    $('<tr>').append(
                        $('<td>', {colspan: 6}).text('<?php echo esc_js(__('No failed media links found', 'lavka-total-sync')); ?>')
                    ).appendTo($body);
                } else {
                    data.rows.forEach(function(row) {
                        const $product = $('<div>', {class: 'lts-mismatch-product'});
                        $('<code>').text(row.sku || '—').appendTo($product);
                        const productLabel = '#' + row.product_id + (row.product_title ? ' · ' + row.product_title : '');
                        if (row.edit_url) {
                            $('<a>', {href: row.edit_url}).text(productLabel).appendTo($product);
                        } else {
                            $('<span>').text(productLabel).appendTo($product);
                        }

                        const $details = $('<div>', {class: 'lts-mismatch-details'});
                        $('<strong>').text(row.cause_label).appendTo($details);
                        $('<span>').text(row.explanation).appendTo($details);
                        const $technical = $('<details>');
                        $('<summary>').text('<?php echo esc_js(__('Technical message', 'lavka-total-sync')); ?>').appendTo($technical);
                        $('<code>').text(row.message).appendTo($technical);
                        $technical.appendTo($details);

                        const $action = $('<div>', {class: 'lts-mismatch-details'});
                        $('<span>').text(row.recommended_action).appendTo($action);
                        if (row.hint) {
                            $('<span>', {class: 'lts-mismatch-hint'}).text(row.hint).appendTo($action);
                        }
                        if (Array.isArray(row.suggested_files) && row.suggested_files.length) {
                            const $suggestions = $('<div>', {class: 'lts-mismatch-suggestions'});
                            $('<strong>').text(
                                '<?php echo esc_js(__('Possible matching files found in the current OVH/S3 media index table:', 'lavka-total-sync')); ?>'
                            ).appendTo($suggestions);
                            row.suggested_files.forEach(function(item) {
                                const $candidate = $('<div>', {class: 'lts-mismatch-suggestion'});
                                $('<code>').text(item.filename).appendTo($candidate);
                                if (item.full_key) {
                                    $('<span>', {class: 'description'}).text(
                                        '<?php echo esc_js(__('OVH/S3 index path:', 'lavka-total-sync')); ?>' + ' ' + item.full_key
                                    ).appendTo($candidate);
                                }
                                if (item.size_bytes !== null || item.etag) {
                                    const proof = [];
                                    if (item.size_bytes !== null) {
                                        proof.push(item.size_bytes + ' <?php echo esc_js(__('bytes', 'lavka-total-sync')); ?>');
                                    }
                                    if (item.etag) proof.push('ETag ' + item.etag);
                                    $('<span>', {class: 'description'}).text(
                                        '<?php echo esc_js(__('S3 proof:', 'lavka-total-sync')); ?>' + ' ' + proof.join(' · ')
                                    ).appendTo($candidate);
                                }
                                const $repairActions = $('<div>', {class: 'lts-folio-repair-actions'}).appendTo($candidate);
                                const $repairResult = $('<div>', {
                                    class: 'lts-folio-repair-result',
                                    hidden: true,
                                    'aria-live': 'polite'
                                }).appendTo($candidate);
                                const $previewButton = $('<button>', {
                                    type: 'button',
                                    class: 'button lts-folio-repair-preview',
                                    text: '<?php echo esc_js(__('Preview Folio correction', 'lavka-total-sync')); ?>',
                                    disabled: !item.full_key || !item.etag
                                }).data('repair', {
                                    sku: row.sku,
                                    product_id: row.product_id,
                                    report_type: row.type,
                                    expected_filename: row.file,
                                    candidate_filename: item.filename,
                                    candidate_full_key: item.full_key
                                }).appendTo($repairActions);
                                if (!item.etag) {
                                    $previewButton.attr('title', '<?php echo esc_js(__('This S3 index row has no ETag, so it cannot be used as write proof.', 'lavka-total-sync')); ?>');
                                }
                                $candidate.appendTo($suggestions);
                            });
                            $('<span>').text(
                                '<?php echo esc_js(__("These filenames are actual records from s3_media_index found after normalizing the filename from Folio. If one of these files is correct, set its exact filename in Folio and rerun media synchronization; do not upload another duplicate.", 'lavka-total-sync')); ?>'
                            ).appendTo($suggestions);
                            $suggestions.appendTo($action);
                        }

                        $('<tr>')
                            .append($('<td>').text(row.time))
                            .append($('<td>').text(row.type_label))
                            .append($('<td>').append($product))
                            .append($('<td>').append($('<code>').text(row.file || '—')))
                            .append($('<td>').append($details))
                            .append($('<td>').append($action))
                            .appendTo($body);
                    });
                }

                mediaMismatchPage = data.page;
                mediaMismatchPages = data.pages;
                $('#lts_media_mismatch_page').text(
                    '<?php echo esc_js(__('Page', 'lavka-total-sync')); ?>' + ' ' + data.page + ' / ' + data.pages
                );
                $('#lts_media_mismatch_prev').prop('disabled', data.page <= 1);
                $('#lts_media_mismatch_next').prop('disabled', data.page >= data.pages);
                $('#lts_media_mismatch_status').text('<?php echo esc_js(__('Done.','lavka-total-sync')); ?>');
            }

            function loadMediaMismatchReport(page) {
                $('#lts_media_mismatch_status').text('<?php echo esc_js(__('Working…','lavka-total-sync')); ?>');
                $.post(ajaxUrl, {
                    action: 'lts_media_mismatch_report',
                    nonce: nonce,
                    type: $('#lts_media_mismatch_type').val(),
                    visibility: $('#lts_media_mismatch_visibility').val(),
                    query: $('#lts_media_mismatch_query').val(),
                    per_page: $('#lts_media_mismatch_per_page').val(),
                    page: page
                }).done(function(res) {
                    if (!res || !res.success) {
                        $('#lts_media_mismatch_status').text('<?php echo esc_js(__('Error','lavka-total-sync')); ?>');
                        return;
                    }
                    renderMediaMismatchReport(res.data);
                }).fail(function() {
                    $('#lts_media_mismatch_status').text('<?php echo esc_js(__('Error','lavka-total-sync')); ?>');
                });
            }

            $('#lts_btn_media_mismatch').on('click', function(e) {
                e.preventDefault();
                loadMediaMismatchReport(1);
            });
            $('#lts_media_mismatch_query').on('keydown', function(e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    loadMediaMismatchReport(1);
                }
            });
            $('#lts_media_mismatch_prev').on('click', function() {
                if (mediaMismatchPage > 1) loadMediaMismatchReport(mediaMismatchPage - 1);
            });
            $('#lts_media_mismatch_next').on('click', function() {
                if (mediaMismatchPage < mediaMismatchPages) loadMediaMismatchReport(mediaMismatchPage + 1);
            });

            $('#lts_media_mismatch_table').on('click', '.lts-folio-repair-preview', function() {
                const $button = $(this);
                const $candidate = $button.closest('.lts-mismatch-suggestion');
                const $result = $candidate.find('.lts-folio-repair-result').first().prop('hidden', false);
                const repair = $button.data('repair') || {};

                $button.prop('disabled', true).text('<?php echo esc_js(__('Preparing preview…', 'lavka-total-sync')); ?>');
                $result.empty().removeClass('is-error is-success');
                $('<span>').text('<?php echo esc_js(__('Searching the current Folio record and validating S3 proof…', 'lavka-total-sync')); ?>').appendTo($result);

                $.post(ajaxUrl, $.extend({
                    action: 'lts_folio_media_repair_preview',
                    nonce: nonce
                }, repair)).done(function(response) {
                    if (!response || !response.success) {
                        renderFolioRepairFailure($result, response || {});
                        return;
                    }
                    renderFolioRepairPreview($result, response.data);
                }).fail(function(xhr) {
                    renderFolioRepairFailure($result, xhr.responseJSON || {});
                }).always(function() {
                    $button.prop('disabled', false).text('<?php echo esc_js(__('Preview Folio correction', 'lavka-total-sync')); ?>');
                });
            });

            $('#lts_media_mismatch_table').on('click', '.lts-folio-repair-apply', function() {
                const $button = $(this);
                const $result = $button.closest('.lts-folio-repair-result');
                const token = $button.data('token') || '';

                $button.prop('disabled', true).text('<?php echo esc_js(__('Applying…', 'lavka-total-sync')); ?>');
                $.post(ajaxUrl, {
                    action: 'lts_folio_media_repair_apply',
                    nonce: nonce,
                    token: token
                }).done(function(response) {
                    if (!response || !response.success) {
                        renderFolioRepairFailure($result, response || {});
                        return;
                    }
                    const data = response.data || {};
                    const javaResponse = data.result || {};
                    const javaResult = Array.isArray(javaResponse.results) && javaResponse.results.length
                        ? javaResponse.results[0]
                        : {};
                    $result.empty().removeClass('is-error is-success').addClass(data.applied ? 'is-success' : 'is-error');
                    $('<strong>').text(data.applied
                        ? '<?php echo esc_js(__('Folio correction applied', 'lavka-total-sync')); ?>'
                        : '<?php echo esc_js(__('Folio correction was blocked', 'lavka-total-sync')); ?>'
                    ).appendTo($result);
                    $('<span>').text(
                        '<?php echo esc_js(__('Java status:', 'lavka-total-sync')); ?>' + ' ' + folioRepairStatusLabel(data.status || javaResult.status)
                    ).appendTo($result);
                    appendFolioMessages($result, '<?php echo esc_js(__('Warnings', 'lavka-total-sync')); ?>', javaResult.warnings);
                    appendFolioMessages($result, '<?php echo esc_js(__('Errors', 'lavka-total-sync')); ?>', javaResult.errors);
                    appendFolioMessages($result, '<?php echo esc_js(__('Warnings', 'lavka-total-sync')); ?>', javaResponse.warnings);
                    appendFolioMessages($result, '<?php echo esc_js(__('Errors', 'lavka-total-sync')); ?>', javaResponse.errors);
                }).fail(function(xhr) {
                    renderFolioRepairFailure($result, xhr.responseJSON || {});
                });
            });

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
