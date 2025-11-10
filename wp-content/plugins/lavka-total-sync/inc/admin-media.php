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