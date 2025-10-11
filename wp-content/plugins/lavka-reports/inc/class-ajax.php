<?php
if (!defined('ABSPATH')) exit;

class Lavka_Reports_Ajax {
    private function log($label, $data=null){
        if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
            error_log('[LAVR] '.$label.(isset($data)?' '.wp_json_encode($data):''));
        }
    }
    public function __construct(){
        add_action('wp_ajax_lavr_ref_warehouses', [$this,'ref_warehouses']);
        add_action('wp_ajax_lavka_reports_ref_ops', [$this, 'ajax_ref_ops']);
        add_action('wp_ajax_lavr_no_movement', [$this,'no_movement']);
        add_action('wp_ajax_lavr_dbg_ping', [$this,'dbg_ping']);
    }
    private function check(){
        $this->log('check() entry', $_POST);
        if (!current_user_can('manage_woocommerce')) wp_send_json_error(['err'=>'forbidden'],403);
        check_ajax_referer('lavka_reports_nonce','nonce');
    }

    public function ref_warehouses(){
        $this->check();

        // читаем базовый URL и токен из Lavka Sync (как источник настроек)
        $sync_opts = function_exists('lavka_sync_get_options') ? lavka_sync_get_options() : get_option('lavka_sync_options', []);
        $base  = rtrim((string)($sync_opts['java_base_url'] ?? ''), '/');
        $token = (string)($sync_opts['api_token'] ?? '');

        if (!$base) {
            wp_send_json_error(['err' => 'java_base_url_not_set']);
        }

        $endpoint = $base . '/ref/warehouses';
        $resp = wp_remote_get($endpoint, [
            'timeout' => 60,
            'headers' => array_filter([
                'Accept'       => 'application/json',
                'X-Auth-Token' => $token ?: null,
            ]),
        ]);

        if (is_wp_error($resp)) wp_send_json_error(['err'=>$resp->get_error_message()],500);
        $code = (int)wp_remote_retrieve_response_code($resp);
        if ($code !== 200)      wp_send_json_error(['err'=>'HTTP '.$code], $code);

        $body  = json_decode(wp_remote_retrieve_body($resp), true);
        $rows  = is_array($body) ? $body : [];
        $items = [];
        foreach ($rows as $r) {
            $wCode = isset($r['code']) ? (string)$r['code'] : '';
            if ($wCode === '') continue;
            $items[] = [
                'id'   => $wCode,                         // код MS-склада — наш id
                'name' => (string)($r['name'] ?? $wCode), // подпись
            ];
        }
        wp_send_json_success(['items'=>$items]);
    }
    public function ajax_ref_ops() {
    $this->check(); // проверка прав + nonce (lavka_reports_nonce)

    // Берём Java base и токен из Lavka Sync (read-only)
    $sync_opts = function_exists('lavka_sync_get_options')
        ? lavka_sync_get_options()
        : get_option('lavka_sync_options', []);

    $base  = rtrim((string)($sync_opts['java_base_url'] ?? ''), '/');
    $token = (string)($sync_opts['api_token'] ?? '');

    if (!$base) {
        wp_send_json_error(['err' => 'java_base_url_not_set']);
    }

    $endpoint = $base . '/ref/op-types';

    $resp = wp_remote_get($endpoint, [
        'timeout' => 60,
        'headers' => array_filter([
            'Accept'       => 'application/json',
            'X-Auth-Token' => $token ?: null,
        ]),
    ]);

    if (is_wp_error($resp)) {
        wp_send_json_error(['err' => $resp->get_error_message()], 500);
    }
    $code = (int) wp_remote_retrieve_response_code($resp);
    if ($code !== 200) {
        wp_send_json_error(['err' => 'HTTP ' . $code], $code);
    }

    $body = json_decode(wp_remote_retrieve_body($resp), true);
    $rows = is_array($body) ? $body : [];

    $out = [];
    foreach ($rows as $row) {
        if (!is_array($row)) continue;
        if ((int)($row['PLANIR'] ?? 0) !== 1) continue; // берём только PLANIR=1

        $val = (string)($row['SIGNIFIC'] ?? '');
        // чистим \r\n и сдваивания пробелов
        $val = trim(preg_replace('/\s+/', ' ', str_replace(["\r", "\n"], ' ', $val)));
        if ($val === '') continue;

        // и код, и имя — это SIGNIFIC
        $out[] = ['code' => $val, 'name' => $val];
    }

    wp_send_json_success(['items' => $out]);
}
    public function no_movement(){
        $this->log('no_movement HIT', $_POST);
        $this->check(); // nonce + права

        $from = sanitize_text_field($_POST['from'] ?? '');
        $to   = sanitize_text_field($_POST['to'] ?? '');
        $ids  = array_map('strval', (array)($_POST['warehouses'] ?? [])); // коды MSSQL как строки (D01, D02, …)
        $ops  = array_values(array_unique(array_filter(array_map('strval', (array)($_POST['ops'] ?? [])))));
        // fallback: если с фронта пусто — берём из сохранённых опций
        if (!$ops) {
            $opt = get_option(Lavka_Reports_Admin::OPT, []);
            foreach ((array)($opt['ops_exclude'] ?? []) as $o) {
                $v = isset($o['code']) ? (string)$o['code'] : '';
                if ($v !== '') $ops[] = $v;
            }
            $ops = array_values(array_unique($ops));
            if (method_exists($this, 'log')) $this->log('no_movement fallback ops(from options)', $ops);
        }

        // ISO в UTC
        $fromIso = $from ? gmdate('Y-m-d\T00:00:00\Z', strtotime($from)) : null;
        $toIso   = $to   ? gmdate('Y-m-d\T23:59:59\Z', strtotime($to))   : null;

        // Java base + token из Lavka Sync
        $sync  = function_exists('lavka_sync_get_options') ? lavka_sync_get_options() : get_option('lavka_sync_options', []);
        $base  = rtrim((string)($sync['java_base_url'] ?? ''), '/');
        $token = (string)($sync['api_token'] ?? '');
        if (!$base) wp_send_json_error(['err'=>'java_base_url_not_set']);

        // $ids сейчас = массив кодов MSSQL (строки) из UI
        $codes = array_values(array_unique(array_filter(array_map('strval', (array)$ids))));
        $this->log('no_movement codes', $codes);
        if (!$codes) {
            // ФОЛБЭК: берём сохранённые склады из опции, если фронт не прислал
            $opt = get_option(Lavka_Reports_Admin::OPT, []);
            $saved = [];
            foreach ((array)($opt['warehouses'] ?? []) as $w) {
                $v = isset($w['id']) ? (string)$w['id'] : '';
                if ($v !== '') $saved[$v] = true;
            }
            $codes = array_values(array_keys($saved));
            $this->log('no_movement fallback codes(from options)', $codes);
            if (!$codes) {
                $this->log('no_movement early return: empty codes');
                wp_send_json_success(['items'=>[], 'last'=>true, 'debug'=>'empty_codes']);
            }
        }
        // один “логический” блок локаций с набором кодов
        $locations = [
            ['id' => 0, 'codes' => $codes]
        ];

        // пагинация
        $page=0; $pageSize=2500; $items=[]; $last=false; $guard=200;
        do {
            $payload = [
                'locations' => $locations,
                'opTypes'   => $ops,
                'from'      => $fromIso,
                'to'        => $toIso,
                'page'      => $page,
                'pageSize'  => $pageSize,
            ];
            $this->log('no_movement POST to Java', [
                'url' => $base.'/admin/stock/stock/no-movement',
                'payload' => $payload,
            ]);
            $resp = wp_remote_post($base.'/admin/stock/stock/no-movement', [
                'timeout' => 45,
                'headers' => array_filter([
                    'Accept'       => 'application/json',
                    'Content-Type' => 'application/json',
                    'X-Auth-Token' => $token ?: null,
                ]),
                'body'    => wp_json_encode($payload),
            ]);

            $http = (int)wp_remote_retrieve_response_code($resp);
        $this->log('no_movement Java HTTP', $http);
            if (is_wp_error($resp)) wp_send_json_error(['err'=>$resp->get_error_message()],500);
            $code = (int)wp_remote_retrieve_response_code($resp);
            if ($code<200 || $code>=300) {
                $this->log('no_movement Java ERR body', wp_remote_retrieve_body($resp));
                wp_send_json_error(['err'=>'HTTP '.$code, 'body'=> wp_remote_retrieve_body($resp)], $code);
            }

            $data = json_decode(wp_remote_retrieve_body($resp), true) ?: [];
            $items = array_merge($items, (array)($data['items'] ?? []));
            $last  = !empty($data['last']);
            $page++;
        } while(!$last && $guard-- > 0);

        wp_send_json_success(['items'=>$items, 'last'=>$last]);
    }
    public function dbg_ping(){
        $this->check();
        $this->log('dbg_ping HIT', $_POST);
        wp_send_json_success(['ok'=>true, 'time'=>gmdate('c')]);
    }
}