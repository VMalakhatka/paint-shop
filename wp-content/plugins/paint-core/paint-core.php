<?php
/*
Plugin Name: Paint Core
Description: Бизнес-логика магазина (CSV к письмам, цены, склады и т.п.).
Author: Volodymyr
Version: 1.0.0
*/
defined('ABSPATH') || exit;

define('PAINT_CORE_PATH', plugin_dir_path(__FILE__));
define('PAINT_CORE_INC',  PAINT_CORE_PATH . 'inc/');

// Подключаем все .php из inc/
$files = glob(PAINT_CORE_INC . '*.php') ?: [];
foreach ($files as $f) {
    require_once $f;
}