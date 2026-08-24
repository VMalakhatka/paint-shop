<?php

namespace Paint\CheckboxFiscalization\Http;

use Paint\CheckboxFiscalization\Config;
use Paint\CheckboxFiscalization\Domain\FiscalizationService;
use Paint\CheckboxFiscalization\Integration\JavaCommandProvider;

defined('ABSPATH') || exit;

final class RestController
{
    public function __construct(
        private Config $config,
        private FiscalizationService $service,
        private JavaCommandProvider $java
    ) {
    }

    public function register(): void
    {
        register_rest_route('pc-checkbox/v1', '/fiscalize', [
            'methods' => 'POST',
            'callback' => [$this, 'fiscalize'],
            'permission_callback' => [$this, 'authorize'],
        ]);
        register_rest_route('pc-checkbox/v1', '/fiscalize-source', [
            'methods' => 'POST',
            'callback' => [$this, 'fiscalizeSource'],
            'permission_callback' => [$this, 'authorize'],
        ]);
        register_rest_route('pc-checkbox/v1', '/operation', [
            'methods' => 'GET',
            'callback' => [$this, 'operation'],
            'permission_callback' => [$this, 'authorize'],
        ]);
        register_rest_route('pc-checkbox/v1', '/reconcile', [
            'methods' => 'POST',
            'callback' => [$this, 'reconcile'],
            'permission_callback' => [$this, 'authorize'],
        ]);
    }

    public function authorize(\WP_REST_Request $request): bool|\WP_Error
    {
        if (current_user_can('manage_pc_checkbox_fiscalization')) {
            return true;
        }
        $expected = $this->config->inboundToken();
        $provided = trim((string) $request->get_header('X-PC-Checkbox-Token'));
        if ($expected !== '' && $provided !== '' && hash_equals($expected, $provided)) {
            return true;
        }
        return new \WP_Error('rest_forbidden', 'A valid fiscalization token is required.', ['status' => 401]);
    }

    public function fiscalize(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $body = $request->get_json_params();
        if (!is_array($body)) {
            return new \WP_Error('invalid_json', 'A JSON object is required.', ['status' => 400]);
        }
        $mode = (string) ($body['mode'] ?? 'preview');
        $command = isset($body['command']) && is_array($body['command']) ? $body['command'] : $body;
        unset($command['mode']);
        return $this->respond($this->service->execute($command, $mode));
    }

    public function fiscalizeSource(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $body = $request->get_json_params();
        if (!is_array($body)) {
            return new \WP_Error('invalid_json', 'A JSON object is required.', ['status' => 400]);
        }
        $command = $this->java->fetch((string) ($body['source_type'] ?? ''), (string) ($body['source_id'] ?? ''));
        if (is_wp_error($command)) {
            return $command;
        }
        return $this->respond($this->service->execute($command, (string) ($body['mode'] ?? 'preview')));
    }

    public function operation(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $key = trim((string) $request->get_param('operation_key'));
        $operation = $key === '' ? null : $this->service->operation($key);
        if (!$operation) {
            return new \WP_Error('operation_not_found', 'Fiscalization operation not found.', ['status' => 404]);
        }
        return new \WP_REST_Response($operation, 200);
    }

    public function reconcile(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $body = $request->get_json_params();
        $key = is_array($body) ? trim((string) ($body['operation_key'] ?? '')) : '';
        if ($key === '') {
            return new \WP_Error('operation_key_required', 'operation_key is required.', ['status' => 400]);
        }
        return $this->respond($this->service->reconcile($key));
    }

    /** @param array<string,mixed>|\WP_Error $result */
    private function respond(array|\WP_Error $result): \WP_REST_Response|\WP_Error
    {
        if (is_wp_error($result)) {
            return $result;
        }
        return new \WP_REST_Response($result, $result['status'] === 'PROCESSING' ? 202 : 200);
    }
}
