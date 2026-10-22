<?php

namespace Paint\NovaPoshta\Infrastructure;

use WP_Error;

defined('ABSPATH') || exit;

final class ApiClient
{
    public function __construct(private readonly ApiRequestThrottle $throttle = new ApiRequestThrottle()) {}

    // Explicit read allowlist: unknown methods and every mutation are single-attempt.
    private const RETRYABLE_READS = [
        'Counterparty.getCounterparties', 'Counterparty.getCounterpartyAddresses',
        'Counterparty.getCounterpartyContactPersons', 'Address.getWarehouses', 'Address.getCities',
        'AddressGeneral.searchSettlements', 'InternetDocument.getDocumentPrice',
        'InternetDocumentGeneral.getDocumentPrice', 'TrackingDocument.getStatusDocuments',
    ];
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
        $attempts = in_array($model . '.' . $method, self::RETRYABLE_READS, true) ? 2 : 1;
        for ($attempt = 0; $attempt < $attempts; $attempt++) {
            $result = $this->throttle->run($key, fn() => $this->request($payload));
            if (!is_wp_error($result) || $result->get_error_code() !== 'pnpm_api_rate_limited') return $result;
        }
        return $result;
    }

    private function request(array $payload)
    {
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
        $limited = $code === 429;
        if (is_array($decoded) && ($decoded['success'] ?? null) === false) {
            foreach ((array)($decoded['errorCodes'] ?? []) as $error) {
                if (is_scalar($error) && (string)$error === '20000401501') $limited = true;
            }
            foreach ((array)($decoded['errors'] ?? []) as $error) {
                if (is_string($error) && in_array(strtolower(trim($error)), ['to many requests', 'too many requests'], true)) $limited = true;
            }
        }
        if ($limited) return new WP_Error('pnpm_api_rate_limited', __('Nova Poshta temporarily limited requests. Wait a few seconds before checking again.', 'paint-nova-poshta-multishipping'));
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

    public function writesEnabled(): bool
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

    /** The secret-bearing carrier URL must never leave this server-side transport. */
    public function marking(string $ref, string $format = 'a4')
    {
        if (!preg_match('/^[a-f0-9]{8}(-[a-f0-9]{4}){3}-[a-f0-9]{12}$/iD', $ref)
            || !in_array($format, ['a4', 'zebra', 'document'], true) || !$this->apiKeyConfigured()) {
            return new WP_Error('pnpm_print_invalid', __('Label printing is unavailable.', 'paint-nova-poshta-multishipping'));
        }
        $path = ['zebra'=>'printMarking100x100','a4'=>'printMarking85x85','document'=>'printDocument'][$format];
        $url = 'https://my.novaposhta.ua/orders/' . $path . '/orders[]/' . rawurlencode($ref)
            . '/type/' . ($format === 'a4' ? 'pdf8' : 'pdf') . '/apiKey/' . rawurlencode($this->apiKey())
            . ($format === 'zebra' ? '/zebra' : '');
        $response = wp_remote_get($url, ['timeout'=>20, 'redirection'=>0, 'limit_response_size'=>10485761]);
        if (is_wp_error($response)) return new WP_Error('pnpm_print_failed', __('Label printing is unavailable.', 'paint-nova-poshta-multishipping'));
        $body = wp_remote_retrieve_body($response);
        $mime = strtolower((string)wp_remote_retrieve_header($response, 'content-type'));
        if (wp_remote_retrieve_response_code($response) !== 200 || !str_starts_with($mime, 'application/pdf')
            || !str_starts_with($body, '%PDF-') || strlen($body) > 10485760) {
            return new WP_Error('pnpm_print_failed', __('Nova Poshta did not return a valid PDF. No file was downloaded.', 'paint-nova-poshta-multishipping'));
        }
        return $body;
    }
}
