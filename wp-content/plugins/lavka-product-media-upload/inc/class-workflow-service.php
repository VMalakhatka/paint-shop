<?php

namespace Lavka\ProductMediaUpload;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Coordinates the post-validation path across Media Cloud, S3 index, Folio and Woo.
 */
final class WorkflowService
{
    private const LOCK_TTL = 2 * HOUR_IN_SECONDS;
    private const S3_PROOF_ATTEMPTS = 2;

    private MediaUploader $uploader;
    private bool $s3_table_checked = false;
    private string $s3_table = '';

    public function __construct()
    {
        $this->uploader = new MediaUploader();
    }

    public function acquire_lock(string $batch_id): array
    {
        if (
            !function_exists('lavka_ecosystem_lock_acquire')
            || !function_exists('lavka_ecosystem_lock_touch')
            || !function_exists('lavka_ecosystem_lock_release')
        ) {
            return [
                'ok' => false,
                'token' => null,
                'lock' => null,
                'message' => __('The shared Lavka synchronization lock is unavailable. The upload was not started.', 'lavka-product-media-upload'),
            ];
        }

        return lavka_ecosystem_lock_acquire(
            'lavka-product-media-upload',
            'product_media_batch',
            'manual',
            __('Product image batch upload', 'lavka-product-media-upload'),
            self::LOCK_TTL,
            ['batch_id' => $batch_id]
        );
    }

    public function release_lock(?string $token): void
    {
        if ($token && function_exists('lavka_ecosystem_lock_release')) {
            lavka_ecosystem_lock_release($token);
        }
    }

    public function touch_lock(?string $token, array $progress = []): void
    {
        if ($token && function_exists('lavka_ecosystem_lock_touch')) {
            lavka_ecosystem_lock_touch($token, self::LOCK_TTL, ['progress' => $progress]);
        }
    }

    /**
     * Completes all uploaded rows. Failures remain resumable on the next identical batch.
     */
    public function complete(
        array $rows,
        string $batch_id,
        string $manifest_hash,
        ?string $lock_token
    ): array {
        $uploaded_indexes = [];
        foreach ($rows as $index => $row) {
            if (($row['status'] ?? '') === 'UPLOADED' && (int) ($row['attachment_id'] ?? 0) > 0) {
                $uploaded_indexes[] = $index;
            }
        }
        if (!$uploaded_indexes) {
            return $rows;
        }

        $this->touch_lock($lock_token, [
            'stage' => 's3_reindex',
            'uploaded' => count($uploaded_indexes),
        ]);
        $reindex = $this->java_request_with_retry('POST', '/admin/media/reindex', []);
        if (!$this->java_success($reindex)) {
            return $this->fail_indexes(
                $rows,
                $uploaded_indexes,
                'S3_INDEX_FAILED',
                __('The OVH/S3 media index could not be refreshed after upload. The files remain available for a safe retry.', 'lavka-product-media-upload'),
                $this->java_technical($reindex),
                's3_reindex'
            );
        }

        $pending_proofs = array_fill_keys($uploaded_indexes, true);
        $proof_failures = [];
        $retry_reindex_failure = null;
        for ($attempt = 1; $attempt <= self::S3_PROOF_ATTEMPTS && $pending_proofs; $attempt++) {
            foreach (array_keys($pending_proofs) as $index) {
                $proof = $this->s3_proof($rows[$index]);
                if (!$proof['ok']) {
                    $proof_failures[$index] = $proof;
                    continue;
                }
                $rows[$index]['_s3_proof'] = $proof['proof'];
                $rows[$index]['workflow_stage'] = 's3_verified';
                unset($pending_proofs[$index], $proof_failures[$index]);
            }

            if (!$pending_proofs || $attempt === self::S3_PROOF_ATTEMPTS) {
                break;
            }

            $this->touch_lock($lock_token, [
                'stage' => 's3_reindex_retry',
                'pending' => count($pending_proofs),
            ]);
            sleep(2);
            $retry_reindex = $this->java_request_with_retry('POST', '/admin/media/reindex', []);
            if (!$this->java_success($retry_reindex)) {
                $retry_reindex_failure = $retry_reindex;
                break;
            }
        }

        foreach (array_keys($pending_proofs) as $index) {
            if ($retry_reindex_failure !== null) {
                $rows[$index] = $this->fail_row(
                    $rows[$index],
                    'S3_INDEX_FAILED',
                    __('The OVH/S3 media index could not be refreshed during the verification retry.', 'lavka-product-media-upload'),
                    $this->java_technical($retry_reindex_failure),
                    's3_reindex_retry'
                );
                continue;
            }

            $failure = $proof_failures[$index] ?? [];
            $rows[$index] = $this->fail_row(
                $rows[$index],
                'S3_PROOF_FAILED',
                (string) ($failure['message'] ?? __('The uploaded object could not be confirmed in the refreshed OVH/S3 index.', 'lavka-product-media-upload')),
                (string) ($failure['technical'] ?? ''),
                's3_proof'
            );
        }

        $groups = [];
        foreach ($uploaded_indexes as $index) {
            if (empty($rows[$index]['_s3_proof'])) {
                continue;
            }
            $groups[(string) ($rows[$index]['sku'] ?? '')][] = $index;
        }

        $processed = 0;
        foreach ($groups as $sku => $indexes) {
            $processed++;
            $this->touch_lock($lock_token, [
                'stage' => 'folio',
                'sku' => $sku,
                'processed_skus' => $processed,
                'total_skus' => count($groups),
            ]);
            $rows = $this->complete_sku($rows, $indexes, $batch_id, $manifest_hash);
        }

        foreach ($rows as &$row) {
            unset($row['_s3_proof']);
        }
        unset($row);

        return $rows;
    }

    private function complete_sku(
        array $rows,
        array $indexes,
        string $batch_id,
        string $manifest_hash
    ): array {
        $sku = (string) ($rows[$indexes[0]]['sku'] ?? '');
        if ($sku === '') {
            return $this->fail_indexes(
                $rows,
                $indexes,
                'FOLIO_SEARCH_FAILED',
                __('Folio synchronization requires an exact SKU.', 'lavka-product-media-upload'),
                '',
                'folio_search'
            );
        }

        $search = $this->java_request_with_retry('GET', '/admin/folio/product-media', [
            'sku' => $sku,
            'role' => 'all',
            'match' => 'exact',
            'limit' => 200,
            'offset' => 0,
        ]);
        if (!$this->java_success($search)) {
            return $this->fail_indexes(
                $rows,
                $indexes,
                'FOLIO_SEARCH_FAILED',
                __('The current Folio image references could not be read. WooCommerce assignment was not changed.', 'lavka-product-media-upload'),
                $this->java_technical($search),
                'folio_search'
            );
        }

        $items = isset($search['json']['items']) && is_array($search['json']['items'])
            ? array_values(array_filter($search['json']['items'], 'is_array'))
            : [];
        $total_items = isset($search['json']['total']) ? (int) $search['json']['total'] : count($items);
        if ($total_items > count($items)) {
            return $this->fail_indexes(
                $rows,
                $indexes,
                'FOLIO_SEARCH_FAILED',
                __('Folio returned more image rows than can be checked safely in one exact search.', 'lavka-product-media-upload'),
                'total=' . $total_items . '; returned=' . count($items),
                'folio_search'
            );
        }
        $built = $this->build_changes($rows, $indexes, $items);
        if (!$built['ok']) {
            return $this->fail_indexes(
                $rows,
                $indexes,
                'FOLIO_PREVIEW_BLOCKED',
                $built['message'],
                $built['technical'] ?? '',
                'folio_prepare'
            );
        }

        $external_request_id = 'woo-media-upload:' . $batch_id . ':' . substr(hash('sha256', $sku), 0, 16);
        $folio_status_by_row = $built['noop_rows'];
        foreach ($built['operations'] as $row_index => $operation) {
            $rows[$row_index]['folio_operation'] = $operation;
        }
        if ($built['changes']) {
            $preview_payload = [
                'externalRequestId' => $external_request_id,
                'previewOnly' => true,
                'source' => 'woo_product_media_upload',
                'changes' => $built['changes'],
            ];
            $preview = $this->java_request_with_retry('POST', '/admin/folio/product-media/changes', $preview_payload);
            $preview_check = $this->validate_change_response($preview, ['ready', 'noop'], count($built['changes']));
            if (!$preview_check['ok']) {
                return $this->fail_indexes(
                    $rows,
                    $indexes,
                    'FOLIO_PREVIEW_BLOCKED',
                    __('Folio refused the preview. No WooCommerce image assignment was changed.', 'lavka-product-media-upload'),
                    $preview_check['technical'],
                    'folio_preview'
                );
            }

            $apply_payload = $preview_payload;
            $apply_payload['previewOnly'] = false;
            $apply = $this->java_request_with_retry('POST', '/admin/folio/product-media/changes', $apply_payload);
            $apply_check = $this->validate_change_response($apply, ['applied', 'noop'], count($built['changes']));
            if (!$apply_check['ok']) {
                return $this->fail_indexes(
                    $rows,
                    $indexes,
                    'FOLIO_APPLY_FAILED',
                    __('Folio did not apply the approved image references. The uploaded files remain available for retry.', 'lavka-product-media-upload'),
                    $apply_check['technical'],
                    'folio_apply'
                );
            }

            foreach ($built['change_rows'] as $change_index => $row_index) {
                $folio_status_by_row[$row_index] = $apply_check['statuses'][$change_index] ?? 'applied';
            }
        }

        usort($indexes, static function (int $left, int $right) use ($rows): int {
            $left_role = (string) ($rows[$left]['role'] ?? '');
            $right_role = (string) ($rows[$right]['role'] ?? '');
            if ($left_role !== $right_role) {
                return $left_role === 'main' ? -1 : 1;
            }
            return (int) ($rows[$left]['position'] ?? 0) <=> (int) ($rows[$right]['position'] ?? 0);
        });

        foreach ($indexes as $index) {
            $status = (string) ($folio_status_by_row[$index] ?? 'noop');
            $rows[$index]['workflow_stage'] = 'woo_assignment';
            $rows[$index]['folio_status'] = $status;
            $rows[$index] = $this->uploader->finalize(
                $rows[$index],
                $batch_id,
                $manifest_hash,
                $status,
                $external_request_id
            );
        }

        return $rows;
    }

    private function build_changes(array $rows, array $indexes, array $items): array
    {
        $main_items = array_values(array_filter($items, static fn(array $item): bool => ($item['role'] ?? '') === 'main'));
        $gallery_items = array_values(array_filter($items, static fn(array $item): bool => ($item['role'] ?? '') === 'gallery'));
        if (count($main_items) > 1) {
            return $this->build_failure(__('Folio returned more than one main-image record for the SKU.', 'lavka-product-media-upload'));
        }

        $changes = [];
        $change_rows = [];
        $noop_rows = [];
        $operations = [];
        $used_record_ids = [];
        foreach ($indexes as $index) {
            $row = $rows[$index];
            $filename = (string) ($row['canonical_file'] ?? '');
            $proof = (array) ($row['_s3_proof'] ?? []);
            if ($filename === '' || !$proof) {
                return $this->build_failure(__('The Folio change is missing an exact filename or S3 proof.', 'lavka-product-media-upload'));
            }

            if (($row['role'] ?? '') === 'main') {
                $operations[$index] = 'set_main';
                $current = $main_items[0] ?? null;
                $old_filename = is_array($current) && array_key_exists('filename', $current)
                    ? $current['filename']
                    : null;
                if ((string) $old_filename === $filename) {
                    $noop_rows[$index] = 'noop';
                    continue;
                }
                $changes[] = [
                    'operation' => 'set_main',
                    'sku' => (string) $row['sku'],
                    'expectedOldFilename' => $old_filename,
                    'filename' => $filename,
                    's3Proof' => $proof,
                ];
                $change_rows[] = $index;
                continue;
            }

            if (($row['role'] ?? '') !== 'gallery') {
                return $this->build_failure(__('The Folio image role is unsupported.', 'lavka-product-media-upload'));
            }

            $position = max(1, (int) ($row['position'] ?? 1));
            $candidate = null;
            $same_file = array_values(array_filter($gallery_items, static function (array $item) use ($filename): bool {
                return (string) ($item['filename'] ?? '') === $filename;
            }));
            if (count($same_file) > 1) {
                return $this->build_failure(__('Folio contains duplicate gallery rows for the target filename.', 'lavka-product-media-upload'));
            }
            if (count($same_file) === 1) {
                $candidate = $same_file[0];
                if ((int) ($candidate['sortOrder'] ?? 0) === $position) {
                    $operations[$index] = 'update_gallery';
                    $noop_rows[$index] = 'noop';
                    continue;
                }
            } else {
                $same_position = array_values(array_filter($gallery_items, static function (array $item) use ($position): bool {
                    return isset($item['sortOrder']) && (int) $item['sortOrder'] === $position;
                }));
                if (count($same_position) > 1) {
                    return $this->build_failure(__('Folio contains more than one gallery row at the requested position.', 'lavka-product-media-upload'));
                }
                $candidate = $same_position[0] ?? null;
            }

            if (is_array($candidate ?? null)) {
                $record_id = $candidate['recordId'] ?? null;
                if (is_array($record_id)) {
                    $record_id = $record_id['key'] ?? null;
                }
                if ($record_id === null || $record_id === '') {
                    return $this->build_failure(__('The Folio gallery row has no stable record ID.', 'lavka-product-media-upload'));
                }
                $record_id = (string) $record_id;
                if (isset($used_record_ids[$record_id])) {
                    return $this->build_failure(
                        __('More than one uploaded image would update the same Folio gallery row. Adjust the gallery positions and check the batch again.', 'lavka-product-media-upload'),
                        'record_id=' . $record_id
                    );
                }
                $used_record_ids[$record_id] = true;
                $operations[$index] = 'update_gallery';
                $changes[] = [
                    'operation' => 'update_gallery',
                    'sku' => (string) $row['sku'],
                    'recordId' => $record_id,
                    'expectedOldFilename' => $candidate['filename'] ?? null,
                    'expectedOldSortOrder' => $candidate['sortOrder'] ?? null,
                    'filename' => $filename,
                    'sortOrder' => $position,
                    's3Proof' => $proof,
                ];
            } else {
                $operations[$index] = 'add_gallery';
                $changes[] = [
                    'operation' => 'add_gallery',
                    'sku' => (string) $row['sku'],
                    'filename' => $filename,
                    'sortOrder' => $position,
                    's3Proof' => $proof,
                ];
            }
            $change_rows[] = $index;
        }

        return [
            'ok' => true,
            'changes' => $changes,
            'change_rows' => $change_rows,
            'noop_rows' => $noop_rows,
            'operations' => $operations,
        ];
    }

    private function s3_proof(array $row): array
    {
        global $wpdb;

        $table = $this->s3_index_table();
        $filename = mb_strtolower((string) ($row['canonical_file'] ?? ''), 'UTF-8');
        $relative_key = ltrim(str_replace('\\', '/', (string) ($row['s3_key'] ?? '')), '/');
        if ($table === '' || $filename === '' || $relative_key === '') {
            return [
                'ok' => false,
                'message' => __('The uploaded object is missing from the current S3 proof context.', 'lavka-product-media-upload'),
                'technical' => 'table=' . $table . '; filename=' . $filename . '; key=' . $relative_key,
            ];
        }

        $records = $wpdb->get_results($wpdb->prepare(
            "SELECT filename_lower, full_key, size_bytes, etag
             FROM `{$table}`
             WHERE filename_lower = %s
             ORDER BY last_modified DESC, id DESC",
            $filename
        ), ARRAY_A);
        if (!is_array($records) || !$records) {
            return [
                'ok' => false,
                'message' => __('The uploaded filename was not found in the refreshed OVH/S3 media index.', 'lavka-product-media-upload'),
                'technical' => $filename,
            ];
        }

        $expected_keys = array_values(array_unique([
            $relative_key,
            'wp-content/uploads/' . preg_replace('~^wp-content/uploads/~', '', $relative_key),
        ]));
        $matches = array_values(array_filter($records, static function (array $record) use ($expected_keys, $relative_key): bool {
            $full_key = ltrim(str_replace('\\', '/', (string) ($record['full_key'] ?? '')), '/');
            return in_array($full_key, $expected_keys, true)
                || str_ends_with($full_key, '/' . ltrim($relative_key, '/'));
        }));
        if (count($matches) !== 1) {
            return [
                'ok' => false,
                'message' => __('The refreshed S3 index does not identify exactly one object for the uploaded attachment.', 'lavka-product-media-upload'),
                'technical' => wp_json_encode(array_column($records, 'full_key'), JSON_UNESCAPED_SLASHES),
            ];
        }

        $match = $matches[0];
        if ((int) ($match['size_bytes'] ?? 0) < 1 || (string) ($match['etag'] ?? '') === '') {
            return [
                'ok' => false,
                'message' => __('The S3 index row has no valid size or ETag and cannot be used as Folio proof.', 'lavka-product-media-upload'),
                'technical' => (string) ($match['full_key'] ?? ''),
            ];
        }

        return [
            'ok' => true,
            'proof' => [
                'fullKey' => (string) $match['full_key'],
                'sizeBytes' => (int) ($match['size_bytes'] ?? 0),
                'etag' => (string) $match['etag'],
            ],
        ];
    }

    private function s3_index_table(): string
    {
        if ($this->s3_table_checked) {
            return $this->s3_table;
        }
        $this->s3_table_checked = true;

        global $wpdb;
        foreach (array_values(array_unique([$wpdb->prefix . 's3_media_index', 's3_media_index'])) as $candidate) {
            $found = (string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($candidate)));
            if ($found === $candidate) {
                $this->s3_table = $candidate;
                break;
            }
        }
        return $this->s3_table;
    }

    private function validate_change_response(array $response, array $allowed, int $expected): array
    {
        if (!$this->java_success($response)) {
            return ['ok' => false, 'statuses' => [], 'technical' => $this->java_technical($response)];
        }
        $results = isset($response['json']['results']) && is_array($response['json']['results'])
            ? array_values($response['json']['results'])
            : [];
        if (count($results) !== $expected) {
            return [
                'ok' => false,
                'statuses' => [],
                'technical' => 'expected_results=' . $expected . '; actual_results=' . count($results),
            ];
        }
        $statuses = array_fill(0, $expected, null);
        foreach ($results as $fallback_index => $result) {
            if (!is_array($result)) {
                return [
                    'ok' => false,
                    'statuses' => [],
                    'technical' => 'invalid_result_at=' . $fallback_index,
                ];
            }
            $index = array_key_exists('index', $result) ? (int) $result['index'] : $fallback_index;
            if ($index < 0 || $index >= $expected || $statuses[$index] !== null) {
                return [
                    'ok' => false,
                    'statuses' => [],
                    'technical' => 'invalid_or_duplicate_result_index=' . $index,
                ];
            }
            $status = sanitize_key((string) ($result['status'] ?? ''));
            $statuses[$index] = $status;
            if (!in_array($status, $allowed, true)) {
                return [
                    'ok' => false,
                    'statuses' => $statuses,
                    'technical' => wp_json_encode($response['json'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ];
            }
        }
        if (in_array(null, $statuses, true)) {
            return ['ok' => false, 'statuses' => [], 'technical' => 'missing_result_index'];
        }
        return ['ok' => true, 'statuses' => $statuses, 'technical' => ''];
    }

    private function java_request(string $method, string $path, array $data): array
    {
        if (function_exists('lts_call_java_media_request')) {
            return lts_call_java_media_request($method, $path, $data);
        }

        $options = function_exists('lts_get_options')
            ? lts_get_options()
            : get_option('lts_options', []);
        $options = is_array($options) ? $options : [];
        $base = rtrim((string) ($options['java_base_url'] ?? $options['base_url'] ?? ''), '/');
        if ($base === '') {
            return ['ok' => false, 'error' => 'java_base_url_missing'];
        }

        $method = strtoupper($method);
        $url = $base . '/' . ltrim($path, '/');
        $headers = [
            'Accept' => 'application/json',
            'User-Agent' => 'Lavka-Product-Media-Upload/' . (defined('LPMU_VERSION') ? LPMU_VERSION : 'dev'),
        ];
        if (!empty($options['api_token'])) {
            $headers['Authorization'] = 'Bearer ' . $options['api_token'];
        }
        $args = [
            'method' => $method,
            'timeout' => max(300, (int) ($options['timeout'] ?? 160)),
            'headers' => $headers,
        ];
        if ($method === 'GET') {
            $url = add_query_arg($data, $url);
        } else {
            $args['headers']['Content-Type'] = 'application/json; charset=utf-8';
            $args['body'] = wp_json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        $response = wp_remote_request($url, $args);
        if (is_wp_error($response)) {
            return ['ok' => false, 'error' => $response->get_error_message()];
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        $raw = (string) wp_remote_retrieve_body($response);
        $json = json_decode($raw, true);
        if ($code < 200 || $code >= 300) {
            return ['ok' => false, 'http' => $code, 'raw' => mb_substr($raw, 0, 4000)];
        }
        if (!is_array($json)) {
            return ['ok' => false, 'http' => $code, 'error' => 'invalid_json', 'raw' => mb_substr($raw, 0, 4000)];
        }
        return ['ok' => true, 'http' => $code, 'json' => $json];
    }

    /** Retry only transport and temporary HTTP failures; logical refusals are final. */
    private function java_request_with_retry(string $method, string $path, array $data, int $attempts = 2): array
    {
        $attempts = max(1, min(3, $attempts));
        $response = [];
        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $response = $this->java_request($method, $path, $data);
            if (!$this->should_retry_java($response) || $attempt === $attempts) {
                return $response;
            }
            sleep($attempt);
        }
        return $response;
    }

    private function should_retry_java(array $response): bool
    {
        if (!empty($response['ok'])) {
            return false;
        }
        if (!isset($response['http'])) {
            return true;
        }
        return in_array((int) $response['http'], [408, 425, 429], true)
            || (int) $response['http'] >= 500;
    }

    private function java_success(array $response): bool
    {
        return !empty($response['ok'])
            && isset($response['json'])
            && is_array($response['json'])
            && (!array_key_exists('ok', $response['json']) || !empty($response['json']['ok']));
    }

    private function java_technical(array $response): string
    {
        if (!empty($response['json']) && is_array($response['json'])) {
            return (string) wp_json_encode($response['json'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        return trim(implode('; ', array_filter([
            isset($response['http']) ? 'HTTP ' . (int) $response['http'] : '',
            (string) ($response['error'] ?? ''),
            (string) ($response['raw'] ?? ''),
        ])));
    }

    private function fail_indexes(
        array $rows,
        array $indexes,
        string $status,
        string $message,
        string $technical,
        string $stage
    ): array {
        foreach ($indexes as $index) {
            $rows[$index] = $this->fail_row($rows[$index], $status, $message, $technical, $stage);
        }
        return $rows;
    }

    private function fail_row(
        array $row,
        string $status,
        string $message,
        string $technical,
        string $stage
    ): array {
        $row['valid'] = false;
        $row['status'] = $status;
        $row['workflow_stage'] = $stage;
        $row['errors'] = array_values(array_unique(array_merge((array) ($row['errors'] ?? []), [$message])));
        $row['technical'] = $technical;
        return $row;
    }

    private function build_failure(string $message, string $technical = ''): array
    {
        return ['ok' => false, 'message' => $message, 'technical' => $technical];
    }
}
