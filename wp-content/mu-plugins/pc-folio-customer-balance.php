<?php
/**
 * Plugin Name: PC Folio Customer Balance
 * Description: Shows the signed-in wholesale customer's Folio balance report in My Account.
 * Author: Volodymyr
 * Version: 0.5.0
 * Text Domain: pc-folio-customer-balance
 */

defined('ABSPATH') || exit;

const PC_FOLIO_BALANCE_ENDPOINT = 'folio-balance';
const PC_FOLIO_BALANCE_VERSION  = '0.5.0';
const PC_FOLIO_BALANCE_ADMIN_PAGE = 'pc-folio-customer-balance';

function pc_folio_balance_user_context(int $user_id = 0, bool $require_customer_role = true): array {
    $user_id = $user_id > 0 ? $user_id : get_current_user_id();
    $user = $user_id > 0 ? get_userdata($user_id) : false;

    if (!$user instanceof WP_User) {
        return [];
    }

    $allowed_roles = ['opt', 'partner'];
    if ($require_customer_role && !array_intersect($allowed_roles, (array) $user->roles)) {
        return [];
    }

    $short_name = trim((string) get_user_meta($user_id, '_folio_partner_short_name', true));
    if ($short_name === '') {
        return [];
    }

    return [
        'user_id'    => $user_id,
        'short_name' => $short_name,
        'name'       => trim((string) get_user_meta($user_id, '_folio_partner_name', true)),
        'type'       => trim((string) get_user_meta($user_id, '_folio_partner_type', true)),
    ];
}

function pc_folio_balance_can_manage(): bool {
    return current_user_can('edit_users') || current_user_can('manage_woocommerce');
}

function pc_folio_balance_admin_url(int $user_id): string {
    return add_query_arg([
        'page'    => PC_FOLIO_BALANCE_ADMIN_PAGE,
        'user_id' => $user_id,
    ], admin_url('admin.php'));
}

function pc_folio_balance_admin_context(int $user_id): array {
    if ($user_id <= 0 || !pc_folio_balance_can_manage()) {
        return [];
    }
    return pc_folio_balance_user_context($user_id, false);
}

function pc_folio_balance_request_context(): array {
    $raw_user_id = $_POST['user_id'] ?? $_GET['user_id'] ?? 0;
    $target_user_id = max(0, (int) $raw_user_id);
    return $target_user_id > 0
        ? pc_folio_balance_admin_context($target_user_id)
        : pc_folio_balance_user_context();
}

function pc_folio_balance_register_endpoint(): void {
    add_rewrite_endpoint(PC_FOLIO_BALANCE_ENDPOINT, EP_ROOT | EP_PAGES);
}
add_action('init', 'pc_folio_balance_register_endpoint');

function pc_folio_balance_query_vars(array $query_vars): array {
    $query_vars[PC_FOLIO_BALANCE_ENDPOINT] = PC_FOLIO_BALANCE_ENDPOINT;
    return $query_vars;
}
add_filter('woocommerce_get_query_vars', 'pc_folio_balance_query_vars');

function pc_folio_balance_flush_rewrite_once(): void {
    if ((string) get_option('pc_folio_balance_rewrite_version', '') === PC_FOLIO_BALANCE_VERSION) {
        return;
    }

    pc_folio_balance_register_endpoint();
    flush_rewrite_rules(false);
    update_option('pc_folio_balance_rewrite_version', PC_FOLIO_BALANCE_VERSION, false);
}
add_action('wp_loaded', 'pc_folio_balance_flush_rewrite_once');

function pc_folio_balance_account_menu(array $items): array {
    if (!pc_folio_balance_user_context()) {
        return $items;
    }

    $logout = $items['customer-logout'] ?? null;
    unset($items['customer-logout']);
    $items[PC_FOLIO_BALANCE_ENDPOINT] = __('Balance with customer', 'pc-folio-customer-balance');

    if ($logout !== null) {
        $items['customer-logout'] = $logout;
    }

    return $items;
}
add_filter('woocommerce_account_menu_items', 'pc_folio_balance_account_menu', 80);

function pc_folio_balance_is_endpoint(): bool {
    return function_exists('is_wc_endpoint_url') && is_wc_endpoint_url(PC_FOLIO_BALANCE_ENDPOINT);
}

function pc_folio_balance_enqueue_assets_for_context(array $context, int $target_user_id = 0): void {
    $base_url = plugin_dir_url(__FILE__) . 'pc-folio-customer-balance/assets/';
    wp_enqueue_style(
        'pc-folio-customer-balance',
        $base_url . 'customer-balance.css',
        [],
        PC_FOLIO_BALANCE_VERSION
    );
    wp_enqueue_script(
        'pc-folio-customer-balance',
        $base_url . 'customer-balance.js',
        [],
        PC_FOLIO_BALANCE_VERSION,
        true
    );
    wp_localize_script('pc-folio-customer-balance', 'pcFolioBalance', [
        'ajaxUrl'     => admin_url('admin-ajax.php'),
        'nonce'       => wp_create_nonce('pc_folio_customer_balance'),
        'exportUrl'   => admin_url('admin-post.php'),
        'exportNonce' => wp_create_nonce('pc_folio_customer_balance_export'),
        'userId'      => $target_user_id,
        'labels'  => [
            'loading'       => __('The report is being generated...', 'pc-folio-customer-balance'),
            'ready'         => __('Select a period start date or generate the report for all time.', 'pc-folio-customer-balance'),
            'requestFailed' => __('The report could not be generated. Please try again later.', 'pc-folio-customer-balance'),
            'empty'         => __('No operations were found for the selected period.', 'pc-folio-customer-balance'),
            'asOf'          => __('The report was generated from active Folio documents as of %s.', 'pc-folio-customer-balance'),
            'warehouse'     => __('Warehouse', 'pc-folio-customer-balance'),
            'documentId'    => __('Folio document ID', 'pc-folio-customer-balance'),
            'requestId'     => __('Request ID: %s', 'pc-folio-customer-balance'),
            'period'        => __('Period: %1$s - %2$s', 'pc-folio-customer-balance'),
            'allTimePeriod' => __('Period: all time', 'pc-folio-customer-balance'),
            'warehouses'    => __('Warehouses: %s', 'pc-folio-customer-balance'),
            'allWarehouses' => __('All warehouses', 'pc-folio-customer-balance'),
            'asOfShort'     => __('As of: %s', 'pc-folio-customer-balance'),
            'operation'     => [
                'opening'     => __('Opening', 'pc-folio-customer-balance'),
                'expense'     => __('Expense', 'pc-folio-customer-balance'),
                'receipt'     => __('Receipt', 'pc-folio-customer-balance'),
                'bankPayment' => __('Bank payment short', 'pc-folio-customer-balance'),
                'cashPayment' => __('Cash payment short', 'pc-folio-customer-balance'),
                'bankCash'    => __('Bank and cash payment', 'pc-folio-customer-balance'),
                'folioCode'   => __('Folio code: %s', 'pc-folio-customer-balance'),
            ],
            'summary'       => [
                'openingBalance'        => __('Opening balance', 'pc-folio-customer-balance'),
                'expenseTotal'          => __('Expense invoice total', 'pc-folio-customer-balance'),
                'receiptTotal'          => __('Receipt invoice total', 'pc-folio-customer-balance'),
                'bankPaymentTotal'      => __('Bank payments', 'pc-folio-customer-balance'),
                'cashPaymentTotal'      => __('Cash payments', 'pc-folio-customer-balance'),
                'commonDebt'            => __('Total debt', 'pc-folio-customer-balance'),
                'deferredAmount'        => __('Deferred / on sale', 'pc-folio-customer-balance'),
                'overdueDeferredAmount' => __('Overdue deferred / on sale', 'pc-folio-customer-balance'),
                'prepaymentAmount'      => __('Prepayment', 'pc-folio-customer-balance'),
                'payableNow'            => __('Payable now', 'pc-folio-customer-balance'),
            ],
            'currency'      => __('UAH', 'pc-folio-customer-balance'),
        ],
    ]);
}

function pc_folio_balance_enqueue_assets(): void {
    $context = pc_folio_balance_user_context();
    if (!pc_folio_balance_is_endpoint() || !$context) {
        return;
    }
    pc_folio_balance_enqueue_assets_for_context($context);
}
add_action('wp_enqueue_scripts', 'pc_folio_balance_enqueue_assets');

function pc_folio_balance_render_report(array $context, bool $admin = false): void {
    ?>
    <section class="pc-folio-balance<?php echo $admin ? ' pc-folio-balance--admin' : ''; ?>" aria-labelledby="pc-folio-balance-title">
        <div class="pc-folio-balance__header">
            <div>
                <h2 id="pc-folio-balance-title"><?php esc_html_e('Balance with customer', 'pc-folio-customer-balance'); ?></h2>
                <p class="pc-folio-balance__partner">
                    <strong><?php echo esc_html($context['name'] !== '' ? $context['name'] : $context['short_name']); ?></strong>
                    <span><?php echo esc_html($context['short_name']); ?></span>
                </p>
            </div>
            <div class="pc-folio-balance__header-actions">
                <button type="button" class="button" data-pc-folio-export disabled>
                    <?php esc_html_e('Export XLSX', 'pc-folio-customer-balance'); ?>
                </button>
                <button type="button" class="button pc-folio-balance__print" data-pc-folio-print disabled>
                    <?php esc_html_e('Print', 'pc-folio-customer-balance'); ?>
                </button>
            </div>
        </div>

        <form class="pc-folio-balance__filters" data-pc-folio-form>
            <label>
                <span><?php esc_html_e('Period start', 'pc-folio-customer-balance'); ?></span>
                <input type="date" name="date_from" max="<?php echo esc_attr(wp_date('Y-m-d')); ?>">
            </label>
            <div class="pc-folio-balance__filter-actions">
                <button type="button" class="button" data-pc-folio-all><?php esc_html_e('All time', 'pc-folio-customer-balance'); ?></button>
                <button type="submit" class="button alt"><?php esc_html_e('Generate report', 'pc-folio-customer-balance'); ?></button>
            </div>
        </form>

        <div class="pc-folio-balance__status" data-pc-folio-status role="status" aria-live="polite"></div>
        <div class="pc-folio-balance__report-meta" data-pc-folio-report-meta hidden></div>
        <div class="pc-folio-balance__summary" data-pc-folio-summary hidden></div>
        <div class="pc-folio-balance__notice" data-pc-folio-notice hidden></div>

        <div class="pc-folio-balance__table-wrap" data-pc-folio-table-wrap hidden>
            <table class="pc-folio-balance__table">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Due date', 'pc-folio-customer-balance'); ?></th>
                        <th><?php esc_html_e('No.', 'pc-folio-customer-balance'); ?></th>
                        <th><?php esc_html_e('Operation', 'pc-folio-customer-balance'); ?></th>
                        <th><?php esc_html_e('Document No.', 'pc-folio-customer-balance'); ?></th>
                        <th><?php esc_html_e('Date', 'pc-folio-customer-balance'); ?></th>
                        <th><?php esc_html_e('Basis', 'pc-folio-customer-balance'); ?></th>
                        <th><?php esc_html_e('Opening debt', 'pc-folio-customer-balance'); ?></th>
                        <th><?php esc_html_e('Expense invoices', 'pc-folio-customer-balance'); ?></th>
                        <th><?php esc_html_e('Receipt invoices', 'pc-folio-customer-balance'); ?></th>
                        <th><?php esc_html_e('Bank payment', 'pc-folio-customer-balance'); ?></th>
                        <th><?php esc_html_e('Cash payment', 'pc-folio-customer-balance'); ?></th>
                        <th><?php esc_html_e('Closing debt', 'pc-folio-customer-balance'); ?></th>
                        <th><?php esc_html_e('Note', 'pc-folio-customer-balance'); ?></th>
                        <th><?php esc_html_e('Invoice date', 'pc-folio-customer-balance'); ?></th>
                    </tr>
                </thead>
                <tbody data-pc-folio-rows></tbody>
            </table>
        </div>
    </section>
    <?php
}

function pc_folio_balance_endpoint_content(): void {
    $context = pc_folio_balance_user_context();
    if (!$context) {
        echo '<p class="woocommerce-error">' . esc_html__('This report is not available for your account.', 'pc-folio-customer-balance') . '</p>';
        return;
    }
    pc_folio_balance_render_report($context);
}
add_action('woocommerce_account_' . PC_FOLIO_BALANCE_ENDPOINT . '_endpoint', 'pc_folio_balance_endpoint_content');

function pc_folio_balance_register_admin_page(): void {
    add_submenu_page(
        null,
        __('Customer balance', 'pc-folio-customer-balance'),
        __('Customer balance', 'pc-folio-customer-balance'),
        'read',
        PC_FOLIO_BALANCE_ADMIN_PAGE,
        'pc_folio_balance_admin_page'
    );
}
add_action('admin_menu', 'pc_folio_balance_register_admin_page');

function pc_folio_balance_admin_page(): void {
    $user_id = isset($_GET['user_id']) ? max(0, (int) $_GET['user_id']) : 0;
    $context = pc_folio_balance_admin_context($user_id);
    if (!$context) {
        wp_die(esc_html__('This report is not available for the selected user.', 'pc-folio-customer-balance'), '', ['response' => 403]);
    }
    ?>
    <div class="wrap">
        <p>
            <a href="<?php echo esc_url(get_edit_user_link($user_id)); ?>">&larr; <?php esc_html_e('Back to user profile', 'pc-folio-customer-balance'); ?></a>
        </p>
        <?php pc_folio_balance_render_report($context, true); ?>
    </div>
    <?php
}

function pc_folio_balance_admin_assets(string $hook_suffix): void {
    unset($hook_suffix);
    $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
    if ($page !== PC_FOLIO_BALANCE_ADMIN_PAGE) {
        return;
    }
    $user_id = isset($_GET['user_id']) ? max(0, (int) $_GET['user_id']) : 0;
    $context = pc_folio_balance_admin_context($user_id);
    if ($context) {
        pc_folio_balance_enqueue_assets_for_context($context, $user_id);
    }
}
add_action('admin_enqueue_scripts', 'pc_folio_balance_admin_assets');

function pc_folio_balance_user_row_action(array $actions, WP_User $user): array {
    if (!pc_folio_balance_can_manage() || !current_user_can('edit_user', $user->ID)) {
        return $actions;
    }
    if (!pc_folio_balance_user_context($user->ID, false)) {
        return $actions;
    }

    $actions['pc_folio_balance'] = sprintf(
        '<a href="%1$s" target="_blank" rel="noopener">%2$s</a>',
        esc_url(pc_folio_balance_admin_url($user->ID)),
        esc_html__('Folio balance', 'pc-folio-customer-balance')
    );
    return $actions;
}
add_filter('user_row_actions', 'pc_folio_balance_user_row_action', 20, 2);
add_filter('ms_user_row_actions', 'pc_folio_balance_user_row_action', 20, 2);

function pc_folio_balance_valid_date(string $value): bool {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        return false;
    }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date instanceof DateTimeImmutable && $date->format('Y-m-d') === $value;
}

function pc_folio_balance_fetch_report(array $context, string $date_from = '') {
    if (!function_exists('lps_java_get')) {
        return new WP_Error('folio_connection_unavailable', __('The Folio service connection is unavailable.', 'pc-folio-customer-balance'), ['status' => 503]);
    }

    $query = [
        'partnerShortName'       => $context['short_name'],
        'includeServicePayments' => 'true',
    ];
    if ($date_from !== '') {
        $query['dateFrom'] = $date_from;
    }

    $response = lps_java_get(add_query_arg($query, '/admin/folio/customer-balance'), ['timeout' => 160]);
    if (is_wp_error($response)) {
        return new WP_Error('folio_unavailable', __('The Folio service is temporarily unavailable.', 'pc-folio-customer-balance'), ['status' => 503]);
    }

    $code = (int) wp_remote_retrieve_response_code($response);
    $data = json_decode((string) wp_remote_retrieve_body($response), true);
    if ($code < 200 || $code >= 300) {
        $messages = [
            400 => __('The report parameters were rejected by Folio.', 'pc-folio-customer-balance'),
            404 => __('The linked customer was not found in Folio.', 'pc-folio-customer-balance'),
            503 => __('The Folio service is temporarily unavailable.', 'pc-folio-customer-balance'),
        ];
        $request_id = is_array($data) ? sanitize_text_field((string) ($data['reqId'] ?? $data['requestId'] ?? '')) : '';
        return new WP_Error('folio_http_error', $messages[$code] ?? __('The report could not be generated. Please try again later.', 'pc-folio-customer-balance'), [
            'status' => $code >= 400 && $code < 600 ? $code : 502,
            'reqId'  => $request_id,
        ]);
    }

    if (!is_array($data) || !isset($data['summary'], $data['rows']) || !is_array($data['summary']) || !is_array($data['rows'])) {
        return new WP_Error('invalid_folio_report', __('Folio returned an invalid report response.', 'pc-folio-customer-balance'), ['status' => 502]);
    }
    if (isset($data['ok']) && $data['ok'] !== true) {
        return new WP_Error('folio_report_failed', __('The report could not be generated. Please try again later.', 'pc-folio-customer-balance'), ['status' => 502]);
    }
    $response_short_name = trim((string) ($data['partner']['shortName'] ?? ''));
    if ($response_short_name === '' || $response_short_name !== $context['short_name']) {
        return new WP_Error('folio_customer_mismatch', __('Folio returned a report for a different customer.', 'pc-folio-customer-balance'), ['status' => 502]);
    }

    return $data;
}

function pc_folio_balance_ajax(): void {
    check_ajax_referer('pc_folio_customer_balance');

    $context = pc_folio_balance_request_context();
    if (!$context) {
        wp_send_json_error(['message' => __('This report is not available for your account.', 'pc-folio-customer-balance')], 403);
    }

    $short_name_length = function_exists('mb_strlen')
        ? mb_strlen($context['short_name'], 'UTF-8')
        : strlen($context['short_name']);
    if ($short_name_length > 8) {
        wp_send_json_error(['message' => __('The Folio customer mapping is invalid. Please contact the manager.', 'pc-folio-customer-balance')], 422);
    }

    $date_from = isset($_POST['date_from']) ? sanitize_text_field(wp_unslash($_POST['date_from'])) : '';
    if ($date_from !== '' && (!pc_folio_balance_valid_date($date_from) || $date_from > wp_date('Y-m-d'))) {
        wp_send_json_error(['message' => __('Enter a valid period start date.', 'pc-folio-customer-balance')], 400);
    }

    $data = pc_folio_balance_fetch_report($context, $date_from);
    if (is_wp_error($data)) {
        $error_data = $data->get_error_data();
        wp_send_json_error([
            'message' => $data->get_error_message(),
            'reqId'   => is_array($error_data) ? (string) ($error_data['reqId'] ?? '') : '',
        ], is_array($error_data) ? (int) ($error_data['status'] ?? 502) : 502);
    }

    wp_send_json_success(['report' => $data]);
}
add_action('wp_ajax_pc_folio_customer_balance', 'pc_folio_balance_ajax');

function pc_folio_balance_export_date($value): string {
    if (is_array($value) && count($value) >= 3) {
        return sprintf('%04d-%02d-%02d', (int) $value[0], (int) $value[1], (int) $value[2]);
    }
    return is_scalar($value) ? substr((string) $value, 0, 10) : '';
}

function pc_folio_balance_operation_label(array $row): string {
    if (!empty($row['openingBalanceRow'])) {
        return __('Opening', 'pc-folio-customer-balance');
    }

    $bank = (float) ($row['bankPayment'] ?? 0);
    $cash = (float) ($row['cashPayment'] ?? 0);
    if ($bank != 0.0 && $cash != 0.0) {
        return __('Bank and cash payment', 'pc-folio-customer-balance');
    }
    if ($bank != 0.0) {
        return __('Bank payment short', 'pc-folio-customer-balance');
    }
    if ($cash != 0.0) {
        return __('Cash payment short', 'pc-folio-customer-balance');
    }
    if ((float) ($row['expenseAmount'] ?? 0) != 0.0) {
        return __('Expense', 'pc-folio-customer-balance');
    }
    if ((float) ($row['receiptAmount'] ?? 0) != 0.0) {
        return __('Receipt', 'pc-folio-customer-balance');
    }

    return trim((string) ($row['documentType'] ?? ''));
}

function pc_folio_balance_export_xlsx(): void {
    if (!is_user_logged_in()) {
        auth_redirect();
    }
    check_admin_referer('pc_folio_customer_balance_export');

    $context = pc_folio_balance_request_context();
    if (!$context) {
        wp_die(esc_html__('This report is not available for your account.', 'pc-folio-customer-balance'), '', ['response' => 403]);
    }

    $date_from = isset($_GET['date_from']) ? sanitize_text_field(wp_unslash($_GET['date_from'])) : '';
    if ($date_from !== '' && (!pc_folio_balance_valid_date($date_from) || $date_from > wp_date('Y-m-d'))) {
        wp_die(esc_html__('Enter a valid period start date.', 'pc-folio-customer-balance'), '', ['response' => 400]);
    }

    if (!class_exists(\PhpOffice\PhpSpreadsheet\Spreadsheet::class)) {
        wp_die(esc_html__('XLSX export is temporarily unavailable.', 'pc-folio-customer-balance'), '', ['response' => 503]);
    }

    $report = pc_folio_balance_fetch_report($context, $date_from);
    if (is_wp_error($report)) {
        wp_die(esc_html($report->get_error_message()), '', ['response' => 502]);
    }

    $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle(__('Balance', 'pc-folio-customer-balance'));

    $partner_name = trim((string) ($report['partner']['name'] ?? $context['name']));
    $partner_short_name = trim((string) ($report['partner']['shortName'] ?? $context['short_name']));
    $filters = is_array($report['filters'] ?? null) ? $report['filters'] : [];
    $date_to = pc_folio_balance_export_date($filters['dateTo'] ?? $filters['asOfDate'] ?? '');
    $filter_from = pc_folio_balance_export_date($filters['dateFrom'] ?? '');
    $all_time = $filter_from === '' || $filter_from === '1753-01-01';

    $sheet->setCellValue('A1', __('Balance with customer', 'pc-folio-customer-balance'));
    $sheet->setCellValueExplicit('A2', $partner_name, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
    $sheet->setCellValueExplicit('A3', $partner_short_name, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
    $sheet->setCellValue('A4', $all_time
        ? __('Period: all time', 'pc-folio-customer-balance')
        : sprintf(__('Period: %1$s - %2$s', 'pc-folio-customer-balance'), $filter_from, $date_to));

    $summary_labels = [
        'openingBalance'        => __('Opening balance', 'pc-folio-customer-balance'),
        'expenseTotal'          => __('Expense invoice total', 'pc-folio-customer-balance'),
        'receiptTotal'          => __('Receipt invoice total', 'pc-folio-customer-balance'),
        'bankPaymentTotal'      => __('Bank payments', 'pc-folio-customer-balance'),
        'cashPaymentTotal'      => __('Cash payments', 'pc-folio-customer-balance'),
        'commonDebt'            => __('Total debt', 'pc-folio-customer-balance'),
        'deferredAmount'        => __('Deferred / on sale', 'pc-folio-customer-balance'),
        'overdueDeferredAmount' => __('Overdue deferred / on sale', 'pc-folio-customer-balance'),
        'prepaymentAmount'      => __('Prepayment', 'pc-folio-customer-balance'),
        'payableNow'            => __('Payable now', 'pc-folio-customer-balance'),
    ];
    $summary = is_array($report['summary'] ?? null) ? $report['summary'] : [];
    $column = 1;
    foreach ($summary_labels as $key => $label) {
        $column_letter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($column);
        $sheet->setCellValue($column_letter . '6', $label);
        $sheet->setCellValueExplicit(
            $column_letter . '7',
            (float) ($summary[$key] ?? 0),
            \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_NUMERIC
        );
        $column++;
    }

    $headers = [
        __('Due date', 'pc-folio-customer-balance'),
        __('No.', 'pc-folio-customer-balance'),
        __('Operation', 'pc-folio-customer-balance'),
        __('Document No.', 'pc-folio-customer-balance'),
        __('Date', 'pc-folio-customer-balance'),
        __('Basis', 'pc-folio-customer-balance'),
        __('Opening debt', 'pc-folio-customer-balance'),
        __('Expense invoices', 'pc-folio-customer-balance'),
        __('Receipt invoices', 'pc-folio-customer-balance'),
        __('Bank payment', 'pc-folio-customer-balance'),
        __('Cash payment', 'pc-folio-customer-balance'),
        __('Closing debt', 'pc-folio-customer-balance'),
        __('Note', 'pc-folio-customer-balance'),
        __('Invoice date', 'pc-folio-customer-balance'),
    ];
    foreach ($headers as $index => $header) {
        $column_letter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($index + 1);
        $sheet->setCellValue($column_letter . '9', $header);
    }

    $row_number = 10;
    foreach ($report['rows'] as $row) {
        if (!is_array($row)) {
            continue;
        }
        $values = [
            pc_folio_balance_export_date($row['controlDate'] ?? ''),
            (int) ($row['sequence'] ?? 0),
            pc_folio_balance_operation_label($row),
            $row['documentNumber'] ?? '',
            pc_folio_balance_export_date($row['documentDate'] ?? ''),
            $row['basis'] ?? '',
            (float) ($row['balanceBefore'] ?? 0),
            (float) ($row['expenseAmount'] ?? 0),
            (float) ($row['receiptAmount'] ?? 0),
            (float) ($row['bankPayment'] ?? 0),
            (float) ($row['cashPayment'] ?? 0),
            (float) ($row['balanceAfter'] ?? 0),
            $row['note'] ?? '',
            pc_folio_balance_export_date($row['invoiceDate'] ?? ''),
        ];
        foreach ($values as $index => $value) {
            $cell = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($index + 1) . $row_number;
            if (in_array($index, [6, 7, 8, 9, 10, 11], true)) {
                $sheet->setCellValueExplicit($cell, $value, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_NUMERIC);
            } else {
                $sheet->setCellValueExplicit($cell, (string) $value, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            }
        }

        $fill = null;
        if (!empty($row['openingBalanceRow'])) {
            $fill = 'E7E7E7';
        } elseif (!empty($row['overdueDeferred'])) {
            $fill = 'FFC7CE';
        } elseif (!empty($row['deferred'])) {
            $fill = 'FFFFCC';
        } elseif (!empty($row['prepayment'])) {
            $fill = 'D7F0E4';
        }
        if ($fill !== null) {
            $sheet->getStyle('A' . $row_number . ':N' . $row_number)->getFill()
                ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                ->getStartColor()->setRGB($fill);
        }
        $row_number++;
    }

    $last_row = max(10, $row_number - 1);
    $sheet->mergeCells('A1:N1');
    $sheet->mergeCells('A2:N2');
    $sheet->mergeCells('A3:N3');
    $sheet->mergeCells('A4:N4');
    $sheet->getStyle('A1:N1')->getFont()->setBold(true)->setSize(16);
    $sheet->getStyle('A6:J6')->getFont()->setBold(true);
    $summary_colors = ['CCFFFF', 'CCFFFF', 'CCFFFF', 'CCFFFF', 'CCFFFF', 'CCFFFF', 'FFFF99', 'FFC7CE', '339966', 'FFF200'];
    foreach ($summary_colors as $index => $color) {
        $column_letter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($index + 1);
        $sheet->getStyle($column_letter . '6:' . $column_letter . '7')->getFill()
            ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
            ->getStartColor()->setRGB($color);
    }
    $sheet->getStyle('I6:I7')->getFont()->getColor()->setRGB('FFFFFF');
    $sheet->getStyle('J7')->getFont()->setBold(true)->setSize(14);
    $sheet->getStyle('A9:N9')->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
    $sheet->getStyle('A9:N9')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB('4472C4');
    $sheet->getStyle('A7:J7')->getNumberFormat()->setFormatCode('#,##0.00');
    $sheet->getStyle('G10:L' . $last_row)->getNumberFormat()->setFormatCode('#,##0.00');
    $sheet->getStyle('A6:J7')->getAlignment()->setWrapText(true);
    $sheet->getStyle('A9:N' . $last_row)->getAlignment()->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_TOP);
    $sheet->getStyle('A9:N' . $last_row)->getAlignment()->setWrapText(true);
    $sheet->setAutoFilter('A9:N' . $last_row);
    $sheet->freezePane('A10');

    $widths = [13, 7, 8, 16, 13, 28, 15, 16, 16, 15, 15, 15, 32, 13];
    foreach ($widths as $index => $width) {
        $sheet->getColumnDimensionByColumn($index + 1)->setWidth($width);
    }

    $filename = 'folio-balance-' . (int) $context['user_id'] . '-' . wp_date('Ymd-His') . '.xlsx';
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    nocache_headers();
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('X-Content-Type-Options: nosniff');
    (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet))->save('php://output');
    $spreadsheet->disconnectWorksheets();
    exit;
}
add_action('admin_post_pc_folio_customer_balance_export', 'pc_folio_balance_export_xlsx');

require_once __DIR__ . '/pc-folio-customer-balance/inc/customer-debtors.php';
