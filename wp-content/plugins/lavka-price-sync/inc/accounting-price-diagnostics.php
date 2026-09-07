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

function lps_accounting_price_diagnostic_export_values(array $item): array {
    $details = is_array($item['details'] ?? null) ? $item['details'] : [];
    $operation = is_array($details['operation'] ?? null) ? $details['operation'] : [];
    $current = is_array($details['currentState'] ?? null) ? $details['currentState'] : [];
    $sql_errors = is_array($details['sqlErrors'] ?? null) ? $details['sqlErrors'] : [];
    $sql_error = is_array($sql_errors[0] ?? null) ? $sql_errors[0] : [];
    $yes_no = static function (array $source, string $key): string {
        if (!array_key_exists($key, $source) || $source[$key] === null) return '';
        return !empty($source[$key]) ? __('Yes', 'lavka-price-sync') : __('No', 'lavka-price-sync');
    };
    return [
        __('ID', 'lavka-price-sync') => $item['id'] ?? '',
        __('Recorded at', 'lavka-price-sync') => $item['createdAt'] ?? '',
        __('Database', 'lavka-price-sync') => $item['sourceDatabase'] ?? '',
        __('Warehouse', 'lavka-price-sync') => $item['warehouseId'] ?? '',
        __('SKU', 'lavka-price-sync') => $item['sku'] ?? '',
        __('Mode', 'lavka-price-sync') => !empty($item['previewOnly']) ? __('Preview', 'lavka-price-sync') : __('Apply', 'lavka-price-sync'),
        __('Java job ID', 'lavka-price-sync') => $item['jobId'] ?? '',
        __('Reason', 'lavka-price-sync') => $item['errorCode'] ?? '',
        __('Message', 'lavka-price-sync') => $item['message'] ?? '',
        __('Stage', 'lavka-price-sync') => $details['stage'] ?? '',
        __('Document type', 'lavka-price-sync') => $operation['documentType'] ?? '',
        __('Document No.', 'lavka-price-sync') => $operation['documentNumber'] ?? '',
        __('Document ID', 'lavka-price-sync') => $operation['documentId'] ?? '',
        __('Document date', 'lavka-price-sync') => $operation['documentDate'] ?? '',
        __('Movement record', 'lavka-price-sync') => $operation['recno'] ?? '',
        __('Initial quantity', 'lavka-price-sync') => $details['initialQuantity'] ?? '',
        __('Before operation', 'lavka-price-sync') => $details['quantityBefore'] ?? '',
        __('Operation', 'lavka-price-sync') => $operation['kind'] ?? '',
        __('Operation quantity', 'lavka-price-sync') => $operation['quantity'] ?? '',
        __('After operation', 'lavka-price-sync') => $details['quantityAfter'] ?? '',
        __('Shortage', 'lavka-price-sync') => $details['shortageQuantity'] ?? '',
        __('Movement position', 'lavka-price-sync') => $details['movementPosition'] ?? '',
        __('Movement count', 'lavka-price-sync') => $details['movementCount'] ?? '',
        __('Current physical quantity', 'lavka-price-sync') => $current['physicalQuantity'] ?? '',
        __('Current available quantity', 'lavka-price-sync') => $current['availableQuantity'] ?? '',
        __('Current accounting quantity', 'lavka-price-sync') => $current['accountingQuantity'] ?? '',
        __('Current accounting price', 'lavka-price-sync') => $current['accountingPrice'] ?? '',
        __('Trigger movement confirmed', 'lavka-price-sync') => $yes_no($details, 'triggerMovementConfirmed'),
        __('Business root cause confirmed', 'lavka-price-sync') => $yes_no($details, 'businessRootCauseConfirmed'),
        __('SQL error code', 'lavka-price-sync') => $sql_error['errorCode'] ?? '',
        __('SQL state', 'lavka-price-sync') => $sql_error['sqlState'] ?? '',
        __('Rollback confirmed', 'lavka-price-sync') => $yes_no($details, 'rollbackConfirmed'),
        __('Committed', 'lavka-price-sync') => $yes_no($details, 'committed'),
        __('Formula confirmed', 'lavka-price-sync') => $yes_no($details, 'formulaConfirmed'),
        __('Cause document confirmed', 'lavka-price-sync') => $yes_no($details, 'documentConfirmed'),
        __('Recommendation', 'lavka-price-sync') => $details['recommendation'] ?? '',
        __('Technical details', 'lavka-price-sync') => wp_json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ];
}

function lps_accounting_price_diagnostic_export_items(array $filters): Generator {
    $before_id = 0;
    do {
        $report = lps_accounting_price_diagnostic_query($filters, $before_id, 500);
        if (empty($report['ok']) || empty($report['available'])) return;
        foreach ((array)$report['items'] as $item) yield $item;
        $before_id = absint($report['nextBeforeId'] ?? 0);
    } while (!empty($report['hasMore']) && $before_id > 0);
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
    fputcsv($output, array_keys(lps_accounting_price_diagnostic_export_values([])));
    foreach (lps_accounting_price_diagnostic_export_items($filters) as $item) {
        $values = lps_accounting_price_diagnostic_export_values($item);
        fputcsv($output, array_map('lps_accounting_price_diagnostic_csv_value', array_values($values)));
    }
    fclose($output);
    exit;
});

add_action('admin_post_lps_accounting_price_diagnostic_export_xlsx', function (): void {
    if (!current_user_can(LPS_CAP)) {
        wp_die(esc_html__('You do not have permission to perform this operation.', 'lavka-price-sync'));
    }
    check_admin_referer('lps_accounting_price_diagnostic_export_xlsx');
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
    if (!class_exists(\PhpOffice\PhpSpreadsheet\Spreadsheet::class)) {
        $autoload = WP_CONTENT_DIR . '/vendor/autoload.php';
        if (is_readable($autoload)) require_once $autoload;
    }
    if (!class_exists(\PhpOffice\PhpSpreadsheet\Spreadsheet::class)) {
        wp_die(esc_html__('XLSX export is temporarily unavailable.', 'lavka-price-sync'), '', ['response' => 503]);
    }

    $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle(__('Diagnostics', 'lavka-price-sync'));
    $row_number = 1;
    $column_count = 0;
    foreach (lps_accounting_price_diagnostic_export_items($filters) as $item) {
        $values = lps_accounting_price_diagnostic_export_values($item);
        if ($row_number === 1) {
            $column_count = count($values);
            foreach (array_keys($values) as $index => $header) {
                $sheet->setCellValueExplicit([$index + 1, 1], $header, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            }
            $row_number++;
        }
        foreach (array_values($values) as $index => $value) {
            $value = is_scalar($value) ? $value : '';
            if (is_int($value) || is_float($value)) {
                $sheet->setCellValueExplicit([$index + 1, $row_number], $value, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_NUMERIC);
            } else {
                $sheet->setCellValueExplicit([$index + 1, $row_number], mb_substr((string)$value, 0, 32000), \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            }
        }
        $row_number++;
    }
    if ($column_count === 0) {
        $empty = lps_accounting_price_diagnostic_export_values([]);
        $column_count = count($empty);
        foreach (array_keys($empty) as $index => $header) {
            $sheet->setCellValueExplicit([$index + 1, 1], $header, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
        }
    }
    $last_column = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($column_count);
    $last_row = max(1, $row_number - 1);
    $sheet->getStyle("A1:{$last_column}1")->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
    $sheet->getStyle("A1:{$last_column}1")->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB('4472C4');
    $sheet->getStyle("A1:{$last_column}{$last_row}")->getAlignment()->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_TOP)->setWrapText(true);
    $sheet->setAutoFilter("A1:{$last_column}{$last_row}");
    $sheet->freezePane('A2');
    for ($column = 1; $column <= $column_count; $column++) {
        $sheet->getColumnDimensionByColumn($column)->setWidth($column >= $column_count - 1 ? 42 : 18);
    }

    $filename = 'folio-accounting-price-diagnostics-' . $filters['warehouseId'] . '-' . gmdate('Ymd-His') . '.xlsx';
    while (ob_get_level() > 0) ob_end_clean();
    nocache_headers();
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('X-Content-Type-Options: nosniff');
    (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet))->save('php://output');
    $spreadsheet->disconnectWorksheets();
    exit;
});
