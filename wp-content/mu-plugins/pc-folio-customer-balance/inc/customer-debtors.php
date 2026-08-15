<?php

defined('ABSPATH') || exit;

const PC_FOLIO_DEBTORS_PAGE = 'pc-folio-customer-debtors';

function pc_folio_debtors_can_view(): bool {
    return current_user_can('manage_woocommerce');
}

function pc_folio_debtors_register_page(): void {
    add_submenu_page(
        'woocommerce',
        __('Folio debtors', 'pc-folio-customer-balance'),
        __('Folio debtors', 'pc-folio-customer-balance'),
        'manage_woocommerce',
        PC_FOLIO_DEBTORS_PAGE,
        'pc_folio_debtors_render_page'
    );
}
add_action('admin_menu', 'pc_folio_debtors_register_page', 40);

function pc_folio_debtors_render_page(): void {
    if (!pc_folio_debtors_can_view()) {
        wp_die(esc_html__('You do not have permission to view this report.', 'pc-folio-customer-balance'), '', ['response' => 403]);
    }
    ?>
    <div class="wrap pc-folio-debtors" data-pc-folio-debtors>
        <h1><?php esc_html_e('Folio debtors', 'pc-folio-customer-balance'); ?></h1>
        <p class="description">
            <?php esc_html_e('The report shows Folio customers whose payable amount is strictly greater than the selected threshold.', 'pc-folio-customer-balance'); ?>
        </p>

        <section class="pc-folio-debtors__snapshot" data-pc-debtors-snapshot aria-labelledby="pc-folio-debtors-snapshot-title">
            <div>
                <h2 id="pc-folio-debtors-snapshot-title"><?php esc_html_e('Debt snapshot', 'pc-folio-customer-balance'); ?></h2>
                <p class="description"><?php esc_html_e('The debtors report uses a previously calculated Folio snapshot.', 'pc-folio-customer-balance'); ?></p>
            </div>
            <dl class="pc-folio-debtors__snapshot-details">
                <div><dt><?php esc_html_e('Status', 'pc-folio-customer-balance'); ?></dt><dd data-pc-snapshot-state>&mdash;</dd></div>
                <div><dt><?php esc_html_e('Calculated as of', 'pc-folio-customer-balance'); ?></dt><dd data-pc-snapshot-date>&mdash;</dd></div>
                <div><dt><?php esc_html_e('Completed', 'pc-folio-customer-balance'); ?></dt><dd data-pc-snapshot-completed>&mdash;</dd></div>
                <div><dt><?php esc_html_e('Customers calculated', 'pc-folio-customer-balance'); ?></dt><dd data-pc-snapshot-total>&mdash;</dd></div>
            </dl>
            <div class="pc-folio-debtors__snapshot-actions">
                <button type="button" class="button" data-pc-snapshot-refresh><?php esc_html_e('Refresh debt data', 'pc-folio-customer-balance'); ?></button>
                <button type="button" class="button" data-pc-snapshot-activity><?php esc_html_e('Check database activity', 'pc-folio-customer-balance'); ?></button>
            </div>
        </section>
        <div class="pc-folio-debtors__snapshot-message" data-pc-snapshot-message role="status" aria-live="polite"></div>
        <div class="pc-folio-debtors__database-activity" data-pc-snapshot-activity-result role="status" aria-live="polite" hidden></div>

        <form class="pc-folio-debtors__filters" data-pc-debtors-form>
            <label>
                <span><?php esc_html_e('Minimum payable amount', 'pc-folio-customer-balance'); ?></span>
                <input type="number" name="min_payable" min="0" step="0.01" value="100.00" required>
            </label>
            <label class="pc-folio-debtors__search">
                <span><?php esc_html_e('Customer search', 'pc-folio-customer-balance'); ?></span>
                <input type="search" name="q" maxlength="100" placeholder="<?php esc_attr_e('Short or full Folio name', 'pc-folio-customer-balance'); ?>">
            </label>
            <label>
                <span><?php esc_html_e('Customer type', 'pc-folio-customer-balance'); ?></span>
                <select name="types">
                    <option value="П,Д,К"><?php esc_html_e('Customers (partners, dealers, buyers)', 'pc-folio-customer-balance'); ?></option>
                    <option value="П"><?php esc_html_e('Partners', 'pc-folio-customer-balance'); ?></option>
                    <option value="Д"><?php esc_html_e('Dealers', 'pc-folio-customer-balance'); ?></option>
                    <option value="К"><?php esc_html_e('Buyers', 'pc-folio-customer-balance'); ?></option>
                    <option value="all"><?php esc_html_e('All types', 'pc-folio-customer-balance'); ?></option>
                </select>
            </label>
            <label>
                <span><?php esc_html_e('Rows per page', 'pc-folio-customer-balance'); ?></span>
                <select name="limit">
                    <option value="25">25</option>
                    <option value="50" selected>50</option>
                    <option value="100">100</option>
                    <option value="200">200</option>
                </select>
            </label>
            <button type="submit" class="button button-primary"><?php esc_html_e('Generate report', 'pc-folio-customer-balance'); ?></button>
        </form>

        <p class="pc-folio-debtors__hint">
            <?php esc_html_e('The report is loaded from the prepared Folio snapshot and does not recalculate every customer during viewing.', 'pc-folio-customer-balance'); ?>
        </p>
        <div class="pc-folio-debtors__status" data-pc-debtors-status role="status" aria-live="polite"></div>
        <div class="pc-folio-debtors__meta" data-pc-debtors-meta hidden></div>
        <div class="pc-folio-debtors__summary" data-pc-debtors-summary hidden></div>

        <div class="pc-folio-debtors__table-wrap" data-pc-debtors-table-wrap hidden>
            <table class="widefat striped pc-folio-debtors__table">
                <colgroup>
                    <col class="pc-folio-debtors__col-customer">
                    <col class="pc-folio-debtors__col-type">
                    <col class="pc-folio-debtors__col-money">
                    <col class="pc-folio-debtors__col-money">
                    <col class="pc-folio-debtors__col-money">
                    <col class="pc-folio-debtors__col-money">
                    <col class="pc-folio-debtors__col-money">
                    <col class="pc-folio-debtors__col-site-user">
                </colgroup>
                <thead>
                    <tr>
                        <th><?php esc_html_e('Folio customer', 'pc-folio-customer-balance'); ?></th>
                        <th><?php esc_html_e('Type', 'pc-folio-customer-balance'); ?></th>
                        <th><?php esc_html_e('Total debt', 'pc-folio-customer-balance'); ?></th>
                        <th><?php esc_html_e('Deferred / on sale', 'pc-folio-customer-balance'); ?></th>
                        <th><?php esc_html_e('Overdue deferred / on sale', 'pc-folio-customer-balance'); ?></th>
                        <th><?php esc_html_e('Prepayment', 'pc-folio-customer-balance'); ?></th>
                        <th><?php esc_html_e('Payable now', 'pc-folio-customer-balance'); ?></th>
                        <th><?php esc_html_e('Customer on site', 'pc-folio-customer-balance'); ?></th>
                    </tr>
                </thead>
                <tbody data-pc-debtors-rows></tbody>
            </table>
        </div>

        <div class="pc-folio-debtors__pagination" data-pc-debtors-pagination hidden>
            <button type="button" class="button" data-pc-debtors-prev><?php esc_html_e('Previous page', 'pc-folio-customer-balance'); ?></button>
            <span data-pc-debtors-page></span>
            <button type="button" class="button" data-pc-debtors-next><?php esc_html_e('Next page', 'pc-folio-customer-balance'); ?></button>
        </div>
    </div>
    <?php
}

function pc_folio_debtors_enqueue_assets(): void {
    $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
    if ($page !== PC_FOLIO_DEBTORS_PAGE || !pc_folio_debtors_can_view()) {
        return;
    }

    $base_url = content_url('/mu-plugins/pc-folio-customer-balance/assets/');
    wp_enqueue_style(
        'pc-folio-customer-debtors',
        $base_url . 'customer-debtors.css',
        [],
        PC_FOLIO_BALANCE_VERSION
    );
    wp_enqueue_script(
        'pc-folio-customer-debtors',
        $base_url . 'customer-debtors.js',
        [],
        PC_FOLIO_BALANCE_VERSION,
        true
    );
    wp_localize_script('pc-folio-customer-debtors', 'pcFolioDebtors', [
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce'   => wp_create_nonce('pc_folio_customer_debtors'),
        'today'   => current_time('Y-m-d'),
        'pollInterval' => 45000,
        'labels'  => [
            'loading'       => __('The debtors report is being generated. This may take some time...', 'pc-folio-customer-balance'),
            'requestFailed' => __('The debtors report could not be generated. Please try again later.', 'pc-folio-customer-balance'),
            'requestId'     => __('Request ID: %s', 'pc-folio-customer-balance'),
            'empty'         => __('No customers exceed the selected payable threshold.', 'pc-folio-customer-balance'),
            'asOf'          => __('As of: %s', 'pc-folio-customer-balance'),
            'threshold'     => __('Threshold: more than %s UAH', 'pc-folio-customer-balance'),
            'found'         => __('Customers found: %s', 'pc-folio-customer-balance'),
            'page'          => __('Page %1$s of %2$s', 'pc-folio-customer-balance'),
            'profile'       => __('Profile', 'pc-folio-customer-balance'),
            'balance'       => __('Detailed balance', 'pc-folio-customer-balance'),
            'notLinked'     => __('Not linked to a site customer', 'pc-folio-customer-balance'),
            'multipleLinks' => __('Multiple site customers are linked', 'pc-folio-customer-balance'),
            'types'         => [
                'П' => __('Partner', 'pc-folio-customer-balance'),
                'Д' => __('Dealer', 'pc-folio-customer-balance'),
                'К' => __('Buyer', 'pc-folio-customer-balance'),
            ],
            'summary'       => [
                'matchedClients'             => __('Debtors found', 'pc-folio-customer-balance'),
                'commonDebtTotal'             => __('Total debt', 'pc-folio-customer-balance'),
                'deferredAmountTotal'         => __('Deferred / on sale', 'pc-folio-customer-balance'),
                'overdueDeferredAmountTotal'  => __('Overdue deferred / on sale', 'pc-folio-customer-balance'),
                'prepaymentAmountTotal'       => __('Prepayment', 'pc-folio-customer-balance'),
                'payableNowTotal'             => __('Payable now', 'pc-folio-customer-balance'),
            ],
            'currency' => __('UAH', 'pc-folio-customer-balance'),
            'snapshot' => [
                'checking'       => __('Checking the Folio debt snapshot...', 'pc-folio-customer-balance'),
                'refreshing'     => __('Debt data is being refreshed...', 'pc-folio-customer-balance'),
                'refreshFailed'  => __('The debt snapshot refresh could not be started.', 'pc-folio-customer-balance'),
                'statusFailed'   => __('The debt snapshot status could not be checked.', 'pc-folio-customer-balance'),
                'active'         => __('Ready', 'pc-folio-customer-balance'),
                'building'       => __('Updating', 'pc-folio-customer-balance'),
                'notReady'       => __('Not created', 'pc-folio-customer-balance'),
                'failed'         => __('Update failed', 'pc-folio-customer-balance'),
                'superseded'     => __('Replaced by a newer snapshot', 'pc-folio-customer-balance'),
                'unknown'        => __('Unknown status', 'pc-folio-customer-balance'),
                'readyMessage'   => __('The snapshot is ready. You can generate the debtors report.', 'pc-folio-customer-balance'),
                'buildingMessage'=> __('The first debt snapshot is still being prepared. A ready report is not available yet.', 'pc-folio-customer-balance'),
                'buildingWithActive' => __('Showing data as of %1$s. The update for %2$s started at %3$s and is still running.', 'pc-folio-customer-balance'),
                'notReadyMessage'=> __('The debt snapshot has not been created yet. Start an administrative refresh.', 'pc-folio-customer-balance'),
                'failedMessage'  => __('The latest debt snapshot refresh failed.', 'pc-folio-customer-balance'),
                'failedWithActive' => __('The latest update failed. The ready report as of %s remains available.', 'pc-folio-customer-balance'),
                'emptyMessage'   => __('The snapshot contains no customers. Refresh the debt data before generating the report.', 'pc-folio-customer-balance'),
                'staleMessage'   => __('Warning: debt data was calculated as of %s.', 'pc-folio-customer-balance'),
                'accepted'       => __('The debt snapshot refresh has started.', 'pc-folio-customer-balance'),
                'running'        => __('A debt snapshot refresh is already running.', 'pc-folio-customer-balance'),
                'activityChecking' => __('Checking database activity...', 'pc-folio-customer-balance'),
                'activityFailed' => __('Database activity could not be checked.', 'pc-folio-customer-balance'),
                'activityChecked' => __('Checked: %s', 'pc-folio-customer-balance'),
                'activitySessions' => __('Sessions: detected %1$s, active %2$s, blocked %3$s, idle %4$s.', 'pc-folio-customer-balance'),
                'activityStates' => [
                    'RUNNING'      => __('The debt calculation is running.', 'pc-folio-customer-balance'),
                    'BLOCKED'      => __('The debt calculation is waiting for a database lock.', 'pc-folio-customer-balance'),
                    'IDLE_SESSION' => __('The calculation session is temporarily idle.', 'pc-folio-customer-balance'),
                    'NOT_DETECTED' => __('The calculation was not detected at this moment.', 'pc-folio-customer-balance'),
                    'UNAVAILABLE'  => __('Database activity is unavailable.', 'pc-folio-customer-balance'),
                ],
            ],
        ],
    ]);
}
add_action('admin_enqueue_scripts', 'pc_folio_debtors_enqueue_assets');

function pc_folio_debtors_filters_from_request() {
    $raw_min = isset($_POST['min_payable']) ? trim((string) wp_unslash($_POST['min_payable'])) : '100.00';
    if (!preg_match('/^\d+(?:\.\d{1,2})?$/', $raw_min)) {
        return new WP_Error('invalid_min_payable', __('Enter a non-negative threshold with no more than two decimal places.', 'pc-folio-customer-balance'));
    }

    $q = isset($_POST['q']) ? sanitize_text_field(wp_unslash($_POST['q'])) : '';
    $q_length = function_exists('mb_strlen') ? mb_strlen($q, 'UTF-8') : strlen($q);
    if ($q_length > 100) {
        return new WP_Error('invalid_search', __('Customer search must not exceed 100 characters.', 'pc-folio-customer-balance'));
    }

    $types = isset($_POST['types']) ? sanitize_text_field(wp_unslash($_POST['types'])) : 'П,Д,К';
    if (!in_array($types, ['П,Д,К', 'П', 'Д', 'К', 'all'], true)) {
        return new WP_Error('invalid_types', __('Select a valid Folio customer type.', 'pc-folio-customer-balance'));
    }

    $limit = isset($_POST['limit']) ? (int) $_POST['limit'] : 50;
    if (!in_array($limit, [25, 50, 100, 200], true)) {
        return new WP_Error('invalid_limit', __('Select a valid number of rows per page.', 'pc-folio-customer-balance'));
    }

    $raw_offset = isset($_POST['offset']) ? trim((string) wp_unslash($_POST['offset'])) : '0';
    if (!preg_match('/^\d+$/', $raw_offset)) {
        return new WP_Error('invalid_offset', __('The requested report page is invalid.', 'pc-folio-customer-balance'));
    }

    return [
        'minPayable' => number_format((float) $raw_min, 2, '.', ''),
        'q'          => $q,
        'types'      => $types,
        'limit'      => $limit,
        'offset'     => (int) $raw_offset,
        'sort'       => 'payableNow_desc',
    ];
}

function pc_folio_debtors_site_users(array $short_names): array {
    global $wpdb;

    $short_names = array_values(array_unique(array_filter(array_map('strval', $short_names))));
    if (!$short_names) {
        return [];
    }

    $placeholders = implode(', ', array_fill(0, count($short_names), '%s'));
    $sql = "SELECT um.user_id, um.meta_value AS short_name, u.display_name
            FROM {$wpdb->usermeta} um
            INNER JOIN {$wpdb->users} u ON u.ID = um.user_id
            WHERE um.meta_key = '_folio_partner_short_name'
              AND um.meta_value IN ({$placeholders})
            ORDER BY um.meta_value, um.user_id";
    $rows = $wpdb->get_results($wpdb->prepare($sql, ...$short_names), ARRAY_A);
    $mapped = [];

    foreach ($rows as $row) {
        $user_id = (int) ($row['user_id'] ?? 0);
        $short_name = trim((string) ($row['short_name'] ?? ''));
        if ($user_id <= 0 || $short_name === '') {
            continue;
        }
        $mapped[$short_name][] = [
            'id'          => $user_id,
            'displayName' => (string) ($row['display_name'] ?? ''),
            'profileUrl'  => current_user_can('edit_user', $user_id) ? get_edit_user_link($user_id) : '',
            'balanceUrl'  => pc_folio_balance_admin_url($user_id),
        ];
    }

    return $mapped;
}

function pc_folio_debtors_fetch(array $filters) {
    if (!function_exists('lps_java_get')) {
        return new WP_Error('folio_connection_unavailable', __('The Folio service connection is unavailable.', 'pc-folio-customer-balance'), ['status' => 503]);
    }

    $response = lps_java_get(add_query_arg($filters, '/admin/folio/customer-debtors'), ['timeout' => 300]);
    if (is_wp_error($response)) {
        return new WP_Error('folio_unavailable', __('The Folio service is temporarily unavailable.', 'pc-folio-customer-balance'), ['status' => 503]);
    }

    $code = (int) wp_remote_retrieve_response_code($response);
    $data = json_decode((string) wp_remote_retrieve_body($response), true);
    if ($code < 200 || $code >= 300) {
        $message = $code === 400
            ? __('The report parameters were rejected by Folio.', 'pc-folio-customer-balance')
            : __('The debtors report could not be generated. Please try again later.', 'pc-folio-customer-balance');
        return new WP_Error('folio_debtors_http_error', $message, [
            'status' => $code >= 400 && $code < 600 ? $code : 502,
            'reqId'  => is_array($data) ? sanitize_text_field((string) ($data['reqId'] ?? $data['requestId'] ?? '')) : '',
        ]);
    }

    if (!is_array($data)
        || ($data['ok'] ?? false) !== true
        || !is_array($data['summary'] ?? null)
        || !is_array($data['debtors'] ?? null)) {
        return new WP_Error('invalid_folio_debtors', __('Folio returned an invalid debtors report.', 'pc-folio-customer-balance'), ['status' => 502]);
    }

    $short_names = [];
    foreach ($data['debtors'] as $debtor) {
        $short_name = trim((string) ($debtor['partner']['shortName'] ?? ''));
        if ($short_name !== '') {
            $short_names[] = $short_name;
        }
    }
    $site_users = pc_folio_debtors_site_users($short_names);
    foreach ($data['debtors'] as &$debtor) {
        $short_name = trim((string) ($debtor['partner']['shortName'] ?? ''));
        $debtor['siteUsers'] = $site_users[$short_name] ?? [];
    }
    unset($debtor);

    return $data;
}

function pc_folio_debtors_snapshot_request(string $method) {
    $method = strtoupper($method);
    $function = $method === 'POST' ? 'lps_java_post' : 'lps_java_get';
    if (!function_exists($function)) {
        return new WP_Error('folio_connection_unavailable', __('The Folio service connection is unavailable.', 'pc-folio-customer-balance'), ['status' => 503]);
    }

    $path = $method === 'POST'
        ? '/admin/folio/customer-debtors/snapshot/refresh'
        : '/admin/folio/customer-debtors/snapshot/status';
    $response = $method === 'POST'
        ? lps_java_post($path, new stdClass(), ['timeout' => 60])
        : lps_java_get($path, ['timeout' => 60]);

    if (is_wp_error($response)) {
        return new WP_Error('folio_snapshot_unavailable', __('The Folio service is temporarily unavailable.', 'pc-folio-customer-balance'), ['status' => 503]);
    }

    $code = (int) wp_remote_retrieve_response_code($response);
    $data = json_decode((string) wp_remote_retrieve_body($response), true);
    if ($code < 200 || $code >= 300 || !is_array($data)) {
        return new WP_Error('folio_snapshot_http_error', __('The Folio debt snapshot request failed.', 'pc-folio-customer-balance'), [
            'status' => $code >= 400 && $code < 600 ? $code : 502,
            'reqId'  => is_array($data) ? sanitize_text_field((string) ($data['reqId'] ?? $data['requestId'] ?? '')) : '',
        ]);
    }

    if (($data['ok'] ?? false) !== true) {
        return new WP_Error('invalid_folio_snapshot', __('Folio returned an invalid debt snapshot response.', 'pc-folio-customer-balance'), ['status' => 502]);
    }

    if ($method === 'GET' && !in_array((string) ($data['status'] ?? ''), ['ACTIVE', 'BUILDING', 'NOT_READY', 'FAILED', 'SUPERSEDED'], true)) {
        return new WP_Error('invalid_folio_snapshot_status', __('Folio returned an unknown debt snapshot status.', 'pc-folio-customer-balance'), ['status' => 502]);
    }

    return $data;
}

function pc_folio_debtors_database_activity_request() {
    if (!function_exists('lps_java_get')) {
        return new WP_Error('folio_connection_unavailable', __('The Folio service connection is unavailable.', 'pc-folio-customer-balance'), ['status' => 503]);
    }

    $response = lps_java_get('/admin/folio/customer-debtors/snapshot/database-activity', ['timeout' => 30]);
    if (is_wp_error($response)) {
        return new WP_Error('folio_database_activity_unavailable', __('The Folio service is temporarily unavailable.', 'pc-folio-customer-balance'), ['status' => 503]);
    }

    $code = (int) wp_remote_retrieve_response_code($response);
    $data = json_decode((string) wp_remote_retrieve_body($response), true);
    if ($code < 200 || $code >= 300 || !is_array($data)) {
        return new WP_Error('folio_database_activity_http_error', __('The Folio database activity request failed.', 'pc-folio-customer-balance'), [
            'status' => $code >= 400 && $code < 600 ? $code : 502,
            'reqId'  => is_array($data) ? sanitize_text_field((string) ($data['reqId'] ?? $data['requestId'] ?? '')) : '',
        ]);
    }

    $state = sanitize_key((string) ($data['state'] ?? ''));
    $state = strtoupper($state);
    if (($data['ok'] ?? false) !== true || !in_array($state, ['RUNNING', 'BLOCKED', 'IDLE_SESSION', 'NOT_DETECTED', 'UNAVAILABLE'], true)) {
        return new WP_Error('invalid_folio_database_activity', __('Folio returned an invalid database activity response.', 'pc-folio-customer-balance'), ['status' => 502]);
    }

    return [
        'state'            => $state,
        'checkedAt'        => sanitize_text_field((string) ($data['checkedAt'] ?? '')),
        'detectedSessions' => max(0, (int) ($data['detectedSessions'] ?? 0)),
        'activeSessions'   => max(0, (int) ($data['activeSessions'] ?? 0)),
        'blockedSessions'  => max(0, (int) ($data['blockedSessions'] ?? 0)),
        'idleSessions'     => max(0, (int) ($data['idleSessions'] ?? 0)),
    ];
}

function pc_folio_debtors_send_error(WP_Error $error): void {
    $error_data = $error->get_error_data();
    wp_send_json_error([
        'message' => $error->get_error_message(),
        'reqId'   => is_array($error_data) ? (string) ($error_data['reqId'] ?? '') : '',
    ], is_array($error_data) ? (int) ($error_data['status'] ?? 502) : 502);
}

function pc_folio_debtors_snapshot_status_ajax(): void {
    check_ajax_referer('pc_folio_customer_debtors');
    if (!pc_folio_debtors_can_view()) {
        wp_send_json_error(['message' => __('You do not have permission to view this report.', 'pc-folio-customer-balance')], 403);
    }

    $snapshot = pc_folio_debtors_snapshot_request('GET');
    if (is_wp_error($snapshot)) {
        pc_folio_debtors_send_error($snapshot);
    }
    wp_send_json_success(['snapshot' => $snapshot]);
}
add_action('wp_ajax_pc_folio_customer_debtors_snapshot_status', 'pc_folio_debtors_snapshot_status_ajax');

function pc_folio_debtors_snapshot_refresh_ajax(): void {
    check_ajax_referer('pc_folio_customer_debtors');
    if (!pc_folio_debtors_can_view()) {
        wp_send_json_error(['message' => __('You do not have permission to refresh this report.', 'pc-folio-customer-balance')], 403);
    }

    $result = pc_folio_debtors_snapshot_request('POST');
    if (is_wp_error($result)) {
        pc_folio_debtors_send_error($result);
    }
    wp_send_json_success(['snapshot' => $result]);
}
add_action('wp_ajax_pc_folio_customer_debtors_snapshot_refresh', 'pc_folio_debtors_snapshot_refresh_ajax');

function pc_folio_debtors_database_activity_ajax(): void {
    check_ajax_referer('pc_folio_customer_debtors');
    if (!pc_folio_debtors_can_view()) {
        wp_send_json_error(['message' => __('You do not have permission to view this report.', 'pc-folio-customer-balance')], 403);
    }

    $activity = pc_folio_debtors_database_activity_request();
    if (is_wp_error($activity)) {
        pc_folio_debtors_send_error($activity);
    }
    wp_send_json_success(['activity' => $activity]);
}
add_action('wp_ajax_pc_folio_customer_debtors_database_activity', 'pc_folio_debtors_database_activity_ajax');

function pc_folio_debtors_ajax(): void {
    check_ajax_referer('pc_folio_customer_debtors');
    if (!pc_folio_debtors_can_view()) {
        wp_send_json_error(['message' => __('You do not have permission to view this report.', 'pc-folio-customer-balance')], 403);
    }

    $filters = pc_folio_debtors_filters_from_request();
    if (is_wp_error($filters)) {
        wp_send_json_error(['message' => $filters->get_error_message()], 400);
    }

    $report = pc_folio_debtors_fetch($filters);
    if (is_wp_error($report)) {
        $error_data = $report->get_error_data();
        wp_send_json_error([
            'message' => $report->get_error_message(),
            'reqId'   => is_array($error_data) ? (string) ($error_data['reqId'] ?? '') : '',
        ], is_array($error_data) ? (int) ($error_data['status'] ?? 502) : 502);
    }

    wp_send_json_success(['report' => $report]);
}
add_action('wp_ajax_pc_folio_customer_debtors', 'pc_folio_debtors_ajax');
