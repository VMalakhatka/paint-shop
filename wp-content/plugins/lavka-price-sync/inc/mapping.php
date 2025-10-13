<?php
// inc/mapping.php
if (!defined('ABSPATH')) exit;

/** ===== Хранилище маппинга =====
 * Храним { role_slug => string|array }:
 *   - строка: старый формат (только code/или name)
 *   - массив: {code, name, organization}
 */

// Гарантируем наличие базовых функций, если вдруг загрузка пошла «в обход» main-файла
if (!function_exists('lps_get_options') || !function_exists('lps_java_get') || !function_exists('lps_get_roles')) {
    require_once __DIR__ . '/helpers.php';
}


function lps_get_role_contract_map(): array {
    $m = get_option(LPS_OPT_MAPPING, []);
    return is_array($m) ? $m : [];
}
function lps_set_role_contract_map(array $map): void {
    $clean = [];
    foreach ($map as $role => $val) {
        $role = sanitize_key($role);
        if ($role === '') continue;

        if (is_array($val)) {
            $clean[$role] = [
                'code'         => sanitize_text_field((string)($val['code'] ?? '')),
                'name'         => sanitize_text_field((string)($val['name'] ?? '')),
                'organization' => ($val['organization'] === null)
                                   ? null
                                   : sanitize_text_field((string)$val['organization']),
            ];
        } else {
            // поддержка старого формата
            $clean[$role] = sanitize_text_field((string)$val);
        }
    }
    update_option(LPS_OPT_MAPPING, $clean, false);
}

/** ===== Вспомогательное: загрузить справочник с бэка ===== */
function lps_fetch_contracts_list(): array {
    $o = lps_get_options();
    $resp = lps_java_get($o['path_contracts'] ?: '/ref/ref/contracts');
    if (is_wp_error($resp)) return [];

    $code = wp_remote_retrieve_response_code($resp);
    $ct   = strtolower((string)wp_remote_retrieve_header($resp,'content-type'));
    if ($code < 200 || $code >= 300 || strpos($ct,'json') === false) return [];

    $arr = json_decode(wp_remote_retrieve_body($resp), true);
    if (!is_array($arr)) return [];

    // нормализация
    $out = [];
    foreach ($arr as $x) {
        if (!is_array($x)) continue;
        $code = trim((string)($x['code'] ?? ''));
        $name = trim((string)($x['name'] ?? ''));
        $org  = array_key_exists('organization',$x)
                  ? ($x['organization'] === null ? null : trim((string)$x['organization']))
                  : null;
        if ($code === '' && $name === '') continue;
        $out[] = ['code'=>$code, 'name'=>$name, 'organization'=>$org];
    }
    return $out;
}

/** ===== AJAX: отдать список контрактов фронту ===== */
add_action('wp_ajax_lps_get_contracts', function () {
    if (!current_user_can(LPS_CAP)) wp_send_json_error(['error'=>'forbidden'], 403);
    check_ajax_referer('lps_admin_nonce');
    wp_send_json_success(['items' => lps_fetch_contracts_list()]);
});

/** ===== AJAX: отдать текущий маппинг ролей ===== */
add_action('wp_ajax_lps_get_mapping', function () {
    if (!current_user_can(LPS_CAP)) wp_send_json_error(['error'=>'forbidden'], 403);
    check_ajax_referer('lps_admin_nonce');
    wp_send_json_success(['map'=>lps_get_role_contract_map(),'roles'=>lps_get_roles()]);
});

/** ===== AJAX: сохранить маппинг
 * Фронт присылает { role => selectedCode }.
 * Мы расширяем до полного объекта, подставляя из свежего справочника.
 */
add_action('wp_ajax_lps_save_mapping', function () {
    if (!current_user_can(LPS_CAP)) wp_send_json_error(['error'=>'forbidden'], 403);
    check_ajax_referer('lps_admin_nonce');

    $raw = $_POST['map'] ?? [];
    if (!is_array($raw)) $raw = [];

    // индексируем справочник по code и по name (на всякий случай)
    $byCode = [];
    $byName = [];
    foreach (lps_fetch_contracts_list() as $c) {
        if ($c['code'] !== '') $byCode[$c['code']] = $c;
        if ($c['name'] !== '') $byName[$c['name']] = $c;
    }

    $final = [];
    foreach ($raw as $role => $sel) {
        $role = sanitize_key($role);
        $key  = (string)$sel;
        if ($role === '' || $key === '') continue;

        $obj = $byCode[$key] ?? $byName[$key] ?? null;
        $final[$role] = $obj ?: $key; // если не нашли — не теряем выбор
    }

    lps_set_role_contract_map($final);
    wp_send_json_success(['ok'=>true, 'written'=>count($final)]);
});