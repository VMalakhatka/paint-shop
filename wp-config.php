<?php
/**
 * wp-config.php
 *
 * Главный конфиг-загрузчик: подключает common и окружение (local/production/staging).
 */

// Абсолютный путь
if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}

define('WP_APPLICATION_PASSWORDS_ALLOW_HTTP', true);

// Если нужно только проверить конфиги без загрузки ядра
if (defined('WP_SKIP_BOOTSTRAP') && WP_SKIP_BOOTSTRAP) {
    require __DIR__ . '/wp-config.common.php';

    // Определяем окружение
    if (defined('WP_ENVIRONMENT_TYPE')) {
        $env = strtolower(WP_ENVIRONMENT_TYPE);
    } elseif (file_exists(__DIR__ . '/wp-config.local.php')) {
        $env = 'local';
        define('WP_ENVIRONMENT_TYPE', 'local');
    } else {
        $env = 'production';
        define('WP_ENVIRONMENT_TYPE', 'production');
    }

    $map = [
        'local'      => __DIR__ . '/wp-config.local.php',
        'staging'    => __DIR__ . '/wp-config.staging.php',
        'production' => __DIR__ . '/wp-config.production.php',
    ];
    $path = $map[$env] ?? (__DIR__ . "/wp-config.$env.php");
    if (file_exists($path)) {
        require $path;
    } else {
        header('Content-Type: text/plain; charset=UTF-8');
        die("❌ Config file not found for env '{$env}': {$path}");
    }

    return;
}

// --- Обычная загрузка с ядром ---
require __DIR__ . '/wp-config.common.php';

// Определяем окружение
if (defined('WP_ENVIRONMENT_TYPE')) {
    $env = strtolower(WP_ENVIRONMENT_TYPE);
} elseif (file_exists(__DIR__ . '/wp-config.local.php')) {
    $env = 'local';
    define('WP_ENVIRONMENT_TYPE', 'local');
} else {
    $env = 'production';
    define('WP_ENVIRONMENT_TYPE', 'production');
}

$map = [
    'local'      => __DIR__ . '/wp-config.local.php',
    'staging'    => __DIR__ . '/wp-config.staging.php',
    'production' => __DIR__ . '/wp-config.production.php',
];
$path = $map[$env] ?? (__DIR__ . "/wp-config.$env.php");
if (file_exists($path)) {
    require $path;
} else {
    header('Content-Type: text/plain; charset=UTF-8');
    die("❌ Config file not found for env '{$env}': {$path}");
}

// Запускаем ядро WP
require_once ABSPATH . 'wp-settings.php';