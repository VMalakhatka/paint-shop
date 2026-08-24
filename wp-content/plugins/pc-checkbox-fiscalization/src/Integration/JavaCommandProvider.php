<?php

namespace Paint\CheckboxFiscalization\Integration;

use Paint\CheckboxFiscalization\Config;

defined('ABSPATH') || exit;

final class JavaCommandProvider
{
    public function __construct(private Config $config)
    {
    }

    /** @return array<string,mixed>|\WP_Error */
    public function fetch(string $source_type, string $source_id): array|\WP_Error
    {
        $source_type = sanitize_key($source_type);
        $source_id = trim($source_id);
        if ($source_type === '' || $source_id === '' || strlen($source_id) > 191) {
            return new \WP_Error('invalid_source', 'source_type and source_id are required.', ['status' => 400]);
        }

        $base = $this->config->javaBaseUrl();
        $path = $this->config->javaCommandPath();
        if (!$this->safeBaseUrl($base)) {
            return new \WP_Error('java_url_invalid', 'The Java base URL must use HTTPS (HTTP is allowed only for localhost).', ['status' => 500]);
        }
        if (!str_starts_with($path, '/') || !str_contains($path, '{source_id}') || str_contains($path, '://')) {
            return new \WP_Error('java_path_invalid', 'The Java path must start with / and include {source_id}.', ['status' => 500]);
        }

        $url = $base . str_replace('{source_id}', rawurlencode($source_id), $path);
        $url = add_query_arg('source_type', $source_type, $url);
        $headers = ['Accept' => 'application/json'];
        if ($this->config->javaToken() !== '') {
            $headers['X-Auth-Token'] = $this->config->javaToken();
        }
        $response = wp_remote_get($url, [
            'headers' => $headers,
            'timeout' => $this->config->javaTimeout(),
            'redirection' => 0,
            'sslverify' => true,
        ]);
        if (is_wp_error($response)) {
            return new \WP_Error('java_transport_error', 'The Java fiscalization endpoint could not be reached.', ['status' => 502]);
        }

        $http_code = (int) wp_remote_retrieve_response_code($response);
        $body = json_decode((string) wp_remote_retrieve_body($response), true);
        if ($http_code < 200 || $http_code >= 300 || !is_array($body)) {
            return new \WP_Error('java_response_invalid', 'The Java endpoint did not return a valid fiscalization command.', [
                'status' => $http_code >= 400 && $http_code < 500 ? $http_code : 502,
            ]);
        }
        return isset($body['command']) && is_array($body['command']) ? $body['command'] : $body;
    }

    private function safeBaseUrl(string $url): bool
    {
        $parts = wp_parse_url($url);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return false;
        }
        if (strtolower((string) $parts['scheme']) === 'https') {
            return true;
        }
        return strtolower((string) $parts['scheme']) === 'http'
            && in_array(strtolower((string) $parts['host']), ['localhost', '127.0.0.1', '::1'], true);
    }
}
