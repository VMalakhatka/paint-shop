<?php
/** wp eval-file .../tests/approval-excel.php --skip-themes; disposable local fixtures only. */
use PaintCore\PCOE\CustomerApproval as Approval;
use PaintCore\PCOE\ApprovalNotifications as Notices;
use PaintCore\PCOE\ApprovalWorkbook as Workbook;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Writer\Html;

if (!defined('WP_CLI') || !WP_CLI || wp_parse_url(home_url(), PHP_URL_HOST) !== 'paint.local') throw new RuntimeException('Local only');
$users = []; $order = null; $id = 0; $product = null; $mails = []; $paths = []; $checks = 0; $result = true; $original = get_current_user_id();
$sku = '000123-'.substr(wp_generate_uuid4(), 0, 8);
$check = static function ($ok, $message) use (&$checks): void { if (!$ok) throw new RuntimeException($message); $checks++; };
$deny = static function ($fn) use ($check): void { try { $fn(); } catch (Throwable $e) { $check(true, 'Rejected'); return; } throw new RuntimeException('Expected rejection'); };
add_filter('pre_http_request', static fn() => new WP_Error('blocked', 'No external HTTP'), PHP_INT_MAX);
add_filter('pre_wp_mail', static function ($pre, $args) use (&$mails, &$paths, &$result, $check, $sku) {
    $mails[] = $args;
    foreach ($args['attachments'] as $name => $path) {
        $paths[] = $path;
        $check(str_ends_with($name, '.xlsx') && is_file($path) && !str_starts_with(realpath($path), realpath(ABSPATH).'/'), 'Real named XLSX outside public directory');
        $book = IOFactory::load($path);
        $cells = $book->getActiveSheet()->toArray();
        $rows = array_values(array_filter($cells, static fn($row) => ($row[0] ?? '') === $sku));
        $check(count($rows) === 1 && $rows[0][1] === '=1+1' && (float) $rows[0][2] === 3.0 && (float) $rows[0][4] === 10.0, 'Exact saved row, leading zero SKU, numeric values and harmless formula-like title');
        foreach ($book->getAllSheets() as $sheet) foreach ($sheet->getCellCollection()->getCoordinates() as $cell) $check($sheet->getCell($cell)->getDataType() !== 'f', 'No formula injection');
        $book->disconnectWorksheets();
    }
    if ($result === 'throw') throw new RuntimeException('Simulated transport uncertainty');
    return $result;
}, PHP_INT_MAX, 2);
try {
    foreach (['opt', 'shop_manager'] as $role) {
        $tag = wp_generate_uuid4();
        $user = wp_insert_user(['user_login'=>'approval-xlsx-'.$tag,'user_email'=>'xlsx-'.$tag.'@example.invalid','user_pass'=>wp_generate_password(30),'display_name'=>'Тестовий клієнт','role'=>$role]);
        if (is_wp_error($user)) throw new RuntimeException('Fixture');
        $users[] = $user;
    }
    [$owner, $manager] = $users;
    $product = new WC_Product_Simple(); $product->set_name('Synthetic approval product'); $product->set_sku($sku); $product->set_status('draft'); $product->save();
    $order = wc_create_order(['customer_id'=>$owner,'status'=>'pc-draft']);
    $item = new WC_Order_Item_Product(); $item->set_product($product); $item->set_name('=1+1'); $item->set_quantity(3); $item->set_total(10); $item->set_subtotal(10); $order->add_item($item);
    $shipping = new WC_Order_Item_Shipping(); $shipping->set_method_title('Fixture shipping'); $shipping->set_total(7); $order->add_item($shipping); $order->calculate_totals(); $order->save();
    $source = ['customer_id'=>$owner,'order_id'=>$order->get_id(),'type'=>'','document_id'=>0];
    $snapshot = Approval::snapshot($source);
    $data = ['source'=>$source,'snapshot'=>$snapshot,'revision'=>Approval::revision($snapshot),'status'=>'pending','history'=>[],'requested_by'=>$manager,'requested_at'=>gmdate('c')];
    $id = (new ReflectionMethod(Approval::class, 'save'))->invoke(null, $data, 0);
    wp_set_current_user($manager);
    $key = wp_generate_uuid4();
    $check(Notices::send_customer($id, $data['revision'], $key, 'xlsx') === 'accepted', 'XLSX accepted by mocked mail service');
    $check(count($mails) === 1 && $mails[0]['to'] === get_userdata($owner)->user_email, 'Only owning profile receives attachment');
    $check(in_array('Reply-To: '.get_userdata($manager)->user_email, $mails[0]['headers'], true), 'Email reply goes to requesting manager');
    $check(str_contains($mails[0]['message'], 'pcoe_approval='.$id), 'Same account confirmation URL in email');
    $check(!is_file($paths[0]), 'Private workbook removed after acceptance');
    Notices::send_customer($id, $data['revision'], $key, 'xlsx');
    Notices::send_customer($id, $data['revision'], $key, 'link');
    $check(count($mails) === 1, 'Replayed POST cannot duplicate or change mail format');
    $deny(fn() => Notices::send_customer($id, 'stale', wp_generate_uuid4(), 'xlsx'));
    $deny(fn() => Notices::send_customer($id, $data['revision'], wp_generate_uuid4(), 'pdf'));
    wp_set_current_user($owner); $deny(fn() => Notices::send_customer($id, $data['revision'], wp_generate_uuid4(), 'xlsx')); wp_set_current_user($manager);
    ob_start(); Notices::panel($id, $data, true); $panel = ob_get_clean();
    $check(str_contains($panel, 'value="xlsx"') && str_contains($panel, 'value="link"') && str_contains($panel, '_wpnonce'), 'Manager panel has both explicit nonce-protected actions');
    if ($dir = getenv('PCOE_APPROVAL_EXCEL_PREVIEW')) {
        file_put_contents($dir.'/panel.html', '<meta charset="utf-8"><div class="wrap">'.$panel.'</div>');
    }
    ob_start(); Notices::panel($id, $data, false); $stale = ob_get_clean();
    $check(!str_contains($stale, 'value="xlsx"'), 'Stale snapshot cannot be sent from the panel');
    Notices::send_customer($id, $data['revision'], wp_generate_uuid4());
    $check(end($mails)['attachments'] === [], 'Existing link-only email remains attachment-free');
    $result = false; $failed = wp_generate_uuid4();
    $check(Notices::send_customer($id, $data['revision'], $failed, 'xlsx') === 'failed' && !is_file(end($paths)), 'Known failure cleans up attachment');
    $count = count($mails); Notices::send_customer($id, $data['revision'], $failed, 'xlsx');
    $check(count($mails) === $count, 'Failure replay is not an automatic retry');
    $result = 'throw';
    $check(Notices::send_customer($id, $data['revision'], wp_generate_uuid4(), 'xlsx') === 'unknown' && !is_file(end($paths)), 'Unknown transport result cleans up attachment');
    $deny(fn() => Notices::send_customer($id, $data['revision'], wp_generate_uuid4(), 'xlsx'));
    $check(Notices::data($id)['status'] === 'pending' && wc_get_order($order->get_id())->has_status('pc-draft'), 'Email does not confirm or advance the order');

    // Exercise a multi-document workbook and Folio-only workbook with saved synthetic snapshots.
    $doc = ['title'=>'Folio account 555001','id'=>555001,'warehouse'=>'Київ','currency'=>'UAH','total'=>'10.00','items'=>$snapshot['items']];
    $data['snapshot']['documents'] = [$doc, array_replace($doc, ['title'=>'Folio account 555002','warehouse'=>'Одеса'])];
    $book = Workbook::workbook($id, $data);
    $check($book->getSheetCount() === 3, 'Main order and each linked Folio document have separate sheets');
    $main = $book->getSheet(0)->toArray();
    $check(count(array_filter($main, static fn($row) => str_contains((string)$row[0], 'UAH') && (float)$row[4] === 17.0)) === 1, 'Saved grand total includes shipping, without adding linked documents twice');
    if ($dir) { $writer = new Html($book); $writer->writeAllSheets(); $writer->save($dir.'/workbook.html'); }
    $book->disconnectWorksheets();
    $folio = $data; $folio['source']['order_id'] = 0; $folio['snapshot'] = $doc;
    $book = Workbook::workbook($id, $folio);
    $check($book->getSheetCount() === 1 && str_contains(json_encode($book->getActiveSheet()->toArray(), JSON_UNESCAPED_UNICODE), 'Київ'), 'Folio-only document keeps warehouse and items');
    $book->disconnectWorksheets();
    $before = glob(sys_get_temp_dir().'/pcoe-approval-*');
    $folio['snapshot']['items'][0]['price'] = 'broken';
    $deny(fn() => Workbook::attachment($id, $folio));
    $check(glob(sys_get_temp_dir().'/pcoe-approval-*') === $before, 'Failed generation leaves no temporary customer file');
    echo "PASS: $checks approval XLSX checks; real workbook readback, email and HTTP intercepted.\n";
} finally {
    if ($id) wp_delete_post($id, true);
    if ($order) $order->delete(true);
    if ($product) $product->delete(true);
    wp_set_current_user($original); require_once ABSPATH.'wp-admin/includes/user.php'; foreach ($users as $user) wp_delete_user($user);
}
