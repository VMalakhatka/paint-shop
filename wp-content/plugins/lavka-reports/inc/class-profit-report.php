<?php
if (!defined('ABSPATH')) exit;

class Lavka_Reports_Profit_Report {
    const PAGE_SLUG = 'lavka-profit-report';
    const AJAX_ACTION = 'lavr_profit_report';
    const NONCE_ACTION = 'lavka_profit_report_nonce';

    public function __construct() {
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_enqueue_scripts', [$this, 'assets']);
        add_action('wp_ajax_' . self::AJAX_ACTION, [$this, 'ajax_report']);
    }

    public function menu() {
        add_submenu_page(
            function_exists('paint_core_lavka_admin_parent_slug') ? paint_core_lavka_admin_parent_slug() : Lavka_Reports_Admin::PAGE_SLUG,
            __('Monthly Folio profit report', 'lavka-reports'),
            __('Folio profit', 'lavka-reports'),
            'manage_woocommerce',
            self::PAGE_SLUG,
            [$this, 'render_page']
        );
    }

    public function assets($hook) {
        $requested_page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        if ('lavka-reports_page_' . self::PAGE_SLUG !== $hook && self::PAGE_SLUG !== $requested_page) return;

        wp_enqueue_style(
            'lavr-profit-report',
            LAVR_URL . 'profit-report.css',
            [],
            LAVR_VER
        );
        wp_enqueue_script('lavr-profit-xlsx', LAVR_URL . 'profit-xlsx.js', [], LAVR_VER, true);
        wp_enqueue_script(
            'lavr-profit-report',
            LAVR_URL . 'profit-report.js',
            ['lavr-profit-xlsx'],
            LAVR_VER,
            true
        );
        wp_localize_script('lavr-profit-report', 'LavkaProfitReport', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'action'  => self::AJAX_ACTION,
            'nonce'   => wp_create_nonce(self::NONCE_ACTION),
            'i18n'    => $this->translations(),
        ]);
    }

    public function render_page() {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('You do not have permission to view this report.', 'lavka-reports'));
        }
        ?>
        <div class="wrap lavr-profit-report" id="lavr-profit-report">
            <h1><?php echo esc_html__('Monthly Folio profit report', 'lavka-reports'); ?></h1>

            <section class="lavr-profit-toolbar" aria-labelledby="lavr-profit-period-title">
                <div class="lavr-profit-field">
                    <label id="lavr-profit-period-title" for="lavr-profit-month"><?php echo esc_html__('Report month', 'lavka-reports'); ?></label>
                    <input type="month" id="lavr-profit-month" name="month" required>
                </div>
                <button type="button" class="button button-primary" id="lavr-profit-calculate">
                    <?php echo esc_html__('Calculate report', 'lavka-reports'); ?>
                </button>
                <div class="lavr-profit-run-state" id="lavr-profit-run-state" aria-live="polite" hidden></div>
            </section>

            <div id="lavr-profit-error" class="notice notice-error lavr-profit-notice" hidden></div>

            <details class="lavr-profit-manual" id="lavr-profit-manual">
                <summary><?php echo esc_html__('Manual report parameters', 'lavka-reports'); ?></summary>
                <p class="description lavr-profit-manual-help"><?php echo esc_html__('Leave additional salary empty to use the server default. Enter zero to override it with zero.', 'lavka-reports'); ?></p>
                <div class="lavr-profit-manual-grid">
                    <div class="lavr-profit-field">
                        <label for="lavr-profit-additional-salary"><?php echo esc_html__('Odesa additional salary', 'lavka-reports'); ?></label>
                        <input type="text" inputmode="decimal" id="lavr-profit-additional-salary" data-param="odesaAdditionalSalary" autocomplete="off">
                        <p id="lavr-profit-salary-source" class="description"></p>
                    </div>
                    <div class="lavr-profit-field">
                        <label for="lavr-profit-kyiv-salary"><?php echo esc_html__('Kyiv additional work', 'lavka-reports'); ?></label>
                        <input type="text" inputmode="decimal" id="lavr-profit-kyiv-salary" data-param="kyivAdditionalSalary" autocomplete="off">
                        <p id="lavr-profit-kyiv-salary-source" class="description"></p>
                    </div>
                    <div class="lavr-profit-field">
                        <label for="lavr-profit-tax-share"><?php echo esc_html__('Odesa employee share', 'lavka-reports'); ?></label>
                        <div class="lavr-profit-input-suffix">
                            <input type="text" inputmode="decimal" id="lavr-profit-tax-share" data-param="odesaTaxShare" autocomplete="off">
                            <span>%</span>
                        </div>
                        <p class="description" id="lavr-profit-tax-share-help"><?php echo esc_html__('Share of officially employed Odesa staff.', 'lavka-reports'); ?></p>
                    </div>
                    <div class="lavr-profit-field">
                        <label for="lavr-profit-rub-rate"><?php echo esc_html__('RUB to UAH rate', 'lavka-reports'); ?></label>
                        <input type="text" inputmode="decimal" id="lavr-profit-rub-rate" data-param="rubToUahRate" autocomplete="off">
                    </div>
                </div>
                <button type="button" class="button" id="lavr-profit-recalculate">
                    <?php echo esc_html__('Recalculate with these parameters', 'lavka-reports'); ?>
                </button>
            </details>

            <div id="lavr-profit-result" hidden>
                <div class="lavr-profit-heading-row lavr-profit-export-bar">
                    <strong id="lavr-profit-result-period"></strong>
                    <button type="button" class="button" id="lavr-profit-export-xlsx"><?php echo esc_html__('Export report to Excel', 'lavka-reports'); ?></button>
                </div>
                <p class="description"><?php echo esc_html__('Excel includes both cities and all loaded audit rows, regardless of the table filters. Missing audit data is loaded before export.', 'lavka-reports'); ?></p>
                <details class="lavr-profit-controls"><summary><?php echo esc_html__('Applied parameters and selection period', 'lavka-reports'); ?></summary><div id="lavr-profit-policy"></div></details>
                <section class="lavr-profit-summary" aria-labelledby="lavr-profit-summary-title">
                    <div class="lavr-profit-heading-row">
                        <div>
                            <h2 id="lavr-profit-summary-title"><?php echo esc_html__('Profit by city', 'lavka-reports'); ?></h2>
                            <p id="lavr-profit-calculated-at" class="description"></p>
                        </div>
                        <span id="lavr-profit-completeness" class="lavr-profit-badge"></span>
                    </div>
                    <div id="lavr-profit-cities" class="lavr-profit-cities"></div>
                </section>

                <section id="lavr-profit-master-class" class="lavr-profit-summary" aria-live="polite"></section>

                <section id="lavr-profit-warnings-section" class="lavr-profit-warnings-section" hidden aria-labelledby="lavr-profit-warnings-title">
                    <h2 id="lavr-profit-warnings-title"><?php echo esc_html__('Warnings and checks', 'lavka-reports'); ?></h2>
                    <div id="lavr-profit-warnings"></div>
                </section>

                <section class="lavr-profit-expenses" aria-labelledby="lavr-profit-expenses-title">
                    <div class="lavr-profit-heading-row">
                        <h2 id="lavr-profit-expenses-title"><?php echo esc_html__('Expense breakdown', 'lavka-reports'); ?></h2>
                        <div class="lavr-profit-segments" id="lavr-profit-expense-filter" role="group" aria-label="<?php echo esc_attr__('Filter expenses by city', 'lavka-reports'); ?>"></div>
                    </div>
                    <p id="lavr-profit-expense-note" class="description"></p>
                    <div class="lavr-profit-table-wrap">
                        <table class="widefat striped" id="lavr-profit-expenses-table">
                            <thead><tr>
                                <th><?php echo esc_html__('City', 'lavka-reports'); ?></th>
                                <th><?php echo esc_html__('Expense', 'lavka-reports'); ?></th>
                                <th><?php echo esc_html__('Documents', 'lavka-reports'); ?></th>
                                <th><?php echo esc_html__('Amount', 'lavka-reports'); ?></th>
                                <th><?php echo esc_html__('Profit impact', 'lavka-reports'); ?></th>
                                <th><?php echo esc_html__('Accounting treatment', 'lavka-reports'); ?></th>
                            </tr></thead>
                            <tbody></tbody>
                        </table>
                    </div>
                </section>

                <details class="lavr-profit-controls" id="lavr-profit-controls">
                    <summary><?php echo esc_html__('Control totals', 'lavka-reports'); ?></summary>
                    <div id="lavr-profit-controls-content"></div>
                </details>

                <details class="lavr-profit-controls"><summary><?php echo esc_html__('Inventory accounting value', 'lavka-reports'); ?></summary><div id="lavr-profit-inventory"></div></details>
                <section id="lavr-profit-period-diagnostics" class="lavr-profit-audit" hidden></section>
                <section class="lavr-profit-audit" aria-labelledby="lavr-profit-audit-title">
                    <div class="lavr-profit-heading-row">
                        <div>
                            <h2 id="lavr-profit-audit-title"><?php echo esc_html__('Document audit', 'lavka-reports'); ?></h2>
                            <p class="description"><?php echo esc_html__('The audit is loaded separately and does not change Folio data.', 'lavka-reports'); ?></p>
                        </div>
                        <button type="button" class="button" id="lavr-profit-load-audit"><?php echo esc_html__('Load document audit', 'lavka-reports'); ?></button>
                    </div>
                    <div id="lavr-profit-master-audit" hidden></div>
                    <div id="lavr-profit-audit-content" hidden>
                        <div id="lavr-profit-audit-note" class="notice notice-warning inline" hidden></div>
                        <div class="lavr-profit-audit-filters">
                            <label><?php echo esc_html__('City', 'lavka-reports'); ?><select id="lavr-profit-audit-city"></select></label>
                            <label><?php echo esc_html__('Category', 'lavka-reports'); ?><select id="lavr-profit-audit-category"></select></label>
                            <label><?php echo esc_html__('Accounting treatment', 'lavka-reports'); ?><select id="lavr-profit-audit-treatment"></select></label>
                            <label class="lavr-profit-checkbox"><input type="checkbox" id="lavr-profit-audit-unclassified"> <?php echo esc_html__('Only unclassified', 'lavka-reports'); ?></label>
                            <button type="button" class="button" id="lavr-profit-export-audit"><?php echo esc_html__('Export loaded rows to CSV', 'lavka-reports'); ?></button>
                        </div>
                        <div class="lavr-profit-table-wrap">
                            <table class="widefat striped" id="lavr-profit-audit-table">
                                <thead><tr>
                                    <th><?php echo esc_html__('Date and document', 'lavka-reports'); ?></th>
                                    <th><?php echo esc_html__('Flow', 'lavka-reports'); ?></th>
                                    <th><?php echo esc_html__('Warehouse', 'lavka-reports'); ?></th>
                                    <th><?php echo esc_html__('Purpose / code / name / class', 'lavka-reports'); ?></th>
                                    <th><?php echo esc_html__('Source amount', 'lavka-reports'); ?></th>
                                    <th><?php echo esc_html__('Report amount', 'lavka-reports'); ?></th>
                                    <th><?php echo esc_html__('City / category', 'lavka-reports'); ?></th>
                                    <th><?php echo esc_html__('Accounting treatment', 'lavka-reports'); ?></th>
                                    <th><?php echo esc_html__('Included in profit', 'lavka-reports'); ?></th>
                                    <th><?php echo esc_html__('Reason', 'lavka-reports'); ?></th>
                                </tr></thead>
                                <tbody></tbody>
                            </table>
                        </div>
                    </div>
                </section>
            </div>
        </div>
        <?php
    }

    public function ajax_report() {
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('You do not have permission to view this report.', 'lavka-reports')], 403);
        }
        check_ajax_referer(self::NONCE_ACTION, 'nonce');

        $operation = sanitize_key(wp_unslash($_POST['operation'] ?? 'summary'));
        if (!in_array($operation, ['summary', 'audit'], true)) {
            wp_send_json_error(['field' => 'operation', 'message' => __('Unknown report operation.', 'lavka-reports')], 400);
        }

        $month = sanitize_text_field(wp_unslash($_POST['month'] ?? ''));
        if (!$this->valid_month($month)) {
            wp_send_json_error(['field' => 'month', 'message' => __('Enter a valid report month.', 'lavka-reports')], 400);
        }

        $query = ['month' => $month];
        $rules = [
            'odesaTaxShare'          => ['min' => 0, 'max' => 1, 'positive' => false],
            'rubToUahRate'           => ['min' => 0, 'max' => null, 'positive' => true],
            'kyivAdditionalSalary'   => ['min' => 0, 'max' => null, 'positive' => false],
            'odesaAdditionalSalary'  => ['min' => 0, 'max' => null, 'positive' => false],
        ];

        foreach ($rules as $name => $rule) {
            $raw = isset($_POST[$name]) ? trim(sanitize_text_field(wp_unslash($_POST[$name]))) : '';
            if ('' === $raw) continue;
            $value = str_replace(',', '.', $raw);
            if (!preg_match('/^\d+(?:\.\d+)?$/', $value)) {
                wp_send_json_error(['field' => $name, 'message' => __('Enter a valid non-negative decimal value.', 'lavka-reports')], 400);
            }
            $number = (float) $value;
            if (($rule['positive'] && $number <= 0) || (!$rule['positive'] && $number < $rule['min']) || (null !== $rule['max'] && $number > $rule['max'])) {
                wp_send_json_error(['field' => $name, 'message' => __('The report parameter is outside the allowed range.', 'lavka-reports')], 400);
            }
            $query[$name] = $value;
        }

        $sync_options = function_exists('lavka_sync_get_options')
            ? lavka_sync_get_options()
            : get_option('lavka_sync_options', []);
        $base = rtrim((string) ($sync_options['java_base_url'] ?? ''), '/');
        $token = (string) ($sync_options['api_token'] ?? '');
        if ('' === $base) {
            wp_send_json_error(['message' => __('Java service URL is not configured.', 'lavka-reports')], 500);
        }

        $path = 'audit' === $operation
            ? '/admin/folio/profit-report/audit'
            : '/admin/folio/profit-report';
        $response = wp_remote_get(add_query_arg($query, $base . $path), [
            'timeout' => 160,
            'headers' => array_filter([
                'Accept'       => 'application/json',
                'X-Auth-Token' => $token ?: null,
            ]),
        ]);
        if (is_wp_error($response)) {
            wp_send_json_error(['message' => $response->get_error_message()], 502);
        }

        wp_send_json_success([
            'httpStatus' => (int) wp_remote_retrieve_response_code($response),
            'bodyRaw'    => (string) wp_remote_retrieve_body($response),
        ]);
    }

    private function valid_month($month) {
        if (!preg_match('/^(\d{4})-(\d{2})$/', $month, $matches)) return false;
        return checkdate((int) $matches[2], 1, (int) $matches[1]);
    }

    private function translations() {
        return [
            'masterIncluded' => __('Included in master class contribution', 'lavka-reports'),
            'masterTitle' => __('Odesa master class', 'lavka-reports'),
            'masterUnavailable' => __('Automatic master class data is unavailable. Check the Java report version.', 'lavka-reports'),
            'masterMissing' => __('The master class article was not found. This is not a confirmed zero.', 'lavka-reports'),
            'sku' => __('SKU', 'lavka-reports'),
            'warehouse' => __('Warehouse', 'lavka-reports'),
            'masterIncome' => __('Master class income', 'lavka-reports'),
            'masterReturns' => __('Master class returns', 'lavka-reports'),
            'masterNet' => __('Net contribution', 'lavka-reports'),
            'masterBase' => __('Gross profit already included in the base', 'lavka-reports'),
            'masterAdjustment' => __('Applied gross adjustment', 'lavka-reports'),
            'incomeLines' => __('Income lines', 'lavka-reports'),
            'returnLines' => __('Return lines', 'lavka-reports'),
            'ignoredLines' => __('Ignored lines', 'lavka-reports'),
            'duplicateLines' => __('Duplicate lines', 'lavka-reports'),
            'source' => __('Source', 'lavka-reports'),
            'salaryDefault' => __('Server default', 'lavka-reports'),
            'salaryOverride' => __('Request override', 'lavka-reports'),
            'salaryApplied' => __('Applied additional salary', 'lavka-reports'),
            'masterAuditTitle' => __('Master class invoice audit', 'lavka-reports'),
            'masterTruncated' => __('The master class audit is truncated by the server. Not all lines are shown.', 'lavka-reports'),
            'masterAuditEmpty' => __('No master class invoice lines.', 'lavka-reports'),
            'line' => __('Line / movement ID', 'lavka-reports'),
            'documentTypes' => __('Document / movement type', 'lavka-reports'),
            'operationKind' => __('Operation', 'lavka-reports'),
            'returnFlag' => __('Return', 'lavka-reports'),
            'accounted' => __('Accounted', 'lavka-reports'),
            'quantity' => __('Quantity', 'lavka-reports'),
            'unitPrice' => __('Unit price', 'lavka-reports'),
            'classification' => __('Classification', 'lavka-reports'),
            'loading' => __('Calculating report...', 'lavka-reports'),
            'recalculating' => __('Recalculating; the previous result is still shown.', 'lavka-reports'),
            'loadingAudit' => __('Loading document audit...', 'lavka-reports'),
            'reportReady' => __('Report calculated successfully.', 'lavka-reports'),
            'auditReady' => __('Document audit loaded successfully.', 'lavka-reports'),
            'requestFailed' => __('The operation failed. Review the error below.', 'lavka-reports'),
            'complete' => __('Report complete', 'lavka-reports'),
            'incomplete' => __('Needs review', 'lavka-reports'),
            /* translators: %s is the local date and time when the report was calculated. */
            'calculatedAt' => __('Calculated at %s', 'lavka-reports'),
            'noData' => __('No data for the selected month.', 'lavka-reports'),
            'noExpenses' => __('No expenses match this filter.', 'lavka-reports'),
            'noAudit' => __('No audit documents match this filter.', 'lavka-reports'),
            'retry' => __('Try again', 'lavka-reports'),
            'generalError' => __('The Folio report could not be loaded. Try again.', 'lavka-reports'),
            'invalidResponse' => __('The Folio service returned an invalid response.', 'lavka-reports'),
            'all' => __('All', 'lavka-reports'),
            'kyiv' => __('Kyiv', 'lavka-reports'),
            'odesa' => __('Odesa', 'lavka-reports'),
            'unallocated' => __('Unallocated', 'lavka-reports'),
            'baseGrossProfit' => __('Base gross profit', 'lavka-reports'),
            'manualGrossAdjustments' => __('Master class adjustment', 'lavka-reports'),
            'grossProfit' => __('Gross profit', 'lavka-reports'),
            'operatingExpenses' => __('Operating expenses', 'lavka-reports'),
            'profit' => __('Profit', 'lavka-reports'),
            'operatingTreatment' => __('Deducted from profit', 'lavka-reports'),
            'capitalizedTreatment' => __('Included in inventory cost', 'lavka-reports'),
            'excludedTreatment' => __('Not a report expense', 'lavka-reports'),
            'unclassifiedTreatment' => __('Needs a rule', 'lavka-reports'),
            'capitalizedHelp' => __('This amount is already included in inventory cost and is not deducted again.', 'lavka-reports'),
            'warningAction' => __('Action required', 'lavka-reports'),
            'warningManual' => __('Manual input required', 'lavka-reports'),
            'warningInfo' => __('Information', 'lavka-reports'),
            'details' => __('Details', 'lavka-reports'),
            'selectedDocuments' => __('Selected documents', 'lavka-reports'),
            'selectedAmount' => __('Selected document amount', 'lavka-reports'),
            'operatingTotal' => __('Operating expenses', 'lavka-reports'),
            'capitalizedTotal' => __('Capitalized in inventory', 'lavka-reports'),
            'excludedTotal' => __('Excluded documents', 'lavka-reports'),
            'unclassifiedTotal' => __('Unclassified documents', 'lavka-reports'),
            'taxPools' => __('Tax pools', 'lavka-reports'),
            'auditTruncated' => __('The document audit is limited to the first 500 rows.', 'lavka-reports'),
            'exportLoaded' => __('Only the currently loaded audit rows are exported.', 'lavka-reports'),
            'bank' => __('Bank', 'lavka-reports'),
            'cash' => __('Cash', 'lavka-reports'),
            'uah' => __('UAH', 'lavka-reports'),
            'rub' => __('RUB', 'lavka-reports'),
            'yes' => __('Yes', 'lavka-reports'),
            'no' => __('No', 'lavka-reports'),
            'threeOfSeven' => __('3 of 7 employees', 'lavka-reports'),
            'shareHelp' => __('Share of officially employed Odesa staff.', 'lavka-reports'),
            'documentsLower' => __('documents', 'lavka-reports'),
            'monthRequired' => __('Select a report month.', 'lavka-reports'),
            'reportMonth' => __('Report month', 'lavka-reports'),
            'detailedRows' => __('Detailed rows and selection rules supplied by Java. Manual amounts have no documents.', 'lavka-reports'),
            'legacyRows' => __('The server returns category totals. Detailed expense rows and selection rules require the updated Java API.', 'lavka-reports'),
            'legacyKyiv' => __('Available after the Java API update. Default: 0 UAH.', 'lavka-reports'),
            'legacyPeriod' => __('The server did not supply the selection period. Do not assume the new period rules were applied.', 'lavka-reports'),
            'parametersChanged' => __('Parameters changed. Recalculate before loading audit or exporting.', 'lavka-reports'),
            'allWarehouses' => __('All warehouses', 'lavka-reports'),
            'onlyWarehouses' => __('Only warehouses', 'lavka-reports'),
            'exceptWarehouses' => __('Except warehouses', 'lavka-reports'),
            'notUsed' => __('Not used', 'lavka-reports'),
            'anyPurpose' => __('Any purpose code', 'lavka-reports'),
            'cityTotal' => __('City total', 'lavka-reports'),
            'inventoryHelp' => __('Inventory value is informational and is not added to profit. City totals and their warehouse details must not be summed together.', 'lavka-reports'),
            'periodDiagnostics' => __('Period checks', 'lavka-reports'),
            'diagnosticsHelp' => __('Separate period register. Documents belonging to another month are expected exclusions. Problematic amounts included in totals require review; see inclusion, impact and reason.', 'lavka-reports'),
            'diagnosticsTruncated' => __('The period diagnostic list is truncated; this is not a complete document register.', 'lavka-reports'),
            'snapshot' => __('Calculated at (Kyiv time)', 'lavka-reports'),
            'exportSnapshot' => __('Snapshot of one API response. Folio data can change; see warnings and completeness.', 'lavka-reports'),
            'profitByCity' => __('Profit by city', 'lavka-reports'),
            'appliedParameters' => __('Applied parameters', 'lavka-reports'),
            'controlTotals' => __('Control totals', 'lavka-reports'),
            'controlsHelp' => __('Control totals cover the whole report. Manual amounts do not increase document count. Shared tax documents can affect two city rows.', 'lavka-reports'),
            'warningsTitle' => __('Warnings and checks', 'lavka-reports'),
            'inventoryTitle' => __('Inventory accounting value', 'lavka-reports'),
            'auditTitle' => __('Document audit', 'lavka-reports'),
            'auditCoverage' => __('Loaded regular audit. Also check the separate period diagnostic register for problematic candidates.', 'lavka-reports'),
            'allExpenseRows' => __('All expense rows', 'lavka-reports'),
            'exportReady' => __('Excel file prepared from the displayed snapshot.', 'lavka-reports'),
            'exportFailed' => __('Could not export the report. Check the audit and try again.', 'lavka-reports'),
            'fields' => [
                'selectedDocumentCount' => __('Selected documents', 'lavka-reports'),
                'selectedDocumentAmount' => __('Selected document amount', 'lavka-reports'),
                'operatingExpenseTotal' => __('Operating expenses total', 'lavka-reports'),
                'capitalizedCostTotal' => __('Capitalized inventory cost', 'lavka-reports'),
                'excludedDocumentAmount' => __('Excluded document amount', 'lavka-reports'),
                'unclassifiedDocumentAmount' => __('Unclassified document amount', 'lavka-reports'),
                'unclassifiedDocumentCount' => __('Unclassified documents', 'lavka-reports'),
                'periodDiagnosticCount' => __('Period diagnostic candidates', 'lavka-reports'),
                'periodProblemCount' => __('Problematic periods', 'lavka-reports'),
                'provisionalDocumentCount' => __('Provisional documents', 'lavka-reports'),
                'provisionalDocumentAmount' => __('Provisional document amount', 'lavka-reports'),
                'provisionalOperatingExpenseTotal' => __('Provisional operating expenses', 'lavka-reports'),
                'auditTruncated' => __('Audit truncated', 'lavka-reports'),
                'taxPools' => __('Tax pools', 'lavka-reports'),
                'documentReason' => __('Document classification reason', 'lavka-reports'),
                'income' => __('Master class income', 'lavka-reports'),
                'returns' => __('Master class returns', 'lavka-reports'),
                'netContribution' => __('Master class net contribution', 'lavka-reports'),
                'grossProfitAlreadyInBase' => __('Master class profit already in base', 'lavka-reports'),
                'grossAdjustmentApplied' => __('Applied master class adjustment', 'lavka-reports'),
                'sku' => __('SKU', 'lavka-reports'),
                'articleFound' => __('Article found', 'lavka-reports'),
                'incomeLineCount' => __('Income lines', 'lavka-reports'),
                'returnLineCount' => __('Return lines', 'lavka-reports'),
                'ignoredLineCount' => __('Ignored lines', 'lavka-reports'),
                'duplicateLineCount' => __('Duplicate lines', 'lavka-reports'),
                'movementId' => __('Movement ID', 'lavka-reports'),
                'documentId' => __('Document ID', 'lavka-reports'),
                'documentNumberSuffix' => __('Document number suffix', 'lavka-reports'),
                'lineNumber' => __('Line number', 'lavka-reports'),
                'documentType' => __('Document type', 'lavka-reports'),
                'movementType' => __('Movement type', 'lavka-reports'),
                'operationKind' => __('Operation', 'lavka-reports'),
                'returnDocument' => __('Return document', 'lavka-reports'),
                'accounted' => __('Accounted', 'lavka-reports'),
                'classification' => __('Classification', 'lavka-reports'),
                'quantity' => __('Quantity', 'lavka-reports'),
                'unitPrice' => __('Unit price', 'lavka-reports'),
                'currency' => __('Currency', 'lavka-reports'),
                'amountSource' => __('Amount source', 'lavka-reports'),
                'includedInMasterClassContribution' => __('Included in master class contribution', 'lavka-reports'),
                'odesaMasterClassIncome' => __('Odesa master class income (automatic)', 'lavka-reports'),
                'odesaMasterClassReturn' => __('Odesa master class returns (automatic)', 'lavka-reports'),

                'city' => __('City', 'lavka-reports'),
                'label' => __('Expense', 'lavka-reports'),
                'expenseCodes' => __('Short organization name', 'lavka-reports'),
                'operationTypes' => __('Operation type', 'lavka-reports'),
                'operationRequired' => __('Operation filter required', 'lavka-reports'),
                'purposeCodes' => __('Purpose code filter', 'lavka-reports'),
                'cashWarehouses' => __('Cash warehouses', 'lavka-reports'),
                'bankWarehouses' => __('Bank warehouses', 'lavka-reports'),
                'documentCount' => __('Documents', 'lavka-reports'),
                'amount' => __('Amount', 'lavka-reports'),
                'profitImpact' => __('Profit impact', 'lavka-reports'),
                'accountingTreatment' => __('Accounting treatment', 'lavka-reports'),
                'source' => __('Source', 'lavka-reports'),
                'note' => __('Selection details', 'lavka-reports'),
                'lineId' => __('Expense row ID', 'lavka-reports'),
                'category' => __('Category', 'lavka-reports'),
                'documentDate' => __('Document date', 'lavka-reports'),
                'documentNumber' => __('Document number', 'lavka-reports'),
                'paymentId' => __('Payment ID', 'lavka-reports'),
                'expenseCode' => __('Expense code', 'lavka-reports'),
                'documentClass' => __('Document class', 'lavka-reports'),
                'purposeCode' => __('Purpose code', 'lavka-reports'),
                'stream' => __('Flow', 'lavka-reports'),
                'warehouseId' => __('Warehouse', 'lavka-reports'),
                'sourceInfo' => __('Information source', 'lavka-reports'),
                'sourceAmount' => __('Source amount', 'lavka-reports'),
                'sourceCurrency' => __('Source currency', 'lavka-reports'),
                'reportAmount' => __('Report amount, UAH', 'lavka-reports'),
                'reportCurrency' => __('Report currency', 'lavka-reports'),
                'appliedRate' => __('Applied exchange rate', 'lavka-reports'),
                'kyivAllocation' => __('Kyiv allocation, UAH', 'lavka-reports'),
                'odesaAllocation' => __('Odesa allocation, UAH', 'lavka-reports'),
                'resolvedMonth' => __('Resolved month', 'lavka-reports'),
                'periodSource' => __('Period source', 'lavka-reports'),
                'periodNote' => __('Period markers', 'lavka-reports'),
                'periodStatus' => __('Period status', 'lavka-reports'),
                'includedInProfit' => __('Included in profit', 'lavka-reports'),
                'includedInTotals' => __('Included in totals', 'lavka-reports'),
                'amountTreatment' => __('Amount treatment', 'lavka-reports'),
                'reason' => __('Reason', 'lavka-reports'),
                'warnings' => __('Warnings', 'lavka-reports'),
                'expenseLineId' => __('Expense row ID', 'lavka-reports'),
                'expenseLineIds' => __('Related expense rows', 'lavka-reports'),
                'parameter' => __('Parameter', 'lavka-reports'),
                'value' => __('Value', 'lavka-reports'),
                'month' => __('Report month', 'lavka-reports'),
                'calculatedAt' => __('Calculated at (Kyiv time)', 'lavka-reports'),
                'ruleVersion' => __('Rule version', 'lavka-reports'),
                'complete' => __('Complete', 'lavka-reports'),
                'warehouseName' => __('Warehouse name', 'lavka-reports'),
                'warehouseIds' => __('Warehouse IDs', 'lavka-reports'),
                'openingAccountingValue' => __('Opening inventory value', 'lavka-reports'),
                'closingAccountingValue' => __('Closing inventory value', 'lavka-reports'),
                'accountingValueChange' => __('Inventory value change', 'lavka-reports'),
                'openingPositionCount' => __('Opening positions', 'lavka-reports'),
                'closingPositionCount' => __('Closing positions', 'lavka-reports'),
                'negativeClosingPositionCount' => __('Negative closing positions', 'lavka-reports'),
                'zeroValueClosingPositionCount' => __('Zero value closing positions', 'lavka-reports'),
                'baseGrossProfit' => __('Base gross profit', 'lavka-reports'),
                'manualGrossAdjustments' => __('Master class adjustment', 'lavka-reports'),
                'grossProfit' => __('Gross profit', 'lavka-reports'),
                'operatingExpenses' => __('Operating expenses', 'lavka-reports'),
                'profit' => __('Profit', 'lavka-reports'),
                'code' => __('Code', 'lavka-reports'),
                'message' => __('Message', 'lavka-reports'),
                'details' => __('Details', 'lavka-reports'),
                'candidateFrom' => __('Candidate period start', 'lavka-reports'),
                'candidateToExclusive' => __('Candidate period end (exclusive)', 'lavka-reports'),
                'explicitPeriodPriority' => __('Note period takes priority', 'lavka-reports'),
                'description' => __('Description', 'lavka-reports'),
                'odesaTaxShare' => __('Odesa employee share', 'lavka-reports'),
                'rubToUahRate' => __('RUB to UAH rate', 'lavka-reports'),
                'kyivAdditionalSalary' => __('Kyiv additional work', 'lavka-reports'),
                'odesaAdditionalSalary' => __('Odesa additional salary', 'lavka-reports'),
                'kyivAdditionalSalarySource' => __('Kyiv additional work source', 'lavka-reports'),
                'odesaAdditionalSalarySource' => __('Odesa additional salary source', 'lavka-reports'),
                'kyivWarehouseIds' => __('Kyiv sales warehouses', 'lavka-reports'),
                'odesaWarehouseIds' => __('Odesa sales warehouses', 'lavka-reports'),
                'kyivStockWarehouseIds' => __('Kyiv inventory warehouses', 'lavka-reports'),
                'odesaStockWarehouseIds' => __('Odesa inventory warehouses', 'lavka-reports'),
                'taxAllocationMethod' => __('Tax allocation method', 'lavka-reports'),
                'status' => __('Status', 'lavka-reports'),
                'name' => __('Name', 'lavka-reports'),
                'filters' => __('Selection rules', 'lavka-reports'),
                'sortOrder' => __('Row order', 'lavka-reports'),
            ],
            'csvDate' => __('Date', 'lavka-reports'),
            'csvDocument' => __('Document', 'lavka-reports'),
            'csvFlow' => __('Flow', 'lavka-reports'),
            'csvWarehouse' => __('Warehouse', 'lavka-reports'),
            'csvPurpose' => __('Purpose code', 'lavka-reports'),
            'csvExpenseCode' => __('Expense code', 'lavka-reports'),
            'csvName' => __('Name', 'lavka-reports'),
            'csvClass' => __('Document class', 'lavka-reports'),
            'csvSourceAmount' => __('Source amount', 'lavka-reports'),
            'csvSourceCurrency' => __('Source currency', 'lavka-reports'),
            'csvReportAmount' => __('Report amount, UAH', 'lavka-reports'),
            'csvCity' => __('City', 'lavka-reports'),
            'csvCategory' => __('Category', 'lavka-reports'),
            'csvTreatment' => __('Accounting treatment', 'lavka-reports'),
            'csvIncluded' => __('Included in profit', 'lavka-reports'),
            'csvReason' => __('Reason', 'lavka-reports'),
        ];
    }
}
