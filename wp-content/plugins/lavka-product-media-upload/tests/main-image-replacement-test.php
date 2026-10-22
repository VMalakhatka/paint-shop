<?php
declare(strict_types=1);
define('ABSPATH', __DIR__ . '/');
define('HOUR_IN_SECONDS', 3600);
define('MINUTE_IN_SECONDS', 60);
function __(string $text, string $domain = ''): string { return $text; }
function wp_basename(string $path): string { return basename($path); }
function wc_get_product(int $id) { return $GLOBALS['product']; }
function get_post_meta(int $id, string $key, bool $single) { return $GLOBALS['files'][$id] ?? ''; }
class ProductFixture {
    public int $main = 12;
    public array $gallery = [13, 12, 14, 15];
    public bool $variation = false;
    public function get_image_id(string $context = ''): int { return $this->main; }
    public function get_gallery_image_ids(string $context = ''): array { return $this->gallery; }
    public function is_type(string $type): bool { return $this->variation && $type === 'variation'; }
    public function set_image_id(int $id): void { $this->main = $id; }
    public function set_gallery_image_ids(array $ids): void { $this->gallery = $ids; }
    public function save(): void {}
}
$product = new ProductFixture();
$files = [12 => '2026/old.jpg', 13 => '2026/other.jpg', 14 => '2026/third.jpg', 15 => '2026/old.jpg'];
require dirname(__DIR__) . '/inc/class-main-image-replacement.php';
require dirname(__DIR__) . '/inc/class-workflow-service.php';
require dirname(__DIR__) . '/inc/class-media-uploader.php';
use Lavka\ProductMediaUpload\MainImageReplacement as Replacement;
use Lavka\ProductMediaUpload\WorkflowService;
use Lavka\ProductMediaUpload\MediaUploader;
$checks = 0;
function check(bool $ok, string $message): void {
    global $checks;
    if (!$ok) { throw new RuntimeException($message); }
    $checks++;
}
function blocked(callable $action, string $message): void {
    try { $action(); } catch (RuntimeException $e) { check(true, $message); return; }
    check(false, $message);
}
$proof = ['fullKey' => 'uploads/new.jpg', 'sizeBytes' => 20, 'etag' => 'example'];
$row = ['sku' => 'SKU', 'product_id' => 1, 'attachment_id' => 30, 'role' => 'main', 'position' => 0, 'canonical_file' => 'new.jpg', '_s3_proof' => $proof];
$row['_main_snapshot'] = Replacement::snapshot($row);
check($row['_main_snapshot']['required'], 'Replacing an existing main must ask');
blocked(fn() => Replacement::choose($row, ''), 'Missing choice must be blocked');
blocked(fn() => Replacement::choose($row, 'unexpected'), 'Unknown choice must be blocked');
$keep = Replacement::choose($row, 'gallery');
$remove = Replacement::choose($row, 'remove');
check(Replacement::gallery_after($keep, $product->gallery) === [13, 14, 12], 'Keep exactly one previous image at the end');
check(Replacement::gallery_after($remove, $product->gallery) === [13, 14], 'Remove all previous-image gallery references only');
$product->main = 99;
blocked(fn() => Replacement::assert_current($row), 'A changed main must invalidate approval');
$product->main = 12; $product->gallery[] = 40;
blocked(fn() => Replacement::assert_current($row), 'A changed gallery must invalidate approval');
$product->gallery = [13, 12, 14, 15];
$product->variation = true;
$variant = $row; $variant['_main_snapshot'] = Replacement::snapshot($row);
blocked(fn() => Replacement::choose($variant, 'gallery'), 'Variations cannot retain a separate gallery');
check(Replacement::choose($variant, 'remove')['previous_main_action'] === 'remove', 'Variation may remove its own main');
$product->variation = false;
$same = $row; $same['attachment_id'] = 12;
check(!Replacement::snapshot($same)['required'], 'Reusing the existing main must not ask');
$product->main = 0;
check(!Replacement::snapshot($row)['required'], 'An empty main must not ask');
$product->main = 12;
$uploader = (new ReflectionClass(MediaUploader::class))->newInstanceWithoutConstructor();
$assign = new ReflectionMethod($uploader, 'assign_role');
check($assign->invoke($uploader, 30, $keep)['ok'], 'Woo keep assignment succeeds');
check($product->main === 30 && $product->gallery === [13, 14, 12], 'Woo assigns new main and retains old exactly once');
$product->main = 12; $product->gallery = [13, 12, 14, 15];
check($assign->invoke($uploader, 30, $remove)['ok'], 'Woo removal assignment succeeds');
check($product->main === 30 && $product->gallery === [13, 14], 'Woo removes old display without deleting files');
check(count($files) === 4, 'Attachment records are preserved');

$workflow = (new ReflectionClass(WorkflowService::class))->newInstanceWithoutConstructor();
$build = new ReflectionMethod($workflow, 'build_changes');
$main = ['role' => 'main', 'filename' => 'old.jpg'];
$oldGallery = ['role' => 'gallery', 'filename' => 'old.jpg', 'recordId' => ['key' => '7'], 'sortOrder' => 1];
$otherGallery = ['role' => 'gallery', 'filename' => 'other.jpg', 'recordId' => ['key' => '8'], 'sortOrder' => 2];
$keep['_previous_main_proof'] = $proof;
$plan = $build->invoke($workflow, [$keep], [0], [$main, $oldGallery, $otherGallery]);
check($plan['ok'] && count($plan['changes']) === 2, 'Retaining an existing gallery image does not add another');
check($plan['changes'][1]['operation'] === 'update_gallery' && $plan['changes'][1]['sortOrder'] === 3, 'Retained image goes after existing gallery');
$retryMain = ['role' => 'main', 'filename' => 'new.jpg'];
$retryGallery = array_replace($oldGallery, ['sortOrder' => 3]);
$retry = $build->invoke($workflow, [$keep], [0], [$retryMain, $retryGallery, $otherGallery]);
check(count($retry['changes']) === 1 && $retry['changes'][0]['sortOrder'] === 3, 'Retry keeps stable gallery position');
$plan = $build->invoke($workflow, [$keep], [0], [$main, $otherGallery]);
check($plan['changes'][1]['operation'] === 'add_gallery', 'Old main absent from gallery is added once');
$plan = $build->invoke($workflow, [$remove], [0], [$main, $oldGallery, $otherGallery]);
check($plan['ok'] && $plan['changes'][0]['operation'] === 'remove_gallery', 'Folio removes old duplicate before replacing main');
check($plan['changes'][0]['recordId'] === '7' && $plan['changes'][0]['expectedOldSortOrder'] === 1, 'Removal uses exact optimistic preconditions');
$duplicate = array_replace($oldGallery, ['recordId' => ['key' => '9']]);
$plan = $build->invoke($workflow, [$keep], [0], [$main, $oldGallery, $duplicate, $otherGallery]);
check(array_column($plan['changes'], 'operation') === ['remove_gallery', 'set_main', 'update_gallery'], 'Duplicate old gallery references collapse to one');
$badMain = ['role' => 'main', 'filename' => 'someone-else.jpg'];
check(!$build->invoke($workflow, [$keep], [0], [$badMain])['ok'], 'Unreviewed Folio main cannot be overwritten');
check(!$build->invoke($workflow, [$row], [0], [$main])['ok'], 'Workflow itself rejects an omitted choice');
$noProof = $keep; unset($noProof['_previous_main_proof']);
check(!$build->invoke($workflow, [$noProof], [0], [$main])['ok'], 'Keeping old photo requires object proof');
$newGallery = array_replace($row, ['role' => 'gallery', 'position' => 5, 'canonical_file' => 'detail.jpg']);
$plan = $build->invoke($workflow, [$keep, $newGallery], [0, 1], [$main, $otherGallery]);
check($plan['changes'][2]['sortOrder'] === 6, 'Old main goes after gallery images from the same batch');
$name = Replacement::versioned_filename(str_repeat('a', 50) . '.webp', str_repeat('1', 64));
check(strlen($name) <= 50 && str_ends_with($name, '.webp'), 'Replacement name fits Folio column');
check($name === Replacement::versioned_filename(str_repeat('a', 50) . '.webp', str_repeat('1', 64)), 'Retry uses stable filename');
check($name !== Replacement::versioned_filename(str_repeat('a', 50) . '.webp', str_repeat('2', 64)), 'Different content gets a separate filename');
echo "PASS: $checks main image replacement checks\n";
