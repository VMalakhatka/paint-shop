<?php
if (!defined('ABSPATH')) exit;

function lps_lock_error_response(array $lock): void {
    wp_send_json_error([
        'error'   => 'lavka_sync_locked',
        'message' => $lock['message'] ?? __('Synchronization is already running. Please try again later.', 'lavka-price-sync'),
        'lock'    => $lock['lock'] ?? null,
    ], 409);
}

/**
 * Построить priceMap из сохранённого маппинга ролей.
 * Возвращает массив вида [ roleSlug => contractName ]
 */
function lps_build_price_map_from_option(): array {
    $map = get_option(LPS_OPT_MAPPING, []);
    if (!is_array($map)) return [];
    // в твой эндпоинт идут «человеческие» названия контрактов (NAME_KONTR),
    // мы их и сохраняем в маппинг, так что просто отдаем как есть.
    $clean = [];
    foreach ($map as $role => $contractName) {
        $role = sanitize_key($role);
        $contractName = (string)$contractName;
        if ($role !== '' && $contractName !== '') $clean[$role] = $contractName;
    }
    return $clean;
}

/**
 * Сходить в Java за ценами сразу для множества ролей и розницы
 * Тело запроса: { skus: [...], priceMap: { role => "NAME_KONTR" } }
 * Ответ: { items: [ { sku, price, prices: { role => value } }, ... ] }
 */
function lps_java_fetch_prices_multi(array $skus): array {
    $o = lps_get_options();
    $priceMap = lps_build_price_map_from_option();
    if (!$priceMap) {
        return ['ok'=>false, 'error'=>'empty_price_map'];
    }

    $payload = [
        'skus'     => array_values(array_filter(array_map('strval', $skus))),
        'priceMap' => $priceMap,
    ];

    $resp = lps_java_post($o['path_prices'], $payload);
    if (is_wp_error($resp)) {
        return ['ok'=>false, 'error'=>$resp->get_error_message()];
    }
    $code = wp_remote_retrieve_response_code($resp);
    $ct   = strtolower((string)wp_remote_retrieve_header($resp,'content-type'));
    $raw  = wp_remote_retrieve_body($resp);

    if ($code < 200 || $code >= 300) {
        $j = (stripos($ct,'json')!==false) ? json_decode($raw, true) : null;
        return ['ok'=>false, 'error'=>"HTTP $code", 'body'=>$j ?: substr($raw,0,400)];
    }

    $data = (stripos($ct,'json')!==false) ? json_decode($raw, true) : [];
    $items = is_array($data['items'] ?? null) ? $data['items'] : [];

    // нормализация
    $out = [];
    foreach ($items as $x) {
        $sku = (string)($x['sku'] ?? '');
        if ($sku==='') continue;
        $retail = isset($x['price']) ? (float)$x['price'] : null;
        $roles  = is_array($x['prices'] ?? null) ? $x['prices'] : [];
        $cleanRoles = [];
        foreach ($roles as $role => $val) {
            $cleanRoles[sanitize_key((string)$role)] = (float)$val;
        }
        $out[] = [
            'sku'    => $sku,
            'retail' => $retail,
            'roles'  => $cleanRoles,
        ];
    }

    return ['ok'=>true, 'items'=>$out];
}

/**
 * Применить цены к товарам:
 *  - розничная: обновляем _regular_price и _price
 *  - ролевые:   _wpc_price_role_<role>
 */
function lps_apply_prices_multi(array $items, bool $dry = false): array {
    $updatedRetail = 0;
    $updatedRoles  = 0;
    $notFound      = 0;
    $details       = [];

    foreach ($items as $row) {
        $sku = (string)($row['sku'] ?? '');
        if ($sku==='') continue;

        $pid = lps_find_product_id_by_sku($sku);
        if (!$pid) {
            $notFound++;
            $details[] = ['sku'=>$sku, 'found'=>false];
            continue;
        }

        $change = ['retail'=>false, 'roles'=>[]];

        // Розничная
        if (isset($row['retail'])) {
            $ret = (float)$row['retail'];
            if (!$dry) {
                update_post_meta($pid, '_regular_price', $ret);
                update_post_meta($pid, '_price',         $ret);
            }
            $updatedRetail++;
            $change['retail'] = $ret;
        }

       // Ролевые
        $roles = is_array($row['roles'] ?? null) ? $row['roles'] : [];
        foreach ($roles as $role => $price) {
            $meta_key = lps_role_meta_key($role);
            if (!$dry) {
                // ===== transient-замок на post_id+meta_key (защита от параллельной записи) =====
                $lock_key = 'lps_lock_' . md5($pid . '|' . $meta_key);
                // если уже кто-то пишет — пропустим/подождём кратко (здесь просто пропуск)
                if (get_transient($lock_key)) {
                    // Можно: sleep(1) и попробовать ещё раз, но обычно хватит пропуска.
                    continue;
                }
                set_transient($lock_key, 1, 10); // TTL 10 сек достаточно для одной записи

                // гарантированно одинарная запись:
                // 1) если запись есть — обновит; 2) если нет — создаст ровно одну
                update_post_meta($pid, $meta_key, (float)$price);

                // снимаем замок
                delete_transient($lock_key);
                // ===== конец замка =====
            }
            $updatedRoles++;
            $change['roles'][] = ['role'=>$role, 'price'=>(float)$price];
        }
        $details[] = ['sku'=>$sku, 'found'=>true] + $change;
    }

    return [
        'updated_retail' => $updatedRetail,
        'updated_roles'  => $updatedRoles,
        'not_found'      => $notFound,
        'items'          => $details,
    ];
}

/**
 * AJAX: ручной запуск по списку SKU (мульти-ролевая синхронизация)
 */
add_action('wp_ajax_lps_run_prices', function () {
    if (!current_user_can(LPS_CAP)) wp_send_json_error(['error'=>'forbidden'], 403);
    check_ajax_referer('lps_sync_skus');

    // читаем SKUs
    $skus = [];
    if (isset($_POST['skus']) && is_array($_POST['skus'])) {
        $skus = array_map('strval', wp_unslash($_POST['skus']));
    } elseif (isset($_POST['skus'])) {
        $raw  = (string)wp_unslash($_POST['skus']);
        if (function_exists('lps_parse_sku_list')) {
            $skus = lps_parse_sku_list($raw);
        } else {
            // fallback: только , ; переводы строк и | — пробелы внутри SKU сохраняем
            $raw  = str_replace(["\r\n","\n","\r",";","|"], ',', $raw);
            $skus = array_values(array_filter(array_map('trim', explode(',', $raw))));
        }
    }
    if (!$skus) wp_send_json_error(['error'=>'empty_skus']);

    $dry = !empty($_POST['dry']);

    $j = lps_java_fetch_prices_multi($skus);
    if (empty($j['ok'])) wp_send_json_error(['error'=>$j['error'] ?? 'java_error', 'body'=>$j['body'] ?? null]);

    $applied = lps_apply_prices_multi($j['items'], $dry);

    wp_send_json_success([
        'ok'=>true,
        'dry'=>$dry,
        'updated_retail'=>$applied['updated_retail'],
        'updated_roles' =>$applied['updated_roles'],
        'not_found'     =>$applied['not_found'],
        // отдаем маленький сэмпл
        'results'       => array_slice($applied['items'], 0, 20),
    ]);
});

/**
 * AJAX: прогон ВСЕХ (постранично) — мульти-ролевой
 */
add_action('wp_ajax_lps_run_prices_all_page', function () {
    if (!current_user_can(LPS_CAP)) wp_send_json_error(['error'=>'forbidden'], 403);
    check_ajax_referer('lps_sync_all');

    $page  = max(0, (int)($_POST['page'] ?? 0));
    $batch = max(LPS_MIN_BATCH, min(LPS_MAX_BATCH, (int)($_POST['batch'] ?? LPS_DEF_BATCH)));
    $dry   = !empty($_POST['dry']);
    $lock_token = isset($_POST['lock_token']) ? sanitize_text_field(wp_unslash($_POST['lock_token'])) : '';
    $owns_lock = false;

    if (function_exists('lavka_ecosystem_lock_acquire') && function_exists('lavka_ecosystem_lock_get')) {
        if ($page === 0 || $lock_token === '') {
            $lock = lavka_ecosystem_lock_acquire(
                'lavka-price-sync',
                'price_sync_all_ajax',
                'manual',
                __('Manual paged price synchronization', 'lavka-price-sync'),
                2 * HOUR_IN_SECONDS
            );
            if (empty($lock['ok'])) {
                lps_lock_error_response($lock);
            }
            $lock_token = (string)($lock['token'] ?? '');
            $owns_lock = true;
        } else {
            $current_lock = lavka_ecosystem_lock_get();
            $owns_lock = $current_lock
                && isset($current_lock['token'])
                && hash_equals((string)$current_lock['token'], $lock_token);

            if (!$owns_lock) {
                lps_lock_error_response([
                    'lock' => $current_lock,
                    'message' => __('Manual paged price synchronization lock is missing. Please restart the process.', 'lavka-price-sync'),
                ]);
            }
        }
    }

    $total = lps_count_all_skus();
    $pages = (int) ceil(max(1,$total) / $batch);
    if ($total<=0 || $page >= $pages) {
        if ($owns_lock && function_exists('lavka_ecosystem_lock_release')) {
            lavka_ecosystem_lock_release($lock_token);
        }
        wp_send_json_success([
            'page'=>$page, 'pages'=>$pages, 'total'=>$total,
            'updated_retail'=>0, 'updated_roles'=>0, 'not_found'=>0,
            'dry'=>$dry, 'sample'=>[], 'lock_token'=>null
        ]);
    }

    $skus = lps_get_skus_slice($page*$batch, $batch);
    $j = lps_java_fetch_prices_multi($skus);
    if (empty($j['ok'])) {
        if ($owns_lock && function_exists('lavka_ecosystem_lock_release')) {
            lavka_ecosystem_lock_release($lock_token);
        }
        wp_send_json_error(['error'=>$j['error'] ?? 'java_error', 'body'=>$j['body'] ?? null]);
    }

    $applied = lps_apply_prices_multi($j['items'], $dry);
    $next_page = $page + 1;
    $done = $next_page >= $pages;

    if ($done && $owns_lock && function_exists('lavka_ecosystem_lock_release')) {
        lavka_ecosystem_lock_release($lock_token);
    }

    wp_send_json_success([
        'page'=>$page, 'pages'=>$pages, 'total'=>$total,
        'updated_retail'=>$applied['updated_retail'],
        'updated_roles' =>$applied['updated_roles'],
        'not_found'     =>$applied['not_found'],
        'dry'=>$dry,
        'sample'=>array_slice($applied['items'], 0, 20),
        'lock_token'=>$done ? null : $lock_token,
    ]);
});

add_action('wp_ajax_lps_run_prices_listed', function () {
    if (!current_user_can(LPS_CAP)) wp_send_json_error(['error'=>'forbidden'], 403);
    check_ajax_referer('lps_sync_skus'); // тот же nonce, что в кнопке

    // принимаем массив skus[] или строку skus
    $skus = [];
    if (isset($_POST['skus']) && is_array($_POST['skus'])) {
        $skus = array_values(array_filter(array_map('trim', array_map('strval', wp_unslash($_POST['skus'])))));
    } else {
        $raw  = (string)($_POST['skus'] ?? '');
        $skus = lps_parse_sku_list($raw);
    }

    if (!$skus) {
        wp_send_json_success(['items'=>[], 'updated_retail'=>0, 'updated_roles'=>0, 'not_found'=>0]);
    }

    $lock_token = null;
    if (function_exists('lavka_ecosystem_lock_acquire')) {
        $lock = lavka_ecosystem_lock_acquire(
            'lavka-price-sync',
            'price_sync_listed_ajax',
            'manual',
            __('Manual listed price synchronization', 'lavka-price-sync'),
            30 * MINUTE_IN_SECONDS
        );
        if (empty($lock['ok'])) {
            lps_lock_error_response($lock);
        }
        $lock_token = $lock['token'] ?? null;
    }

    $j = lps_java_fetch_prices_multi($skus);
    if (empty($j['ok'])) {
        if ($lock_token && function_exists('lavka_ecosystem_lock_release')) {
            lavka_ecosystem_lock_release($lock_token);
        }
        wp_send_json_error(['error'=>$j['error'] ?? 'java_error', 'body'=>$j['body'] ?? null]);
    }

    $applied = lps_apply_prices_multi($j['items'], /*dry*/ false);
    if ($lock_token && function_exists('lavka_ecosystem_lock_release')) {
        lavka_ecosystem_lock_release($lock_token);
    }

    wp_send_json_success([
        'updated_retail'=>$applied['updated_retail'],
        'updated_roles' =>$applied['updated_roles'],
        'not_found'     =>$applied['not_found'],
        'items'         =>$applied['items'],
    ]);
});
