<?php
/**
 * MU I18N Loader: централизованная загрузка переводов для MU-плагинов.
 * Кладите .mo файлы в wp-content/mu-plugins/languages
 * Формат: <textdomain>-<locale>.mo (например, paint-core-uk_UA.mo)
 */

if (!defined('ABSPATH')) exit;

add_action('muplugins_loaded', function () {
    // Перечень текст-доменов MU-плагинов (добавляй при необходимости)
    $domains = [
        'pc-account-tweaks',
        'pc-cart-guard',
        'pc-stock-tap',
        'pc-wholesale-quick-order',
        'psu-force-per-page',
        'psu-search-filters',
        'role-price-import-lite',
        'stock-import-csv-lite',
        'stock-locations-ui',
        'stock-sync-to-woo',
        // если нужно: 'pc-order-import-export', но это обычный плагин, не MU
    ];

    $lang_rel_dir = 'languages'; // относительный путь внутри mu-plugins
    $lang_abs_dir = WPMU_PLUGIN_DIR . '/' . $lang_rel_dir;

    // Определяем локаль и готовим кандидатов (uk_UA -> [uk_UA, uk])
    $locale = function_exists('determine_locale') ? determine_locale() : get_locale();
    $candidates = [$locale];
    if (strpos($locale, '_') !== false) {
        $lang_only = substr($locale, 0, strpos($locale, '_'));
        if ($lang_only) $candidates[] = $lang_only;
    }

    foreach ($domains as $domain) {
        // 1) Пытаемся стандартно через load_muplugin_textdomain (WP сам соберёт путь)
        $loaded = function_exists('load_muplugin_textdomain')
            ? load_muplugin_textdomain($domain, $lang_rel_dir)
            : false;

        // 2) Если не загрузилось — вручную перебираем кандидатов
        if (!$loaded) {
            foreach ($candidates as $lc) {
                $mo = $lang_abs_dir . '/' . $domain . '-' . $lc . '.mo';
                if (file_exists($mo) && load_textdomain($domain, $mo)) {
                    $loaded = true;
                    break;
                }
            }
        }
        // (опционально можно логировать $loaded, если надо отладить)
    }
});