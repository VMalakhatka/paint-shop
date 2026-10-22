<?php
/** Offline regression: load the actual MU-plugin, no WordPress/HTTP/database writes. */
if (PHP_SAPI !== 'cli') exit;
define('ABSPATH', __DIR__ . '/');
define('DAY_IN_SECONDS', 86400);
function add_action(...$args) {}
function add_filter(...$args) {}
function wp_strip_all_tags($s, $breaks = false) { return strip_tags($s); }
function sanitize_text_field($s) { return trim(strip_tags($s)); }
function wp_date($format, $timestamp = null) { return gmdate($format, $timestamp ?? time()); }
function get_bloginfo($name) { return 'Fixture shop'; }
function wp_generate_uuid4() { return '00000000-0000-4000-8000-000000000001'; }
class FixtureDate extends DateTimeImmutable {
    public function date_i18n($format) { return $this->format($format); }
}
class WC_Order {
    public string $method = 'wayforpay', $currency = 'UAH', $transaction = 'fixture-reference';
    public string $number = '123456';
    public bool $paid = true;
    public mixed $verified = ['reference' => 'fixture-reference', 'amount_cents' => 8400, 'currency' => 'UAH'];
    public function get_meta(...$args) { return $this->verified; }
    public function get_payment_method() { return $this->method; }
    public function get_currency() { return $this->currency; }
    public function get_transaction_id() { return $this->transaction; }
    public function get_date_paid() { return $this->paid ? new FixtureDate('2026-10-16T12:00:00Z') : null; }
    public function get_date_created() { return new FixtureDate('2026-10-16T11:55:00Z'); }
    public function get_order_number() { return $this->number; }
    public function get_user_id() { return 0; }
    public function get_customer_note() { return 'Keep existing note'; }
    public function __call($name, $args) { return ''; }
}
require dirname(__DIR__, 2) . '/wp-content/mu-plugins/pc-folio-order-link.php';
$checks = 0;
function check($ok, $label) {
    global $checks;
    if (!$ok) throw new RuntimeException($label);
    ++$checks;
}
function headerFor(WC_Order $order): array {
    return pc_folio_build_account_header_preview($order, ['name' => 'Fixture', 'short_name' => 'FIXTURE']);
}
$order = new WC_Order();
$header = headerFor($order);
check($header['additionalInfo'] === 'WFP 123456 2026-10-16 11:55', 'verified payment label');
check($header['sourceInfo'] === 'Keep existing note', 'customer note preserved');
check($header['operationType'] === 'СЧЕТ', 'account document unchanged');
check(strlen($header['additionalInfo']) <= 30, 'Folio length');
foreach (['bacs', 'cod', 'cash'] as $method) {
    $o = new WC_Order(); $o->method = $method;
    check(str_starts_with(headerFor($o)['additionalInfo'], 'Int '), 'other gateway ' . $method);
}
foreach (['', [], ['reference' => 'fixture-reference'],
    ['reference' => 'other', 'amount_cents' => 8400, 'currency' => 'UAH'],
    ['reference' => 'fixture-reference', 'amount_cents' => 0, 'currency' => 'UAH'],
    ['reference' => 'fixture-reference', 'amount_cents' => '8400', 'currency' => 'UAH'],
    ['reference' => 'fixture-reference', 'amount_cents' => 8400, 'currency' => 'EUR']] as $evidence) {
    $o = new WC_Order(); $o->verified = $evidence;
    check(str_starts_with(headerFor($o)['additionalInfo'], 'Int '), 'missing/invalid evidence');
}
$o = new WC_Order(); $o->paid = false;
check(str_starts_with(headerFor($o)['additionalInfo'], 'Int '), 'unpaid order');
$o = new WC_Order(); $o->transaction = '';
check(str_starts_with(headerFor($o)['additionalInfo'], 'Int '), 'missing transaction');
$o = new WC_Order(); $o->currency = 'EUR';
check(str_starts_with(headerFor($o)['additionalInfo'], 'Int '), 'order currency mismatch');
$o = new WC_Order(); $o->number = str_repeat('1', 40);
check(strlen(headerFor($o)['additionalInfo']) === 30, 'long order number remains bounded');
check(str_starts_with(headerFor($o)['additionalInfo'], 'WFP '), 'prefix survives truncation');
echo "PASS: $checks Folio WayForPay information assertions\n";
