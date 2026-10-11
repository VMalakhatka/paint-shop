<?php
namespace PaintCore\PCOE;
defined('ABSPATH') || exit;

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/** A review copy of the saved approval, never a newly calculated payment invoice. */
final class ApprovalWorkbook {
    public static function available(): bool { return class_exists(Spreadsheet::class) && class_exists(Xlsx::class); }

    /** Caller owns cleanup after synchronous wp_mail; the file is outside public uploads. */
    public static function attachment(int $id, array $data): array {
        if (!self::available()) throw new \RuntimeException(__('Excel export is unavailable. Contact the site administrator.', 'pc-order-import-export'));
        $path = tempnam(sys_get_temp_dir(), 'pcoe-approval-');
        if (!$path) throw new \RuntimeException(__('Could not create the Excel file. Nothing was sent.', 'pc-order-import-export'));
        if (str_starts_with(realpath($path), rtrim(realpath(ABSPATH), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR)) {
            unlink($path);
            throw new \RuntimeException(__('Could not create the Excel file. Nothing was sent.', 'pc-order-import-export'));
        }
        register_shutdown_function(static function () use ($path): void { if (is_file($path)) unlink($path); });
        $book = null;
        try {
            $book = self::workbook($id, $data);
            (new Xlsx($book))->save($path);
            if (!is_file($path) || filesize($path) === 0) throw new \RuntimeException('Empty workbook');
            return ['confirmation-'.$id.'-'.substr($data['revision'], 0, 12).'.xlsx' => $path];
        } catch (\Throwable $e) {
            if (is_file($path)) unlink($path);
            throw new \RuntimeException(__('Could not create the Excel file. Nothing was sent.', 'pc-order-import-export'), 0, $e);
        } finally { if ($book) $book->disconnectWorksheets(); }
    }

    public static function download(): void {
        $paths = [];
        try {
            if (!current_user_can('manage_woocommerce')) throw new \RuntimeException('Forbidden');
            check_admin_referer('pcoe_approval_download');
            $id = absint($_POST['approval_id'] ?? 0); $data = ApprovalNotifications::data($id);
            if (!hash_equals($data['revision'], (string)($_POST['revision'] ?? ''))
                || !hash_equals($data['revision'], CustomerApproval::revision(CustomerApproval::snapshot($data['source'])))) throw new \RuntimeException(__('The order changed. Review it again before continuing.', 'pc-order-import-export'));
            $paths = self::attachment($id, $data);
            nocache_headers(); header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Disposition: attachment; filename="'.array_key_first($paths).'"');
            readfile(reset($paths));
        } catch (\Throwable $e) { wp_die(esc_html($e->getMessage()), '', ['response' => 409]); }
        finally { foreach ($paths as $path) if (is_file($path)) unlink($path); }
        exit;
    }

    public static function workbook(int $id, array $data): Spreadsheet {
        $book = new Spreadsheet();
        $book->getDefaultStyle()->getFont()->setName('Calibri')->setSize(11);
        $customer = get_userdata((int) $data['source']['customer_id']);
        $details = [];
        $order = !empty($data['source']['order_id']) ? wc_get_order($data['source']['order_id']) : false;
        if ($order && (int) $order->get_customer_id() === (int) $data['source']['customer_id']) {
            foreach (ManagerNotifications::documents($order) as $doc) {
                $details[] = __('Folio account', 'pc-order-import-export').' #'.$doc['number'].' · '.($doc['date'] ?: __('Document date unavailable', 'pc-order-import-export'));
            }
        }
        $snapshots = !empty($data['snapshot']['bundle']) ? [$data['snapshot']] : array_merge([$data['snapshot']], $data['snapshot']['documents'] ?? []);
        foreach ($snapshots as $index => $snapshot) {
            $sheet = $index ? $book->createSheet() : $book->getActiveSheet();
            // Fixed translated names plus a sequence avoid invalid/duplicate document sheet names.
            $sheet->setTitle($index ? __('Folio account', 'pc-order-import-export').' '.$index : __('Order confirmation', 'pc-order-import-export'));
            self::sheet($sheet, $id, $snapshot, $customer ? $customer->display_name : '', $index ? [] : $details);
        }
        $book->setActiveSheetIndex(0);
        return $book;
    }

    private static function text(Worksheet $sheet, string $cell, string $text): void {
        // Customer names, SKUs and product titles must never become spreadsheet formulas.
        $sheet->setCellValueExplicit($cell, html_entity_decode(wp_strip_all_tags($text), ENT_QUOTES, 'UTF-8'), DataType::TYPE_STRING);
    }

    private static function sheet(Worksheet $sheet, int $id, array $snapshot, string $customer, array $details): void {
        $row = 1;
        $bundle = !empty($snapshot['bundle']); $end = $bundle ? 'G' : 'E';
        $line = static function (string $text, int $height = 28) use ($sheet, &$row, $end): void {
            $sheet->mergeCells("A$row:{$end}$row");
            self::text($sheet, "A$row", $text);
            $lines = max(count(explode("\n", $text)), (int) ceil(mb_strlen($text) / 105));
            $sheet->getRowDimension($row)->setRowHeight(min(409, max($height, $lines * 16)));
            $row++;
        };
        $line(__('Order confirmation', 'pc-order-import-export'), 34);
        $sheet->getStyle('A1:'.$end.'1')->getFont()->setBold(true)->setSize(18)->getColor()->setARGB('FFFFFFFF');
        $sheet->getStyle('A1:'.$end.'1')->getFill()->setFillType('solid')->getStartColor()->setARGB('FF324D68');
        $line((string) $snapshot['title']);
        $line(__('Customer', 'pc-order-import-export').': '.$customer);
        $line(sprintf(__('Confirmation request #%s', 'pc-order-import-export'), $id));
        if (!empty($snapshot['warehouse'])) $line(__('Warehouse', 'pc-order-import-export').': '.$snapshot['warehouse']);
        foreach ($details as $detail) $line($detail);
        foreach (['information', 'note'] as $key) if (!empty($snapshot[$key])) $line((string) $snapshot[$key], 42);
        $line(__('Review the attached items and reply to your manager with agreement or corrections. To confirm on the website and choose delivery, use the link in the email.', 'pc-order-import-export'), 44);
        if (!empty($snapshot['payment_details'])) {
            $line(__('Payment details', 'pc-order-import-export'));
            foreach (ApprovalBundle::payment_labels() as $key => $label) if (!empty($snapshot['payment_details'][$key])) $line($label.': '.$snapshot['payment_details'][$key]);
            $line(__('Before payment, agree the amount with your manager, including any payments already made.', 'pc-order-import-export'), 36);
        }
        $header = $row++;
        $columns = ['A' => __('SKU', 'pc-order-import-export'), 'B' => __('Product', 'pc-order-import-export'),
            'C' => __('Quantity', 'pc-order-import-export'), 'D' => __('Price', 'pc-order-import-export'), 'E' => __('Amount', 'pc-order-import-export')];
        if ($bundle) $columns += ['F' => __('Folio account', 'pc-order-import-export'), 'G' => __('Warehouse', 'pc-order-import-export')];
        foreach ($columns as $col => $label) self::text($sheet, $col.$header, $label);
        $sheet->getRowDimension($header)->setRowHeight(26);
        $sheet->getStyle("A$header:{$end}$header")->getFont()->setBold(true);
        $sheet->getStyle("A$header:{$end}$header")->getFill()->setFillType('solid')->getStartColor()->setARGB('FFE5EDF5');
        $first = $row;
        foreach ($snapshot['items'] as $item) {
            self::text($sheet, 'A'.$row, (string) $item['sku']);
            self::text($sheet, 'B'.$row, (string) $item['name']);
            if ($bundle) { self::text($sheet, 'F'.$row, $item['document']); self::text($sheet, 'G'.$row, $item['warehouse']); }
            foreach (['C' => 'quantity', 'D' => 'price', 'E' => 'amount'] as $col => $key) {
                if (!is_numeric($item[$key]) || !is_finite((float) $item[$key])) throw new \RuntimeException('Invalid snapshot amount');
                $sheet->setCellValueExplicit($col.$row, (float) $item[$key], DataType::TYPE_NUMERIC);
            }
            $sheet->getRowDimension($row)->setRowHeight(max(32, 16 * (int) ceil(mb_strlen((string) $item['name']) / 45)));
            if (($row - $first) % 2 === 1) $sheet->getStyle("A$row:{$end}$row")->getFill()->setFillType('solid')->getStartColor()->setARGB('FFF4F7FA');
            $row++;
        }
        $last = $row - 1;
        $sheet->getStyle("C$first:C$last")->getNumberFormat()->setFormatCode('0.###');
        $sheet->getStyle("D$first:E$last")->getNumberFormat()->setFormatCode('#,##0.00');
        $sheet->mergeCells("A$row:D$row");
        self::text($sheet, 'A'.$row, __('Document total', 'pc-order-import-export').' · '.$snapshot['currency']);
        if (!is_numeric($snapshot['total']) || !is_finite((float) $snapshot['total'])) throw new \RuntimeException('Invalid snapshot total');
        // Preserve the agreed total, including Woo shipping/fees/taxes; do not sum linked documents twice.
        $sheet->setCellValueExplicit('E'.$row, (float) $snapshot['total'], DataType::TYPE_NUMERIC);
        $sheet->getStyle("E$row")->getNumberFormat()->setFormatCode('#,##0.00');
        $sheet->getStyle("A$row:{$end}$row")->getFont()->setBold(true);
        $sheet->getStyle("A$row:{$end}$row")->getFill()->setFillType('solid')->getStartColor()->setARGB('FFE5EDF5');
        $sheet->getRowDimension($row++)->setRowHeight(30);
        if ($bundle) $line(__('Selected account totals are combined once. Original Folio accounts and Woo orders remain separate. This is not a calculation of unpaid debt.', 'pc-order-import-export'), 44);
        else $line(__('The document total includes any delivery charges, fees and taxes already saved in this document. Linked Folio sheets are details of the same order; do not add their totals to the order total.', 'pc-order-import-export'), 44);
        $line(__('A reply by email does not automatically confirm the order on the website or change its contents.', 'pc-order-import-export'), 36);
        $link = $row;
        $line(__('Open confirmation', 'pc-order-import-export'));
        $sheet->getCell('A'.$link)->getHyperlink()->setUrl(CustomerApproval::customer_url($id));
        $sheet->getStyle('A'.$link)->getFont()->setUnderline(true)->getColor()->setARGB('FF165DAB');
        foreach (['A' => 22, 'B' => 53, 'C' => 12, 'D' => 15, 'E' => 17] as $col => $width) $sheet->getColumnDimension($col)->setWidth($width);
        if ($bundle) { $sheet->getColumnDimension('F')->setWidth(27); $sheet->getColumnDimension('G')->setWidth(22); }
        $sheet->getStyle('A1:'.$end.$row)->getAlignment()->setWrapText(true)->setVertical('center');
        $sheet->getStyle("C$first:E$last")->getAlignment()->setHorizontal('right');
        $sheet->freezePane('C'.$first);
        $sheet->setAutoFilter("A$header:{$end}$last")->setShowGridlines(false);
        $sheet->getPageSetup()->setOrientation('landscape')->setPaperSize(9)->setFitToWidth(1)->setFitToHeight(0)->setPrintArea('A1:'.$end.($row - 1))->setRowsToRepeatAtTopByStartAndEnd($header, $header);
    }
}
