<?php
// Offline transition tests. No WordPress, database, HTTP, email or financial writes.
define('ABSPATH', __DIR__);
function __($value, $domain = '') { return $value; }
function wp_json_encode($value) { return json_encode($value); }
function sanitize_textarea_field($value) { return strip_tags($value); }
require dirname(__DIR__) . '/inc/CustomerApproval.php';
use PaintCore\PCOE\CustomerApproval as Approval;
$checks = 0;
function check($condition, $message) { global $checks; if (!$condition) throw new RuntimeException($message); $checks++; }
function rejected(callable $fn) { try { $fn(); } catch (RuntimeException $e) { check(true, 'rejected'); return; } throw new RuntimeException('Expected rejection'); }
$source = ['customer_id' => 11, 'order_id' => 20];
$snapshot = ['items' => [['sku' => 'TEST', 'quantity' => '2', 'price' => '10']], 'total' => '20'];
$hash = Approval::revision($snapshot);
$data = ['source' => $source, 'snapshot' => $snapshot, 'revision' => $hash, 'status' => 'pending'];
$choices = ['payment' => ['bacs' => 'Bank'], 'delivery' => ['pickup:1' => 'Pickup']];
$input = ['revision' => $hash, 'consent' => '1', 'payment' => 'bacs', 'delivery' => 'pickup:1', 'recipient' => 'Test recipient', 'phone' => '000000', 'destination' => 'Test warehouse'];
$result = Approval::confirmed($data, 11, $hash, $input, $choices);
check($result['status'] === 'confirmed' && $result['confirmed_by'] === 11, 'Customer identity recorded');
check($result['preferences']['payment'] === ['id' => 'bacs', 'label' => 'Bank'], 'Server labels persisted');
check($result['snapshot'] === $snapshot, 'Snapshot retained');
check($result['consent_version'] === 1 && !empty($result['confirmed_at']), 'Audit evidence');
check(Approval::confirmed($result, 11, $hash, $input, $choices) === $result, 'Replay idempotent');
rejected(fn() => Approval::confirmed($data, 12, $hash, $input, $choices));
rejected(fn() => Approval::confirmed($data, 0, $hash, $input, $choices));
rejected(fn() => Approval::confirmed($data, 11, $hash, array_replace($input, ['consent' => '0']), $choices));
rejected(fn() => Approval::confirmed($data, 11, $hash, array_replace($input, ['payment' => 'forged']), $choices));
rejected(fn() => Approval::confirmed($data, 11, $hash, array_replace($input, ['delivery' => 'disabled']), $choices));
rejected(fn() => Approval::confirmed($data, 11, $hash, array_replace($input, ['recipient' => '']), $choices));
rejected(fn() => Approval::confirmed($data, 11, $hash, array_replace($input, ['destination' => str_repeat('x', 501)]), $choices));
$new = Approval::revision(array_replace($snapshot, ['total' => '25']));
rejected(fn() => Approval::confirmed($data, 11, $new, $input, $choices));
rejected(fn() => Approval::confirmed($result, 11, $new, $input, $choices));
rejected(fn() => Approval::confirmed($data, 11, $hash, array_replace($input, ['revision' => 'old']), $choices));
check(Approval::key($source) !== Approval::key(array_replace($source, ['customer_id' => 12])), 'Customer scoped target');
check(Approval::key($source) !== Approval::key(['customer_id' => 11, 'type' => 'ACCOUNT', 'document_id' => 20]), 'Different source namespaces');
check(Approval::revision(array_merge($snapshot, ['title' => 'Order'])) === Approval::revision(array_merge($snapshot, ['title' => 'Замовлення'])), 'Locale independent revision');
check(Approval::revision(array_merge($snapshot, ['warehouseId' => '1'])) !== Approval::revision(array_merge($snapshot, ['warehouseId' => '5'])), 'Warehouse changes need new consent');
echo "$checks approval checks passed\n";
