<?php
if (!defined('ABSPATH')) exit;

const LPS_ACCOUNTING_PRICES_SINGLE_PATH = '/admin/folio/accounting-prices/recalculate';
const LPS_ACCOUNTING_PRICES_FULL_PATH = '/admin/folio/accounting-prices/recalculate/full';
const LPS_ACCOUNTING_PRICES_STATUS_PATH = '/admin/folio/accounting-prices/recalculate/full/status';
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
        'pollInterval' => 3000,
        'storageKey' => 'lpsAccountingPriceJobId',
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
            'fullPreviewStarting' => __('Starting a read-only warehouse check...', 'lavka-price-sync'),
            'fullApplyStarting' => __('Starting the warehouse recalculation...', 'lavka-price-sync'),
            'confirmSingleApply' => __('Recalculate this product in Folio now?', 'lavka-price-sync'),
            'confirmFullApply' => __('Recalculate accounting prices for the entire selected warehouse? Previously committed products cannot be rolled back as one batch.', 'lavka-price-sync'),
            'previewRequired' => __('Run a successful preview for this SKU and warehouse first.', 'lavka-price-sync'),
            'fullPreviewRequired' => __('Complete a full read-only check for this warehouse before recalculation.', 'lavka-price-sync'),
            'requestAccepted' => __('The task was accepted. Waiting for progress...', 'lavka-price-sync'),
            'jobRunning' => __('The task is running.', 'lavka-price-sync'),
            'jobCompleted' => __('The task completed without warnings.', 'lavka-price-sync'),
            'jobWarnings' => __('The task completed with warnings. Some products were skipped.', 'lavka-price-sync'),
            'jobStopped' => __('The task stopped on a negative chronological stock.', 'lavka-price-sync'),
            'jobFailed' => __('The task failed.', 'lavka-price-sync'),
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
            'totalProducts' => __('Total products', 'lavka-price-sync'),
            'processedProducts' => __('Processed products', 'lavka-price-sync'),
            'eligibleProducts' => __('Eligible products', 'lavka-price-sync'),
            'recalculatedProducts' => __('Recalculated products', 'lavka-price-sync'),
            'priceChangedProducts' => __('Prices changed', 'lavka-price-sync'),
            'skippedProducts' => __('Skipped products', 'lavka-price-sync'),
            'warningCount' => __('Warnings', 'lavka-price-sync'),
            'copyDone' => __('SKU list copied.', 'lavka-price-sync'),
            'exportEmpty' => __('There are no warnings to export.', 'lavka-price-sync'),
            'httpError' => __('Java API returned HTTP', 'lavka-price-sync'),
            'requestId' => __('Request ID', 'lavka-price-sync'),
            'rawResponse' => __('Raw Java response', 'lavka-price-sync'),
            'applyAvailableAfterPreview' => __('Full recalculation becomes available after a completed read-only check of the selected warehouse.', 'lavka-price-sync'),
            'statusLabels' => [
                'IDLE' => __('Not started', 'lavka-price-sync'),
                'BUSY' => __('Busy', 'lavka-price-sync'),
                'QUEUED' => __('Queued', 'lavka-price-sync'),
                'RUNNING' => __('Running', 'lavka-price-sync'),
                'COMPLETED' => __('Completed', 'lavka-price-sync'),
                'COMPLETED_WITH_WARNINGS' => __('Completed with warnings', 'lavka-price-sync'),
                'STOPPED_ON_NEGATIVE_STOCK' => __('Stopped on negative stock', 'lavka-price-sync'),
                'FAILED' => __('Failed', 'lavka-price-sync'),
                'FAILED_PARTIAL' => __('Failed after partial recalculation', 'lavka-price-sync'),
                'PREVIEW_READY' => __('Check passed', 'lavka-price-sync'),
                'PREVIEW_BLOCKED' => __('Check blocked recalculation', 'lavka-price-sync'),
                'RECALCULATED' => __('Recalculated', 'lavka-price-sync'),
                'BLOCKED' => __('Blocked', 'lavka-price-sync'),
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
    ?>
    <div class="wrap lps-ap" id="lps-accounting-prices">
        <h1><?php echo esc_html__('Folio accounting prices', 'lavka-price-sync'); ?></h1>

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
            <h2><?php echo esc_html__('Warehouse accounting prices', 'lavka-price-sync'); ?></h2>
            <div class="lps-ap-form-row">
                <label>
                    <input type="checkbox" id="lps-ap-continue-negative" checked>
                    <?php echo esc_html__('Skip products with negative chronological stock and continue.', 'lavka-price-sync'); ?>
                </label>
                <button type="button" class="button button-primary" id="lps-ap-full-preview">
                    <?php echo esc_html__('Check entire warehouse without changes', 'lavka-price-sync'); ?>
                </button>
            </div>
            <div class="lps-ap-danger-zone">
                <label>
                    <input type="checkbox" id="lps-ap-full-confirm">
                    <?php echo esc_html__('I have a current backup and confirm the agreed Folio maintenance window.', 'lavka-price-sync'); ?>
                </label>
                <button type="button" class="button lps-ap-danger-button" id="lps-ap-full-apply" disabled>
                    <?php echo esc_html__('Recalculate entire warehouse in Folio', 'lavka-price-sync'); ?>
                </button>
                <span class="description"><?php echo esc_html__('Full recalculation becomes available after a completed read-only check of the selected warehouse.', 'lavka-price-sync'); ?></span>
            </div>
            <div id="lps-ap-full-notice" class="lps-ap-result-notice" hidden></div>
            <div id="lps-ap-progress" hidden>
                <div class="lps-ap-progress-track" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0">
                    <span></span>
                </div>
                <p id="lps-ap-progress-label" class="description"></p>
            </div>
            <div id="lps-ap-full-summary"></div>
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

            $response = lps_java_post(LPS_ACCOUNTING_PRICES_SINGLE_PATH, [
                'sku' => $sku,
                'warehouseId' => $warehouse_id,
                'previewOnly' => $preview_only,
            ]);
            lps_accounting_prices_send_response($response);
            break;

        case 'full_start':
            $warehouse_id = absint($_POST['warehouseId'] ?? 0);
            $preview_only = (string)($_POST['previewOnly'] ?? '1') === '1';
            $continue_on_negative = (string)($_POST['continueOnNegativeStock'] ?? '1') === '1';

            if ($warehouse_id < 1) {
                wp_send_json_error(['message' => __('Folio warehouse is required.', 'lavka-price-sync')], 400);
            }
            if (!$preview_only && (string)($_POST['confirmApply'] ?? '') !== '1') {
                wp_send_json_error(['message' => __('Explicit confirmation is required for Folio changes.', 'lavka-price-sync')], 400);
            }

            $response = lps_java_post(LPS_ACCOUNTING_PRICES_FULL_PATH, [
                'warehouseId' => $warehouse_id,
                'previewOnly' => $preview_only,
                'continueOnNegativeStock' => $continue_on_negative,
            ], ['timeout' => 30]);
            lps_accounting_prices_send_response($response);
            break;

        case 'full_status':
            $response = lps_java_get(LPS_ACCOUNTING_PRICES_STATUS_PATH, ['timeout' => 30]);
            lps_accounting_prices_send_response($response);
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
