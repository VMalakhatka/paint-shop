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
 * - В конце прогона больше НЕ драфтим «устаревшие» товары (логика отключена).
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

// [LTS] ANCHOR: http POST helper (JSON)
if (!function_exists('lts_http_post_json')) {
    function lts_http_post_json(string $url, array $payload, array $headers = [], int $timeout = 120) {
        $args = [
            'timeout' => $timeout,
            'headers' => array_merge([
                'Accept'       => 'application/json',
                'Content-Type' => 'application/json; charset=utf-8',
                'User-Agent'   => LTS_USER_AGENT,
            ], $headers),
            'body'    => wp_json_encode($payload),
            'method'  => 'POST',
        ];
        $resp = wp_remote_post($url, $args);
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

// [LTS] ANCHOR: collect seen window (sku asc with stored hash)
if (!function_exists('lts_collect_seen_window')) {
    /**
     * Возвращает окно {sku, hash} из Woo, отсортированное по SKU (ASC), начиная с $after (исключительно).
     * @param int $limit
     * @param string|null $after
     * @return array<int,array{sku:string,hash:string}>
     */
    function lts_collect_seen_window(int $limit, ?string $after = null): array {
        global $wpdb;
        $limit = max(1, min(1000, $limit));
        // Лексикографическое сравнение по мета _sku.
        $afterCond = '';
        $params = [];
        if ($after !== null && $after !== '') {
            $afterCond = 'AND sku.meta_value > %s';
            $params[] = $after;
        }
        $sql = "
            SELECT sku.post_id, sku.meta_value AS sku,
                   COALESCE(h.meta_value, '') AS hash
            FROM {$wpdb->postmeta} sku
            JOIN {$wpdb->posts} p ON p.ID = sku.post_id AND p.post_type = 'product'
            LEFT JOIN {$wpdb->postmeta} h ON h.post_id = sku.post_id AND h.meta_key = '_ms_hash'
            WHERE sku.meta_key = '_sku'
              $afterCond
            ORDER BY sku.meta_value ASC
            LIMIT {$limit}
        ";
        $rows = $params ? $wpdb->get_results($wpdb->prepare($sql, ...$params), ARRAY_A)
                        : $wpdb->get_results($sql, ARRAY_A);
        $out = [];
        if ($rows) {
            foreach ($rows as $r) {
                $s = (string)$r['sku'];
                $h = isset($r['hash']) ? (string)$r['hash'] : '';
                if ($s !== '') $out[] = ['sku'=>$s, 'hash'=>$h];
            }
        }
        return $out;
    }
}

// [LTS] ANCHOR: draft by SKUs helper
if (!function_exists('lts_draft_products_by_skus')) {
    /**
     * Переводит найденные по SKU товары в статус draft.
     * Возвращает кол-во обновлённых постов.
     */
    function lts_draft_products_by_skus(array $skus): int {
        $skus = array_values(array_filter(array_map('strval', $skus)));
        if (!$skus) return 0;
        global $wpdb;
        // Найдём post_id по _sku
        $in = implode(',', array_fill(0, count($skus), '%s'));
        $sql = "
            SELECT DISTINCT pm.post_id
            FROM {$wpdb->postmeta} pm
            JOIN {$wpdb->posts} p ON p.ID = pm.post_id
            WHERE pm.meta_key = '_sku' AND pm.meta_value IN ($in)
              AND p.post_type = 'product'
        ";
        $ids = $wpdb->get_col($wpdb->prepare($sql, ...$skus));
        $cnt = 0;
        if ($ids) {
            foreach ($ids as $pid) {
                $pid = (int)$pid;
                $res = wp_update_post(['ID'=>$pid, 'post_status'=>'draft'], true);
                if (!is_wp_error($res)) $cnt++;
            }
        }
        return $cnt;
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

/**
 * Создаёт (если нужно) атрибут Woo и термин, и привязывает его к продукту.
 * $taxonomy_name — без префикса 'pa_' (например, 'edin_izmer').
 * Если уже существует — просто обновляем термы и делаем атрибут видимым.
 */
function lts_set_product_attribute_text(int $product_id, string $taxonomy_with_pa, string $term_value, bool $visible = true): void {
    if ($term_value === '') return;

    // Убедимся, что таксономия зарегистрирована (обычно Woo делает это на init).
    $tax = $taxonomy_with_pa; // ожидается с префиксом 'pa_'
    if (!taxonomy_exists($tax)) {
        // Если можем — создадим атрибут через API Woo.
        if (function_exists('wc_create_attribute')) {
            $base = preg_replace('/^pa_/', '', $tax);
            $attr_id = wc_attribute_taxonomy_id_by_name($base);
            if (!$attr_id) {
                $res = wc_create_attribute([
                    'name'         => $base,
                    'slug'         => $base,
                    'type'         => 'select',
                    'order_by'     => 'menu_order',
                    'has_archives' => false,
                ]);
                // wc_create_attribute вернёт wp_error либо id
                if (!is_wp_error($res)) {
                    $attr_id = (int)$res;
                }
            }
            // Попробуем принудительно зарегистрировать таксономию для немедленного использования
            if (!taxonomy_exists($tax)) {
                register_taxonomy($tax, 'product', [
                    'hierarchical' => false,
                    'label'        => ucfirst(str_replace(['pa_','_'],' ', $tax)),
                    'query_var'    => true,
                    'rewrite'      => false,
                    'show_ui'      => false,
                    'show_in_nav_menus' => false,
                    'show_admin_column' => false,
                ]);
            }
        } else {
            // Fallback: мягкая регистрация таксономии
            register_taxonomy($tax, 'product', [
                'hierarchical' => false,
                'label'        => ucfirst(str_replace(['pa_','_'],' ', $tax)),
                'query_var'    => true,
                'rewrite'      => false,
                'show_ui'      => false,
                'show_in_nav_menus' => false,
                'show_admin_column' => false,
            ]);
        }
    }

    // Создадим термин при необходимости
    $term = term_exists($term_value, $tax);
    if (!$term || is_wp_error($term)) {
        $term = wp_insert_term($term_value, $tax);
    }
    if (is_wp_error($term)) {
        return;
    }

    // Привяжем термин к товару
    wp_set_object_terms($product_id, [(int)$term['term_id']], $tax, false);

    // Обновим карточные атрибуты (_product_attributes), чтобы было видно на витрине
    $attrs = get_post_meta($product_id, '_product_attributes', true);
    if (!is_array($attrs)) $attrs = [];

    if (!isset($attrs[$tax]) || !is_array($attrs[$tax])) {
        $position = count($attrs);
        $attrs[$tax] = [
            'name'         => $tax,
            'value'        => '',
            'position'     => $position,
            'is_visible'   => $visible ? 1 : 0,
            'is_variation' => 0,
            'is_taxonomy'  => 1,
        ];
    } else {
        // гарантируем видимость
        $attrs[$tax]['is_visible'] = $visible ? 1 : 0;
        $attrs[$tax]['is_taxonomy'] = 1;
    }

    update_post_meta($product_id, '_product_attributes', $attrs);
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

    // Стандартные поля и согласованные мета:
    if (array_key_exists('globalUniqueId', $it)) {
        update_post_meta($pid, '_global_unique_id', (string)$it['globalUniqueId']);
    }

    // Единица измерения → атрибут pa_edin_izmer (видимый)
    if (array_key_exists('edinIzmer', $it)) {
        $edin = trim((string)$it['edinIzmer']);
        if ($edin !== '') {
            lts_set_product_attribute_text($pid, 'pa_edin_izmer', $edin, true);
        }
    }

    // ВЛИЯНИЕ СТАТУСА НА ПУБЛИКАЦИЮ/ВИДИМОСТЬ
    // status=1  → публикуем и делаем видимым (убрать exclude термы)
    // status=0  → публикуем, но скрываем из каталога и поиска (оставляем опубликованным)
    if (array_key_exists('status', $it)) {
        $statusInt = (int)$it['status'];
        // Термы видимости WooCommerce
        if (function_exists('wc_get_product_visibility_term_ids')) {
            $vis = wc_get_product_visibility_term_ids(); // ['exclude-from-catalog'=>id, 'exclude-from-search'=>id, ...]
            $excludeTerms = [];
            if (!empty($vis['exclude-from-catalog'])) $excludeTerms[] = (int)$vis['exclude-from-catalog'];
            if (!empty($vis['exclude-from-search']))  $excludeTerms[] = (int)$vis['exclude-from-search'];
            if ($statusInt === 1) {
                // Публикуем и делаем видимым
                wp_update_post(['ID' => $pid, 'post_status' => 'publish']);
                if ($excludeTerms) {
                    wp_remove_object_terms($pid, $excludeTerms, 'product_visibility');
                }
            } else {
                // Оставляем publish, но скрываем с витрины и из поиска
                wp_update_post(['ID' => $pid, 'post_status' => 'publish']);
                if ($excludeTerms) {
                    // Добавляем exclude-термы (не трогаем остальные)
                    wp_set_post_terms($pid, $excludeTerms, 'product_visibility', true);
                }
            }
        } else {
            // Fallback: только статус поста
            wp_update_post(['ID' => $pid, 'post_status' => ($statusInt === 1 ? 'publish' : 'publish')]);
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

    // Сохраняем контрольную сумму карточки (если пришла из diff)
    if (isset($it['hash'])) {
        update_post_meta($pid, '_ms_hash', (string)$it['hash']);
    }

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
    $draftStale = null; // логика «устаревших» отключена
    $maxSeconds = isset($args['max_seconds']) ? max(1, (int)$args['max_seconds']) : null;

    // Run-scoped logger: attach current run id (if provided) to every DB log entry.
    $runId = isset($args['run_id']) ? (string)$args['run_id'] : '';
    $log = function (string $level, string $tag, array $ctx = []) use ($runId) {
        if (!is_array($ctx)) {
            $ctx = [];
        }
        if ($runId !== '') {
            $ctx['run'] = $runId;
        }
        lts_log_db($level, $tag, $ctx);
    };

    // progress lines for live UI (worker polls level=progress, tag=progress)
    $progress = function (string $line) use ($runId) {
        lts_log_db('progress', 'progress', ['run' => $runId, 'line' => $line]);
    };

    // курсор к бэкенду отправляем ровно таким, как ввёл оператор
    $afterRaw = array_key_exists('after', $args) ? (string)$args['after'] : '';

    $progress(sprintf('Start: limit=%d, after="%s", dry=%s', $limit, $afterRaw, $dry ? 'true' : 'false'));


    // [LTS] ANCHOR: logs - start
    $log('info', 'sync', [
        'result'  => 'start',
        'message' => 'sync goods start',
        'ctx'     => ['limit'=>$limit, 'after'=>$after, 'max'=>$max, 'dry'=>$dry]
    ]);

    $done = 0;
    $created = 0;
    $updated = 0;
    $drafted_diff = 0;   // drafted by toDelete
    $last_cursor = $after;
    $last_flag = false;
    $started_at = time();

    // DIFF режим: используем сохранённый путь `path_sync` и дефолт /admin/export/card-tov/diff
    $endpointRaw = !empty($o['path_sync']) ? $o['path_sync'] : '/admin/export/card-tov/diff';
    $endpoint    = '/' . ltrim($endpointRaw, '/');
    $url         = rtrim($o['java_base_url'], '/') . $endpoint;

    do {
        // Собираем seen window (массив объектов {sku,hash})
        $seen = lts_collect_seen_window($limit, $last_cursor);

        // Бэкенд ожидает ROOT = JSON-МАССИВ (List<ItemHash>),
        // а параметры afterSku/limit — через query string.
        $qs = ['limit' => $limit];
        if ($afterRaw !== '') {
            $qs['after'] = $afterRaw; // строго то, что ввёл оператор (имя параметра на бэке — "after")
        }
        $reqUrl = $url . '?' . http_build_query($qs);

        $progress(sprintf('Request: after="%s", limit=%d, seen=%d', $afterRaw, $limit, count($seen)));

        // Логируем факт подготовки запроса
        $log('info', 'diff', [
            'result'  => 'request',
            'message' => 'diff request prepared',
            'ctx'     => [
                'after' => $afterRaw,
                'limit'    => $limit,
                'seen_cnt' => count($seen),
                'target'   => $reqUrl,
            ],
        ]);

        // Тело = только массив $seen, без обёртки объекта
        $headers = [];
        if (!empty($o['api_token'])) {
            $headers['Authorization'] = 'Bearer ' . $o['api_token'];
        }
        $j = lts_http_post_json($reqUrl, $seen, $headers, (int)$o['timeout']);
        if (empty($j['ok'])) {
            $log('error', 'fetch', ['error'=>$j['error'] ?? 'unknown', 'after'=>$last_cursor]);
            lts_log('fetch_page_error', ['error'=>$j['error'] ?? 'unknown', 'after'=>$last_cursor]);
            return ['ok'=>false, 'error'=>$j['error'] ?? 'fetch_failed', 'after'=>$last_cursor];
        }
        $data = $j['data'];
        $toDelete = is_array($data['toDelete'] ?? null) ? $data['toDelete'] : [];
        $toUpdateFull = is_array($data['toUpdateFull'] ?? null) ? $data['toUpdateFull'] : [];
        $toCreateFull = is_array($data['toCreateFull'] ?? null) ? $data['toCreateFull'] : [];
        $last_flag = !empty($data['last']);

        // Если всё пусто — завершить
        if (empty($toDelete) && empty($toUpdateFull) && empty($toCreateFull)) {
            break;
        }

        // Удаляем (draft) по toDelete
        if (!$dry && !empty($toDelete)) {
            $drafted_now = lts_draft_products_by_skus($toDelete);
            $drafted_diff += (int)$drafted_now;
            $progress(sprintf('Draft-by-diff: %d (skus: %s)', (int)$drafted_now, implode(',', array_slice($toDelete, 0, 5))));
            if ($drafted_now > 0) {
                $log('info', 'draft', [
                    'result'  => 'drafted_diff',
                    'message' => 'marked products as draft by diff',
                    'ctx'     => ['count'=>$drafted_now, 'skus'=>$toDelete]
                ]);
            }
        }

        // Обновляем/создаём по toUpdateFull и toCreateFull
        foreach ([['arr'=>$toUpdateFull,'type'=>'update'], ['arr'=>$toCreateFull,'type'=>'create']] as $batch) {
            foreach ($batch['arr'] as $it) {
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
                $progress('Upsert begin: '.$sku);
                try {
                    $res = lts_upsert_product_from_item($it, $o);
                    if (!empty($res['ok'])) {
                        $progress('Upsert ok: '.$sku.' -> post_id='.$res['post_id']);
                        $log('info', 'upsert', [
                            'sku'     => $sku,
                            'post_id' => $res['post_id'] ?? null,
                            'result'  => $pid_before ? 'updated' : 'created'
                        ]);
                        if ($pid_before) $updated++; else $created++;
                        $done++;
                    } else {
                        $progress('Upsert fail: '.$sku.' -> '.($res['error'] ?? 'unknown'));
                        $log('error', 'upsert', [
                            'sku'     => $sku,
                            'post_id' => $pid_before ?: null,
                            'result'  => 'failed',
                            'message' => $res['error'] ?? 'unknown'
                        ]);
                    }
                } catch (Throwable $e) {
                    $progress('Upsert exception: '.$sku.' -> '.$e->getMessage());
                    $log('error', 'upsert', [
                        'sku'     => $sku,
                        'post_id' => $pid_before ?: null,
                        'result'  => 'exception',
                        'message' => $e->getMessage()
                    ]);
                }
                $last_cursor = $sku;
                if ($max && $done >= $max) {
                    $last_flag = true;
                    break 2;
                }
            }
        }

        // nextAfter для курсора
        $last_cursor = isset($data['nextAfter']) ? (string)$data['nextAfter'] : $last_cursor;

        $progress(sprintf('Page end: nextAfter="%s", done=%d, created=%d, updated=%d', $last_cursor, $done, $created, $updated));

        // Keep product_cat counters consistent after each processed portion
        if (function_exists('lts_recount_all_product_cat_counts')) {
            lts_recount_all_product_cat_counts();
            $progress('Recount categories: done');
        }

        // небольшая щадящая пауза для уменьшения нагрузки
        usleep(100 * 1000); // 100ms

    } while (!$last_flag);


    $elapsed = time() - $started_at;
    lts_log('sync_done', [
        'done'=>$done, 'created'=>$created, 'updated'=>$updated,
        'drafted'=>$drafted_diff, 'elapsed_sec'=>$elapsed, 'last_after'=>$last_cursor
    ]);
    $progress(sprintf('Finish: done=%d, created=%d, updated=%d, drafted_diff=%d, after="%s"', $done, $created, $updated, $drafted_diff, $last_cursor));
    // [LTS] ANCHOR: logs - finish
    $log('info', 'sync', [
        'result'  => 'finish',
        'message' => 'sync goods finish',
        'ctx'     => [
            'done'=>$done, 'created'=>$created, 'updated'=>$updated,
            'drafted'=>$drafted_diff, 'elapsed_sec'=>$elapsed, 'last_after'=>$last_cursor
        ]
    ]);
    // Final safety recount to ensure consistency even if the session was interrupted earlier
    if (function_exists('lts_recount_all_product_cat_counts')) {
        lts_recount_all_product_cat_counts();
        $progress('Recount categories: final done');
    }
    return [
        'ok'             => true,
        'done'           => $done,
        'created'        => $created,
        'updated'        => $updated,
        // По ожиданию пользователя "drafted" = сколько задрафтили по списку toDelete
        'drafted'        => $drafted_diff,
        // Дополнительно возвращаем раздельно
        'drafted_diff'   => $drafted_diff,
        'after'          => $last_cursor,
        'elapsed'        => $elapsed,
        'last'           => $last_flag,
    ];
}