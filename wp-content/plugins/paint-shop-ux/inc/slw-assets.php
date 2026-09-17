<?php
/** Stable versions for the installed Stock Locations frontend assets. */
if (!defined('ABSPATH')) exit;

final class PSU_SLW_Assets {
    private const FILES = [
        'slw-frontend-styles'            => 'css/frontend-style.css',
        'slw-common-styles'              => 'css/common-style.css',
        'slw-common-scripts'             => 'js/common.js',
        'slw-jquery-blockui'              => 'js/jquery.blockUI.js',
        'slw-frontend-product-underscore' => 'js/underscore-min.js',
        'slw-frontend-product-scripts'    => 'js/product.js',
        'slw-archive-scripts'             => 'js/archive.js',
        'slw-frontend-cart-scripts'       => 'js/cart.js',
    ];

    public static function boot(): void {
        add_filter('script_loader_src', [self::class, 'source'], 20, 2);
        add_filter('style_loader_src', [self::class, 'source'], 20, 2);
    }

    public static function source(string $src, string $handle): string {
        if (is_admin() || !isset(self::FILES[$handle])
            || !defined('SLW_PLUGIN_DIR') || !defined('SLW_PLUGIN_DIR_URL')
            || !defined('SLW_PLUGIN_VERSION')) return $src;

        $relative = self::FILES[$handle];
        $expected = wp_parse_url(trailingslashit(SLW_PLUGIN_DIR_URL) . $relative);
        $actual = wp_parse_url($src);
        // A replacement/CDN resource under the same handle belongs to its owner.
        if (!$expected || !$actual
            || strcasecmp($actual['host'] ?? '', $expected['host'] ?? '') !== 0
            || ($actual['port'] ?? null) !== ($expected['port'] ?? null)
            || ($actual['path'] ?? '') !== ($expected['path'] ?? '')
            || isset($actual['user']) || isset($actual['pass'])) return $src;

        static $versions = [];
        if (!array_key_exists($relative, $versions)) {
            $versions[$relative] = self::version(
                trailingslashit(SLW_PLUGIN_DIR) . $relative,
                (string) SLW_PLUGIN_VERSION
            );
        }
        // Missing/unreadable files keep the vendor URL and its existing behavior.
        return $versions[$relative] === null ? $src : add_query_arg('ver', $versions[$relative], $src);
    }

    public static function version(string $file, string $plugin_version): ?string {
        if (!is_file($file) || !is_readable($file)) return null;
        $hash = hash_file('sha256', $file);
        return $hash === false ? null : $plugin_version . '-' . substr($hash, 0, 16);
    }
}

PSU_SLW_Assets::boot();
