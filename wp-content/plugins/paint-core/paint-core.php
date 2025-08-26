<?php
/*
Plugin Name: Paint Core
Description: Бизнес-логика магазина (CSV к письмам, цены, склады и т.п.).
Author: Volodymyr
Version: 1.0.0
*/
defined('ABSPATH') || exit;

define('PAINT_CORE_PATH', plugin_dir_path(__FILE__));
define('PAINT_CORE_URL',  plugin_dir_url(__FILE__));
define('PAINT_CORE_INC',  PAINT_CORE_PATH . 'inc/');

/* 0) Конфиг (должен грузиться ПЕРВЫМ) */
$pc_config = PAINT_CORE_PATH . 'config.php';
if (file_exists($pc_config)) {
    require_once $pc_config;
}

/* 1) Файлы с приоритетом (если нужны ранние функции) */
$priority = [
    'stock-public.php',
];

foreach ($priority as $fname) {
    $full = PAINT_CORE_INC . $fname;
    if (file_exists($full)) {
        require_once $full;
    }
}

/* 2) Все остальные inc/*.php (кроме уже загруженных) */
$loaded = array_flip($priority);
$all = glob(PAINT_CORE_INC . '*.php') ?: [];
sort($all);
foreach ($all as $f) {
    $base = basename($f);
    if (!isset($loaded[$base])) {
        require_once $f;
    }
}