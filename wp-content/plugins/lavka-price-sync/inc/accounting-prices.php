<?php
if (!defined('ABSPATH')) exit;

const LPS_ACCOUNTING_PRICES_SINGLE_PATH = '/admin/folio/accounting-prices/recalculate';
const LPS_ACCOUNTING_PRICES_WAREHOUSES_PATH = '/ref/warehouses';

add_action('admin_menu', function () {
    add_submenu_page(
        'lps-main',
        __('Folio accounting prices', 'lavka-price-sync'),
        __('Folio accounting prices', 'lavka-price-sync'),
        LPS_CAP,
        'lps-accounting-prices',
        'lps_render_accounting_prices_page'
    );
}, 20);

add_action('admin_enqueue_scripts', function () {
    $page = sanitize_key(wp_unslash($_GET['page'] ?? ''));
    if ($page !== 'lps-accounting-prices') return;

    $css_path = dirname(__DIR__) . '/assets/accounting-prices.css';
    $js_path = dirname(__DIR__) . '/assets/accounting-prices.js';
    $plugin_file = dirname(__DIR__) . '/lavka-price-sync.php';
    $native_job = lps_accounting_prices_native_job_state();

    wp_enqueue_style(
        'lps-accounting-prices',
        plugins_url('assets/accounting-prices.css', $plugin_file),
        [],
        @filemtime($css_path) ?: '1.0'
    );
    wp_enqueue_script(
        'lps-accounting-prices',
        plugins_url('assets/accounting-prices.js', $plugin_file),
        [],
        @filemtime($js_path) ?: '1.0',
        true
    );
    wp_localize_script('lps-accounting-prices', 'LPS_ACCOUNTING_PRICES', [
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('lps_accounting_prices'),
        'pollInterval' => 5000,
        'storageKey' => 'lpsNativeAccountingPriceJobId',
        'pollOnLoad' => !empty($native_job['running']),
        'i18n' => [
            'loading' => __('Loading...', 'lavka-price-sync'),
            'networkError' => __('The server request failed.', 'lavka-price-sync'),
            'unknownError' => __('Unknown error.', 'lavka-price-sync'),
            'warehouseRequired' => __('Select a Folio warehouse.', 'lavka-price-sync'),
            'selectWarehouse' => __('Select a warehouse', 'lavka-price-sync'),
            'noWarehouses' => __('The Java service returned no warehouses.', 'lavka-price-sync'),
            'warehouseLoadFailed' => __('The warehouse directory is unavailable. Enter the Folio warehouse ID manually.', 'lavka-price-sync'),
            'skuRequired' => __('Enter a product SKU.', 'lavka-price-sync'),
            'previewRunning' => __('Checking the product without changes...', 'lavka-price-sync'),
            'applyRunning' => __('Recalculating the product in Folio...', 'lavka-price-sync'),
            'fullPreviewStarting' => __('Starting the exact rollback preview of the native Folio recalculation...', 'lavka-price-sync'),
            'fullApplyStarting' => __('Starting the native Folio warehouse recalculation...', 'lavka-price-sync'),
            'confirmSingleApply' => __('Recalculate this product in Folio now?', 'lavka-price-sync'),
            'confirmFullApply' => __('Run the full native Folio recalculation for the selected warehouse? Java will first perform a rollback preflight and will commit only if it is clean.', 'lavka-price-sync'),
            'previewRequired' => __('Run a successful preview for this SKU and warehouse first.', 'lavka-price-sync'),
            'fullPreviewRequired' => __('Complete an exact rollback preview for this warehouse before recalculation.', 'lavka-price-sync'),
            'requestAccepted' => __('The task was accepted. Waiting for progress...', 'lavka-price-sync'),
            'jobRunning' => __('The task is running.', 'lavka-price-sync'),
            'jobCompleted' => __('The task completed without warnings.', 'lavka-price-sync'),
            'jobStopped' => __('The task stopped on a negative chronological stock.', 'lavka-price-sync'),
            'jobFailed' => __('The task failed.', 'lavka-price-sync'),
            'jobFailedPartial' => __('The task failed after one or more portions were committed. Do not retry it automatically; check Folio manually.', 'lavka-price-sync'),
            'jobOutcomeUnknown' => __('The task outcome cannot be proven. Do not retry it; check Folio and the Java logs manually.', 'lavka-price-sync'),
            'jobBlockedNegative' => __('The rollback preflight found negative chronological stock. Nothing was committed.', 'lavka-price-sync'),
            'idle' => __('No warehouse task has been started since the Java service restart.', 'lavka-price-sync'),
            'previewReady' => __('The preview found no blocking problems.', 'lavka-price-sync'),
            'previewBlocked' => __('The preview found problems. Recalculation is blocked.', 'lavka-price-sync'),
            'recalculated' => __('The product was recalculated in Folio.', 'lavka-price-sync'),
            'notChanged' => __('The procedure completed; the accounting price was already correct.', 'lavka-price-sync'),
            'warningsTruncated' => __('Only part of the warnings is shown. The complete negative-stock diagnostics remain in the Java log.', 'lavka-price-sync'),
            'noWarnings' => __('No warnings were returned.', 'lavka-price-sync'),
            'noState' => __('No state data was returned.', 'lavka-price-sync'),
            'before' => __('Before', 'lavka-price-sync'),
            'after' => __('After', 'lavka-price-sync'),
            'currentSku' => __('Current SKU', 'lavka-price-sync'),
            'nextSku' => __('Next SKU', 'lavka-price-sync'),
            'phase' => __('Phase', 'lavka-price-sync'),
            'warehouse' => __('Warehouse', 'lavka-price-sync'),
            'document' => __('Document', 'lavka-price-sync'),
            'date' => __('Date', 'lavka-price-sync'),
            'quantityBefore' => __('Before operation', 'lavka-price-sync'),
            'operation' => __('Operation', 'lavka-price-sync'),
            'quantityAfter' => __('After operation', 'lavka-price-sync'),
            'shortage' => __('Shortage', 'lavka-price-sync'),
            'reason' => __('Reason', 'lavka-price-sync'),
            'details' => __('Technical details', 'lavka-price-sync'),
            'receipt' => __('receipt', 'lavka-price-sync'),
            'expense' => __('expense', 'lavka-price-sync'),
            'unknownOperation' => __('operation', 'lavka-price-sync'),
            'warehouseName' => __('Warehouse', 'lavka-price-sync'),
            'physicalQuantity' => __('Physical quantity', 'lavka-price-sync'),
            'availableQuantity' => __('Available quantity', 'lavka-price-sync'),
            'accountingQuantity' => __('Accounting quantity', 'lavka-price-sync'),
            'accountingPrice' => __('Accounting price', 'lavka-price-sync'),
            'initialQuantity' => __('Initial quantity', 'lavka-price-sync'),
            'accountingAmount' => __('Accounting amount', 'lavka-price-sync'),
            'movementCount' => __('Accounted movements', 'lavka-price-sync'),
            'procedureCalls' => __('Procedure calls', 'lavka-price-sync'),
            'preflightChunks' => __('Rolled-back preflight portions', 'lavka-price-sync'),
            'committedChunks' => __('Committed portions', 'lavka-price-sync'),
            'progressUnits' => __('Progress units', 'lavka-price-sync'),
            'warningCount' => __('Warnings', 'lavka-price-sync'),
            'copyDone' => __('SKU list copied.', 'lavka-price-sync'),
            'exportEmpty' => __('There are no warnings to export.', 'lavka-price-sync'),
            'httpError' => __('Java API returned HTTP', 'lavka-price-sync'),
            'requestId' => __('Request ID', 'lavka-price-sync'),
            'rawResponse' => __('Raw Java response', 'lavka-price-sync'),
            'applyAvailableAfterPreview' => __('Full recalculation becomes available after a successful exact rollback preview of the selected warehouse.', 'lavka-price-sync'),
            'fullPreviewUnsafe' => __('The preview returned PREVIEW_READY, but its safety checks are incomplete. Applying changes remains blocked.', 'lavka-price-sync'),
            'statusLabels' => [
                'IDLE' => __('Not started', 'lavka-price-sync'),
                'BUSY' => __('Busy', 'lavka-price-sync'),
                'QUEUED' => __('Queued', 'lavka-price-sync'),
                'RUNNING' => __('Running', 'lavka-price-sync'),
                'COMPLETED' => __('Completed', 'lavka-price-sync'),
                'STOPPED_ON_NEGATIVE_STOCK' => __('Stopped on negative stock', 'lavka-price-sync'),
                'FAILED' => __('Failed', 'lavka-price-sync'),
                'FAILED_PARTIAL' => __('Failed after partial recalculation', 'lavka-price-sync'),
                'PREVIEW_READY' => __('Check passed', 'lavka-price-sync'),
                'PREVIEW_BLOCKED' => __('Check blocked recalculation', 'lavka-price-sync'),
                'BLOCKED_NEGATIVE_STOCK' => __('Blocked by negative stock', 'lavka-price-sync'),
                'OUTCOME_UNKNOWN' => __('Outcome unknown', 'lavka-price-sync'),
                'RECALCULATED' => __('Recalculated', 'lavka-price-sync'),
                'BLOCKED' => __('Blocked', 'lavka-price-sync'),
            ],
            'phaseLabels' => [
                'QUEUED' => __('Waiting in queue', 'lavka-price-sync'),
                'PRECHECK_RUNNING' => __('Rollback preflight is running', 'lavka-price-sync'),
                'PRECHECK_COMPLETED' => __('Rollback preflight completed', 'lavka-price-sync'),
                'APPLY_RUNNING' => __('Applying recalculation portions', 'lavka-price-sync'),
                'COMPLETED' => __('Recalculation completed', 'lavka-price-sync'),
                'STOPPED' => __('Recalculation stopped', 'lavka-price-sync'),
                'FAILED' => __('Recalculation failed', 'lavka-price-sync'),
                'MANUAL_REVIEW' => __('Manual review required', 'lavka-price-sync'),
            ],
            'warningLabels' => [
                'NEGATIVE_CHRONOLOGICAL_STOCK' => __('Negative chronological stock', 'lavka-price-sync'),
                'RETURN_MOVEMENT_REQUIRES_REVIEW' => __('Return movement requires review', 'lavka-price-sync'),
                'ZERO_QUANTITY_ACCOUNTED_MOVEMENT' => __('Accounted movement has zero quantity', 'lavka-price-sync'),
                'MOVEMENT_DATE_MISSING' => __('Movement date is missing', 'lavka-price-sync'),
                'NON_INTEGRAL_TECHNICAL_KEY' => __('Invalid legacy document key', 'lavka-price-sync'),
                'ACCOUNTING_METHOD_UNSUPPORTED' => __('Accounting method is not supported', 'lavka-price-sync'),
                'ACCOUNTING_GROUP_UNSUPPORTED' => __('Warehouse accounting group is not supported', 'lavka-price-sync'),
                'ACCOUNTING_GROUP_SETTINGS_MISMATCH' => __('Warehouse group settings do not match', 'lavka-price-sync'),
                'HIDDEN_PRODUCT_TYPE' => __('Folio excludes this product type', 'lavka-price-sync'),
                'TMP_MOVE_NOT_EMPTY' => __('Folio temporary movement table is not empty', 'lavka-price-sync'),
            ],
        ],
    ]);
});

function lps_render_accounting_prices_page(): void {
    if (!current_user_can(LPS_CAP)) return;

    $cron_options = lps_accounting_prices_native_cron_options();
    $native_job = lps_accounting_prices_native_job_state();
    $native_batch = lps_accounting_prices_native_batch_state();
    $next_run = wp_next_scheduled(LPS_ACCOUNTING_PRICES_NATIVE_CRON_HOOK);
    $cron_saved = sanitize_key(wp_unslash($_GET['cron_saved'] ?? ''));
    $cron_error = sanitize_key(wp_unslash($_GET['cron_error'] ?? ''));
    $weekdays = [
        'mon' => __('Monday', 'lavka-price-sync'),
        'tue' => __('Tuesday', 'lavka-price-sync'),
        'wed' => __('Wednesday', 'lavka-price-sync'),
        'thu' => __('Thursday', 'lavka-price-sync'),
        'fri' => __('Friday', 'lavka-price-sync'),
        'sat' => __('Saturday', 'lavka-price-sync'),
        'sun' => __('Sunday', 'lavka-price-sync'),
    ];
    $native_status_labels = [
        'IDLE' => __('Not started', 'lavka-price-sync'),
        'QUEUED' => __('Queued', 'lavka-price-sync'),
        'RUNNING' => __('Running', 'lavka-price-sync'),
        'PREVIEW_READY' => __('Check passed', 'lavka-price-sync'),
        'BLOCKED_NEGATIVE_STOCK' => __('Blocked by negative stock', 'lavka-price-sync'),
        'COMPLETED' => __('Completed', 'lavka-price-sync'),
        'STOPPED_ON_NEGATIVE_STOCK' => __('Stopped on negative stock', 'lavka-price-sync'),
        'FAILED' => __('Failed', 'lavka-price-sync'),
        'FAILED_PARTIAL' => __('Failed after partial recalculation', 'lavka-price-sync'),
        'OUTCOME_UNKNOWN' => __('Outcome unknown', 'lavka-price-sync'),
    ];
    $native_status = strtoupper((string)($native_job['status'] ?? ''));
    $batch_status_labels = [
        'QUEUED' => __('Queued', 'lavka-price-sync'),
        'STARTING' => __('Starting warehouse recalculation', 'lavka-price-sync'),
        'RUNNING' => __('Running', 'lavka-price-sync'),
        'WAITING_LOCK' => __('Waiting for the global Lavka lock', 'lavka-price-sync'),
        'WAITING_NEXT' => __('Waiting for the next warehouse', 'lavka-price-sync'),
        'COMPLETED' => __('Completed', 'lavka-price-sync'),
        'BLOCKED_NEGATIVE_STOCK' => __('Blocked by negative stock', 'lavka-price-sync'),
        'STOPPED_ON_NEGATIVE_STOCK' => __('Stopped on negative stock', 'lavka-price-sync'),
        'FAILED' => __('Failed', 'lavka-price-sync'),
        'START_FAILED' => __('Could not start', 'lavka-price-sync'),
        'FAILED_PARTIAL' => __('Failed after partial recalculation', 'lavka-price-sync'),
        'OUTCOME_UNKNOWN' => __('Outcome unknown', 'lavka-price-sync'),
        'DISABLED' => __('Stopped by administrator', 'lavka-price-sync'),
    ];
    $batch_status = strtoupper((string)($native_batch['status'] ?? ''));
    $batch_stage = sanitize_key((string)($native_batch['stage'] ?? 'apply'));
    $batch_stage_labels = [
        'preview' => __('Preview stage', 'lavka-price-sync'),
        'apply' => __('Apply stage', 'lavka-price-sync'),
    ];
    $batch_total = count(lps_accounting_prices_native_normalize_warehouse_ids($native_batch['warehouse_ids'] ?? []));
    $batch_results = is_array($native_batch['results'] ?? null) ? $native_batch['results'] : [];
    $batch_success_status = $batch_stage === 'preview' ? 'preview_ready' : 'completed';
    $batch_completed = count(array_filter(
        $batch_results,
        static fn($result): bool => is_array($result)
            && sanitize_key((string)($result['stage'] ?? 'apply')) === $batch_stage
            && sanitize_key((string)($result['status'] ?? '')) === $batch_success_status
    ));
    ?>
    <div class="wrap lps-ap" id="lps-accounting-prices">
        <h1><?php echo esc_html__('Folio accounting prices', 'lavka-price-sync'); ?></h1>

        <?php if ($cron_saved === '1'): ?>
            <div class="notice notice-success is-dismissible"><p><?php echo esc_html__('The automatic recalculation schedule was saved.', 'lavka-price-sync'); ?></p></div>
        <?php elseif ($cron_saved === '0'): ?>
            <div class="notice notice-error"><p>
                <?php
                echo esc_html($cron_error === 'confirmation'
                    ? __('Confirm automatic Folio changes before enabling the schedule.', 'lavka-price-sync')
                    : __('Select at least one Folio warehouse before enabling the schedule.', 'lavka-price-sync'));
                ?>
            </p></div>
        <?php endif; ?>

        <div class="notice notice-warning inline lps-ap-safety">
            <p><strong><?php echo esc_html__('Financial operation', 'lavka-price-sync'); ?></strong></p>
            <p><?php echo esc_html__('Preview is read-only. Apply changes Folio data and must be run only in an agreed maintenance window.', 'lavka-price-sync'); ?></p>
        </div>

        <div class="lps-ap-toolbar">
            <label for="lps-ap-warehouse"><strong><?php echo esc_html__('Folio warehouse', 'lavka-price-sync'); ?></strong></label>
            <select id="lps-ap-warehouse" class="regular-text" disabled>
                <option value=""><?php echo esc_html__('Loading warehouses...', 'lavka-price-sync'); ?></option>
            </select>
            <input id="lps-ap-warehouse-manual" class="small-text" type="number" min="1" step="1" hidden
                   aria-label="<?php echo esc_attr__('Folio warehouse ID', 'lavka-price-sync'); ?>"
                   placeholder="<?php echo esc_attr__('Warehouse ID', 'lavka-price-sync'); ?>">
            <span id="lps-ap-warehouse-status" class="description"></span>
        </div>

        <nav class="nav-tab-wrapper" aria-label="<?php echo esc_attr__('Accounting price views', 'lavka-price-sync'); ?>">
            <button type="button" class="nav-tab nav-tab-active" data-lps-ap-tab="single" aria-selected="true">
                <?php echo esc_html__('One product', 'lavka-price-sync'); ?>
            </button>
            <button type="button" class="nav-tab" data-lps-ap-tab="full" aria-selected="false">
                <?php echo esc_html__('Entire warehouse', 'lavka-price-sync'); ?>
            </button>
        </nav>

        <section class="lps-ap-panel" data-lps-ap-panel="single">
            <h2><?php echo esc_html__('Product accounting price', 'lavka-price-sync'); ?></h2>
            <div class="lps-ap-form-row">
                <label for="lps-ap-sku"><strong><?php echo esc_html__('SKU', 'lavka-price-sync'); ?></strong></label>
                <input id="lps-ap-sku" type="text" class="regular-text" maxlength="100" autocomplete="off">
                <button type="button" class="button button-primary" id="lps-ap-single-preview">
                    <?php echo esc_html__('Check without changes', 'lavka-price-sync'); ?>
                </button>
            </div>
            <div class="lps-ap-apply-row">
                <label>
                    <input type="checkbox" id="lps-ap-single-confirm" disabled>
                    <?php echo esc_html__('I reviewed the preview and confirm recalculation of this product.', 'lavka-price-sync'); ?>
                </label>
                <button type="button" class="button lps-ap-danger-button" id="lps-ap-single-apply" disabled>
                    <?php echo esc_html__('Recalculate product in Folio', 'lavka-price-sync'); ?>
                </button>
            </div>
            <div id="lps-ap-single-notice" class="lps-ap-result-notice" hidden></div>
            <div id="lps-ap-single-result"></div>
        </section>

        <section class="lps-ap-panel" data-lps-ap-panel="full" hidden>
            <h2><?php echo esc_html__('Full native Folio recalculation', 'lavka-price-sync'); ?></h2>
            <p class="description lps-ap-native-description">
                <?php echo esc_html__('This mode runs the complete Folio I_UCHET_TOVAR algorithm. The preview executes every portion in a transaction and always rolls it back.', 'lavka-price-sync'); ?>
            </p>
            <div class="lps-ap-form-row">
                <button type="button" class="button button-primary" id="lps-ap-full-preview">
                    <?php echo esc_html__('Run exact rollback preview', 'lavka-price-sync'); ?>
                </button>
            </div>
            <div class="lps-ap-danger-zone">
                <label>
                    <input type="checkbox" id="lps-ap-full-confirm">
                    <?php echo esc_html__('I have a current backup and confirm the agreed Folio maintenance window.', 'lavka-price-sync'); ?>
                </label>
                <button type="button" class="button lps-ap-danger-button" id="lps-ap-full-apply" disabled>
                    <?php echo esc_html__('Run full native recalculation in Folio', 'lavka-price-sync'); ?>
                </button>
                <span class="description"><?php echo esc_html__('Apply repeats the full rollback preflight and starts committing portions only when the preflight is clean.', 'lavka-price-sync'); ?></span>
            </div>
            <div id="lps-ap-full-notice" class="lps-ap-result-notice" hidden></div>
            <div id="lps-ap-progress" hidden>
                <div class="lps-ap-progress-track" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0">
                    <span></span>
                </div>
                <p id="lps-ap-progress-label" class="description"></p>
            </div>
            <div id="lps-ap-full-summary"></div>

            <section class="lps-ap-cron-settings" aria-labelledby="lps-ap-cron-heading">
                <h3 id="lps-ap-cron-heading"><?php echo esc_html__('Automatic weekly recalculation', 'lavka-price-sync'); ?></h3>
                <p><?php echo esc_html__('The schedule is disabled by default. A scheduled run performs real Folio changes after Java completes its mandatory rollback preflight.', 'lavka-price-sync'); ?></p>

                <?php if (!empty($cron_options['paused_reason'])): ?>
                    <div class="notice notice-error inline"><p>
                        <strong><?php echo esc_html__('Schedule paused:', 'lavka-price-sync'); ?></strong>
                        <?php echo esc_html((string)$cron_options['paused_reason']); ?>
                    </p></div>
                <?php endif; ?>

                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="lps-ap-cron-form">
                    <input type="hidden" name="action" value="lps_accounting_prices_save_cron">
                    <?php wp_nonce_field('lps_accounting_prices_save_cron'); ?>

                    <label class="lps-ap-cron-toggle">
                        <input type="checkbox" name="enabled" value="1" <?php checked(!empty($cron_options['enabled'])); ?>>
                        <strong><?php echo esc_html__('Enable weekly recalculation', 'lavka-price-sync'); ?></strong>
                    </label>

                    <div class="lps-ap-cron-grid">
                        <fieldset class="lps-ap-cron-warehouses" id="lps-ap-cron-warehouses"
                                  data-selected="<?php echo esc_attr(wp_json_encode($cron_options['warehouse_ids'])); ?>">
                            <legend><?php echo esc_html__('Folio warehouses', 'lavka-price-sync'); ?></legend>
                            <div class="lps-ap-cron-warehouse-options">
                                <?php foreach ($cron_options['warehouse_ids'] as $warehouse_id): ?>
                                    <label>
                                        <input type="checkbox" name="warehouse_ids[]" value="<?php echo esc_attr((string)$warehouse_id); ?>" checked>
                                        <?php
                                        /* translators: %d: numeric Folio warehouse ID. */
                                        echo esc_html(sprintf(__('Warehouse ID: %d', 'lavka-price-sync'), $warehouse_id));
                                        ?>
                                    </label>
                                <?php endforeach; ?>
                                <?php if (!$cron_options['warehouse_ids']): ?>
                                    <span class="description"><?php echo esc_html__('Loading warehouses...', 'lavka-price-sync'); ?></span>
                                <?php endif; ?>
                            </div>
                            <p class="description"><?php echo esc_html__('Selected warehouses are processed sequentially: first all previews, then all recalculations. Warehouses are never processed in parallel.', 'lavka-price-sync'); ?></p>
                        </fieldset>
                        <label>
                            <span><?php echo esc_html__('Day of week', 'lavka-price-sync'); ?></span>
                            <select name="weekday">
                                <?php foreach ($weekdays as $value => $label): ?>
                                    <option value="<?php echo esc_attr($value); ?>" <?php selected($cron_options['weekday'], $value); ?>><?php echo esc_html($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label>
                            <span><?php echo esc_html__('Start time', 'lavka-price-sync'); ?></span>
                            <input type="time" name="time" value="<?php echo esc_attr((string)$cron_options['time']); ?>" required>
                        </label>
                    </div>

                    <label class="lps-ap-cron-confirm">
                        <input type="checkbox" name="automatic_apply_confirmed" value="1" <?php checked(!empty($cron_options['automatic_apply_confirmed'])); ?>>
                        <?php echo esc_html__('I understand that the schedule first checks every selected warehouse and then automatically changes accounting prices in Folio only if all previews are clean.', 'lavka-price-sync'); ?>
                    </label>

                    <p class="description">
                        <?php
                        printf(
                            /* translators: %s: WordPress site time zone. */
                            esc_html__('Time zone: %s. If another Lavka synchronization holds the global lock, the run is postponed without sending a request to Java.', 'lavka-price-sync'),
                            esc_html(wp_timezone_string())
                        );
                        ?>
                    </p>
                    <p>
                        <button type="submit" class="button button-primary"><?php echo esc_html__('Save schedule', 'lavka-price-sync'); ?></button>
                    </p>
                </form>

                <dl class="lps-ap-cron-status">
                    <div>
                        <dt><?php echo esc_html__('Next scheduled run', 'lavka-price-sync'); ?></dt>
                        <dd><?php echo esc_html($next_run
                            ? wp_date(get_option('date_format') . ' ' . get_option('time_format'), $next_run, wp_timezone())
                            : __('Not scheduled', 'lavka-price-sync')); ?></dd>
                    </div>
                    <div>
                        <dt><?php echo esc_html__('Last native job status', 'lavka-price-sync'); ?></dt>
                        <dd><?php echo esc_html($native_status !== ''
                            ? ($native_status_labels[$native_status] ?? $native_status)
                            : __('No runs yet', 'lavka-price-sync')); ?></dd>
                    </div>
                    <div>
                        <dt><?php echo esc_html__('Last scheduled warehouse queue', 'lavka-price-sync'); ?></dt>
                        <dd>
                            <?php
                            if ($batch_status === '') {
                                echo esc_html__('No scheduled runs yet', 'lavka-price-sync');
                            } else {
                                echo esc_html($batch_status_labels[$batch_status] ?? $batch_status);
                                if (isset($batch_stage_labels[$batch_stage])) {
                                    echo ' · ' . esc_html($batch_stage_labels[$batch_stage]);
                                }
                                if ($batch_total > 0) {
                                    echo '<br>';
                                    if ($batch_stage === 'preview') {
                                        printf(
                                            /* translators: 1: completed warehouse preview count, 2: total warehouse count. */
                                            esc_html__('%1$d of %2$d warehouse previews completed', 'lavka-price-sync'),
                                            $batch_completed,
                                            $batch_total
                                        );
                                    } else {
                                        printf(
                                            /* translators: 1: completed warehouse count, 2: total warehouse count. */
                                            esc_html__('%1$d of %2$d warehouses completed', 'lavka-price-sync'),
                                            $batch_completed,
                                            $batch_total
                                        );
                                    }
                                }
                            }
                            ?>
                        </dd>
                    </div>
                </dl>
            </section>
        </section>

        <section class="lps-ap-warnings" id="lps-ap-warnings" hidden>
            <div class="lps-ap-section-heading">
                <h2><?php echo esc_html__('Diagnostics and skipped products', 'lavka-price-sync'); ?></h2>
                <div class="lps-ap-actions">
                    <button type="button" class="button" id="lps-ap-copy-skus">
                        <?php echo esc_html__('Copy SKU list', 'lavka-price-sync'); ?>
                    </button>
                    <button type="button" class="button" id="lps-ap-export-csv">
                        <?php echo esc_html__('Export CSV', 'lavka-price-sync'); ?>
                    </button>
                    <button type="button" class="button" id="lps-ap-export-json">
                        <?php echo esc_html__('Export JSON', 'lavka-price-sync'); ?>
                    </button>
                </div>
            </div>
            <div id="lps-ap-truncated" class="notice notice-warning inline" hidden><p></p></div>
            <div id="lps-ap-warnings-table"></div>
        </section>
    </div>
    <?php
}

add_action('wp_ajax_lps_accounting_prices', 'lps_accounting_prices_ajax');

function lps_accounting_prices_ajax(): void {
    if (!current_user_can(LPS_CAP)) {
        wp_send_json_error(['message' => __('You do not have permission to perform this operation.', 'lavka-price-sync')], 403);
    }
    check_ajax_referer('lps_accounting_prices');

    $operation = sanitize_key(wp_unslash($_POST['operation'] ?? ''));
    $options = lps_get_options();
    if (empty($options['java_base_url'])) {
        wp_send_json_error(['message' => __('Java Base URL is not configured.', 'lavka-price-sync')], 400);
    }

    switch ($operation) {
        case 'warehouses':
            $response = lps_java_get(LPS_ACCOUNTING_PRICES_WAREHOUSES_PATH, ['timeout' => 30]);
            lps_accounting_prices_send_response($response, true);
            break;

        case 'single':
            $sku = trim(sanitize_text_field(wp_unslash($_POST['sku'] ?? '')));
            $warehouse_id = absint($_POST['warehouseId'] ?? 0);
            $preview_only = (string)($_POST['previewOnly'] ?? '1') === '1';

            if ($sku === '' || $warehouse_id < 1) {
                wp_send_json_error(['message' => __('SKU and Folio warehouse are required.', 'lavka-price-sync')], 400);
            }
            if (!$preview_only && (string)($_POST['confirmApply'] ?? '') !== '1') {
                wp_send_json_error(['message' => __('Explicit confirmation is required for Folio changes.', 'lavka-price-sync')], 400);
            }

            $single_lock_token = '';
            if (!$preview_only) {
                if (!function_exists('lavka_ecosystem_lock_acquire')) {
                    wp_send_json_error(['message' => __('The global Lavka lock is unavailable. The recalculation was not started.', 'lavka-price-sync')], 503);
                }
                $single_lock = lavka_ecosystem_lock_acquire(
                    'lavka-price-sync',
                    'accounting_price_single',
                    'manual',
                    __('Single-product Folio accounting-price recalculation', 'lavka-price-sync'),
                    10 * MINUTE_IN_SECONDS,
                    ['warehouse_id' => $warehouse_id, 'sku' => $sku]
                );
                if (empty($single_lock['ok'])) {
                    wp_send_json_error([
                        'message' => $single_lock['message'] ?? __('Another Lavka synchronization is running.', 'lavka-price-sync'),
                        'lock' => $single_lock['lock'] ?? null,
                    ], 409);
                }
                $single_lock_token = (string)($single_lock['token'] ?? '');
            }

            $response = lps_java_post(LPS_ACCOUNTING_PRICES_SINGLE_PATH, [
                'sku' => $sku,
                'warehouseId' => $warehouse_id,
                'previewOnly' => $preview_only,
            ]);
            if ($single_lock_token !== '' && function_exists('lavka_ecosystem_lock_release')) {
                lavka_ecosystem_lock_release($single_lock_token);
            }
            lps_accounting_prices_send_response($response);
            break;

        case 'full_start':
            $warehouse_id = absint($_POST['warehouseId'] ?? 0);
            $preview_only = (string)($_POST['previewOnly'] ?? '1') === '1';

            if ($warehouse_id < 1) {
                wp_send_json_error(['message' => __('Folio warehouse is required.', 'lavka-price-sync')], 400);
            }
            if (!$preview_only && (string)($_POST['confirmApply'] ?? '') !== '1') {
                wp_send_json_error(['message' => __('Explicit confirmation is required for Folio changes.', 'lavka-price-sync')], 400);
            }

            wp_send_json_success(lps_accounting_prices_native_start($warehouse_id, $preview_only, 'manual'));
            break;

        case 'full_status':
            wp_send_json_success(lps_accounting_prices_native_poll(true));
            break;

        default:
            wp_send_json_error(['message' => __('Unsupported accounting-price operation.', 'lavka-price-sync')], 400);
    }
}

function lps_accounting_prices_send_response($response, bool $normalize_warehouses = false): void {
    if (is_wp_error($response)) {
        wp_send_json_error([
            'message' => $response->get_error_message(),
            'httpStatus' => 0,
        ], 502);
    }

    $http_status = (int)wp_remote_retrieve_response_code($response);
    $raw = (string)wp_remote_retrieve_body($response);
    $body = json_decode($raw, true);
    if (!is_array($body)) {
        $body = ['message' => $raw !== '' ? $raw : __('Java API returned an empty response.', 'lavka-price-sync')];
    }

    if ($normalize_warehouses && $http_status >= 200 && $http_status < 300) {
        $source = isset($body['items']) && is_array($body['items']) ? $body['items'] : $body;
        $items = [];
        foreach ((array)$source as $row) {
            if (!is_array($row)) continue;
            $id = $row['id'] ?? ($row['code'] ?? ($row['warehouseId'] ?? ''));
            if (!is_numeric($id) || (int)$id < 1) continue;
            $name = $row['name'] ?? ($row['title'] ?? ($row['warehouseName'] ?? (string)$id));
            $items[] = [
                'id' => (int)$id,
                'name' => sanitize_text_field((string)$name),
            ];
        }
        usort($items, static fn(array $a, array $b): int => $a['id'] <=> $b['id']);
        $body = ['items' => $items];
    }

    wp_send_json_success([
        'httpStatus' => $http_status,
        'body' => $body,
    ]);
}
