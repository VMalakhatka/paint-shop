<?php

namespace Paint\CheckboxFiscalization\Domain;

use Paint\CheckboxFiscalization\Config;
use Paint\CheckboxFiscalization\Infrastructure\ApiClient;
use Paint\CheckboxFiscalization\Infrastructure\OperationRepository;

defined('ABSPATH') || exit;

final class FiscalizationService
{
    public function __construct(
        private Config $config,
        private CommandValidator $validator,
        private OperationRepository $operations,
        private ApiClient $api
    ) {
    }

    /** @return array<string,mixed>|\WP_Error */
    public function execute(array $input, string $mode = 'preview'): array|\WP_Error
    {
        $key = is_scalar($input['operation_key'] ?? null) ? trim((string) $input['operation_key']) : '';
        if (!$this->operations->lock($key)) {
            return new \WP_Error('operation_busy', 'The operation is already being processed.', ['status' => 409]);
        }
        try {
            return $this->executeLocked($input, $mode);
        } catch (\Throwable) {
            return new \WP_Error('operation_processing_failed', 'The operation was interrupted. Check its saved state before retrying.', ['status' => 500]);
        } finally {
            $this->operations->unlock($key);
        }
    }

    private function executeLocked(array $input, string $mode): array|\WP_Error
    {
        $mode = strtolower(trim($mode));
        if (!in_array($mode, ['preview', 'validate', 'fiscalize'], true)) {
            return new \WP_Error('invalid_mode', 'mode must be preview, validate, or fiscalize.', ['status' => 400]);
        }

        try {
            $command = $this->validator->normalize($input);
            $hash = $this->validator->hash($command);
            $reservation = $this->operations->reserve($command, $hash, $mode);
        } catch (ValidationException $error) {
            return new \WP_Error($error->errorCode, $error->getMessage(), [
                'status' => 422,
                'field' => $error->fieldPath,
            ]);
        } catch (\Throwable) {
            return new \WP_Error('operation_storage_error', 'The fiscalization operation could not be stored.', ['status' => 500]);
        }

        $operation = $reservation['operation'];
        if (!hash_equals((string) $operation['operation_key'], (string) $command['operation_key'])) {
            return new \WP_Error('receipt_id_conflict', 'receipt_id is already reserved by another operation_key.', ['status' => 409]);
        }
        if (!hash_equals((string) $operation['request_hash'], $hash)) {
            return new \WP_Error('idempotency_conflict', 'operation_key already exists with different command data.', ['status' => 409]);
        }
        if (in_array($operation['status'], ['SUCCEEDED', 'FISCALIZED'], true)) {
            return $this->result($operation, true, true);
        }
        if (in_array($operation['status'], ['PROCESSING', 'UNCERTAIN'], true)) {
            return new \WP_Error('reconciliation_required', 'The operation state is uncertain. Reconcile it before any retry.', [
                'status' => 409,
                'operation' => $this->result($operation),
            ]);
        }

        if ($mode === 'preview') {
            $this->operations->update($command['operation_key'], ['status' => 'PREVIEW', 'mode' => 'preview']);
            return $this->result($this->operations->find($command['operation_key']) ?? $operation);
        }
        if ($mode === 'fiscalize' && !$this->config->fiscalizationEnabled()) {
            return new \WP_Error('fiscalization_locked', 'Fiscalization is locked. Define PC_CHECKBOX_ALLOW_FISCALIZATION as true to enable it.', ['status' => 403]);
        }
        if (!$this->config->connectionConfigured()) {
            return new \WP_Error('checkbox_not_configured', 'Checkbox license key and cashier PIN are not configured.', ['status' => 503]);
        }

        $auth = $this->api->signIn();
        if (!$auth['ok']) {
            $this->storeFailure($command['operation_key'], $auth, 'AUTH_FAILED');
            return $this->apiError($auth, 'Checkbox authentication failed.');
        }
        $token = (string) $auth['access_token'];

        if ($mode === 'validate') {
            $response = $this->api->validateReceipt($token, $command);
            if (!$response['ok']) {
                $this->storeFailure($command['operation_key'], $response, 'VALIDATION_FAILED');
                return $this->apiError($response, 'Checkbox rejected the receipt command.');
            }
            $this->operations->update($command['operation_key'], [
                'status' => 'VALIDATED',
                'mode' => 'validate',
                'http_code' => $response['http_code'],
                'error_code' => null,
                'error_message' => null,
            ]);
            return $this->result($this->operations->find($command['operation_key']) ?? $operation);
        }

        $environment = $this->assertSafeEnvironment($token);
        if (is_wp_error($environment)) {
            $this->storePreflightError($command['operation_key'], $environment, 'profile', 'ENVIRONMENT_FAILED');
            return $environment;
        }
        $shift = $this->ensureShift($token, $operation);
        if (is_wp_error($shift)) {
            $this->storePreflightError($command['operation_key'], $shift, 'shift', 'SHIFT_FAILED');
            return $shift;
        }

        $this->operations->incrementAttempt($command['operation_key'], 'fiscalize');
        $this->operations->update($command['operation_key'], [
            'status' => 'PROCESSING',
            'shift_id' => $shift,
            'error_code' => null,
            'error_message' => null,
        ]);
        $response = $this->api->createReceipt($token, $command);
        if (!$response['ok']) {
            $http_code = (int) $response['http_code'];
            $uncertain = (bool) $response['transport_error']
                || $http_code >= 500
                || in_array($http_code, [408, 409, 425, 429], true);
            $this->storeFailure($command['operation_key'], $response, $uncertain ? 'UNCERTAIN' : 'FAILED');
            if ($uncertain) {
                return new \WP_Error('reconciliation_required', 'Checkbox may have accepted the receipt. Reconcile before retrying.', [
                    'status' => 409,
                    'operation_key' => $command['operation_key'],
                ]);
            }
            return $this->apiError($response, 'Checkbox rejected the fiscalization request.');
        }

        $this->storeReceiptState($command['operation_key'], $response['body'], (int) $response['http_code']);
        $stored = $this->operations->find($command['operation_key']) ?? $operation;
        if (!in_array($stored['status'], ['SUCCEEDED', 'FISCALIZED'], true)) {
            return new \WP_Error('reconciliation_required', 'Checkbox accepted the request but fiscal completion is not confirmed yet.', [
                'status' => 202,
                'operation' => $this->result($stored),
            ]);
        }
        do_action('pc_checkbox_fiscalization_succeeded', $this->result($stored), $command);
        return $this->result($stored, true);
    }

    /** @return array<string,mixed>|\WP_Error */
    public function reconcile(string $operation_key): array|\WP_Error
    {
        if (!$this->operations->lock($operation_key)) {
            return new \WP_Error('operation_busy', 'The operation is already being processed.', ['status' => 409]);
        }
        try {
            return $this->reconcileLocked($operation_key);
        } catch (\Throwable) {
            return new \WP_Error('operation_processing_failed', 'The operation was interrupted. Check its saved state before retrying.', ['status' => 500]);
        } finally {
            $this->operations->unlock($operation_key);
        }
    }

    private function reconcileLocked(string $operation_key): array|\WP_Error
    {
        $operation = $this->operations->find($operation_key);
        if (!$operation) {
            return new \WP_Error('operation_not_found', 'Fiscalization operation not found.', ['status' => 404]);
        }
        if (in_array($operation['status'], ['SUCCEEDED', 'FISCALIZED'], true)) {
            return $this->result($operation, true, true);
        }
        if (!$this->config->connectionConfigured()) {
            return new \WP_Error('checkbox_not_configured', 'Checkbox connection is not configured.', ['status' => 503]);
        }
        $auth = $this->api->signIn();
        if (!$auth['ok']) {
            return $this->apiError($auth, 'Checkbox authentication failed.');
        }
        $response = $this->api->getReceipt((string) $auth['access_token'], (string) $operation['receipt_uuid']);
        if (!$response['ok']) {
            if ((int) $response['http_code'] === 404) {
                return new \WP_Error('receipt_not_confirmed', 'Checkbox does not currently return this receipt. It was not resent.', [
                    'status' => 409,
                    'operation' => $this->result($operation),
                ]);
            }
            return $this->apiError($response, 'The Checkbox receipt state could not be read.');
        }
        $this->storeReceiptState($operation_key, $response['body'], (int) $response['http_code']);
        return $this->result($this->operations->find($operation_key) ?? $operation);
    }

    /** @return array<string,mixed>|null */
    public function operation(string $operation_key): ?array
    {
        $operation = $this->operations->find($operation_key);
        return $operation ? $this->result($operation) : null;
    }

    /** @return array<string,mixed>|\WP_Error */
    public function connectionSummary(): array|\WP_Error
    {
        if (!$this->config->connectionConfigured()) {
            return new \WP_Error('checkbox_not_configured', 'Checkbox connection is not configured.', ['status' => 503]);
        }
        $auth = $this->api->signIn();
        if (!$auth['ok']) {
            return $this->apiError($auth, 'Checkbox authentication failed.');
        }
        $token = (string) $auth['access_token'];
        $profile = $this->api->cashierProfile($token);
        $shift = $this->api->activeShift($token);
        $taxes = $this->api->taxes($token);
        return [
            'environment' => $profile['ok'] ? $this->api->environment($profile['body']) : 'unknown',
            'profile_available' => (bool) $profile['ok'],
            'shift_status' => $shift['ok'] ? ($this->activeShiftId($shift['body']) !== '' ? 'OPENED' : 'CLOSED') : 'UNKNOWN',
            'cashier' => $profile['ok'] ? [
                'id' => (string) ($profile['body']['id'] ?? ''),
                'full_name' => (string) ($profile['body']['full_name'] ?? ''),
                'is_test' => (bool) ($profile['body']['is_test'] ?? false),
            ] : [],
            'cash_register' => $shift['ok'] && is_array($shift['body']['cash_register'] ?? null) ? [
                'id' => (string) ($shift['body']['cash_register']['id'] ?? ''),
                'number' => (string) ($shift['body']['cash_register']['number'] ?? ''),
                'fiscal_number' => (string) ($shift['body']['cash_register']['fiscal_number'] ?? ''),
                'active' => (bool) ($shift['body']['cash_register']['active'] ?? false),
            ] : [],
            'taxes' => $taxes['ok'] ? $this->safeTaxes($taxes['body']) : [],
        ];
    }

    /** @return string|\WP_Error */
    private function assertSafeEnvironment(string $token): string|\WP_Error
    {
        $profile = $this->api->cashierProfile($token);
        if (!$profile['ok']) {
            return $this->apiError($profile, 'The Checkbox cash-register environment could not be verified.');
        }
        $environment = $this->api->environment($profile['body']);
        if ($environment !== 'test' && !$this->config->liveEnabled()) {
            return new \WP_Error('live_fiscalization_locked', 'The register is live or could not be proven to be test. Define PC_CHECKBOX_ALLOW_LIVE as true only for an approved live rollout.', ['status' => 403]);
        }
        return $environment;
    }

    /** @return string|\WP_Error */
    private function ensureShift(string $token, array $operation): string|\WP_Error
    {
        $manager = new ShiftManager($this->config, $this->api, $this->operations);
        $result = $manager->ensure($token, $this->config->shiftPolicy() === 'open_if_missing');
        if (is_wp_error($result)) {
            $data = (array) $result->get_error_data();
            if (!empty($data['shift_id'])) $this->operations->update($operation['operation_key'], ['shift_id'=>$data['shift_id']]);
            return $result;
        }
        return $result['shift_id'];
    }

    private function storePreflightError(string $key, \WP_Error $error, string $stage, string $status): void
    {
        $data = (array) $error->get_error_data();
        $http = (int) ($data['http_code'] ?? 0);
        $prefix = '[' . (($data['stage'] ?? '') ?: $stage) . '] ';
        $message = $this->api->safeMessage($error->get_error_message());
        $this->operations->update($key, [
            'status'=>$status,
            'http_code'=>$http,
            'error_code'=>$error->get_error_code(),
            'error_message'=>str_starts_with($message, $prefix) ? $message : $prefix . $message,
        ]);
    }

    /** @param array<string,mixed> $body */
    private function activeShiftId(array $body): string
    {
        if (isset($body['results']) && is_array($body['results']) && isset($body['results'][0]) && is_array($body['results'][0])) {
            return strtoupper((string) ($body['results'][0]['status'] ?? '')) === 'OPENED'
                ? (string) ($body['results'][0]['id'] ?? '')
                : '';
        }
        if (isset($body['id']) && strtoupper((string) ($body['status'] ?? '')) === 'OPENED') {
            return (string) $body['id'];
        }
        return '';
    }

    /** @param array<string,mixed> $response */
    private function storeFailure(string $key, array $response, string $status): void
    {
        $this->operations->update($key, [
            'status' => $status,
            'http_code' => (int) $response['http_code'],
            'error_code' => (string) $response['error_code'],
            'error_message' => (string) $response['error_message'],
        ]);
    }

    /** @param array<string,mixed> $body */
    private function storeReceiptState(string $key, array $body, int $http_code): void
    {
        $status = strtoupper((string) ($body['status'] ?? ''));
        $fiscal_code = (string) ($body['fiscal_code'] ?? '');
        $is_sent = ($body['is_sent_dps'] ?? null) === true;
        $complete = $is_sent || in_array($status, ['DONE', 'FISCALIZED'], true);
        $failed = in_array($status, ['ERROR', 'CANCELLED', 'CANCELLATION'], true);
        $this->operations->update($key, [
            'status' => $complete ? 'SUCCEEDED' : ($failed ? 'FAILED' : 'PROCESSING'),
            'checkbox_receipt_id' => (string) ($body['id'] ?? ''),
            'fiscal_code' => $fiscal_code,
            'shift_id' => is_array($body['shift'] ?? null) ? (string) ($body['shift']['id'] ?? '') : (string) ($body['shift_id'] ?? ''),
            'transaction_id' => isset($body['transaction_id'])
                ? (string) $body['transaction_id']
                : (is_array($body['transaction'] ?? null) ? (string) ($body['transaction']['id'] ?? '') : ''),
            'http_code' => $http_code,
            'error_code' => $failed ? 'checkbox_receipt_' . strtolower($status) : null,
            'error_message' => $failed ? 'Checkbox returned terminal receipt status ' . $status . '.' : null,
        ]);
    }

    /** @param array<string,mixed> $response */
    private function apiError(array $response, string $fallback, string $stage = ''): \WP_Error
    {
        $status = (int) $response['http_code'];
        if ($status < 400 || $status > 599) {
            $status = 502;
        }
        return new \WP_Error(
            (string) ($response['error_code'] ?: 'checkbox_error'),
            (string) ($response['error_message'] ?: $fallback),
            ['status' => $status, 'http_code'=>(int) $response['http_code'], 'stage'=>$stage]
        );
    }

    /** @param array<string,mixed> $operation @return array<string,mixed> */
    private function result(array $operation, bool $success = false, bool $replayed = false): array
    {
        return [
            'success' => $success || in_array($operation['status'], ['SUCCEEDED', 'FISCALIZED'], true),
            'replayed' => $replayed,
            'operation_key' => (string) $operation['operation_key'],
            'receipt_id' => (string) $operation['receipt_uuid'],
            'status' => (string) $operation['status'],
            'mode' => (string) $operation['mode'],
            'source' => [
                'system' => (string) $operation['source_system'],
                'entity_type' => (string) $operation['source_type'],
                'entity_id' => (string) $operation['source_id'],
            ],
            'operation_type' => (string) $operation['operation_type'],
            'total_cents' => (int) $operation['total_cents'],
            'checkbox_receipt_id' => (string) ($operation['checkbox_receipt_id'] ?? ''),
            'fiscal_code' => (string) ($operation['fiscal_code'] ?? ''),
            'attempts' => (int) $operation['attempts'],
            'http_code' => (int) ($operation['http_code'] ?? 0),
            'error_code' => (string) ($operation['error_code'] ?? ''),
            'error_message' => (string) ($operation['error_message'] ?? ''),
            'shift_id' => (string) ($operation['shift_id'] ?? ''),
            'updated_at' => (string) $operation['updated_at'],
        ];
    }

    /** @param array<string,mixed> $body @return list<array<string,mixed>> */
    private function safeTaxes(array $body): array
    {
        $rows = isset($body['results']) && is_array($body['results']) ? $body['results'] : $body;
        $safe = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $safe[] = [
                'code' => $row['code'] ?? '',
                'label' => (string) ($row['label'] ?? $row['name'] ?? ''),
                'symbol' => (string) ($row['symbol'] ?? ''),
            ];
        }
        return array_slice($safe, 0, 50);
    }
}
