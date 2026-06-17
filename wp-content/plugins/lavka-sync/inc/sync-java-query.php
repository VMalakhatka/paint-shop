<?php
if (!defined('ABSPATH')) exit;

/**
 * Читает мэппинг из WP-опции и формирует payload для Java:
 *   { skus: [...], locations: [{id: <term_id>, codes: [..]}, ...] }
 * Затем POST к /admin/stock/stock/query и применение ответа к Woo.
 */
function lavka_sync_java_query_and_apply(array $skus, array $opts = []): array {
    $o = lavka_sync_get_options();

    // 1) Нормализуем SKU
    $skus = array_values(array_filter(array_unique(array_map(function($s){
        $s = trim((string)$s);
        return $s !== '' ? $s : null;
    }, $skus))));

    if (!$skus) {
        return ['ok'=>false, 'error'=>'Empty SKUs list'];
    }

// берём мэппинг и ГОТОВИМ locations
    $mapItems  = lavka_map_collect();
    $locations = [];
    foreach ($mapItems as $row) {
        $tid   = (int)($row['term_id'] ?? 0);
        $codes = array_values(array_filter(
            array_map('strval', (array)($row['codes'] ?? [])),
            fn($s)=> $s !== ''
        ));
        if ($tid && $codes) {
            $locations[] = ['id' => $tid, 'codes' => $codes]; // ВАЖНО
        }
    }
    if (!$locations) {
        return ['ok'=>false, 'error'=>'No locations mapping available'];
    }

    // 3) Флаги записи
    $flags = [
        'dry'                 => false,
        'set_manage'          => true,
        'upd_status'          => true,
        'attach_terms'        => true,
        'set_primary'         => true,
        'duplicate_slug_meta' => false,
    ];
    $flags = array_merge($flags, array_intersect_key($opts, $flags));

    // 4) Запрос к Java
    $base = rtrim($o['java_base_url'] ?? '', '/');
    $path = '/' . ltrim($o['java_stock_query_path'] ?? '/admin/stock/stock/query', '/');
    $url  = $base . $path;

    $payload = [
        'skus'      => $skus,
        'locations' => $locations,
    ];
    if (defined('WP_DEBUG') && WP_DEBUG) error_log('[lavka] stock query payload: '.wp_json_encode($payload));
    file_put_contents(

        WP_CONTENT_DIR.'/lavka-debug.log',

        date('Y-m-d H:i:s')." HTTP START\n",

        FILE_APPEND

    );
    $resp = wp_remote_post($url, [
        'timeout' => 160,
        'headers' => [
            'X-Auth-Token'  => $o['api_token'] ?? '',
            'Accept'        => 'application/json',
            'Content-Type'  => 'application/json',
        ],
        'body' => wp_json_encode($payload),
    ]);
    file_put_contents(

        WP_CONTENT_DIR.'/lavka-debug.log',

        date('Y-m-d H:i:s')." HTTP FINISH\n",

        FILE_APPEND

    );
    if (is_wp_error($resp)) {
        return ['ok'=>false, 'error'=>$resp->get_error_message()];
    }
    $code = wp_remote_retrieve_response_code($resp);
    file_put_contents(

        WP_CONTENT_DIR.'/lavka-debug.log',

        date('Y-m-d H:i:s')." HTTP CODE=".$code."\n",

        FILE_APPEND

    );
    $rawBody = wp_remote_retrieve_body($resp);

    file_put_contents(
        WP_CONTENT_DIR.'/lavka-debug.log',
        date('Y-m-d H:i:s')
        ." BODY LEN=".strlen($rawBody)."\n",
        FILE_APPEND
    );

    file_put_contents(
        WP_CONTENT_DIR.'/lavka-debug.log',
        date('Y-m-d H:i:s')." BEFORE JSON\n",
        FILE_APPEND
    );

    $body = json_decode($rawBody, true);

    file_put_contents(
        WP_CONTENT_DIR.'/lavka-debug.log',
        date('Y-m-d H:i:s')." AFTER JSON\n",
        FILE_APPEND
    );
    
    file_put_contents(
        WP_CONTENT_DIR.'/lavka-debug.log',
        date('Y-m-d H:i:s')
        ." JSON OK=".(json_last_error() === JSON_ERROR_NONE ? 'YES' : 'NO')
        ."\n",
        FILE_APPEND
    );

    file_put_contents(
        WP_CONTENT_DIR.'/lavka-debug.log',
        date('Y-m-d H:i:s')
        ." JSON ERROR=".json_last_error_msg()
        ."\n",
        FILE_APPEND
    );

    file_put_contents(
        WP_CONTENT_DIR.'/lavka-debug.log',
        date('Y-m-d H:i:s')
        ." ITEMS=".count($body['items'] ?? [])
        ."\n",
        FILE_APPEND
    );

    if ($code < 200 || $code >= 300) {
        return ['ok'=>false, 'error'=>"Java status $code", 'body'=>$body];
    }

    // 5) Применяем к Woo
    $items = (array)($body['items'] ?? []);
    $results = [];
    $notFound = 0;
   foreach ($items as $it) {

        $sku   = (string)($it['sku'] ?? '');
        $lines = [];

        foreach ((array)($it['lines'] ?? []) as $ln) {
            $lines[] = [
                'term_id' => (int)($ln['id'] ?? 0),
                'qty'     => (float)($ln['qty'] ?? 0)
            ];
        }

        if ($sku && $lines) {

            file_put_contents(
                WP_CONTENT_DIR . '/lavka-debug.log',
                date('Y-m-d H:i:s')
                . " WRITE SKU={$sku} LINES=" . count($lines) . "\n",
                FILE_APPEND
            );

            $res = lavka_write_stock_for_sku(
                $sku,
                $lines,
                $flags
            );

            file_put_contents(
                WP_CONTENT_DIR . '/lavka-debug.log',
                date('Y-m-d H:i:s')
                . " WRITE DONE {$sku}\n",
                FILE_APPEND
            );

            $results[] = $res;

            if (empty($res['found'])) {
                $notFound++;
            }
        }
    }

    return [
        'ok'        => true,
        'dry'       => (bool)$flags['dry'],
        'processed' => count($results),
        'not_found' => $notFound,
        'results'   => $results,
    ];
}