<?php
/**
 * Autoload Composer vendor placed in wp-content/vendor
 */
if (!defined('ABSPATH')) exit;


$autoload = WP_CONTENT_DIR . '/vendor/autoload.php';
if (file_exists($autoload) && !class_exists(\Composer\Autoload\ClassLoader::class, false)) {
    require_once $autoload;
}