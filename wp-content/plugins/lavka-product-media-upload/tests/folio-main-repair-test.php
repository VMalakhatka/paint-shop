<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { exit(1); }
define('ABSPATH', __DIR__ . '/');
define('HOUR_IN_SECONDS', 3600);
define('MINUTE_IN_SECONDS', 60);
define('ARRAY_A', 'ARRAY_A');
require dirname(__DIR__) . '/inc/class-workflow-service.php';

use Lavka\ProductMediaUpload\WorkflowService;

final class RepairProductFixture
{
    public int $attachment = 12;

    public function get_id(): int { return 42; }
    public function get_name(): string { return 'Silver marker'; }
    public function get_sku(string $context): string { return 'KR-16416'; }
    public function get_image_id(string $context): int { return $this->attachment; }
}

$product = new RepairProductFixture();
$wpdb = new class {
    public string $prefix = 'wp_';
    public function prepare(string $sql, string $value): string { return $value; }
    public function esc_like(string $value): string { return $value; }
    public function get_var(string $query): string { return $query; }
    public function get_results(string $query, string $format): array {
        return [['full_key' => 'wp-content/uploads/2025/06/KR-16416.jpg', 'size_bytes' => 45352, 'etag' => '"old-etag"']];
    }
};

$folio = 'kr-16416.jpg';
$requests = [];
$previews = [];
$currentUser = 51;
$permissions = ['edit_post' => true, 'read_post' => true];
$locked = false;
$storageAvailable = true;

function __(string $text, string $domain): string { return $text; }
function wp_basename(string $path): string { return basename($path); }
function wp_json_encode($value, int $flags = 0): string { return json_encode($value, $flags | JSON_THROW_ON_ERROR); }
function wp_get_attachment_url(int $id): string { return 'https://media.example.org/wp-content/uploads/2025/06/KR-16416.jpg'; }
function wp_attachment_is_image(int $id): bool { return true; }
function get_post_status(int $id): string { return 'inherit'; }
function esc_url_raw(string $url, array $protocols): string { return $url; }
function get_post_meta(int $id, string $key, bool $single): string { return '2025/06/KR-16416.jpg'; }
function wc_get_product_id_by_sku(string $sku): int { return strcasecmp($sku, 'KR-16416') === 0 ? 42 : 0; }
function wc_get_product(int $id) { return $id === 42 ? $GLOBALS['product'] : false; }
function current_user_can(string $permission, int $id): bool { return $GLOBALS['permissions'][$permission]; }
function get_current_user_id(): int { return $GLOBALS['currentUser']; }
function wp_generate_uuid4(): string { return '2cffe113-ba48-40c0-a704-58be946693c2'; }
function wp_is_uuid(string $value, int $version): bool { return $value === wp_generate_uuid4(); }
function wp_safe_remote_head(string $url, array $args): array {
    return ['code' => $GLOBALS['storageAvailable'] ? 200 : 404, 'content-length' => '45352', 'etag' => '"old-etag"'];
}
function wp_remote_retrieve_response_code(array $response): int { return $response['code']; }
function wp_remote_retrieve_header(array $response, string $name): string { return $response[$name]; }
function is_wp_error($value): bool { return false; }
function sanitize_key(string $value): string { return $value; }
function set_transient(string $key, array $value, int $expiry): bool { $GLOBALS['previews'][$key] = $value; return true; }
function get_transient(string $key) { return $GLOBALS['previews'][$key] ?? false; }
function delete_transient(string $key): void { unset($GLOBALS['previews'][$key]); }
function lavka_ecosystem_lock_acquire(...$args): array {
    if ($GLOBALS['locked']) { return ['ok' => false]; }
    $GLOBALS['locked'] = true;
    return ['ok' => true, 'token' => 'active-repair'];
}
function lavka_ecosystem_lock_release(string $token): void { $GLOBALS['locked'] = false; }
function lavka_ecosystem_lock_touch(...$args): void {}

function lts_call_java_media_request(string $method, string $path, array $data): array
{
    global $folio, $requests, $locked;
    $requests[] = [$method, $data];
    if ($method === 'GET') {
        check($path === '/admin/folio/product-media' && $data['sku'] === 'KR-16416' && $data['match'] === 'exact' && $data['role'] === 'main', 'Exact Folio lookup');
        return ['ok' => true, 'json' => ['ok' => true, 'total' => 1, 'items' => [['role' => 'main', 'sku' => 'KR-16416', 'filename' => $folio]]]];
    }
    check($path === '/admin/folio/product-media/changes' && count($data['changes']) === 1, 'Single Folio write target');
    $change = $data['changes'][0];
    check($change['operation'] === 'set_main' && $change['sku'] === 'KR-16416' && $change['filename'] === 'KR-16416.jpg'
        && $change['s3Proof']['fullKey'] === 'wp-content/uploads/2025/06/KR-16416.jpg', 'Exact target from the current attachment');
    if ($data['previewOnly']) {
        return ['ok' => true, 'json' => ['ok' => true, 'results' => [['status' => 'ready']]]];
    }
    check($locked, 'Apply holds the shared synchronization lock');
    if ($change['expectedOldFilename'] !== $folio) {
        return ['ok' => true, 'json' => ['ok' => false, 'results' => [['status' => 'blocked']]]];
    }
    $folio = $change['filename'];
    return ['ok' => true, 'json' => ['ok' => true, 'results' => [['status' => 'applied']]]];
}

function check(bool $condition, string $message): void {
    if (!$condition) { throw new RuntimeException($message); }
}

function rejects(callable $action, string $label): void {
    try { $action(); } catch (RuntimeException $error) { return; }
    throw new RuntimeException($label);
}

$service = (new ReflectionClass(WorkflowService::class))->newInstanceWithoutConstructor();
$sku = 'KR-16416';
rejects(fn() => $service->preview_main_repair(strtolower($sku)), 'Inexact Woo SKU rejected');
$permissions['edit_post'] = false;
rejects(fn() => $service->preview_main_repair($sku), 'Other users cannot prepare repairs for this product');
$permissions['edit_post'] = true;
$permissions['read_post'] = false;
rejects(fn() => $service->preview_main_repair($sku), 'Users must be able to read the current attachment');
$permissions['read_post'] = true;
$storageAvailable = false;
rejects(fn() => $service->preview_main_repair($sku), 'Unreachable S3 object rejected');
check(!$requests, 'Invalid inputs never reach Java');
$storageAvailable = true;
$preview = $service->preview_main_repair($sku);
$token = $preview['token'];
check($preview['before'] === 'kr-16416.jpg' && $preview['after'] === 'KR-16416.jpg' && $token !== '', 'Preview displays exact names with their case');
check($folio === $preview['before'] && count($requests) === 2, 'Preview does not write Folio');
$currentUser = 52;
rejects(fn() => $service->apply_main_repair($token), 'Preview token belongs to its author');
$currentUser = 51;
$locked = true;
rejects(fn() => $service->apply_main_repair($token), 'Concurrent synchronization blocks apply');
$locked = false;
$product->attachment = 13;
rejects(fn() => $service->apply_main_repair($token), 'Changed attachment invalidates preview');
$product->attachment = 12;
check(!$locked && count($requests) === 2, 'Invalid apply never reaches Java and releases lock');
$folio = 'another.jpg';
rejects(fn() => $service->apply_main_repair($token), 'Folio rejects a changed previous filename');
check($folio === 'another.jpg' && !$locked, 'Failed apply preserves newer Folio value');
$folio = $preview['before'];
$service->apply_main_repair($token);
check($folio === $preview['after'] && !$locked && !$previews, 'Authorized apply writes Folio and consumes preview');
$last = end($requests)[1];
check($last === array_replace($requests[1][1], ['previewOnly' => false]), 'Apply sends exactly the approved change with the same request ID');
rejects(fn() => $service->apply_main_repair($token), 'Consumed token cannot be reapplied');
check($service->preview_main_repair($sku)['token'] === '', 'Already aligned shows no confirmation');
echo "PASS: scoped Folio filename preview, apply, permissions, storage, locking and stale inputs\n";
