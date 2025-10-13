<?php
// inc/mapping.php
if (!defined('ABSPATH')) exit;

/** Текущий маппинг { role_slug => contractCode } */
function lps_get_role_contract_map(): array {
    $m = get_option(LPS_OPT_MAPPING, []);
    return is_array($m) ? array_map('strval', $m) : [];
}

/** Сохранить маппинг (строки; пустые значения выкидываем) */
function lps_set_role_contract_map(array $map): void {
    $clean = [];
    foreach ($map as $role => $code) {
        $role = sanitize_key((string)$role);
        $code = (string)$code;
        if ($role === '' || $code === '') continue;
        $clean[$role] = sanitize_text_field($code);
    }
    update_option(LPS_OPT_MAPPING, $clean, false);
}

/** Вытянуть контракты из Java */
add_action('wp_ajax_lps_get_contracts', function () {
    if (!current_user_can(LPS_CAP)) wp_send_json_error(['error'=>'forbidden'], 403);
    check_ajax_referer('lps_admin_nonce');

    $o = lps_get_options();
    if (empty($o['java_base_url'])) {
        wp_send_json_success(['items'=>[]]); // пусто, но без ошибки — чтобы UI жил
    }

    $resp = lps_java_get($o['path_contracts']);
    if (is_wp_error($resp)) wp_send_json_error(['error'=>$resp->get_error_message()]);

    $code = wp_remote_retrieve_response_code($resp);
    $ct   = strtolower((string)wp_remote_retrieve_header($resp,'content-type'));
    $raw  = wp_remote_retrieve_body($resp);
    $data = (strpos($ct,'json')!==false) ? json_decode($raw, true) : null;

    if ($code < 200 || $code >= 300) {
        wp_send_json_error(['error'=>"HTTP $code", 'body'=> $data ?: substr($raw,0,400)]);
    }

    // ожидаем массив объектов [{code,name,organization}]
    $items = [];
    if (is_array($data)) {
        foreach ($data as $x) {
            if (!is_array($x)) continue;
            $code = trim((string)($x['code'] ?? ''));
            $name = (string)($x['name'] ?? '');
            $org  = (string)($x['organization'] ?? '');
            if ($code === '' && $name === '') continue;
            $items[] = ['code'=>$code, 'name'=>$name, 'organization'=>$org];
        }
    }
    wp_send_json_success(['items'=>$items]);
});

/** Прочитать текущий маппинг */
add_action('wp_ajax_lps_get_mapping', function () {
    if (!current_user_can(LPS_CAP)) wp_send_json_error(['error'=>'forbidden'], 403);
    check_ajax_referer('lps_admin_nonce');

    wp_send_json_success([
        'map'   => lps_get_role_contract_map(),
        'roles' => lps_get_roles(), // для отладки/будущего
    ]);
});

/** Сохранить маппинг */
add_action('wp_ajax_lps_save_mapping', function () {
    if (!current_user_can(LPS_CAP)) wp_send_json_error(['error'=>'forbidden'], 403);
    check_ajax_referer('lps_admin_nonce');

    // Принимаем максимально терпимо:
    $raw = $_POST['map'] ?? [];

    // Если пришла JSON-строка (на всякий случай)
    if (!is_array($raw) && is_string($raw)) {
        $try = json_decode(stripslashes($raw), true);
        if (is_array($try)) $raw = $try;
    }
    if (!is_array($raw)) $raw = [];

    // Нормализуем к string=>string
    $incoming = [];
    foreach ($raw as $k => $v) {
        $incoming[(string)$k] = is_array($v) ? (string)reset($v) : (string)$v;
    }

    // Записываем
    lps_set_role_contract_map($incoming);
    $saved = lps_get_role_contract_map();

    wp_send_json_success([
        'ok'      => true,
        'written' => count($saved),
        'saved'   => $saved,
        // Диагностика — что прилетело:
        'incoming'=> $incoming,
    ]);
});