<?php
if (!defined('ABSPATH')) exit;

const LPS_ACCOUNTING_PRICE_DIAGNOSTIC_TABLE = 'folio_accounting_price_diagnostic';

function lps_accounting_price_diagnostic_table_ready(): bool {
    global $wpdb;
    $table = LPS_ACCOUNTING_PRICE_DIAGNOSTIC_TABLE;
    return (string)$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) === $table;
}

function lps_accounting_price_diagnostic_date(string $value): string {
    $value = trim($value);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) return '';
    [$year, $month, $day] = array_map('intval', explode('-', $value));
    return checkdate($month, $day, $year) ? $value : '';
}

function lps_accounting_price_diagnostic_filters(array $input): array {
    $mode = sanitize_key((string)($input['mode'] ?? 'all'));
    if (!in_array($mode, ['all', 'preview', 'apply'], true)) $mode = 'all';
    return [
        'sourceDatabase' => sanitize_text_field((string)($input['sourceDatabase'] ?? '')),
        'warehouseId' => absint($input['warehouseId'] ?? 0),
        'sku' => trim(sanitize_text_field((string)($input['sku'] ?? ''))),
        'jobId' => trim(sanitize_text_field((string)($input['jobId'] ?? ''))),
        'mode' => $mode,
        'dateFrom' => lps_accounting_price_diagnostic_date((string)($input['dateFrom'] ?? '')),
        'dateTo' => lps_accounting_price_diagnostic_date((string)($input['dateTo'] ?? '')),
    ];
}

function lps_accounting_price_diagnostic_query(array $input, int $before_id = 0, int $per_page = 50): array {
    global $wpdb;
    $filters = lps_accounting_price_diagnostic_filters($input);
    if ($filters['sourceDatabase'] === '' || $filters['warehouseId'] < 1) {
        return ['ok' => false, 'available' => lps_accounting_price_diagnostic_table_ready(),
            'message' => __('Select a Folio database and warehouse for the permanent diagnostic log.', 'lavka-price-sync')];
    }
    if (!lps_accounting_price_diagnostic_table_ready()) {
        return ['ok' => true, 'available' => false, 'items' => [], 'nextBeforeId' => 0,
            'filters' => $filters,
            'message' => __('The permanent accounting-price diagnostic log is not available yet. Deploy Java with MariaDB migration V14; current campaign warnings and snapshot errors remain available.', 'lavka-price-sync')];
    }

    $where = ['source_database = %s', 'warehouse_id = %d'];
    $args = [$filters['sourceDatabase'], $filters['warehouseId']];
    if ($filters['sku'] !== '') { $where[] = 'sku = %s'; $args[] = $filters['sku']; }
    if ($filters['jobId'] !== '') { $where[] = 'job_id = %s'; $args[] = $filters['jobId']; }
    if ($filters['mode'] !== 'all') { $where[] = 'preview_only = %d'; $args[] = $filters['mode'] === 'preview' ? 1 : 0; }
    if ($filters['dateFrom'] !== '') { $where[] = 'created_at >= %s'; $args[] = $filters['dateFrom'] . ' 00:00:00'; }
    if ($filters['dateTo'] !== '') {
        $until = (new DateTimeImmutable($filters['dateTo'], new DateTimeZone('UTC')))->modify('+1 day')->format('Y-m-d 00:00:00');
        $where[] = 'created_at < %s'; $args[] = $until;
    }
    if ($before_id > 0) { $where[] = 'id < %d'; $args[] = $before_id; }
    $per_page = in_array($per_page, [20, 50, 100, 500], true) ? $per_page : 50;
    $args[] = $per_page + 1;
    $sql = "SELECT id, job_id, source_database, warehouse_id, sku, preview_only,
                   error_code, message, diagnostics_json, created_at
            FROM " . LPS_ACCOUNTING_PRICE_DIAGNOSTIC_TABLE . "
            WHERE " . implode(' AND ', $where) . "
            ORDER BY id DESC LIMIT %d";
    $rows = $wpdb->get_results($wpdb->prepare($sql, ...$args), ARRAY_A) ?: [];
    $has_more = count($rows) > $per_page;
    if ($has_more) array_pop($rows);
    $items = [];
    foreach ($rows as $row) {
        $raw = (string)($row['diagnostics_json'] ?? '');
        $details = json_decode($raw, true);
        if (!is_array($details)) {
            $details = ['diagnosticsMalformed' => true, 'rawDiagnostics' => $raw];
        }
        $items[] = [
            'id' => absint($row['id'] ?? 0),
            'jobId' => sanitize_text_field((string)($row['job_id'] ?? '')),
            'sourceDatabase' => sanitize_text_field((string)($row['source_database'] ?? '')),
            'warehouseId' => absint($row['warehouse_id'] ?? 0),
            'sku' => (string)($row['sku'] ?? ''),
            'previewOnly' => !empty($row['preview_only']),
            'errorCode' => sanitize_text_field((string)($row['error_code'] ?? '')),
            'message' => sanitize_textarea_field((string)($row['message'] ?? '')),
            'details' => $details,
            'createdAt' => sanitize_text_field((string)($row['created_at'] ?? '')),
        ];
    }
    return [
        'ok' => true,
        'available' => true,
        'items' => $items,
        'hasMore' => $has_more,
        'nextBeforeId' => $has_more && $items ? absint(end($items)['id']) : 0,
        'beforeId' => max(0, $before_id),
        'perPage' => $per_page,
        'filters' => $filters,
        'message' => '',
    ];
}

function lps_accounting_price_diagnostic_csv_value($value): string {
    $value = is_scalar($value) ? (string)$value : '';
    return preg_match('/^[=+\-@]/u', $value) ? "'" . $value : $value;
}

add_action('admin_post_lps_accounting_price_diagnostic_export', function (): void {
    if (!current_user_can(LPS_CAP)) {
        wp_die(esc_html__('You do not have permission to perform this operation.', 'lavka-price-sync'));
    }
    check_admin_referer('lps_accounting_price_diagnostic_export');
    $filters = lps_accounting_price_diagnostic_filters([
        'sourceDatabase' => wp_unslash($_GET['source_database'] ?? ''),
        'warehouseId' => $_GET['warehouse_id'] ?? 0,
        'sku' => wp_unslash($_GET['sku'] ?? ''),
        'jobId' => wp_unslash($_GET['job_id'] ?? ''),
        'mode' => wp_unslash($_GET['mode'] ?? 'all'),
        'dateFrom' => wp_unslash($_GET['date_from'] ?? ''),
        'dateTo' => wp_unslash($_GET['date_to'] ?? ''),
    ]);
    if ($filters['sourceDatabase'] === '' || $filters['warehouseId'] < 1 || !lps_accounting_price_diagnostic_table_ready()) {
        wp_die(esc_html__('The permanent accounting-price diagnostic log is not available for this warehouse.', 'lavka-price-sync'));
    }

    $filename = 'folio-accounting-price-diagnostics-' . $filters['warehouseId'] . '-' . gmdate('Ymd-His') . '.csv';
    nocache_headers();
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $output = fopen('php://output', 'wb');
    if ($output === false) exit;
    fwrite($output, "\xEF\xBB\xBF");
    fputcsv($output, [
        __('ID', 'lavka-price-sync'), __('Recorded at', 'lavka-price-sync'), __('Database', 'lavka-price-sync'),
        __('Warehouse', 'lavka-price-sync'), __('SKU', 'lavka-price-sync'), __('Mode', 'lavka-price-sync'),
        __('Java job ID', 'lavka-price-sync'), __('Reason', 'lavka-price-sync'), __('Message', 'lavka-price-sync'),
        __('Stage', 'lavka-price-sync'), __('SQL error code', 'lavka-price-sync'), __('SQL state', 'lavka-price-sync'),
        __('Rollback confirmed', 'lavka-price-sync'), __('Committed', 'lavka-price-sync'),
        __('Formula confirmed', 'lavka-price-sync'), __('Cause document confirmed', 'lavka-price-sync'),
        __('Recommendation', 'lavka-price-sync'), __('Technical details', 'lavka-price-sync'),
    ]);
    $before_id = 0;
    do {
        $report = lps_accounting_price_diagnostic_query($filters, $before_id, 500);
        if (empty($report['ok']) || empty($report['available'])) break;
        foreach ((array)$report['items'] as $item) {
            $details = is_array($item['details'] ?? null) ? $item['details'] : [];
            $sql_errors = is_array($details['sqlErrors'] ?? null) ? $details['sqlErrors'] : [];
            $sql_error = is_array($sql_errors[0] ?? null) ? $sql_errors[0] : [];
            fputcsv($output, array_map('lps_accounting_price_diagnostic_csv_value', [
                $item['id'], $item['createdAt'], $item['sourceDatabase'], $item['warehouseId'], $item['sku'],
                $item['previewOnly'] ? __('Preview', 'lavka-price-sync') : __('Apply', 'lavka-price-sync'),
                $item['jobId'], $item['errorCode'], $item['message'], $details['stage'] ?? '',
                $sql_error['errorCode'] ?? '', $sql_error['sqlState'] ?? '',
                array_key_exists('rollbackConfirmed', $details) ? (!empty($details['rollbackConfirmed']) ? __('Yes', 'lavka-price-sync') : __('No', 'lavka-price-sync')) : '',
                array_key_exists('committed', $details) ? (!empty($details['committed']) ? __('Yes', 'lavka-price-sync') : __('No', 'lavka-price-sync')) : '',
                array_key_exists('formulaConfirmed', $details) ? (!empty($details['formulaConfirmed']) ? __('Yes', 'lavka-price-sync') : __('No', 'lavka-price-sync')) : '',
                array_key_exists('documentConfirmed', $details) ? (!empty($details['documentConfirmed']) ? __('Yes', 'lavka-price-sync') : __('No', 'lavka-price-sync')) : '',
                $details['recommendation'] ?? '', wp_json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]));
        }
        $before_id = absint($report['nextBeforeId'] ?? 0);
    } while (!empty($report['hasMore']) && $before_id > 0);
    fclose($output);
    exit;
});
