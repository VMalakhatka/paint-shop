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
    $spreadsheet->setActiveSheetIndex(0);

}
