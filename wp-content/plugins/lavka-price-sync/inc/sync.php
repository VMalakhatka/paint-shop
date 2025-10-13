<?php
// inc/sync.php
if (!defined('ABSPATH')) exit;

/** Обновить цены: items = [{sku, price}] для роли $roleSlug */
function lps_apply_prices(array $items, string $roleSlug, bool $dry = false): array {
    $meta_key = lps_role_meta_key($roleSlug);
    $updated = 0; $notFound = 0; $details = [];

    foreach ($items as $row) {
        $sku   = (string)($row['sku'] ?? '');
        if ($sku==='') continue;
        $price = (float)($row['price'] ?? 0);

        $pid = lps_find_product_id_by_sku($sku);
        if (!$pid) {
            $notFound++;
            $details[] = ['sku'=>$sku, 'found'=>false];
            continue;
        }
        if (!$dry) {
            update_post_meta($pid, $meta_key, $price);
        }
        $updated++;
        $details[] = ['sku'=>$sku, 'found'=>true, 'price'=>$price];
    }
    return ['updated'=>$updated, 'not_found'=>$notFound, 'items'=>$details];
}

/** Сходить в Java за ценами для контракта + sku[] */
function lps_java_fetch_prices(string $contractCode, array $skus): array {
    $o = lps_get_options();
    $body = [
        'contractCode' => $contractCode,
        'skus'         => array_values(array_filter(array_map('strval', $skus))),
    ];
    $resp = lps_java_post($o['path_prices'], wp_json_encode($body));
    if (is_wp_error($resp)) {
        return ['ok'=>false, 'error'=>$resp->get_error_message()];
    }
    $code = wp_remote_retrieve_response_code($resp);
    $ct   = strtolower((string)wp_remote_retrieve_header($resp,'content-type'));
    if ($code < 200 || $code >= 300) {
        $text = wp_remote_retrieve_body($resp);
        // попытка распарсить JSON-ошибку
        $j = (stripos($ct,'json')!==false) ? json_decode($text, true) : null;
        return ['ok'=>false, 'error'=>"HTTP $code", 'body'=>$j ?: substr($text,0,400)];
    }
    $data = (stripos($ct,'json')!==false) ? json_decode(wp_remote_retrieve_body($resp), true) : [];
    $items = [];
    $src = (isset($data['items']) && is_array($data['items'])) ? $data['items'] : (is_array($data) ? $data : []);
    foreach ($src as $x) {
        $sku   = (string)($x['sku'] ?? '');
        if ($sku==='') continue;
        $price = (float)($x['price'] ?? 0);
        $items[] = ['sku'=>$sku, 'price'=>$price];
    }
    return ['ok'=>true, 'items'=>$items];
}

/** AJAX: ручной запуск по переданным SKU */
add_action('wp_ajax_lps_run_prices', function () {
    if (!current_user_can(LPS_CAP)) wp_send_json_error(['error'=>'forbidden'], 403);
    check_ajax_referer('lps_admin_nonce');

    $role = sanitize_key($_POST['role'] ?? '');
    $dry  = !empty($_POST['dry']);
    $map  = lps_get_role_contract_map();
    $contract = (string)($map[$role] ?? '');
    if ($role==='' || $contract==='') {
        wp_send_json_error(['error'=>'role_or_contract_missing']);
    }

    // получить список skus
    $skus = [];
    if (isset($_POST['skus']) && is_array($_POST['skus'])) {
        $skus = array_map('strval', wp_unslash($_POST['skus']));
    } elseif (isset($_POST['skus'])) {
        $raw = (string)wp_unslash($_POST['skus']);
        $skus = preg_split('/[\s,;]+/u', $raw, -1, PREG_SPLIT_NO_EMPTY);
    }
    $skus = array_values(array_unique(array_filter(array_map('trim', $skus))));
    if (!$skus) wp_send_json_error(['error'=>'empty_skus']);

    $j = lps_java_fetch_prices($contract, $skus);
    if (empty($j['ok'])) wp_send_json_error(['error'=>$j['error'] ?? 'java_error', 'body'=>$j['body'] ?? null]);

    $applied = lps_apply_prices($j['items'], $role, $dry);
    wp_send_json_success([
        'ok'=>true,
        'dry'=>$dry,
        'updated'=>$applied['updated'],
        'not_found'=>$applied['not_found'],
        'results'=>$applied['items'],
    ]);
});

/** AJAX: прогон всех SKU (постранично) для выбранной роли */
add_action('wp_ajax_lps_run_prices_all_page', function () {
    if (!current_user_can(LPS_CAP)) wp_send_json_error(['error'=>'forbidden'], 403);
    check_ajax_referer('lps_admin_nonce');

    $role  = sanitize_key($_POST['role'] ?? '');
    $page  = max(0, (int)($_POST['page'] ?? 0));
    $batch = max(LPS_MIN_BATCH, min(LPS_MAX_BATCH, (int)($_POST['batch'] ?? LPS_DEF_BATCH)));
    $dry   = !empty($_POST['dry']);

    $map = lps_get_role_contract_map();
    $contract = (string)($map[$role] ?? '');
    if ($role==='' || $contract==='') wp_send_json_error(['error'=>'role_or_contract_missing']);

    $total = lps_count_all_skus();
    $pages = (int) ceil(max(1,$total) / $batch);
    if ($total<=0 || $page >= $pages) {
        wp_send_json_success([
            'page'=>$page, 'pages'=>$pages, 'total'=>$total,
            'updated'=>0, 'not_found'=>0, 'dry'=>$dry, 'sample'=>[]
        ]);
    }

    $skus = lps_get_skus_slice($page*$batch, $batch);
    $j = lps_java_fetch_prices($contract, $skus);
    if (empty($j['ok'])) wp_send_json_error(['error'=>$j['error'] ?? 'java_error', 'body'=>$j['body'] ?? null]);

    $applied = lps_apply_prices($j['items'], $role, $dry);

    // отдаём только сэмпл из 20 строк
    $sample = array_slice($applied['items'], 0, 20);

    wp_send_json_success([
        'page'=>$page, 'pages'=>$pages, 'total'=>$total,
        'updated'=>$applied['updated'], 'not_found'=>$applied['not_found'],
        'dry'=>$dry, 'sample'=>$sample,
    ]);
});

/** Крон: если включен — перебираем все роли из маппинга */
add_action(LPS_CRON_HOOK, function () {
    $o = lps_get_options();
    if (empty($o['cron_enabled'])) return;

    $map = lps_get_role_contract_map();
    if (!$map) return;

    $batch = max(LPS_MIN_BATCH, min(LPS_MAX_BATCH, (int)$o['batch']));
    $total = lps_count_all_skus();
    $pages = (int) ceil(max(1,$total) / $batch);

    foreach ($map as $role => $contract) {
        for ($p=0; $p<$pages; $p++) {
            $skus = lps_get_skus_slice($p*$batch, $batch);
            if (!$skus) continue;
            $j = lps_java_fetch_prices($contract, $skus);
            if (empty($j['ok'])) break; // можно залогировать и продолжить
            lps_apply_prices($j['items'], $role, false);
        }
    }
});