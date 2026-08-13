<?php

namespace Lavka\ProductMediaUpload;

if (!defined('ABSPATH')) {
    exit;
}

final class ImageValidator
{
    private ?array $s3_index_columns = null;

    public const SETTINGS_OPTION = 'lpmu_validation_thresholds';

    private ProductResolver $products;
    private array $thresholds;
    private bool $s3_table_checked = false;
    private string $s3_index_table = '';

    public function __construct()
    {
        $this->products = new ProductResolver();
        $this->thresholds = self::thresholds();
    }

    public static function thresholds(): array
    {
        $defaults = [
            'max_file_bytes' => 10 * MB_IN_BYTES,
            'warn_file_bytes' => 5 * MB_IN_BYTES,
            'min_side_warn' => 550,
            'recommended_min' => 1000,
            'recommended_max' => 2000,
            'large_side_warn' => 4000,
            'max_side' => 12000,
            'max_pixels' => 40000000,
            'aspect_ratio_warn' => 4.0,
            'filename_warn_length' => 60,
            'filename_max_length' => 100,
            'strict_decoder_warnings' => true,
            'remote_verify_attempts' => 3,
        ];

        $saved = get_option(self::SETTINGS_OPTION, []);
        $values = wp_parse_args(is_array($saved) ? $saved : [], $defaults);
        $values = (array) apply_filters('lavka_product_media_upload_thresholds', $values);
        return wp_parse_args($values, $defaults);
    }

    public function validate(array $registry, array $uploads, bool $generate_names = true): array
    {
        $prepared_files = $this->prepare_uploads($uploads);
        $rows = [];
        $seen_row_keys = [];
        $seen_sources = [];
        $seen_canonical = [];
        $assets = [];
        $seen_main = [];
        $seen_product_assignments = [];
        $resumable_uploads = [];

        foreach ($registry['rows'] as $manifest_row) {
            $row = $this->base_result($manifest_row);
            $errors = (array) ($manifest_row['manifest_errors'] ?? []);
            $warnings = [];

            $sku = (string) ($manifest_row['sku'] ?? '');
            $barcode = (string) ($manifest_row['barcode'] ?? '');
            $source_file = (string) ($manifest_row['source_file'] ?? '');
            $role = strtolower((string) ($manifest_row['role'] ?? ''));
            $position_raw = (string) ($manifest_row['position'] ?? '');
            $position = $role === 'gallery' ? (int) $position_raw : 0;

            if ($sku === '' && $barcode === '') {
                $errors[] = __('An SKU or barcode is required.', 'lavka-product-media-upload');
            }
            if ($source_file === '') {
                $errors[] = __('The source filename is required.', 'lavka-product-media-upload');
            }
            if (!in_array($role, ['main', 'gallery'], true)) {
                $errors[] = __('The image role must be main or gallery.', 'lavka-product-media-upload');
            }
            if ($role === 'gallery' && ($position_raw === '' || !ctype_digit($position_raw) || $position < 1)) {
                $errors[] = __('A gallery image requires a unique positive position.', 'lavka-product-media-upload');
            }

            if ($source_file !== '' && $this->has_mixed_lookalike_scripts($source_file)) {
                $errors[] = __('The source filename mixes visually similar Latin and Cyrillic letters. Correct it explicitly in the registry.', 'lavka-product-media-upload');
            }

            $row_key = mb_strtolower($sku . '|' . $barcode . '|' . $role . '|' . $position, 'UTF-8');
            if (isset($seen_row_keys[$row_key])) {
                $errors[] = __('The same product, role and position occur more than once in the registry.', 'lavka-product-media-upload');
            }
            $seen_row_keys[$row_key] = true;

            $source_key = mb_strtolower($source_file, 'UTF-8');
            $seen_sources[$source_key] = true;

            $main_key = mb_strtolower($sku !== '' ? 'sku:' . $sku : 'barcode:' . $barcode, 'UTF-8');
            if ($role === 'main' && isset($seen_main[$main_key])) {
                $errors[] = __('A product can have only one main image in a batch.', 'lavka-product-media-upload');
            } elseif ($role === 'main') {
                $seen_main[$main_key] = true;
            }

            $unsafe_source = $this->source_filename_error($source_file);
            if ($unsafe_source !== '') {
                $errors[] = $unsafe_source;
                $row['status'] = 'UNSAFE_FILENAME';
            }

            $file_candidates = $prepared_files['by_name'][$source_key] ?? [];
            if (!$file_candidates) {
                $errors[] = __('No selected image matches the registry source filename.', 'lavka-product-media-upload');
                $row['status'] = 'FILE_NOT_FOUND';
            } elseif (count($file_candidates) !== 1) {
                $errors[] = __('More than one selected file matches the registry source filename.', 'lavka-product-media-upload');
                $row['status'] = 'DUPLICATE_IN_BATCH';
            }

            $product_result = null;
            if (!$errors) {
                $product_result = $this->products->resolve(
                    $sku,
                    $barcode,
                    $sku !== ''
                );
                if (empty($product_result['ok'])) {
                    $errors[] = (string) $product_result['message'];
                    $row['status'] = (string) $product_result['status'];
                    $row['technical'] = (string) ($product_result['technical'] ?? '');
                } else {
                    $warnings = array_merge($warnings, (array) ($product_result['warnings'] ?? []));
                    $row['product_id'] = (int) $product_result['product_id'];
                    if ($row['sku'] === '') {
                        $row['sku'] = (string) ($product_result['product_sku'] ?? '');
                    }
                    $row['product_type'] = (string) $product_result['product_type'];
                    $row['product_name'] = (string) $product_result['product_name'];
                    $assignment_key = $row['product_id'] . '|' . $role . '|' . $position;
                    if (isset($seen_product_assignments[$assignment_key])) {
                        $errors[] = __('The same product, role and position occur more than once in the registry.', 'lavka-product-media-upload');
                        $row['status'] = 'MANIFEST_ERROR';
                    }
                    $seen_product_assignments[$assignment_key] = true;
                    if ($role === 'gallery' && $product_result['product_type'] === 'variation') {
                        $errors[] = __('WooCommerce variations do not have a standard separate gallery.', 'lavka-product-media-upload');
                        $row['status'] = 'MANIFEST_ERROR';
                    }
                }
            }

            $file_result = null;
            if (!$errors && count($file_candidates) === 1) {
                $file_result = $this->validate_file($file_candidates[0]);
                $warnings = array_merge($warnings, $file_result['warnings']);
                if (!$file_result['ok']) {
                    $errors = array_merge($errors, $file_result['errors']);
                    $row['status'] = $file_result['status'];
                    $row['technical'] = (string) ($file_result['technical'] ?? '');
                } else {
                    $row = array_merge($row, [
                        'format' => $file_result['format'],
                        'mime' => $file_result['mime'],
                        'width' => $file_result['width'],
                        'height' => $file_result['height'],
                        'file_size' => $file_result['size'],
                        'sha256' => $file_result['sha256'],
                        'color_space' => $file_result['color_space'],
                    ]);
                    $row['_upload'] = $file_candidates[0];
                }
            }

            if (!$errors && $file_result && $file_result['ok']) {
                $canonical = $this->canonical_filename(
                    $manifest_row,
                    $file_result['extension'],
                    $generate_names
                );
                if (!$canonical['ok']) {
                    $errors[] = $canonical['message'];
                    $row['status'] = 'UNSAFE_FILENAME';
                } else {
                    $row['canonical_file'] = $canonical['filename'];
                    $warnings = array_merge($warnings, $canonical['warnings']);
                }
            }

            if (!$errors && $row['canonical_file'] !== '') {
                // Deduplicate repeated registry references to the same selected
                // source file. Equal bytes under intentionally different source
                // names remain separate assets with separate canonical names.
                $asset_key = hash('sha256', $source_key . '|' . (string) $row['sha256']);
                if (isset($assets[$asset_key])) {
                    $asset = $assets[$asset_key];
                    $row['canonical_file'] = (string) $asset['canonical_file'];
                    $row['_asset_owner_row'] = (int) $asset['row_number'];
                    $row['_asset_owner_product_id'] = (int) $asset['product_id'];
                    $warnings[] = __('This image is shared by multiple registry rows. It will be uploaded once and assigned to every listed product.', 'lavka-product-media-upload');
                } else {
                    $assets[$asset_key] = [
                        'canonical_file' => (string) $row['canonical_file'],
                        'row_number' => (int) $row['row_number'],
                        'product_id' => (int) $row['product_id'],
                    ];
                    $row['_asset_owner_row'] = (int) $row['row_number'];
                    $row['_asset_owner_product_id'] = (int) $row['product_id'];
                }
                $row['_asset_key'] = $asset_key;

                $canonical_key = mb_strtolower((string) $row['canonical_file'], 'UTF-8');
                if (isset($seen_canonical[$canonical_key]) && $seen_canonical[$canonical_key] !== $asset_key) {
                    $errors[] = __('The canonical filename is duplicated inside this batch.', 'lavka-product-media-upload');
                    $row['status'] = 'DUPLICATE_IN_BATCH';
                }
                $seen_canonical[$canonical_key] = $asset_key;
            }

            if (!$errors && $row['canonical_file'] !== '') {
                $wp_conflict = $this->wordpress_name_conflict($row['canonical_file']);
                if ($wp_conflict > 0) {
                    if ($this->existing_attachment_matches_source($wp_conflict, $row)) {
                        $row['_reuse_attachment_id'] = $wp_conflict;
                        $row['attachment_id'] = $wp_conflict;
                        $row['url'] = (string) wp_get_attachment_url($wp_conflict);
                        $warnings[] = __('An identical image already exists in WordPress and OVH/S3. The existing file will be reused without uploading another copy.', 'lavka-product-media-upload');
                    } elseif (
                        \lpmu_writes_enabled()
                        && $this->is_matching_partial_upload($wp_conflict, $row)
                    ) {
                        $resumable_uploads[$row['row_number']] = $wp_conflict;
                        $row['attachment_id'] = $wp_conflict;
                        $row['url'] = (string) wp_get_attachment_url($wp_conflict);
                        $warnings[] = __('A matching attachment from an incomplete earlier attempt will be verified and resumed without creating a duplicate.', 'lavka-product-media-upload');
                    } else {
                        $errors[] = __('The canonical filename already exists in the WordPress Media Library.', 'lavka-product-media-upload');
                        $row['status'] = 'NAME_CONFLICT_WP';
                        $row['technical'] = 'attachment_id=' . $wp_conflict;
                    }
                }
            }

            if (
                !$errors
                && $row['canonical_file'] !== ''
                && empty($row['_reuse_attachment_id'])
                && empty($resumable_uploads[$row['row_number']])
            ) {
                $remote = apply_filters(
                    'lavka_product_media_upload_s3_name_check',
                    $this->built_in_s3_name_check($row['canonical_file']),
                    $row['canonical_file'],
                    $row
                );
                if (is_array($remote) && !empty($remote['available'])) {
                    if (!empty($remote['error'])) {
                        $errors[] = __('The S3 media index could not be checked.', 'lavka-product-media-upload');
                        $row['status'] = 'NAME_CONFLICT_S3';
                        $row['technical'] = (string) ($remote['technical'] ?? '');
                    } elseif (!empty($remote['conflict'])) {
                        $errors[] = __('The file already exists in OVH/S3, but no matching WordPress attachment with proven identical content was found. WooCommerce requires an attachment ID, so this row cannot be linked safely.', 'lavka-product-media-upload');
                        $row['status'] = 'NAME_CONFLICT_S3';
                        $row['technical'] = (string) ($remote['technical'] ?? '');
                    }
                }
            }

            if (!$errors && $row['canonical_file'] !== '') {
                $legacy_variants = $this->legacy_auto_renamed_variants($row['canonical_file']);
                if ($legacy_variants) {
                    $warnings[] = sprintf(
                        /* translators: %s: comma-separated filenames */
                        __('Automatically renamed legacy variants already exist: %s. The approved canonical filename will be uploaded separately; existing variants will not be changed.', 'lavka-product-media-upload'),
                        implode(', ', $legacy_variants)
                    );
                }
            }

            if (!$errors) {
                $row['valid'] = true;
                $row['status'] = 'READY';
                $row['_upload'] = $file_candidates[0];
                $row['_product'] = $product_result['product'];
                if (!empty($resumable_uploads[$row['row_number']])) {
                    $row['_resume_attachment_id'] = (int) $resumable_uploads[$row['row_number']];
                }
            }

            $row['errors'] = array_values(array_unique(array_filter($errors)));
            $row['warnings'] = array_values(array_unique(array_filter($warnings)));
            $rows[] = $row;
        }

        $this->add_gallery_position_warnings($rows);

        $extra_files = [];
        foreach ($prepared_files['files'] as $file) {
            $key = mb_strtolower((string) $file['safe_name'], 'UTF-8');
            if (!isset($seen_sources[$key])) {
                $extra_files[] = [
                    'row_number' => '',
                    'sku' => '',
                    'barcode' => '',
                    'source_file' => $file['safe_name'],
                    'canonical_file' => '',
                    'format' => '',
                    'role' => '',
                    'position' => '',
                    'product_id' => 0,
                    'product_name' => '',
                    'product_type' => '',
                    'file_size' => (int) $file['size'],
                    'width' => 0,
                    'height' => 0,
                    'sha256' => '',
                    'color_space' => '',
                    'mime' => '',
                    'attachment_id' => 0,
                    'url' => '',
                    'status' => 'EXTRA_FILE',
                    'valid' => false,
                    'errors' => [],
                    'warnings' => [__('The selected file is not referenced by any registry row.', 'lavka-product-media-upload')],
                    'technical' => '',
                ];
            }
        }

        $summary = $this->summarize(array_merge($rows, $extra_files));

        return [
            'rows' => $rows,
            'extra_files' => $extra_files,
            'summary' => $summary,
            'capabilities' => [
                's3_index_check' => (bool) apply_filters(
                    'lavka_product_media_upload_s3_check_available',
                    $this->s3_index_available()
                ),
                'malware_scan' => (bool) apply_filters('lavka_product_media_upload_malware_scan_available', false),
                'perceptual_hash' => (bool) apply_filters('lavka_product_media_upload_phash_available', false),
            ],
        ];
    }

    public function wordpress_name_conflict(string $filename): int
    {
        global $wpdb;

        $like_path = '%/' . $wpdb->esc_like($filename);
        $like_url = '%/' . $wpdb->esc_like($filename);
        $sql = "
            SELECT DISTINCT p.ID
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} pm
              ON pm.post_id = p.ID AND pm.meta_key = '_wp_attached_file'
            WHERE p.post_type = 'attachment'
              AND (
                    BINARY pm.meta_value = BINARY %s
                 OR BINARY pm.meta_value LIKE BINARY %s
                 OR BINARY p.guid LIKE BINARY %s
              )
            LIMIT 1
        ";

        return (int) $wpdb->get_var($wpdb->prepare($sql, $filename, $like_path, $like_url));
    }

    private function legacy_auto_renamed_variants(string $filename): array
    {
        $extension = strtolower((string) pathinfo($filename, PATHINFO_EXTENSION));
        $stem = (string) pathinfo($filename, PATHINFO_FILENAME);
        if ($stem === '' || $extension === '') {
            return [];
        }

        global $wpdb;

        $pattern = '/^' . preg_quote($stem, '/') . '-[1-9][0-9]*\\.' . preg_quote($extension, '/') . '$/i';
        $like_tail = '%' . $wpdb->esc_like($stem . '-') . '%' . $wpdb->esc_like('.' . $extension);
        $variants = [];

        $wordpress_paths = $wpdb->get_col($wpdb->prepare(
            "
                SELECT pm.meta_value
                FROM {$wpdb->posts} p
                INNER JOIN {$wpdb->postmeta} pm
                  ON pm.post_id = p.ID AND pm.meta_key = '_wp_attached_file'
                WHERE p.post_type = 'attachment'
                  AND LOWER(pm.meta_value) LIKE LOWER(%s)
                LIMIT 50
            ",
            $like_tail
        ));
        foreach ($wordpress_paths as $path) {
            $candidate = wp_basename((string) $path);
            if (preg_match($pattern, $candidate)) {
                $variants[] = $candidate;
            }
        }

        $table = $this->s3_index_table();
        if ($table !== '') {
            $s3_pattern = $wpdb->esc_like(strtolower($stem) . '-') . '%' . $wpdb->esc_like('.' . $extension);
            $s3_rows = $wpdb->get_results(
                $wpdb->prepare(
                    "
                        SELECT filename_lower, full_key
                        FROM `{$table}`
                        WHERE LOWER(filename_lower) LIKE %s
                           OR LOWER(full_key) LIKE %s
                        LIMIT 50
                    ",
                    $s3_pattern,
                    '%' . $s3_pattern
                ),
                ARRAY_A
            );
            foreach ($s3_rows as $s3_row) {
                $candidate = wp_basename((string) ($s3_row['filename_lower'] ?: $s3_row['full_key']));
                if (preg_match($pattern, $candidate)) {
                    $variants[] = $candidate;
                }
            }
        }

        $variants = array_values(array_unique($variants));
        natcasesort($variants);
        return array_values($variants);
    }

    private function built_in_s3_name_check(string $filename): array
    {
        global $wpdb;

        $table = $this->s3_index_table();
        if ($table === '') {
            return ['available' => false, 'conflict' => false];
        }

        $like_key = '%/' . $wpdb->esc_like($filename);
        $sql = "
            SELECT id, filename_lower, full_key
            FROM `{$table}`
            WHERE BINARY filename_lower = BINARY %s
               OR BINARY full_key = BINARY %s
               OR BINARY full_key LIKE BINARY %s
            LIMIT 1
        ";
        $wpdb->last_error = '';
        $match = $wpdb->get_row(
            $wpdb->prepare($sql, strtolower($filename), $filename, $like_key),
            ARRAY_A
        );

        if ($wpdb->last_error !== '') {
            return [
                'available' => true,
                'conflict' => false,
                'error' => true,
                'technical' => $wpdb->last_error,
            ];
        }
        if (!is_array($match)) {
            return ['available' => true, 'conflict' => false];
        }

        return [
            'available' => true,
            'conflict' => true,
            'technical' => 's3_id=' . (int) ($match['id'] ?? 0)
                . '; full_key=' . (string) ($match['full_key'] ?? ''),
        ];
    }

    private function s3_index_available(): bool
    {
        return $this->s3_index_table() !== '';
    }

    private function s3_index_table(): string
    {
        if ($this->s3_table_checked) {
            return $this->s3_index_table;
        }

        global $wpdb;
        $this->s3_table_checked = true;
        $candidates = array_values(array_unique([
            $wpdb->prefix . 's3_media_index',
            's3_media_index',
        ]));

        foreach ($candidates as $candidate) {
            $found = (string) $wpdb->get_var(
                $wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($candidate))
            );
            if ($found === $candidate) {
                $this->s3_index_table = $candidate;
                break;
            }
        }

        return $this->s3_index_table;
    }

    private function prepare_uploads(array $uploads): array
    {
        $files = [];
        $by_name = [];

        foreach ($uploads as $upload) {
            $raw_name = (string) ($upload['name'] ?? '');
            $safe_name = wp_basename(str_replace('\\', '/', $raw_name));
            $upload['raw_name'] = RegistryReader::normalize_text($raw_name);
            $upload['safe_name'] = RegistryReader::normalize_text($safe_name);
            $upload['unsafe_name_error'] = $this->source_filename_error($upload['raw_name']);
            $files[] = $upload;
            $key = mb_strtolower($upload['safe_name'], 'UTF-8');
            $by_name[$key][] = $upload;
        }

        return ['files' => $files, 'by_name' => $by_name];
    }

    private function validate_file(array $file): array
    {
        $errors = [];
        $warnings = [];
        $status = 'FORMAT_ERROR';
        $technical = '';
        $tmp = (string) ($file['tmp_name'] ?? '');
        $name = (string) ($file['safe_name'] ?? '');
        $size = (int) ($file['size'] ?? 0);
        $upload_error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);

        if ($upload_error !== UPLOAD_ERR_OK) {
            return $this->file_failure('UPLOAD_FAILED', sprintf(
                /* translators: %d: PHP upload error code */
                __('PHP rejected the image upload with error %d.', 'lavka-product-media-upload'),
                $upload_error
            ));
        }
        if (!empty($file['unsafe_name_error'])) {
            return $this->file_failure('UNSAFE_FILENAME', (string) $file['unsafe_name_error']);
        }
        if ($tmp === '' || !is_file($tmp) || !is_readable($tmp) || !is_uploaded_file($tmp)) {
            return $this->file_failure('UPLOAD_FAILED', __('The image temporary file is missing or is not a valid HTTP upload.', 'lavka-product-media-upload'));
        }
        $actual_size = (int) filesize($tmp);
        if ($size < 1 || $actual_size < 1) {
            return $this->file_failure('FORMAT_ERROR', __('The image file is empty.', 'lavka-product-media-upload'));
        }
        if ($size !== $actual_size) {
            return $this->file_failure(
                'UPLOAD_FAILED',
                __('The uploaded byte count does not match the size declared by the request.', 'lavka-product-media-upload'),
                'declared=' . $size . '; actual=' . $actual_size
            );
        }
        if ($actual_size > (int) $this->thresholds['max_file_bytes']) {
            return $this->file_failure('FILE_TOO_LARGE', __('The image exceeds the configured hard file-size limit.', 'lavka-product-media-upload'));
        }
        if ($actual_size > (int) $this->thresholds['warn_file_bytes']) {
            $warnings[] = __('The image is larger than the configured warning threshold.', 'lavka-product-media-upload');
        }

        $source_error = $this->source_filename_error($name);
        if ($source_error !== '') {
            return $this->file_failure('UNSAFE_FILENAME', $source_error);
        }

        $source_extension = strtolower((string) pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($source_extension, ['jpg', 'jpeg', 'png', 'webp'], true)) {
            return $this->file_failure('FORMAT_ERROR', __('Only JPEG, PNG and WebP images are allowed.', 'lavka-product-media-upload'));
        }

        $head = (string) file_get_contents($tmp, false, null, 0, 64);
        $signature = $this->signature_format($head);
        if ($signature === '') {
            return $this->file_failure('FORMAT_ERROR', __('The file magic signature is not JPEG, PNG or WebP.', 'lavka-product-media-upload'));
        }

        if (!class_exists(\finfo::class)) {
            return $this->file_failure(
                'FORMAT_ERROR',
                __('The PHP Fileinfo extension is required for MIME verification.', 'lavka-product-media-upload')
            );
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $finfo_mime = (string) $finfo->file($tmp);
        $wp_mime = (string) wp_get_image_mime($tmp);
        $expected_mime = $this->mime_for_format($signature);
        $normalized_source_extension = $source_extension === 'jpeg' ? 'jpg' : $source_extension;

        $check = wp_check_filetype_and_ext($tmp, $name, [
            'jpg|jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
        ]);
        $check_mime = (string) ($check['type'] ?? '');

        if (
            $normalized_source_extension !== $signature
            || $finfo_mime !== $expected_mime
            || $wp_mime !== $expected_mime
            || $check_mime !== $expected_mime
        ) {
            return $this->file_failure(
                'FORMAT_ERROR',
                __('The extension, magic signature, server MIME and WordPress file check do not agree.', 'lavka-product-media-upload'),
                'ext=' . $normalized_source_extension . '; signature=' . $signature . '; finfo=' . $finfo_mime . '; wp=' . $wp_mime . '; check=' . $check_mime
            );
        }

        $container = $this->validate_container($tmp, $signature);
        if (!$container['ok']) {
            return $this->file_failure('FORMAT_ERROR', $container['message'], $container['technical'] ?? '');
        }
        $warnings = array_merge($warnings, $container['warnings'] ?? []);

        $geometry = @getimagesize($tmp);
        if (!is_array($geometry) || empty($geometry[0]) || empty($geometry[1])) {
            return $this->file_failure('DIMENSION_ERROR', __('The image dimensions could not be read.', 'lavka-product-media-upload'));
        }
        $width = (int) $geometry[0];
        $height = (int) $geometry[1];
        $pixels = $width * $height;
        if ($width < 1 || $height < 1) {
            return $this->file_failure('DIMENSION_ERROR', __('The image has invalid dimensions.', 'lavka-product-media-upload'));
        }
        if ($width > (int) $this->thresholds['max_side'] || $height > (int) $this->thresholds['max_side'] || $pixels > (int) $this->thresholds['max_pixels']) {
            return $this->file_failure('DIMENSION_ERROR', __('The image exceeds the configured geometry or total-pixel limit.', 'lavka-product-media-upload'));
        }
        $memory_error = $this->decode_memory_error($pixels, $actual_size);
        if ($memory_error !== '') {
            return $this->file_failure(
                'DIMENSION_ERROR',
                __('The image is within the configured pixel limit but cannot be decoded safely with the currently available PHP memory.', 'lavka-product-media-upload'),
                $memory_error
            );
        }
        if (min($width, $height) < (int) $this->thresholds['min_side_warn']) {
            $warnings[] = __('At least one image side is below the configured minimum-side warning.', 'lavka-product-media-upload');
        }
        if (max($width, $height) > (int) $this->thresholds['large_side_warn']) {
            $warnings[] = __('At least one image side is above the configured large-side warning.', 'lavka-product-media-upload');
        }
        if (
            min($width, $height) < (int) $this->thresholds['recommended_min']
            || max($width, $height) > (int) $this->thresholds['recommended_max']
        ) {
            $warnings[] = __('The image is outside the recommended side range.', 'lavka-product-media-upload');
        }
        $ratio = $width / $height;
        if ($ratio > (float) $this->thresholds['aspect_ratio_warn'] || $ratio < (1 / (float) $this->thresholds['aspect_ratio_warn'])) {
            $warnings[] = __('The image has an unusually wide or tall aspect ratio.', 'lavka-product-media-upload');
        }

        $bit_depth = isset($geometry['bits']) ? (int) $geometry['bits'] : 0;
        $channels = isset($geometry['channels']) ? (int) $geometry['channels'] : 0;
        if ($signature === 'jpg' && $bit_depth !== 0 && $bit_depth !== 8) {
            return $this->file_failure('FORMAT_ERROR', __('The JPEG bit depth is not supported by the current pipeline.', 'lavka-product-media-upload'));
        }
        if ($signature === 'jpg' && $channels === 4) {
            $warnings[] = __('The JPEG appears to use CMYK. RGB or sRGB is preferred for the storefront.', 'lavka-product-media-upload');
        }
        $color_space = $this->color_space($tmp, $signature, $channels);

        $decode = $this->full_decode($tmp, $width, $height, $signature);
        if (!$decode['ok']) {
            return $this->file_failure('DECODE_ERROR', $decode['message'], $decode['technical'] ?? '');
        }
        $warnings = array_merge($warnings, $decode['warnings']);

        $editor = wp_get_image_editor($tmp);
        if (is_wp_error($editor)) {
            return $this->file_failure(
                'DECODE_ERROR',
                __('The WordPress Image Editor could not open the image.', 'lavka-product-media-upload'),
                $editor->get_error_message()
            );
        }
        $editor_size = $editor->get_size();
        if (
            !is_array($editor_size)
            || (int) ($editor_size['width'] ?? 0) !== $width
            || (int) ($editor_size['height'] ?? 0) !== $height
        ) {
            return $this->file_failure(
                'DECODE_ERROR',
                __('The WordPress Image Editor reported unexpected image dimensions.', 'lavka-product-media-upload')
            );
        }
        unset($editor);

        $metadata_warnings = $this->metadata_warnings($tmp, $signature);
        $warnings = array_merge($warnings, $metadata_warnings);

        $malware = apply_filters(
            'lavka_product_media_upload_malware_scan',
            ['available' => false, 'clean' => true],
            $tmp,
            ['filename' => $name, 'sha256' => hash_file('sha256', $tmp) ?: '']
        );
        if (is_array($malware) && !empty($malware['available']) && empty($malware['clean'])) {
            return $this->file_failure('FORMAT_ERROR', __('The external malware scanner rejected the image.', 'lavka-product-media-upload'), (string) ($malware['technical'] ?? ''));
        }

        $visual = apply_filters(
            'lavka_product_media_upload_visual_analysis',
            ['warnings' => []],
            $tmp,
            ['width' => $width, 'height' => $height, 'format' => $signature]
        );
        if (is_array($visual)) {
            $warnings = array_merge($warnings, (array) ($visual['warnings'] ?? []));
        }

        return [
            'ok' => true,
            'status' => 'READY',
            'errors' => $errors,
            'warnings' => array_values(array_unique(array_filter($warnings))),
            'technical' => $technical,
            'format' => strtoupper($signature === 'jpg' ? 'JPEG' : $signature),
            'extension' => $signature,
            'mime' => $expected_mime,
            'width' => $width,
            'height' => $height,
            'size' => $actual_size,
            'sha256' => hash_file('sha256', $tmp) ?: '',
            'color_space' => $color_space,
        ];
    }

    private function canonical_filename(
        array $row,
        string $actual_extension,
        bool $generate_names
    ): array
    {
        $warnings = [];

        if (!empty($row['legacy']) || !$generate_names) {
            $source = (string) $row['source_file'];
            $stem = strtolower((string) pathinfo($source, PATHINFO_FILENAME));
        } else {
            $identifier = (string) ($row['sku'] ?: $row['barcode']);
            $stem_result = $this->canonical_stem($identifier);
            if (!$stem_result['ok']) {
                return $stem_result;
            }
            $stem = $stem_result['stem'];
            if (($row['role'] ?? '') === 'gallery') {
                $stem .= '_' . ((int) $row['position'] + 1);
            }
        }

        $filename = $stem . '.' . $actual_extension;
        if (strlen($filename) > (int) $this->thresholds['filename_max_length']) {
            return ['ok' => false, 'message' => __('The canonical filename exceeds the configured hard length limit.', 'lavka-product-media-upload')];
        }
        if (strlen($filename) > (int) $this->thresholds['filename_warn_length']) {
            $warnings[] = __('The canonical filename exceeds the configured warning length.', 'lavka-product-media-upload');
        }
        if (!preg_match('/^[a-z0-9][a-z0-9_-]*\.(?:jpg|png|webp)$/', $filename)) {
            return ['ok' => false, 'message' => __('The canonical filename contains unsupported characters.', 'lavka-product-media-upload')];
        }
        if (substr_count($filename, '.') !== 1 || $this->is_reserved_stem($stem)) {
            return ['ok' => false, 'message' => __('The canonical filename is hidden, reserved or has multiple extensions.', 'lavka-product-media-upload')];
        }

        return ['ok' => true, 'filename' => $filename, 'warnings' => $warnings];
    }

    private function canonical_stem(string $identifier): array
    {
        $identifier = RegistryReader::normalize_text($identifier);
        if ($identifier === '') {
            return ['ok' => false, 'message' => __('A canonical filename cannot be generated without an identifier.', 'lavka-product-media-upload')];
        }

        $mapping = (array) apply_filters('lavka_product_media_upload_sku_prefix_map', [
            'Ж' => 'g',
            'РСУ' => 'rcy',
        ]);

        $stem = $identifier;
        $matched = false;
        foreach ($mapping as $source_prefix => $target_prefix) {
            if ($source_prefix !== '' && mb_strpos($identifier, (string) $source_prefix, 0, 'UTF-8') === 0) {
                $stem = (string) $target_prefix . mb_substr($identifier, mb_strlen((string) $source_prefix, 'UTF-8'), null, 'UTF-8');
                $matched = true;
                break;
            }
        }

        if (!$matched && preg_match('/[^\x20-\x7E]/u', $identifier)) {
            return ['ok' => false, 'message' => __('The SKU uses a non-ASCII prefix without an explicit business mapping.', 'lavka-product-media-upload')];
        }

        $stem = strtolower($stem);
        $stem = preg_replace('/\s+/', '-', $stem) ?? $stem;
        $stem = preg_replace('/-+/', '-', $stem) ?? $stem;
        if (!preg_match('/^[a-z0-9_-]+$/', $stem)) {
            return ['ok' => false, 'message' => __('The identifier cannot be converted to a safe canonical filename without guessing.', 'lavka-product-media-upload')];
        }

        return ['ok' => true, 'stem' => $stem];
    }

    private function source_filename_error(string $name): string
    {
        if ($name === '') {
            return '';
        }
        if (preg_match('/[\x00-\x1F\x7F]/', $name)) {
            return __('The filename contains NUL or control characters.', 'lavka-product-media-upload');
        }
        if (
            str_contains($name, '../')
            || str_contains($name, '..\\')
            || str_contains($name, '/')
            || str_contains($name, '\\')
            || preg_match('/^(?:[a-z]:|\\\\|\/)/i', $name)
        ) {
            return __('The filename contains a path or path-traversal sequence.', 'lavka-product-media-upload');
        }
        if ($name[0] === '.' || substr_count($name, '.') !== 1) {
            return __('Hidden files and multiple extensions are not allowed.', 'lavka-product-media-upload');
        }

        $stem = (string) pathinfo($name, PATHINFO_FILENAME);
        if ($stem === '' || $this->is_reserved_stem($stem)) {
            return __('The filename has an empty or reserved basename.', 'lavka-product-media-upload');
        }

        return '';
    }

    private function is_reserved_stem(string $stem): bool
    {
        return (bool) preg_match('/^(?:con|prn|aux|nul|com[1-9]|lpt[1-9])$/i', $stem);
    }

    private function signature_format(string $head): string
    {
        if (str_starts_with($head, "\xFF\xD8\xFF")) {
            return 'jpg';
        }
        if (str_starts_with($head, "\x89PNG\x0D\x0A\x1A\x0A")) {
            return 'png';
        }
        if (strlen($head) >= 12 && substr($head, 0, 4) === 'RIFF' && substr($head, 8, 4) === 'WEBP') {
            return 'webp';
        }

        return '';
    }

    private function mime_for_format(string $format): string
    {
        return [
            'jpg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
        ][$format] ?? '';
    }

    private function color_space(string $path, string $format, int $channels): string
    {
        if ($format === 'jpg') {
            return $channels === 4 ? 'CMYK' : 'RGB';
        }

        $bytes = (string) file_get_contents($path);
        if ($format === 'png' && strlen($bytes) > 25) {
            $color_type = ord($bytes[25]);
            return in_array($color_type, [4, 6], true) ? 'RGBA' : 'RGB';
        }
        if ($format === 'webp') {
            return str_contains($bytes, 'ALPH') ? 'RGBA' : 'RGB';
        }

        return '';
    }

    private function decode_memory_error(int $pixels, int $file_size): string
    {
        $memory_limit = wp_convert_hr_to_bytes((string) ini_get('memory_limit'));
        if ($memory_limit < 1) {
            return '';
        }

        $usage = memory_get_usage(true);
        $estimated_decode_bytes = ($pixels * 5) + $file_size + (16 * MB_IN_BYTES);
        $available = max(0, $memory_limit - $usage);
        if ($estimated_decode_bytes <= (int) floor($available * 0.9)) {
            return '';
        }

        return 'memory_limit=' . $memory_limit
            . '; current_usage=' . $usage
            . '; estimated_decode=' . $estimated_decode_bytes;
    }

    private function validate_container(string $path, string $format): array
    {
        $bytes = (string) file_get_contents($path);
        if ($this->contains_suspicious_payload($bytes)) {
            return [
                'ok' => false,
                'message' => __('The file contains a suspicious executable or script signature.', 'lavka-product-media-upload'),
                'technical' => 'polyglot_signature',
            ];
        }

        if ($format === 'jpg') {
            $trimmed = rtrim($bytes, "\x00\x09\x0A\x0D\x20");
            if (!str_starts_with($bytes, "\xFF\xD8") || !str_ends_with($trimmed, "\xFF\xD9")) {
                return ['ok' => false, 'message' => __('The JPEG SOI/EOI structure is incomplete.', 'lavka-product-media-upload')];
            }
            return ['ok' => true, 'warnings' => []];
        }
        if ($format === 'png') {
            return $this->validate_png($bytes);
        }
        if ($format === 'webp') {
            return $this->validate_webp($bytes);
        }

        return ['ok' => false, 'message' => __('The image container is unsupported.', 'lavka-product-media-upload')];
    }

    private function validate_png(string $bytes): array
    {
        $length = strlen($bytes);
        $offset = 8;
        $found_iend = false;
        $animated = false;

        while ($offset + 12 <= $length) {
            $chunk_length = unpack('N', substr($bytes, $offset, 4))[1] ?? -1;
            $type = substr($bytes, $offset + 4, 4);
            if ($chunk_length < 0 || $offset + 12 + $chunk_length > $length) {
                return ['ok' => false, 'message' => __('The PNG chunk structure is invalid.', 'lavka-product-media-upload')];
            }
            $data = substr($bytes, $offset + 8, $chunk_length);
            $stored_crc = substr($bytes, $offset + 8 + $chunk_length, 4);
            $calculated_crc = pack('H*', hash('crc32b', $type . $data));
            if (!hash_equals($stored_crc, $calculated_crc)) {
                return ['ok' => false, 'message' => __('A PNG chunk CRC check failed.', 'lavka-product-media-upload'), 'technical' => $type];
            }
            if ($type === 'acTL') {
                $animated = true;
            }
            $offset += 12 + $chunk_length;
            if ($type === 'IEND') {
                $found_iend = true;
                break;
            }
        }

        if (!$found_iend || $offset !== $length) {
            return ['ok' => false, 'message' => __('The PNG does not end cleanly at the IEND chunk.', 'lavka-product-media-upload')];
        }
        if ($animated) {
            return ['ok' => false, 'message' => __('Animated PNG files are not allowed for product images.', 'lavka-product-media-upload')];
        }

        return ['ok' => true, 'warnings' => []];
    }

    private function validate_webp(string $bytes): array
    {
        $length = strlen($bytes);
        if ($length < 20) {
            return ['ok' => false, 'message' => __('The WebP container is too short.', 'lavka-product-media-upload')];
        }
        $declared = unpack('V', substr($bytes, 4, 4))[1] ?? 0;
        if ($declared + 8 !== $length) {
            return ['ok' => false, 'message' => __('The WebP RIFF size does not match the file size.', 'lavka-product-media-upload')];
        }

        $offset = 12;
        $animated = false;
        while ($offset + 8 <= $length) {
            $type = substr($bytes, $offset, 4);
            $chunk_length = unpack('V', substr($bytes, $offset + 4, 4))[1] ?? -1;
            if ($chunk_length < 0 || $offset + 8 + $chunk_length > $length) {
                return ['ok' => false, 'message' => __('The WebP chunk structure is invalid.', 'lavka-product-media-upload')];
            }
            if ($type === 'ANIM' || $type === 'ANMF') {
                $animated = true;
            }
            if ($type === 'VP8X' && $chunk_length >= 1) {
                $flags = ord($bytes[$offset + 8]);
                if (($flags & 0x02) !== 0) {
                    $animated = true;
                }
            }
            $offset += 8 + $chunk_length + ($chunk_length % 2);
        }
        if ($offset !== $length) {
            return ['ok' => false, 'message' => __('The WebP container has trailing or incomplete data.', 'lavka-product-media-upload')];
        }
        if ($animated) {
            return ['ok' => false, 'message' => __('Animated WebP files are not allowed for product images.', 'lavka-product-media-upload')];
        }

        return ['ok' => true, 'warnings' => []];
    }

    private function full_decode(string $path, int $width, int $height, string $format): array
    {
        $warnings = [];
        $decoder_messages = [];
        $previous_handler = set_error_handler(static function (int $severity, string $message) use (&$decoder_messages): bool {
            $decoder_messages[] = $message;
            return true;
        });

        try {
            $bytes = file_get_contents($path);
            $image = $bytes !== false ? imagecreatefromstring($bytes) : false;
        } finally {
            restore_error_handler();
        }

        if ($image === false) {
            return [
                'ok' => false,
                'message' => __('The server image decoder could not fully decode the file.', 'lavka-product-media-upload'),
                'technical' => implode(' | ', $decoder_messages),
            ];
        }
        if (imagesx($image) !== $width || imagesy($image) !== $height) {
            imagedestroy($image);
            return [
                'ok' => false,
                'message' => __('Decoded image dimensions differ from the header dimensions.', 'lavka-product-media-upload'),
            ];
        }

        if ($decoder_messages) {
            if (!empty($this->thresholds['strict_decoder_warnings'])) {
                imagedestroy($image);
                return [
                    'ok' => false,
                    'message' => __('The image decoder reported a warning in strict mode.', 'lavka-product-media-upload'),
                    'technical' => implode(' | ', $decoder_messages),
                ];
            }
            $warnings[] = __('The image decoder reported a non-blocking warning.', 'lavka-product-media-upload');
        }

        $warnings = array_merge($warnings, $this->sample_visual_warnings($image, $format));
        imagedestroy($image);

        return ['ok' => true, 'warnings' => $warnings];
    }

    private function sample_visual_warnings($image, string $format): array
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $steps_x = min(32, $width);
        $steps_y = min(32, $height);
        $count = 0;
        $sum = 0.0;
        $sum_square = 0.0;
        $transparent = 0;

        for ($y_index = 0; $y_index < $steps_y; $y_index++) {
            $y = min($height - 1, (int) floor(($y_index + 0.5) * $height / $steps_y));
            for ($x_index = 0; $x_index < $steps_x; $x_index++) {
                $x = min($width - 1, (int) floor(($x_index + 0.5) * $width / $steps_x));
                $rgba = imagecolorat($image, $x, $y);
                $red = ($rgba >> 16) & 0xFF;
                $green = ($rgba >> 8) & 0xFF;
                $blue = $rgba & 0xFF;
                $alpha = ($rgba >> 24) & 0x7F;
                $luminance = 0.2126 * $red + 0.7152 * $green + 0.0722 * $blue;
                $sum += $luminance;
                $sum_square += $luminance * $luminance;
                $count++;
                if ($format === 'png' && $alpha >= 120) {
                    $transparent++;
                }
            }
        }

        if ($count < 1) {
            return [];
        }
        $mean = $sum / $count;
        $variance = max(0.0, ($sum_square / $count) - ($mean * $mean));
        $warnings = [];
        if ($variance < 25.0) {
            $warnings[] = __('The image appears almost uniform or has very low contrast.', 'lavka-product-media-upload');
        } elseif ($variance < 100.0) {
            $warnings[] = __('The image appears to have low contrast.', 'lavka-product-media-upload');
        }
        if ($transparent / $count > 0.95) {
            $warnings[] = __('The image appears to be almost completely transparent.', 'lavka-product-media-upload');
        }

        return $warnings;
    }

    private function metadata_warnings(string $path, string $format): array
    {
        $warnings = [];
        $bytes = (string) file_get_contents($path);
        if (
            str_contains($bytes, 'ICC_PROFILE')
            || str_contains($bytes, 'iCCP')
            || str_contains($bytes, 'ICCP')
        ) {
            $warnings[] = __('The image contains an ICC profile. Verify storefront color rendering.', 'lavka-product-media-upload');
        }

        if ($format === 'jpg' && function_exists('exif_read_data')) {
            $exif = @exif_read_data($path, null, true, false);
            if (is_array($exif)) {
                $orientation = (int) ($exif['IFD0']['Orientation'] ?? 1);
                if ($orientation > 1) {
                    $warnings[] = __('The JPEG uses EXIF orientation and may require explicit autorotation.', 'lavka-product-media-upload');
                }
                if (!empty($exif['GPS'])) {
                    $warnings[] = __('The JPEG contains GPS metadata.', 'lavka-product-media-upload');
                }
                if (count($exif) > 0) {
                    $warnings[] = __('The JPEG contains EXIF metadata. Metadata is not stripped in version 1.', 'lavka-product-media-upload');
                }
            }
        }

        return $warnings;
    }

    private function contains_suspicious_payload(string $bytes): bool
    {
        $patterns = [
            '/<\?(?:php|=)/i',
            '/<script\b/i',
            '/\b(?:eval|assert)\s*\(/i',
        ];
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $bytes)) {
                return true;
            }
        }

        return false;
    }

    private function has_mixed_lookalike_scripts(string $value): bool
    {
        $has_latin = (bool) preg_match('/[ABCEHKMOPTXabcehkmoptx]/u', $value);
        $has_cyrillic = (bool) preg_match('/[АВЕКМНОРСТХавекмнорстх]/u', $value);
        return $has_latin && $has_cyrillic;
    }

    private function base_result(array $row): array
    {
        return [
            'row_number' => (int) ($row['row_number'] ?? 0),
            'sku' => (string) ($row['sku'] ?? ''),
            'barcode' => (string) ($row['barcode'] ?? ''),
            'source_file' => (string) ($row['source_file'] ?? ''),
            'canonical_file' => '',
            'format' => '',
            'role' => (string) ($row['role'] ?? ''),
            'position' => (string) ($row['position'] ?? ''),
            'product_id' => 0,
            'product_name' => '',
            'product_type' => '',
            'file_size' => 0,
            'width' => 0,
            'height' => 0,
            'sha256' => '',
            'color_space' => '',
            'mime' => '',
            'attachment_id' => 0,
            'url' => '',
            'status' => 'MANIFEST_ERROR',
            'valid' => false,
            'errors' => [],
            'warnings' => [],
            'technical' => '',
        ];
    }

    private function file_failure(string $status, string $message, string $technical = ''): array
    {
        return [
            'ok' => false,
            'status' => $status,
            'errors' => [$message],
            'warnings' => [],
            'technical' => $technical,
        ];
    }

    private function existing_attachment_matches_source(int $attachment_id, array $row): bool
    {
        if (get_post_type($attachment_id) !== 'attachment' || (string) ($row['sha256'] ?? '') === '') {
            return false;
        }

        $known_hash = (string) get_post_meta($attachment_id, '_lpmu_source_sha256', true);
        if ($known_hash !== '') {
            return hash_equals($known_hash, (string) $row['sha256']);
        }

        $path = get_attached_file($attachment_id, true);
        if (is_string($path) && $path !== '' && is_file($path) && is_readable($path)) {
            $actual_hash = hash_file('sha256', $path);
            return is_string($actual_hash) && hash_equals($actual_hash, (string) $row['sha256']);
        }

        return $this->s3_object_matches_source((string) ($row['canonical_file'] ?? ''), $row);
    }

    private function s3_object_matches_source(string $filename, array $row): bool
    {
        global $wpdb;

        $table = $this->s3_index_table();
        if ($table === '' || $filename === '') {
            return false;
        }

        if ($this->s3_index_columns === null) {
            $this->s3_index_columns = $wpdb->get_col("SHOW COLUMNS FROM `{$table}`", 0);
        }
        $columns = $this->s3_index_columns;
        if (!in_array('size_bytes', $columns, true) || !in_array('etag', $columns, true)) {
            return false;
        }

        $match = $wpdb->get_row($wpdb->prepare(
            "SELECT size_bytes, etag FROM `{$table}` WHERE BINARY filename_lower = BINARY %s LIMIT 1",
            mb_strtolower($filename, 'UTF-8')
        ), ARRAY_A);
        if (!is_array($match) || (int) ($match['size_bytes'] ?? 0) !== (int) ($row['file_size'] ?? 0)) {
            return false;
        }

        $etag = strtolower(trim((string) ($match['etag'] ?? ''), "\"' "));
        $source_tmp = (string) (($row['_upload']['tmp_name'] ?? ''));
        if (!preg_match('/^[a-f0-9]{32}$/', $etag) || $source_tmp === '' || !is_file($source_tmp)) {
            return false;
        }

        $source_md5 = md5_file($source_tmp);
        return is_string($source_md5) && hash_equals($etag, strtolower($source_md5));
    }

    private function is_matching_partial_upload(int $attachment_id, array $row): bool
    {
        return get_post_type($attachment_id) === 'attachment'
            && (string) get_post_meta($attachment_id, '_lpmu_source_sha256', true) !== ''
            && hash_equals(
                (string) get_post_meta($attachment_id, '_lpmu_source_sha256', true),
                (string) ($row['sha256'] ?? '')
            )
            && (int) get_post_meta($attachment_id, '_lpmu_product_id', true) === (int) ($row['product_id'] ?? 0)
            && (string) get_post_meta($attachment_id, '_lpmu_role', true) === (string) ($row['role'] ?? '')
            && (int) get_post_meta($attachment_id, '_lpmu_position', true) === (int) ($row['position'] ?? 0);
    }

    private function add_gallery_position_warnings(array &$rows): void
    {
        $positions = [];
        foreach ($rows as $index => $row) {
            if (($row['role'] ?? '') !== 'gallery' || (int) ($row['product_id'] ?? 0) < 1) {
                continue;
            }
            $product_id = (int) $row['product_id'];
            $positions[$product_id]['indexes'][] = $index;
            $positions[$product_id]['values'][] = (int) ($row['position'] ?? 0);
        }

        foreach ($positions as $group) {
            $values = array_values(array_unique(array_filter($group['values'])));
            sort($values, SORT_NUMERIC);
            if (!$values || $values === range(1, max($values))) {
                continue;
            }
            foreach ($group['indexes'] as $index) {
                $rows[$index]['warnings'][] = __('Gallery positions contain gaps. Version 1 will not renumber them automatically.', 'lavka-product-media-upload');
                $rows[$index]['warnings'] = array_values(array_unique($rows[$index]['warnings']));
            }
        }
    }

    private function summarize(array $rows): array
    {
        $summary = [
            'total' => count($rows),
            'ready' => 0,
            'errors' => 0,
            'warnings' => 0,
            'success' => 0,
        ];
        foreach ($rows as $row) {
            if (!empty($row['valid'])) {
                $summary['ready']++;
            }
            if (!empty($row['errors'])) {
                $summary['errors']++;
            }
            if (!empty($row['warnings'])) {
                $summary['warnings']++;
            }
            if (($row['status'] ?? '') === 'SUCCESS') {
                $summary['success']++;
            }
        }

        return $summary;
    }
}
