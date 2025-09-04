<?php
/**
 * Универсальный загрузчик конфигов.
 * - Определяет окружение (production|staging|local)
 * - Подключает wp-config.common.php + нужный файл окружения
 * - Ядро WP грузит ТОЛЬКО если не задан WP_SKIP_BOOTSTRAP (удобно для CLI-проверок)
 */

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}

/** 1) Общие настройки (без DB и без wp-settings.php!) */
require __DIR__ . '/wp-config.common.php';

/** 2) Определяем окружение */
$env = getenv('WP_ENVIRONMENT_TYPE');
if ($env) {
    $env = strtolower($env);
} elseif (file_exists(__DIR__ . '/wp-config.local.php')) {
    $env = 'local';
} else {
    $env = 'production';
}
if (!defined('WP_ENVIRONMENT_TYPE')) {
    define('WP_ENVIRONMENT_TYPE', $env);
}

/** 3) Подключаем файл окружения */
$map = [
    'local'      => __DIR__ . '/wp-config.local.php',
    'staging'    => __DIR__ . '/wp-config.staging.php',
    'production' => __DIR__ . '/wp-config.production.php',
];
$envFile = $map[$env] ?? (__DIR__ . "/wp-config.$env.php");

if (!is_file($envFile)) {
    header('Content-Type: text/plain; charset=UTF-8');
    die("❌ Config for env '{$env}' not found: {$envFile}");
}
require $envFile;

/** 4) Санити-чек БД (чтобы не ловить «Database Error» от ядра) */
foreach (['DB_NAME','DB_USER','DB_PASSWORD','DB_HOST'] as $c) {
    if (!defined($c) || constant($c) === '') {
        header('Content-Type: text/plain; charset=UTF-8');
        die("❌ Missing DB constant: {$c} (env={$env}, file={$envFile})");
    }
}

/** 5) Грузим ядро WP, если не попросили пропустить */
if (!defined('WP_SKIP_BOOTSTRAP') || !WP_SKIP_BOOTSTRAP) {
    require_once ABSPATH . 'wp-settings.php';
}