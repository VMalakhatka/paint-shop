<?php
if (!defined('ABSPATH')) exit;

/** Get & normalize plugin options */
function lps_get_options(): array {
    $o = get_option(LPS_OPT_MAIN, []);
    return wp_parse_args(is_array($o)?$o:[], [
        'java_base_url'  => '',
        'api_token'      => '',
        'path_contracts' => '/ref/ref/contracts',
        'path_prices'    => '/prices/query',
        'batch'          => LPS_DEF_BATCH,
        'timeout'        => 160,
        'cron_enabled'   => false,
    ]);
}
function lps_update_options(array $o): void {
    update_option(LPS_OPT_MAIN, $o, false);
}

/** Mapping role → contractCode */
function lps_get_mapping(): array {
    $m = get_option(LPS_OPT_MAPPING, []);
    return is_array($m) ? $m : [];
}
function lps_update_mapping(array $map) {
    // sanitize: only slug => string
    $clean = [];
    foreach ($map as $slug => $code) {
        $slug = sanitize_key($slug);
        $code = sanitize_text_field((string)$code);
        if ($slug !== '' && $code !== '') $clean[$slug] = $code;
    }
    update_option(LPS_OPT_MAPPING, $clean, false);
}

/** Build Java URL */
function lps_java_url(string $path): string {
    $o = lps_get_options();
    $base = rtrim($o['java_base_url'], '/');
    $path = '/'.ltrim($path, '/');
    return $base.$path;
}

// ==== HTTP ====
function lps_java_get(string $path, array $args = []) {
    $o = lps_get_options();
    $url = rtrim($o['java_base_url'], '/') . '/' . ltrim($path, '/');
    $args = wp_parse_args($args, [
        'timeout' => (int)($o['timeout'] ?? 160),
        'headers' => [
            'X-Auth-Token' => $o['api_token'] ?? '',
            'Accept'       => 'application/json',
        ],
    ]);
    return wp_remote_get($url, $args);
}

function lps_java_post(string $path, $body, array $args = []) {
    $o = lps_get_options();
    $url = rtrim($o['java_base_url'], '/') . '/' . ltrim($path, '/');
    $args = wp_parse_args($args, [
        'timeout' => (int)($o['timeout'] ?? 160),
        'headers' => [
            'X-Auth-Token'   => $o['api_token'] ?? '',
            'Accept'         => 'application/json',
            'Content-Type'   => 'application/json',
        ],
        'body' => is_string($body) ? $body : wp_json_encode($body),
    ]);
    return wp_remote_post($url, $args);
}


// ==== роли ====
function lps_get_roles(): array {
    $out = [];
    foreach (wp_roles()->roles as $slug => $def) {
        $out[$slug] = $def['name'] ?? $slug;
    }
    ksort($out, SORT_NATURAL|SORT_FLAG_CASE);
    return $out;
}


/** HTTP GET/POST to Java */
function lps_http_json(string $method, string $url, array $args = []) {
    $o = lps_get_options();
    $req = [
        'timeout' => (int)$o['timeout'],
        'headers' => [
            'Accept'        => 'application/json',
            'X-Auth-Token'  => (string)($o['api_token'] ?? ''),
        ],
    ];
    if (strtoupper($method) === 'POST') {
        $req['headers']['Content-Type'] = 'application/json';
        $req['body'] = wp_json_encode($args, JSON_UNESCAPED_UNICODE);
        $resp = wp_remote_post($url, $req);
    } else {
        $resp = wp_remote_get(add_query_arg($args, $url), $req);
    }
    if (is_wp_error($resp)) {
        return ['ok'=>false, 'error'=>$resp->get_error_message()];
    }
    $code = wp_remote_retrieve_response_code($resp);
    $body_raw = wp_remote_retrieve_body($resp);
    $ct = strtolower(wp_remote_retrieve_header($resp, 'content-type') ?: '');
    $body = (strpos($ct,'application/json')!==false) ? json_decode($body_raw, true) : $body_raw;
    return [
        'ok'   => $code >= 200 && $code < 300,
        'code' => $code,
        'body' => $body,
        'raw'  => $body_raw,
    ];
}

/** Collect SKUs (paged) */
function lps_count_all_skus(): int {
    global $wpdb;
    return (int)$wpdb->get_var("
        SELECT COUNT(DISTINCT m.meta_value)
        FROM {$wpdb->postmeta} m
        JOIN {$wpdb->posts} p ON p.ID = m.post_id
        WHERE m.meta_key = '_sku' AND m.meta_value <> ''
          AND p.post_type IN ('product','product_variation')
          AND p.post_status IN ('publish','private','draft','pending')
    ");
}
function lps_get_skus_slice(int $offset, int $limit): array {
    global $wpdb;
    $sql = $wpdb->prepare("
        SELECT DISTINCT m.meta_value
        FROM {$wpdb->postmeta} m
        JOIN {$wpdb->posts} p ON p.ID = m.post_id
        WHERE m.meta_key = '_sku' AND m.meta_value <> ''
          AND p.post_type IN ('product','product_variation')
          AND p.post_status IN ('publish','private','draft','pending')
        ORDER BY m.meta_value ASC
        LIMIT %d OFFSET %d
    ", $limit, $offset);
    $rows = $wpdb->get_col($sql) ?: [];
    return array_values(array_filter(array_map('strval',$rows)));
}