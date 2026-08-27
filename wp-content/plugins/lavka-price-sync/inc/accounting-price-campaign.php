<?php
if (!defined('ABSPATH')) exit;

const LPS_ACCOUNTING_PRICE_CAMPAIGN_OPTION = 'lps_accounting_price_sku_campaign';
const LPS_ACCOUNTING_PRICE_CAMPAIGN_TICK_HOOK = 'lps_accounting_price_sku_campaign_tick';
const LPS_ACCOUNTING_PRICE_CAMPAIGN_LOCK_PROCESS = 'accounting_price_sku_campaign';
const LPS_ACCOUNTING_PRICE_CAMPAIGN_SNAPSHOT_PATH = '/admin/folio/accounting-prices/snapshot/refresh';
const LPS_ACCOUNTING_PRICE_CAMPAIGN_SNAPSHOT_STATUS_PATH = '/admin/folio/accounting-prices/snapshot/status';
const LPS_ACCOUNTING_PRICE_CAMPAIGN_RANGE_PATH = '/admin/folio/accounting-prices/recalculate/native-range';
const LPS_ACCOUNTING_PRICE_CAMPAIGN_RANGE_STATUS_PATH = '/admin/folio/accounting-prices/recalculate/native-range/status';
const LPS_ACCOUNTING_PRICE_CAMPAIGN_MAX_REPORTS = 500;
const LPS_ACCOUNTING_PRICE_CAMPAIGN_MAX_WARNINGS = 500;
const LPS_ACCOUNTING_PRICE_CAMPAIGN_WAREHOUSE_HISTORY_PREFIX = 'lps_accounting_price_sku_campaign_warehouse_';
const LPS_ACCOUNTING_PRICE_CAMPAIGN_WAREHOUSE_HISTORY_INDEX = 'lps_accounting_price_sku_campaign_warehouse_index';
const LPS_ACCOUNTING_PRICE_CAMPAIGN_MAX_WAREHOUSE_ISSUES = 500;

function lps_accounting_price_campaign_state(): array {
    $state = get_option(LPS_ACCOUNTING_PRICE_CAMPAIGN_OPTION, []);
    return is_array($state) ? $state : [];
}

function lps_accounting_price_campaign_store(array $state): void {
    $state['updated_at'] = current_time('mysql');
    $state['updated_at_gmt'] = current_time('mysql', true);
    update_option(LPS_ACCOUNTING_PRICE_CAMPAIGN_OPTION, $state, false);
    if (empty($state['active']) && !empty($state['campaign_id']) && absint($state['current_warehouse_id'] ?? 0) > 0) {
        lps_accounting_price_campaign_persist_warehouse_history($state);
    }
}

function lps_accounting_price_campaign_schedule_tick(int $delay = 10): ?int {
    $existing = wp_next_scheduled(LPS_ACCOUNTING_PRICE_CAMPAIGN_TICK_HOOK);
    if ($existing) return (int)$existing;

    $timestamp = time() + max(1, $delay);
    return wp_schedule_single_event($timestamp, LPS_ACCOUNTING_PRICE_CAMPAIGN_TICK_HOOK)
        ? $timestamp
        : null;
}

function lps_accounting_price_campaign_clear_ticks(): void {
    wp_clear_scheduled_hook(LPS_ACCOUNTING_PRICE_CAMPAIGN_TICK_HOOK);
}

function lps_accounting_price_campaign_log(string $event, array $data = []): void {
    if (!function_exists('lavka_ecosystem_log_event')) return;

    lavka_ecosystem_log_event($event, wp_parse_args($data, [
        'owner' => 'lavka-price-sync',
        'process' => LPS_ACCOUNTING_PRICE_CAMPAIGN_LOCK_PROCESS,
        'source' => 'cron',
        'user_id' => get_current_user_id(),
    ]));
}

function lps_accounting_price_campaign_acquire_lock(array &$state, string $operation): array {
    if (!function_exists('lavka_ecosystem_lock_acquire')) {
        return [
            'ok' => false,
            'message' => __('The global Lavka lock is unavailable. The SKU campaign was not started.', 'lavka-price-sync'),
        ];
    }

    $warehouse_id = absint($state['current_warehouse_id'] ?? 0);
    $result = lavka_ecosystem_lock_acquire(
        'lavka-price-sync',
        LPS_ACCOUNTING_PRICE_CAMPAIGN_LOCK_PROCESS,
        sanitize_key((string)($state['source'] ?? 'manual')),
        __('Folio accounting-price SKU campaign', 'lavka-price-sync'),
        2 * HOUR_IN_SECONDS,
        [
            'campaign_id' => (string)($state['campaign_id'] ?? ''),
            'warehouse_id' => $warehouse_id,
            'operation' => sanitize_key($operation),
        ]
    );

    if (!empty($result['ok'])) {
        $state['lock_token'] = (string)($result['token'] ?? '');
        $state['waiting_lock'] = false;
    }
    return $result;
}

function lps_accounting_price_campaign_touch_lock(array $state, array $progress = []): bool {
    $token = (string)($state['lock_token'] ?? '');
    return $token !== '' && function_exists('lavka_ecosystem_lock_touch')
        ? lavka_ecosystem_lock_touch($token, 2 * HOUR_IN_SECONDS, ['progress' => $progress])
        : false;
}

function lps_accounting_price_campaign_release_lock(array &$state): void {
    $token = (string)($state['lock_token'] ?? '');
    if ($token !== '' && function_exists('lavka_ecosystem_lock_release')) {
        lavka_ecosystem_lock_release($token);
    }
    $state['lock_token'] = '';
}

function lps_accounting_price_campaign_snapshot_tables(): array {
    return [
        'generation' => 'folio_product_snapshot_generation',
        'item' => 'folio_product_snapshot_item',
    ];
}

function lps_accounting_price_campaign_tables_ready(): bool {
    global $wpdb;
    foreach (lps_accounting_price_campaign_snapshot_tables() as $table) {
        $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table)));
        if ((string)$found !== $table) return false;
    }
    return true;
}

function lps_accounting_price_campaign_latest_generation(int $warehouse_id): array {
    global $wpdb;
    $tables = lps_accounting_price_campaign_snapshot_tables();
    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT id, source_database, warehouse_id, status, completed_at
         FROM {$tables['generation']}
         WHERE warehouse_id = %d AND status = 'ACTIVE'
         ORDER BY id DESC LIMIT 1",
        $warehouse_id
    ), ARRAY_A);
    return is_array($row) ? $row : [];
}

function lps_accounting_price_campaign_latest_attempt(int $warehouse_id): array {
    global $wpdb;
    $tables = lps_accounting_price_campaign_snapshot_tables();
    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT id, source_database, warehouse_id, horizon_months, status, trigger_source,
                started_at, completed_at, last_heartbeat_at, total_products,
                unverified_products, dirty_products, new_products, removed_products, error_message
         FROM {$tables['generation']}
         WHERE warehouse_id = %d
         ORDER BY id DESC LIMIT 1",
        $warehouse_id
    ), ARRAY_A);
    return is_array($row) ? $row : [];
}

function lps_accounting_price_campaign_history_ids(): array {
    $ids = get_option(LPS_ACCOUNTING_PRICE_CAMPAIGN_WAREHOUSE_HISTORY_INDEX, []);
    return function_exists('lps_accounting_prices_native_normalize_warehouse_ids')
        ? lps_accounting_prices_native_normalize_warehouse_ids(is_array($ids) ? $ids : [])
        : array_values(array_unique(array_filter(array_map('absint', is_array($ids) ? $ids : []))));
}

function lps_accounting_price_campaign_warehouse_history(int $warehouse_id): array {
    if ($warehouse_id < 1) return [];
    $history = get_option(LPS_ACCOUNTING_PRICE_CAMPAIGN_WAREHOUSE_HISTORY_PREFIX . $warehouse_id, []);
    return is_array($history) ? $history : [];
}

function lps_accounting_price_campaign_issue_code(array $issue): string {
    return strtoupper(sanitize_key((string)($issue['code'] ?? '')));
}

function lps_accounting_price_campaign_issue_is_error(array $issue): bool {
    $severity = strtolower(sanitize_key((string)($issue['severity'] ?? '')));
    return $severity === 'error' || in_array(lps_accounting_price_campaign_issue_code($issue), ['FAILED_CHUNK', 'WAREHOUSE_FAILED'], true);
}

function lps_accounting_price_campaign_history_from_state(array $state, int $warehouse_id): array {
    if ($warehouse_id < 1 || empty($state['campaign_id'])) return [];

    $reports = array_values(array_filter((array)($state['reports'] ?? []), static function ($report) use ($warehouse_id): bool {
        return is_array($report) && absint($report['warehouse_id'] ?? 0) === $warehouse_id;
    }));
    $issues = array_values(array_filter((array)($state['warnings'] ?? []), static function ($issue) use ($warehouse_id): bool {
        return is_array($issue) && absint($issue['warehouseId'] ?? ($issue['warehouse_id'] ?? 0)) === $warehouse_id;
    }));
    $is_current = absint($state['current_warehouse_id'] ?? 0) === $warehouse_id;
    if (!$reports && !$issues && !$is_current) return [];

    $final_report = [];
    $processed_skus = 0;
    foreach ($reports as $report) {
        $status = strtoupper(sanitize_key((string)($report['status'] ?? '')));
        if (in_array($status, ['COMPLETED', 'COMPLETED_WITH_WARNINGS'], true)) {
            $processed_skus += absint($report['sku_count'] ?? 0);
        }
        if (in_array($status, ['WAREHOUSE_FAILED', 'SNAPSHOT_CONFIRMED'], true)) $final_report = $report;
    }

    $negative_count = 0;
    $error_count = 0;
    foreach ($issues as $issue) {
        if (lps_accounting_price_campaign_issue_code($issue) === 'NEGATIVE_CHRONOLOGICAL_STOCK') $negative_count++;
        if (lps_accounting_price_campaign_issue_is_error($issue)) $error_count++;
    }

    $status = 'RUNNING';
    if ($final_report) {
        $status = strtoupper(sanitize_key((string)($final_report['status'] ?? 'SNAPSHOT_CONFIRMED')));
        if ($status === 'SNAPSHOT_CONFIRMED') $status = $issues ? 'COMPLETED_WITH_WARNINGS' : 'COMPLETED';
    } elseif ($is_current && empty($state['active'])) {
        $status = strtoupper(sanitize_key((string)($state['status'] ?? 'MANUAL_REVIEW')));
    }

    $completed_at = sanitize_text_field((string)($final_report['completed_at'] ?? ''));
    if ($completed_at === '' && $is_current && empty($state['active'])) {
        $completed_at = sanitize_text_field((string)($state['completed_at'] ?? ($state['updated_at'] ?? '')));
    }

    return [
        'warehouse_id' => $warehouse_id,
        'campaign_id' => sanitize_text_field((string)($state['campaign_id'] ?? '')),
        'source' => sanitize_key((string)($state['source'] ?? 'manual')),
        'source_database' => sanitize_text_field((string)($state['source_database'] ?? '')),
        'status' => $status,
        'started_at' => sanitize_text_field((string)($state['warehouse_started_at'] ?? ($state['started_at'] ?? ''))),
        'completed_at' => $completed_at,
        'processed_skus' => $processed_skus,
        'counts_before' => $is_current && is_array($state['counts_before'] ?? null)
            ? $state['counts_before']
            : (is_array($final_report['counts_before'] ?? null) ? $final_report['counts_before'] : []),
        'counts_after' => $is_current && is_array($state['counts_after'] ?? null)
            ? $state['counts_after']
            : (is_array($final_report['counts_after'] ?? null) ? $final_report['counts_after'] : []),
        'negative_count' => $negative_count,
        'error_count' => $error_count,
        'warning_count' => count($issues) - $error_count,
        'issues' => array_slice($issues, -LPS_ACCOUNTING_PRICE_CAMPAIGN_MAX_WAREHOUSE_ISSUES),
        'issues_truncated' => !empty($state['warnings_truncated']) || count($issues) > LPS_ACCOUNTING_PRICE_CAMPAIGN_MAX_WAREHOUSE_ISSUES,
        'error' => sanitize_textarea_field((string)($final_report['error'] ?? ($is_current ? ($state['error'] ?? '') : ''))),
    ];
}

function lps_accounting_price_campaign_history_timestamp(array $history): int {
    $value = (string)($history['completed_at'] ?? ($history['started_at'] ?? ''));
    $timestamp = $value !== '' ? strtotime($value) : false;
    return $timestamp === false ? 0 : $timestamp;
}

function lps_accounting_price_campaign_effective_history(int $warehouse_id, ?array $state = null): array {
    $stored = lps_accounting_price_campaign_warehouse_history($warehouse_id);
    $current = lps_accounting_price_campaign_history_from_state($state ?? lps_accounting_price_campaign_state(), $warehouse_id);
    if (!$current) return $stored;
    if (!$stored || lps_accounting_price_campaign_history_timestamp($current) >= lps_accounting_price_campaign_history_timestamp($stored)) {
        return $current;
    }
    return $stored;
}

function lps_accounting_price_campaign_persist_warehouse_history(array $state, int $warehouse_id = 0): void {
    $warehouse_id = $warehouse_id > 0 ? $warehouse_id : absint($state['current_warehouse_id'] ?? 0);
    $history = lps_accounting_price_campaign_history_from_state($state, $warehouse_id);
    if (!$history) return;

    update_option(LPS_ACCOUNTING_PRICE_CAMPAIGN_WAREHOUSE_HISTORY_PREFIX . $warehouse_id, $history, false);
    $ids = lps_accounting_price_campaign_history_ids();
    $ids[] = $warehouse_id;
    update_option(
        LPS_ACCOUNTING_PRICE_CAMPAIGN_WAREHOUSE_HISTORY_INDEX,
        lps_accounting_prices_native_normalize_warehouse_ids($ids),
        false
    );
}

function lps_accounting_price_campaign_persist_all_warehouse_history(array $state): void {
    $warehouse_ids = lps_accounting_prices_native_normalize_warehouse_ids($state['warehouse_ids'] ?? []);
    if (!$warehouse_ids && absint($state['current_warehouse_id'] ?? 0) > 0) {
        $warehouse_ids = [absint($state['current_warehouse_id'])];
    }
    foreach ($warehouse_ids as $warehouse_id) {
        lps_accounting_price_campaign_persist_warehouse_history($state, $warehouse_id);
    }
}

function lps_accounting_price_campaign_counts(string $source_database, int $warehouse_id): array {
    global $wpdb;
    $tables = lps_accounting_price_campaign_snapshot_tables();
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT verification_state, COUNT(*) AS products
         FROM {$tables['item']}
         WHERE source_database = %s AND warehouse_id = %d
         GROUP BY verification_state",
        $source_database,
        $warehouse_id
    ), ARRAY_A);
    $counts = array_fill_keys(['UNVERIFIED', 'NEW', 'DIRTY', 'FAILED', 'VERIFIED', 'REMOVED'], 0);
    foreach ((array)$rows as $row) {
        $key = strtoupper(sanitize_key((string)($row['verification_state'] ?? '')));
        if (array_key_exists($key, $counts)) $counts[$key] = absint($row['products'] ?? 0);
    }
    return $counts;
}

function lps_accounting_price_campaign_snapshot_warehouse_ids(): array {
    global $wpdb;
    if (!lps_accounting_price_campaign_tables_ready()) return [];
    $tables = lps_accounting_price_campaign_snapshot_tables();
    return lps_accounting_prices_native_normalize_warehouse_ids(
        $wpdb->get_col("SELECT DISTINCT warehouse_id FROM {$tables['generation']} WHERE warehouse_id > 0 ORDER BY warehouse_id ASC")
    );
}

function lps_accounting_price_campaign_last_applied_at(string $source_database, int $warehouse_id): string {
    global $wpdb;
    if ($source_database === '' || $warehouse_id < 1) return '';
    $tables = lps_accounting_price_campaign_snapshot_tables();
    return sanitize_text_field((string)$wpdb->get_var($wpdb->prepare(
        "SELECT MAX(applied_at) FROM {$tables['item']} WHERE source_database = %s AND warehouse_id = %d",
        $source_database,
        $warehouse_id
    )));
}

function lps_accounting_price_campaign_warehouse_overview(array $warehouse_directory = []): array {
    if (!lps_accounting_price_campaign_tables_ready()) {
        return ['ok' => false, 'message' => __('The Folio product snapshot tables are unavailable.', 'lavka-price-sync')];
    }

    $names = [];
    $warehouse_ids = [];
    foreach ($warehouse_directory as $warehouse) {
        if (!is_array($warehouse)) continue;
        $warehouse_id = absint($warehouse['id'] ?? 0);
        if ($warehouse_id < 1) continue;
        $warehouse_ids[] = $warehouse_id;
        $names[$warehouse_id] = sanitize_text_field((string)($warehouse['name'] ?? $warehouse_id));
    }
    $warehouse_ids = array_merge(
        $warehouse_ids,
        lps_accounting_price_campaign_snapshot_warehouse_ids(),
        lps_accounting_price_campaign_history_ids(),
        lps_accounting_prices_native_normalize_warehouse_ids(lps_accounting_prices_native_cron_options()['warehouse_ids'] ?? []),
        lps_accounting_prices_native_normalize_warehouse_ids(lps_accounting_price_campaign_state()['warehouse_ids'] ?? [])
    );
    $warehouse_ids = lps_accounting_prices_native_normalize_warehouse_ids($warehouse_ids);
    sort($warehouse_ids, SORT_NUMERIC);

    $state = lps_accounting_price_campaign_state();
    $rows = [];
    $summary = [
        'warehouses' => count($warehouse_ids),
        'notProcessed' => 0,
        'withErrors' => 0,
        'negativeStock' => 0,
    ];

    foreach ($warehouse_ids as $warehouse_id) {
        $attempt = lps_accounting_price_campaign_latest_attempt($warehouse_id);
        $active = lps_accounting_price_campaign_latest_generation($warehouse_id);
        $source_database = sanitize_text_field((string)($active['source_database'] ?? ''));
        $counts = $source_database !== ''
            ? lps_accounting_price_campaign_counts($source_database, $warehouse_id)
            : array_fill_keys(['UNVERIFIED', 'NEW', 'DIRTY', 'FAILED', 'VERIFIED', 'REMOVED'], 0);
        $last_applied_at = lps_accounting_price_campaign_last_applied_at($source_database, $warehouse_id);
        $history = lps_accounting_price_campaign_effective_history($warehouse_id, $state);
        $has_processing_history = !empty($history['campaign_id']);
        $has_ever_processed = $has_processing_history || $last_applied_at !== '';
        $negative_count = absint($history['negative_count'] ?? 0);
        $last_error = sanitize_textarea_field((string)($history['error'] ?? ($attempt['error_message'] ?? '')));
        $error_count = max(
            absint($history['error_count'] ?? 0),
            absint($counts['FAILED'] ?? 0),
            $last_error !== '' ? 1 : 0
        );

        $latest_status = strtoupper(sanitize_key((string)($attempt['status'] ?? '')));
        $history_status = strtoupper(sanitize_key((string)($history['status'] ?? '')));
        if (!$attempt) {
            $display_status = 'NO_SNAPSHOT';
        } elseif ($latest_status === 'BUILDING') {
            $display_status = 'BUILDING';
        } elseif (!$has_ever_processed) {
            $display_status = 'NOT_PROCESSED';
        } elseif ($history_status !== '') {
            $display_status = $history_status;
        } elseif ($latest_status === 'FAILED') {
            $display_status = 'FAILED';
        } else {
            $display_status = 'LEGACY_PROCESSED';
        }

        if (!$has_ever_processed) $summary['notProcessed']++;
        if ($error_count > 0 || in_array($display_status, ['FAILED', 'WAREHOUSE_FAILED', 'FAILED_PARTIAL', 'OUTCOME_UNKNOWN', 'MANUAL_REVIEW'], true)) {
            $summary['withErrors']++;
        }
        $summary['negativeStock'] += $negative_count;

        $rows[] = [
            'warehouseId' => $warehouse_id,
            'warehouseName' => $names[$warehouse_id] ?? sprintf(
                /* translators: %d: Folio warehouse ID. */
                __('Warehouse %d', 'lavka-price-sync'),
                $warehouse_id
            ),
            'status' => $display_status,
            'hasEverProcessed' => $has_ever_processed,
            'latestAttempt' => $attempt,
            'activeSnapshot' => $active,
            'sourceDatabase' => $source_database,
            'counts' => $counts,
            'lastAppliedAt' => $last_applied_at,
            'lastProcessedAt' => sanitize_text_field((string)($history['completed_at'] ?? '')),
            'processedSkus' => absint($history['processed_skus'] ?? 0),
            'negativeCount' => $negative_count,
            'errorCount' => $error_count,
            'warningCount' => absint($history['warning_count'] ?? 0),
            'diagnosticsAvailable' => !empty($history['issues']),
            'diagnosticsTruncated' => !empty($history['issues_truncated']),
            'lastError' => $last_error,
        ];
    }

    return [
        'ok' => true,
        'rows' => $rows,
        'summary' => $summary,
        'directoryAvailable' => !empty($warehouse_directory),
        'generatedAt' => current_time('mysql'),
    ];
}

function lps_accounting_price_campaign_diagnostics(
    array $warehouse_directory = [],
    int $warehouse_id = 0,
    string $kind = 'all',
    int $page = 1,
    int $per_page = 50
): array {
    $overview = lps_accounting_price_campaign_warehouse_overview($warehouse_directory);
    if (empty($overview['ok'])) return $overview;

    $kind = in_array($kind, ['all', 'negative', 'errors'], true) ? $kind : 'all';
    $names = [];
    $scopes = [];
    $warehouse_rows = [];
    foreach ((array)($overview['rows'] ?? []) as $row) {
        if (!is_array($row)) continue;
        $row_warehouse_id = absint($row['warehouseId'] ?? 0);
        if ($row_warehouse_id < 1 || ($warehouse_id > 0 && $row_warehouse_id !== $warehouse_id)) continue;
        $names[$row_warehouse_id] = (string)($row['warehouseName'] ?? $row_warehouse_id);
        $scopes[$row_warehouse_id] = sanitize_text_field((string)($row['sourceDatabase'] ?? ''));
        $warehouse_rows[$row_warehouse_id] = $row;
    }

    $items = [];
    $truncated = false;
    $state = lps_accounting_price_campaign_state();
    foreach ($scopes as $scope_warehouse_id => $source_database) {
        $history = lps_accounting_price_campaign_effective_history($scope_warehouse_id, $state);
        $truncated = $truncated || !empty($history['issues_truncated']);
        foreach ((array)($history['issues'] ?? []) as $issue) {
            if (!is_array($issue)) continue;
            $code = lps_accounting_price_campaign_issue_code($issue);
            $is_negative = $code === 'NEGATIVE_CHRONOLOGICAL_STOCK';
            $is_error = lps_accounting_price_campaign_issue_is_error($issue);
            if ($kind === 'negative' && !$is_negative) continue;
            if ($kind === 'errors' && !$is_error) continue;
            $issue['warehouseId'] = $scope_warehouse_id;
            $issue['warehouseName'] = $names[$scope_warehouse_id] ?? (string)$scope_warehouse_id;
            $items[] = $issue;
        }

        $warehouse_error = sanitize_textarea_field((string)($warehouse_rows[$scope_warehouse_id]['lastError'] ?? ''));
        if ($kind !== 'negative' && $warehouse_error !== '') {
            $already_present = false;
            foreach ($items as $existing) {
                if (absint($existing['warehouseId'] ?? 0) === $scope_warehouse_id
                    && $warehouse_error === sanitize_textarea_field((string)($existing['message'] ?? ''))) {
                    $already_present = true;
                    break;
                }
            }
            if (!$already_present) {
                $items[] = [
                    'severity' => 'error',
                    'code' => 'WAREHOUSE_PROCESSING_ERROR',
                    'message' => $warehouse_error,
                    'details' => [],
                    'recordedAt' => sanitize_text_field((string)($history['completed_at'] ?? '')),
                    'warehouseId' => $scope_warehouse_id,
                    'warehouseName' => $names[$scope_warehouse_id] ?? (string)$scope_warehouse_id,
                ];
            }
        }

        if ($kind !== 'negative' && $source_database !== '') {
            global $wpdb;
            $tables = lps_accounting_price_campaign_snapshot_tables();
            $failed_items = $wpdb->get_results($wpdb->prepare(
                "SELECT sku, product_name, last_error, last_observed_at
                 FROM {$tables['item']}
                 WHERE source_database = %s AND warehouse_id = %d AND verification_state = 'FAILED'
                 ORDER BY sku ASC",
                $source_database,
                $scope_warehouse_id
            ), ARRAY_A) ?: [];
            foreach ($failed_items as $failed_item) {
                $sku = (string)($failed_item['sku'] ?? '');
                $already_present = false;
                foreach ($items as $existing) {
                    $details = is_array($existing['details'] ?? null) ? $existing['details'] : [];
                    if (absint($existing['warehouseId'] ?? 0) === $scope_warehouse_id
                        && $sku !== ''
                        && $sku === (string)($existing['sku'] ?? ($details['sku'] ?? ($details['art'] ?? '')))) {
                        $already_present = true;
                        break;
                    }
                }
                if ($already_present) continue;
                $items[] = [
                    'severity' => 'error',
                    'code' => 'FAILED_SNAPSHOT_ITEM',
                    'message' => (string)($failed_item['last_error'] ?? __('The snapshot marks this product as failed.', 'lavka-price-sync')),
                    'sku' => $sku,
                    'details' => ['productName' => (string)($failed_item['product_name'] ?? '')],
                    'recordedAt' => (string)($failed_item['last_observed_at'] ?? ''),
                    'warehouseId' => $scope_warehouse_id,
                    'warehouseName' => $names[$scope_warehouse_id] ?? (string)$scope_warehouse_id,
                ];
            }
        }
    }

    usort($items, static function (array $left, array $right): int {
        return strcmp((string)($right['recordedAt'] ?? ''), (string)($left['recordedAt'] ?? ''));
    });
    $page = max(1, $page);
    $per_page = in_array($per_page, [20, 50, 100], true) ? $per_page : 50;
    $total = count($items);
    $pages = max(1, (int)ceil($total / $per_page));
    $page = min($page, $pages);

    return [
        'ok' => true,
        'kind' => $kind,
        'warehouseId' => $warehouse_id,
        'items' => array_slice($items, ($page - 1) * $per_page, $per_page),
        'total' => $total,
        'page' => $page,
        'pages' => $pages,
        'perPage' => $per_page,
        'truncated' => $truncated,
    ];
}

function lps_accounting_price_campaign_select_skus(
    string $source_database,
    int $warehouse_id,
    string $cursor,
    int $limit,
    bool $include_unverified
): array {
    global $wpdb;
    $tables = lps_accounting_price_campaign_snapshot_tables();
    $states = $include_unverified
        ? "'UNVERIFIED','NEW','DIRTY'"
        : "'NEW','DIRTY'";
    $limit = max(1, min(500, $limit));

    $sql = "SELECT sku
            FROM {$tables['item']}
            WHERE source_database = %s
              AND warehouse_id = %d
              AND present_in_folio = 1
              AND verification_state IN ({$states})";
    $args = [$source_database, $warehouse_id];
    if ($cursor !== '') {
        $sql .= ' AND sku > %s';
        $args[] = $cursor;
    }
    $sql .= ' ORDER BY sku ASC LIMIT %d';
    $args[] = $limit;

    $rows = $wpdb->get_col($wpdb->prepare($sql, ...$args));
    return array_values(array_filter(array_map('strval', (array)$rows), static fn(string $sku): bool => $sku !== ''));
}

function lps_accounting_price_campaign_report_states(): array {
    return ['NEW', 'DIRTY', 'FAILED', 'REMOVED'];
}

function lps_accounting_price_campaign_state_reason(string $verification_state): string {
    switch (strtoupper($verification_state)) {
        case 'NEW':
            return __('The product was first found in Folio and has not yet received a confirmed recalculation.', 'lavka-price-sync');
        case 'DIRTY':
            return __('The Folio movement fingerprint changed after the last confirmed recalculation.', 'lavka-price-sync');
        case 'FAILED':
            return __('The product recalculation failed. Review the saved error and correct the Folio data before retrying.', 'lavka-price-sync');
        case 'REMOVED':
            return __('The product existed in an earlier snapshot but is absent from the current Folio warehouse snapshot.', 'lavka-price-sync');
        default:
            return '';
    }
}

function lps_accounting_price_campaign_report_scope(?array $state = null): array {
    $state = $state ?? lps_accounting_price_campaign_state();
    $warehouse_id = absint($state['current_warehouse_id'] ?? 0);
    $source_database = sanitize_text_field((string)($state['source_database'] ?? ''));

    if ($warehouse_id > 0 && $source_database === '') {
        $generation = lps_accounting_price_campaign_latest_generation($warehouse_id);
        $source_database = sanitize_text_field((string)($generation['source_database'] ?? ''));
    }

    return [$source_database, $warehouse_id];
}

function lps_accounting_price_campaign_snapshot_items(
    string $verification_state,
    int $page = 1,
    int $per_page = 50,
    int $warehouse_id = 0,
    string $source_database = ''
): array {
    global $wpdb;

    $verification_state = strtoupper(sanitize_key($verification_state));
    if (!in_array($verification_state, lps_accounting_price_campaign_report_states(), true)) {
        return ['ok' => false, 'message' => __('Select a supported snapshot state.', 'lavka-price-sync')];
    }
    if (!lps_accounting_price_campaign_tables_ready()) {
        return ['ok' => false, 'message' => __('The Folio product snapshot tables are unavailable.', 'lavka-price-sync')];
    }

    $source_database = sanitize_text_field($source_database);
    if ($warehouse_id < 1) {
        [$source_database, $warehouse_id] = lps_accounting_price_campaign_report_scope();
    } elseif ($source_database === '') {
        $generation = lps_accounting_price_campaign_latest_generation($warehouse_id);
        $source_database = sanitize_text_field((string)($generation['source_database'] ?? ''));
    }
    if ($source_database === '' || $warehouse_id < 1) {
        return ['ok' => false, 'message' => __('No completed snapshot is available for this campaign.', 'lavka-price-sync')];
    }

    $tables = lps_accounting_price_campaign_snapshot_tables();
    $change_table = 'folio_product_snapshot_change';
    $page = max(1, $page);
    $per_page = in_array($per_page, [20, 50, 100, 500], true) ? $per_page : 50;
    $total = (int)$wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$tables['item']}
         WHERE source_database = %s AND warehouse_id = %d AND verification_state = %s",
        $source_database,
        $warehouse_id,
        $verification_state
    ));
    $pages = max(1, (int)ceil($total / $per_page));
    $page = min($page, $pages);
    $offset = ($page - 1) * $per_page;

    $items = $wpdb->get_results($wpdb->prepare(
        "SELECT sku, product_name, verification_state, present_in_folio,
                movement_count, first_movement_date, last_movement_date,
                first_seen_at, last_seen_at, last_observed_at, applied_at,
                last_generation_id, last_error, observed_digest, applied_digest
         FROM {$tables['item']}
         WHERE source_database = %s AND warehouse_id = %d AND verification_state = %s
         ORDER BY sku ASC LIMIT %d OFFSET %d",
        $source_database,
        $warehouse_id,
        $verification_state,
        $per_page,
        $offset
    ), ARRAY_A) ?: [];

    $changes_by_sku = [];
    $skus = array_values(array_filter(array_map(static fn(array $row): string => (string)($row['sku'] ?? ''), $items)));
    if ($skus) {
        $placeholders = implode(',', array_fill(0, count($skus), '%s'));
        $change_args = array_merge([$source_database, $warehouse_id], $skus);
        $changes = $wpdb->get_results($wpdb->prepare(
            "SELECT sku, change_type, detected_at, generation_id
             FROM {$change_table}
             WHERE source_database = %s AND warehouse_id = %d AND sku IN ({$placeholders})
             ORDER BY detected_at DESC, id DESC",
            ...$change_args
        ), ARRAY_A) ?: [];
        foreach ($changes as $change) {
            $sku = (string)($change['sku'] ?? '');
            if ($sku !== '' && !isset($changes_by_sku[$sku])) {
                $changes_by_sku[$sku] = $change;
            }
        }
    }

    foreach ($items as &$item) {
        $sku = (string)($item['sku'] ?? '');
        $item['latest_change'] = $changes_by_sku[$sku] ?? null;
        $item['digest_matches'] = !empty($item['observed_digest'])
            && hash_equals((string)$item['observed_digest'], (string)($item['applied_digest'] ?? ''));
        unset($item['observed_digest'], $item['applied_digest']);
    }
    unset($item);

    return [
        'ok' => true,
        'state' => $verification_state,
        'warehouseId' => $warehouse_id,
        'sourceDatabase' => $source_database,
        'items' => $items,
        'total' => $total,
        'page' => $page,
        'pages' => $pages,
        'perPage' => $per_page,
    ];
}

function lps_accounting_price_campaign_batch_size(array $state): int {
    $initial = max(1, min(500, absint($state['initial_batch_size'] ?? 100)));
    $maximum = max($initial, min(500, absint($state['max_batch_size'] ?? 500)));
    $remaining = max(0, absint($state['deadline_at'] ?? 0) - time());
    if ($remaining < 5 * MINUTE_IN_SECONDS) return 0;

    $seconds_per_sku = (float)($state['seconds_per_sku'] ?? 0);
    if ($seconds_per_sku <= 0) {
        $seconds_per_sku = 3.0;
        $maximum = min($maximum, $initial);
    }
    $safe = (int)floor(($remaining * 0.70) / max(0.1, $seconds_per_sku));
    return max(0, min($maximum, $safe));
}

function lps_accounting_price_campaign_append_report(array &$state, array $report): void {
    $reports = is_array($state['reports'] ?? null) ? $state['reports'] : [];
    $report['completed_at'] = current_time('mysql');
    $reports[] = lps_accounting_prices_native_sanitize_report_value($report);
    $state['reports'] = array_slice($reports, -LPS_ACCOUNTING_PRICE_CAMPAIGN_MAX_REPORTS);
}

function lps_accounting_price_campaign_append_warnings(array &$state, array $warnings): void {
    $stored = is_array($state['warnings'] ?? null) ? $state['warnings'] : [];
    foreach (lps_accounting_prices_native_sanitize_issues($warnings) as $warning) $stored[] = $warning;
    if (count($stored) > LPS_ACCOUNTING_PRICE_CAMPAIGN_MAX_WARNINGS) {
        $state['warnings_truncated'] = true;
    }
    $state['warnings'] = array_slice($stored, -LPS_ACCOUNTING_PRICE_CAMPAIGN_MAX_WARNINGS);
}

function lps_accounting_price_campaign_deadline_reached(array $state): bool {
    $deadline = absint($state['deadline_at'] ?? 0);
    return $deadline > 0 && time() >= $deadline;
}

function lps_accounting_price_campaign_snapshot_was_interrupted(array $status): bool {
    if (!empty($status['running'])) return false;

    $snapshot_status = strtoupper(sanitize_key((string)($status['status'] ?? '')));
    $snapshot_phase = strtoupper(sanitize_key((string)($status['phase'] ?? '')));
    return $snapshot_phase === 'RECOVERY_REQUIRED'
        || in_array($snapshot_status, ['INTERRUPTED', 'QUEUED', 'BUILDING'], true);
}

function lps_accounting_price_campaign_public_state(?array $state = null): array {
    $state = $state ?? lps_accounting_price_campaign_state();
    $public = [
        'active' => !empty($state['active']),
        'campaignId' => sanitize_text_field((string)($state['campaign_id'] ?? '')),
        'source' => sanitize_key((string)($state['source'] ?? '')),
        'status' => strtoupper(sanitize_key((string)($state['status'] ?? 'IDLE'))),
        'phase' => strtoupper(sanitize_key((string)($state['phase'] ?? 'IDLE'))),
        'warehouseIds' => lps_accounting_prices_native_normalize_warehouse_ids($state['warehouse_ids'] ?? []),
        'warehouseIndex' => absint($state['warehouse_index'] ?? 0),
        'currentWarehouseId' => absint($state['current_warehouse_id'] ?? 0),
        'sourceDatabase' => sanitize_text_field((string)($state['source_database'] ?? '')),
        'initialMode' => !empty($state['include_unverified']),
        'currentBatchSize' => count((array)($state['current_skus'] ?? [])),
        'currentSkus' => array_values(array_map('strval', (array)($state['current_skus'] ?? []))),
        'processedSkus' => absint($state['processed_skus'] ?? 0),
        'successfulBatches' => absint($state['successful_batches'] ?? 0),
        'warningCount' => absint($state['warning_count'] ?? 0),
        'errorCount' => absint($state['error_count'] ?? 0),
        'failedWarehouses' => absint($state['failed_warehouses'] ?? 0),
        'countsBefore' => is_array($state['counts_before'] ?? null) ? $state['counts_before'] : [],
        'countsAfter' => is_array($state['counts_after'] ?? null) ? $state['counts_after'] : [],
        'snapshot' => is_array($state['snapshot_status'] ?? null) ? $state['snapshot_status'] : [],
        'range' => is_array($state['range_status'] ?? null) ? $state['range_status'] : [],
        'reports' => is_array($state['reports'] ?? null) ? $state['reports'] : [],
        'warnings' => is_array($state['warnings'] ?? null) ? $state['warnings'] : [],
        'warningsTruncated' => !empty($state['warnings_truncated']),
        'stopRequested' => !empty($state['stop_requested']),
        'startedAt' => sanitize_text_field((string)($state['started_at'] ?? '')),
        'completedAt' => sanitize_text_field((string)($state['completed_at'] ?? '')),
        'deadlineAt' => absint($state['deadline_at'] ?? 0),
        'error' => sanitize_textarea_field((string)($state['error'] ?? '')),
        'message' => sanitize_textarea_field((string)($state['message'] ?? '')),
        'nextTickAt' => (int)(wp_next_scheduled(LPS_ACCOUNTING_PRICE_CAMPAIGN_TICK_HOOK) ?: 0),
    ];
    return $public;
}

function lps_accounting_price_campaign_create(array $warehouse_ids, string $source = 'manual'): array {
    $warehouse_ids = lps_accounting_prices_native_normalize_warehouse_ids($warehouse_ids);
    if (!$warehouse_ids) {
        return ['ok' => false, 'httpStatus' => 400, 'message' => __('Select at least one Folio warehouse.', 'lavka-price-sync')];
    }
    if (!lps_accounting_price_campaign_tables_ready()) {
        return ['ok' => false, 'httpStatus' => 503, 'message' => __('The Folio product snapshot tables are unavailable. Build or migrate the snapshot storage first.', 'lavka-price-sync')];
    }

    $existing = lps_accounting_price_campaign_state();
    if (!empty($existing['active'])) {
        return ['ok' => false, 'httpStatus' => 409, 'message' => __('An accounting-price SKU campaign is already running.', 'lavka-price-sync'), 'state' => lps_accounting_price_campaign_public_state($existing)];
    }
    $native_job = lps_accounting_prices_native_job_state();
    if (!empty($native_job['running'])) {
        return ['ok' => false, 'httpStatus' => 409, 'message' => __('Another Folio accounting-price job is already running.', 'lavka-price-sync')];
    }
    if (!empty($existing['campaign_id'])) {
        lps_accounting_price_campaign_persist_all_warehouse_history($existing);
    }

    $options = lps_accounting_prices_native_cron_options();
    $window_minutes = max(30, min(720, absint($options['campaign_window_minutes'] ?? 240)));
    $state = [
        'campaign_id' => wp_generate_uuid4(),
        'active' => true,
        'source' => in_array($source, ['manual', 'cron', 'recovery'], true) ? $source : 'manual',
        'status' => 'running',
        'phase' => 'snapshot_before_start',
        'warehouse_ids' => $warehouse_ids,
        'warehouse_index' => 0,
        'current_warehouse_id' => $warehouse_ids[0],
        'source_database' => '',
        'cursor' => '',
        'current_skus' => [],
        'range_job_id' => '',
        'snapshot_generation_id' => 0,
        'snapshot_stage' => 'before',
        'initial_batch_size' => max(1, min(500, absint($options['campaign_initial_batch_size'] ?? 100))),
        'max_batch_size' => max(1, min(500, absint($options['campaign_max_batch_size'] ?? 500))),
        'horizon_months' => max(12, min(36, absint($options['campaign_horizon_months'] ?? 24))),
        'deadline_at' => time() + $window_minutes * MINUTE_IN_SECONDS,
        'processed_skus' => 0,
        'successful_batches' => 0,
        'warning_count' => 0,
        'error_count' => 0,
        'failed_warehouses' => 0,
        'warnings_truncated' => false,
        'warnings' => [],
        'reports' => [],
        'counts_before' => [],
        'counts_after' => [],
        'stop_requested' => false,
        'lock_token' => '',
        'poll_errors' => 0,
        'started_at' => current_time('mysql'),
        'started_at_gmt' => current_time('mysql', true),
        'warehouse_started_at' => current_time('mysql'),
        'completed_at' => '',
        'error' => '',
        'message' => __('Preparing the first Folio product snapshot.', 'lavka-price-sync'),
    ];
    lps_accounting_price_campaign_store($state);
    lps_accounting_price_campaign_log('accounting_price_campaign_started', [
        'source' => $state['source'],
        'message' => 'Accounting-price SKU campaign started.',
        'context' => ['campaign_id' => $state['campaign_id'], 'warehouse_ids' => $warehouse_ids],
    ]);
    lps_accounting_price_campaign_schedule_tick(1);
    return ['ok' => true, 'httpStatus' => 202, 'message' => __('The SKU campaign was accepted.', 'lavka-price-sync'), 'state' => lps_accounting_price_campaign_public_state($state)];
}

function lps_accounting_price_campaign_start_snapshot(array &$state, string $stage): void {
    if (($state['snapshot_attempt_stage'] ?? '') !== $stage) {
        $previous = lps_accounting_price_campaign_latest_generation(absint($state['current_warehouse_id'] ?? 0));
        $state['snapshot_previous_generation_id'] = absint($previous['id'] ?? 0);
        $state['snapshot_attempt_stage'] = $stage;
        $state['snapshot_start_uncertain'] = false;
    }
    $state['snapshot_stage'] = $stage;
    $lock = lps_accounting_price_campaign_acquire_lock($state, 'snapshot_' . $stage);
    if (empty($lock['ok'])) {
        $state['phase'] = 'waiting_lock';
        $state['waiting_lock'] = true;
        $state['message'] = (string)($lock['message'] ?? __('Waiting for the global Lavka lock.', 'lavka-price-sync'));
        lps_accounting_price_campaign_store($state);
        lps_accounting_price_campaign_schedule_tick(5 * MINUTE_IN_SECONDS);
        return;
    }

    $warehouse_id = absint($state['current_warehouse_id'] ?? 0);
    $response = lps_java_post(LPS_ACCOUNTING_PRICE_CAMPAIGN_SNAPSHOT_PATH, [
        'warehouseId' => $warehouse_id,
        'horizonMonths' => absint($state['horizon_months'] ?? 24),
    ], ['timeout' => 30]);
    $result = lps_accounting_prices_native_decode_response($response);
    $body = is_array($result['body'] ?? null) ? $result['body'] : [];
    if (!$result['ok'] || empty($body['accepted'])) {
        if (in_array((int)$result['httpStatus'], [400, 403, 404, 422], true)) {
            lps_accounting_price_campaign_release_lock($state);
            $state['active'] = false;
            $state['status'] = 'manual_review';
            $state['phase'] = 'snapshot_start_blocked';
            $state['error'] = sanitize_textarea_field((string)($body['message'] ?? __('The Folio product snapshot request was rejected. Check the Java configuration before retrying.', 'lavka-price-sync')));
            $state['completed_at'] = current_time('mysql');
            lps_accounting_price_campaign_store($state);
            lps_accounting_prices_native_pause_schedule($state['error']);
            return;
        }
        $body_warehouse = absint($body['warehouseId'] ?? 0);
        if (!empty($body['running']) && $body_warehouse === $warehouse_id) {
            $state['snapshot_status'] = lps_accounting_prices_native_sanitize_report_value($body);
            $state['snapshot_start_uncertain'] = false;
            $state['phase'] = 'snapshot_' . $stage . '_poll';
            $state['message'] = __('The Folio product snapshot is already running. Waiting for its result without sending a duplicate request.', 'lavka-price-sync');
            lps_accounting_price_campaign_store($state);
            lps_accounting_price_campaign_schedule_tick(15);
            return;
        }

        $state['snapshot_start_uncertain'] = true;
        $state['phase'] = 'snapshot_' . $stage . '_poll';
        $state['message'] = sanitize_textarea_field((string)($body['message'] ?? __('The snapshot start result is unknown. Checking status without repeating the request.', 'lavka-price-sync')));
        lps_accounting_price_campaign_store($state);
        lps_accounting_price_campaign_schedule_tick(30);
        return;
    }

    $state['snapshot_generation_id'] = absint($body['generationId'] ?? 0);
    $state['snapshot_start_uncertain'] = false;
    $state['snapshot_status'] = lps_accounting_prices_native_sanitize_report_value($body);
    $state['phase'] = 'snapshot_' . $stage . '_poll';
    $state['message'] = __('The Folio product snapshot is being built.', 'lavka-price-sync');
    lps_accounting_price_campaign_store($state);
    lps_accounting_price_campaign_schedule_tick(10);
}

function lps_accounting_price_campaign_poll_snapshot(array &$state): void {
    $response = lps_java_get(LPS_ACCOUNTING_PRICE_CAMPAIGN_SNAPSHOT_STATUS_PATH, ['timeout' => 30]);
    $result = lps_accounting_prices_native_decode_response($response);
    if (!$result['ok']) {
        $state['poll_errors'] = absint($state['poll_errors'] ?? 0) + 1;
        if (empty($state['first_poll_error_at'])) $state['first_poll_error_at'] = time();
        if (time() - absint($state['first_poll_error_at']) >= LPS_ACCOUNTING_PRICES_NATIVE_POLL_OUTAGE_LIMIT) {
            lps_accounting_price_campaign_release_lock($state);
            $state['active'] = false;
            $state['status'] = 'manual_review';
            $state['phase'] = 'snapshot_status_unavailable';
            $state['error'] = __('The snapshot status remained unavailable for two hours. Automatic continuation was stopped.', 'lavka-price-sync');
            $state['completed_at'] = current_time('mysql');
            lps_accounting_price_campaign_store($state);
            lps_accounting_prices_native_pause_schedule($state['error']);
            return;
        }
        $state['message'] = __('The snapshot status is temporarily unavailable. Waiting without starting another job.', 'lavka-price-sync');
        lps_accounting_price_campaign_touch_lock($state, ['phase' => 'snapshot_poll_error', 'errors' => $state['poll_errors']]);
        lps_accounting_price_campaign_store($state);
        lps_accounting_price_campaign_schedule_tick(60);
        return;
    }

    $body = is_array($result['body'] ?? null) ? $result['body'] : [];
    $state['snapshot_status'] = lps_accounting_prices_native_sanitize_report_value($body);
    $state['poll_errors'] = 0;
    $state['first_poll_error_at'] = 0;
    $status_warehouse = absint($body['warehouseId'] ?? 0);
    $snapshot_status = strtoupper((string)($body['status'] ?? ''));
    $snapshot_running = !empty($body['running']);
    if (lps_accounting_price_campaign_snapshot_was_interrupted($body)) {
        lps_accounting_price_campaign_release_lock($state);
        $state['active'] = false;
        $state['status'] = 'manual_review';
        $state['phase'] = 'snapshot_interrupted';
        $state['error'] = __('The product snapshot was interrupted by a Java restart. No process is active. Start the campaign again; the previous active snapshot remains available.', 'lavka-price-sync');
        $state['message'] = $state['error'];
        $state['completed_at'] = current_time('mysql');
        lps_accounting_price_campaign_store($state);
        lps_accounting_price_campaign_clear_ticks();
        lps_accounting_prices_native_pause_schedule($state['error']);
        return;
    }
    if ($snapshot_running) {
        if ($status_warehouse > 0 && $status_warehouse !== absint($state['current_warehouse_id'] ?? 0)) {
            lps_accounting_price_campaign_release_lock($state);
            $state['phase'] = 'waiting_snapshot';
            $state['message'] = __('Another Folio warehouse snapshot is running. The campaign will wait without sending a duplicate request.', 'lavka-price-sync');
            lps_accounting_price_campaign_store($state);
            lps_accounting_price_campaign_schedule_tick(2 * MINUTE_IN_SECONDS);
            return;
        }
        lps_accounting_price_campaign_touch_lock($state, [
            'phase' => $body['phase'] ?? 'snapshot_building',
            'warehouse_id' => $state['current_warehouse_id'] ?? 0,
        ]);
        lps_accounting_price_campaign_store($state);
        lps_accounting_price_campaign_schedule_tick(15);
        return;
    }

    lps_accounting_price_campaign_release_lock($state);
    $status = $snapshot_status;
    $generation_id = absint($body['generationId'] ?? 0);
    if (!empty($state['snapshot_start_uncertain'])
        && $generation_id <= absint($state['snapshot_previous_generation_id'] ?? 0)) {
        $state['snapshot_start_uncertain'] = false;
        $state['phase'] = 'waiting_snapshot';
        $state['message'] = __('The previous snapshot is still active and no new snapshot was started. A new request will be attempted later.', 'lavka-price-sync');
        lps_accounting_price_campaign_store($state);
        lps_accounting_price_campaign_schedule_tick(2 * MINUTE_IN_SECONDS);
        return;
    }
    if ($status !== 'ACTIVE') {
        $state['status'] = 'manual_review';
        $state['phase'] = 'snapshot_failed';
        $state['active'] = false;
        $state['error'] = sanitize_textarea_field((string)($body['error'] ?? __('The Folio product snapshot did not become active.', 'lavka-price-sync')));
        $state['completed_at'] = current_time('mysql');
        lps_accounting_price_campaign_store($state);
        lps_accounting_prices_native_pause_schedule($state['error']);
        return;
    }

    $warehouse_id = absint($state['current_warehouse_id'] ?? 0);
    $generation = lps_accounting_price_campaign_latest_generation($warehouse_id);
    $source_database = sanitize_text_field((string)($body['sourceDatabase'] ?? ($generation['source_database'] ?? '')));
    if ($source_database === '') {
        $state['status'] = 'manual_review';
        $state['phase'] = 'snapshot_storage_mismatch';
        $state['active'] = false;
        $state['error'] = __('The active snapshot was built, but its source database could not be resolved in WordPress.', 'lavka-price-sync');
        $state['completed_at'] = current_time('mysql');
        lps_accounting_price_campaign_store($state);
        lps_accounting_prices_native_pause_schedule($state['error']);
        return;
    }

    $state['source_database'] = $source_database;
    $state['snapshot_start_uncertain'] = false;
    $state['snapshot_generation_id'] = $generation_id;
    $counts = lps_accounting_price_campaign_counts($source_database, $warehouse_id);
    if (($state['snapshot_stage'] ?? 'before') === 'before') {
        $state['counts_before'] = $counts;
        $state['include_unverified'] = ($counts['UNVERIFIED'] ?? 0) > 0;
        $state['cursor'] = '';
        $state['phase'] = 'select_batch';
        $state['message'] = __('The fresh snapshot is ready. Selecting products that require recalculation.', 'lavka-price-sync');
    } else {
        $state['counts_after'] = $counts;
        lps_accounting_price_campaign_finish_warehouse($state);
        return;
    }
    lps_accounting_price_campaign_store($state);
    lps_accounting_price_campaign_schedule_tick(1);
}

function lps_accounting_price_campaign_request_matches(array $body, array $state): bool {
    $request = is_array($body['request'] ?? null) ? $body['request'] : [];
    if (absint($request['warehouseId'] ?? 0) !== absint($state['current_warehouse_id'] ?? 0)) return false;
    $expected = array_values(array_map('strval', (array)($state['current_skus'] ?? [])));
    $actual = array_values(array_map('strval', (array)($request['skus'] ?? [])));
    sort($expected, SORT_STRING);
    sort($actual, SORT_STRING);
    return $expected !== [] && $expected === $actual;
}

function lps_accounting_price_campaign_start_range(array &$state, array $skus): void {
    $state['current_skus'] = array_values($skus);
    $lock = lps_accounting_price_campaign_acquire_lock($state, 'native_range');
    if (empty($lock['ok'])) {
        $state['phase'] = 'waiting_lock';
        $state['waiting_lock'] = true;
        $state['message'] = (string)($lock['message'] ?? __('Waiting for the global Lavka lock.', 'lavka-price-sync'));
        lps_accounting_price_campaign_store($state);
        lps_accounting_price_campaign_schedule_tick(5 * MINUTE_IN_SECONDS);
        return;
    }

    $state['range_started_at_unix'] = time();
    $state['phase'] = 'range_starting';
    lps_accounting_price_campaign_store($state);

    $response = lps_java_post(LPS_ACCOUNTING_PRICE_CAMPAIGN_RANGE_PATH, [
        'warehouseId' => absint($state['current_warehouse_id'] ?? 0),
        'skus' => array_values($skus),
        'previewOnly' => false,
        'confirmApply' => true,
    ], ['timeout' => 30]);
    $result = lps_accounting_prices_native_decode_response($response);
    $body = is_array($result['body'] ?? null) ? $result['body'] : [];
    $accepted = $result['httpStatus'] === 202 && !empty($body['accepted']) && !empty($body['jobId']);

    if (!$accepted) {
        if ((int)$result['httpStatus'] === 409) {
            $status_response = lps_java_get(LPS_ACCOUNTING_PRICE_CAMPAIGN_RANGE_STATUS_PATH, ['timeout' => 30]);
            $status_result = lps_accounting_prices_native_decode_response($status_response);
            $status_body = is_array($status_result['body'] ?? null) ? $status_result['body'] : [];
            if ($status_result['ok'] && !empty($status_body['running']) && lps_accounting_price_campaign_request_matches($status_body, $state)) {
                $body = $status_body;
                $accepted = true;
            }
        }

        if ((int)$result['httpStatus'] === 409 && !$accepted) {
            lps_accounting_price_campaign_release_lock($state);
            $state['phase'] = 'waiting_java_slot';
            $state['message'] = __('The Folio accounting-price slot is busy. No duplicate request was sent; the campaign will check again later.', 'lavka-price-sync');
            lps_accounting_price_campaign_store($state);
            lps_accounting_price_campaign_schedule_tick(2 * MINUTE_IN_SECONDS);
            return;
        }

        if (!$accepted) {
            $status_response = lps_java_get(LPS_ACCOUNTING_PRICE_CAMPAIGN_RANGE_STATUS_PATH, ['timeout' => 30]);
            $status_result = lps_accounting_prices_native_decode_response($status_response);
            $status_body = is_array($status_result['body'] ?? null) ? $status_result['body'] : [];
            if ($status_result['ok'] && !empty($status_body['running']) && lps_accounting_price_campaign_request_matches($status_body, $state)) {
                $body = $status_body;
                $accepted = true;
            }
        }
    }

    if (!$accepted) {
        lps_accounting_price_campaign_release_lock($state);
        $state['active'] = false;
        $state['status'] = 'outcome_unknown';
        $state['phase'] = 'manual_review';
        $state['error'] = sanitize_textarea_field((string)($body['message'] ?? __('The native-range start outcome could not be proven. Do not retry automatically.', 'lavka-price-sync')));
        $state['completed_at'] = current_time('mysql');
        lps_accounting_price_campaign_store($state);
        lps_accounting_prices_native_pause_schedule($state['error']);
        return;
    }

    $state['range_job_id'] = sanitize_text_field((string)($body['jobId'] ?? ''));
    $state['range_status'] = lps_accounting_prices_native_sanitize_report_value($body);
    $state['phase'] = 'range_poll';
    $state['message'] = __('The selected SKU batch is being recalculated in Folio.', 'lavka-price-sync');
    lps_accounting_price_campaign_store($state);
    lps_accounting_price_campaign_schedule_tick(15);
}

function lps_accounting_price_campaign_poll_range(array &$state): void {
    $response = lps_java_get(LPS_ACCOUNTING_PRICE_CAMPAIGN_RANGE_STATUS_PATH, ['timeout' => 30]);
    $result = lps_accounting_prices_native_decode_response($response);
    if (!$result['ok']) {
        $state['poll_errors'] = absint($state['poll_errors'] ?? 0) + 1;
        if (empty($state['first_poll_error_at'])) $state['first_poll_error_at'] = time();
        if (time() - absint($state['first_poll_error_at']) >= LPS_ACCOUNTING_PRICES_NATIVE_POLL_OUTAGE_LIMIT) {
            lps_accounting_price_campaign_release_lock($state);
            $state['active'] = false;
            $state['status'] = 'outcome_unknown';
            $state['phase'] = 'manual_review';
            $state['error'] = __('The native-range status remained unavailable for two hours. Do not retry automatically; verify Folio manually.', 'lavka-price-sync');
            $state['completed_at'] = current_time('mysql');
            lps_accounting_price_campaign_store($state);
            lps_accounting_prices_native_pause_schedule($state['error']);
            return;
        }
        $state['message'] = __('The native-range status is temporarily unavailable. Waiting without repeating apply.', 'lavka-price-sync');
        lps_accounting_price_campaign_touch_lock($state, ['phase' => 'range_poll_error', 'errors' => $state['poll_errors']]);
        lps_accounting_price_campaign_store($state);
        lps_accounting_price_campaign_schedule_tick(60);
        return;
    }

    $body = is_array($result['body'] ?? null) ? $result['body'] : [];
    $job_id = sanitize_text_field((string)($body['jobId'] ?? ''));
    $expected_job_id = sanitize_text_field((string)($state['range_job_id'] ?? ''));
    if ($expected_job_id !== '' && $job_id !== '' && $expected_job_id !== $job_id) {
        lps_accounting_price_campaign_release_lock($state);
        $state['active'] = false;
        $state['status'] = 'outcome_unknown';
        $state['phase'] = 'manual_review';
        $state['error'] = __('The Java service returned a different native-range job. Manual review is required.', 'lavka-price-sync');
        $state['completed_at'] = current_time('mysql');
        lps_accounting_price_campaign_store($state);
        lps_accounting_prices_native_pause_schedule($state['error']);
        return;
    }

    $state['range_status'] = lps_accounting_prices_native_sanitize_report_value($body);
    $state['poll_errors'] = 0;
    $state['first_poll_error_at'] = 0;
    if (!empty($body['running']) || strtoupper((string)($body['status'] ?? '')) === 'QUEUED') {
        lps_accounting_price_campaign_touch_lock($state, [
            'phase' => $body['phase'] ?? 'range_running',
            'progress_percent' => $body['progressPercent'] ?? null,
            'current_art' => $body['currentArt'] ?? '',
        ]);
        lps_accounting_price_campaign_store($state);
        lps_accounting_price_campaign_schedule_tick(30);
        return;
    }

    lps_accounting_price_campaign_release_lock($state);
    $status = strtoupper((string)($body['status'] ?? ''));
    $skus = array_values(array_map('strval', (array)($state['current_skus'] ?? [])));
    $elapsed = max(1, time() - absint($state['range_started_at_unix'] ?? time()));
    $warnings = is_array($body['warnings'] ?? null) ? $body['warnings'] : [];
    $errors = is_array($body['errors'] ?? null) ? $body['errors'] : [];
    $failed_chunk = is_array($body['failedChunk'] ?? null) ? $body['failedChunk'] : [];
    $recorded_at = current_time('mysql');
    $recorded_warehouse_id = absint($state['current_warehouse_id'] ?? 0);
    foreach ($warnings as &$warning) {
        if (!is_array($warning)) $warning = ['message' => (string)$warning];
        if (empty($warning['severity'])) $warning['severity'] = 'warning';
        $warning['recordedAt'] = $recorded_at;
        $warning['warehouseId'] = $recorded_warehouse_id;
        $warning['jobId'] = $job_id;
    }
    unset($warning);
    foreach ($errors as &$error) {
        if (!is_array($error)) $error = ['message' => (string)$error];
        if (empty($error['severity'])) $error['severity'] = 'error';
        $error['recordedAt'] = $recorded_at;
        $error['warehouseId'] = $recorded_warehouse_id;
        $error['jobId'] = $job_id;
    }
    unset($error);
    lps_accounting_price_campaign_append_warnings($state, $warnings);
    lps_accounting_price_campaign_append_warnings($state, $errors);
    if ($failed_chunk) {
        lps_accounting_price_campaign_append_warnings($state, [[
            'severity' => 'error',
            'code' => 'FAILED_CHUNK',
            'message' => __('The native-range job rejected a chunk. Automatic retry is disabled.', 'lavka-price-sync'),
            'details' => $failed_chunk,
            'recordedAt' => $recorded_at,
            'warehouseId' => $recorded_warehouse_id,
            'jobId' => $job_id,
        ]]);
    }
    $state['warnings_truncated'] = !empty($state['warnings_truncated']) || !empty($body['warningsTruncated']);
    $state['warning_count'] = absint($state['warning_count'] ?? 0) + absint($body['warningCount'] ?? count($warnings));
    $state['error_count'] = absint($state['error_count'] ?? 0) + count($errors) + ($failed_chunk ? 1 : 0);
    lps_accounting_price_campaign_append_report($state, [
        'warehouse_id' => absint($state['current_warehouse_id'] ?? 0),
        'job_id' => $job_id,
        'status' => $status,
        'sku_count' => count($skus),
        'first_sku' => $skus[0] ?? '',
        'last_sku' => $skus ? end($skus) : '',
        'duration_seconds' => $elapsed,
        'warning_count' => absint($body['warningCount'] ?? count($warnings)),
        'warnings_truncated' => !empty($body['warningsTruncated']),
        'failed_chunk' => $failed_chunk ?: null,
        'error' => $body['error'] ?? '',
    ]);

    if (in_array($status, ['COMPLETED', 'COMPLETED_WITH_WARNINGS'], true)) {
        $count = count($skus);
        $state['processed_skus'] = absint($state['processed_skus'] ?? 0) + $count;
        $state['successful_batches'] = absint($state['successful_batches'] ?? 0) + 1;
        if ($count > 0) {
            $sample = $elapsed / $count;
            $previous = (float)($state['seconds_per_sku'] ?? 0);
            $state['seconds_per_sku'] = $previous > 0 ? ($previous * 0.6 + $sample * 0.4) : $sample;
            $state['cursor'] = (string)end($skus);
        }
        $state['current_skus'] = [];
        $state['range_job_id'] = '';
        $state['phase'] = 'select_batch';
        $state['message'] = $status === 'COMPLETED_WITH_WARNINGS'
            ? __('The safe products were recalculated; problem products were recorded and skipped.', 'lavka-price-sync')
            : __('The SKU batch was recalculated successfully.', 'lavka-price-sync');
        lps_accounting_price_campaign_store($state);
        lps_accounting_price_campaign_schedule_tick(1);
        return;
    }

    if (in_array($status, ['FAILED_PARTIAL', 'OUTCOME_UNKNOWN'], true)) {
        $state['active'] = false;
        $state['status'] = strtolower($status);
        $state['phase'] = 'manual_review';
        $state['error'] = sanitize_textarea_field((string)($body['error'] ?? __('The SKU campaign requires a manual Folio review before any retry.', 'lavka-price-sync')));
        $state['completed_at'] = current_time('mysql');
        lps_accounting_price_campaign_store($state);
        lps_accounting_prices_native_pause_schedule($state['error']);
        return;
    }

    $state['failed_warehouses'] = absint($state['failed_warehouses'] ?? 0) + 1;
    $state['warehouse_failed'] = true;
    $state['warehouse_error'] = sanitize_textarea_field((string)($body['error'] ?? __('The current warehouse failed. The campaign will verify its snapshot and continue with the next warehouse.', 'lavka-price-sync')));
    $state['phase'] = 'snapshot_after_start';
    $state['message'] = $state['warehouse_error'];
    lps_accounting_price_campaign_store($state);
    lps_accounting_price_campaign_schedule_tick(1);
}

function lps_accounting_price_campaign_finish_warehouse(array &$state): void {
    $warehouse_id = absint($state['current_warehouse_id'] ?? 0);
    lps_accounting_price_campaign_append_report($state, [
        'warehouse_id' => $warehouse_id,
        'status' => !empty($state['warehouse_failed']) ? 'WAREHOUSE_FAILED' : 'SNAPSHOT_CONFIRMED',
        'sku_count' => 0,
        'counts_before' => $state['counts_before'] ?? [],
        'counts_after' => $state['counts_after'] ?? [],
        'error' => $state['warehouse_error'] ?? '',
    ]);
    lps_accounting_price_campaign_persist_warehouse_history($state, $warehouse_id);

    $state['warehouse_index'] = absint($state['warehouse_index'] ?? 0) + 1;
    $warehouse_ids = lps_accounting_prices_native_normalize_warehouse_ids($state['warehouse_ids'] ?? []);
    $deadline_reached = lps_accounting_price_campaign_deadline_reached($state);
    if ($state['warehouse_index'] >= count($warehouse_ids) || !empty($state['stop_requested']) || $deadline_reached) {
        $state['active'] = false;
        $state['status'] = (!empty($state['stop_requested']) || $deadline_reached)
            ? 'paused'
            : (($state['warning_count'] ?? 0) > 0 || ($state['failed_warehouses'] ?? 0) > 0 ? 'completed_with_warnings' : 'completed');
        $state['phase'] = 'completed';
        $state['completed_at'] = current_time('mysql');
        if (!empty($state['stop_requested'])) {
            $state['message'] = __('The SKU campaign stopped safely after the current operation and final snapshot.', 'lavka-price-sync');
        } elseif ($deadline_reached) {
            $state['message'] = __('The maintenance window ended. The campaign stopped safely after the mandatory final snapshot; remaining warehouses will be handled by a later campaign.', 'lavka-price-sync');
        } else {
            $state['message'] = __('The accounting-price SKU campaign completed.', 'lavka-price-sync');
        }
        lps_accounting_price_campaign_store($state);
        lps_accounting_price_campaign_clear_ticks();
        lps_accounting_price_campaign_log('accounting_price_campaign_finished', [
            'source' => $state['source'] ?? 'manual',
            'message' => 'Accounting-price SKU campaign finished.',
            'context' => [
                'campaign_id' => $state['campaign_id'] ?? '',
                'status' => $state['status'],
                'processed_skus' => $state['processed_skus'] ?? 0,
                'warning_count' => $state['warning_count'] ?? 0,
            ],
        ]);
        return;
    }

    $state['current_warehouse_id'] = $warehouse_ids[$state['warehouse_index']];
    $state['source_database'] = '';
    $state['cursor'] = '';
    $state['current_skus'] = [];
    $state['range_job_id'] = '';
    $state['snapshot_generation_id'] = 0;
    $state['snapshot_stage'] = 'before';
    $state['counts_before'] = [];
    $state['counts_after'] = [];
    $state['warehouse_failed'] = false;
    $state['warehouse_error'] = '';
    $state['warehouse_started_at'] = current_time('mysql');
    $state['phase'] = 'snapshot_before_start';
    $state['message'] = __('Preparing the next Folio warehouse snapshot.', 'lavka-price-sync');
    lps_accounting_price_campaign_store($state);
    lps_accounting_price_campaign_schedule_tick(1);
}

function lps_accounting_price_campaign_tick(): array {
    $state = lps_accounting_price_campaign_state();
    if (empty($state['active'])) return lps_accounting_price_campaign_public_state($state);

    $phase = sanitize_key((string)($state['phase'] ?? 'snapshot_before_start'));
    if (in_array($phase, ['waiting_lock', 'waiting_java_slot', 'range_starting'], true)
        && !empty($state['current_skus'])
        && (!empty($state['stop_requested']) || lps_accounting_price_campaign_deadline_reached($state))) {
        $state['current_skus'] = [];
        $state['range_job_id'] = '';
        $state['phase'] = 'snapshot_after_start';
        $state['message'] = __('No new SKU batch was accepted because the maintenance window is ending. Building the mandatory final snapshot.', 'lavka-price-sync');
        lps_accounting_price_campaign_store($state);
        lps_accounting_price_campaign_schedule_tick(1);
        return lps_accounting_price_campaign_public_state($state);
    }
    if ($phase === 'waiting_lock') {
        $operation = !empty($state['current_skus']) ? 'native_range' : 'snapshot_' . ($state['snapshot_stage'] ?? 'before');
        $phase = $operation === 'native_range' ? 'range_starting' : 'snapshot_' . ($state['snapshot_stage'] ?? 'before') . '_start';
        $state['phase'] = $phase;
    } elseif ($phase === 'waiting_snapshot') {
        $phase = 'snapshot_' . ($state['snapshot_stage'] ?? 'before') . '_start';
        $state['phase'] = $phase;
    } elseif ($phase === 'waiting_java_slot') {
        $phase = 'range_starting';
        $state['phase'] = $phase;
    }

    switch ($phase) {
        case 'snapshot_before_start':
            lps_accounting_price_campaign_start_snapshot($state, 'before');
            break;
        case 'snapshot_after_start':
            lps_accounting_price_campaign_start_snapshot($state, 'after');
            break;
        case 'snapshot_before_poll':
        case 'snapshot_after_poll':
            lps_accounting_price_campaign_poll_snapshot($state);
            break;
        case 'select_batch':
            if (!empty($state['stop_requested']) || lps_accounting_price_campaign_deadline_reached($state)) {
                $state['phase'] = 'snapshot_after_start';
                $state['message'] = __('The maintenance window is ending. Building the mandatory final snapshot.', 'lavka-price-sync');
                lps_accounting_price_campaign_store($state);
                lps_accounting_price_campaign_schedule_tick(1);
                break;
            }
            $batch_size = lps_accounting_price_campaign_batch_size($state);
            if ($batch_size < 1) {
                $state['phase'] = 'snapshot_after_start';
                $state['message'] = __('There is not enough safe time for another batch. Building the mandatory final snapshot.', 'lavka-price-sync');
                lps_accounting_price_campaign_store($state);
                lps_accounting_price_campaign_schedule_tick(1);
                break;
            }
            $skus = lps_accounting_price_campaign_select_skus(
                (string)($state['source_database'] ?? ''),
                absint($state['current_warehouse_id'] ?? 0),
                (string)($state['cursor'] ?? ''),
                $batch_size,
                !empty($state['include_unverified'])
            );
            if (!$skus) {
                $state['phase'] = 'snapshot_after_start';
                $state['message'] = __('No more eligible SKU remain in this warehouse. Building the mandatory final snapshot.', 'lavka-price-sync');
                lps_accounting_price_campaign_store($state);
                lps_accounting_price_campaign_schedule_tick(1);
                break;
            }
            lps_accounting_price_campaign_start_range($state, $skus);
            break;
        case 'range_starting':
            $skus = array_values(array_map('strval', (array)($state['current_skus'] ?? [])));
            if (!$skus) {
                $state['phase'] = 'select_batch';
                lps_accounting_price_campaign_store($state);
                lps_accounting_price_campaign_schedule_tick(1);
            } else {
                lps_accounting_price_campaign_start_range($state, $skus);
            }
            break;
        case 'range_poll':
            lps_accounting_price_campaign_poll_range($state);
            break;
        default:
            lps_accounting_price_campaign_release_lock($state);
            $state['active'] = false;
            $state['status'] = 'manual_review';
            $state['phase'] = 'unknown_phase';
            $state['error'] = __('The SKU campaign reached an unknown phase. Automatic continuation was stopped.', 'lavka-price-sync');
            $state['completed_at'] = current_time('mysql');
            lps_accounting_price_campaign_store($state);
            lps_accounting_prices_native_pause_schedule($state['error']);
    }
    return lps_accounting_price_campaign_public_state();
}

function lps_accounting_price_campaign_request_stop(): array {
    $state = lps_accounting_price_campaign_state();
    if (empty($state['active'])) return lps_accounting_price_campaign_public_state($state);
    $state['stop_requested'] = true;
    $state['message'] = __('The campaign will stop after the current operation and mandatory final snapshot.', 'lavka-price-sync');
    lps_accounting_price_campaign_store($state);
    lps_accounting_price_campaign_schedule_tick(1);
    return lps_accounting_price_campaign_public_state($state);
}

function lps_accounting_price_campaign_run_scheduled(string $source = 'cron'): array {
    $options = lps_accounting_prices_native_cron_options();
    if ($source === 'cron') lps_accounting_prices_native_reschedule();
    if (empty($options['enabled']) || empty($options['automatic_apply_confirmed'])) {
        return ['ok' => false, 'httpStatus' => 400, 'message' => 'schedule_disabled'];
    }
    return lps_accounting_price_campaign_create($options['warehouse_ids'] ?? [], $source === 'cron' ? 'cron' : 'recovery');
}

function lps_accounting_price_campaign_maybe_resume(): void {
    $state = lps_accounting_price_campaign_state();
    if (!empty($state['active']) && !wp_next_scheduled(LPS_ACCOUNTING_PRICE_CAMPAIGN_TICK_HOOK)) {
        lps_accounting_price_campaign_schedule_tick(10);
    }
}

function lps_accounting_price_campaign_csv_cell($value): string {
    $value = is_scalar($value) ? (string)$value : '';
    return preg_match('/^[=+\-@]/u', $value) ? "'" . $value : $value;
}

add_action(LPS_ACCOUNTING_PRICE_CAMPAIGN_TICK_HOOK, 'lps_accounting_price_campaign_tick');
add_action('init', 'lps_accounting_price_campaign_maybe_resume', 31);

add_action('admin_post_lps_accounting_price_snapshot_report_export', function (): void {
    if (!current_user_can(LPS_CAP)) {
        wp_die(esc_html__('You do not have permission to perform this operation.', 'lavka-price-sync'));
    }
    check_admin_referer('lps_accounting_price_snapshot_report_export');

    $verification_state = strtoupper(sanitize_key(wp_unslash($_GET['verification_state'] ?? '')));
    $warehouse_id = absint($_GET['warehouse_id'] ?? 0);
    $source_database = sanitize_text_field(wp_unslash($_GET['source_database'] ?? ''));
    if (!in_array($verification_state, lps_accounting_price_campaign_report_states(), true)) {
        wp_die(esc_html__('Select a supported snapshot state.', 'lavka-price-sync'));
    }

    $filename = 'folio-accounting-price-snapshot-' . strtolower($verification_state) . '-' . gmdate('Ymd-His') . '.csv';
    nocache_headers();
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $output = fopen('php://output', 'wb');
    if ($output === false) exit;
    fwrite($output, "\xEF\xBB\xBF");
    fputcsv($output, [
        __('State', 'lavka-price-sync'),
        __('Warehouse', 'lavka-price-sync'),
        __('SKU', 'lavka-price-sync'),
        __('Product', 'lavka-price-sync'),
        __('Reason', 'lavka-price-sync'),
        __('Last error', 'lavka-price-sync'),
        __('Present in Folio', 'lavka-price-sync'),
        __('Movements', 'lavka-price-sync'),
        __('First movement', 'lavka-price-sync'),
        __('Last movement', 'lavka-price-sync'),
        __('Last observed', 'lavka-price-sync'),
        __('Last recalculated', 'lavka-price-sync'),
        __('Latest change', 'lavka-price-sync'),
        __('Change detected', 'lavka-price-sync'),
    ]);

    $page = 1;
    do {
        $report = lps_accounting_price_campaign_snapshot_items(
            $verification_state,
            $page,
            500,
            $warehouse_id,
            $source_database
        );
        if (empty($report['ok'])) {
            fclose($output);
            exit;
        }
        foreach ((array)($report['items'] ?? []) as $item) {
            if (!is_array($item)) continue;
            $change = is_array($item['latest_change'] ?? null) ? $item['latest_change'] : [];
            fputcsv($output, array_map('lps_accounting_price_campaign_csv_cell', [
                $verification_state,
                (string)($report['warehouseId'] ?? ''),
                (string)($item['sku'] ?? ''),
                (string)($item['product_name'] ?? ''),
                lps_accounting_price_campaign_state_reason($verification_state),
                (string)($item['last_error'] ?? ''),
                !empty($item['present_in_folio']) ? __('Yes', 'lavka-price-sync') : __('No', 'lavka-price-sync'),
                (string)($item['movement_count'] ?? 0),
                (string)($item['first_movement_date'] ?? ''),
                (string)($item['last_movement_date'] ?? ''),
                (string)($item['last_observed_at'] ?? ''),
                (string)($item['applied_at'] ?? ''),
                (string)($change['change_type'] ?? ''),
                (string)($change['detected_at'] ?? ''),
            ]));
        }
        $page++;
    } while ($page <= absint($report['pages'] ?? 1));

    fclose($output);
    exit;
});

add_action('admin_post_lps_accounting_price_campaign_export', function (): void {
    if (!current_user_can(LPS_CAP)) {
        wp_die(esc_html__('You do not have permission to perform this operation.', 'lavka-price-sync'));
    }
    check_admin_referer('lps_accounting_price_campaign_export');

    $state = lps_accounting_price_campaign_state();
    $filename = 'folio-accounting-price-sku-campaign-' . gmdate('Ymd-His') . '.csv';
    nocache_headers();
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $output = fopen('php://output', 'wb');
    if ($output === false) exit;
    fwrite($output, "\xEF\xBB\xBF");
    fputcsv($output, [
        __('When', 'lavka-price-sync'),
        __('Entry type', 'lavka-price-sync'),
        __('Warehouse', 'lavka-price-sync'),
        __('Result', 'lavka-price-sync'),
        __('SKU', 'lavka-price-sync'),
        __('SKU count', 'lavka-price-sync'),
        __('Message', 'lavka-price-sync'),
        __('Document', 'lavka-price-sync'),
        __('Problem date', 'lavka-price-sync'),
        __('Before operation', 'lavka-price-sync'),
        __('Operation quantity', 'lavka-price-sync'),
        __('After operation', 'lavka-price-sync'),
        __('Shortage', 'lavka-price-sync'),
        __('Technical details', 'lavka-price-sync'),
    ]);

    foreach ((array)($state['reports'] ?? []) as $report) {
        if (!is_array($report)) continue;
        fputcsv($output, array_map('lps_accounting_price_campaign_csv_cell', [
            (string)($report['completed_at'] ?? ''),
            __('Batch or warehouse result', 'lavka-price-sync'),
            (string)($report['warehouse_id'] ?? ''),
            (string)($report['status'] ?? ''),
            trim((string)($report['first_sku'] ?? '') . (($report['last_sku'] ?? '') !== ($report['first_sku'] ?? '') ? ' … ' . (string)($report['last_sku'] ?? '') : '')),
            (string)($report['sku_count'] ?? 0),
            (string)($report['error'] ?? ''),
            '',
            '',
            '',
            '',
            '',
            '',
            (string)wp_json_encode($report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]));
    }

    foreach ((array)($state['warnings'] ?? []) as $issue) {
        if (!is_array($issue)) continue;
        $details = is_array($issue['details'] ?? null) ? $issue['details'] : [];
        $operation = is_array($details['operation'] ?? null) ? $details['operation'] : [];
        $document = trim((string)($operation['documentNumber'] ?? ''));
        if ($document === '') $document = trim((string)($operation['documentId'] ?? ''));
        $document_type = trim((string)($operation['documentType'] ?? ''));
        if ($document_type !== '') $document = trim($document_type . ' ' . $document);
        fputcsv($output, array_map('lps_accounting_price_campaign_csv_cell', [
            (string)($issue['recordedAt'] ?? ''),
            strtolower((string)($issue['severity'] ?? 'warning')) === 'error'
                ? __('Error', 'lavka-price-sync')
                : __('Warning', 'lavka-price-sync'),
            (string)($issue['warehouseId'] ?? ''),
            (string)($issue['code'] ?? ''),
            (string)($issue['sku'] ?? ($details['sku'] ?? ($details['art'] ?? ($details['inputArt'] ?? '')))),
            '1',
            (string)($issue['message'] ?? ''),
            $document,
            (string)($operation['documentDate'] ?? ($details['problemDate'] ?? '')),
            (string)($details['quantityBefore'] ?? ''),
            (string)($operation['quantity'] ?? ($details['movementQuantity'] ?? '')),
            (string)($details['quantityAfter'] ?? ''),
            (string)($details['shortageQuantity'] ?? ''),
            (string)wp_json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]));
    }
    if (!empty($state['warnings_truncated'])) {
        fputcsv($output, array_map('lps_accounting_price_campaign_csv_cell', [
            current_time('mysql'),
            __('Warning', 'lavka-price-sync'),
            '',
            'REPORT_TRUNCATED',
            '',
            '',
            __('Only the most recent diagnostics are stored in WordPress. Review the Java logs for the complete history.', 'lavka-price-sync'),
            '',
            '',
            '',
            '',
            '',
            '',
            '',
        ]));
    }
    fclose($output);
    exit;
});
