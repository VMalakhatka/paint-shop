<?php
defined('ABSPATH') || exit;

function pc_folio_balance_add_preorders_sheet(\PhpOffice\PhpSpreadsheet\Spreadsheet $spreadsheet, array $report): void {
    $preorder_sheet = $spreadsheet->createSheet();
    $preorder_sheet->setTitle(__('Preorder invoices', 'pc-folio-customer-balance'));
    $preorder_headers = [__('Document No.', 'pc-folio-customer-balance'), __('Date', 'pc-folio-customer-balance'), __('Warehouse', 'pc-folio-customer-balance'), __('Amount', 'pc-folio-customer-balance'), __('Information', 'pc-folio-customer-balance')];
    $preorder_sheet->fromArray($preorder_headers, null, 'A1');
    $preorder_row = 2;
    foreach (($report['preorders'] ?? []) as $preorder) {
        foreach (['A' => $preorder['documentNumber'] ?? '', 'B' => pc_folio_balance_export_date($preorder['documentDate'] ?? ''), 'C' => $preorder['warehouseLabel'] ?? '', 'E' => $preorder['information'] ?? ''] as $col => $value) {
            $preorder_sheet->setCellValueExplicit($col . $preorder_row, (string) $value, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
        }
        $preorder_sheet->setCellValueExplicit('D' . $preorder_row, (float) ($preorder['amount'] ?? 0), \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_NUMERIC);
        $preorder_row++;
    }
    $preorder_sheet->setCellValueExplicit('C' . $preorder_row, __('Preorder invoices', 'pc-folio-customer-balance'), \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
    $preorder_sheet->setCellValueExplicit('D' . $preorder_row, (float) ($report['summary']['prepaymentAmount'] ?? 0), \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_NUMERIC);
    $preorder_sheet->getStyle('D2:D' . $preorder_row)->getNumberFormat()->setFormatCode('#,##0.00');
    $preorder_sheet->getStyle('A1:E1')->getFont()->setBold(true);
    foreach (['A' => 18, 'B' => 15, 'C' => 24, 'D' => 20, 'E' => 65] as $col => $width) $preorder_sheet->getColumnDimension($col)->setWidth($width);
    $preorder_sheet->getStyle('A1:E' . $preorder_row)->getAlignment()->setWrapText(true);
    $preorder_sheet->freezePane('A2');
    // Keep the data sheet, but make the reserve visible in the main reconciliation too.
    $balance_sheet = $spreadsheet->getSheet(0);
    $heading_row = $balance_sheet->getHighestDataRow() + 3;
    $balance_sheet->mergeCells('A' . $heading_row . ':N' . $heading_row);
    $balance_sheet->setCellValueExplicit('A' . $heading_row, __('Preorder invoices', 'pc-folio-customer-balance'), \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
    $balance_sheet->getStyle('A' . $heading_row)->getFont()->setBold(true)->setSize(14);
    $header_row = $heading_row + 1;
    // Use the ledger's existing widths, merging cells for readable invoice information.
    $columns = ['A:B', 'C:D', 'E:F', 'G:H', 'I:N'];
    for ($source_row = 1; $source_row <= $preorder_row; $source_row++) {
        $target_row = $header_row + $source_row - 1;
        foreach ($columns as $index => $range) {
            [$first, $last] = explode(':', $range);
            $balance_sheet->mergeCells($first . $target_row . ':' . $last . $target_row);
            $source_cell = $preorder_sheet->getCell(chr(65 + $index) . $source_row);
            $balance_sheet->setCellValueExplicit($first . $target_row, $source_cell->getValue(), $source_cell->getDataType());
        }
        $balance_sheet->getRowDimension($target_row)->setRowHeight(30);
        if ($source_row > 1) $balance_sheet->getStyle('G' . $target_row)->getNumberFormat()->setFormatCode('#,##0.00');
    }
    $last_row = $header_row + $preorder_row - 1;
    $balance_sheet->getStyle('A' . $header_row . ':N' . $last_row)->getAlignment()->setWrapText(true);
    $balance_sheet->getStyle('A' . $header_row . ':N' . $header_row)->getFont()->setBold(true);
    $balance_sheet->getStyle('A' . $last_row . ':N' . $last_row)->getFont()->setBold(true);
    $balance_sheet->getStyle('A' . $header_row . ':N' . $last_row)->getBorders()->getAllBorders()
        ->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN);
    $spreadsheet->setActiveSheetIndex(0);

}
