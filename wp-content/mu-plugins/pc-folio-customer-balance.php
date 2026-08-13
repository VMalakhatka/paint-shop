<?php
/**
 * Plugin Name: PC Folio Customer Balance
 * Description: Shows the signed-in wholesale customer's Folio balance report in My Account.
 * Author: Volodymyr
 * Version: 0.1.0
 * Text Domain: pc-folio-customer-balance
 */

defined('ABSPATH') || exit;

const PC_FOLIO_BALANCE_ENDPOINT = 'folio-balance';
const PC_FOLIO_BALANCE_VERSION  = '0.1.0';

function pc_folio_balance_user_context(int $user_id = 0): array {
    $user_id = $user_id > 0 ? $user_id : get_current_user_id();
    $user = $user_id > 0 ? get_userdata($user_id) : false;

    if (!$user instanceof WP_User) {
        return [];
    }

    $allowed_roles = ['opt', 'partner'];
    if (!array_intersect($allowed_roles, (array) $user->roles)) {
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

function pc_folio_balance_enqueue_assets(): void {
    if (!pc_folio_balance_is_endpoint() || !pc_folio_balance_user_context()) {
        return;
    }

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
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce'   => wp_create_nonce('pc_folio_customer_balance'),
        'labels'  => [
            'loading'       => __('The report is being generated...', 'pc-folio-customer-balance'),
            'ready'         => __('Select a period start date or generate the report for all time.', 'pc-folio-customer-balance'),
            'requestFailed' => __('The report could not be generated. Please try again later.', 'pc-folio-customer-balance'),
            'empty'         => __('No operations were found for the selected period.', 'pc-folio-customer-balance'),
            'asOf'          => __('The report was generated from active Folio documents as of %s.', 'pc-folio-customer-balance'),
            'warehouse'     => __('Warehouse', 'pc-folio-customer-balance'),
            'documentId'    => __('Folio document ID', 'pc-folio-customer-balance'),
            'requestId'     => __('Request ID: %s', 'pc-folio-customer-balance'),
            'invalidRules'  => __('The Folio report contains an invalid deferred-payment classification and cannot be shown as financially correct. Please contact the manager.', 'pc-folio-customer-balance'),
            'period'        => __('Period: %1$s - %2$s', 'pc-folio-customer-balance'),
            'allTimePeriod' => __('Period: all time', 'pc-folio-customer-balance'),
            'warehouses'    => __('Warehouses: %s', 'pc-folio-customer-balance'),
            'allWarehouses' => __('All warehouses', 'pc-folio-customer-balance'),
            'asOfShort'     => __('As of: %s', 'pc-folio-customer-balance'),
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
add_action('wp_enqueue_scripts', 'pc_folio_balance_enqueue_assets');

function pc_folio_balance_endpoint_content(): void {
    $context = pc_folio_balance_user_context();
    if (!$context) {
        echo '<p class="woocommerce-error">' . esc_html__('This report is not available for your account.', 'pc-folio-customer-balance') . '</p>';
        return;
    }
    ?>
    <section class="pc-folio-balance" aria-labelledby="pc-folio-balance-title">
        <div class="pc-folio-balance__header">
            <div>
                <h2 id="pc-folio-balance-title"><?php esc_html_e('Balance with customer', 'pc-folio-customer-balance'); ?></h2>
                <p class="pc-folio-balance__partner">
                    <strong><?php echo esc_html($context['name'] !== '' ? $context['name'] : $context['short_name']); ?></strong>
                    <span><?php echo esc_html($context['short_name']); ?></span>
                </p>
            </div>
            <button type="button" class="button pc-folio-balance__print" data-pc-folio-print disabled>
                <?php esc_html_e('Print', 'pc-folio-customer-balance'); ?>
            </button>
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
                        <th><?php esc_html_e('D', 'pc-folio-customer-balance'); ?></th>
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
add_action('woocommerce_account_' . PC_FOLIO_BALANCE_ENDPOINT . '_endpoint', 'pc_folio_balance_endpoint_content');

function pc_folio_balance_valid_date(string $value): bool {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        return false;
    }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date instanceof DateTimeImmutable && $date->format('Y-m-d') === $value;
}

function pc_folio_balance_ajax(): void {
    check_ajax_referer('pc_folio_customer_balance');

    $context = pc_folio_balance_user_context();
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

    if (!function_exists('lps_java_get')) {
        wp_send_json_error(['message' => __('The Folio service connection is unavailable.', 'pc-folio-customer-balance')], 503);
    }

    $query = [
        'partnerShortName'      => $context['short_name'],
        'includeServicePayments' => 'true',
    ];
    if ($date_from !== '') {
        $query['dateFrom'] = $date_from;
    }

    $response = lps_java_get(add_query_arg($query, '/admin/folio/customer-balance'), ['timeout' => 160]);
    if (is_wp_error($response)) {
        wp_send_json_error(['message' => __('The Folio service is temporarily unavailable.', 'pc-folio-customer-balance')], 503);
    }

    $code = (int) wp_remote_retrieve_response_code($response);
    $body = (string) wp_remote_retrieve_body($response);
    $data = json_decode($body, true);

    if ($code < 200 || $code >= 300) {
        $request_id = '';
        if (is_array($data)) {
            $request_id = sanitize_text_field((string) ($data['reqId'] ?? $data['requestId'] ?? ''));
        }
        $messages = [
            400 => __('The report parameters were rejected by Folio.', 'pc-folio-customer-balance'),
            404 => __('The linked customer was not found in Folio.', 'pc-folio-customer-balance'),
            503 => __('The Folio service is temporarily unavailable.', 'pc-folio-customer-balance'),
        ];
        wp_send_json_error([
            'message' => $messages[$code] ?? __('The report could not be generated. Please try again later.', 'pc-folio-customer-balance'),
            'reqId'   => $request_id,
        ], $code >= 400 && $code < 600 ? $code : 502);
    }

    if (!is_array($data) || !isset($data['summary'], $data['rows']) || !is_array($data['summary']) || !is_array($data['rows'])) {
        wp_send_json_error(['message' => __('Folio returned an invalid report response.', 'pc-folio-customer-balance')], 502);
    }
    if (isset($data['ok']) && $data['ok'] !== true) {
        wp_send_json_error(['message' => __('The report could not be generated. Please try again later.', 'pc-folio-customer-balance')], 502);
    }
    $response_short_name = trim((string) ($data['partner']['shortName'] ?? ''));
    if ($response_short_name === '' || $response_short_name !== $context['short_name']) {
        wp_send_json_error(['message' => __('Folio returned a report for a different customer.', 'pc-folio-customer-balance')], 502);
    }

    wp_send_json_success(['report' => $data]);
}
add_action('wp_ajax_pc_folio_customer_balance', 'pc_folio_balance_ajax');
