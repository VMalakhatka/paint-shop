<?php
// [LTS] ANCHOR: guard
if (!defined('ABSPATH')) exit;

/**
 * lavka-total-sync — ядро тотальной синхронизации карточек
 * Источник: GET {JAVA_BASE_URL}/admin/export/card-tov?limit=...&after=...
 *
 * ЗАМЕТКИ
 * - Не трогаем цену/остатки (это делают отдельные плагины).
 * - Создаём новые товары, обновляем существующие.
 * - Пишем term->description для категории из grDescr (чтобы «прилипало» сверху).
 * - Помечаем каждый обработанный товар метой _sync_updated_at (локальное время WP).
 * - По окончании можно «задрафтить» всё, что не обновлялось дольше порога.
 */


// [LTS] ANCHOR: constants
if (!defined('LTS_OPT'))        define('LTS_OPT',        'lts_options');
if (!defined('LTS_CAP'))        define('LTS_CAP',        'manage_lavka_sync'); // доступ
if (!defined('LTS_USER_AGENT')) define('LTS_USER_AGENT', 'LavkaTotalSync/1.0');

// [LTS] ANCHOR: logs include (single source of truth)
require_once __DIR__ . '/logs.php';



// [LTS] ANCHOR: http GET helper
if (!function_exists('lts_http_get_json')) {
    function lts_http_get_json(string $url, array $headers = [], int $timeout = 120) {
        $args = [
            'timeout' => $timeout,
            'headers' => array_merge([
                'Accept'     => 'application/json',
                'User-Agent' => LTS_USER_AGENT,
            ], $headers),
        ];
        $resp = wp_remote_get($url, $args);
        if (is_wp_error($resp)) {
            return ['ok'=>false, 'error'=>$resp->get_error_message()];
        }
        $code = wp_remote_retrieve_response_code($resp);
        $ct   = (string)wp_remote_retrieve_header($resp, 'content-type');
        $body = (string)wp_remote_retrieve_body($resp);
        if ($code < 200 || $code >= 300) {
            return ['ok'=>false, 'error'=>"HTTP $code", 'body'=>substr($body, 0, 600)];
        }
        $json = (stripos($ct, 'json') !== false) ? json_decode($body, true) : null;
        if (!is_array($json)) {
            return ['ok'=>false, 'error'=>'bad_json'];
        }
        return ['ok'=>true, 'data'=>$json];
    }
}

// [LTS] ANCHOR: find product by SKU
if (!function_exists('lts_find_product_id_by_sku')) {
    function lts_find_product_id_by_sku(string $sku): ?int {
        global $wpdb;
        $pid = $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta}
            WHERE meta_key = '_sku' AND meta_value = %s
            ORDER BY post_id ASC LIMIT 1", $sku
        ));
        return $pid ? (int)$pid : null;
    }
}

// [LTS] ANCHOR: ensure product post exists
function lts_ensure_product(string $sku, string $name): int {
    $pid = lts_find_product_id_by_sku($sku);
    if ($pid) return $pid;

    $postarr = [
        'post_type'   => 'product',
        'post_status' => 'publish',
        'post_title'  => $name ?: $sku,
        'post_content'=> '',
    ];
    $pid = wp_insert_post($postarr, true);
    if (is_wp_error($pid)) {
        throw new RuntimeException('insert_product_failed: '.$pid->get_error_message());
    }
    update_post_meta($pid, '_sku', $sku);
    // по умолчанию — простой товар
    // update_post_meta($pid, '_visibility', 'visible'); - это убрать ? заменить не надо?
    update_post_meta($pid, '_manage_stock', 'no'); // Остатки не трогаем тут
    return (int)$pid;
}

// [LTS] ANCHOR: attach image
function lts_attach_external_image(?string $url, int $post_id): ?int {
    if (!$url) return null;

    // Уже есть миниатюра? — не перезагружаем каждый раз (минимум дерганий).
    $thumb_id = get_post_thumbnail_id($post_id);
    if ($thumb_id) return (int)$thumb_id;

    // Импортируем
    require_once ABSPATH . 'wp-admin/includes/media.php';
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';

    $att_id = media_sideload_image($url, $post_id, null, 'id');
    if (is_wp_error($att_id)) {
        error_log('[LTS] image import failed: '.$att_id->get_error_message().' url='.$url);
        return null;
    }
    set_post_thumbnail($post_id, $att_id);
    return (int)$att_id;
}

// [LTS] ANCHOR: normalize text helper
/**
 * Нормализует текст для сравнения: удаляет теги, приводит пробелы, тримит.
 */
function lts_norm_text(?string $s): string {
    $s = (string)$s;
    // remove tags and decode entities the WP-way
    $s = wp_strip_all_tags($s, true);
    // normalize whitespace
    $s = preg_replace('/\s+/u', ' ', $s);
    return trim($s);
}

// [LTS] ANCHOR: set category + description (with prefix/suffix + tiny cache)
function lts_assign_category_and_description(int $post_id, ?int $groupId, ?string $grDescr): void {
    if (!$groupId || $groupId <= 0) return;

    // привяжем товар к категории
    wp_set_post_terms($post_id, [$groupId], 'product_cat', false);

    // ---- read prefix/suffix HTML directly from plugin options (LTS_OPT)
    $opt    = get_option(LTS_OPT, []);
    $prefix = isset($opt['cat_desc_prefix_html']) ? trim((string)$opt['cat_desc_prefix_html']) : '';
    $suffix = isset($opt['cat_desc_suffix_html']) ? trim((string)$opt['cat_desc_suffix_html']) : '';

    // исходный текст (может быть пустым — тогда выходим)
    $core = trim((string)$grDescr);
    if ($core === '') return;

    // если уже пришёл с приклеенными краями — не дублируем
    $finalHtml = $core;
    $coreTrim  = trim($core);

    // debug: log options read
    lts_log_db('info', 'catdesc', [
        'result'  => 'opts',
        'message' => 'cat-desc options read',
        'ctx'     => [
            'prefix_len' => strlen($prefix),
            'suffix_len' => strlen($suffix),
            'term_id'    => $groupId,
        ],
    ]);

    $hasPrefix = $prefix !== '' && str_starts_with($coreTrim, trim($prefix));
    $hasSuffix = $suffix !== '' && str_ends_with($coreTrim, trim($suffix));

    if (!$hasPrefix || !$hasSuffix) {
        $finalHtml = ($hasPrefix ? '' : $prefix) . $coreTrim . ($hasSuffix ? '' : $suffix);
    }

    lts_log_db('info', 'catdesc', [
        'result'  => 'build',
        'message' => 'cat-desc built',
        'ctx'     => [
            'core_len'  => strlen($coreTrim),
            'final_len' => strlen($finalHtml),
            'term_id'   => $groupId,
        ],
    ]);
    // --- лёгкий кэш, чтобы не дёргать БД по одному и тому же терму
    static $termWriteCache = []; // term_id => finalHtml (точное сравнение)
    static $termReadCache  = []; // term_id => currentHtml (точное сравнение)

    if (isset($termWriteCache[$groupId]) && $termWriteCache[$groupId] === $finalHtml) {
        lts_log_db('info', 'catdesc', [
            'result'  => 'skip',
            'message' => 'cat-desc unchanged',
            'ctx'     => ['term_id' => $groupId, 'changed' => 0],
        ]);
        return;
    }

    if (!isset($termReadCache[$groupId])) {
        $t = get_term($groupId, 'product_cat');
        $termReadCache[$groupId] = ($t && !is_wp_error($t)) ? (string)$t->description : '';
    }

    // обновляем только если реально отличается HTML (точное сравнение)
        // обновляем только если реально отличается HTML (точное сравнение)
    if ($termReadCache[$groupId] !== $finalHtml) {

        // --- ВАЖНО: временно снимаем KSES-фильтры, чтобы сохранить inline-стили и теги
        $removed = [];

        // Эти фильтры часто режут описание термов
        if (has_filter('pre_term_description', 'wp_filter_kses')) {
            remove_filter('pre_term_description', 'wp_filter_kses');
            $removed[] = ['pre_term_description','wp_filter_kses'];
        }
        if (has_filter('term_description', 'wp_kses_data')) {
            remove_filter('term_description', 'wp_kses_data');
            $removed[] = ['term_description','wp_kses_data'];
        }
        // На некоторых инсталляциях встречается ещё wp_kses_post
        if (has_filter('pre_term_description', 'wp_kses_post')) {
            remove_filter('pre_term_description', 'wp_kses_post');
            $removed[] = ['pre_term_description','wp_kses_post'];
        }

        // Обновляем описание с HTML/inline-стилями
        $res = wp_update_term($groupId, 'product_cat', ['description' => $finalHtml]);

        // Возвращаем фильтры назад, чтобы не влиять на чужой код
        foreach ($removed as [$hook,$cb]) {
            add_filter($hook, $cb);
        }

        if (!is_wp_error($res)) {
            $termReadCache[$groupId]  = $finalHtml;
            $termWriteCache[$groupId] = $finalHtml;
            lts_log_db('info', 'catdesc', [
                'result'  => 'update',
                'message' => 'cat-desc updated',
                'ctx'     => ['term_id' => $groupId, 'changed' => 1],
            ]);
        }
    } else {
        $termWriteCache[$groupId] = $finalHtml;
        lts_log_db('info', 'catdesc', [
            'result'  => 'skip',
            'message' => 'cat-desc unchanged',
            'ctx'     => ['term_id' => $groupId, 'changed' => 0],
        ]);
    }
}

// [LTS] ANCHOR: upsert one item (без цены/остатков)
function lts_upsert_product_from_item(array $it, array $opts): array {
    $sku   = (string)($it['sku'] ?? '');
    if ($sku === '') return ['ok'=>false, 'error'=>'empty_sku'];

    $name  = (string)($it['name'] ?? $sku);
    $pid   = lts_ensure_product($sku, $name);

    // Обновляем базовые поля
    wp_update_post([
        'ID'           => $pid,
        'post_title'   => $name ?: $sku,
        'post_content' => (string)($it['description'] ?? ''),
        // post_status не трогаем — задача с «устаревшими» ниже
    ]);

    // Габариты/вес (Woo ожидает строки, но мы приведём к числу и обратно)
    $weight = isset($it['weight']) ? (float)$it['weight'] : null;
    $length = isset($it['length']) ? (float)$it['length'] : null;
    $width  = isset($it['width'])  ? (float)$it['width']  : null;
    $height = isset($it['height']) ? (float)$it['height'] : null;

    if ($weight !== null) update_post_meta($pid, '_weight', (string)$weight);
    if ($length !== null) update_post_meta($pid, '_length', (string)$length);
    if ($width  !== null) update_post_meta($pid, '_width',  (string)$width);
    if ($height !== null) update_post_meta($pid, '_height', (string)$height);

    // Прочие поля в мету (если пришли)
    $map = [
        'globalUniqueId' => '_global_uid',
        'razmIzmer'      => '_razm_izmer',
        'edinIzmer'      => '_edin_izmer',
        'vesEdinic'      => '_ves_edinic',
        'status'         => '_ms_status',
    ];
    foreach ($map as $src => $meta_key) {
        if (array_key_exists($src, $it)) {
            $val = $it[$src];
            update_post_meta($pid, $meta_key, is_scalar($val) ? (string)$val : wp_json_encode($val));
        }
    }

    // Категория + её описание
    $groupId = isset($it['groupId']) ? (int)$it['groupId'] : null;
    $grDescr = array_key_exists('grDescr', $it) ? (string)$it['grDescr'] : null;
    lts_assign_category_and_description($pid, $groupId, $grDescr);

    // Картинка (по желанию)
    if (!empty($opts['import_images'])) {
        $img = (string)($it['img'] ?? '');
        if ($img !== '') lts_attach_external_image($img, $pid);
    }

    // Пометка времени успешного апдейта
    update_post_meta($pid, '_sync_updated_at', current_time('mysql')); // локальное WP-время

    return ['ok'=>true, 'post_id'=>$pid];
}

// [LTS] ANCHOR: fetch one page from Java
function lts_fetch_page(string $base, string $token, int $limit, ?string $after, int $timeout): array {
    $q = [
        'limit' => $limit,
    ];
    if ($after) $q['after'] = $after;

    $url = $base . '/admin/export/card-tov?' . http_build_query($q);
    $headers = $token ? ['Authorization' => 'Bearer '.$token] : [];
    return lts_http_get_json($url, $headers, $timeout);
}

// [LTS] ANCHOR: mark stale to draft
function lts_mark_stale_products_as_draft(int $older_than_seconds = 7200): int {
    $threshold_ts = time() - max(60, $older_than_seconds);
    // wp_date/ date_i18n не нужны: в мета хранится строка локального времени.
    $threshold = function_exists('wp_date')
        ? wp_date('Y-m-d H:i:s', $threshold_ts, wp_timezone())
        : date_i18n('Y-m-d H:i:s', $threshold_ts);

    $args = [
        'post_type'      => 'product',
        'posts_per_page' => -1,
        'post_status'    => ['publish', 'private'],
        'meta_query'     => [
            [
                'key'     => '_sync_updated_at',
                'value'   => $threshold,
                'compare' => '<',
                'type'    => 'DATETIME'
            ]
        ],
        'fields'         => 'ids',
    ];
    $q = new WP_Query($args);
    $count = 0;
    if (!empty($q->posts)) {
        foreach ($q->posts as $pid) {
            // Безопасно: только меняем статус, содержимое не трогаем
            wp_update_post([
                'ID'          => (int)$pid,
                'post_status' => 'draft'
            ]);
            $count++;
        }
    }
    wp_reset_postdata();
    return $count;
}

// [LTS] ANCHOR: logger (простой)
function lts_log(string $msg, array $ctx = []): void {
    $line = '[LTS] '.$msg;
    if ($ctx) $line .= ' '.wp_json_encode($ctx);
    error_log($line);
}

// [LTS] ANCHOR: public runner
/**
 * Запуск тотальной синхронизации:
 * - Пагинация по keyset (after=lastSku)
 * - Создание/обновление карточек (без цен/остатков)
 * - Пометка _sync_updated_at
 * - (опционально) «задрафтить» устаревшие
 *
 * @param array $args [
 *   'limit'   => int (override page limit, 50..1000),
 *   'after'   => string|null (начальный курсор),
 *   'max'     => int|null (макс. кол-во элементов за прогон; null = не ограничивать),
 *   'dry_run' => bool (только проверить источник, без записи в Woo),
 *   'draft_stale_seconds' => int|null (если задан — по завершении задрафтить те, кто старее),
 *   'max_seconds' => int|null (макс. время выполнения в секундах; null = не ограничивать),
 * ]
 */
function lts_sync_goods_run(array $args = []): array {
    if (!class_exists('WC_Product')) {
        return ['ok'=>false, 'error'=>'woocommerce_missing'];
    }

    $o = lts_get_options();
    if (!$o['java_base_url']) {
        return ['ok'=>false, 'error'=>'java_base_url_missing'];
    }

    $limit   = isset($args['limit']) ? max(50, min(1000, (int)$args['limit'])) : (int)$o['page_limit'];
    $after   = isset($args['after']) ? (string)$args['after'] : null;
    $max     = isset($args['max']) ? max(1, (int)$args['max']) : null;
    $dry     = !empty($args['dry_run']);
    $draftStale = isset($args['draft_stale_seconds']) ? max(60, (int)$args['draft_stale_seconds']) : null;
    $maxSeconds = isset($args['max_seconds']) ? max(1, (int)$args['max_seconds']) : null;


        // [LTS] ANCHOR: logs - start
    lts_log_db('info', 'sync', [
        'result'  => 'start',
        'message' => 'sync goods start',
        'ctx'     => ['limit'=>$limit, 'after'=>$after, 'max'=>$max, 'dry'=>$dry]
    ]);

    $done = 0;
    $created = 0;
    $updated = 0;
    $last_cursor = $after;
    $last_flag = false;
    $started_at = time();

    do {
        $j = lts_fetch_page($o['java_base_url'], $o['api_token'], $limit, $last_cursor, (int)$o['timeout']);
        if (empty($j['ok'])) {
            lts_log('fetch_page_error', ['error'=>$j['error'] ?? 'unknown', 'after'=>$last_cursor]);
            return ['ok'=>false, 'error'=>$j['error'] ?? 'fetch_failed', 'after'=>$last_cursor];
        }
        $data = $j['data'];
        $items = is_array($data['items'] ?? null) ? $data['items'] : [];
        $next = isset($data['nextAfter']) ? (string)$data['nextAfter'] : null;
        $last_flag = !empty($data['last']);

        if (!$items) {
            // пустая страница — считаем завершением
            break;
        }

        foreach ($items as $it) {
            if ($maxSeconds && (time() - $started_at) >= $maxSeconds) {
                $last_flag = true;
                break 2;
            }
            // сухой прогон — ничего не пишем
            if ($dry) {
                $done++;
                $last_cursor = (string)($it['sku'] ?? $last_cursor);
                continue;
            }

            $sku = (string)($it['sku'] ?? '');
            if ($sku === '') continue;

            $pid_before = lts_find_product_id_by_sku($sku);
            try {
                $res = lts_upsert_product_from_item($it, $o);
                if (!empty($res['ok'])) {
                    // [LTS] ANCHOR: logs - per item ok
                    lts_log_db('info', 'upsert', [
                        'sku'     => $sku,
                        'post_id' => $res['post_id'] ?? null,
                        'result'  => $pid_before ? 'updated' : 'created'
                    ]);
                    if ($pid_before) $updated++; else $created++;
                    $done++;
                } else {
                    // was: lts_log('upsert_failed', [...]);
                    lts_log_db('error', 'upsert', [
                        'sku'     => $sku,
                        'post_id' => $pid_before ?: null,
                        'result'  => 'failed',
                        'message' => $res['error'] ?? 'unknown'
                    ]);
                }
            } catch (Throwable $e) {
                // was: lts_log('exception_upsert', [...]);
                lts_log_db('error', 'upsert', [
                    'sku'     => $sku,
                    'post_id' => $pid_before ?: null,
                    'result'  => 'exception',
                    'message' => $e->getMessage()
                ]);
            }

            $last_cursor = $sku;
            if ($max && $done >= $max) {
                $last_flag = true; // условно завершаем досрочно
                break 2;
            }
        }

        // движемся дальше
        $last_cursor = $next ?: $last_cursor;

        // небольшая щадящая пауза для уменьшения нагрузки
        usleep(100 * 1000); // 100ms

    } while (!$last_flag);

    $drafted = 0;
    if (!$dry && $draftStale) {
        $drafted = lts_mark_stale_products_as_draft($draftStale);
        // [LTS] ANCHOR: logs - drafted summary

                lts_log_db('info', 'draft', [
                    'result'  => 'drafted',
                    'message' => 'marked stale products as draft',
                    'ctx'     => ['count'=>$drafted, 'older_than_sec'=>$draftStale]
                ]);
    }

    $elapsed = time() - $started_at;
    lts_log('sync_done', [
        'done'=>$done, 'created'=>$created, 'updated'=>$updated,
        'drafted'=>$drafted, 'elapsed_sec'=>$elapsed, 'last_after'=>$last_cursor
    ]);
    // [LTS] ANCHOR: logs - finish
        lts_log_db('info', 'sync', [
            'result'  => 'finish',
            'message' => 'sync goods finish',
            'ctx'     => [
                'done'=>$done, 'created'=>$created, 'updated'=>$updated,
                'drafted'=>$drafted, 'elapsed_sec'=>$elapsed, 'last_after'=>$last_cursor
            ]
        ]);
    return [
        'ok'       => true,
        'done'     => $done,
        'created'  => $created,
        'updated'  => $updated,
        'drafted'  => $drafted,
        'after'    => $last_cursor,
        'elapsed'  => $elapsed,
        'last'     => $last_flag,
    ];
}