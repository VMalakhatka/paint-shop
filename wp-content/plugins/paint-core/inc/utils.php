<?php
defined('ABSPATH') || exit;

const PC_DEBUG = false; // включай true для отладки

if (!function_exists('pc_log')) {
    function pc_log($msg) {
        if (defined('PC_DEBUG') && PC_DEBUG) {
            error_log('[PC] ' . $msg);
        }
    }
}