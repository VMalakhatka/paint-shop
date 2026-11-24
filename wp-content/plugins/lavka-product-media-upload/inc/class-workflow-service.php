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

    public function preview_main_repair(string $sku): array
    {
        $target = $this->main_repair_target($sku);
        $search = $this->java_request('GET', '/admin/folio/product-media', [
            'sku' => $sku, 'role' => 'main', 'match' => 'exact', 'limit' => 2, 'offset' => 0,
        ]);
        $items = $search['json']['items'] ?? [];
        $item = $items[0] ?? [];
        if (!$this->java_success($search) || !is_array($items) || count($items) !== 1 || (int) ($search['json']['total'] ?? 1) !== 1
            || ($item['sku'] ?? '') !== $sku || ($item['role'] ?? '') !== 'main' || !is_string($item['filename'] ?? null)) {
            throw new \RuntimeException(__('One current main image could not be found in Folio for this SKU.', 'lavka-product-media-upload'));
        }

        $before = $item['filename'];
        $after = $target['filename'];
        $view = ['sku' => $sku, 'product_name' => $target['product_name'], 'before' => $before, 'after' => $after, 'url' => $target['url'], 'token' => ''];
        if ($before === $after) {
            return $view;
        }

        $token = wp_generate_uuid4();
        $payload = [
            'externalRequestId' => 'woo-main-image-name:' . $token,
            'previewOnly' => true,
            'source' => 'woo_media_repair',
            'changes' => [[
                'operation' => 'set_main',
                'sku' => $sku,
                'expectedOldFilename' => $before,
                'filename' => $after,
                's3Proof' => $target['proof'],
            ]],
        ];
        $result = $this->java_request('POST', '/admin/folio/product-media/changes', $payload);
        $check = $this->validate_change_response($result, ['ready'], 1);
        if (!$check['ok'] || ($result['json']['ok'] ?? false) !== true) {
            throw new \RuntimeException(__('Folio did not approve this filename correction. Check the current product images and try the preview again.', 'lavka-product-media-upload'));
        }

        $payload['previewOnly'] = false;
        if (!set_transient('lpmu_main_repair_' . $token, [
            'user_id' => get_current_user_id(),
            'target' => $target,
            'payload' => $payload,
        ], 30 * MINUTE_IN_SECONDS)) {
            throw new \RuntimeException(__('The preview could not be saved. Run it again.', 'lavka-product-media-upload'));
        }
        $view['token'] = $token;
        return $view;
    }

    public function apply_main_repair(string $token): void
    {
        $key = 'lpmu_main_repair_' . $token;
        $stored = wp_is_uuid($token, 4) ? get_transient($key) : false;
        if (!is_array($stored) || (int) $stored['user_id'] !== get_current_user_id()) {
            throw new \RuntimeException(__('This preview has expired. Check the SKU again.', 'lavka-product-media-upload'), 410);
        }

        $lock = $this->acquire_lock($token);
        if (empty($lock['ok'])) {
            throw new \RuntimeException(
                (string) ($lock['message'] ?? __('Another Lavka synchronization is running. Try again later.', 'lavka-product-media-upload')),
                409
            );
        }
        try {
            $change = $stored['payload']['changes'][0];
            $target = $this->main_repair_target($change['sku']);
            foreach (['product_id', 'attachment_id', 'filename', 'proof'] as $field) {
                if ($target[$field] !== $stored['target'][$field]) {
                    throw new \RuntimeException(__('The main photo changed after the preview. Check the SKU again.', 'lavka-product-media-upload'), 409);
                }
            }

            $result = $this->java_request('POST', '/admin/folio/product-media/changes', $stored['payload']);
            $check = $this->validate_change_response($result, ['applied', 'noop'], 1);
            if (!$check['ok'] || ($result['json']['ok'] ?? false) !== true) {
                throw new \RuntimeException(__('The correction could not be confirmed. Run a new preview to check the current Folio filename.', 'lavka-product-media-upload'));
            }
            if (function_exists('lts_log_db')) {
                lts_log_db('info', 'folio_media_repair', [
                    'sku' => $change['sku'],
                    'post_id' => $target['product_id'],
                    'result' => $check['statuses'][0],
                    'message' => 'Folio main filename aligned with the current WooCommerce image.',
                    'ctx' => [
                        'external_request_id' => $stored['payload']['externalRequestId'],
                        'previous_filename' => $change['expectedOldFilename'],
                        'filename' => $change['filename'],
                        'user_id' => get_current_user_id(),
                    ],
                ]);
            }
            delete_transient($key);
        } finally {
            $this->release_lock($lock['token'] ?? null);
        }
    }

    private function main_repair_target(string $sku): array
    {
        $product = $sku !== '' ? wc_get_product(wc_get_product_id_by_sku($sku)) : false;
        if (!$product || $product->get_sku('edit') !== $sku || !current_user_can('edit_post', $product->get_id())) {
            throw new \RuntimeException(__('Enter the exact SKU of a product you can edit.', 'lavka-product-media-upload'), 403);
        }

        $id = (int) $product->get_image_id('edit');
        $file = $id > 0 ? (string) get_post_meta($id, '_wp_attached_file', true) : '';
        $url = $id > 0 ? esc_url_raw((string) wp_get_attachment_url($id), ['http', 'https']) : '';
        if ($file === '' || $url === '' || !wp_attachment_is_image($id) || get_post_status($id) === 'trash'
            || !current_user_can('read_post', $id)) {
            throw new \RuntimeException(__('This product needs a verifiable main photo in WooCommerce before correcting its Folio filename.', 'lavka-product-media-upload'));
        }
        $filename = wp_basename($file);
        $proof = $this->verified_attachment_proof($filename, $file, $url);
        if (!$proof['ok'] || basename($proof['proof']['fullKey']) !== $filename) {
            throw new \RuntimeException(__('The current WooCommerce photo could not be verified in OVH/S3.', 'lavka-product-media-upload'));
        }
        return [
            'product_id' => $product->get_id(), 'product_name' => $product->get_name(), 'attachment_id' => $id,
            'filename' => $filename, 'url' => $url, 'proof' => $proof['proof'],
        ];
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
            unset($row['_s3_proof'], $row['_previous_main_proof']);
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

        $folio_filenames = array_column($items, 'filename');
        foreach ($indexes as $index) {
            $row = $rows[$index];
            if (($row['role'] ?? '') !== 'main') { continue; }
            try {
                MainImageReplacement::assert_current($row);
                $action = $row['previous_main_action'] ?? '';
                $old = (string) ($row['previous_main_file'] ?? '');
                $has_case_variant = false;
                if ($action === 'remove' && $old !== '') {
                    foreach ($folio_filenames as $filename) {
                        if ($filename !== $old && strcasecmp((string) $filename, $old) === 0) {
                            $has_case_variant = true;
                            break;
                        }
                    }
                }
                if ($action === 'gallery' || $has_case_variant) {
                    $old_proof = $this->previous_main_proof($row);
                    if (!$old_proof['ok']) { throw new \RuntimeException($old_proof['message']); }
                    $rows[$index]['_previous_main_proof'] = $old_proof['proof'];
                }
            } catch (\RuntimeException $e) {
                return $this->fail_indexes($rows, $indexes, 'FOLIO_PREVIEW_BLOCKED', $e->getMessage(), '', 'folio_prepare');
            }
        }

        $built = $this->build_changes($rows, $indexes, $items);
        if (!$built['ok']) {
            if (!empty($built['folio_main_repair'])) {
                foreach ($indexes as $index) {
                    if (($rows[$index]['role'] ?? '') === 'main') { $rows[$index]['folio_main_repair'] = true; }
                }
            }
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
                // Finalize main last so a retained previous image stays at the end.
                return $left_role === 'main' ? 1 : -1;
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
        $retained_changes = [];
        $retained_rows = [];
        $last_position = 0;
        foreach ($gallery_items as $item) { $last_position = max($last_position, (int) ($item['sortOrder'] ?? 0)); }
        foreach ($indexes as $index) { $last_position = max($last_position, (int) ($rows[$index]['position'] ?? 0)); }
        foreach ($indexes as $index) {
            $row = $rows[$index];
            if (($row['role'] ?? '') !== 'main' || empty($row['_main_snapshot']['required'])) { continue; }
            $action = (string) ($row['previous_main_action'] ?? '');
            $old = (string) ($row['previous_main_file'] ?? '');
            $current = (string) ($main_items[0]['filename'] ?? '');
            $proof = (array) ($row['_previous_main_proof'] ?? []);
            if (!in_array($action, ['gallery', 'remove'], true)) {
                return $this->build_failure(__('Choose whether to move the current main image to the gallery or remove it from this product.', 'lavka-product-media-upload'));
            }
            if ($current !== '' && $current !== $row['canonical_file']
                && !$this->matches_previous_main($main_items[0], $old, $proof)) {
                return $this->build_failure(
                    __('The main images in Folio and WooCommerce differ. Reconcile them before replacing this image.', 'lavka-product-media-upload'),
                    wp_json_encode(['folio' => $current, 'woocommerce' => $old], JSON_UNESCAPED_UNICODE)
                ) + ['folio_main_repair' => true];
            }
            $old_items = [];
            foreach ($gallery_items as $key => $item) {
                if ($this->matches_previous_main($item, $old, $proof)) {
                    $old_items[] = $item;
                    unset($gallery_items[$key]);
                }
            }
            $keep = $action === 'gallery' ? array_shift($old_items) : null;
            foreach ($old_items as $item) {
                $id = is_array($item['recordId'] ?? null) ? ($item['recordId']['key'] ?? '') : ($item['recordId'] ?? '');
                if ((string) $id === '') { return $this->build_failure(__('The Folio gallery row has no stable record ID.', 'lavka-product-media-upload')); }
                $used_record_ids[(string) $id] = true;
                $changes[] = [
                    'operation' => 'remove_gallery', 'sku' => $row['sku'], 'recordId' => (string) $id,
                    'expectedOldFilename' => $item['filename'], 'expectedOldSortOrder' => $item['sortOrder'] ?? null,
                ];
                $change_rows[] = $index;
            }
            if ($action === 'gallery') {
                // Exclude the retained image itself so a retry does not keep
                // increasing its position after Folio already applied the move.
                $last_position = 0;
                foreach ($gallery_items as $item) { $last_position = max($last_position, (int) ($item['sortOrder'] ?? 0)); }
                foreach ($indexes as $other_index) { $last_position = max($last_position, (int) ($rows[$other_index]['position'] ?? 0)); }
                if ($old === '' || !$proof) { return $this->build_failure(__('The previous main image could not be verified in storage. It was not moved to the gallery.', 'lavka-product-media-upload')); }
                $change = [
                    'operation' => $keep ? 'update_gallery' : 'add_gallery', 'sku' => $row['sku'],
                    'filename' => $old, 'sortOrder' => ++$last_position, 's3Proof' => $proof,
                ];
                if ($keep) {
                    $id = is_array($keep['recordId'] ?? null) ? ($keep['recordId']['key'] ?? '') : ($keep['recordId'] ?? '');
                    if ((string) $id === '') { return $this->build_failure(__('The Folio gallery row has no stable record ID.', 'lavka-product-media-upload')); }
                    $used_record_ids[(string) $id] = true;
                    $change += ['recordId' => (string) $id, 'expectedOldFilename' => $keep['filename'], 'expectedOldSortOrder' => $keep['sortOrder'] ?? null];
                }
                $retained_changes[] = $change;
                $retained_rows[] = $index;
            }
            foreach ($indexes as $other_index) {
                $other = $rows[$other_index];
                if (($other['role'] ?? '') === 'gallery' && $old !== '' && $other['canonical_file'] === $old) {
                    return $this->build_failure(__('The previous main image is already handled by the replacement choice. Remove its separate gallery row and check the batch again.', 'lavka-product-media-upload'));
                }
            }
        }
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
            'changes' => array_merge($changes, $retained_changes),
            'change_rows' => array_merge($change_rows, $retained_rows),
            'noop_rows' => $noop_rows,
            'operations' => $operations,
        ];
    }

    private function matches_previous_main(array $item, string $filename, array $proof): bool
    {
        $stored = (string) ($item['filename'] ?? '');
        if ($filename === '' || strcasecmp($stored, $filename) !== 0) {
            return false;
        }
        if ($stored === $filename) {
            return true;
        }

        // Folio uses a case-insensitive index lookup. Confirm its sole match is
        // the exact object already verified for the WooCommerce attachment.
        $matches = $item['s3']['matches'] ?? [];
        if (!$proof || !is_array($matches) || count($matches) !== 1) {
            return false;
        }
        $match = reset($matches);
        return is_array($match)
            && ($match['fullKey'] ?? '') === $proof['fullKey']
            && (int) ($match['sizeBytes'] ?? 0) === $proof['sizeBytes']
            && trim((string) ($match['etag'] ?? ''), '"') === trim($proof['etag'], '"');
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

    private function previous_main_proof(array $row): array
    {
        $snapshot = $row['_main_snapshot'];
        $proof = $this->verified_attachment_proof(
            $snapshot['filename'], $snapshot['file'], (string) wp_get_attachment_url($snapshot['attachment_id'])
        );
        if (!$proof['ok'] && empty($proof['message'])) {
            return $this->build_failure(__('The previous main image could not be verified in storage. It was not moved to the gallery.', 'lavka-product-media-upload'));
        }
        return $proof;
    }

    private function verified_attachment_proof(string $filename, string $file, string $url): array
    {
        $proof = $this->s3_proof(['canonical_file' => $filename, 's3_key' => $file]);
        if (!$proof['ok']) { return $proof; }
        $response = wp_safe_remote_head($url, [
            'timeout' => 12, 'redirection' => 3, 'sslverify' => true,
        ]);
        if (is_wp_error($response)
            || (int) wp_remote_retrieve_response_code($response) !== 200
            || (int) wp_remote_retrieve_header($response, 'content-length') !== $proof['proof']['sizeBytes']
            || trim((string) wp_remote_retrieve_header($response, 'etag'), '"') !== trim($proof['proof']['etag'], '"')) {
            return ['ok' => false];
        }
        return $proof;
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
