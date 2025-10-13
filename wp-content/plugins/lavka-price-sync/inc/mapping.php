<?php
// inc/mapping.php
if (!defined('ABSPATH')) exit;

/** Храним и читаем маппинг { role_slug => contractCode } */
function lps_get_role_contract_map(): array {
    $m = get_option(LPS_OPT_MAPPING, []);
    return is_array($m) ? array_map('strval', $m) : [];
}
function lps_set_role_contract_map(array $map): void {
    // нормализуем ключи ролей
    $clean = [];
    foreach ($map as $role => $code) {
        $role = sanitize_key($role);
        if ($role === '') continue;
        $clean[$role] = sanitize_text_field((string)$code);
    }
    update_option(LPS_OPT_MAPPING, $clean, false);
}

/** AJAX: получить список контрактов из Java */
add_action('wp_ajax_lps_get_contracts', function () {
    if (!current_user_can(LPS_CAP)) wp_send_json_error(['error'=>'forbidden'], 403);
    check_ajax_referer('lps_admin_nonce');

    $o = lps_get_options();
    if (!$o['java_base_url']) wp_send_json_error(['error'=>'no_java_url']);

    $resp = lps_java_get($o['path_contracts']);
    if (is_wp_error($resp)) wp_send_json_error(['error'=>$resp->get_error_message()]);
    $code = wp_remote_retrieve_response_code($resp);
    $ct   = strtolower((string)wp_remote_retrieve_header($resp,'content-type'));
    $body = (stripos($ct,'json')!==false) ? json_decode(wp_remote_retrieve_body($resp), true) : [];

    if ($code < 200 || $code >= 300) {
        wp_send_json_error(['error'=>"HTTP $code", 'body'=>is_array($body)?$body:null]);
    }

    // нормализуем к виду [{code, name}]
    $items = [];
    $src = (isset($body['items']) && is_array($body['items'])) ? $body['items'] : (is_array($body) ? $body : []);
    foreach ($src as $x) {
        $code = (string)($x['code'] ?? $x['id'] ?? $x['contract'] ?? '');
        if ($code==='') continue;
        $name = (string)($x['name'] ?? $x['title'] ?? $code);
        $items[] = ['code'=>$code, 'name'=>$name];
    }
    wp_send_json_success(['items'=>$items]);
});

/** AJAX: получить текущий роль→контракт */
add_action('wp_ajax_lps_get_mapping', function () {
    if (!current_user_can(LPS_CAP)) wp_send_json_error(['error'=>'forbidden'], 403);
    check_ajax_referer('lps_admin_nonce');

    wp_send_json_success(['map'=>lps_get_role_contract_map(), 'roles'=>lps_get_roles()]);
});

/** AJAX: сохранить роль→контракт */
add_action('wp_ajax_lps_save_mapping', function () {
    if (!current_user_can(LPS_CAP)) wp_send_json_error(['error'=>'forbidden'], 403);
    check_ajax_referer('lps_admin_nonce');

    $raw = $_POST['map'] ?? [];
    if (!is_array($raw)) $raw = [];
    lps_set_role_contract_map($raw);

    wp_send_json_success(['ok'=>true, 'written'=>count($raw)]);
});