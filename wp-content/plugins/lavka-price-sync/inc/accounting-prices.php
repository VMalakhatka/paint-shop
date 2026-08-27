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
    $campaign_js_path = dirname(__DIR__) . '/assets/accounting-price-campaign.js';
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
    wp_enqueue_script(
        'lps-accounting-price-campaign',
        plugins_url('assets/accounting-price-campaign.js', $plugin_file),
        ['lps-accounting-prices'],
        @filemtime($campaign_js_path) ?: '1.0',
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
            'jobCompletedWithWarnings' => __('Safe products were recalculated. Problem products were skipped; review the warnings.', 'lavka-price-sync'),
            'jobStopped' => __('The task stopped on a negative chronological stock.', 'lavka-price-sync'),
            'jobFailed' => __('The task failed.', 'lavka-price-sync'),
            'failedChunk' => __('Rejected recalculation portion', 'lavka-price-sync'),
            'failedChunkDescription' => __('These values belong to the portion rejected by Folio validation. The progress values above may describe a previously accepted portion.', 'lavka-price-sync'),
            'failedChunkRolledBack' => __('No portions were committed. Changes from the failed operation were rolled back.', 'lavka-price-sync'),
            'failedChunkNoRetry' => __('Do not retry automatically. Correct the cause and start a new preview manually.', 'lavka-price-sync'),
            'inputArt' => __('Input article', 'lavka-price-sync'),
            'outputArt' => __('Output article', 'lavka-price-sync'),
            'failedNextArt' => __('Next article returned for the rejected portion', 'lavka-price-sync'),
            'returnCode' => __('Return code', 'lavka-price-sync'),
            'currentUnits' => __('Current units', 'lavka-price-sync'),
            'totalUnits' => __('Total units', 'lavka-price-sync'),
            'problemDate' => __('Problem date', 'lavka-price-sync'),
            'validationError' => __('Validation error', 'lavka-price-sync'),
            'jobFailedPartial' => __('The task failed after one or more portions were committed. Do not retry it automatically; check Folio manually.', 'lavka-price-sync'),
            'jobOutcomeUnknown' => __('The task outcome cannot be proven. Do not retry it; check Folio and the Java logs manually.', 'lavka-price-sync'),
            'jobBlockedNegative' => __('The rollback preflight found negative chronological stock. Nothing was committed.', 'lavka-price-sync'),
            'idle' => __('No warehouse task has been started since the Java service restart.', 'lavka-price-sync'),
            'previewReady' => __('The preview found no blocking problems.', 'lavka-price-sync'),
            'previewReadyWithWarnings' => __('The preview completed with skipped problem products. Applying changes to the remaining products is allowed.', 'lavka-price-sync'),
            'previewBlocked' => __('The preview found problems. Recalculation is blocked.', 'lavka-price-sync'),
            'recalculated' => __('The product was recalculated in Folio.', 'lavka-price-sync'),
            'notChanged' => __('The procedure completed; the accounting price was already correct.', 'lavka-price-sync'),
            'warningsTruncated' => __('Only part of the warnings is shown. The complete diagnostics remain in the Java log.', 'lavka-price-sync'),
            'noWarnings' => __('No warnings were returned.', 'lavka-price-sync'),
            'noState' => __('No state data was returned.', 'lavka-price-sync'),
            'before' => __('Before', 'lavka-price-sync'),
            'after' => __('After', 'lavka-price-sync'),
            'currentSku' => __('Current SKU', 'lavka-price-sync'),
            'nextSku' => __('Next SKU', 'lavka-price-sync'),
            'progressCurrentSku' => __('Last article reported for overall progress', 'lavka-price-sync'),
            'progressNextSku' => __('Next overall progress cursor', 'lavka-price-sync'),
            'failedSafety' => __('Failed operation safety status', 'lavka-price-sync'),
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
            'skippedProduct' => __('Product skipped', 'lavka-price-sync'),
            'receipt' => __('receipt', 'lavka-price-sync'),
            'expense' => __('expense', 'lavka-price-sync'),
            'unknownOperation' => __('operation', 'lavka-price-sync'),
            'warehouseName' => __('Warehouse', 'lavka-price-sync'),
            'physicalQuantity' => __('Physical quantity', 'lavka-price-sync'),
            'availableQuantity' => __('Available quantity', 'lavka-price-sync'),
            'accountingQuantity' => __('Accounting quantity', 'lavka-price-sync'),
            'accountingPrice' => __('Accounting price', 'lavka-price-sync'),
            'accountingCurrencyPrice' => __('Accounting price in currency', 'lavka-price-sync'),
            'salePrice' => __('Sale price', 'lavka-price-sync'),
            'saleCurrencyPrice' => __('Sale price in currency', 'lavka-price-sync'),
            'priceBasis' => __('Price basis', 'lavka-price-sync'),
            'reservedQuantity' => __('Reserved quantity', 'lavka-price-sync'),
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
            'fullPreviewUnsafe' => __('The preview returned a successful status, but its safety checks are incomplete. Applying changes remains blocked.', 'lavka-price-sync'),
            'statusLabels' => [
                'IDLE' => __('Not started', 'lavka-price-sync'),
                'BUSY' => __('Busy', 'lavka-price-sync'),
                'QUEUED' => __('Queued', 'lavka-price-sync'),
                'RUNNING' => __('Running', 'lavka-price-sync'),
                'COMPLETED' => __('Completed', 'lavka-price-sync'),
                'COMPLETED_WITH_WARNINGS' => __('Completed with skipped products', 'lavka-price-sync'),
                'STOPPED_ON_NEGATIVE_STOCK' => __('Stopped on negative stock', 'lavka-price-sync'),
                'FAILED' => __('Failed', 'lavka-price-sync'),
                'FAILED_PARTIAL' => __('Failed after partial recalculation', 'lavka-price-sync'),
                'PREVIEW_READY' => __('Check passed', 'lavka-price-sync'),
                'PREVIEW_READY_WITH_WARNINGS' => __('Check passed with skipped products', 'lavka-price-sync'),
                'PREVIEW_BLOCKED' => __('Check blocked recalculation', 'lavka-price-sync'),
                'BLOCKED_NEGATIVE_STOCK' => __('Blocked by negative stock', 'lavka-price-sync'),
                'OUTCOME_UNKNOWN' => __('Outcome unknown', 'lavka-price-sync'),
                'RECALCULATED' => __('Recalculated', 'lavka-price-sync'),
                'BLOCKED' => __('Blocked', 'lavka-price-sync'),
            ],
            'phaseLabels' => [
                'QUEUED' => __('Waiting in queue', 'lavka-price-sync'),
                'DIAGNOSTIC_SCAN' => __('Scanning products for safe recalculation', 'lavka-price-sync'),
                'PRECHECK_RUNNING' => __('Rollback preflight is running', 'lavka-price-sync'),
                'PRECHECK_COMPLETED' => __('Rollback preflight completed', 'lavka-price-sync'),
                'QUARANTINE_PREPARATION' => __('Preparing safe exclusion of problem products', 'lavka-price-sync'),
                'APPLY_RUNNING' => __('Applying recalculation portions', 'lavka-price-sync'),
                'APPLY_COMPLETED' => __('Recalculation application completed', 'lavka-price-sync'),
                'APPLY_STOPPED' => __('Recalculation application stopped', 'lavka-price-sync'),
                'COMPLETED' => __('Recalculation completed', 'lavka-price-sync'),
                'STOPPED' => __('Recalculation stopped', 'lavka-price-sync'),
                'FAILED' => __('Recalculation failed', 'lavka-price-sync'),
                'MANUAL_REVIEW' => __('Manual review required', 'lavka-price-sync'),
            ],
            'warningLabels' => [
                'NEGATIVE_CHRONOLOGICAL_STOCK' => __('Negative chronological stock', 'lavka-price-sync'),
                'ZERO_ACCOUNTING_QUANTITY_DENOMINATOR' => __('Zero accounting quantity denominator', 'lavka-price-sync'),
                'ZERO_ACCOUNTING_PRICE_WITH_SALE_PRICE' => __('Zero accounting price with a sale price', 'lavka-price-sync'),
                'AMBIGUOUS_MOVEMENT_ORDER' => __('Ambiguous movement order', 'lavka-price-sync'),
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
    wp_localize_script('lps-accounting-price-campaign', 'LPS_ACCOUNTING_PRICE_CAMPAIGN', [
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('lps_accounting_prices'),
        'snapshotReportExportUrl' => wp_nonce_url(
            admin_url('admin-post.php?action=lps_accounting_price_snapshot_report_export'),
            'lps_accounting_price_snapshot_report_export'
        ),
        'pollInterval' => 5000,
        'i18n' => [
            'startConfirm' => __('Start the accounting-price SKU campaign for the selected warehouse?', 'lavka-price-sync'),
            'stopConfirm' => __('Stop accepting new batches after the current operation and build the final snapshot?', 'lavka-price-sync'),
            'warehouseRequired' => __('Select a Folio warehouse.', 'lavka-price-sync'),
            'confirmationRequired' => __('Confirm the maintenance window before starting the campaign.', 'lavka-price-sync'),
            'requestFailed' => __('The server request failed.', 'lavka-price-sync'),
            'loading' => __('Loading...', 'lavka-price-sync'),
            'idle' => __('No SKU campaign has been started yet.', 'lavka-price-sync'),
            'noReports' => __('The campaign has not completed any batches yet.', 'lavka-price-sync'),
            'noWarnings' => __('No skipped products or warnings were recorded.', 'lavka-price-sync'),
            'warningsTruncated' => __('Only part of the warnings is shown. The complete diagnostics remain in the Java log.', 'lavka-price-sync'),
            'stopRequested' => __('Safe stop requested. The current operation will finish and the final snapshot will be built.', 'lavka-price-sync'),
            'status' => __('Status', 'lavka-price-sync'),
            'phase' => __('Phase', 'lavka-price-sync'),
            'warehouse' => __('Warehouse', 'lavka-price-sync'),
            'processed' => __('Processed SKU', 'lavka-price-sync'),
            'batches' => __('Successful batches', 'lavka-price-sync'),
            'warnings' => __('Warnings', 'lavka-price-sync'),
            'errors' => __('Errors', 'lavka-price-sync'),
            'failedWarehouses' => __('Failed warehouses', 'lavka-price-sync'),
            'currentBatch' => __('Current SKU batch', 'lavka-price-sync'),
            'statesBefore' => __('Snapshot states before processing', 'lavka-price-sync'),
            'statesAfter' => __('Snapshot states after processing', 'lavka-price-sync'),
            'stateReport' => __('Snapshot state report', 'lavka-price-sync'),
            'stateReportDescription' => __('Open NEW, DIRTY, FAILED, or REMOVED to review the actual products behind the counters.', 'lavka-price-sync'),
            'viewState' => __('View', 'lavka-price-sync'),
            'exportState' => __('Export selected state CSV', 'lavka-price-sync'),
            'noStateItems' => __('There are no products in this snapshot state.', 'lavka-price-sync'),
            'product' => __('Product', 'lavka-price-sync'),
            'state' => __('State', 'lavka-price-sync'),
            'stateReason' => __('Why this state is assigned', 'lavka-price-sync'),
            'lastError' => __('Last error', 'lavka-price-sync'),
            'movements' => __('Movements', 'lavka-price-sync'),
            'movementPeriod' => __('Movement period', 'lavka-price-sync'),
            'lastObserved' => __('Last observed', 'lavka-price-sync'),
            'lastRecalculated' => __('Last recalculated', 'lavka-price-sync'),
            'latestChange' => __('Latest change', 'lavka-price-sync'),
            'previousPage' => __('Previous page', 'lavka-price-sync'),
            'nextPage' => __('Next page', 'lavka-price-sync'),
            'pageOf' => __('Page %1$d of %2$d', 'lavka-price-sync'),
            'stateReasons' => [
                'NEW' => __('The product was first found in Folio and has not yet received a confirmed recalculation.', 'lavka-price-sync'),
                'DIRTY' => __('The Folio movement fingerprint changed after the last confirmed recalculation.', 'lavka-price-sync'),
                'FAILED' => __('The product recalculation failed. Review the saved error and correct the Folio data before retrying.', 'lavka-price-sync'),
                'REMOVED' => __('The product existed in an earlier snapshot but is absent from the current Folio warehouse snapshot.', 'lavka-price-sync'),
            ],
            'reports' => __('Batch and warehouse report', 'lavka-price-sync'),
            'warningReport' => __('Skipped products and diagnostics', 'lavka-price-sync'),
            'negativeStockExplanation' => __('The chronological stock became negative at this movement. Check the document date, quantity, warehouse, and movement order in Folio.', 'lavka-price-sync'),
            'document' => __('Document', 'lavka-price-sync'),
            'problemDate' => __('Problem date', 'lavka-price-sync'),
            'initialQuantity' => __('Initial quantity', 'lavka-price-sync'),
            'movementRecord' => __('Movement record', 'lavka-price-sync'),
            'beforeOperation' => __('Before operation', 'lavka-price-sync'),
            'operationQuantity' => __('Operation quantity', 'lavka-price-sync'),
            'afterOperation' => __('After operation', 'lavka-price-sync'),
            'shortage' => __('Shortage', 'lavka-price-sync'),
            'movementPosition' => __('Movement position', 'lavka-price-sync'),
            'currentPhysicalQuantity' => __('Current physical quantity', 'lavka-price-sync'),
            'currentAccountingQuantity' => __('Current accounting quantity', 'lavka-price-sync'),
            'receipt' => __('receipt', 'lavka-price-sync'),
            'expense' => __('expense', 'lavka-price-sync'),
            'unknownOperation' => __('operation', 'lavka-price-sync'),
            'when' => __('When', 'lavka-price-sync'),
            'result' => __('Result', 'lavka-price-sync'),
            'skuCount' => __('SKU count', 'lavka-price-sync'),
            'duration' => __('Duration', 'lavka-price-sync'),
            'reason' => __('Reason', 'lavka-price-sync'),
            'message' => __('Message', 'lavka-price-sync'),
            'sku' => __('SKU', 'lavka-price-sync'),
            'details' => __('Technical details', 'lavka-price-sync'),
            'seconds' => __('sec.', 'lavka-price-sync'),
            'statusLabels' => [
                'IDLE' => __('Not started', 'lavka-price-sync'),
                'RUNNING' => __('Running', 'lavka-price-sync'),
                'COMPLETED' => __('Completed', 'lavka-price-sync'),
                'COMPLETED_WITH_WARNINGS' => __('Completed with skipped products', 'lavka-price-sync'),
                'PAUSED' => __('Stopped safely', 'lavka-price-sync'),
                'MANUAL_REVIEW' => __('Manual review required', 'lavka-price-sync'),
                'FAILED_PARTIAL' => __('Failed after partial recalculation', 'lavka-price-sync'),
                'OUTCOME_UNKNOWN' => __('Outcome unknown', 'lavka-price-sync'),
            ],
            'phaseLabels' => [
                'SNAPSHOT_BEFORE_START' => __('Starting the initial product snapshot', 'lavka-price-sync'),
                'SNAPSHOT_BEFORE_POLL' => __('Building the initial product snapshot', 'lavka-price-sync'),
                'SELECT_BATCH' => __('Selecting products from snapshot states', 'lavka-price-sync'),
                'RANGE_STARTING' => __('Starting the selected SKU batch', 'lavka-price-sync'),
                'RANGE_POLL' => __('Recalculating the selected SKU batch', 'lavka-price-sync'),
                'QUARANTINE_PREPARATION' => __('Preparing safe skips for problem products', 'lavka-price-sync'),
                'SNAPSHOT_AFTER_START' => __('Starting the mandatory final snapshot', 'lavka-price-sync'),
                'SNAPSHOT_AFTER_POLL' => __('Building the mandatory final snapshot', 'lavka-price-sync'),
                'WAITING_LOCK' => __('Waiting for the global Lavka lock', 'lavka-price-sync'),
                'WAITING_JAVA_SLOT' => __('Waiting for the Folio accounting-price slot', 'lavka-price-sync'),
                'WAITING_SNAPSHOT' => __('Waiting to restart the snapshot request', 'lavka-price-sync'),
                'MANUAL_REVIEW' => __('Manual review required', 'lavka-price-sync'),
                'COMPLETED' => __('Campaign completed', 'lavka-price-sync'),
            ],
        ],
    ]);
});

function lps_accounting_prices_batch_report_rows(array $batch): array {
    $rows = [];
    foreach ((array)($batch['results'] ?? []) as $result) {
        if (!is_array($result)) continue;

        $base = [
            'completed_at' => sanitize_text_field((string)($result['completed_at'] ?? '')),
            'stage' => sanitize_key((string)($result['stage'] ?? '')),
            'warehouse_id' => absint($result['warehouse_id'] ?? 0),
            'job_id' => sanitize_text_field((string)($result['job_id'] ?? '')),
            'status' => strtoupper(sanitize_key((string)($result['status'] ?? ''))),
            'phase' => strtoupper(sanitize_key((string)($result['phase'] ?? ''))),
            'outcome' => sanitize_key((string)($result['outcome'] ?? '')),
        ];
        $issues = [];
        foreach ((array)($result['warnings'] ?? []) as $issue) {
            if (is_array($issue)) $issues[] = ['severity' => 'warning', 'issue' => $issue];
        }
        foreach ((array)($result['errors'] ?? []) as $issue) {
            if (is_array($issue)) $issues[] = ['severity' => 'error', 'issue' => $issue];
        }
        $failed_chunk = isset($result['failed_chunk']) && is_array($result['failed_chunk'])
            ? $result['failed_chunk']
            : [];
        if ($base['status'] === 'FAILED' && $failed_chunk) {
            $issues[] = [
                'severity' => 'error',
                'issue' => [
                    'code' => 'FAILED_CHUNK',
                    'message' => __('Rejected recalculation portion', 'lavka-price-sync'),
                    'details' => $failed_chunk,
                ],
            ];
        }
        if (!empty($result['warnings_truncated'])) {
            $issues[] = [
                'severity' => 'warning',
                'issue' => [
                    'code' => 'WARNINGS_TRUNCATED',
                    'message' => __('Only part of the warnings was returned by the service. The complete diagnostics remain in the Folio service log.', 'lavka-price-sync'),
                    'details' => ['warningCount' => absint($result['warning_count'] ?? 0)],
                ],
            ];
        }

        if (!$issues) {
            $fallback_message = (string)($result['error'] ?? '');
            if ($fallback_message === '' && absint($result['warning_count'] ?? 0) > 0) {
                /* translators: %d: number of warnings reported without item details. */
                $fallback_message = sprintf(__('%d warnings were reported without item details.', 'lavka-price-sync'), absint($result['warning_count']));
            }
            $issues[] = [
                'severity' => $base['outcome'] === 'error' || $base['outcome'] === 'fatal' ? 'error' : $base['outcome'],
                'issue' => [
                    'code' => $base['status'],
                    'message' => $fallback_message,
                    'details' => [],
                ],
            ];
        }

        foreach ($issues as $entry) {
            $issue = $entry['issue'];
            $details = isset($issue['details']) && is_array($issue['details']) ? $issue['details'] : [];
            $rows[] = array_merge($base, [
                'severity' => sanitize_key((string)$entry['severity']),
                'code' => strtoupper(sanitize_key((string)($issue['code'] ?? $base['status']))),
                'sku' => sanitize_text_field((string)($details['sku'] ?? ($details['art'] ?? ($details['inputArt'] ?? ($issue['sku'] ?? ''))))),
                'message' => sanitize_textarea_field((string)($issue['message'] ?? ($result['error'] ?? ''))),
                'skipped' => !empty($details['skipped']),
                'details' => $details,
            ]);
        }
    }
    return $rows;
}

function lps_accounting_prices_batch_details_text(array $details): string {
    $parts = [];
    $operation = isset($details['operation']) && is_array($details['operation']) ? $details['operation'] : [];
    $current = isset($details['currentState']) && is_array($details['currentState']) ? $details['currentState'] : [];
    $fields = [
        __('Warehouse', 'lavka-price-sync') => $details['warehouseId'] ?? ($operation['warehouseId'] ?? null),
        __('Document', 'lavka-price-sync') => $operation['documentNumber'] ?? ($operation['documentId'] ?? ($details['documentNumber'] ?? null)),
        __('Date', 'lavka-price-sync') => $operation['documentDate'] ?? ($details['documentDate'] ?? null),
        __('Before operation', 'lavka-price-sync') => $details['quantityBefore'] ?? null,
        __('Operation', 'lavka-price-sync') => $operation['quantity'] ?? ($operation['operationQuantity'] ?? ($details['operationQuantity'] ?? null)),
        __('After operation', 'lavka-price-sync') => $details['quantityAfter'] ?? null,
        __('Shortage', 'lavka-price-sync') => $details['shortageQuantity'] ?? ($details['shortage'] ?? null),
        __('Accounting price', 'lavka-price-sync') => $details['accountingPrice'] ?? ($current['accountingPrice'] ?? null),
        __('Sale price', 'lavka-price-sync') => $details['salePrice'] ?? ($current['salePrice'] ?? null),
        __('Accounting quantity', 'lavka-price-sync') => $details['accountingQuantity'] ?? ($current['accountingQuantity'] ?? null),
        __('Physical quantity', 'lavka-price-sync') => $details['physicalQuantity'] ?? ($current['physicalQuantity'] ?? null),
        __('Input article', 'lavka-price-sync') => $details['inputArt'] ?? null,
        __('Output article', 'lavka-price-sync') => $details['outputArt'] ?? null,
        __('Next article returned for the rejected portion', 'lavka-price-sync') => $details['nextArt'] ?? null,
        __('Return code', 'lavka-price-sync') => $details['returnCode'] ?? null,
        __('Current units', 'lavka-price-sync') => $details['currentUnits'] ?? null,
        __('Total units', 'lavka-price-sync') => $details['totalUnits'] ?? null,
        __('Problem date', 'lavka-price-sync') => $details['problemDate'] ?? null,
        __('Validation error', 'lavka-price-sync') => $details['validationError'] ?? null,
    ];
    foreach ($fields as $label => $value) {
        if ($value === '' || $value === null) continue;
        $parts[] = $label . ': ' . (is_scalar($value) ? (string)$value : wp_json_encode($value));
    }
    if (!empty($details['skipped'])) $parts[] = __('Product skipped', 'lavka-price-sync');
    return implode('; ', $parts);
}

function lps_render_accounting_prices_page(): void {
    if (!current_user_can(LPS_CAP)) return;

    $cron_options = lps_accounting_prices_native_cron_options();
    $native_job = lps_accounting_prices_native_job_state();
    $native_batch = lps_accounting_prices_native_batch_state();
    $campaign_state = function_exists('lps_accounting_price_campaign_state')
        ? lps_accounting_price_campaign_state()
        : [];
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
    $saved_schedule_status = !empty($cron_options['paused_reason'])
        ? __('Paused after an error', 'lavka-price-sync')
        : (!empty($cron_options['enabled'])
            ? __('Enabled', 'lavka-price-sync')
            : __('Disabled', 'lavka-price-sync'));
    $saved_warehouse_ids = lps_accounting_prices_native_normalize_warehouse_ids($cron_options['warehouse_ids'] ?? []);
    $saved_warehouse_text = $saved_warehouse_ids
        ? implode(', ', array_map('strval', $saved_warehouse_ids))
        : __('No warehouses selected', 'lavka-price-sync');
    $saved_weekday = $weekdays[sanitize_key((string)($cron_options['weekday'] ?? 'sun'))] ?? __('Sunday', 'lavka-price-sync');
    $native_status_labels = [
        'IDLE' => __('Not started', 'lavka-price-sync'),
        'QUEUED' => __('Queued', 'lavka-price-sync'),
        'RUNNING' => __('Running', 'lavka-price-sync'),
        'PREVIEW_READY' => __('Check passed', 'lavka-price-sync'),
        'PREVIEW_READY_WITH_WARNINGS' => __('Check passed with skipped products', 'lavka-price-sync'),
        'BLOCKED_NEGATIVE_STOCK' => __('Blocked by negative stock', 'lavka-price-sync'),
        'COMPLETED' => __('Completed', 'lavka-price-sync'),
        'COMPLETED_WITH_WARNINGS' => __('Completed with skipped products', 'lavka-price-sync'),
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
        'COMPLETED_WITH_WARNINGS' => __('Completed with skipped products', 'lavka-price-sync'),
        'COMPLETED_WITH_ERRORS' => __('Completed with warehouse errors', 'lavka-price-sync'),
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
    $batch_report_rows = lps_accounting_prices_batch_report_rows($native_batch);
    $batch_success_statuses = $batch_stage === 'preview'
        ? ['preview_ready', 'preview_ready_with_warnings']
        : ['completed', 'completed_with_warnings'];
    $batch_completed = count(array_filter(
        $batch_results,
        static fn($result): bool => is_array($result)
            && sanitize_key((string)($result['stage'] ?? 'apply')) === $batch_stage
            && in_array(sanitize_key((string)($result['status'] ?? '')), $batch_success_statuses, true)
    ));
    $batch_error_count = count(array_filter(
        $batch_results,
        static fn($result): bool => is_array($result)
            && in_array(sanitize_key((string)($result['outcome'] ?? '')), ['error', 'fatal'], true)
    ));
    $batch_warning_count = array_sum(array_map(
        static fn($result): int => is_array($result) ? absint($result['warning_count'] ?? 0) : 0,
        $batch_results
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
            <button type="button" class="nav-tab" data-lps-ap-tab="campaign" aria-selected="false">
                <?php echo esc_html__('SKU campaign and schedule', 'lavka-price-sync'); ?>
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
        </section>

        <section class="lps-ap-panel lps-ap-campaign-panel" data-lps-ap-panel="campaign" hidden>
            <h2><?php echo esc_html__('Accounting-price SKU campaign', 'lavka-price-sync'); ?></h2>
            <p class="description lps-ap-native-description">
                <?php echo esc_html__('The campaign builds a fresh snapshot, selects only UNVERIFIED, NEW and DIRTY products, recalculates them in sequential native-range batches, and finishes with a mandatory verification snapshot. FAILED and VERIFIED products are never selected automatically.', 'lavka-price-sync'); ?>
            </p>
            <div class="lps-ap-campaign-actions">
                <label>
                    <input type="checkbox" id="lps-ap-campaign-confirm">
                    <?php echo esc_html__('I confirm the Folio maintenance window and understand that native-range apply changes accounting prices.', 'lavka-price-sync'); ?>
                </label>
                <div>
                    <button type="button" class="button button-primary" id="lps-ap-campaign-start" disabled>
                        <?php echo esc_html__('Start SKU campaign for selected warehouse', 'lavka-price-sync'); ?>
                    </button>
                    <button type="button" class="button" id="lps-ap-campaign-stop" <?php disabled(empty($campaign_state['active'])); ?>>
                        <?php echo esc_html__('Stop safely after current operation', 'lavka-price-sync'); ?>
                    </button>
                    <a class="button" href="<?php echo esc_url(wp_nonce_url(
                        admin_url('admin-post.php?action=lps_accounting_price_campaign_export'),
                        'lps_accounting_price_campaign_export'
                    )); ?>">
                        <?php echo esc_html__('Export campaign report CSV', 'lavka-price-sync'); ?>
                    </a>
                </div>
            </div>
            <div id="lps-ap-campaign-notice" class="lps-ap-result-notice" hidden></div>
            <div id="lps-ap-campaign-dashboard" class="lps-ap-campaign-dashboard" aria-live="polite"></div>

            <section class="lps-ap-cron-settings" aria-labelledby="lps-ap-cron-heading">
                <h3 id="lps-ap-cron-heading"><?php echo esc_html__('Campaign parameters and weekly schedule', 'lavka-price-sync'); ?></h3>

                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="lps-ap-cron-form">
                    <input type="hidden" name="action" value="lps_accounting_prices_save_cron">
                    <?php wp_nonce_field('lps_accounting_prices_save_cron'); ?>

                    <fieldset class="lps-ap-campaign-parameters">
                        <legend><?php echo esc_html__('Campaign parameters', 'lavka-price-sync'); ?></legend>
                        <p class="description"><?php echo esc_html__('These parameters apply to both manual runs and the weekly schedule.', 'lavka-price-sync'); ?></p>
                        <div class="lps-ap-campaign-parameters-grid">
                            <label>
                                <span><?php echo esc_html__('First batch size', 'lavka-price-sync'); ?></span>
                                <input type="number" name="campaign_initial_batch_size" min="1" max="500" value="<?php echo esc_attr((string)$cron_options['campaign_initial_batch_size']); ?>">
                            </label>
                            <label>
                                <span><?php echo esc_html__('Maximum batch size', 'lavka-price-sync'); ?></span>
                                <input type="number" name="campaign_max_batch_size" min="1" max="500" value="<?php echo esc_attr((string)$cron_options['campaign_max_batch_size']); ?>">
                            </label>
                            <label>
                                <span><?php echo esc_html__('Maintenance window, minutes', 'lavka-price-sync'); ?></span>
                                <input type="number" name="campaign_window_minutes" min="30" max="720" value="<?php echo esc_attr((string)$cron_options['campaign_window_minutes']); ?>">
                            </label>
                            <label>
                                <span><?php echo esc_html__('Snapshot horizon, months', 'lavka-price-sync'); ?></span>
                                <input type="number" name="campaign_horizon_months" min="12" max="36" value="<?php echo esc_attr((string)$cron_options['campaign_horizon_months']); ?>">
                            </label>
                        </div>
                    </fieldset>

                    <h4 class="lps-ap-schedule-parameters-heading"><?php echo esc_html__('Automatic weekly SKU campaign', 'lavka-price-sync'); ?></h4>
                    <p><?php echo esc_html__('The schedule is disabled by default. Warehouses run sequentially. The first campaign also includes UNVERIFIED products; regular runs select only NEW and DIRTY states.', 'lavka-price-sync'); ?></p>

                    <section class="lps-ap-saved-schedule" aria-labelledby="lps-ap-saved-schedule-heading">
                        <h4 id="lps-ap-saved-schedule-heading"><?php echo esc_html__('Saved schedule', 'lavka-price-sync'); ?></h4>
                        <dl class="lps-ap-cron-status">
                            <div>
                                <dt><?php echo esc_html__('Schedule status', 'lavka-price-sync'); ?></dt>
                                <dd><?php echo esc_html($saved_schedule_status); ?></dd>
                            </div>
                            <div>
                                <dt><?php echo esc_html__('Selected Folio warehouses', 'lavka-price-sync'); ?></dt>
                                <dd id="lps-ap-saved-warehouses"
                                    data-warehouse-ids="<?php echo esc_attr(wp_json_encode($saved_warehouse_ids)); ?>"><?php echo esc_html($saved_warehouse_text); ?></dd>
                            </div>
                            <div>
                                <dt><?php echo esc_html__('Weekly start', 'lavka-price-sync'); ?></dt>
                                <dd>
                                    <?php
                                    printf(
                                        /* translators: 1: weekday, 2: local start time, 3: WordPress timezone. */
                                        esc_html__('%1$s at %2$s (%3$s)', 'lavka-price-sync'),
                                        esc_html($saved_weekday),
                                        esc_html((string)($cron_options['time'] ?? '03:30')),
                                        esc_html(wp_timezone_string())
                                    );
                                    ?>
                                </dd>
                            </div>
                            <div>
                                <dt><?php echo esc_html__('Maintenance window', 'lavka-price-sync'); ?></dt>
                                <dd>
                                    <?php
                                    printf(
                                        /* translators: %d: maintenance window length in minutes. */
                                        esc_html__('%d minutes', 'lavka-price-sync'),
                                        absint($cron_options['campaign_window_minutes'] ?? 240)
                                    );
                                    ?>
                                </dd>
                            </div>
                            <div>
                                <dt><?php echo esc_html__('SKU batch sizes', 'lavka-price-sync'); ?></dt>
                                <dd>
                                    <?php
                                    printf(
                                        /* translators: 1: first batch size, 2: maximum batch size. */
                                        esc_html__('first %1$d, maximum %2$d', 'lavka-price-sync'),
                                        absint($cron_options['campaign_initial_batch_size'] ?? 100),
                                        absint($cron_options['campaign_max_batch_size'] ?? 500)
                                    );
                                    ?>
                                </dd>
                            </div>
                            <div>
                                <dt><?php echo esc_html__('Snapshot horizon', 'lavka-price-sync'); ?></dt>
                                <dd>
                                    <?php
                                    printf(
                                        /* translators: %d: snapshot horizon in months. */
                                        esc_html__('%d months', 'lavka-price-sync'),
                                        absint($cron_options['campaign_horizon_months'] ?? 24)
                                    );
                                    ?>
                                </dd>
                            </div>
                            <div>
                                <dt><?php echo esc_html__('Next scheduled run', 'lavka-price-sync'); ?></dt>
                                <dd><?php echo esc_html($next_run
                                    ? wp_date(get_option('date_format') . ' ' . get_option('time_format'), $next_run, wp_timezone())
                                    : __('Not scheduled', 'lavka-price-sync')); ?></dd>
                            </div>
                        </dl>
                    </section>

                    <?php if (!empty($cron_options['paused_reason'])): ?>
                        <div class="notice notice-error inline"><p>
                            <strong><?php echo esc_html__('Schedule paused:', 'lavka-price-sync'); ?></strong>
                            <?php echo esc_html((string)$cron_options['paused_reason']); ?>
                        </p></div>
                    <?php endif; ?>

                    <label class="lps-ap-cron-toggle">
                        <input type="checkbox" name="enabled" value="1" <?php checked(!empty($cron_options['enabled'])); ?>>
                        <strong><?php echo esc_html__('Enable weekly SKU campaign', 'lavka-price-sync'); ?></strong>
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
                            <p class="description"><?php echo esc_html__('Selected warehouses are processed sequentially. A regular FAILED result stops only the current warehouse; FAILED_PARTIAL or OUTCOME_UNKNOWN stops the whole campaign for manual review.', 'lavka-price-sync'); ?></p>
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
                        <?php echo esc_html__('I understand that the schedule performs real native-range recalculation for eligible snapshot states. Problem products are skipped and recorded, and every processed warehouse receives a final verification snapshot.', 'lavka-price-sync'); ?>
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
                        <button type="submit" class="button button-primary"><?php echo esc_html__('Save campaign parameters and schedule', 'lavka-price-sync'); ?></button>
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
                                if ($batch_error_count > 0 || $batch_warning_count > 0) {
                                    echo '<br>';
                                    printf(
                                        /* translators: 1: recorded warehouse error count, 2: warning count. */
                                        esc_html__('Recorded warehouse errors: %1$d; warnings: %2$d', 'lavka-price-sync'),
                                        $batch_error_count,
                                        $batch_warning_count
                                    );
                                }
                            }
                            ?>
                        </dd>
                    </div>
                </dl>

                <?php if ($batch_results): ?>
                    <section class="lps-ap-batch-report" aria-labelledby="lps-ap-batch-report-heading">
                        <div class="lps-ap-section-heading">
                            <div>
                                <h4 id="lps-ap-batch-report-heading"><?php echo esc_html__('Last scheduled recalculation report', 'lavka-price-sync'); ?></h4>
                                <p class="description"><?php echo esc_html__('The report preserves when and why a product or warehouse was skipped. Safe products continue to be recalculated.', 'lavka-price-sync'); ?></p>
                            </div>
                            <a class="button" href="<?php echo esc_url(wp_nonce_url(
                                admin_url('admin-post.php?action=lps_accounting_prices_export_batch_report'),
                                'lps_accounting_prices_export_batch_report'
                            )); ?>">
                                <?php echo esc_html__('Export detailed CSV', 'lavka-price-sync'); ?>
                            </a>
                        </div>

                        <div class="lps-ap-table-wrap">
                            <table class="widefat striped lps-ap-batch-table">
                                <thead><tr>
                                    <th><?php echo esc_html__('When', 'lavka-price-sync'); ?></th>
                                    <th><?php echo esc_html__('Stage', 'lavka-price-sync'); ?></th>
                                    <th><?php echo esc_html__('Warehouse', 'lavka-price-sync'); ?></th>
                                    <th><?php echo esc_html__('Result', 'lavka-price-sync'); ?></th>
                                    <th><?php echo esc_html__('SKU', 'lavka-price-sync'); ?></th>
                                    <th><?php echo esc_html__('Reason', 'lavka-price-sync'); ?></th>
                                    <th><?php echo esc_html__('Explanation', 'lavka-price-sync'); ?></th>
                                </tr></thead>
                                <tbody>
                                <?php foreach ($batch_report_rows as $row): ?>
                                    <?php
                                    $severity = sanitize_key((string)$row['severity']);
                                    $stage_label = $batch_stage_labels[$row['stage']] ?? strtoupper((string)$row['stage']);
                                    $status_label = $native_status_labels[$row['status']]
                                        ?? ($batch_status_labels[$row['status']] ?? $row['status']);
                                    $details_text = lps_accounting_prices_batch_details_text($row['details']);
                                    ?>
                                    <tr class="lps-ap-batch-row is-<?php echo esc_attr($severity ?: 'success'); ?>">
                                        <td><?php echo esc_html((string)$row['completed_at']); ?></td>
                                        <td><?php echo esc_html((string)$stage_label); ?></td>
                                        <td><?php echo esc_html((string)$row['warehouse_id']); ?></td>
                                        <td><?php echo esc_html((string)$status_label); ?></td>
                                        <td><code><?php echo esc_html((string)($row['sku'] ?: '—')); ?></code></td>
                                        <td>
                                            <strong><?php echo esc_html((string)$row['code']); ?></strong>
                                            <?php if ($row['message'] !== ''): ?><p><?php echo esc_html((string)$row['message']); ?></p><?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($details_text !== ''): ?><p><?php echo esc_html($details_text); ?></p><?php endif; ?>
                                            <?php if ($row['details']): ?>
                                                <details>
                                                    <summary><?php echo esc_html__('Technical details', 'lavka-price-sync'); ?></summary>
                                                    <pre><?php echo esc_html((string)wp_json_encode($row['details'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)); ?></pre>
                                                </details>
                                            <?php elseif ($details_text === '' && $row['message'] === ''): ?>
                                                <?php echo esc_html__('No problems were reported for this warehouse.', 'lavka-price-sync'); ?>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </section>
                <?php endif; ?>
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

add_action('admin_post_lps_accounting_prices_export_batch_report', function (): void {
    if (!current_user_can(LPS_CAP)) {
        wp_die(esc_html__('You do not have permission to perform this operation.', 'lavka-price-sync'));
    }
    check_admin_referer('lps_accounting_prices_export_batch_report');

    $batch = lps_accounting_prices_native_batch_state();
    $rows = lps_accounting_prices_batch_report_rows($batch);
    $filename = 'folio-accounting-price-report-' . wp_date('Ymd-His') . '.csv';

    nocache_headers();
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    echo "\xEF\xBB\xBF";
    $output = fopen('php://output', 'wb');
    if ($output === false) exit;

    fputcsv($output, [
        __('When', 'lavka-price-sync'),
        __('Stage', 'lavka-price-sync'),
        __('Warehouse', 'lavka-price-sync'),
        __('Result', 'lavka-price-sync'),
        __('Severity', 'lavka-price-sync'),
        __('SKU', 'lavka-price-sync'),
        __('Reason', 'lavka-price-sync'),
        __('Explanation', 'lavka-price-sync'),
        __('Technical details', 'lavka-price-sync'),
        __('Java job ID', 'lavka-price-sync'),
    ], ';', '"', '');

    foreach ($rows as $row) {
        fputcsv($output, [
            $row['completed_at'],
            $row['stage'],
            $row['warehouse_id'],
            $row['status'],
            $row['severity'],
            $row['sku'],
            $row['code'],
            $row['message'] !== '' ? $row['message'] : lps_accounting_prices_batch_details_text($row['details']),
            wp_json_encode($row['details'], JSON_UNESCAPED_UNICODE),
            $row['job_id'],
        ], ';', '"', '');
    }
    fclose($output);
    exit;
});

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

        case 'campaign_start':
            $warehouse_id = absint($_POST['warehouseId'] ?? 0);
            if ($warehouse_id < 1) {
                wp_send_json_error(['message' => __('Folio warehouse is required.', 'lavka-price-sync')], 400);
            }
            if ((string)($_POST['confirmApply'] ?? '') !== '1') {
                wp_send_json_error(['message' => __('Explicit confirmation is required for Folio changes.', 'lavka-price-sync')], 400);
            }
            wp_send_json_success(lps_accounting_price_campaign_create([$warehouse_id], 'manual'));
            break;

        case 'campaign_status':
            wp_send_json_success(lps_accounting_price_campaign_public_state());
            break;

        case 'campaign_snapshot_items':
            wp_send_json_success(lps_accounting_price_campaign_snapshot_items(
                (string)wp_unslash($_POST['verificationState'] ?? ''),
                absint($_POST['page'] ?? 1),
                absint($_POST['perPage'] ?? 50)
            ));
            break;

        case 'campaign_stop':
            wp_send_json_success(lps_accounting_price_campaign_request_stop());
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
