<?php
if (!defined('ABSPATH')) exit;

const LPS_PRODUCT_ANALYTICS_EXPORT_NONCE = 'lps_product_analytics_export';
const LPS_PRODUCT_ANALYTICS_EXPORT_ROW_LIMIT = 100000;

function lps_product_analytics_export_scalar($value) {
    return is_numeric($value) ? (float)$value : '';
}

function lps_product_analytics_export_availability(array $availability): array {
    return [
        (string)($availability['status'] ?? ''),
        lps_product_analytics_export_scalar($availability['stockoutDays'] ?? null),
        lps_product_analytics_export_scalar($availability['stockoutPercent'] ?? null),
    ];
}

function lps_product_analytics_export_breakdown(array $rows, bool $groups = false): string {
    $parts = [];
    foreach ($rows as $row) {
        if (!is_array($row)) continue;
        $availability = is_array($row['availability'] ?? null) ? $row['availability'] : [];
        $name = (string)($groups
            ? ($row['name'] ?? $row['code'] ?? '')
            : ($row['warehouseName'] ?? $row['warehouseId'] ?? ''));
        if ($name === '') continue;
        $status = (string)($availability['status'] ?? '');
        $days = $availability['stockoutDays'] ?? null;
        $percent = $availability['stockoutPercent'] ?? null;
        $detail = [];
        /* translators: %s: number of days without stock. */
        if ($days !== null) $detail[] = sprintf(__('%s days', 'lavka-price-sync'), (string)$days);
        /* translators: %s: percentage of days without stock. */
        if ($percent !== null) $detail[] = sprintf(__('%s%%', 'lavka-price-sync'), (string)$percent);
        if (!$detail && $status !== '') $detail[] = $status;
        $parts[] = $name . ($detail ? ': ' . implode(', ', $detail) : '');
    }
    return implode(' | ', $parts);
}

function lps_product_analytics_export_columns(string $tab): array {
    $common = [
        ['key' => 'abc', 'label' => __('ABC class', 'lavka-price-sync')],
        ['key' => 'sku', 'label' => __('SKU', 'lavka-price-sync')],
        ['key' => 'gtin', 'label' => __('Primary GTIN', 'lavka-price-sync')],
        ['key' => 'product', 'label' => __('Product', 'lavka-price-sync')],
        ['key' => 'supplier', 'label' => __('Current supplier', 'lavka-price-sync')],
    ];
    $metrics = $tab === 'movements' ? [
        ['key' => 'soldUnits', 'label' => __('Sold units', 'lavka-price-sync')],
        ['key' => 'regularSoldUnits', 'label' => __('Regular sales quantity', 'lavka-price-sync')],
        ['key' => 'oneOffSoldUnits', 'label' => __('One-off sales quantity', 'lavka-price-sync')],
        ['key' => 'returnQuantity', 'label' => __('Returns', 'lavka-price-sync')],
        ['key' => 'salesRevenue', 'label' => __('Sales revenue', 'lavka-price-sync')],
        ['key' => 'salesCogs', 'label' => __('Cost of sales', 'lavka-price-sync')],
        ['key' => 'grossProfit', 'label' => __('Gross profit', 'lavka-price-sync')],
        ['key' => 'grossMarginPercent', 'label' => __('Gross margin, %', 'lavka-price-sync')],
        ['key' => 'inventoryTurns', 'label' => __('Inventory turns', 'lavka-price-sync')],
        ['key' => 'gmroi', 'label' => __('GMROI', 'lavka-price-sync')],
        ['key' => 'coverageDays', 'label' => __('Stock coverage, days', 'lavka-price-sync')],
    ] : [
        ['key' => 'physicalQuantity', 'label' => __('Physical quantity', 'lavka-price-sync')],
        ['key' => 'reservedQuantity', 'label' => __('Reserved quantity', 'lavka-price-sync')],
        ['key' => 'availableQuantity', 'label' => __('Available quantity', 'lavka-price-sync')],
        ['key' => 'inventoryValue', 'label' => __('Capital in stock', 'lavka-price-sync')],
    ];
    return array_merge($common, $metrics, [
        ['key' => 'availabilityStatus', 'label' => __('Availability status', 'lavka-price-sync')],
        ['key' => 'stockoutDays', 'label' => __('Days without stock', 'lavka-price-sync')],
        ['key' => 'stockoutPercent', 'label' => __('Days without stock, %', 'lavka-price-sync')],
        ['key' => 'warehouseAvailability', 'label' => __('Availability by warehouse', 'lavka-price-sync')],
        ['key' => 'groupAvailability', 'label' => __('Availability by combined warehouse', 'lavka-price-sync')],
        ['key' => 'warehouseGroupsRevision', 'label' => __('Warehouse group revision', 'lavka-price-sync')],
    ]);
}

function lps_product_analytics_export_row(array $row, string $tab, string $warehouse_groups_revision = ''): array {
    $metrics = is_array($row['metrics'] ?? null) ? $row['metrics'] : [];
    $dimensions = is_array($row['dimensions'] ?? null) ? $row['dimensions'] : [];
    $availability = is_array($row['availability'] ?? null) ? $row['availability'] : [];
    [$availability_status, $stockout_days, $stockout_percent] = lps_product_analytics_export_availability($availability);
    $data = [
        'abc' => (string)($row['abcClass'] ?? ''),
        'sku' => (string)($row['sku'] ?? ''),
        'gtin' => (string)($dimensions['primaryBarcode'] ?? ''),
        'product' => (string)($row['productName'] ?? ''),
        'supplier' => implode(', ', array_map('strval', (array)($dimensions['currentSuppliers'] ?? []))),
        'availabilityStatus' => $availability_status,
        'stockoutDays' => $stockout_days,
        'stockoutPercent' => $stockout_percent,
        'warehouseAvailability' => lps_product_analytics_export_breakdown((array)($row['warehouseBreakdown'] ?? [])),
        'groupAvailability' => lps_product_analytics_export_breakdown((array)($row['warehouseGroupBreakdown'] ?? []), true),
        'warehouseGroupsRevision' => $warehouse_groups_revision,
    ];
    foreach (lps_product_analytics_export_columns($tab) as $column) {
        $key = $column['key'];
        if (!array_key_exists($key, $data) && array_key_exists($key, $metrics)) {
            $data[$key] = lps_product_analytics_export_scalar($metrics[$key]);
        }
    }
    return $data;
}

function lps_product_analytics_export_fetch_rows(array $query, string $tab) {
    $rows = [];
    $metadata = null;
    $cursor = null;
    $seen_cursors = [];
    do {
        $query['page'] = ['size' => 500, 'cursor' => $cursor];
        $response = lps_product_analytics_v4_request_java(LPS_PRODUCT_ANALYTICS_QUERY_PATH, $query);
        if (is_wp_error($response)) return $response;
        if ($metadata === null) {
            $metadata = lps_product_analytics_export_metadata($query, $response);
        }
        $revision = (string)($metadata['warehouseGroupsRevision'] ?? '');
        foreach ((array)($response['rows'] ?? []) as $row) {
            if (is_array($row)) $rows[] = lps_product_analytics_export_row($row, $tab, $revision);
            if (count($rows) > LPS_PRODUCT_ANALYTICS_EXPORT_ROW_LIMIT) {
                return new WP_Error('export_too_large', __('The report is too large to export safely. Narrow the filters and try again.', 'lavka-price-sync'));
            }
        }
        $next = trim((string)($response['nextCursor'] ?? ''));
        if ($next === '') break;
        if (isset($seen_cursors[$next])) {
            return new WP_Error('export_cursor_loop', __('The analytics service returned a repeated page cursor.', 'lavka-price-sync'));
        }
        $seen_cursors[$next] = true;
        $cursor = $next;
    } while (true);
    return [
        'rows' => $rows,
        'metadata' => $metadata ?: lps_product_analytics_export_metadata($query, []),
    ];
}

function lps_product_analytics_export_metadata(array $query, array $response): array {
    $availability = is_array($query['calculation']['availability'] ?? null)
        ? $query['calculation']['availability']
        : [];
    $scenario = is_array($query['scenario'] ?? null) ? $query['scenario'] : [];
    $generation = is_array($response['generation'] ?? null) ? $response['generation'] : [];

    return [
        'generatedAt' => wp_date('Y-m-d H:i:s'),
        'sourceDatabase' => (string)($query['sourceDatabase'] ?? ''),
        'warehouseIds' => implode(', ', array_map('intval', (array)($query['warehouseIds'] ?? []))),
        'period' => trim((string)($query['period']['from'] ?? '') . ' - ' . (string)($query['period']['to'] ?? ''), ' -'),
        'scenario' => !empty($scenario['id'])
            ? sprintf('#%d v%d', (int)$scenario['id'], (int)($scenario['version'] ?? 0))
            : __('Temporary filters', 'lavka-price-sync'),
        'generationId' => (string)($generation['id'] ?? $response['generationId'] ?? ''),
        'warehouseGroupsRevision' => (string)($availability['warehouseGroupsRevision'] ?? ''),
        'warehouseGroups' => implode(' | ', array_map(static function (array $group): string {
            return sprintf(
                '%s [%s]',
                (string)($group['name'] ?? $group['code'] ?? ''),
                implode(', ', array_map('intval', (array)($group['warehouseIds'] ?? [])))
            );
        }, array_filter((array)($availability['warehouseGroups'] ?? []), 'is_array'))),
    ];
}

function lps_product_analytics_export_csv(array $columns, array $rows, string $filename): void {
    while (ob_get_level()) ob_end_clean();
    nocache_headers();
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $output = fopen('php://output', 'wb');
    fwrite($output, "\xEF\xBB\xBF");
    fputcsv($output, array_column($columns, 'label'), ';', '"', '');
    foreach ($rows as $row) {
        fputcsv($output, array_map(static fn(array $column) => $row[$column['key']] ?? '', $columns), ';', '"', '');
    }
    fclose($output);
    exit;
}

function lps_product_analytics_export_xlsx(array $columns, array $rows, array $metadata, string $filename): void {
    if (!class_exists(\PhpOffice\PhpSpreadsheet\Spreadsheet::class)) {
        wp_die(esc_html__('The XLSX library is unavailable.', 'lavka-price-sync'));
    }
    $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle(__('Analytics', 'lavka-price-sync'));
    $sheet->fromArray(array_column($columns, 'label'), null, 'A1');
    foreach ($rows as $index => $row) {
        $excel_row = $index + 2;
        foreach ($columns as $column_index => $column) {
            $cell = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($column_index + 1) . $excel_row;
            $value = $row[$column['key']] ?? '';
            if (in_array($column['key'], ['sku', 'gtin'], true)) {
                $sheet->setCellValueExplicit($cell, (string)$value, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            } else {
                $sheet->setCellValue($cell, $value);
            }
        }
    }
    $last_column = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($columns));
    $sheet->getStyle("A1:{$last_column}1")->getFont()->setBold(true);
    $sheet->getStyle("A1:{$last_column}1")->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('FFDDEBF7');
    $sheet->freezePane('A2');
    $sheet->setAutoFilter("A1:{$last_column}1");
    foreach (range(1, count($columns)) as $column_index) {
        $letter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($column_index);
        $key = (string)($columns[$column_index - 1]['key'] ?? '');
        $width = in_array($key, ['product', 'supplier', 'warehouseAvailability', 'groupAvailability'], true) ? 42 : 18;
        $sheet->getColumnDimension($letter)->setWidth($width);
    }
    $sheet->getStyle("A1:{$last_column}" . max(2, count($rows) + 1))->getAlignment()->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_TOP);
    $sheet->getStyle("A1:{$last_column}" . max(2, count($rows) + 1))->getAlignment()->setWrapText(true);

    $metadata_sheet = $spreadsheet->createSheet();
    $metadata_sheet->setTitle(__('Report parameters', 'lavka-price-sync'));
    $metadata_sheet->fromArray([
        [__('Parameter', 'lavka-price-sync'), __('Value', 'lavka-price-sync')],
        [__('Generated at', 'lavka-price-sync'), (string)($metadata['generatedAt'] ?? '')],
        [__('Source database', 'lavka-price-sync'), (string)($metadata['sourceDatabase'] ?? '')],
        [__('Warehouses', 'lavka-price-sync'), (string)($metadata['warehouseIds'] ?? '')],
        [__('Report period', 'lavka-price-sync'), (string)($metadata['period'] ?? '')],
        [__('Analytics scenario', 'lavka-price-sync'), (string)($metadata['scenario'] ?? '')],
        [__('Snapshot generation', 'lavka-price-sync'), (string)($metadata['generationId'] ?? '')],
        [__('Warehouse group revision', 'lavka-price-sync'), (string)($metadata['warehouseGroupsRevision'] ?? '')],
        [__('Combined warehouses', 'lavka-price-sync'), (string)($metadata['warehouseGroups'] ?? '')],
    ], null, 'A1');
    $metadata_sheet->getStyle('A1:B1')->getFont()->setBold(true);
    $metadata_sheet->getColumnDimension('A')->setWidth(30);
    $metadata_sheet->getColumnDimension('B')->setWidth(90);
    $metadata_sheet->getStyle('A1:B9')->getAlignment()->setWrapText(true)->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_TOP);
    $spreadsheet->setActiveSheetIndex(0);
    while (ob_get_level()) ob_end_clean();
    nocache_headers();
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet))->save('php://output');
    $spreadsheet->disconnectWorksheets();
    exit;
}

function lps_product_analytics_export(): void {
    if (!current_user_can(LPS_CAP)) {
        wp_die(
            esc_html__('You do not have permission to view product analytics.', 'lavka-price-sync'),
            esc_html__('Export failed', 'lavka-price-sync'),
            ['response' => 403]
        );
    }
    check_admin_referer(LPS_PRODUCT_ANALYTICS_EXPORT_NONCE);
    $format = sanitize_key(wp_unslash($_POST['format'] ?? 'csv'));
    $tab = sanitize_key(wp_unslash($_POST['reportTab'] ?? 'products'));
    if (!in_array($format, ['csv', 'xlsx'], true) || !in_array($tab, ['products', 'movements'], true)) {
        wp_die(esc_html__('The export format is invalid.', 'lavka-price-sync'), esc_html__('Export failed', 'lavka-price-sync'), ['response' => 400]);
    }
    $payload = json_decode((string)wp_unslash($_POST['payloadJson'] ?? ''), true);
    if (!is_array($payload)) wp_die(esc_html__('The product-analytics request is invalid.', 'lavka-price-sync'), esc_html__('Export failed', 'lavka-price-sync'), ['response' => 400]);
    $query = lps_product_analytics_v4_sanitize_query($payload);
    $export = lps_product_analytics_export_fetch_rows($query, $tab);
    if (is_wp_error($export)) wp_die(esc_html($export->get_error_message()), esc_html__('Export failed', 'lavka-price-sync'));
    $columns = lps_product_analytics_export_columns($tab);
    $data = (array)($export['rows'] ?? []);
    $metadata = (array)($export['metadata'] ?? []);
    $filename = sprintf('folio-product-analytics-%s-%s.%s', $tab, wp_date('Ymd-His'), $format);
    if ($format === 'xlsx') lps_product_analytics_export_xlsx($columns, $data, $metadata, $filename);
    lps_product_analytics_export_csv($columns, $data, $filename);
}
add_action('admin_post_lps_product_analytics_export', 'lps_product_analytics_export');
