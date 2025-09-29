    <?php
    if (!defined('ABSPATH')) exit;

    /**
     * Тянет агрегированные остатки из Java и применяет их к товарам в Woo.
     * Ожидаемый ответ Java:
     *   { items: [ { sku: "...", stocks: [ {location_slug:"odesa", qty:12}, ... ] }, ... ] }
     */
    function lavka_sync_pull_from_java_and_apply( array $opts = [] ): array {
        $o     = lavka_sync_get_options();
        $base  = rtrim($o['java_base_url'] ?? '', '/');
        $path  = '/' . ltrim($o['java_stock_query_path'] ?? '/admin/stock/stock/query', '/');
        $url   = $base . $path;

        // dry/флаги: можно передать в $opts, есть дефолты
        $flags = [
            'dry'                 => false,
            'set_manage'          => true,
            'upd_status'          => true,
            'attach_terms'        => true,
            'set_primary'         => true,
            'duplicate_slug_meta' => false,
        ];
        $flags = array_merge($flags, array_intersect_key($opts, $flags));

        // тянем из Java
        $resp = wp_remote_get($url, [
            'timeout' => 30,
            'headers' => [
                'X-Auth-Token' => $o['api_token'] ?? '',
                'Accept'       => 'application/json',
            ],
        ]);
        if (is_wp_error($resp)) {
            return ['ok'=>false,'error'=>$resp->get_error_message()];
        }
        $code = wp_remote_retrieve_response_code($resp);
        $body = json_decode(wp_remote_retrieve_body($resp), true);
        if ($code < 200 || $code >= 300) {
            return ['ok'=>false,'error'=>"Java status $code",'body'=>$body];
        }

        $items = (array)($body['items'] ?? (is_array($body) ? $body : []));
        if (!$items) return ['ok'=>true,'dry'=>$flags['dry'],'processed'=>0,'not_found'=>0,'results'=>[]];

        // применяем к Woo — используем уже существующий низкоуровневый писатель
        $results   = [];
        $notFound  = 0;
        $processed = 0;

        foreach ($items as $it) {
            $sku    = (string)($it['sku'] ?? '');
            $stocks = (array)($it['stocks'] ?? []);
            if ($sku === '' || !$stocks) continue;

            $res = lavka_write_stock_for_sku($sku, $stocks, $flags);
            $results[] = $res;
            $processed++;
            if (empty($res['found'])) $notFound++;
        }

        // лог по желанию
        lavka_log_write([
            'action'      => 'sync_pull_java',
            'supplier'    => $o['supplier'] ?? '',
            'stock_id'    => (int)($o['stock_id'] ?? 0),
            'dry'         => $flags['dry'],
            'updated'     => $processed - $notFound,
            'not_found'   => $notFound,
            'duration_ms' => 0, // можно обернуть в таймер, если нужно
            'status'      => 'OK',
            'message'     => 'pull from '.$url,
        ]);

        return [
            'ok'        => true,
            'dry'       => (bool)$flags['dry'],
            'processed' => $processed,
            'not_found' => $notFound,
            'results'   => $results,
        ];
    }