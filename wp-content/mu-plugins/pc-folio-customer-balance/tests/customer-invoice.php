<?php
// Run with wp eval-file. Fixtures only: no Folio calls or outgoing mail.
defined('ABSPATH') || exit;
if (!defined('WP_CLI') || !WP_CLI || wp_parse_url(home_url(), PHP_URL_HOST) !== 'paint.local') {
    throw new RuntimeException('Run only with WP-CLI on paint.local.');
}
function invoice_assert($condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
    echo "PASS: $message\n";
}
$fixture = [
    'partner' => ['shortName' => 'TEST', 'name' => 'Test customer'],
    'document' => [
        'documentType' => 'EXPENSE', 'documentId' => 123,
        'documentNumber' => '=123', 'documentNumberSuffix' => '/test',
        'documentDate' => '2026-09-12T00:00:00', 'totalAmount' => 31.25,
        'items' => [
            ['sku' => 'INVOICE-TEST-MISSING', 'name' => '=SUM(A1:A2)', 'quantity' => 2, 'price' => 10, 'amount' => 20],
            ['sku' => 'INVOICE-TEST-SECOND', 'name' => 'Test long product name for a historical document', 'quantity' => 1.5, 'price' => 7.5, 'amount' => 11.25],
        ],
    ],
];
$model = pc_folio_invoice_model($fixture, 'EXPENSE', 123);
invoice_assert(!is_wp_error($model) && count($model['rows']) === 2 && $model['missing'] === 2, 'Unavailable products retained, unknown barcodes blank');
invoice_assert(is_wp_error(pc_folio_invoice_model($fixture, 'EXPENSE', 124)), 'Mismatched document identity rejected');
invoice_assert(is_wp_error(pc_folio_invoice_model($fixture, 'PAYMENT', 123)), 'Payment rejected');
invoice_assert(is_wp_error(pc_folio_documents_validate_partner($fixture, ['short_name' => 'OTHER'])), 'Different customer rejected');
$bad = $fixture;
$bad['document']['items'][0]['price'] = null;
invoice_assert(is_wp_error(pc_folio_invoice_model($bad, 'EXPENSE', 123)), 'Unknown price is not zero');
$bad = $fixture;
$bad['document']['totalAmount'] = 30;
invoice_assert(is_wp_error(pc_folio_invoice_model($bad, 'EXPENSE', 123)), 'Inconsistent totals rejected');
$bad = $fixture;
$bad['document']['returnDocument'] = true;
invoice_assert(is_wp_error(pc_folio_invoice_model($bad, 'EXPENSE', 123)), 'Return rejected');
$bad = $fixture;
$bad['document']['items'][0]['returnLine'] = true;
invoice_assert(is_wp_error(pc_folio_invoice_model($bad, 'EXPENSE', 123)), 'Return line rejected');
$settings = ['name' => 'Test recipient', 'tax_id' => '0000000000', 'iban' => 'UA00000000000000000000000000000', 'bank' => 'Test bank', 'mfo' => '', 'logo_id' => 0, 'confirmed' => true];
invoice_assert(is_wp_error(pc_folio_invoice_validate_settings($settings)), 'Invalid IBAN blocked');
// Synthetic checksum-valid IBAN; never production configuration.
$settings['iban'] = 'UA89' . str_repeat('0', 25);
invoice_assert(pc_folio_invoice_validate_settings($settings) === true, 'IBAN checksum validated');
$settings['confirmed'] = false;
invoice_assert(is_wp_error(pc_folio_invoice_validate_settings($settings)), 'Unconfirmed payee blocked');
$settings['confirmed'] = true;
$model['rows'][0][1] = '0012345678905';
$book = pc_folio_invoice_workbook($model, $settings);
$sheet = $book->getActiveSheet();
invoice_assert($sheet->getCell('C14')->getDataType() === 's' && $sheet->getCell('C14')->getValue() === '0012345678905', 'Barcode leading zeros retained as text');
invoice_assert($sheet->getCell('D14')->getDataType() === 's', 'Product text cannot inject spreadsheet formula');
invoice_assert($sheet->getCell('D1')->getDataType() === 's', 'Document number cannot inject spreadsheet formula');
invoice_assert($sheet->getCell('G16')->getCalculatedValue() === 31.25, 'Formula total matches authoritative total');
$path = sys_get_temp_dir() . '/folio-invoice-test.xlsx';
(new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($book))->save($path);
(new \PhpOffice\PhpSpreadsheet\Writer\Html($book))->save(sys_get_temp_dir() . '/folio-invoice-test.html');
$book->disconnectWorksheets();
$loaded = \PhpOffice\PhpSpreadsheet\IOFactory::load($path);
invoice_assert($loaded->getActiveSheet()->getCell('C14')->getValue() === '0012345678905', 'XLSX round trip preserves barcodes');
$loaded->disconnectWorksheets();
$user_id = random_int(100000000, 999999999);
$key = 'pc_folio_invoice_mail_' . $user_id;
try {
    invoice_assert(pc_folio_invoice_reserve_mail($user_id, 'first') === true, 'Mail reserved atomically');
    invoice_assert(is_wp_error(pc_folio_invoice_reserve_mail($user_id, 'first')), 'Duplicate pending request does not resend');
    invoice_assert(is_wp_error(pc_folio_invoice_reserve_mail($user_id, 'second')), 'Concurrent different request blocked');
    update_option($key, ['request' => 'first', 'status' => 'accepted', 'until' => time() + 60], false);
    invoice_assert(pc_folio_invoice_reserve_mail($user_id, 'first') === 'accepted', 'Accepted request returns without resending');
    update_option($key, ['request' => 'first', 'status' => 'accepted', 'until' => time() - 1], false);
    invoice_assert(pc_folio_invoice_reserve_mail($user_id, 'second') === true, 'New request allowed after cooldown');
} finally {
    delete_option($key);
}
echo "Fixture XLSX: $path\n";

// Exercise the email endpoint with intercepted HTTP and wp_mail, including ownership failure.
if (!defined('DOING_AJAX')) define('DOING_AJAX', true);
class InvoiceTestResponse extends RuntimeException {}
$die = static function () { return static function () { throw new InvoiceTestResponse(); }; };
add_filter('wp_die_ajax_handler', $die, 999);
$context_filter = static function () use ($user_id) { return ['user_id'=>$user_id, 'short_name'=>'TEST', 'name'=>'Test customer']; };
$settings_filter = static function () use ($settings) { return $settings; };
$http_result = $fixture;
$http_result['ok'] = true;
$http_calls = 0;
$http_filter = static function ($pre, $args, $url) use (&$http_result, &$http_calls) {
    $http_calls++;
    if (!str_contains($url, '/admin/folio/customer-documents/EXPENSE/123') || !str_contains($url, 'partnerShortName=TEST')) {
        throw new RuntimeException('Unexpected HTTP request in test');
    }
    return ['response'=>['code'=>200], 'body'=>wp_json_encode($http_result)];
};
$mail_calls = 0;
$mail_accept = true;
$attached_path = '';
$attachment_valid = false;
$mail_filter = static function ($pre, $atts) use (&$mail_calls, &$attached_path, &$attachment_valid, &$mail_accept) {
    $mail_calls++;
    $filename = array_key_first($atts['attachments']);
    $attached_path = $atts['attachments'][$filename];
    $attachment_valid = str_ends_with($filename, '.xlsx') && is_file($attached_path)
        && str_starts_with(file_get_contents($attached_path), 'PK') && $atts['to'] === 'test@example.invalid';
    return $mail_accept;
};
add_filter('pc_folio_documents_request_context', $context_filter);
add_filter('pre_option_pc_folio_invoice_settings', $settings_filter);
add_filter('pre_http_request', $http_filter, 999, 3);
add_filter('pre_wp_mail', $mail_filter, 999, 2);
$nonce = wp_create_nonce('pc_folio_customer_documents');
$invoke = static function (array $extra = []) use ($nonce) {
    $_POST = array_merge(['document_type'=>'EXPENSE','document_id'=>123,'mode'=>'email','email'=>'test@example.invalid','request_key'=>'00000000-0000-4000-8000-000000000001'], $extra);
    $_REQUEST = $_POST + ['_ajax_nonce'=>$nonce];
    ob_start();
    try { pc_folio_invoice_ajax(); } catch (InvoiceTestResponse $response) {}
    return json_decode(ob_get_clean(), true);
};
try {
    invoice_assert(!has_action('wp_ajax_nopriv_pc_folio_customer_invoice'), 'No guest invoice endpoint');
    $result = $invoke(['_ajax_nonce'=>'invalid']);
    invoice_assert($result === null && $http_calls === 0 && $mail_calls === 0, 'Invalid nonce rejected before document access');
    $result = $invoke(['email'=>"test@example.invalid\r\nBcc: other@example.invalid"]);
    invoice_assert(!$result['success'] && $http_calls === 0 && $mail_calls === 0, 'Email header injection rejected before API call');
    $http_result['partner']['shortName'] = 'OTHER';
    $result = $invoke();
    invoice_assert(!$result['success'] && $mail_calls === 0, 'Other customer document never emailed');
    $http_result['partner']['shortName'] = 'TEST';
    $result = $invoke();
    invoice_assert($result['success'] && $mail_calls === 1 && $attachment_valid, 'Email endpoint attaches an actual XLSX with a readable filename');
    invoice_assert(!is_file($attached_path), 'Private attachment deleted after mail');
    $result = $invoke();
    invoice_assert($result['success'] && $mail_calls === 1, 'Endpoint retry does not send duplicate email');
    update_option($key, ['request'=>'previous', 'status'=>'accepted', 'until'=>time()-1], false);
    $mail_accept = false;
    $result = $invoke(['request_key'=>'00000000-0000-4000-8000-000000000002']);
    invoice_assert(!$result['success'] && $mail_calls === 2 && !is_file($attached_path), 'Mail failure is explicit and temporary file is removed');
    $result = $invoke(['request_key'=>'00000000-0000-4000-8000-000000000002']);
    invoice_assert(!$result['success'] && $mail_calls === 2, 'Failed mail is not silently retried');
} finally {
    delete_option($key);
    remove_filter('wp_die_ajax_handler', $die, 999);
    remove_filter('pc_folio_documents_request_context', $context_filter);
    remove_filter('pre_option_pc_folio_invoice_settings', $settings_filter);
    remove_filter('pre_http_request', $http_filter, 999);
    remove_filter('pre_wp_mail', $mail_filter, 999);
}
