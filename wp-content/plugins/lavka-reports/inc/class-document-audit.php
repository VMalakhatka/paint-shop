<?php
if (!defined('ABSPATH')) exit;

/** Read-only document quality audit. Classification belongs to Java. */
class Lavka_Reports_Document_Audit {
    const PAGE_SLUG = 'lavka-document-audit';
    const AJAX_ACTION = 'lavr_document_audit';
    const NONCE_ACTION = 'lavr_document_audit_nonce';
    public function __construct() {
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_enqueue_scripts', [$this, 'assets']);
        add_action('wp_ajax_' . self::AJAX_ACTION, [$this, 'ajax_report']);
    }
    public function menu() {
        add_submenu_page(function_exists('paint_core_lavka_admin_parent_slug') ? paint_core_lavka_admin_parent_slug() : Lavka_Reports_Admin::PAGE_SLUG,
            __('Folio document audit', 'lavka-reports'), __('Folio document audit', 'lavka-reports'), 'manage_woocommerce', self::PAGE_SLUG, [$this, 'render_page']);
    }
    public function assets($hook) {
        if (self::PAGE_SLUG !== sanitize_key(wp_unslash($_GET['page'] ?? ''))) return;
        wp_enqueue_style('lavr-document-audit', LAVR_URL . 'document-audit.css', [], LAVR_VER);
        wp_enqueue_script('lavr-profit-xlsx', LAVR_URL . 'profit-xlsx.js', [], LAVR_VER, true);
        wp_enqueue_script('lavr-document-audit', LAVR_URL . 'document-audit.js', ['lavr-profit-xlsx'], LAVR_VER, true);
        wp_localize_script('lavr-document-audit', 'LavkaDocumentAudit', ['ajaxUrl'=>admin_url('admin-ajax.php'), 'action'=>self::AJAX_ACTION, 'nonce'=>wp_create_nonce(self::NONCE_ACTION), 'i18n'=>$this->translations()]);
    }
    public function render_page() {
        if (!current_user_can('manage_woocommerce')) wp_die(esc_html__('You do not have permission to view this report.', 'lavka-reports'));
        $t = $this->translations();
        ?>
        <div class="wrap lavr-document-audit" id="lavr-document-audit">
            <h1><?php echo esc_html($t['title']); ?></h1>
            <p><?php echo esc_html($t['intro']); ?></p>
            <form id="lda-form" class="lda-toolbar">
                <label><?php echo esc_html($t['from']); ?><input type="date" id="lda-from" required></label>
                <label><?php echo esc_html($t['to']); ?><input type="date" id="lda-to" required></label>
                <button class="button button-primary" type="submit" id="lda-run"><?php echo esc_html($t['run']); ?></button>
                <button class="button" type="button" id="lda-stop" disabled><?php echo esc_html($t['stop']); ?></button>
                <button class="button" type="button" id="lda-export" disabled><?php echo esc_html($t['export']); ?></button>
            </form>
            <p id="lda-state" role="status" aria-live="polite"></p>
            <p id="lda-error" role="alert" hidden></p>
            <div id="lda-result" hidden>
                <section><h2><?php echo esc_html($t['coverage']); ?></h2><div id="lda-counts" class="lda-counts"></div>
                    <p><?php echo esc_html($t['validHelp']); ?></p><p class="lda-warning"><?php echo esc_html($t['live']); ?></p>
                    <div id="lda-coverage"></div></section>
                <section><h2><?php echo esc_html($t['registry']); ?></h2>
                    <div class="lda-toolbar"><label><?php echo esc_html($t['status']); ?><select id="lda-status"><option value=""><?php echo esc_html($t['all']); ?></option><?php foreach (['ERROR','RULE_REVIEW','VALID'] as $s): ?><option value="<?php echo esc_attr($s); ?>"><?php echo esc_html($t[$s]); ?></option><?php endforeach; ?></select></label>
                    <label><?php echo esc_html($t['category']); ?><select id="lda-category"></select></label>
                    <label><?php echo esc_html($t['search']); ?><input type="search" id="lda-search"></label></div>
                    <p><?php echo esc_html($t['exportHelp']); ?></p>
                    <div id="lda-registry" class="lda-scroll" tabindex="0" role="region" aria-label="<?php echo esc_attr($t['registry']); ?>"></div>
                    <div class="lda-toolbar"><button class="button" id="lda-prev" type="button"><?php echo esc_html($t['prev']); ?></button><span id="lda-page"></span><button class="button" id="lda-next" type="button"><?php echo esc_html($t['next']); ?></button></div>
                </section>
                <section><details><summary><?php echo esc_html($t['rules']); ?></summary><div id="lda-rules"></div></details></section>
            </div>
        </div>
        <?php
    }
    public function ajax_report() {
        if (!current_user_can('manage_woocommerce')) wp_send_json_error(['message'=>__('You do not have permission to view this report.', 'lavka-reports')], 403);
        if (false === check_ajax_referer(self::NONCE_ACTION, 'nonce', false)) wp_send_json_error(['code'=>'REPORT_SESSION_EXPIRED', 'message'=>__('Your audit session has expired. Reload the page and select the dates again.', 'lavka-reports')], 403);
        $query = [];
        foreach (['dateFrom','dateTo'] as $key) {
            $value = $_POST[$key] ?? '';
            if (!is_string($value) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) || !checkdate((int)$m[2], (int)$m[3], (int)$m[1])) $this->invalid_dates();
            $query[$key] = $value;
        }
        $from = new DateTimeImmutable($query['dateFrom']); $to = new DateTimeImmutable($query['dateTo']);
        if ($to < $from || $from->diff($to)->days > 365) $this->invalid_dates();
        $query['pageSize'] = 200;
        foreach (['afterPaymentId','upperPaymentId'] as $key) {
            if (!isset($_POST[$key]) || $_POST[$key] === '') continue;
            $value = $_POST[$key];
            if (!is_string($value) || !preg_match('/^\d{1,15}$/', $value)) wp_send_json_error(['message'=>__('Invalid or inconsistent audit response. Reload the audit.', 'lavka-reports')], 400);
            $query[$key] = $value;
        }
        if (isset($_POST['expectedRulesVersion'])) {
            $v = $_POST['expectedRulesVersion'];
            if (!is_string($v) || !preg_match('/^[a-zA-Z0-9._-]{1,80}$/', $v)) wp_send_json_error(['message'=>__('Invalid or inconsistent audit response. Reload the audit.', 'lavka-reports')], 400);
            $query['expectedRulesVersion'] = $v;
        }
        $options = function_exists('lavka_sync_get_options') ? lavka_sync_get_options() : get_option('lavka_sync_options', []);
        $base = rtrim((string)($options['java_base_url'] ?? ''), '/');
        if ($base === '') wp_send_json_error(['message'=>__('Java service URL is not configured.', 'lavka-reports')], 500);
        $response = wp_remote_get(add_query_arg($query, $base . '/admin/folio/document-audit'), ['timeout'=>160,'headers'=>array_filter(['Accept'=>'application/json','X-Auth-Token'=>$options['api_token'] ?? null])]);
        if (is_wp_error($response)) wp_send_json_error(['message'=>$response->get_error_message()], 502);
        wp_send_json_success(['httpStatus'=>(int)wp_remote_retrieve_response_code($response), 'bodyRaw'=>(string)wp_remote_retrieve_body($response)]);
    }
    private function invalid_dates() {
        wp_send_json_error(['message'=>__('Select valid dates, in order, covering no more than 366 days.', 'lavka-reports')], 400);
    }
    private function translations() {
        return [
            'periodEvidence' => __('Period evidence', 'lavka-reports'),
            'disabled' => __('Document audit is disabled in Java. The administrator must restrict API access before enabling LAVKA_FOLIO_DOCUMENT_AUDIT_ENABLED.', 'lavka-reports'),
            'sourceRows' => __('Source rows', 'lavka-reports'),
            'sourceSheet' => __('Source sheet', 'lavka-reports'),
            'label' => __('Name', 'lavka-reports'),
            'categoryCode' => __('Category code', 'lavka-reports'),
            'amountHelp' => __('Amounts are shown as SUM_POR. COD_VALUT alone does not confirm their currency. No currency conversion or total is calculated.', 'lavka-reports'),
            'amountCurrencyStatus' => __('Amount unit verification', 'lavka-reports'),
            'organizationName' => __('Organization name', 'lavka-reports'),
            'UNKNOWN' => __('Unknown', 'lavka-reports'),
            'OUTGOING' => __('Outgoing', 'lavka-reports'),
            'INCOMING' => __('Incoming', 'lavka-reports'),
            'title' => __('Folio document audit', 'lavka-reports'),
            'intro' => __('Check cash and bank documents by document date, across all warehouses and both directions. This report does not change documents or profit calculations.', 'lavka-reports'),
            'from' => __('Date from', 'lavka-reports'),
            'to' => __('Date to', 'lavka-reports'),
            'run' => __('Check documents', 'lavka-reports'),
            'stop' => __('Stop loading', 'lavka-reports'),
            'export' => __('Export loaded registry to Excel', 'lavka-reports'),
            'loading' => __('Loading documents…', 'lavka-reports'),
            'ready' => __('Loading finished. Review findings and coverage below.', 'lavka-reports'),
            'partial' => __('Incomplete loading. Received documents are preserved; this is not a full audit.', 'lavka-reports'),
            'stale' => __('Dates changed. Run the audit again before exporting.', 'lavka-reports'),
            'error' => __('Could not load the audit. Check Java availability and retry.', 'lavka-reports'),
            'invalid' => __('Invalid or inconsistent audit response. Reload the audit.', 'lavka-reports'),
            'dates' => __('Select valid dates, in order, covering no more than 366 days.', 'lavka-reports'),
            'coverage' => __('Audit coverage', 'lavka-reports'),
            'registry' => __('Document registry', 'lavka-reports'),
            'rules' => __('Rules and their sources', 'lavka-reports'),
            'all' => __('All', 'lavka-reports'),
            'VALID' => __('Checks passed', 'lavka-reports'),
            'ERROR' => __('Document error', 'lavka-reports'),
            'RULE_REVIEW' => __('Rule review needed', 'lavka-reports'),
            'validHelp' => __('Checks passed means only the implemented checks passed. Unchecked areas remain listed below.', 'lavka-reports'),
            'live' => __('Live reading, not a frozen snapshot: documents may change while pages are loading.', 'lavka-reports'),
            'loaded' => __('Loaded documents', 'lavka-reports'),
            'total' => __('Source document count', 'lavka-reports'),
            'status' => __('Status', 'lavka-reports'),
            'category' => __('Category', 'lavka-reports'),
            'date' => __('Document date', 'lavka-reports'),
            'number' => __('Document number', 'lavka-reports'),
            'id' => __('Document ID', 'lavka-reports'),
            'register' => __('Register', 'lavka-reports'),
            'direction' => __('Direction', 'lavka-reports'),
            'warehouse' => __('Warehouse', 'lavka-reports'),
            'amount' => __('Amount', 'lavka-reports'),
            'currency' => __('Currency', 'lavka-reports'),
            'org' => __('Organization short code', 'lavka-reports'),
            'purpose' => __('Purpose code', 'lavka-reports'),
            'operation' => __('Operation type', 'lavka-reports'),
            'sourceInfo' => __('Information source', 'lavka-reports'),
            'note' => __('Note / period evidence', 'lavka-reports'),
            'period' => __('Resolved period', 'lavka-reports'),
            'findings' => __('Findings', 'lavka-reports'),
            'actual' => __('Actual', 'lavka-reports'),
            'expected' => __('Expected', 'lavka-reports'),
            'recommendation' => __('Recommended action', 'lavka-reports'),
            'field' => __('Field', 'lavka-reports'),
            'code' => __('Code', 'lavka-reports'),
            'ruleIds' => __('Rule IDs', 'lavka-reports'),
            'search' => __('Search loaded documents', 'lavka-reports'),
            'empty' => __('No documents match these filters.', 'lavka-reports'),
            'prev' => __('Previous page', 'lavka-reports'),
            'next' => __('Next page', 'lavka-reports'),
            'shown' => __('Shown / filtered', 'lavka-reports'),
            'version' => __('Rules version', 'lavka-reports'),
            'calculated' => __('Calculated at', 'lavka-reports'),
            'unsupported' => __('Not checked', 'lavka-reports'),
            'warnings' => __('Coverage warnings', 'lavka-reports'),
            'cash' => __('Cash', 'lavka-reports'),
            'bank' => __('Bank', 'lavka-reports'),
            'IN' => __('Incoming', 'lavka-reports'),
            'OUT' => __('Outgoing', 'lavka-reports'),
            'unknown' => __('Unknown', 'lavka-reports'),
            'masked' => __('Sensitive values masked', 'lavka-reports'),
            'sheet' => __('Source sheet / rows', 'lavka-reports'),
            'limitations' => __('Rule limitations', 'lavka-reports'),
            'manifest' => __('Audit details', 'lavka-reports'),
            'exportHelp' => __('Excel contains every loaded document, regardless of the display filters, plus findings, rules and coverage.', 'lavka-reports'),
            'complete' => __('All source pages loaded', 'lavka-reports'),
            'yes' => __('Yes', 'lavka-reports'),
            'no' => __('No', 'lavka-reports'),
            'source' => __('Source', 'lavka-reports'),
            'dateBasis' => __('Date selection basis', 'lavka-reports'),
            'consistency' => __('Read consistency', 'lavka-reports'),
            'allWarehouses' => __('All warehouses', 'lavka-reports'),
            'directions' => __('Directions', 'lavka-reports'),
            'registers' => __('Registers', 'lavka-reports'),
            'rulesComplete' => __('Rules coverage complete', 'lavka-reports'),
            'pageComplete' => __('Last source page complete', 'lavka-reports'),
            'evidence' => __('Rule evidence', 'lavka-reports'),
            'periodRequirement' => __('Period requirement', 'lavka-reports'),
            'requiredSourceInfo' => __('Required information source', 'lavka-reports'),
            'expectedOperationTypes' => __('Expected operation types', 'lavka-reports'),
            'organizationCodes' => __('Organization codes', 'lavka-reports'),
            'profitTreatment' => __('Profit treatment', 'lavka-reports'),
            'recognition' => __('Recognition', 'lavka-reports'),
        ];
    }
}
