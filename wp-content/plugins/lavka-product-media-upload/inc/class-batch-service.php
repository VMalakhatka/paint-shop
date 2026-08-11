<?php

namespace Lavka\ProductMediaUpload;

if (!defined('ABSPATH')) {
    exit;
}

final class BatchService
{
    private const BATCH_TTL = HOUR_IN_SECONDS;

    public function dry_run(
        array $registry_upload,
        array $image_uploads,
        bool $legacy_main_confirm,
        bool $generate_names
    ): array
    {
        $reader = new RegistryReader();
        $registry = $reader->read($registry_upload, $legacy_main_confirm);
        $validator = new ImageValidator();
        $validation = $validator->validate($registry, $image_uploads, $generate_names);

        $batch_id = wp_generate_uuid4();
        $batch_token = \lpmu_writes_enabled() ? str_replace('-', '', wp_generate_uuid4()) : '';
        $fingerprint = $this->fingerprint(
            $registry_upload,
            $image_uploads,
            $legacy_main_confirm,
            $generate_names
        );
        $approved = [];

        foreach ($validation['rows'] as $row) {
            if (!empty($row['valid'])) {
                $approved[] = [
                    'row_number' => (int) $row['row_number'],
                    'canonical_file' => (string) $row['canonical_file'],
                    'sha256' => (string) $row['sha256'],
                    'product_id' => (int) $row['product_id'],
                    'role' => (string) $row['role'],
                    'position' => (int) $row['position'],
                    'attachment_id' => (int) ($row['attachment_id'] ?? 0),
                ];
            }
        }

        if ($batch_token !== '') {
            set_transient($this->batch_key($batch_token), [
                'user_id' => get_current_user_id(),
                'batch_id' => $batch_id,
                'manifest_hash' => (string) $registry['manifest_hash'],
                'fingerprint' => $fingerprint,
                'legacy_main_confirm' => $legacy_main_confirm,
                'generate_names' => $generate_names,
                'approved' => $approved,
                'created_at' => time(),
            ], self::BATCH_TTL);
        }

        $public_rows = $this->public_rows(array_merge($validation['rows'], $validation['extra_files']));
        $report = [
            'batch_id' => $batch_id,
            'manifest_hash' => (string) $registry['manifest_hash'],
            'created_at' => current_time('mysql'),
            'registry_name' => (string) $registry['source_name'],
            'registry_mode' => (string) $registry['mode'],
            'rows' => $public_rows,
            'summary' => $validation['summary'],
            'capabilities' => $validation['capabilities'],
        ];
        $report_link = ReportStore::store($report);

        return [
            'batch_id' => $batch_id,
            'batch_token' => $batch_token,
            'manifest_hash' => (string) $registry['manifest_hash'],
            'registry_mode' => (string) $registry['mode'],
            'registry_name' => (string) $registry['source_name'],
            'rows' => $public_rows,
            'summary' => $validation['summary'],
            'capabilities' => $validation['capabilities'],
            'report_url' => $report_link['url'],
            'can_upload' => \lpmu_writes_enabled() && (int) $validation['summary']['ready'] > 0,
        ];
    }

    public function upload(
        string $batch_token,
        array $registry_upload,
        array $image_uploads,
        bool $legacy_main_confirm,
        bool $generate_names
    ): array {
        if (!\lpmu_writes_enabled()) {
            throw new \RuntimeException(__('File writing is disabled for this verification stage.', 'lavka-product-media-upload'));
        }

        $stored = get_transient($this->batch_key($batch_token));
        if (!is_array($stored) || (int) ($stored['user_id'] ?? 0) !== get_current_user_id()) {
            throw new \RuntimeException(__('The dry-run token has expired or belongs to another user.', 'lavka-product-media-upload'));
        }
        if ((bool) ($stored['legacy_main_confirm'] ?? false) !== $legacy_main_confirm) {
            throw new \RuntimeException(__('The legacy-role confirmation changed after the dry run.', 'lavka-product-media-upload'));
        }
        if ((bool) ($stored['generate_names'] ?? false) !== $generate_names) {
            throw new \RuntimeException(__('The canonical filename option changed after the dry run.', 'lavka-product-media-upload'));
        }

        $current_fingerprint = $this->fingerprint(
            $registry_upload,
            $image_uploads,
            $legacy_main_confirm,
            $generate_names
        );
        if (!hash_equals((string) $stored['fingerprint'], $current_fingerprint)) {
            throw new \RuntimeException(__('The registry or selected images changed after the dry run. Check the batch again.', 'lavka-product-media-upload'));
        }

        $reader = new RegistryReader();
        $registry = $reader->read($registry_upload, $legacy_main_confirm);
        if (!hash_equals((string) $stored['manifest_hash'], (string) $registry['manifest_hash'])) {
            throw new \RuntimeException(__('The registry hash changed after the dry run.', 'lavka-product-media-upload'));
        }

        $validator = new ImageValidator();
        $validation = $validator->validate($registry, $image_uploads, $generate_names);
        $approved_map = [];
        foreach ((array) ($stored['approved'] ?? []) as $approved) {
            $approved_map[$this->approval_key($approved)] = true;
        }

        foreach ($validation['rows'] as $row) {
            if (!empty($row['valid']) && !isset($approved_map[$this->approval_key($row)])) {
                throw new \RuntimeException(__('A row differs from the approved dry-run result.', 'lavka-product-media-upload'));
            }
        }

        $workflow = new WorkflowService();
        $lock = $workflow->acquire_lock((string) $stored['batch_id']);
        if (empty($lock['ok'])) {
            throw new \RuntimeException(
                (string) ($lock['message'] ?? __('Another Lavka synchronization is running. Try this batch again later.', 'lavka-product-media-upload')),
                409
            );
        }

        $lock_token = isset($lock['token']) ? (string) $lock['token'] : null;
        try {
            $uploader = new MediaUploader();
            $completed = [];
            $ready_total = count(array_filter($validation['rows'], static fn(array $row): bool => !empty($row['valid'])));
            $ready_done = 0;
            foreach ($validation['rows'] as $row) {
                if (empty($row['valid'])) {
                    $completed[] = $row;
                    continue;
                }
                $ready_done++;
                $workflow->touch_lock($lock_token, [
                    'stage' => 'upload',
                    'uploaded' => $ready_done,
                    'total' => $ready_total,
                ]);
                $completed[] = $uploader->upload(
                    $row,
                    (string) $stored['batch_id'],
                    (string) $stored['manifest_hash']
                );
            }
            $completed = $workflow->complete(
                $completed,
                (string) $stored['batch_id'],
                (string) $stored['manifest_hash'],
                $lock_token
            );
            $completed = array_merge($completed, $validation['extra_files']);
        } finally {
            $workflow->release_lock($lock_token);
        }

        $summary = $this->summarize($completed);
        $report = [
            'batch_id' => (string) $stored['batch_id'],
            'manifest_hash' => (string) $stored['manifest_hash'],
            'created_at' => current_time('mysql'),
            'rows' => $this->public_rows($completed),
            'summary' => $summary,
        ];
        $report_link = ReportStore::store($report);

        if (function_exists('lavka_ecosystem_log_event')) {
            $has_failures = (int) ($summary['errors'] ?? 0) > 0 || (int) ($summary['partial'] ?? 0) > 0;
            lavka_ecosystem_log_event($has_failures ? 'media_batch_partial' : 'media_batch_completed', [
                'level' => $has_failures ? 'warning' : 'info',
                'owner' => 'lavka-product-media-upload',
                'process' => 'product_media_batch',
                'source' => 'manual',
                'message' => $has_failures
                    ? 'Product image batch completed with rows requiring attention.'
                    : 'Product image batch completed successfully.',
                'context' => [
                    'batch_id' => (string) $stored['batch_id'],
                    'manifest_hash' => (string) $stored['manifest_hash'],
                    'summary' => $summary,
                ],
            ]);
        }

        delete_transient($this->batch_key($batch_token));

        return [
            'batch_id' => (string) $stored['batch_id'],
            'manifest_hash' => (string) $stored['manifest_hash'],
            'rows' => $report['rows'],
            'summary' => $summary,
            'report_url' => $report_link['url'],
            'can_upload' => false,
        ];
    }

    private function fingerprint(
        array $registry_upload,
        array $image_uploads,
        bool $legacy_main_confirm,
        bool $generate_names
    ): string
    {
        $registry_tmp = (string) ($registry_upload['tmp_name'] ?? '');
        $parts = [
            'registry=' . (hash_file('sha256', $registry_tmp) ?: ''),
            'legacy=' . ($legacy_main_confirm ? '1' : '0'),
            'generate_names=' . ($generate_names ? '1' : '0'),
        ];

        foreach ($image_uploads as $file) {
            $tmp = (string) ($file['tmp_name'] ?? '');
            $name = RegistryReader::normalize_text(wp_basename(str_replace('\\', '/', (string) ($file['name'] ?? ''))));
            $parts[] = mb_strtolower($name, 'UTF-8')
                . '|' . (int) ($file['size'] ?? 0)
                . '|' . ($tmp !== '' && is_file($tmp) ? (hash_file('sha256', $tmp) ?: '') : '');
        }
        sort($parts, SORT_STRING);

        return hash('sha256', implode("\n", $parts));
    }

    private function approval_key(array $row): string
    {
        return implode('|', [
            (int) ($row['row_number'] ?? 0),
            (string) ($row['canonical_file'] ?? ''),
            (string) ($row['sha256'] ?? ''),
            (int) ($row['product_id'] ?? 0),
            (string) ($row['role'] ?? ''),
            (int) ($row['position'] ?? 0),
            (int) ($row['attachment_id'] ?? 0),
        ]);
    }

    private function public_rows(array $rows): array
    {
        foreach ($rows as &$row) {
            unset($row['_upload'], $row['_product'], $row['_resume_attachment_id']);
        }
        unset($row);
        return array_values($rows);
    }

    private function summarize(array $rows): array
    {
        $summary = [
            'total' => count($rows),
            'ready' => 0,
            'success' => 0,
            'partial' => 0,
            'errors' => 0,
            'warnings' => 0,
        ];
        foreach ($rows as $row) {
            if (($row['status'] ?? '') === 'READY') {
                $summary['ready']++;
            }
            if (($row['status'] ?? '') === 'SUCCESS') {
                $summary['success']++;
            }
            if (
                !empty($row['attachment_id'])
                && ($row['status'] ?? '') !== 'SUCCESS'
                && !in_array(($row['status'] ?? ''), ['READY', 'EXTRA_FILE'], true)
            ) {
                $summary['partial']++;
            }
            if (!empty($row['errors'])) {
                $summary['errors']++;
            }
            if (!empty($row['warnings'])) {
                $summary['warnings']++;
            }
        }
        return $summary;
    }

    private function batch_key(string $token): string
    {
        return 'lpmu_batch_' . md5($token);
    }
}
