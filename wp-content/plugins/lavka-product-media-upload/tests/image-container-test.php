<?php

declare(strict_types=1);

define('ABSPATH', __DIR__ . '/');
define('MB_IN_BYTES', 1048576);

function __(string $text, string $domain = ''): string { return $text; }
function apply_filters(string $tag, mixed $value): mixed { return $value; }
function get_option(string $name, mixed $default = false): mixed { return $default; }
function wp_parse_args(mixed $args, array $defaults = []): array
{
    return array_merge($defaults, is_array($args) ? $args : []);
}

require_once dirname(__DIR__) . '/inc/class-product-resolver.php';
require_once dirname(__DIR__) . '/inc/class-image-validator.php';

$validator = new Lavka\ProductMediaUpload\ImageValidator();
$container = new ReflectionMethod($validator, 'validate_container');
$decode = new ReflectionMethod($validator, 'full_decode');
$raw_scan = new ReflectionMethod($validator, 'contains_suspicious_payload');
$checks = 0;

function check(bool $condition, string $label): void
{
    global $checks;
    if (!$condition) {
        throw new RuntimeException($label);
    }
    $checks++;
}

function chunk(string $type, string $data): string
{
    return pack('N', strlen($data)) . $type . $data . pack('H*', hash('crc32b', $type . $data));
}

function png(string $pixels, string $extra = '', ?string $idat = null): string
{
    $pixels = str_pad($pixels, (int) ceil(strlen($pixels) / 3) * 3, ' ');
    return "\x89PNG\r\n\x1A\n"
        . chunk('IHDR', pack('NNCCCCC', intdiv(strlen($pixels), 3), 1, 8, 2, 0, 0, 0))
        . $extra
        // Stored DEFLATE blocks retain the literal token in valid pixel data.
        . chunk('IDAT', $idat ?? gzcompress("\0" . $pixels, 0))
        . chunk('IEND', '');
}

function inspect_bytes(string $bytes, string $format = 'png'): array
{
    global $validator, $container, $decode;
    $path = tempnam(sys_get_temp_dir(), 'lpmu-container-');
    try {
        file_put_contents($path, $bytes);
        $result = $container->invoke($validator, $path, $format);
        if ($result['ok']) {
            $size = getimagesize($path);
            return $decode->invoke($validator, $path, (int) $size[0], (int) $size[1], $format);
        }
        return $result;
    } finally {
        unlink($path);
    }
}

foreach (['<?=', '<?php', '<script', 'eval(', 'assert('] as $token) {
    $bytes = png($token);
    check($raw_scan->invoke($validator, $bytes), 'Fixture must reproduce the old raw-byte false positive');
    check(inspect_bytes($bytes)['ok'], 'Valid PNG pixels containing ' . $token . ' must decode');
    $result = inspect_bytes(png('rgb', chunk('tEXt', "Comment\0" . $token)));
    check(!$result['ok'] && ($result['technical'] ?? '') === 'polyglot_signature', 'Script-like metadata must remain blocked');
}

check(inspect_bytes(png('rgb', chunk('tEXt', "Comment\0Product photo")))['ok'], 'Ordinary metadata must pass');
check(!inspect_bytes(png('rgb', chunk('raNd', '<?php')))['ok'], 'Unknown ancillary chunk payloads must be checked');
check(!inspect_bytes(png('rgb', chunk('acTL', pack('NN', 1, 0))))['ok'], 'Animated PNG must remain blocked');

$valid = png('rgb');
$broken_crc = $valid;
$broken_crc[29] = chr(ord($broken_crc[29]) ^ 1);
check(!inspect_bytes($broken_crc)['ok'], 'Bad CRC must fail');
check(!inspect_bytes(substr($valid, 0, -1))['ok'], 'Truncated PNG must fail');
check(!inspect_bytes($valid . '<?php')['ok'], 'Script after IEND must fail');
check(!inspect_bytes($valid . "\0")['ok'], 'Even non-script trailing data must fail');
check(!inspect_bytes('badmagic' . substr($valid, 8))['ok'], 'Incorrect PNG magic must fail');
check(!inspect_bytes(png('rgb', '', '<?php'))['ok'], 'Undecodable IDAT with valid CRC must fail full decoding');
check(!inspect_bytes("\xFF\xD8<?php\xFF\xD9", 'jpg')['ok'], 'JPEG signature detection must remain active');
check(!inspect_bytes('RIFFxxxxWEBP<script', 'webp')['ok'], 'WebP signature detection must remain active');

// Optional real-world regression file: read-only, never copied into the repository.
if (isset($argv[1])) {
    $path = $argv[1];
    $before = hash_file('sha256', $path);
    $result = $container->invoke($validator, $path, 'png');
    check($result['ok'], 'Supplied PNG container must pass: ' . json_encode($result));
    $size = getimagesize($path);
    $result = $decode->invoke($validator, $path, (int) $size[0], (int) $size[1], 'png');
    check($result['ok'], 'Supplied PNG must fully decode: ' . json_encode($result));
    check(hash_file('sha256', $path) === $before, 'Supplied image must remain unchanged');
    echo 'PASS: supplied PNG container and full decode (' . $size[0] . 'x' . $size[1] . ")\n";
}

echo "PASS: $checks image container/decode regression checks\n";
