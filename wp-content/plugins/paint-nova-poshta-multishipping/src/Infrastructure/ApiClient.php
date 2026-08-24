<?php

namespace Paint\NovaPoshta\Infrastructure;

use WP_Error;

defined('ABSPATH') || exit;

final class ApiClient
{
    private const MUTATING_METHODS = [
        'save', 'update', 'delete', 'redirecting', 'return', 'create',
    ];

    public function apiKeyConfigured(): bool
    {
        return $this->apiKey() !== '';
    }

    public function maskedApiKey(): string
    {
        $key = $this->apiKey();
        if ($key === '') {
            return '';
        }
        return str_repeat('*', max(8, strlen($key) - 4)) . substr($key, -4);
    }

    /** @return array<string,mixed>|WP_Error */
    public function call(string $model, string $method, array $properties = [], bool $allow_write = false)
    {
        $model = preg_replace('/[^A-Za-z0-9_]/', '', $model) ?? '';
        $method = preg_replace('/[^A-Za-z0-9_]/', '', $method) ?? '';
        if ($model === '' || $method === '') {
            return new WP_Error('pnpm_invalid_api_call', __('Invalid Nova Poshta API method.', 'paint-nova-poshta-multishipping'));
        }

        if ($this->isMutating($method)) {
            if (!$allow_write || !$this->writesEnabled()) {
                return new WP_Error(
                    'pnpm_write_disabled',
                    __('Real Nova Poshta write operations are disabled.', 'paint-nova-poshta-multishipping')
                );
            }
        }

        $key = $this->apiKey();
        if ($key === '') {
            return new WP_Error('pnpm_missing_api_key', __('Nova Poshta API key is not configured.', 'paint-nova-poshta-multishipping'));
        }

        $payload = [
            'apiKey' => $key,
            'modelName' => $model,
            'calledMethod' => $method,
            'methodProperties' => $properties,
        ];
        $response = wp_remote_post($this->baseUrl(), [
            'timeout' => 15,
            'redirection' => 0,
            'headers' => ['Content-Type' => 'application/json; charset=utf-8'],
            'body' => wp_json_encode($payload),
        ]);

        if (is_wp_error($response)) {
            return new WP_Error('pnpm_api_unavailable', __('Nova Poshta API is unavailable.', 'paint-nova-poshta-multishipping'));
        }

        $code = wp_remote_retrieve_response_code($response);
        $decoded = json_decode(wp_remote_retrieve_body($response), true);
        if ($code < 200 || $code >= 300 || !is_array($decoded)) {
            return new WP_Error('pnpm_api_invalid_response', __('Nova Poshta API returned an invalid response.', 'paint-nova-poshta-multishipping'));
        }

        return $decoded;
    }

    private function apiKey(): string
    {
        if (defined('PNPM_NOVA_POSHTA_API_KEY')) {
            return trim((string) constant('PNPM_NOVA_POSHTA_API_KEY'));
        }
        $environment = getenv('PNPM_NOVA_POSHTA_API_KEY');
        return is_string($environment) ? trim($environment) : '';
    }

    private function baseUrl(): string
    {
        $settings = get_option('pnpm_settings', []);
        $url = is_array($settings) ? ($settings['api_base_url'] ?? '') : '';
        $url = esc_url_raw((string) $url);
        return str_starts_with($url, 'https://api.novaposhta.ua/')
            ? $url
            : 'https://api.novaposhta.ua/v2.0/json/';
    }

    private function writesEnabled(): bool
    {
        $settings = get_option('pnpm_settings', []);
        $setting = is_array($settings) && ($settings['writes_enabled'] ?? 'no') === 'yes';
        return $setting
            && defined('PNPM_ALLOW_REAL_TTN')
            && constant('PNPM_ALLOW_REAL_TTN') === true;
    }

    private function isMutating(string $method): bool
    {
        return in_array(strtolower($method), self::MUTATING_METHODS, true);
    }
}
