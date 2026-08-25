<?php

define('ABSPATH', __DIR__ . '/');
define('PCCF_VERSION', 'test');

function wp_json_encode(mixed $value, int $flags = 0): string|false
{
    return json_encode($value, $flags);
}

function is_email(string $email): bool
{
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

function get_option(string $name, mixed $default = false): mixed
{
    return $default;
}

function wp_parse_args(array $args, array $defaults = []): array
{
    return array_merge($defaults, $args);
}

require_once dirname(__DIR__) . '/src/Domain/ValidationException.php';
require_once dirname(__DIR__) . '/src/Domain/CommandValidator.php';
require_once dirname(__DIR__) . '/src/Config.php';
require_once dirname(__DIR__) . '/src/Infrastructure/ApiClient.php';

$command = json_decode((string) file_get_contents(dirname(__DIR__) . '/docs/examples/folio-sale-command.json'), true);
$validator = new Paint\CheckboxFiscalization\Domain\CommandValidator();
$normalized = $validator->normalize($command);
assert($normalized['expected_total_cents'] === 12500);
assert(strlen($validator->hash($normalized)) === 64);

$mismatch = $command;
$mismatch['payments'][0]['value_cents'] = 12499;
try {
    $validator->normalize($mismatch);
    throw new RuntimeException('A payment mismatch was not rejected.');
} catch (Paint\CheckboxFiscalization\Domain\ValidationException $error) {
    assert($error->errorCode === 'payments_total_mismatch');
}

$return = $command;
$return['operation_type'] = 'RETURN';
$return['operation_key'] = 'folio:refund:123456:RETURN:v1';
$return['receipt_id'] = 'c6b86b97-8ed7-4e2e-b0cb-e874e165f1ec';
$return['related_receipt_id'] = $command['receipt_id'];
assert($validator->normalize($return)['goods'][0]['is_return'] === true);

$percent = $command;
$percent['operation_key'] = 'folio:expense:123456:SELL:percent';
$percent['receipt_id'] = 'b5b74880-ef30-4f05-a326-f19cf990e883';
$percent['discounts'] = [['type' => 'DISCOUNT', 'mode' => 'PERCENT', 'value' => 1.5]];
assert($validator->normalize($percent)['discounts'][0]['value'] === 1.5);

$api = new Paint\CheckboxFiscalization\Infrastructure\ApiClient(new Paint\CheckboxFiscalization\Config());
$payload = $api->receiptPayload($normalized);
assert($payload['id'] === $command['receipt_id']);
assert(!isset($payload['goods'][0]['total_sum']));
assert($payload['goods'][0]['is_return'] === false);

echo "CommandValidator smoke test passed.\n";
