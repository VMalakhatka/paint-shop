<?php
// WP-CLI integration test on a local copy. Keep database fixtures in a rolled-back transaction.
namespace Lavka\ProductMediaUpload;
if (!defined('WP_CLI') || !WP_CLI || !in_array(wp_get_environment_type(), ['local', 'development'], true)) {
    exit(1);
}
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once dirname(__DIR__) . '/lavka-product-media-upload.php';

function row_check($condition, string $label): void {
    if (!$condition) { throw new \RuntimeException($label); }
    echo "PASS $label\n";
}

// WP-CLI supplies fixture bytes instead of a multipart POST. Only that one path qualifies.
function is_uploaded_file($path): bool { return $path === ($GLOBALS['row_choice_upload'] ?? null) || \is_uploaded_file($path); }

global $wpdb;
$user = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID'])[0];
wp_set_current_user($user);
$sku = $wpdb->get_var("SELECT m.meta_value FROM {$wpdb->postmeta} m INNER JOIN {$wpdb->posts} p ON p.ID=m.post_id
    WHERE m.meta_key='_sku' AND m.meta_value<>'' AND p.post_type='product' AND p.post_status='publish' LIMIT 1");
$product = wc_get_product(wc_get_product_id_by_sku($sku));
row_check($product, 'local Woo product available');
$stem = 'choice-' . substr(wp_generate_uuid4(), 0, 8);
$name = $stem . '-scaled.png';
$key = '2026/01/' . $name;
$url = 'https://images.example.org/' . $key;
$original = wp_tempnam('upload.png');
$GLOBALS['row_choice_upload'] = $original;
$img = imagecreatetruecolor(243, 102);
imagepng($img, $original);
imagefill($img, 0, 0, imagecolorallocate($img, 255, 196, 0));
ob_start();
imagepng($img);
$bytes = ob_get_clean();
$wpdb->query('START TRANSACTION');
try {
    $id = wp_insert_attachment(['post_title' => $stem, 'post_mime_type' => 'image/png', 'guid' => $url]);
    update_post_meta($id, '_wp_attached_file', $key);
    update_post_meta($id, '_wp_attachment_metadata', ['width' => 243, 'height' => 102, 'file' => $key, 'original_image' => $stem . '.png']);
    $table = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($wpdb->prefix . 's3_media_index'))) ?: 's3_media_index';
    row_check($wpdb->insert($table, ['filename_lower' => $name, 'full_key' => 'wp-content/uploads/' . $key,
        'size_bytes' => strlen($bytes), 'etag' => md5($bytes)]), 'indexed fixture created');
    add_filter('wp_get_attachment_url', static fn($resolved, $attachment) => $attachment === $id ? $url : $resolved, 999, 2);
    add_filter('pre_http_request', static function ($pre, $args, $request_url) use ($url, &$bytes) {
        if ($request_url !== $url) { return new \WP_Error('test', 'Unexpected URL'); }
        if (!empty($args['stream'])) { file_put_contents($args['filename'], $bytes); }
        return ['headers' => ['etag' => md5($bytes), 'content-type' => 'image/png', 'content-length' => strlen($bytes)],
            'response' => ['code' => 200], 'body' => ''];
    }, 999, 3);
    $registry = ['rows' => [['sku' => $sku, 'barcode' => '', 'row_number' => 2, 'source_file' => $stem . '.png', 'role' => 'main']]];
    $uploads = [['name' => $stem . '.png', 'tmp_name' => $original, 'error' => 0, 'size' => filesize($original)]];
    $validate = static fn($choice) => (new ImageValidator())->validate($registry, $uploads, false, [2 => $choice])['rows'][0];
    $row = $validate(['filename' => $stem . '-front.png']);
    row_check($row['valid'] && $row['width'] === 243 && $row['height'] === 102 && $row['warnings']
        && $row['canonical_file'] === $stem . '-front.png', 'renamed small swatch is READY with warnings');
    row_check($validate(['filename' => $name])['status'] === 'NAME_CONFLICT_WP', 'occupied WordPress name still blocked');
    $taken = $stem . '-taken.png';
    row_check($wpdb->insert($table, ['filename_lower' => $taken, 'full_key' => 'wp-content/uploads/' . $taken,
        'size_bytes' => strlen($bytes), 'etag' => md5($bytes)]), 'S3-only fixture created');
    row_check($validate(['filename' => $taken])['status'] === 'NAME_CONFLICT_S3', 'occupied S3-only name still blocked');
    row_check(!$validate(['filename' => '../bad.png'])['valid'] && !$validate(['filename' => 'wrong.jpg'])['valid'], 'invalid explicit names blocked');
    row_check(in_array($id, array_column(LibraryImage::candidates(['product_id' => $product->get_id(), 'canonical_file' => $stem . '.png']), 'id'), true), 'scaled candidate discoverable');
    $row = $validate(['attachment' => $id]);
    row_check($row['valid'] && $row['attachment_id'] === $id && $row['canonical_file'] === $name
        && $row['sha256'] === hash('sha256', $bytes) && $row['sha256'] !== hash_file('sha256', $original), 'selected attachment bytes validated instead of source');
    $uploaded = (new MediaUploader())->upload($row, 'local-test', 'local-test');
    row_check($uploaded['status'] === 'UPLOADED' && $uploaded['attachment_id'] === $id && $uploaded['s3_key'] === $key, 'existing attachment reused without sideload');
    $bytes[0] = 'X';
    row_check($validate(['attachment' => $id])['status'] === 'S3_PROOF_FAILED', 'changed object blocked before assignment');
    row_check(!$validate(['attachment' => $product->get_id()])['valid'], 'non-image selection blocked');
} finally {
    $wpdb->query('ROLLBACK');
    if (isset($id)) { clean_post_cache($id); }
    wp_delete_file($original);
}
