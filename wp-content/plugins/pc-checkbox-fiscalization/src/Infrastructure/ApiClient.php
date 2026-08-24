<?php

namespace Paint\CheckboxFiscalization\Infrastructure;

use Paint\CheckboxFiscalization\Config;

defined('ABSPATH') || exit;

final class ApiClient
{
    public function __construct(private Config $config)
    {
    }

    /** @return array<string,mixed> */
    public function signIn(): array
    {
        $response = $this->request(
            'POST',
            '/api/v1/cashier/signinPinCode',
            ['pin_code' => $this->config->cashierPin()],
            '',
            ['X-License-Key' => $this->config->licenseKey()]
        );
        if (!$response['ok']) {
            return $response;
        }

        $token = is_array($response['body']) ? (string) ($response['body']['access_token'] ?? '') : '';
        if ($token === '') {
            return $this->localFailure('missing_access_token', 'Checkbox did not return an access token.', 502);
        }
        $response['access_token'] = $token;
        return $response;
    }

    /** @return array<string,mixed> */
    public function cashierProfile(string $token): array
    {
        return $this->request('GET', '/api/v1/cashier/me', null, $token);
    }

    /** @return array<string,mixed> */
    public function activeShift(string $token): array
    {
        return $this->request('GET', '/api/v1/cashier/shift', null, $token);
    }

    /** @return array<string,mixed> */
    public function taxes(string $token): array
    {
        return $this->request('GET', '/api/v1/cashier/tax', null, $token);
    }

    /** @return array<string,mixed> */
    public function openShift(string $token, string $shift_id): array
    {
        return $this->request(
            'POST',
            '/api/v1/shifts',
            ['id' => $shift_id],
            $token,
            ['X-License-Key' => $this->config->licenseKey()]
        );
    }

    /** @return array<string,mixed> */
    public function getShift(string $token, string $shift_id): array
    {
        return $this->request('GET', '/api/v1/shifts/' . rawurlencode($shift_id), null, $token);
    }

    /** @param array<string,mixed> $command @return array<string,mixed> */
    public function validateReceipt(string $token, array $command): array
    {
        return $this->request('POST', '/api/v1/receipts/validate', $this->receiptPayload($command), $token);
    }

    /** @param array<string,mixed> $command @return array<string,mixed> */
    public function createReceipt(string $token, array $command): array
    {
        // Checkbox uses the sell endpoint for both sales and returns; is_return is set per goods line.
        return $this->request('POST', '/api/v1/receipts/sell', $this->receiptPayload($command), $token);
    }

    /** @return array<string,mixed> */
    public function getReceipt(string $token, string $receipt_id): array
    {
        return $this->request('GET', '/api/v1/receipts/' . rawurlencode($receipt_id), null, $token);
    }

    /** @param array<string,mixed> $command @return array<string,mixed> */
    public function receiptPayload(array $command): array
    {
        $goods = [];
        foreach ($command['goods'] as $item) {
            $good = [
                'code' => $item['code'],
                'name' => $item['name'],
                'price' => $item['price_cents'],
                'tax' => $item['tax_codes'],
            ];
            foreach (['barcode', 'uktzed', 'header', 'footer'] as $field) {
                if (isset($item[$field])) {
                    $good[$field] = $item[$field];
                }
            }
            if (isset($item['excise_barcodes'])) {
                $good['excise_barcodes'] = $item['excise_barcodes'];
            }
            $goods[] = [
                'good' => $good,
                'quantity' => $item['quantity_thousandths'],
                'is_return' => (bool) $item['is_return'],
                'discounts' => $item['discounts'],
                'total_sum' => $item['line_total_cents'],
            ];
        }

        $payments = [];
        foreach ($command['payments'] as $payment) {
            $mapped = [
                'type' => $payment['type'],
                'value' => $payment['value_cents'],
                'label' => $payment['label'],
            ];
            foreach (['code', 'provider_type', 'commission', 'card_mask', 'bank_name', 'auth_code', 'rrn', 'payment_system', 'owner_name', 'terminal', 'acquirer_and_seller', 'receipt_no', 'signature_required', 'tapxphone_terminal', 'transaction_id'] as $field) {
                if (isset($payment[$field])) {
                    $mapped[$field] = $payment[$field];
                }
            }
            $payments[] = $mapped;
        }

        $payload = [
            'id' => $command['receipt_id'],
            'goods' => $goods,
            'payments' => $payments,
            'discounts' => $command['discounts'],
        ];
        foreach (['cashier_name', 'control_number', 'header', 'footer', 'barcode', 'rounding_mode', 'related_receipt_id', 'order_id', 'stock_code', 'technical_return', 'context', 'previous_receipt_id', 'delivery'] as $field) {
            if (isset($command[$field])) {
                $payload[$field] = $command[$field];
            }
        }
        if (isset($command['department'])) {
            // Checkbox API retains this historical field spelling.
            $payload['departament'] = $command['department'];
        }
        return $payload;
    }

    /** @param array<string,mixed> $profile */
    public function environment(array $profile): string
    {
        $flags = $this->findValues($profile, ['is_test', 'test']);
        foreach ($flags as $flag) {
            if ($flag === true || $flag === 1 || $flag === '1') {
                return 'test';
            }
            if ($flag === false || $flag === 0 || $flag === '0') {
                return 'live';
            }
        }
        foreach ($this->findValues($profile, ['fiscal_number', 'fiscal_code', 'license_key']) as $value) {
            if (is_string($value) && str_starts_with(strtoupper($value), 'TEST')) {
                return 'test';
            }
        }
        return 'unknown';
    }

    /** @return array<string,mixed> */
    private function request(
        string $method,
        string $path,
        ?array $body = null,
        string $token = '',
        array $extra_headers = [],
        array $query = []
    ): array {
        $url = $this->config->apiBaseUrl() . $path;
        if ($query !== []) {
            $url = add_query_arg($query, $url);
        }
        $headers = array_merge([
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            'X-Client-Name' => $this->config->clientName(),
            'X-Client-Version' => $this->config->clientVersion(),
        ], $extra_headers);
        if ($this->config->deviceId() !== '') {
            $headers['X-Device-ID'] = $this->config->deviceId();
        }
        if ($this->config->accessKey() !== '') {
            $headers['X-Access-Key'] = $this->config->accessKey();
        }
        if ($token !== '') {
            $headers['Authorization'] = 'Bearer ' . $token;
        }

        $args = [
            'method' => $method,
            'headers' => $headers,
            'timeout' => $this->config->requestTimeout(),
            'redirection' => 0,
            'sslverify' => true,
            'data_format' => 'body',
        ];
        if ($body !== null) {
            $args['body'] = wp_json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        $raw = wp_remote_request($url, $args);
        if (is_wp_error($raw)) {
            return $this->localFailure('transport_error', $this->safeMessage($raw->get_error_message()), 0, true);
        }

        $http_code = (int) wp_remote_retrieve_response_code($raw);
        $decoded = json_decode((string) wp_remote_retrieve_body($raw), true);
        $decoded = is_array($decoded) ? $decoded : [];
        $ok = $http_code >= 200 && $http_code < 300;
        return [
            'ok' => $ok,
            'http_code' => $http_code,
            'body' => $decoded,
            'error_code' => $ok ? '' : $this->errorCode($decoded, $http_code),
            'error_message' => $ok ? '' : $this->errorMessage($decoded, $http_code),
            'transport_error' => false,
        ];
    }

    /** @return array<string,mixed> */
    private function localFailure(string $code, string $message, int $http_code, bool $transport = false): array
    {
        return [
            'ok' => false,
            'http_code' => $http_code,
            'body' => [],
            'error_code' => $code,
            'error_message' => $message,
            'transport_error' => $transport,
        ];
    }

    /** @param array<string,mixed> $body */
    private function errorCode(array $body, int $http_code): string
    {
        foreach (['code', 'error_code', 'detail'] as $field) {
            if (isset($body[$field]) && is_scalar($body[$field])) {
                return sanitize_key((string) $body[$field]) ?: 'checkbox_error';
            }
        }
        return 'checkbox_http_' . $http_code;
    }

    /** @param array<string,mixed> $body */
    private function errorMessage(array $body, int $http_code): string
    {
        foreach (['message', 'detail', 'error'] as $field) {
            if (isset($body[$field]) && is_scalar($body[$field])) {
                return $this->safeMessage((string) $body[$field]);
            }
        }
        return 'Checkbox API returned HTTP ' . $http_code . '.';
    }

    private function safeMessage(string $message): string
    {
        foreach ([$this->config->licenseKey(), $this->config->cashierPin(), $this->config->accessKey()] as $secret) {
            if ($secret !== '') {
                $message = str_replace($secret, '[redacted]', $message);
            }
        }
        $message = wp_strip_all_tags($message);
        $message = preg_replace('/[\r\n\t]+/', ' ', $message) ?: '';
        return function_exists('mb_substr') ? mb_substr($message, 0, 500) : substr($message, 0, 500);
    }

    /** @param array<string,mixed> $data @param list<string> $keys @return list<mixed> */
    private function findValues(array $data, array $keys): array
    {
        $values = [];
        foreach ($data as $key => $value) {
            if (in_array((string) $key, $keys, true)) {
                $values[] = $value;
            }
            if (is_array($value)) {
                $values = array_merge($values, $this->findValues($value, $keys));
            }
        }
        return $values;
    }
}
