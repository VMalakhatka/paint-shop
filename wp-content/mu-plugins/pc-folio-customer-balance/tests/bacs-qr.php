<?php
/** php tests/bacs-qr.php; isolated fixtures, no WP DB, mail or financial writes. */
define('ABSPATH', __DIR__);
define('WPMU_PLUGIN_URL', 'https://example.invalid/mu-plugins');
$hooks = [];
function add_action($name, $fn, ...$rest) { $GLOBALS['hooks'][$name][] = $fn; }
function add_filter(...$args) {}
function add_shortcode(...$args) {}
function __($text, $domain = '') { return $GLOBALS['translations'][$text] ?? $text; }
function esc_html__($text, $domain = '') { return esc_html(__($text, $domain)); }
function esc_attr__($text, $domain = '') { return esc_attr(__($text, $domain)); }
function esc_html($text) { return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8'); }
function esc_attr($text) { return esc_html($text); }
function esc_url($text) { return esc_html($text); }
function esc_url_raw($text) { return $text; }
function wp_kses_post($text) { return $text; }
function wp_json_encode($data) { return json_encode($data); }
function wp_salt($scheme) { return 'synthetic-test-only'; }
function absint($v) { return abs((int) $v); }
function get_theme_mod($name) { return 0; }
function get_option($name, $default = false) { return $GLOBALS['options'][$name] ?? $default; }
function wp_enqueue_style(...$args) {}
function wp_enqueue_script(...$args) {}
function get_current_user_id() { return $GLOBALS['viewer'] ?? 0; }
function current_user_can($capability) { return false; }
function wc_get_order($id) { return $GLOBALS['order']; }
class WP_Error { public function __construct(...$args) {} }
function is_wp_error($value) { return $value instanceof WP_Error; }
class WC_Order {
    public $method = 'bacs', $currency = 'UAH', $parent = 0, $status = 'on-hold', $paid = false, $date_paid = null, $refunded = '0', $amount = '84.00', $number = 'QR-123', $customer = 9;
    public function get_payment_method() { return $this->method; }
    public function get_currency() { return $this->currency; }
    public function get_parent_id() { return $this->parent; }
    public function has_status($status) { return in_array($this->status, (array) $status, true); }
    public function is_paid() { return $this->paid; }
    public function get_date_paid() { return $this->date_paid; }
    public function get_total_refunded() { return $this->refunded; }
    public function get_total() { return $this->amount; }
    public function get_order_number() { return $this->number; }
    public function get_customer_id() { return $this->customer; }
    public function get_formatted_order_total() { return $this->amount . ' UAH'; }
    public function get_checkout_order_received_url() { return 'https://example.invalid/order/QR-123?key=fixture-only'; }
    public function save() { throw new RuntimeException('Rendering must not save orders'); }
    public function payment_complete() { throw new RuntimeException('Rendering must not mark paid'); }
}
require_once __DIR__ . '/../inc/customer-invoice.php';
$recipient = ['name' => 'ФОП Тестовий Одержувач', 'tax_id' => '0000000000', 'iban' => '', 'bank' => 'Fixture bank', 'mfo' => '', 'confirmed' => true];
// Nonproduction all-zero bank/account. Generate its checksum; never use real requisites.
$body = str_repeat('0', 25); $rem = 0;
foreach (str_split($body . '301000') as $digit) $rem = ($rem * 10 + (int) $digit) % 97;
$recipient['iban'] = 'UA' . str_pad((string) (98 - $rem), 2, '0', STR_PAD_LEFT) . $body;
$account = ['account_name' => $recipient['name'], 'tax_id' => $recipient['tax_id'], 'iban' => $recipient['iban'], 'bank_name' => $recipient['bank']];
$account['pc_folio_confirmed_hash'] = pc_folio_bacs_confirmation_hash($account);
$options = ['woocommerce_bacs_accounts' => [$account]];
$order = new WC_Order();
$capture = static function ($fn) { ob_start(); try { $fn(); return ob_get_contents(); } finally { ob_end_clean(); } };
if (in_array($argv[1] ?? '', ['--render', '--render-max'], true)) {
    if (!empty($argv[2])) $GLOBALS['translations'] = json_decode(file_get_contents($argv[2]), true);
    if ($argv[1] === '--render-max') {
        while (pc_folio_bacs_qr_order($order)) $order->number .= 'A';
        $order->number = substr($order->number, 0, -1);
    }
    $assets = realpath(__DIR__ . '/../assets');
    echo '<!doctype html><html lang="uk"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>QR preview — synthetic data</title>';
    echo '<link rel="stylesheet" href="file://' . $assets . '/bacs-qr.css"><style>body{font:16px/1.5 Arial;margin:16px auto;max-width:1000px;padding:0 12px}a{color:#176ca0}.button{display:inline-block;padding:12px 18px;background:#176ca0;color:white;text-decoration:none;border-radius:4px}h2{font-size:24px}</style><body>';
    pc_folio_bacs_render_details(); pc_folio_bacs_render_qr($order);
    echo '<script src="file://' . $assets . '/third-party/qrcodegen.js"></script><script src="file://' . $assets . '/bacs-qr.js"></script></body></html>';
    exit;
}
$checks = 0;
$assert = static function ($ok, $message) use (&$checks) { if (!$ok) throw new RuntimeException($message); $checks++; };
$decode = static fn($url) => base64_decode(strtr(substr($url, strlen('https://qr.bank.gov.ua/')), '-_', '+/'), true);
$qr = pc_folio_bacs_qr_order($order);
$assert($decode($qr['url']) === implode("\n", ['BCD','002','1','UCT','',$recipient['name'],$recipient['iban'],'UAH84','0000000000','','','Payment for order #QR-123','']), 'Exact NBU field order, empty reserved fields and full order total');
foreach (['0.01' => 'UAH0.01', '4.00' => 'UAH4', '4.10' => 'UAH4.10', '999999999.99' => 'UAH999999999.99'] as $amount => $field) {
    $link = pc_folio_bacs_qr_link($recipient, (string) $amount, 'Order 1');
    $assert(explode("\n", $decode($link))[7] === $field, 'Exact amount without floating point: ' . $amount);
}
foreach (['0', '0.00', '-4.00', '04.00', '4.001', '4,00', '4.0', '1e3', '1000000000', "4.00\n"] as $amount) {
    $assert(pc_folio_bacs_qr_link($recipient, $amount, 'Order 1') === '', 'Reject invalid amount');
}
foreach (["Order\nUCT", "Order\r1", "Order\0", "Order\x7f", "Order\u{00a0}", 'Order 😀', str_repeat('X', 421)] as $purpose) {
    $assert(pc_folio_bacs_qr_link($recipient, '4', $purpose) === '', 'Reject malformed fields without changing payment data');
}
foreach ([['confirmed' => false], ['iban' => 'UA00' . $body], ['tax_id' => '1'], ['name' => str_repeat('Я', 141)], ['name' => "Name\nInjected"]] as $bad) {
    $assert(pc_folio_bacs_qr_link(array_replace($recipient, $bad), '4', 'Order 1') === '', 'Reject invalid recipient');
}
$assert(pc_folio_bacs_qr_link($recipient, '4', str_repeat('Я', 300)) === '', 'Enforce encoded data limit');
foreach (['method' => 'wayforpay', 'currency' => 'EUR', 'parent' => 1, 'status' => 'processing', 'paid' => true, 'date_paid' => '2026-11-14', 'refunded' => '1.00', 'amount' => '0.00'] as $field => $value) {
    $copy = clone $order; $copy->$field = $value;
    $assert(pc_folio_bacs_qr_order($copy) === [], 'Do not request an unsafe/full repeat payment: ' . $field);
}
foreach (['completed', 'cancelled', 'refunded', 'failed'] as $status) {
    $copy = clone $order; $copy->status = $status;
    $assert(pc_folio_bacs_qr_order($copy) === [], 'No QR for terminal order');
}
$html = $capture(static fn() => pc_folio_bacs_render_qr($order));
$assert(str_contains($html, 'data-payment-qr=') && str_contains($html, '84.00 UAH') && str_contains($html, 'does not confirm payment'), 'Customer receives amount, QR, link and explicit pending notice');
$assert(pc_folio_bacs_qr_title('old') === 'Payment to sole proprietor account — QR', 'FOP checkout title');
$options['woocommerce_bacs_accounts'][0]['iban'] = 'UA00' . $body;
$assert(pc_folio_bacs_qr_order($order) === [] && pc_folio_bacs_qr_title('old') === 'old', 'Fresh recipient validation invalidates QR');
$options['woocommerce_bacs_accounts'] = [$account];
foreach ([0, 8] as $viewer) {
    $assert($capture(static fn() => $hooks['woocommerce_view_order'][0](1)) === '', 'Guest or different customer receives no QR');
}
$viewer = 9;
$assert(str_contains($capture(static fn() => $hooks['woocommerce_view_order'][0](1)), 'data-payment-qr='), 'Owner can see QR in account');
foreach ([false, true] as $plain) {
    $email = $capture(static fn() => pc_folio_bacs_qr_email_link($order, $plain));
    $assert(str_contains($email, 'fixture-only') && !str_contains($email, 'qr.bank.gov.ua'), 'Email links to protected current order, never frozen bank QR');
}
echo "PASS: $checks bank QR contract, access and repeat-payment checks\n";
