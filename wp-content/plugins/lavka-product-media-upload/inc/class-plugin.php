<?php

namespace Lavka\ProductMediaUpload;

if (!defined('ABSPATH')) {
    exit;
}

final class Plugin
{
    private const PAGE_SLUG = 'lavka-product-media-upload';
    private const NONCE_ACTION = 'lpmu_batch_action';

    private static ?self $instance = null;
    private string $page_hook = '';

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public function boot(): void
    {
        add_action('init', [$this, 'load_textdomain']);
        add_action('admin_menu', [$this, 'register_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('wp_ajax_lpmu_dry_run', [$this, 'ajax_dry_run']);
        if (\lpmu_writes_enabled()) {
            add_action('wp_ajax_lpmu_upload_batch', [$this, 'ajax_upload_batch']);
        }
        add_action('admin_post_lpmu_download_report', [$this, 'download_report']);
        add_action('admin_post_lpmu_download_template', [$this, 'download_template']);
        add_action('admin_post_lpmu_save_validation_settings', [$this, 'save_validation_settings']);
    }

    public function load_textdomain(): void
    {
        load_plugin_textdomain(
            'lavka-product-media-upload',
            false,
            dirname(plugin_basename(LPMU_FILE)) . '/languages'
        );
    }

    public function register_menu(): void
    {
        $this->page_hook = (string) add_submenu_page(
            'upload.php',
            __('Product image batches', 'lavka-product-media-upload'),
            __('Product image batches', 'lavka-product-media-upload'),
            $this->capability(),
            self::PAGE_SLUG,
            [$this, 'render_page']
        );
    }

    public function enqueue_assets(string $hook): void
    {
        if ($hook !== $this->page_hook) {
            return;
        }

        wp_enqueue_style(
            'lpmu-admin',
            LPMU_URL . 'assets/admin.css',
            [],
            LPMU_VERSION
        );
        wp_enqueue_script(
            'lpmu-admin',
            LPMU_URL . 'assets/admin.js',
            [],
            LPMU_VERSION,
            true
        );

        wp_localize_script('lpmu-admin', 'LPMU_DATA', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce(self::NONCE_ACTION),
            'maxFileBytes' => ImageValidator::thresholds()['max_file_bytes'],
            'maxFiles' => max(1, (int) ini_get('max_file_uploads') - 1),
            'maxRequestBytes' => (int) floor(wp_max_upload_size() * 0.95),
            'writesEnabled' => \lpmu_writes_enabled(),
            'strings' => [
                'checking' => __('Checking the batch…', 'lavka-product-media-upload'),
                'uploading' => __('Uploading to OVH/S3, synchronizing Folio and assigning WooCommerce images…', 'lavka-product-media-upload'),
                'requestFailed' => __('The server request failed.', 'lavka-product-media-upload'),
                'selectRegistry' => __('Select an XLS or XLSX registry.', 'lavka-product-media-upload'),
                'selectImages' => __('Select at least one image.', 'lavka-product-media-upload'),
                /* translators: %d: server-side maximum number of uploaded files */
                'tooManyImages' => __('The server accepts at most %d image files per check. Split this set into smaller batches.', 'lavka-product-media-upload'),
                /* translators: 1: total selected file size, 2: safe server request size limit */
                'requestTooLarge' => __('The selected registry and images total %1$s, but the safe server request limit is %2$s. Split the images into smaller batches.', 'lavka-product-media-upload'),
                'dryRunRequired' => __('Run the mandatory check before uploading.', 'lavka-product-media-upload'),
                /* translators: %d: number of selected image files */
                'filesSelected' => __('%d image files selected', 'lavka-product-media-upload'),
                'dropHint' => __('Drop JPEG, PNG or WebP files here', 'lavka-product-media-upload'),
                'chooseFolder' => __('Choose an image folder', 'lavka-product-media-upload'),
                'ready' => __('Ready', 'lavka-product-media-upload'),
                'errors' => __('Errors', 'lavka-product-media-upload'),
                'warnings' => __('Warnings', 'lavka-product-media-upload'),
                'details' => __('Technical details', 'lavka-product-media-upload'),
                'noWarnings' => __('No warnings', 'lavka-product-media-upload'),
                'reportReady' => __('The operation is complete. Download the report for the full audit trail.', 'lavka-product-media-upload'),
                'reportPartial' => __('The operation finished, but some rows require a safe retry. Review the errors and download the report.', 'lavka-product-media-upload'),
                'confirmUpload' => __('Upload all approved images, refresh the S3 index, update Folio and assign the images in WooCommerce?', 'lavka-product-media-upload'),
                's3Unavailable' => __('The S3 media index is unavailable, so remote filename conflicts were not checked.', 'lavka-product-media-upload'),
                'extraFile' => __('Not referenced by the registry', 'lavka-product-media-upload'),
                'row' => __('Row', 'lavka-product-media-upload'),
                'identifiers' => __('Identifiers', 'lavka-product-media-upload'),
                'skuLabel' => __('SKU', 'lavka-product-media-upload'),
                'gtinLabel' => __('GTIN', 'lavka-product-media-upload'),
                'source' => __('Source file', 'lavka-product-media-upload'),
                'canonical' => __('Canonical file', 'lavka-product-media-upload'),
                'image' => __('Image', 'lavka-product-media-upload'),
                'assignment' => __('Assignment', 'lavka-product-media-upload'),
                'result' => __('Result', 'lavka-product-media-upload'),
                'product' => __('Product', 'lavka-product-media-upload'),
                'role' => __('Role', 'lavka-product-media-upload'),
                'position' => __('Position', 'lavka-product-media-upload'),
                'size' => __('Size', 'lavka-product-media-upload'),
                'dimensions' => __('Dimensions', 'lavka-product-media-upload'),
                'colorSpace' => __('Color space', 'lavka-product-media-upload'),
                'sha256' => __('SHA-256', 'lavka-product-media-upload'),
                'attachment' => __('Attachment', 'lavka-product-media-upload'),
                'total' => __('Total', 'lavka-product-media-upload'),
                'approved' => __('Approved', 'lavka-product-media-upload'),
                'successful' => __('Successful', 'lavka-product-media-upload'),
                'partial' => __('Requires retry', 'lavka-product-media-upload'),
                'workflow' => __('Workflow stage', 'lavka-product-media-upload'),
                'folioOperation' => __('Folio operation', 'lavka-product-media-upload'),
                'folioStatus' => __('Folio status', 'lavka-product-media-upload'),
                's3Key' => __('S3 key', 'lavka-product-media-upload'),
                'workflowStageLabels' => [
                    's3_reindex' => __('Refreshing the S3 index', 'lavka-product-media-upload'),
                    's3_reindex_retry' => __('Repeating the S3 index refresh', 'lavka-product-media-upload'),
                    's3_proof' => __('Checking the S3 object proof', 'lavka-product-media-upload'),
                    's3_verified' => __('S3 object verified', 'lavka-product-media-upload'),
                    'folio_search' => __('Reading Folio references', 'lavka-product-media-upload'),
                    'folio_prepare' => __('Preparing the Folio change', 'lavka-product-media-upload'),
                    'folio_preview' => __('Checking the Folio preview', 'lavka-product-media-upload'),
                    'folio_apply' => __('Applying the Folio change', 'lavka-product-media-upload'),
                    'woo_assignment' => __('Assigning the image in WooCommerce', 'lavka-product-media-upload'),
                    'completed' => __('Completed', 'lavka-product-media-upload'),
                ],
                'folioOperationLabels' => [
                    'set_main' => __('Set main image', 'lavka-product-media-upload'),
                    'update_gallery' => __('Update gallery image', 'lavka-product-media-upload'),
                    'add_gallery' => __('Add gallery image', 'lavka-product-media-upload'),
                ],
                'folioStatusLabels' => [
                    'ready' => __('Ready', 'lavka-product-media-upload'),
                    'applied' => __('Applied', 'lavka-product-media-upload'),
                    'noop' => __('Already correct', 'lavka-product-media-upload'),
                    'blocked' => __('Blocked', 'lavka-product-media-upload'),
                ],
                'statusLabels' => [
                    'READY' => __('Verification passed', 'lavka-product-media-upload'),
                    'UPLOADED' => __('Uploaded; synchronization is pending', 'lavka-product-media-upload'),
                    'SUCCESS' => __('Full workflow completed', 'lavka-product-media-upload'),
                    'MANIFEST_ERROR' => __('Registry error', 'lavka-product-media-upload'),
                    'UNSAFE_FILENAME' => __('Unsafe filename', 'lavka-product-media-upload'),
                    'FILE_NOT_FOUND' => __('File not found', 'lavka-product-media-upload'),
                    'EXTRA_FILE' => __('Extra file', 'lavka-product-media-upload'),
                    'FORMAT_ERROR' => __('Invalid image format', 'lavka-product-media-upload'),
                    'DECODE_ERROR' => __('Image decode failed', 'lavka-product-media-upload'),
                    'DIMENSION_ERROR' => __('Invalid image dimensions', 'lavka-product-media-upload'),
                    'FILE_TOO_LARGE' => __('File is too large', 'lavka-product-media-upload'),
                    'DUPLICATE_IN_BATCH' => __('Duplicate in batch', 'lavka-product-media-upload'),
                    'POSSIBLE_VISUAL_DUPLICATE' => __('Possible visual duplicate', 'lavka-product-media-upload'),
                    'PRODUCT_NOT_FOUND' => __('Product not found', 'lavka-product-media-upload'),
                    'IDENTIFIER_MISMATCH' => __('Identifier mismatch', 'lavka-product-media-upload'),
                    'NAME_CONFLICT_WP' => __('WordPress filename conflict', 'lavka-product-media-upload'),
                    'NAME_CONFLICT_S3' => __('S3 filename conflict', 'lavka-product-media-upload'),
                    'UPLOAD_FAILED' => __('Upload failed', 'lavka-product-media-upload'),
                    'METADATA_FAILED' => __('Attachment metadata failed', 'lavka-product-media-upload'),
                    'OVH_VERIFY_FAILED' => __('OVH verification failed', 'lavka-product-media-upload'),
                    'S3_INDEX_FAILED' => __('S3 index refresh failed', 'lavka-product-media-upload'),
                    'S3_PROOF_FAILED' => __('S3 object proof failed', 'lavka-product-media-upload'),
                    'FOLIO_SEARCH_FAILED' => __('Folio lookup failed', 'lavka-product-media-upload'),
                    'FOLIO_PREVIEW_BLOCKED' => __('Folio preview blocked', 'lavka-product-media-upload'),
                    'FOLIO_APPLY_FAILED' => __('Folio update failed', 'lavka-product-media-upload'),
                    'WOO_ASSIGN_FAILED' => __('WooCommerce image assignment failed', 'lavka-product-media-upload'),
                ],
            ],
        ]);
    }

    public function render_page(): void
    {
        if (!$this->can_operate()) {
            wp_die(esc_html__('You do not have permission to check product images.', 'lavka-product-media-upload'));
        }

        $thresholds = ImageValidator::thresholds();
        $template_url = wp_nonce_url(
            admin_url('admin-post.php?action=lpmu_download_template'),
            'lpmu_download_template'
        );
        ?>
        <div class="wrap lpmu-wrap">
            <h1><?php echo esc_html__('Product image batch workflow', 'lavka-product-media-upload'); ?></h1>
            <p class="description">
                <?php echo esc_html__('Check the XLS/XLSX registry, then upload approved images and synchronize OVH/S3, Folio and WooCommerce in one controlled operation.', 'lavka-product-media-upload'); ?>
            </p>

            <?php if (!\lpmu_writes_enabled()) : ?>
                <div class="notice notice-info inline">
                    <p>
                        <strong><?php echo esc_html__('Verification-only mode is active.', 'lavka-product-media-upload'); ?></strong>
                        <?php echo esc_html__('This stage does not write files to WordPress, OVH/S3 or product records.', 'lavka-product-media-upload'); ?>
                    </p>
                </div>
            <?php endif; ?>

            <div class="notice notice-warning inline">
                <p><?php echo esc_html__('Before checking a batch, run a fresh Total Sync and refresh the S3 media index so product and conflict data are current.', 'lavka-product-media-upload'); ?></p>
            </div>

            <?php if (!empty($_GET['settings-updated'])) : ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php echo esc_html__('Validation settings were saved.', 'lavka-product-media-upload'); ?></p>
                </div>
            <?php endif; ?>

            <?php if (!$this->dependencies_ready()) : ?>
                <div class="notice notice-error inline">
                    <p><?php echo esc_html__('WooCommerce or PhpSpreadsheet is unavailable. Batch processing is disabled.', 'lavka-product-media-upload'); ?></p>
                </div>
            <?php endif; ?>

            <section class="lpmu-panel">
                <h2><?php echo esc_html__('1. Select the registry and images', 'lavka-product-media-upload'); ?></h2>
                <div class="lpmu-grid">
                    <div>
                        <label class="lpmu-label" for="lpmu-registry">
                            <?php echo esc_html__('Registry file', 'lavka-product-media-upload'); ?>
                        </label>
                        <input id="lpmu-registry" type="file" accept=".xls,.xlsx,application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet">
                        <p class="description">
                            <?php echo esc_html__('The current four-column XLS and the new header-based template are supported.', 'lavka-product-media-upload'); ?>
                            <a href="<?php echo esc_url($template_url); ?>"><?php echo esc_html__('Download the new XLSX template', 'lavka-product-media-upload'); ?></a>
                        </p>
                    </div>
                    <div>
                        <label class="lpmu-label" for="lpmu-images">
                            <?php echo esc_html__('Source images', 'lavka-product-media-upload'); ?>
                        </label>
                        <div id="lpmu-drop-zone" class="lpmu-drop-zone" tabindex="0">
                            <span class="dashicons dashicons-format-image" aria-hidden="true"></span>
                            <strong><?php echo esc_html__('Drop JPEG, PNG or WebP files here', 'lavka-product-media-upload'); ?></strong>
                            <span><?php echo esc_html__('or choose multiple files', 'lavka-product-media-upload'); ?></span>
                        </div>
                        <input id="lpmu-images" class="screen-reader-text" type="file" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp" multiple>
                        <input id="lpmu-folder" class="screen-reader-text" type="file" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp" multiple webkitdirectory directory>
                        <p class="lpmu-source-actions">
                            <button id="lpmu-folder-button" class="button" type="button">
                                <span class="dashicons dashicons-open-folder" aria-hidden="true"></span>
                                <?php echo esc_html__('Choose an image folder', 'lavka-product-media-upload'); ?>
                            </button>
                        </p>
                        <p id="lpmu-file-count" class="description"></p>
                        <p class="description">
                            <?php echo esc_html__('Folder selection reads image files from the chosen folder. Original files and names on your computer are never changed.', 'lavka-product-media-upload'); ?>
                        </p>
                        <p class="description">
                            <?php
                            printf(
                                /* translators: %d: server-side maximum number of uploaded files */
                                esc_html__('Server limit: up to %d image files per check.', 'lavka-product-media-upload'),
                                max(1, (int) ini_get('max_file_uploads') - 1)
                            );
                            ?>
                        </p>
                    </div>
                </div>

                <label class="lpmu-confirm">
                    <input id="lpmu-legacy-main" type="checkbox" value="1">
                    <?php echo esc_html__('For a four-column registry without roles, explicitly treat every row as a main product image.', 'lavka-product-media-upload'); ?>
                </label>
                <label class="lpmu-confirm">
                    <input id="lpmu-generate-names" type="checkbox" value="1" checked>
                    <?php echo esc_html__('For header-based registries, generate canonical filenames from SKU or barcode. Legacy four-column registries always use column 2 as the authoritative filename.', 'lavka-product-media-upload'); ?>
                </label>
            </section>

            <section class="lpmu-panel lpmu-thresholds">
                <details>
                    <summary><?php echo esc_html__('Validation thresholds and capabilities', 'lavka-product-media-upload'); ?></summary>
                    <form class="lpmu-settings-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <input type="hidden" name="action" value="lpmu_save_validation_settings">
                        <?php wp_nonce_field('lpmu_save_validation_settings'); ?>
                        <div class="lpmu-settings-grid">
                            <?php
                            $this->number_setting(
                                'max_file_mib',
                                __('Maximum file size (MiB)', 'lavka-product-media-upload'),
                                (float) $thresholds['max_file_bytes'] / MB_IN_BYTES,
                                1,
                                100,
                                0.5
                            );
                            $this->number_setting(
                                'warn_file_mib',
                                __('File size warning (MiB)', 'lavka-product-media-upload'),
                                (float) $thresholds['warn_file_bytes'] / MB_IN_BYTES,
                                1,
                                100,
                                0.5
                            );
                            $this->number_setting('min_side_warn', __('Minimum side warning (px)', 'lavka-product-media-upload'), $thresholds['min_side_warn'], 1, 12000);
                            $this->number_setting('recommended_min', __('Recommended minimum side (px)', 'lavka-product-media-upload'), $thresholds['recommended_min'], 1, 12000);
                            $this->number_setting('recommended_max', __('Recommended maximum side (px)', 'lavka-product-media-upload'), $thresholds['recommended_max'], 1, 12000);
                            $this->number_setting('large_side_warn', __('Large side warning (px)', 'lavka-product-media-upload'), $thresholds['large_side_warn'], 1, 12000);
                            $this->number_setting('max_side', __('Hard maximum side (px)', 'lavka-product-media-upload'), $thresholds['max_side'], 100, 50000);
                            $this->number_setting('max_pixels', __('Hard maximum total pixels', 'lavka-product-media-upload'), $thresholds['max_pixels'], 1000000, 500000000);
                            $this->number_setting('aspect_ratio_warn', __('Aspect-ratio warning threshold', 'lavka-product-media-upload'), $thresholds['aspect_ratio_warn'], 1.1, 20, 0.1);
                            $this->number_setting('filename_warn_length', __('Filename warning length', 'lavka-product-media-upload'), $thresholds['filename_warn_length'], 10, 100);
                            $this->number_setting('filename_max_length', __('Filename hard length limit', 'lavka-product-media-upload'), $thresholds['filename_max_length'], 20, 255);
                            ?>
                        </div>
                        <label>
                            <input type="checkbox" name="thresholds[strict_decoder_warnings]" value="1" <?php checked(!empty($thresholds['strict_decoder_warnings'])); ?>>
                            <?php echo esc_html__('Treat image decoder warnings as blocking errors.', 'lavka-product-media-upload'); ?>
                        </label>
                        <p>
                            <button type="submit" class="button"><?php echo esc_html__('Save validation settings', 'lavka-product-media-upload'); ?></button>
                        </p>
                    </form>
                    <p>
                        <?php echo esc_html__('The server verifies signatures, MIME, a full image decode, dimensions, metadata and WordPress name conflicts. ClamAV, perceptual hashing and expensive blur/content analysis are extension points and are not claimed as active unless an integration provides them.', 'lavka-product-media-upload'); ?>
                    </p>
                    <p>
                        <?php echo esc_html__('These values apply only to this batch uploader. The global WordPress upload limit is not changed.', 'lavka-product-media-upload'); ?>
                    </p>
                </details>
            </section>

            <div class="lpmu-actions">
                <button id="lpmu-check" class="button button-primary" type="button" <?php disabled(!$this->dependencies_ready()); ?>>
                    <?php echo esc_html__('Check without writing', 'lavka-product-media-upload'); ?>
                </button>
                <?php if (\lpmu_writes_enabled()) : ?>
                    <button id="lpmu-upload" class="button button-primary lpmu-hidden" type="button">
                        <?php echo esc_html__('Upload and synchronize approved rows', 'lavka-product-media-upload'); ?>
                    </button>
                <?php endif; ?>
                <a id="lpmu-report-link" class="button lpmu-hidden" href="#">
                    <?php echo esc_html__('Download CSV report', 'lavka-product-media-upload'); ?>
                </a>
                <span id="lpmu-spinner" class="spinner"></span>
            </div>

            <div id="lpmu-summary" class="lpmu-summary" aria-live="polite"></div>
            <div id="lpmu-results" class="lpmu-results"></div>
        </div>
        <?php
    }

    public function ajax_dry_run(): void
    {
        $this->guard_ajax();

        try {
            $service = new BatchService();
            $result = $service->dry_run(
                $this->registry_upload(),
                $this->image_uploads(),
                !empty($_POST['legacy_main_confirm']),
                !empty($_POST['generate_names'])
            );
            wp_send_json_success($result);
        } catch (\Throwable $e) {
            wp_send_json_error([
                'message' => __('The batch could not be checked.', 'lavka-product-media-upload'),
                'technical' => $e->getMessage(),
            ], 400);
        }
    }

    public function ajax_upload_batch(): void
    {
        if (!\lpmu_writes_enabled()) {
            wp_send_json_error([
                'message' => __('File writing is disabled for this verification stage.', 'lavka-product-media-upload'),
            ], 403);
        }

        $this->guard_ajax();

        ignore_user_abort(true);
        if (function_exists('set_time_limit')) {
            @set_time_limit(900);
        }
        @ini_set('max_execution_time', '900');

        $token = sanitize_text_field(wp_unslash($_POST['batch_token'] ?? ''));
        if ($token === '') {
            wp_send_json_error(['message' => __('The dry-run token is missing.', 'lavka-product-media-upload')], 400);
        }

        try {
            $service = new BatchService();
            $result = $service->upload(
                $token,
                $this->registry_upload(),
                $this->image_uploads(),
                !empty($_POST['legacy_main_confirm']),
                !empty($_POST['generate_names'])
            );
            wp_send_json_success($result);
        } catch (\Throwable $e) {
            $status = (int) $e->getCode() === 409 ? 409 : 400;
            wp_send_json_error([
                'message' => __('The approved batch could not be uploaded.', 'lavka-product-media-upload'),
                'technical' => $e->getMessage(),
            ], $status);
        }
    }

    public function download_report(): void
    {
        if (!$this->can_operate()) {
            wp_die(esc_html__('You do not have permission to download this report.', 'lavka-product-media-upload'));
        }

        $token = sanitize_text_field(wp_unslash($_GET['token'] ?? ''));
        check_admin_referer('lpmu_report_' . $token);

        $report = ReportStore::get($token);
        if (!$report) {
            wp_die(esc_html__('The report has expired or does not exist.', 'lavka-product-media-upload'));
        }

        nocache_headers();
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="product-media-upload-' . gmdate('Ymd-His') . '.csv"');
        echo ReportStore::to_csv($report); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        exit;
    }

    public function download_template(): void
    {
        if (!$this->can_operate()) {
            wp_die(esc_html__('You do not have permission to download this template.', 'lavka-product-media-upload'));
        }
        check_admin_referer('lpmu_download_template');

        if (!class_exists(\PhpOffice\PhpSpreadsheet\Spreadsheet::class)) {
            wp_die(esc_html__('PhpSpreadsheet is unavailable.', 'lavka-product-media-upload'));
        }

        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('images');
        $sheet->fromArray([
            ['sku', 'barcode', 'source_file', 'role', 'position'],
            ['P-296-010', '3167862960105', 'IMG_1001.JPG', 'main', ''],
            ['P-296-010', '3167862960105', 'IMG_1002.JPG', 'gallery', 1],
        ]);
        $sheet->getStyle('A1:E1')->getFont()->setBold(true);
        $sheet->freezePane('A2');
        foreach (range('A', 'E') as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }

        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        nocache_headers();
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="product-image-registry-template.xlsx"');
        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        $writer->save('php://output');
        exit;
    }

    public function save_validation_settings(): void
    {
        if (!$this->can_operate()) {
            wp_die(esc_html__('You do not have permission to change validation settings.', 'lavka-product-media-upload'));
        }
        check_admin_referer('lpmu_save_validation_settings');

        $input = isset($_POST['thresholds']) && is_array($_POST['thresholds'])
            ? wp_unslash($_POST['thresholds'])
            : [];

        $max_file_mib = $this->bounded_float($input['max_file_mib'] ?? 10, 1, 100);
        $warn_file_mib = min(
            $max_file_mib,
            $this->bounded_float($input['warn_file_mib'] ?? 5, 1, 100)
        );
        $max_side = $this->bounded_int($input['max_side'] ?? 12000, 100, 50000);
        $recommended_min = $this->bounded_int($input['recommended_min'] ?? 1000, 1, $max_side);
        $recommended_max = max(
            $recommended_min,
            $this->bounded_int($input['recommended_max'] ?? 2000, 1, $max_side)
        );
        $filename_warn = $this->bounded_int($input['filename_warn_length'] ?? 60, 10, 100);
        $filename_max = max(
            $filename_warn,
            $this->bounded_int($input['filename_max_length'] ?? 100, 20, 255)
        );

        update_option(ImageValidator::SETTINGS_OPTION, [
            'max_file_bytes' => (int) round($max_file_mib * MB_IN_BYTES),
            'warn_file_bytes' => (int) round($warn_file_mib * MB_IN_BYTES),
            'min_side_warn' => $this->bounded_int($input['min_side_warn'] ?? 550, 1, $max_side),
            'recommended_min' => $recommended_min,
            'recommended_max' => $recommended_max,
            'large_side_warn' => $this->bounded_int($input['large_side_warn'] ?? 4000, 1, $max_side),
            'max_side' => $max_side,
            'max_pixels' => $this->bounded_int($input['max_pixels'] ?? 40000000, 1000000, 500000000),
            'aspect_ratio_warn' => $this->bounded_float($input['aspect_ratio_warn'] ?? 4, 1.1, 20),
            'filename_warn_length' => $filename_warn,
            'filename_max_length' => $filename_max,
            'strict_decoder_warnings' => !empty($input['strict_decoder_warnings']),
        ], false);

        wp_safe_redirect(add_query_arg(
            [
                'page' => self::PAGE_SLUG,
                'settings-updated' => '1',
            ],
            admin_url('upload.php')
        ));
        exit;
    }

    private function guard_ajax(): void
    {
        check_ajax_referer(self::NONCE_ACTION, 'nonce');
        if (!$this->can_operate()) {
            wp_send_json_error(['message' => __('You do not have permission to check product images.', 'lavka-product-media-upload')], 403);
        }
        if (!$this->dependencies_ready()) {
            wp_send_json_error(['message' => __('WooCommerce or PhpSpreadsheet is unavailable.', 'lavka-product-media-upload')], 500);
        }
    }

    private function registry_upload(): array
    {
        $file = $_FILES['registry'] ?? null;
        if (!is_array($file)) {
            throw new \RuntimeException(__('The registry file is missing.', 'lavka-product-media-upload'));
        }

        return $file;
    }

    private function image_uploads(): array
    {
        $files = $_FILES['images'] ?? null;
        if (!is_array($files) || !isset($files['name']) || !is_array($files['name'])) {
            return [];
        }

        $uploads = [];
        foreach ($files['name'] as $index => $name) {
            $uploads[] = [
                'name' => (string) $name,
                'type' => (string) ($files['type'][$index] ?? ''),
                'tmp_name' => (string) ($files['tmp_name'][$index] ?? ''),
                'error' => (int) ($files['error'][$index] ?? UPLOAD_ERR_NO_FILE),
                'size' => (int) ($files['size'][$index] ?? 0),
            ];
        }

        return $uploads;
    }

    private function can_operate(): bool
    {
        return current_user_can($this->capability()) && current_user_can('upload_files');
    }

    private function capability(): string
    {
        return (string) apply_filters('lavka_product_media_upload_capability', 'manage_woocommerce');
    }

    private function dependencies_ready(): bool
    {
        return class_exists('WooCommerce') && class_exists(\PhpOffice\PhpSpreadsheet\IOFactory::class);
    }

    private function number_setting(
        string $key,
        string $label,
        $value,
        float $min,
        float $max,
        float $step = 1
    ): void {
        ?>
        <label>
            <span><?php echo esc_html($label); ?></span>
            <input
                type="number"
                name="thresholds[<?php echo esc_attr($key); ?>]"
                value="<?php echo esc_attr((string) $value); ?>"
                min="<?php echo esc_attr((string) $min); ?>"
                max="<?php echo esc_attr((string) $max); ?>"
                step="<?php echo esc_attr((string) $step); ?>"
            >
        </label>
        <?php
    }

    private function bounded_int($value, int $min, int $max): int
    {
        return max($min, min($max, (int) $value));
    }

    private function bounded_float($value, float $min, float $max): float
    {
        return max($min, min($max, (float) $value));
    }
}
