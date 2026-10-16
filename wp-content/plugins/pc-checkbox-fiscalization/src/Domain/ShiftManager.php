<?php

namespace Paint\CheckboxFiscalization\Domain;

use Paint\CheckboxFiscalization\Config;
use Paint\CheckboxFiscalization\Infrastructure\ApiClient;
use Paint\CheckboxFiscalization\Infrastructure\OperationRepository;

defined('ABSPATH') || exit;

/** Shared manual/automatic opening journal. Never closes shifts or creates receipts. */
final class ShiftManager
{
    public function __construct(private Config $config, private ApiClient $api, private OperationRepository $locks) {}

    public function preview(): array|\WP_Error
    {
        $auth = $this->api->signIn();
        if (!$auth['ok']) return $this->failure($auth, 'shift.auth');
        $context = $this->context((string) $auth['access_token']);
        if (is_wp_error($context)) return $context;
        $state = $this->api->activeShift((string) $auth['access_token']);
        if (!$state['ok']) return $this->failure($state, 'shift.lookup');
        $context['shift_status'] = (string) ($state['body']['status'] ?? 'CLOSED');
        $context['last_attempt'] = get_option($this->journalKey(), []);
        return $context;
    }

    public function openConfirmed(string $fingerprint): array|\WP_Error
    {
        $auth = $this->api->signIn();
        if (!$auth['ok']) return $this->failure($auth, 'shift.auth');
        return $this->ensure((string) $auth['access_token'], true, $fingerprint);
    }

    public function ensure(string $token, bool $allowOpen, ?string $fingerprint = null): array|\WP_Error
    {
        if (!$this->config->fiscalizationEnabled()) return new \WP_Error('fiscalization_locked', 'Fiscal operations are locked.', ['status'=>403]);
        $key = $this->journalKey();
        if (!$this->locks->lock($key)) return new \WP_Error('shift_busy', 'Another shift request is in progress.', ['status'=>409]);
        try {
            $context = $this->context($token);
            if (is_wp_error($context)) return $context;
            if ($fingerprint !== null && !hash_equals($context['fingerprint'], $fingerprint)) {
                return new \WP_Error('shift_preview_changed', 'Cashier, register or configuration changed. Preview again.', ['status'=>409]);
            }
            $saved = get_option($key, []);
            if (($saved['status'] ?? '') === 'PENDING') {
                return $this->resolve($token, $saved, $context);
            }
            $active = $this->api->activeShift($token);
            if (!$active['ok']) return $this->failure($active, 'shift.lookup');
            $body = $active['body']['results'][0] ?? $active['body'];
            $status = strtoupper((string) ($body['status'] ?? 'CLOSED'));
            if ($status === 'OPENED') {
                if (empty($body['id']) || ($body['cash_register']['id'] ?? '') !== $context['register_id']) {
                    return new \WP_Error('shift_register_mismatch', 'The cashier has a shift on another register. No opening or receipt was sent.', ['status'=>409]);
                }
                return ['shift_id'=>(string) $body['id'], 'status'=>'OPENED', 'replayed'=>true];
            }
            if ($status !== 'CLOSED') return new \WP_Error('shift_transition_pending', 'The shift is changing state. Check again later.', ['status'=>409]);
            if ($context['register_has_shift']) return new \WP_Error('register_has_shift', 'The register already has a shift, but it is not the current cashier shift. Check Checkbox before opening.', ['status'=>409]);
            if (!$allowOpen) return new \WP_Error('open_shift_required', 'An opened Checkbox shift is required.', ['status'=>409]);
            $saved = ['shift_id'=>wp_generate_uuid4(), 'status'=>'PENDING', 'updated_at'=>gmdate('c'), 'error_code'=>'', 'error_message'=>''];
            $this->save($saved); // Must succeed before POST, including process-crash recovery.
            $response = $this->api->openShift($token, $saved['shift_id']);
            if (!$response['ok']) {
                $http = (int) $response['http_code'];
                $uncertain = !empty($response['transport_error']) || $http >= 500 || in_array($http, [408,409,425,429], true);
                $saved['status'] = $uncertain ? 'PENDING' : 'REJECTED';
                $saved['http_code'] = $http;
                $saved['error_code'] = (string) $response['error_code'];
                $saved['error_message'] = '[shift.open] ' . $this->api->safeMessage((string) $response['error_message']);
                $this->save($saved);
                return $this->failure($response, 'shift.open', $saved['shift_id']);
            }
            return $this->resolve($token, $saved, $context);
        } catch (\Throwable) {
            return new \WP_Error('shift_storage_error', 'Shift processing was interrupted. Check the saved attempt before retrying.', ['status'=>500]);
        } finally {
            $this->locks->unlock($key);
        }
    }

    private function context(string $token): array|\WP_Error
    {
        $profile = $this->api->cashierProfile($token);
        if (!$profile['ok']) return $this->failure($profile, 'shift.profile');
        $register = $this->api->registerInfo($token);
        if (!$register['ok']) return $this->failure($register, 'shift.register');
        $cashier = $profile['body']; $cash = $register['body'];
        $environment = $this->api->environment($cashier);
        if (!in_array($environment, ['test','live'], true) || $environment !== $this->api->environment($cash)
            || empty($cash['id']) || empty($cashier['id']) || empty($cashier['organization']['id'])
            || ($cashier['organization']['id'] !== ($cash['organization_id'] ?? null))) {
            return new \WP_Error('shift_identity_unverified', 'Register, cashier environment or organization cannot be verified.', ['status'=>409]);
        }
        if ($environment === 'live' && !$this->config->liveEnabled()) return new \WP_Error('live_fiscalization_locked', 'Live fiscal operations are locked.', ['status'=>403]);
        return [
            'environment'=>$environment, 'organization_id'=>(string) $cashier['organization']['id'],
            'cashier_id'=>(string) $cashier['id'], 'cashier_name'=>(string) ($cashier['full_name'] ?? ''),
            'register_id'=>(string) $cash['id'], 'fiscal_number'=>(string) ($cash['fiscal_number'] ?? ''),
            'register_has_shift'=>!empty($cash['has_shift']),
            'fingerprint'=>hash('sha256', $this->config->licenseKey() . ':' . $this->config->cashierPin() . ':' . $cashier['id'] . ':' . $cash['id'] . ':' . $environment),
        ];
    }

    private function resolve(string $token, array $saved, array $context): array|\WP_Error
    {
        $response = $this->api->getShift($token, $saved['shift_id']);
        if (!$response['ok']) {
            $saved['http_code'] = (int) $response['http_code'];
            $saved['error_code'] = (string) $response['error_code'];
            $saved['error_message'] = '[shift.status] ' . $this->api->safeMessage((string) $response['error_message']);
            $this->save($saved);
            return $this->failure($response, 'shift.status', $saved['shift_id']);
        }
        $body = $response['body'];
        $status = strtoupper((string) ($body['status'] ?? ''));
        if ($status === 'OPENED' && ($body['cash_register']['id'] ?? '') === $context['register_id']) {
            $this->save(array_merge($saved, ['status'=>'OPENED','error_code'=>'','error_message'=>'']));
            return ['shift_id'=>$saved['shift_id'], 'status'=>'OPENED', 'replayed'=>true];
        }
        // CLOSED is terminal; never automatically open another shift after a failed attempt.
        $message = $status === 'CLOSED' ? 'Shift opening ended CLOSED. Check the opening transaction with Checkbox support.' : 'Opening is not confirmed. Check again; no new opening request will be sent.';
        $transaction = $body['initial_transaction'] ?? [];
        if (is_array($transaction) && is_string($transaction['response_error_message'] ?? null)) $message .= ' ' . $transaction['response_error_message'];
        $saved['error_message'] = '[shift.status] ' . $this->api->safeMessage($message);
        $saved['error_code'] = $status === 'CLOSED' ? 'shift_open_failed' : 'shift_open_pending';
        $this->save($saved);
        return new \WP_Error($saved['error_code'], $saved['error_message'], ['status'=>409,'stage'=>'shift.status','shift_id'=>$saved['shift_id']]);
    }

    private function journalKey(): string { return 'pccf_shift_' . hash('sha256', $this->config->licenseKey()); }

    private function save(array $state): void
    {
        $state['updated_at'] = gmdate('c');
        update_option($this->journalKey(), $state, false);
        if (get_option($this->journalKey()) !== $state) throw new \RuntimeException('Shift journal write failed.');
    }

    private function failure(array $response, string $stage, string $shift = ''): \WP_Error
    {
        return new \WP_Error($response['error_code'] ?: 'checkbox_error', '[' . $stage . '] ' . $this->api->safeMessage($response['error_message'] ?: 'Checkbox request failed.'), [
            'status'=>(int) $response['http_code'] >= 400 ? (int) $response['http_code'] : 502,
            'http_code'=>(int) $response['http_code'], 'stage'=>$stage, 'shift_id'=>$shift,
        ]);
    }
}
