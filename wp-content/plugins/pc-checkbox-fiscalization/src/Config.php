<?php

namespace Paint\CheckboxFiscalization;

defined('ABSPATH') || exit;

final class Config
{
    public const OPTION = 'pccf_settings';

    /** @return array<string,mixed> */
    public function settings(): array
    {
        $saved = get_option(self::OPTION, []);
        $saved = is_array($saved) ? $saved : [];

        return wp_parse_args($saved, [
            'api_base_url' => 'https://api.checkbox.ua',
            'client_name' => 'PC Checkbox Fiscalization',
            'client_version' => PCCF_VERSION,
            'device_id' => '',
            'request_timeout' => 30,
            'shift_policy' => 'require_open',
            'java_base_url' => '',
            'java_command_path' => '/admin/folio/fiscalization/commands/{source_id}',
            'java_timeout' => 30,
        ]);
    }

    public function apiBaseUrl(): string
    {
        return 'https://api.checkbox.ua';
    }

    public function clientName(): string
    {
        return (string) $this->settings()['client_name'];
    }

    public function clientVersion(): string
    {
        return (string) $this->settings()['client_version'];
    }

    public function deviceId(): string
    {
        return (string) $this->settings()['device_id'];
    }

    public function requestTimeout(): int
    {
        return max(5, min(120, (int) $this->settings()['request_timeout']));
    }

    public function shiftPolicy(): string
    {
        return $this->settings()['shift_policy'] === 'open_if_missing'
            ? 'open_if_missing'
            : 'require_open';
    }

    public function javaBaseUrl(): string
    {
        return rtrim((string) $this->settings()['java_base_url'], '/');
    }

    public function javaCommandPath(): string
    {
        return (string) $this->settings()['java_command_path'];
    }

    public function javaTimeout(): int
    {
        return max(5, min(120, (int) $this->settings()['java_timeout']));
    }

    public function licenseKey(): string
    {
        return $this->secret('PC_CHECKBOX_LICENSE_KEY');
    }

    public function cashierPin(): string
    {
        return $this->secret('PC_CHECKBOX_CASHIER_PIN');
    }

    public function accessKey(): string
    {
        return $this->secret('PC_CHECKBOX_ACCESS_KEY');
    }

    public function inboundToken(): string
    {
        return $this->secret('PC_CHECKBOX_INBOUND_TOKEN');
    }

    public function javaToken(): string
    {
        return $this->secret('PC_CHECKBOX_JAVA_TOKEN');
    }

    public function fiscalizationEnabled(): bool
    {
        return defined('PC_CHECKBOX_ALLOW_FISCALIZATION')
            && constant('PC_CHECKBOX_ALLOW_FISCALIZATION') === true;
    }

    public function liveEnabled(): bool
    {
        return defined('PC_CHECKBOX_ALLOW_LIVE')
            && constant('PC_CHECKBOX_ALLOW_LIVE') === true;
    }

    public function connectionConfigured(): bool
    {
        return $this->licenseKey() !== '' && $this->cashierPin() !== '';
    }

    public function inboundConfigured(): bool
    {
        return $this->inboundToken() !== '';
    }

    private function secret(string $name): string
    {
        if (defined($name)) {
            $value = constant($name);
            return is_string($value) ? trim($value) : '';
        }

        $value = getenv($name);
        return is_string($value) ? trim($value) : '';
    }
}

