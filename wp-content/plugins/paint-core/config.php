<?php
namespace PaintCore;

defined('ABSPATH') || exit;

/**
 * PaintCore global config
 */
final class Config
{
    /** Включить логи */
    public const DEBUG = false;

    /** Отключить «старые» строки складов в корзине из Paint Core */
    public const DISABLE_LEGACY_CART_LOCATIONS = true;

    /** Включить новый алгоритм распределения списания */
    public const ENABLE_STOCK_ALLOCATION = true;
}

/** Удобный хелпер логов (внутри ns) */
if (!function_exists(__NAMESPACE__ . '\\pc_log')) {
    function pc_log($msg) {
        if (Config::DEBUG) {
            error_log('[PC] ' . (is_scalar($msg) ? $msg : wp_json_encode($msg)));
        }
    }
}

/** Глобальный фильтр из конфига */
\add_filter('pc_disable_legacy_cart_locations', static function () {
    return Config::DISABLE_LEGACY_CART_LOCATIONS;
});