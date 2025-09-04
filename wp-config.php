<?php
/**
 * Main loader config
 *
 * Определяет окружение и подключает нужный файл
 */

require_once __DIR__ . '/wp-config.common.php';

// Определяем окружение (по константе WP_ENVIRONMENT_TYPE)
$env = defined('WP_ENVIRONMENT_TYPE') ? WP_ENVIRONMENT_TYPE : 'production';

// Путь к файлу окружения
$path = __DIR__ . "/wp-config.$env.php";

if (file_exists($path)) {
    require_once $path;
} else {
    die("❌ Config file not found: $path");
}