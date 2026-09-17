<?php
// Run with WP-CLI on paint.local; only temporary files are written.
if (!defined('WP_CLI') || !WP_CLI || wp_get_environment_type() !== 'local'
    || wp_parse_url(home_url(), PHP_URL_HOST) !== 'paint.local') throw new RuntimeException('Local only');
$passed = 0;
$check = static function ($condition, $message) use (&$passed) {
    if (!$condition) throw new RuntimeException($message);
    $passed++;
};
$base = trailingslashit(SLW_PLUGIN_DIR_URL);
$cases = [
    'style_loader_src' => [
        'slw-frontend-styles' => 'css/frontend-style.css',
        'slw-common-styles' => 'css/common-style.css',
    ],
    'script_loader_src' => [
        'slw-common-scripts' => 'js/common.js',
        'slw-jquery-blockui' => 'js/jquery.blockUI.js',
        'slw-frontend-product-underscore' => 'js/underscore-min.js',
        'slw-frontend-product-scripts' => 'js/product.js',
        'slw-archive-scripts' => 'js/archive.js',
        'slw-frontend-cart-scripts' => 'js/cart.js',
    ],
];
$inventory = [];
foreach ($cases as $filter => $assets) {
    foreach ($assets as $handle => $relative) {
        $one = apply_filters($filter, $base.$relative.'?ver=123&other=keep#asset', $handle);
        $two = apply_filters($filter, $base.$relative.'?ver=456&other=keep#asset', $handle);
        $check($one === $two, 'Stable across vendor timestamps: '.$handle);
        parse_str(wp_parse_url($one, PHP_URL_QUERY), $query);
        $check(str_starts_with($query['ver'] ?? '', SLW_PLUGIN_VERSION.'-'), 'Plugin version included');
        $check(($query['other'] ?? '') === 'keep' && wp_parse_url($one, PHP_URL_FRAGMENT) === 'asset', 'Other URL data preserved');
        $inventory[$handle] = ['file'=>$relative,'version'=>$query['ver'],'bytes'=>filesize(SLW_PLUGIN_DIR.'/'.$relative)];
    }
}
$src = $base.'js/common.js?ver=123';
$check(PSU_SLW_Assets::source($src, 'unrelated-script') === $src, 'Unknown handles untouched');
$other = $base.'js/product.js?ver=123';
$check(PSU_SLW_Assets::source($other, 'slw-common-scripts') === $other, 'Replaced path untouched');
$cdn = str_replace(wp_parse_url($base, PHP_URL_HOST), 'cdn.example.invalid', $src);
$check(PSU_SLW_Assets::source($cdn, 'slw-common-scripts') === $cdn, 'CDN replacement untouched');

$file = tempnam(sys_get_temp_dir(), 'psu-slw-version-');
try {
    file_put_contents($file, 'first content');
    $original_time = filemtime($file);
    $first = PSU_SLW_Assets::version($file, '3.2.1');
    $check($first === PSU_SLW_Assets::version($file, '3.2.1'), 'Unchanged file stable');
    file_put_contents($file, 'other content');
    touch($file, $original_time);
    $check($first !== PSU_SLW_Assets::version($file, '3.2.1'), 'Content change invalidates even with preserved mtime');
    $check(PSU_SLW_Assets::version($file, '3.2.1') !== PSU_SLW_Assets::version($file, '3.2.2'), 'Plugin update invalidates');
    $check(PSU_SLW_Assets::version($file.'-missing', '3.2.1') === null, 'Missing file fallback');
} finally { unlink($file); }

// The output filters must not alter dependencies, localized data, inline code or placement.
$scripts = wp_scripts();
$saved = isset($scripts->registered['slw-common-scripts']) ? clone $scripts->registered['slw-common-scripts'] : null;
try {
    wp_deregister_script('slw-common-scripts');
    wp_register_script('slw-common-scripts', $base.'js/common.js', ['jquery'], '123', true);
    wp_localize_script('slw-common-scripts', 'slw_test_fixture', ['stock'=>17]);
    wp_add_inline_script('slw-common-scripts', 'window.slwTestMarker = true;', 'after');
    $before = serialize($scripts->registered['slw-common-scripts']);
    apply_filters('script_loader_src', $src, 'slw-common-scripts');
    $check(serialize($scripts->registered['slw-common-scripts']) === $before, 'Registry and dynamic data unchanged');
} finally {
    unset($scripts->registered['slw-common-scripts']);
    if ($saved) $scripts->registered['slw-common-scripts'] = $saved;
}
require_once ABSPATH.'wp-admin/includes/screen.php';
$saved_screen = $GLOBALS['current_screen'] ?? null;
try {
    set_current_screen('dashboard');
    $check(PSU_SLW_Assets::source($src, 'slw-common-scripts') === $src, 'Admin untouched');
} finally { $GLOBALS['current_screen'] = $saved_screen; }
echo wp_json_encode(['passed'=>$passed,'inventory'=>$inventory], JSON_PRETTY_PRINT), "\n";
