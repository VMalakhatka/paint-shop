<?php
if (!defined('ABSPATH')) exit;

add_action('wp_ajax_lts_sync_run', function () {
    if (!current_user_can('manage_lavka_sync')) {
        wp_send_json_error(['error' => 'cap']);
    }
    check_ajax_referer('lts_admin_nonce', 'nonce');

    $o = get_option('lts_options', []);
    $base = isset($o['java_base_url']) ? rtrim((string)$o['java_base_url'], '/') : '';
    $token = isset($o['api_token']) ? (string)$o['api_token'] : '';
    $timeout = isset($o['timeout']) ? (int)$o['timeout'] : 600;

    if (!$base) wp_send_json_error(['error' => 'java_base_url_missing']);

    $url = $base . '/sync/run';

    // Параметры из UI
    $limit       = isset($_POST['limit'])       && $_POST['limit'] !== '' ? (int)$_POST['limit'] : null;
    $pageSizeWoo = isset($_POST['pageSizeWoo']) && $_POST['pageSizeWoo'] !== '' ? (int)$_POST['pageSizeWoo'] : null;
    $cursorAfter = isset($_POST['cursorAfter']) ? sanitize_text_field((string)$_POST['cursorAfter']) : null;
    $dryRun      = !empty($_POST['dryRun']);

    $payload = [
        'limit'       => $limit,
        'pageSizeWoo' => $pageSizeWoo,
        'cursorAfter' => ($cursorAfter === '') ? null : $cursorAfter,
        'dryRun'      => $dryRun,
    ];

    $headers = [
        'Accept'       => 'application/json',
        'Content-Type' => 'application/json; charset=utf-8',
        'User-Agent'   => 'LavkaTotalSync/1.0',
    ];
    if ($token !== '') {
        $headers['Authorization'] = 'Bearer ' . $token;
    }

    $resp = wp_remote_post($url, [
        'timeout' => max(20, $timeout),
        'headers' => $headers,
        'body'    => wp_json_encode($payload),
    ]);

    if (is_wp_error($resp)) {
        wp_send_json_error(['error' => $resp->get_error_message()]);
    }

    $code = wp_remote_retrieve_response_code($resp);
    $body = (string) wp_remote_retrieve_body($resp);
    $json = json_decode($body, true);

    // Отдаём как есть (и код, и json/текст — на всякий случай)
    wp_send_json_success([
        'status' => $code,
        'json'   => is_array($json) ? $json : null,
        'raw'    => is_array($json) ? null : mb_substr($body, 0, 2000),
    ]);
});