<?php
/*
Plugin Name: Paint Core
Description: Бизнес-логика магазина (CSV к письмам, цены, склады и т.п.).
Author: Volodymyr
Version: 1.0.0
*/
defined('ABSPATH') || exit;

define('PAINT_CORE_PATH', plugin_dir_path(__FILE__));
define('PAINT_CORE_URL',  plugin_dir_url(__FILE__)); // ДЛЯ assets
define('PAINT_CORE_INC', plugin_dir_path(__FILE__) . 'inc/');

// 1) приоритетные файлы
$priority = [
  'utils.php',            // здесь pc_log и константы
  'stock-public.php',     // здесь pc_available_qty_for_product()
  // добавь сюда, если нужно: 'stock-locations-display.php', ...
];

// сначала грузим по приоритету, если существуют
foreach ($priority as $fname) {
    $full = PAINT_CORE_INC . $fname;
    if (file_exists($full)) require_once $full;
}

// 2) потом — все остальные inc/*.php, кроме уже загруженных
$loaded = array_flip($priority);
$all = glob(PAINT_CORE_INC . '*.php') ?: [];
sort($all);
foreach ($all as $f) {
    $base = basename($f);
    if (!isset($loaded[$base])) require_once $f;
}