<?php

defined('ABSPATH') || exit;

/** Fetch every filtered page; never silently export a truncated or shifting list. */
function pc_folio_debtors_export_report(array $filters, callable $fetch) {
    $filters['limit'] = 200;
    $filters['offset'] = 0;
    $report = null;
    $seen = [];
    $rows = [];
    do {
        $page = $fetch($filters);
        if (is_wp_error($page)) {
            return $page;
        }
        if ($report === null) {
            $report = $page;
            $total = (int) ($page['summary']['matchedClients'] ?? -1);
            if ($total < 0 || $total > 20000) {
                return new WP_Error('export_limit', __('Too many customers to export. Narrow the filters and try again.', 'pc-folio-customer-balance'));
            }
        }
        $expected_summary = $report['summary'];
        $actual_summary = $page['summary'];
        unset($expected_summary['returnedClients'], $actual_summary['returnedClients']);
        if ($expected_summary != $actual_summary || $report['asOfDate'] !== $page['asOfDate']
            || count($page['debtors']) !== min(200, $total - count($rows))) {
            return new WP_Error('export_changed', __('The report changed during export. Please try again.', 'pc-folio-customer-balance'));
        }
        foreach ($page['debtors'] as $debtor) {
            $key = (string) ($debtor['partner']['shortName'] ?? '');
            if ($key === '' || isset($seen[$key])) {
                return new WP_Error('export_changed', __('The report changed during export. Please try again.', 'pc-folio-customer-balance'));
            }
            $seen[$key] = true;
            $rows[] = $debtor;
        }
        $filters['offset'] = count($rows);
    } while (count($rows) < $total);
    foreach (['commonDebt', 'deferredAmount', 'overdueDeferredAmount', 'prepaymentAmount', 'payableNow'] as $field) {
        $cents = array_sum(array_map(static fn($item) => (int) round((float) ($item[$field] ?? 0) * 100), $rows));
        if ($cents !== (int) round((float) ($report['summary'][$field . 'Total'] ?? 0) * 100)) {
            return new WP_Error('export_changed', __('The report changed during export. Please try again.', 'pc-folio-customer-balance'));
        }
    }
    $report['debtors'] = $rows;
    return $report;
}

function pc_folio_debtors_export_workbook(array $report): \PhpOffice\PhpSpreadsheet\Spreadsheet {
    $book = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sheet = $book->getActiveSheet();
    $sheet->setTitle(__('Folio debtors', 'pc-folio-customer-balance'));
    $text = static function ($cell, $value) use ($sheet) {
        // Names and search terms are data, including strings beginning with '='.
        $sheet->setCellValueExplicit($cell, (string) $value, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
    };
    $text('A1', __('Folio debtors', 'pc-folio-customer-balance'));
    $filters = $report['filters'];
    $text('A2', sprintf(__('As of: %s', 'pc-folio-customer-balance'), pc_folio_balance_export_date($report['asOfDate'])));
    $text('A3', sprintf(__('Threshold: more than %s UAH', 'pc-folio-customer-balance'), $filters['minPayable']));
    $text('A4', __('Customer search', 'pc-folio-customer-balance') . ': ' . ($filters['q'] ?? ''));
    $types = $filters['types'] ?? [];
    $text('A5', __('Customer type', 'pc-folio-customer-balance') . ': ' . (!$types ? __('All types', 'pc-folio-customer-balance') : (is_array($types) ? implode(', ', $types) : $types)));
    $headers = ['Folio customer', 'Folio short name', 'Type', 'Total debt', 'Deferred / on sale',
        'Overdue deferred / on sale', 'Payments marked PRD', 'Payable now', 'Customer on site'];
    foreach ($headers as $index => $label) {
        $text(chr(65 + $index) . '7', __($label, 'pc-folio-customer-balance'));
    }
    $types = ['П' => __('Partner', 'pc-folio-customer-balance'), 'Д' => __('Dealer', 'pc-folio-customer-balance'), 'К' => __('Buyer', 'pc-folio-customer-balance')];
    $fields = ['commonDebt', 'deferredAmount', 'overdueDeferredAmount', 'prepaymentAmount', 'payableNow'];
    $row = 8;
    foreach ($report['debtors'] as $debtor) {
        $partner = $debtor['partner'];
        $text('A' . $row, $partner['name'] ?? '');
        $text('B' . $row, $partner['shortName']);
        $text('C' . $row, $types[$partner['type'] ?? ''] ?? ($partner['type'] ?? ''));
        foreach ($fields as $index => $field) {
            $sheet->setCellValueExplicit(chr(68 + $index) . $row, (float) ($debtor[$field] ?? 0), \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_NUMERIC);
        }
        $text('I' . $row, implode('; ', array_column($debtor['siteUsers'] ?? [], 'displayName')));
        $row++;
    }
    $text('A' . $row, __('Total', 'pc-folio-customer-balance'));
    foreach ($fields as $index => $field) {
        $column = chr(68 + $index);
        $sheet->setCellValue($column . $row, $row > 8 ? '=SUM(' . $column . '8:' . $column . ($row - 1) . ')' : 0);
    }
    foreach (range(1, 5) as $r) $sheet->mergeCells('A' . $r . ':I' . $r);
    $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(16);
    $sheet->getStyle('A7:I7')->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
    $sheet->getStyle('A7:I7')->getFill()->setFillType('solid')->getStartColor()->setRGB('4472C4');
    $sheet->getStyle('A7:I' . $row)->getAlignment()->setWrapText(true);
    $sheet->getStyle('D8:H' . $row)->getNumberFormat()->setFormatCode('#,##0.00');
    $sheet->getStyle('H8:H' . $row)->getFill()->setFillType('solid')->getStartColor()->setRGB('FFF2CC');
    $sheet->getStyle('A' . $row . ':I' . $row)->getFont()->setBold(true);
    $sheet->setAutoFilter('A7:I' . max(7, $row - 1));
    $sheet->freezePane('D8');
    foreach ([40, 18, 16, 20, 22, 24, 24, 20, 30] as $index => $width) {
        $sheet->getColumnDimension(chr(65 + $index))->setWidth($width);
    }
    return $book;
}

function pc_folio_debtors_export_xlsx(): void {
    if (!pc_folio_debtors_can_view()) {
        wp_die(esc_html__('You do not have permission to view this report.', 'pc-folio-customer-balance'), '', ['response' => 403]);
    }
    check_admin_referer('pc_folio_customer_debtors_export');
    $filters = pc_folio_debtors_filters_from_request();
    if (is_wp_error($filters)) wp_die(esc_html($filters->get_error_message()), '', ['response' => 400]);
    if (!class_exists(\PhpOffice\PhpSpreadsheet\Spreadsheet::class)) {
        wp_die(esc_html__('XLSX export is temporarily unavailable.', 'pc-folio-customer-balance'), '', ['response' => 503]);
    }
    $before = pc_folio_debtors_snapshot_request('GET');
    if (is_wp_error($before)) wp_die(esc_html($before->get_error_message()));
    $report = pc_folio_debtors_export_report($filters, 'pc_folio_debtors_fetch');
    if (is_wp_error($report)) wp_die(esc_html($report->get_error_message()), '', ['response' => 502]);
    $after = pc_folio_debtors_snapshot_request('GET');
    if (is_wp_error($after) || ($before['activeSnapshot'] ?? null) != ($after['activeSnapshot'] ?? null)) {
        wp_die(esc_html__('The report changed during export. Please try again.', 'pc-folio-customer-balance'), '', ['response' => 409]);
    }
    $book = pc_folio_debtors_export_workbook($report);
    while (ob_get_level() > 0) ob_end_clean();
    nocache_headers();
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="folio-debtors-' . wp_date('Ymd-His') . '.xlsx"');
    header('X-Content-Type-Options: nosniff');
    (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($book))->save('php://output');
    $book->disconnectWorksheets();
    exit;
}
add_action('admin_post_pc_folio_customer_debtors_export', 'pc_folio_debtors_export_xlsx');
