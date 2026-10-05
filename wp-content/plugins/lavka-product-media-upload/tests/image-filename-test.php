<?php

declare(strict_types=1);

define('ABSPATH', __DIR__ . '/');
if (!defined('MB_IN_BYTES')) {
    define('MB_IN_BYTES', 1048576);
}

if (!function_exists('__')) {
    function __(string $text, string $domain = ''): string
    {
        return $text;
    }
}

if (!function_exists('apply_filters')) {
    function apply_filters(string $tag, mixed $value): mixed
    {
        return $value;
    }
}

if (!function_exists('get_option')) {
    function get_option(string $name, mixed $default = false): mixed
    {
        return $default;
    }
}

if (!function_exists('wp_parse_args')) {
    function wp_parse_args(mixed $args, array $defaults = []): array
    {
        return array_merge($defaults, is_array($args) ? $args : []);
    }
}

require_once dirname(__DIR__) . '/inc/class-product-resolver.php';
require_once dirname(__DIR__) . '/inc/class-registry-reader.php';
require_once dirname(__DIR__) . '/inc/class-image-validator.php';

$validator = new Lavka\ProductMediaUpload\ImageValidator();
$method = new ReflectionMethod($validator, 'canonical_stem');
$method->setAccessible(true);

$cases = [
    'КЦМ-БК038/60' => 'kcm-bk038_60',
    'КЦМ-БК038/63' => 'kcm-bk038_63',
    'КЦМ-БК038/64' => 'kcm-bk038_64',
    'КЦМ-БК242/01' => 'kcm-bk242_01',
    'КЦМ-БК242/03' => 'kcm-bk242_03',
    'КЦМ-БК242/08' => 'kcm-bk242_08',
    'КЦМ-БК003/44' => 'kcm-bk003_44',
    'КЦМ-БК003/45' => 'kcm-bk003_45',
    'КЦМ-БК003\\45' => 'kcm-bk003_45',
];

foreach ($cases as $identifier => $expected) {
    $result = $method->invoke($validator, $identifier);
    if (($result['ok'] ?? false) !== true || ($result['stem'] ?? '') !== $expected) {
        throw new RuntimeException(sprintf(
            '%s: expected %s, got %s',
            $identifier,
            $expected,
            var_export($result, true)
        ));
    }
}

echo sprintf("PASS: %d compound SKU filename conversions\n", count($cases));
