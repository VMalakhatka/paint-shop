<?php
if (!defined('ABSPATH')) exit;

if (!defined('LAVKA_SYNC_OPTION')) {
    define('LAVKA_SYNC_OPTION', 'lavka_sync_options');
}

/** Опции плагина (единая точка правды) */
if (!function_exists('lavka_sync_get_options')) {
    function lavka_sync_get_options(): array {
        $defaults = [
            // UI / интеграционные настройки
            'java_base_url' => 'http://127.0.0.1:8080',
            'api_token'     => '',
            'supplier'      => 'KREUL',
            'stock_id'      => '7',
            'schedule'      => 'off', // off|hourly|twicedaily|daily
            'java_wh_path'    => '/warehouses', // <— НОВОЕ: эндпоинт Java со справочником складов
            'java_stock_query_path' => '/admin/stock/stock/query',
            'java_stock_query_path'    => '/admin/stock/stock/query',

            // Флаги поведения при записи остатков
            'set_manage'          => true,
            'upd_status'          => true,
            'attach_terms'        => true,
            'set_primary'         => true,
            'duplicate_slug_meta' => false,
        ];
        return wp_parse_args(get_option(LAVKA_SYNC_OPTION, []), $defaults);
    }
}

/** Разрешаем Application Passwords в локалке/HTTP (если нужно) */
add_filter('wp_is_application_passwords_available', function ($available) {
    if ($available) return true;
    if (defined('LAVKA_ALLOW_APP_PW_OVER_HTTP') && LAVKA_ALLOW_APP_PW_OVER_HTTP) return true;

    $host    = $_SERVER['HTTP_HOST']   ?? '';
    $remote  = $_SERVER['REMOTE_ADDR'] ?? '';
    $is_local = in_array($remote, ['127.0.0.1','::1'], true)
             || str_contains($host, 'localhost')
             || str_ends_with($host, '.local');
    return $is_local ? true : $available;
}, 10, 1);

/** Роли/права + таблица логов на активации (регаем из core, указывая главный файл) */
register_activation_hook(dirname(__DIR__) . '/lavka-sync.php', function () {
    // Роль на базе shop_manager
    $base = get_role('shop_manager');
    if ($base && !get_role('lavka_manager')) {
        add_role('lavka_manager', 'Lavka Manager', $base->capabilities);
    }
    // Права
    foreach (['shop_manager','lavka_manager','administrator'] as $role) {
        if ($r = get_role($role)) {
            $r->add_cap('manage_lavka_sync');
            $r->add_cap('view_lavka_reports');
        }
    }

    // Таблица логов
    global $wpdb;
    $table   = $wpdb->prefix . 'lavka_sync_logs';
    $charset = $wpdb->get_charset_collate();
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta("
        CREATE TABLE {$table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        ts DATETIME NOT NULL,
        action VARCHAR(50) NOT NULL,
        supplier VARCHAR(100) NOT NULL,
        stock_id INT NOT NULL,
        dry TINYINT(1) NOT NULL DEFAULT 0,
        changed_since_hours INT NULL,
        updated INT NOT NULL DEFAULT 0,
        not_found INT NOT NULL DEFAULT 0,
        duration_ms INT NOT NULL DEFAULT 0,
        status VARCHAR(20) NOT NULL DEFAULT 'OK',
        message TEXT NULL,
        PRIMARY KEY (id),
        KEY ts (ts),
        KEY action (action)
        ) {$charset};
    ");
});

/** На всякий случай — дожимаем капы на init */
add_action('init', function () {
    foreach (['administrator','shop_manager','lavka_manager'] as $role_name) {
        if ($role = get_role($role_name)) {
            if (!$role->has_cap('manage_lavka_sync'))   $role->add_cap('manage_lavka_sync');
            if (!$role->has_cap('view_lavka_reports'))  $role->add_cap('view_lavka_reports');
        }
    }
});

/* =========================
 * ВСПОМОГАТЕЛЬНЫЕ ФУНКЦИИ
 * ========================= */

/** Быстрый поиск товара по SKU (product/variation) */
function lavka_find_product_id_by_sku(string $sku): int {
    $sku = trim($sku);
    if ($sku === '') return 0;

    if (function_exists('wc_get_product_id_by_sku')) {
        $pid = (int) wc_get_product_id_by_sku($sku);
        if ($pid) return $pid;
    }
    global $wpdb;
    return (int) $wpdb->get_var($wpdb->prepare(
        "SELECT p.ID
         FROM {$wpdb->posts} p
         JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_sku'
         WHERE m.meta_value = %s AND p.post_type IN ('product','product_variation')
         LIMIT 1", $sku
    ));
}

/** Кэш термов location по slug */
function lavka_get_location_term_by_slug(string $slug) {
    static $cache = [];
    $slug = sanitize_title($slug);
    if ($slug === '') return null;
    if (array_key_exists($slug, $cache)) return $cache[$slug];
    $t = get_term_by('slug', $slug, 'location');
    $cache[$slug] = ($t && !is_wp_error($t)) ? $t : null;
    return $cache[$slug];
}

/** Привязка товара к термину location (если отсутствует) */
function lavka_attach_location_term(int $product_id, int $term_id): void {
    $terms = wp_get_object_terms($product_id, 'location', ['fields' => 'ids']);
    if (is_wp_error($terms)) return;
    if (!in_array($term_id, array_map('intval', $terms), true)) {
        wp_set_object_terms($product_id, array_merge($terms ?: [], [$term_id]), 'location', false);
    }
}

/** Поставить primary location, если не стоит */
function lavka_set_primary_location_if_missing(int $product_id, int $term_id): void {
    $cur = (int) get_post_meta($product_id, '_yoast_wpseo_primary_location', true);
    if (!$cur) update_post_meta($product_id, '_yoast_wpseo_primary_location', $term_id);
}

/**
 * Запись остатков по SKU.
 * $lines: [["location_slug"=>"odesa","qty"=>12.34], ...]
 * $opts:  ['set_manage'=>bool,'upd_status'=>bool,'attach_terms'=>bool,'set_primary'=>bool,'duplicate_slug_meta'=>bool,'dry'=>bool]
 */
function lavka_write_stock_for_sku(string $sku, array $lines, array $opts): array {
    $pid = lavka_find_product_id_by_sku($sku);
    if (!$pid) {
        return ['sku' => $sku, 'found' => false];
    }

    $rows         = [];
    $sum          = 0.0;
    $meta_records = 0;
    $first_ok_tid = 0;

    foreach ($lines as $row) {
        if (!is_array($row)) continue;

        // qty обязателен и числовой
        if (!array_key_exists('qty', $row) || !is_numeric($row['qty'])) {
            $rows[] = ['ok' => false, 'error' => 'qty_invalid'];
            continue;
        }
        $qty = (float) $row['qty'];

        $term = null;
        $tid  = 0;
        $slug = null;

        if (isset($row['term_id']) || isset($row['id'])) {
            $tid  = (int) ($row['term_id'] ?? $row['id']);
            $term = get_term($tid, 'location');
            if ($term && !is_wp_error($term)) {
                $tid  = (int) $term->term_id;
                $slug = (string) $term->slug;
            } else {
                $rows[] = ['ok' => false, 'term_id' => (int)$tid, 'qty' => $qty, 'error' => 'term_not_found'];
                continue;
            }
        } elseif (isset($row['location_slug'])) {
            $slugIn = sanitize_title((string)$row['location_slug']);
            if ($slugIn === '') {
                $rows[] = ['ok' => false, 'location_slug' => '', 'qty' => $qty, 'error' => 'slug_empty'];
                continue;
            }
            $term = get_term_by('slug', $slugIn, 'location');
            if ($term && !is_wp_error($term)) {
                $tid  = (int) $term->term_id;
                $slug = (string) $term->slug;
            } else {
                $rows[] = ['ok' => false, 'location_slug' => $slugIn, 'qty' => $qty, 'error' => 'term_not_found'];
                continue;
            }
        } else {
            $rows[] = ['ok' => false, 'qty' => $qty, 'error' => 'no_term_id_or_slug'];
            continue;
        }

        // запись по TERM_ID (+ опциональный дубль по slug)
        $meta_key_id = '_stock_at_' . $tid;
        if (empty($opts['dry'])) {
            update_post_meta($pid, $meta_key_id, wc_format_decimal($qty, 3));
        }
        $meta_records++;

        if (!empty($opts['duplicate_slug_meta']) && $slug) {
            $meta_key_slug = '_stock_at_' . $slug;
            if (empty($opts['dry'])) {
                update_post_meta($pid, $meta_key_slug, wc_format_decimal($qty, 3));
            }
            $meta_records++;
        }

        // привязать term к товару (не добавляет дубликаты)
        if (!empty($opts['attach_terms']) && empty($opts['dry'])) {
            wp_set_object_terms($pid, [$tid], 'location', true);
        }

        // аккумулируем
        $sum += $qty;
        if ($first_ok_tid === 0) $first_ok_tid = $tid;

        $rows[] = [
            'ok'      => true,
            'term_id' => $tid,
            'slug'    => $slug,
            'qty'     => $qty,
        ];
    }

    // итоговые поля товара
    if (empty($opts['dry'])) {
        update_post_meta($pid, '_stock', wc_format_decimal($sum, 3));

        if (!empty($opts['set_manage'])) {
            update_post_meta($pid, '_manage_stock', 'yes');
        }
        if (!empty($opts['upd_status'])) {
            update_post_meta($pid, '_stock_status', $sum > 0 ? 'instock' : 'outofstock');
            if (function_exists('wc_delete_product_transients')) {
                wc_delete_product_transients($pid);
            }
        }
        if (!empty($opts['set_primary']) && $first_ok_tid > 0) {
            if (function_exists('lavka_set_primary_location_if_missing')) {
                lavka_set_primary_location_if_missing($pid, $first_ok_tid);
            } else {
                // запасной вариант: Yoast Primary term
                $cur = (int) get_post_meta($pid, '_yoast_wpseo_primary_location', true);
                if (!$cur) update_post_meta($pid, '_yoast_wpseo_primary_location', $first_ok_tid);
            }
        }
    }

    return [
        'sku'        => $sku,
        'found'      => true,
        'product_id' => (int) $pid,
        'total'      => (float) $sum,
        'lines'      => $rows,
        'dry'        => !empty($opts['dry']),
    ];
}

add_action('init', function () {
    foreach (['administrator','shop_manager','lavka_manager'] as $role_name) {
        if ($r = get_role($role_name)) {
            $r->add_cap('manage_lavka_sync');
            $r->add_cap('view_lavka_reports');
        }
    }
});