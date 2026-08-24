<?php

declare(strict_types=1);

define('ABSPATH', dirname(__DIR__, 6) . '/');

if (!class_exists('WP_Error')) {
    final class WP_Error
    {
        public function __construct(public string $code, public string $message)
        {
        }
    }
}

if (!function_exists('__')) {
    function __(string $message): string
    {
        return $message;
    }
}

if (!function_exists('wp_strip_all_tags')) {
    function wp_strip_all_tags(string $value): string
    {
        return strip_tags($value);
    }
}

if (!function_exists('wp_parse_url')) {
    function wp_parse_url(string $value, int $component = -1): string|int|array|false|null
    {
        return parse_url($value, $component);
    }
}

if (!function_exists('apply_filters')) {
    function apply_filters(string $hook, mixed $value): mixed
    {
        return $value;
    }
}

require_once dirname(__DIR__) . '/src/Domain/TtnNormalizer.php';

$normalizer = new Paint\NovaPoshta\Domain\TtnNormalizer();
$cases = [
    ['20400000000000', '20400000000000'],
    ['https://tracking.novaposhta.ua/#/uk/20400000000000', '20400000000000'],
    ['https://novaposhta.ua/tracking/?cargo_number=20400000000000', '20400000000000'],
    ['https://evil.example/20400000000000', 'error'],
    ['not-a-shipment-number', 'error'],
];

foreach ($cases as [$input, $expected]) {
    $actual = $normalizer->normalize($input);
    $result = is_string($actual) ? $actual : 'error';
    if ($result !== $expected) {
        fwrite(STDERR, sprintf("FAIL: %s => %s, expected %s\n", $input, $result, $expected));
        exit(1);
    }
}

fwrite(STDOUT, "TTN normalizer checks passed.\n");
