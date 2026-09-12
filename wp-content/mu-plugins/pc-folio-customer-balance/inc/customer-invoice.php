<?php

defined('ABSPATH') || exit;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

function pc_folio_invoice_settings(): array {
    return wp_parse_args((array) get_option('pc_folio_invoice_settings', []), [
        'name' => '', 'tax_id' => '', 'iban' => '', 'bank' => '', 'mfo' => '',
        'confirmed' => false, 'logo_id' => (int) get_theme_mod('custom_logo'),
    ]);
}

function pc_folio_invoice_validate_settings(array $settings) {
    foreach (['name', 'tax_id', 'iban', 'bank'] as $key) {
        if (trim((string) ($settings[$key] ?? '')) === '') {
            return new WP_Error('invoice_settings', __('Payment invoice requisites must be configured and confirmed by the manager.', 'pc-folio-customer-balance'));
        }
    }
    $iban = strtoupper(preg_replace('/\s+/', '', $settings['iban']));
    if (!preg_match('/^UA[0-9]{27}$/', $iban) || !preg_match('/^\d{8}(\d{2})?$/', $settings['tax_id'])) {
        return new WP_Error('invoice_settings', __('Check the IBAN and tax ID.', 'pc-folio-customer-balance'));
    }
    $digits = substr($iban, 4) . '3010' . substr($iban, 2, 2);
    $remainder = 0;
    foreach (str_split($digits) as $digit) {
        $remainder = ($remainder * 10 + (int) $digit) % 97;
    }
    if ($remainder !== 1) {
        return new WP_Error('invoice_iban', __('Check the IBAN and tax ID.', 'pc-folio-customer-balance'));
    }
    if (empty($settings['confirmed'])) {
        return new WP_Error('invoice_unconfirmed', __('Payment invoice requisites must be configured and confirmed by the manager.', 'pc-folio-customer-balance'));
    }
    return true;
}

add_action('admin_menu', static function (): void {
    add_submenu_page(
        function_exists('paint_core_lavka_admin_parent_slug') ? paint_core_lavka_admin_parent_slug() : 'woocommerce',
        __('Lavka: payment invoices', 'pc-folio-customer-balance'),
        __('Lavka: payment invoices', 'pc-folio-customer-balance'),
        'manage_options', 'pc-folio-invoices', 'pc_folio_invoice_render_settings'
    );
}, 60);

function pc_folio_invoice_render_settings(): void {
    if (!current_user_can('manage_options')) return;
    $settings = pc_folio_invoice_settings();
    $fields = [
        'name' => __('Supplier / payment recipient', 'pc-folio-customer-balance'),
        'tax_id' => __('Tax ID', 'pc-folio-customer-balance'),
        'iban' => __('IBAN', 'pc-folio-customer-balance'),
        'bank' => __('Bank', 'pc-folio-customer-balance'),
        'mfo' => __('Bank code (MFO)', 'pc-folio-customer-balance'),
        'logo_id' => __('Logo media ID', 'pc-folio-customer-balance'),
    ];
    echo '<div class="wrap"><h1>' . esc_html__('Lavka: payment invoices', 'pc-folio-customer-balance') . '</h1>';
    if (isset($_GET['saved'])) echo '<div class="notice notice-success"><p>' . esc_html__('Settings saved.', 'pc-folio-customer-balance') . '</p></div>';
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="pc_folio_invoice_settings">';
    wp_nonce_field('pc_folio_invoice_settings');
    echo '<table class="form-table">';
    foreach ($fields as $key => $label) {
        echo '<tr><th><label for="invoice-' . esc_attr($key) . '">' . esc_html($label) . '</label></th><td><input class="regular-text" id="invoice-' . esc_attr($key) . '" name="' . esc_attr($key) . '" type="' . ($key === 'logo_id' ? 'number' : 'text') . '" value="' . esc_attr((string) $settings[$key]) . '">';
        if ($key === 'logo_id') echo ' <button type="button" class="button" id="pc-invoice-logo">' . esc_html__('Choose logo', 'pc-folio-customer-balance') . '</button>';
        echo '</td></tr>';
    }
    echo '</table><p><label><input type="checkbox" name="confirmed" value="1" ' . checked(!empty($settings['confirmed']), true, false) . '> ' . esc_html__('I have verified the payment recipient and bank details for generated invoices.', 'pc-folio-customer-balance') . '</label></p>';
    submit_button();
    echo '</form></div>';
}

add_action('admin_enqueue_scripts', static function (): void {
    if (($_GET['page'] ?? '') !== 'pc-folio-invoices' || !current_user_can('manage_options')) return;
    wp_enqueue_media();
    wp_add_inline_script('media-editor', 'document.addEventListener("DOMContentLoaded",function(){var button=document.getElementById("pc-invoice-logo");if(!button)return;button.addEventListener("click",function(){var frame=wp.media({multiple:false,library:{type:["image/png","image/jpeg"]}});frame.on("select",function(){document.getElementById("invoice-logo_id").value=frame.state().get("selection").first().id;});frame.open();});});');
});

add_action('admin_post_pc_folio_invoice_settings', static function (): void {
    if (!current_user_can('manage_options')) wp_die('', '', ['response' => 403]);
    check_admin_referer('pc_folio_invoice_settings');
    $settings = [];
    foreach (['name', 'tax_id', 'iban', 'bank', 'mfo'] as $key) {
        $settings[$key] = sanitize_text_field(wp_unslash((string) ($_POST[$key] ?? '')));
    }
    $settings['iban'] = strtoupper(preg_replace('/\s+/', '', $settings['iban']));
    $settings['logo_id'] = absint($_POST['logo_id'] ?? 0);
    $settings['confirmed'] = !empty($_POST['confirmed']);
    if ($settings['confirmed']) {
        $valid = pc_folio_invoice_validate_settings($settings);
        if (is_wp_error($valid)) wp_die(esc_html($valid->get_error_message()), '', ['response' => 400, 'back_link' => true]);
    }
    update_option('pc_folio_invoice_settings', $settings, false);
    wp_safe_redirect(add_query_arg(['page' => 'pc-folio-invoices', 'saved' => 1], admin_url('admin.php')));
    exit;
});

/** Normalize the authoritative document without repricing or dropping unavailable products. */
function pc_folio_invoice_model(array $result, string $type, int $id) {
    $doc = $result['document'] ?? [];
    if (!in_array($type, ['ACCOUNT', 'EXPENSE'], true)
        || ($doc['documentType'] ?? '') !== $type || (int) ($doc['documentId'] ?? 0) !== $id
        || !empty($doc['returnDocument']) || empty($doc['items']) || !is_array($doc['items'])) {
        return new WP_Error('invoice_document', __('This document cannot be used for a payment invoice.', 'pc-folio-customer-balance'));
    }
    $date = $doc['documentDate'] ?? '';
    if (is_array($date) && count($date) >= 3) $date = sprintf('%04d-%02d-%02d', $date[0], $date[1], $date[2]);
    $date = substr((string) $date, 0, 10);
    $number = trim((string) ($doc['documentNumber'] ?? '') . (string) ($doc['documentNumberSuffix'] ?? ''));
    if ($number === '' || !pc_folio_balance_valid_date($date) || !is_numeric($doc['totalAmount'] ?? null)) {
        return new WP_Error('invoice_data', __('The document has incomplete or inconsistent amounts. Contact the manager.', 'pc-folio-customer-balance'));
    }
    $rows = [];
    $sum_cents = 0;
    $missing = 0;
    foreach ($doc['items'] as $item) {
        foreach (['quantity', 'price', 'amount'] as $field) {
            if (!is_numeric($item[$field] ?? null) || !is_finite((float) $item[$field]) || (float) $item[$field] < 0) {
                return new WP_Error('invoice_amount', __('The document has incomplete or inconsistent amounts. Contact the manager.', 'pc-folio-customer-balance'));
            }
        }
        if (!empty($item['returnLine'])) return new WP_Error('invoice_return', __('This document cannot be used for a payment invoice.', 'pc-folio-customer-balance'));
        $sku = trim((string) ($item['sku'] ?? ''));
        $product_id = $sku !== '' && function_exists('wc_get_product_id_by_sku') ? wc_get_product_id_by_sku($sku) : 0;
        $product = $product_id ? wc_get_product($product_id) : false;
        $barcode = $product && function_exists('psu_product_display_barcode') ? psu_product_display_barcode($product) : '';
        if ($barcode === '') $missing++;
        $rows[] = [$sku, $barcode, (string) ($item['name'] ?? ''), (float) $item['quantity'], (float) $item['price'], (float) $item['amount']];
        $sum_cents += (int) round((float) $item['amount'] * 100);
    }
    $total_cents = (int) round((float) $doc['totalAmount'] * 100);
    if ($sum_cents !== $total_cents || $total_cents <= 0) {
        return new WP_Error('invoice_total', __('The document has incomplete or inconsistent amounts. Contact the manager.', 'pc-folio-customer-balance'));
    }
    return [
        'number' => $number, 'date' => $date, 'type' => $type,
        'customer' => (string) ($doc['payerName'] ?? $result['partner']['name'] ?? ''),
        'rows' => $rows, 'total' => $total_cents / 100, 'missing' => $missing,
    ];
}

function pc_folio_invoice_workbook(array $model, array $settings): Spreadsheet {
    $book = new Spreadsheet();
    $sheet = $book->getActiveSheet();
    $sheet->setTitle(__('Payment invoice', 'pc-folio-customer-balance'));
    $book->getDefaultStyle()->getFont()->setName('Calibri')->setSize(11);
    $string = static function (string $cell, string $value) use ($sheet): void {
        $sheet->setCellValueExplicit($cell, $value, DataType::TYPE_STRING);
    };
    $sheet->mergeCells('A1:C3')->mergeCells('D1:G2')->mergeCells('D3:G3');
    $string('A1', get_bloginfo('name'));
    $string('D1', sprintf(__('Payment invoice No. %s', 'pc-folio-customer-balance'), $model['number']));
    $string('D3', sprintf(__('Document date: %s', 'pc-folio-customer-balance'), date('d.m.Y', strtotime($model['date']))));
    $sheet->getStyle('D1:G2')->getFont()->setBold(true)->setSize(18);
    $sheet->getStyle('A1:G3')->getAlignment()->setWrapText(true)->setVertical('center');
    $logo = !empty($settings['logo_id']) ? get_attached_file((int) $settings['logo_id']) : '';
    if (!$logo || !is_file($logo) || !in_array(wp_check_filetype($logo)['ext'], ['png', 'jpg', 'jpeg'], true)) {
        $logo = __DIR__ . '/../assets/lavka-invoice-logo.png';
    }
    if ($logo && is_file($logo) && in_array(wp_check_filetype($logo)['ext'], ['png', 'jpg', 'jpeg'], true)) {
        $drawing = new \PhpOffice\PhpSpreadsheet\Worksheet\Drawing();
        $drawing->setName('Logo')->setPath($logo)->setHeight(65)->setCoordinates('A1')->setOffsetX(6)->setOffsetY(4)->setWorksheet($sheet);
        $string('A1', '');
    }
    $headers = [
        5 => [__('Customer', 'pc-folio-customer-balance'), $model['customer']],
        6 => [__('Supplier / payment recipient', 'pc-folio-customer-balance'), $settings['name']],
        7 => [__('Tax ID', 'pc-folio-customer-balance'), $settings['tax_id']],
        8 => [__('IBAN', 'pc-folio-customer-balance'), $settings['iban']],
        9 => [__('Bank', 'pc-folio-customer-balance'), $settings['bank'] . ($settings['mfo'] ? ' / ' . $settings['mfo'] : '')],
    ];
    foreach ($headers as $row => [$label, $value]) {
        $sheet->mergeCells("A$row:B$row")->mergeCells("C$row:G$row");
        $string("A$row", $label);
        $string("C$row", $value);
        $sheet->getRowDimension($row)->setRowHeight(32);
    }
    $sheet->mergeCells('A11:G11');
    $string('A11', __('Full source document amount, not the outstanding balance. No new Folio document is created.', 'pc-folio-customer-balance'));
    $sheet->getRowDimension(11)->setRowHeight(32);
    $labels = [__('Line', 'pc-folio-customer-balance'), __('SKU', 'pc-folio-customer-balance'), __('Barcode', 'pc-folio-customer-balance'), __('Name', 'pc-folio-customer-balance'), __('Quantity', 'pc-folio-customer-balance'), __('Price, UAH', 'pc-folio-customer-balance'), __('Amount, UAH', 'pc-folio-customer-balance')];
    foreach ($labels as $index => $label) $string(chr(65 + $index) . '13', $label);
    $row = 14;
    foreach ($model['rows'] as $index => $values) {
        $sheet->setCellValueExplicit("A$row", $index + 1, DataType::TYPE_NUMERIC);
        foreach ($values as $col => $value) {
            $sheet->setCellValueExplicit(chr(66 + $col) . $row, $value, $col < 3 ? DataType::TYPE_STRING : DataType::TYPE_NUMERIC);
        }
        $sheet->getRowDimension($row)->setRowHeight(max(34, 15 * (int) ceil(mb_strlen($values[2]) / 38)));
        if ($row % 2 === 0) $sheet->getStyle("A$row:G$row")->getFill()->setFillType('solid')->getStartColor()->setRGB('F1F5F3');
        $row++;
    }
    $sheet->mergeCells("A$row:F$row");
    $string("A$row", __('Total, UAH', 'pc-folio-customer-balance'));
    $sheet->setCellValue("G$row", '=SUM(G14:G' . ($row - 1) . ')');
    $sheet->getStyle("A$row:G$row")->getFont()->setBold(true);
    $sheet->getRowDimension($row)->setRowHeight(28);
    $note = $row + 2;
    $sheet->mergeCells("A$note:G$note");
    $string("A$note", sprintf(__('Current catalogue barcodes. Items without a verified barcode: %d. Prices and amounts are from the source Folio document.', 'pc-folio-customer-balance'), $model['missing']));
    $sheet->getRowDimension($note)->setRowHeight(32);
    $sheet->getStyle("A1:G$note")->getAlignment()->setWrapText(true)->setVertical('center');
    $sheet->getStyle('A13:G13')->getFill()->setFillType('solid')->getStartColor()->setRGB('25634D');
    $sheet->getStyle('A13:G13')->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
    $sheet->getRowDimension(13)->setRowHeight(30);
    $sheet->getStyle("F14:G$row")->getNumberFormat()->setFormatCode('#,##0.00');
    $sheet->getStyle("E14:E$row")->getNumberFormat()->setFormatCode('0.###');
    foreach (['A' => 8, 'B' => 20, 'C' => 21, 'D' => 44, 'E' => 12, 'F' => 15, 'G' => 16] as $col => $width) $sheet->getColumnDimension($col)->setWidth($width);
    $sheet->freezePane('E14');
    $sheet->setAutoFilter('A13:G' . ($row - 1));
    $sheet->getPageSetup()->setPaperSize(9)->setOrientation('landscape')->setFitToWidth(1)->setFitToHeight(0)->setRowsToRepeatAtTopByStartAndEnd(13, 13)->setPrintArea("A1:G$note");
    $sheet->setShowGridlines(false);
    return $book;
}

/** One atomic per-user reservation prevents double clicks and concurrent mail sends. */
function pc_folio_invoice_reserve_mail(int $user_id, string $request_key) {
    $key = 'pc_folio_invoice_mail_' . $user_id;
    $state = get_option($key, []);
    if (($state['request'] ?? '') === $request_key) {
        return ($state['status'] ?? '') === 'accepted' ? 'accepted' : new WP_Error('invoice_mail_unknown', __('This email request was already attempted. Check your inbox before sending again.', 'pc-folio-customer-balance'));
    }
    if ($state && (int) ($state['until'] ?? 0) > time()) {
        return new WP_Error('invoice_mail_busy', __('Please wait before sending another invoice email.', 'pc-folio-customer-balance'));
    }
    if ($state) {
        global $wpdb;
        // Compare-and-delete: a competing request may already have reserved this slot.
        $removed = $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", $key, maybe_serialize($state)));
        wp_cache_delete($key, 'options');
        if (!$removed) return new WP_Error('invoice_mail_busy', __('Please wait before sending another invoice email.', 'pc-folio-customer-balance'));
    }
    if (!add_option($key, ['request' => $request_key, 'status' => 'pending', 'until' => time() + 600], '', false)) {
        return new WP_Error('invoice_mail_busy', __('Please wait before sending another invoice email.', 'pc-folio-customer-balance'));
    }
    return true;
}

function pc_folio_invoice_ajax(): void {
    check_ajax_referer('pc_folio_customer_documents');
    $context = pc_folio_documents_request_context();
    if (is_wp_error($context)) pc_folio_documents_send_error($context);
    $type = strtoupper(sanitize_key(wp_unslash((string) ($_POST['document_type'] ?? ''))));
    $id = absint($_POST['document_id'] ?? 0);
    $mode = sanitize_key($_POST['mode'] ?? '');
    if (!in_array($type, ['ACCOUNT', 'EXPENSE'], true) || !$id || !in_array($mode, ['download', 'email'], true)) {
        wp_send_json_error(['message' => __('Select a valid Folio document.', 'pc-folio-customer-balance')], 400);
    }
    $email = trim(wp_unslash((string) ($_POST['email'] ?? '')));
    $request_key = (string) ($_POST['request_key'] ?? '');
    if ($mode === 'email' && (!is_email($email) || strlen($email) > 254 || !preg_match('/^[a-f0-9-]{36}$/i', $request_key))) {
        wp_send_json_error(['message' => __('Enter a valid recipient email.', 'pc-folio-customer-balance')], 400);
    }
    $settings = pc_folio_invoice_settings();
    $valid = pc_folio_invoice_validate_settings($settings);
    if (is_wp_error($valid)) pc_folio_documents_send_error($valid);
    if (!class_exists(Spreadsheet::class)) wp_send_json_error(['message' => __('XLSX export is temporarily unavailable.', 'pc-folio-customer-balance')], 503);
    $result = pc_folio_documents_fetch_detail($context, $type, $id, false);
    if (is_wp_error($result)) pc_folio_documents_send_error($result);
    $model = pc_folio_invoice_model($result, $type, $id);
    if (is_wp_error($model)) pc_folio_documents_send_error($model);
    $mail_option = 'pc_folio_invoice_mail_' . (int) $context['user_id'];
    if ($mode === 'email') {
        $reservation = pc_folio_invoice_reserve_mail((int) $context['user_id'], $request_key);
        if (is_wp_error($reservation)) pc_folio_documents_send_error($reservation);
        if ($reservation === 'accepted') wp_send_json_success(['result' => ['accepted' => true]]);
    }
    $path = '';
    $book = null;
    $error = null;
    $bytes = '';
    $filename = 'folio-invoice-' . strtolower($type) . '-' . $id . '.xlsx';
    // Private temporary file, removed even on mail/writer failure; never stored in uploads.
    try {
        $path = tempnam(sys_get_temp_dir(), 'folio-invoice-');
        if (!$path) throw new RuntimeException('Temporary file unavailable');
        chmod($path, 0600);
        $book = pc_folio_invoice_workbook($model, $settings);
        (new Xlsx($book))->save($path);
        if ($mode === 'email') {
            $subject = sprintf(__('Payment invoice No. %s', 'pc-folio-customer-balance'), $model['number']);
            $subject = str_replace(["\r", "\n"], ' ', $subject);
            $body = __('Your payment invoice is attached as an Excel file. It contains the full source document amount, not the outstanding balance.', 'pc-folio-customer-balance');
            $accepted = wp_mail($email, $subject, $body, [], [$filename => $path]);
            update_option($mail_option, ['request' => $request_key, 'status' => $accepted ? 'accepted' : 'failed', 'until' => time() + 60], false);
            if (!$accepted) throw new RuntimeException('Mail not accepted');
        } else {
            $bytes = file_get_contents($path);
            if ($bytes === false) throw new RuntimeException('File unavailable');
        }
    } catch (Throwable $exception) {
        $error = __('The invoice could not be prepared or sent. Contact the manager before retrying an email.', 'pc-folio-customer-balance');
    } finally {
        if ($book) $book->disconnectWorksheets();
        if ($path && is_file($path)) unlink($path);
    }
    if ($error) wp_send_json_error(['message' => $error], 502);
    if ($mode === 'email') wp_send_json_success(['result' => ['accepted' => true]]);
    nocache_headers();
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('X-Content-Type-Options: nosniff');
    echo $bytes;
    exit;
}
add_action('wp_ajax_pc_folio_customer_invoice', 'pc_folio_invoice_ajax');
