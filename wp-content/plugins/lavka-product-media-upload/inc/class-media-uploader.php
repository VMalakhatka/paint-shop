<?php

namespace Lavka\ProductMediaUpload;

if (!defined('ABSPATH')) {
    exit;
}

final class MediaUploader
{
    private ImageValidator $validator;
    private array $thresholds;

    public function __construct()
    {
        $this->validator = new ImageValidator();
        $this->thresholds = ImageValidator::thresholds();
    }

    public function upload(array $row, string $batch_id, string $manifest_hash): array
    {
        $filename = (string) ($row['canonical_file'] ?? '');
        $product_id = (int) ($row['product_id'] ?? 0);
        $source = (array) ($row['_upload'] ?? []);
        $source_tmp = (string) ($source['tmp_name'] ?? '');
        $resume_attachment_id = (int) ($row['_resume_attachment_id'] ?? 0);

        if ($filename === '' || $product_id < 1 || $source_tmp === '') {
            return $this->failure($row, 'UPLOAD_FAILED', __('The approved row is missing internal upload data.', 'lavka-product-media-upload'));
        }
        $existing_attachment_id = $this->validator->wordpress_name_conflict($filename);
        if ($resume_attachment_id > 0 && $existing_attachment_id !== $resume_attachment_id) {
            return $this->failure($row, 'NAME_CONFLICT_WP', __('The resumable attachment no longer matches the canonical filename.', 'lavka-product-media-upload'));
        }
        if ($resume_attachment_id < 1 && $existing_attachment_id > 0) {
            return $this->failure($row, 'NAME_CONFLICT_WP', __('The canonical filename appeared in WordPress after the dry run.', 'lavka-product-media-upload'));
        }

        $lock_name = 'lpmu_name_lock_' . md5($filename);
        $lock_token = wp_generate_uuid4();
        if (!add_option($lock_name, $lock_token, '', false)) {
            return $this->failure($row, 'NAME_CONFLICT_WP', __('Another upload is currently using the same canonical filename.', 'lavka-product-media-upload'));
        }

        $server_copy = '';
        try {
            if ($resume_attachment_id > 0) {
                $this->write_tracking_meta(
                    $resume_attachment_id,
                    $row,
                    $batch_id,
                    $manifest_hash
                );
                return $this->complete_attachment(
                    $resume_attachment_id,
                    $row,
                    $batch_id,
                    $manifest_hash
                );
            }

            $upload_dir = wp_upload_dir();
            if (!empty($upload_dir['error'])) {
                return $this->failure($row, 'UPLOAD_FAILED', (string) $upload_dir['error']);
            }
            $target_path = trailingslashit((string) $upload_dir['path']) . $filename;
            if (file_exists($target_path)) {
                return $this->failure($row, 'NAME_CONFLICT_WP', __('A physical upload file already uses the canonical filename.', 'lavka-product-media-upload'));
            }

            $server_copy = wp_tempnam($filename);
            if (!$server_copy || !copy($source_tmp, $server_copy)) {
                return $this->failure($row, 'UPLOAD_FAILED', __('The canonical server copy could not be created.', 'lavka-product-media-upload'));
            }

            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';
            require_once ABSPATH . 'wp-admin/includes/media.php';

            $field = 'lpmu_canonical_image';
            $previous_file = $_FILES[$field] ?? null;
            $_FILES[$field] = [
                'name' => $filename,
                'type' => (string) ($row['mime'] ?? ''),
                'tmp_name' => $server_copy,
                'error' => UPLOAD_ERR_OK,
                'size' => (int) ($row['file_size'] ?? filesize($server_copy)),
            ];

            $exact_name_callback = static function (string $dir, string $name, string $ext) use ($filename): string {
                return $filename;
            };

            try {
                $attachment_id = media_handle_upload(
                    $field,
                    $product_id,
                    [
                        'post_title' => (string) ($row['product_name'] ?: pathinfo($filename, PATHINFO_FILENAME)),
                        'post_excerpt' => '',
                        'post_content' => '',
                    ],
                    [
                        'test_form' => false,
                        'test_upload' => false,
                        'mimes' => [
                            'jpg|jpeg' => 'image/jpeg',
                            'png' => 'image/png',
                            'webp' => 'image/webp',
                        ],
                        'unique_filename_callback' => $exact_name_callback,
                    ]
                );
            } finally {
                if ($previous_file === null) {
                    unset($_FILES[$field]);
                } else {
                    $_FILES[$field] = $previous_file;
                }
            }

            if (is_wp_error($attachment_id)) {
                return $this->failure($row, 'UPLOAD_FAILED', $attachment_id->get_error_message(), $attachment_id->get_error_code());
            }

            $attachment_id = (int) $attachment_id;
            $this->write_tracking_meta($attachment_id, $row, $batch_id, $manifest_hash);
            return $this->complete_attachment(
                $attachment_id,
                $row,
                $batch_id,
                $manifest_hash
            );
        } finally {
            if ($server_copy !== '' && file_exists($server_copy)) {
                wp_delete_file($server_copy);
            }
            if (get_option($lock_name) === $lock_token) {
                delete_option($lock_name);
            }
        }
    }

    private function write_tracking_meta(
        int $attachment_id,
        array $row,
        string $batch_id,
        string $manifest_hash
    ): void {
        update_post_meta($attachment_id, '_lpmu_batch_id', $batch_id);
        update_post_meta($attachment_id, '_lpmu_manifest_hash', $manifest_hash);
        update_post_meta($attachment_id, '_lpmu_source_sha256', (string) ($row['sha256'] ?? ''));
        update_post_meta($attachment_id, '_lpmu_product_id', (int) ($row['product_id'] ?? 0));
        update_post_meta($attachment_id, '_lpmu_role', (string) ($row['role'] ?? ''));
        update_post_meta($attachment_id, '_lpmu_position', (int) ($row['position'] ?? 0));
    }

    private function complete_attachment(
        int $attachment_id,
        array $row,
        string $batch_id,
        string $manifest_hash
    ): array {
        $post_check = $this->verify_attachment($attachment_id, $row);
        if (!$post_check['ok']) {
            return $this->failure(
                array_merge($row, ['attachment_id' => $attachment_id, 'url' => $post_check['url'] ?? '']),
                $post_check['status'],
                $post_check['message'],
                $post_check['technical'] ?? ''
            );
        }

        $remote = $this->verify_remote($post_check['url'], $row);
        if (!$remote['ok']) {
            return $this->failure(
                array_merge($row, ['attachment_id' => $attachment_id, 'url' => $post_check['url']]),
                'OVH_VERIFY_FAILED',
                $remote['message'],
                $remote['technical'] ?? ''
            );
        }

        $role_result = $this->assign_role($attachment_id, $row);
        if (!$role_result['ok']) {
            return $this->failure(
                array_merge($row, ['attachment_id' => $attachment_id, 'url' => $post_check['url']]),
                'UPLOAD_FAILED',
                $role_result['message'],
                $role_result['technical'] ?? ''
            );
        }

        $parent_warnings = $this->sync_attachment_parent(
            $attachment_id,
            (int) ($row['product_id'] ?? 0)
        );

        update_post_meta($attachment_id, '_lpmu_verified_at', current_time('mysql'));

        $attached_file = (string) get_post_meta($attachment_id, '_wp_attached_file', true);
        $payload = [
            'batch_id' => $batch_id,
            'manifest_hash' => $manifest_hash,
            'sku' => (string) ($row['sku'] ?? ''),
            'barcode' => (string) ($row['barcode'] ?? ''),
            'filename' => (string) ($row['canonical_file'] ?? ''),
            'source_sha256' => (string) ($row['sha256'] ?? ''),
            's3_key' => $attached_file,
            's3_url' => $post_check['url'],
            'role' => (string) ($row['role'] ?? ''),
            'position' => (int) ($row['position'] ?? 0),
            'attachment_id' => $attachment_id,
            'product_id' => (int) ($row['product_id'] ?? 0),
            'mime' => (string) ($row['mime'] ?? ''),
            'width' => (int) ($row['width'] ?? 0),
            'height' => (int) ($row['height'] ?? 0),
        ];

        /**
         * Fires only after WordPress metadata, product assignment and remote-object verification succeed.
         *
         * Java/Folio integration may subscribe without changing this uploader.
         */
        do_action('lavka_product_media_upload_after_upload', $payload);

        return array_merge($this->public_row($row), [
            'valid' => true,
            'status' => 'SUCCESS',
            'attachment_id' => $attachment_id,
            'url' => $post_check['url'],
            'errors' => [],
            'warnings' => array_values(array_unique(array_merge(
                (array) ($row['warnings'] ?? []),
                (array) ($post_check['warnings'] ?? []),
                (array) ($remote['warnings'] ?? []),
                $parent_warnings
            ))),
            'technical' => '',
        ]);
    }

    private function sync_attachment_parent(int $attachment_id, int $product_id): array
    {
        $attachment = get_post($attachment_id);
        if (!$attachment || $attachment->post_type !== 'attachment' || $product_id < 1) {
            return [__('The attachment parent could not be synchronized with the product.', 'lavka-product-media-upload')];
        }

        $current_parent = (int) $attachment->post_parent;
        if ($current_parent === $product_id) {
            return [];
        }
        if ($current_parent > 0) {
            return [__('The attachment already belongs to another WordPress post, so its media parent was not changed.', 'lavka-product-media-upload')];
        }

        $updated = wp_update_post([
            'ID' => $attachment_id,
            'post_parent' => $product_id,
        ], true);

        if (is_wp_error($updated) || (int) $updated !== $attachment_id) {
            return [__('The attachment parent could not be synchronized with the product.', 'lavka-product-media-upload')];
        }

        clean_post_cache($attachment_id);
        return [];
    }

    private function verify_attachment(int $attachment_id, array $row): array
    {
        $post = get_post($attachment_id);
        $url = (string) wp_get_attachment_url($attachment_id);
        $attached_file = (string) get_post_meta($attachment_id, '_wp_attached_file', true);
        $metadata = wp_get_attachment_metadata($attachment_id);
        $expected_filename = (string) ($row['canonical_file'] ?? '');
        $expected_mime = (string) ($row['mime'] ?? '');

        if (!$post || $post->post_type !== 'attachment') {
            return ['ok' => false, 'status' => 'METADATA_FAILED', 'message' => __('WordPress did not create a valid attachment post.', 'lavka-product-media-upload'), 'url' => $url];
        }
        if ($post->post_mime_type !== $expected_mime) {
            return [
                'ok' => false,
                'status' => 'METADATA_FAILED',
                'message' => __('The attachment MIME differs from the validated image MIME.', 'lavka-product-media-upload'),
                'technical' => 'expected=' . $expected_mime . '; actual=' . $post->post_mime_type,
                'url' => $url,
            ];
        }
        if ($attached_file === '' || wp_basename($attached_file) !== $expected_filename) {
            return [
                'ok' => false,
                'status' => 'METADATA_FAILED',
                'message' => __('The attachment file key does not contain the expected canonical filename.', 'lavka-product-media-upload'),
                'technical' => $attached_file,
                'url' => $url,
            ];
        }
        if (!is_array($metadata)) {
            return ['ok' => false, 'status' => 'METADATA_FAILED', 'message' => __('WordPress attachment metadata was not generated.', 'lavka-product-media-upload'), 'url' => $url];
        }
        if ((int) ($metadata['width'] ?? 0) !== (int) ($row['width'] ?? 0) || (int) ($metadata['height'] ?? 0) !== (int) ($row['height'] ?? 0)) {
            return [
                'ok' => false,
                'status' => 'METADATA_FAILED',
                'message' => __('Attachment metadata dimensions differ from the validated source dimensions.', 'lavka-product-media-upload'),
                'url' => $url,
            ];
        }
        $url_parts = $url !== '' ? wp_parse_url($url) : false;
        if (
            !is_array($url_parts)
            || !in_array(strtolower((string) ($url_parts['scheme'] ?? '')), ['http', 'https'], true)
            || empty($url_parts['host'])
        ) {
            return ['ok' => false, 'status' => 'METADATA_FAILED', 'message' => __('The attachment URL is missing or invalid.', 'lavka-product-media-upload'), 'url' => $url];
        }

        $warnings = [];
        if (empty($metadata['sizes']) || !is_array($metadata['sizes'])) {
            $warnings[] = __('WordPress generated no derivative image sizes for this attachment.', 'lavka-product-media-upload');
        }

        return ['ok' => true, 'url' => $url, 'warnings' => $warnings];
    }

    private function assign_role(int $attachment_id, array $row): array
    {
        $product = wc_get_product((int) ($row['product_id'] ?? 0));
        if (!$product) {
            return ['ok' => false, 'message' => __('The WooCommerce product disappeared before image assignment.', 'lavka-product-media-upload')];
        }

        $role = (string) ($row['role'] ?? '');
        if ($role === 'main') {
            $product->set_image_id($attachment_id);
            $product->save();
            return ['ok' => true];
        }
        if ($role === 'gallery' && $product->is_type('variation')) {
            return ['ok' => false, 'message' => __('WooCommerce variations do not have a standard separate gallery.', 'lavka-product-media-upload')];
        }
        if ($role === 'gallery') {
            $gallery = array_values(array_filter(array_map('intval', $product->get_gallery_image_ids())));
            if (!in_array($attachment_id, $gallery, true)) {
                $position = max(1, (int) ($row['position'] ?? 1));
                array_splice($gallery, min(count($gallery), $position - 1), 0, [$attachment_id]);
            }
            $product->set_gallery_image_ids($gallery);
            $product->save();
            return ['ok' => true];
        }

        return ['ok' => false, 'message' => __('The image role is unsupported.', 'lavka-product-media-upload')];
    }

    private function verify_remote(string $url, array $row): array
    {
        $url_host = strtolower((string) wp_parse_url($url, PHP_URL_HOST));
        $site_host = strtolower((string) wp_parse_url(home_url('/'), PHP_URL_HOST));
        $default_should_verify = $url_host !== '' && $site_host !== '' && $url_host !== $site_host;
        $should_verify = (bool) apply_filters(
            'lavka_product_media_upload_should_verify_remote',
            $default_should_verify,
            $url,
            $row
        );

        if (!$should_verify) {
            return [
                'ok' => true,
                'warnings' => [__('Remote-object verification was not required for the resolved attachment URL.', 'lavka-product-media-upload')],
            ];
        }

        $attempts = max(1, min(5, (int) $this->thresholds['remote_verify_attempts']));
        $expected_mime = (string) ($row['mime'] ?? '');
        $expected_size = (int) ($row['file_size'] ?? 0);
        $last_technical = '';

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $response = wp_remote_head($url, [
                'timeout' => 12,
                'redirection' => 3,
                'sslverify' => true,
            ]);
            if (!is_wp_error($response)) {
                $code = (int) wp_remote_retrieve_response_code($response);
                $content_type = strtolower(trim((string) wp_remote_retrieve_header($response, 'content-type')));
                $content_type = trim(explode(';', $content_type)[0]);
                $content_length = (int) wp_remote_retrieve_header($response, 'content-length');

                if ($code >= 200 && $code < 400 && $content_type === $expected_mime && ($content_length < 1 || $content_length === $expected_size)) {
                    return ['ok' => true, 'warnings' => []];
                }
                $last_technical = 'http=' . $code . '; type=' . $content_type . '; length=' . $content_length;
            } else {
                $last_technical = $response->get_error_message();
            }

            if ($attempt < $attempts) {
                usleep((int) (250000 * (2 ** ($attempt - 1))));
            }
        }

        $probe = wp_remote_get($url, [
            'timeout' => 12,
            'redirection' => 3,
            'sslverify' => true,
            'headers' => ['Range' => 'bytes=0-63'],
            'limit_response_size' => 64,
        ]);
        if (!is_wp_error($probe)) {
            $body = (string) wp_remote_retrieve_body($probe);
            $format = $this->signature_format($body);
            $expected_format = strtolower((string) ($row['format'] ?? ''));
            $expected_format = $expected_format === 'jpeg' ? 'jpg' : $expected_format;
            if ($format === $expected_format) {
                return [
                    'ok' => true,
                    'warnings' => [__('Remote HEAD metadata was incomplete, but the public object signature was verified.', 'lavka-product-media-upload')],
                ];
            }
            $last_technical .= '; probe_signature=' . $format;
        } else {
            $last_technical .= '; probe=' . $probe->get_error_message();
        }

        return [
            'ok' => false,
            'message' => __('The Media Cloud or OVH object could not be verified after bounded retries.', 'lavka-product-media-upload'),
            'technical' => $last_technical,
        ];
    }

    private function signature_format(string $bytes): string
    {
        if (str_starts_with($bytes, "\xFF\xD8\xFF")) {
            return 'jpg';
        }
        if (str_starts_with($bytes, "\x89PNG\x0D\x0A\x1A\x0A")) {
            return 'png';
        }
        if (strlen($bytes) >= 12 && substr($bytes, 0, 4) === 'RIFF' && substr($bytes, 8, 4) === 'WEBP') {
            return 'webp';
        }
        return '';
    }

    private function failure(array $row, string $status, string $message, string $technical = ''): array
    {
        $public = $this->public_row($row);
        $public['valid'] = false;
        $public['status'] = $status;
        $public['errors'] = array_values(array_unique(array_merge((array) ($row['errors'] ?? []), [$message])));
        $public['warnings'] = array_values(array_unique((array) ($row['warnings'] ?? [])));
        $public['technical'] = $technical;
        return $public;
    }

    private function public_row(array $row): array
    {
        unset($row['_upload'], $row['_product'], $row['_resume_attachment_id']);
        return $row;
    }
}
