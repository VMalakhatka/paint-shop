<?php
/**
 * Plugin Name: Lavka Product Media Upload
 * Description: Validates product image batches from XLS/XLSX manifests and reports naming, product, WordPress and S3 conflicts.
 * Version: 0.1.1
 * Author: Volodymyr
 * Text Domain: lavka-product-media-upload
 * Domain Path: /languages
 * Requires PHP: 8.1
 * Requires Plugins: woocommerce
 */

if (!defined('ABSPATH')) {
    exit;
}

define('LPMU_VERSION', '0.1.1');
define('LPMU_FILE', __FILE__);
define('LPMU_DIR', plugin_dir_path(__FILE__));
define('LPMU_URL', plugin_dir_url(__FILE__));

if (!defined('LPMU_ENABLE_WRITES')) {
    define('LPMU_ENABLE_WRITES', true);
}

function lpmu_writes_enabled(): bool
{
    return (bool) apply_filters('lavka_product_media_upload_enable_writes', LPMU_ENABLE_WRITES);
}

require_once LPMU_DIR . 'inc/class-registry-reader.php';
require_once LPMU_DIR . 'inc/class-product-resolver.php';
require_once LPMU_DIR . 'inc/class-image-validator.php';
require_once LPMU_DIR . 'inc/class-media-uploader.php';
require_once LPMU_DIR . 'inc/class-report-store.php';
require_once LPMU_DIR . 'inc/class-batch-service.php';
require_once LPMU_DIR . 'inc/class-plugin.php';

add_action('plugins_loaded', static function (): void {
    \Lavka\ProductMediaUpload\Plugin::instance()->boot();
});
