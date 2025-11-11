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
    try {
        $res = lts_call_java_media('/admin/media/sync/range', $payload);
        if (function_exists('lts_log_db')) {
            lts_log_db(!empty($res['ok']) ? 'info' : 'error', 'media_cron', [
                'result' => !empty($res['ok']) ? 'ok' : 'fail',
                'http'   => $res['http'] ?? 200,
            ]);
        }
    } catch (Throwable $e) {
        error_log('[LTS][media_cron] exception: '.$e->getMessage());
    }
    // Перепланируем следующий одиночный запуск
    lts_media_cron_schedule_next();
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

    $res = lts_call_java_media('/admin/media/sync/range', $payload);
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

    $res = lts_call_java_media('/admin/media/sync', $payload);
    if (!empty($res['ok'])) wp_send_json_success($res);
    wp_send_json_error($res);
});

/** Рендер страницы UI */
function lts_render_media_sync_page() {
    $cap = defined('LTS_CAP') ? LTS_CAP : 'manage_options';
    if (!current_user_can($cap)) return;

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
        <h2><?php _e('Media Sync Cron (range only)', 'lavka-total-sync'); ?></h2>
        <?php $o = function_exists('lts_get_options') ? lts_get_options() : []; ?>
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
            $('#lts_mc_freq').on('change', function(){
                if ($(this).val()==='weekly') $('#lts_mc_row_dow').show(); else $('#lts_mc_row_dow').hide();
            });
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
            updNext();
        })(jQuery);
        </script>

        <script>
        (function($){
            const ajaxUrl = '<?php echo esc_js(admin_url('admin-ajax.php')); ?>';
            const nonce   = '<?php echo esc_js( wp_create_nonce('lts_admin_nonce') ); ?>';

            function print(obj){ try{return JSON.stringify(obj,null,2);}catch(e){return String(obj);} }

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