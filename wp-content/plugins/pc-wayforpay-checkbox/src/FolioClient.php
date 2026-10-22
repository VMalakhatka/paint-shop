<?php
namespace Paint\WayForPayCheckbox;

class FolioClient
{
    public function call(string $method, string $path, ?array $body = null): array
    {
        $options = (array) get_option(defined('LTS_OPT') ? LTS_OPT : 'lts_options', []);
        $base = rtrim((string) ($options['java_base_url'] ?? ($options['base_url'] ?? '')), '/');
        $token = defined('PC_WFP_FOLIO_API_TOKEN') ? (string) PC_WFP_FOLIO_API_TOKEN : '';
        if (!preg_match('#^https?://#', $base) || strlen($token) < 32) return ['http' => 503, 'code' => 'folio_connection_not_configured'];
        $args = ['method' => $method, 'timeout' => 45, 'redirection' => 0,
            'headers' => ['X-Auth-Token' => $token, 'Accept' => 'application/json', 'Content-Type' => 'application/json']];
        if ($body !== null) $args['body'] = wp_json_encode($body);
        $response = wp_remote_request($base . '/admin/folio/wfp-payments/' . $path, $args);
        if (is_wp_error($response)) return ['http' => 0, 'code' => 'folio_transport_unknown'];
        $json = json_decode(wp_remote_retrieve_body($response), true);
        return ['http' => (int) wp_remote_retrieve_response_code($response),
            'code' => is_array($json) ? sanitize_key((string) ($json['code'] ?? '')) : 'folio_invalid_response',
            'data' => is_array($json) ? $json : []];
    }
}
