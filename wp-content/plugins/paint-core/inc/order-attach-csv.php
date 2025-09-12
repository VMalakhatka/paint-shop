<?php
namespace PaintCore\Orders;

defined('ABSPATH') || exit;

// единый набор складских хелперов
require_once __DIR__ . '/warehouse-helpers.php';

/**
 * CSV-вложение к письмам WooCommerce.
 *
 * Режими:
 *  - by_plan (дефолт): окремі рядки по кожному складу
 *  - summary: один рядок на позицію, а розбивку по складах пишемо в колонку Notes
 *             як "Списання: Одеса — 3, Київ — 1".
 *
 * GTIN: береться «як є» (можуть бути букви) з першого знайденого ключа:
 * - якщо існує глобальний хелпер \pc_get_product_gtin() — використовуємо його;
 * - інакше шукаємо у PC_GTIN_META_KEY або в списку pc_gtin_meta_keys().
 */

/** ------------------------- GTIN (без нормалізації) ------------------------- */

/** Повертає список ключів мета, де може жити GTIN (можна розширити фільтром). */
function pc_gtin_meta_keys(): array {
    $keys = array_filter(array_unique(array_merge(
        [ defined('PC_GTIN_META_KEY') ? PC_GTIN_META_KEY : '' ],
        [
            '_global_unique_id',  // ваш ключ
            '_wpm_gtin_code',     // WebToffee/Woo product GTIN
            '_alg_ean',           // EAN/UPC/GTIN by Alg
            '_ean',
            '_sku_gtin',
        ]
    )));
    return apply_filters('pc_gtin_meta_keys', $keys);
}

/** Отримати GTIN із продукту/варіації «як є». */
function pc_csv_get_product_gtin(\WC_Product $product): string {
    // 1) глобальний хелпер, якщо є
    if (function_exists('\\pc_get_product_gtin')) {
        $v = \pc_get_product_gtin($product);
        if ($v !== '' && $v !== null) return (string)$v;
    }

    // 2) локальний пошук
    $fetch = function(int $post_id): string {
        foreach (pc_gtin_meta_keys() as $key) {
            if ($key === '') continue;
            $raw = get_post_meta($post_id, $key, true);
            if ($raw !== '' && $raw !== null) return (string)$raw; // як є
        }
        return '';
    };

    $v = $fetch($product->get_id());
    if ($v !== '') return $v;

    if ($product->is_type('variation')) {
        $pid = (int)$product->get_parent_id();
        if ($pid) {
            $v = $fetch($pid);
            if ($v !== '') return $v;
        }
    }
    return '';
}

/** ------------------------- Локації / склад ------------------------- */

/** Primary term_id локації для продукту/варіації. */
function pc_primary_location_term_id_for_product(\WC_Product $product): int {
    $pid = $product->get_id();
    $tid = (int) get_post_meta($pid, '_yoast_wpseo_primary_location', true);
    if (!$tid && $product->is_type('variation')) {
        $parent = $product->get_parent_id();
        if ($parent) $tid = (int) get_post_meta($parent, '_yoast_wpseo_primary_location', true);
    }
    return $tid ?: 0;
}

/** Назва локації по term_id/slug (без фатальних). */
function pc_location_name_by($term_id = 0, string $slug = ''): string {
    if ($term_id) {
        $t = get_term((int)$term_id, 'location');
        if ($t && !is_wp_error($t)) return $t->name;
    }
    if ($slug !== '') {
        $t = get_term_by('slug', $slug, 'location');
        if ($t && !is_wp_error($t)) return $t->name;
    }
    return '';
}

/** ------------------------- Хедери/настройки CSV ------------------------- */

/** Режим експорту: 'by_plan' (дефолт) або 'summary'. */
function pc_orders_csv_mode(): string {
    $mode = apply_filters('pc_orders_csv_mode', 'by_plan');
    return in_array($mode, ['by_plan', 'summary'], true) ? $mode : 'by_plan';
}

/** Заголовки колонок (можна перевизначити фільтром). */
function pc_orders_csv_headers(string $mode): array {
    // Локализуем базовые названия колонок
    $headers = ($mode === 'summary')
        ? [
            __('SKU', 'paint-core'),
            __('GTIN', 'paint-core'),
            __('Quantity', 'paint-core'),
            __('Price', 'paint-core'),
            __('Location', 'paint-core'),
            __('Notes', 'paint-core'),
        ]
        : [
            __('SKU', 'paint-core'),
            __('GTIN', 'paint-core'),
            __('Quantity', 'paint-core'),
            __('Price', 'paint-core'),
            __('Location', 'paint-core'),
        ];
    return apply_filters('pc_orders_csv_headers', $headers, $mode);
}

/** Роздільник CSV (за замовчуванням — ';'). */
function pc_orders_csv_delimiter(): string {
    return (string) apply_filters('pc_orders_csv_delimiter', ';');
}

/** ------------------------- Генерація та прикріплення ------------------------- */

add_filter('woocommerce_email_attachments', function ($attachments, $email_id, $order, $email) {

    if (!($order instanceof \WC_Order)) return $attachments;

    $mode     = pc_orders_csv_mode();             // 'by_plan' | 'summary'
    $headers  = pc_orders_csv_headers($mode);
    $rows     = [ $headers ];

    // Назви локацій, щоб швидко діставати
    $locById = [];
    $terms = get_terms(['taxonomy'=>'location','hide_empty'=>false]);
    if (!is_wp_error($terms)) {
        foreach ($terms as $t) $locById[(int)$t->term_id] = $t->name;
    }

    foreach ($order->get_items('line_item') as $item) {
        if (!($item instanceof \WC_Order_Item_Product)) continue;
        $product = $item->get_product();
        if (!$product) continue;

        // SKU (для варіацій — фолбек на батька)
        $sku = $product->get_sku();
        if (!$sku && $product->is_type('variation')) {
            $parent = wc_get_product($product->get_parent_id());
            if ($parent) $sku = $parent->get_sku();
        }

        // GTIN (як є)
        $gtin = pc_csv_get_product_gtin($product);

        $qty_total  = max(0, (int)$item->get_quantity());
        $line_total = (float)$item->get_total();
        $unit_price = $qty_total > 0 ? $line_total / $qty_total : $line_total;

        // лан (для by_plan або для нотатки в summary)
        $plan = \pc_get_order_item_plan($item);

         if ($mode === 'by_plan') {
            if (!empty($plan)) {
                foreach ($plan as $term_id => $q) {
                    $term_id = (int)$term_id;
                    $q = (int)$q;
                    if ($term_id <= 0 || $q <= 0) continue;
                    $rows[] = [
                        $sku ?: '',
                        $gtin ?: '',
                        $q,
                        wc_format_decimal($unit_price, wc_get_price_decimals()),
                        $locById[$term_id] ?? ('#'.$term_id),
                    ];
                }
            } else {
                // фолбек: если плана нет — одной строкой с primary
                $primary_id = pc_primary_location_term_id_for_product($product);
                $rows[] = [
                    $sku ?: '',
                    $gtin ?: '',
                    $qty_total,
                    wc_format_decimal($unit_price, wc_get_price_decimals()),
                    $locById[$primary_id] ?? '',
                ];
            }
        } else { // summary
            $loc_label = pc_order_item_location_label($item); // "Одеса — 3, Київ — 1" либо одно значение
            $notes     = $loc_label !== '' ? sprintf(__('Write-off: %s', 'paint-core'), $loc_label) : '';
            $rows[] = [
                $sku ?: '',
                $gtin ?: '',
                $qty_total,
                wc_format_decimal($unit_price, wc_get_price_decimals()),
                $loc_label,
                $notes,
            ];
        }

    }

    // Створюємо CSV у тимчасовому місці
    $uploads  = function_exists('wp_upload_dir') ? wp_upload_dir() : null;
    $tmp_base = ($uploads && is_dir($uploads['basedir']) && is_writable($uploads['basedir']))
        ? $uploads['basedir'] : sys_get_temp_dir();

    $filename = sprintf('wc-order-%d-%s.csv', $order->get_id(), uniqid());
    $path     = trailingslashit($tmp_base) . $filename;

    $delim = pc_orders_csv_delimiter();

    if ($fh = fopen($path, 'w')) {
        fwrite($fh, "\xEF\xBB\xBF"); // UTF-8 BOM
        foreach ($rows as $r) fputcsv($fh, $r, $delim);
        fclose($fh);

        $attachments[] = $path;
        set_transient('wc_csv_is_mine_' . md5($path), 1, HOUR_IN_SECONDS);
    }
    return $attachments;
}, 99, 4);

/** ------------------------- Прибирання тимчасових файлів ------------------------- */
add_action('wp_mail_succeeded', function($data){
    foreach ((array)($data['attachments'] ?? []) as $file) {
        if ($file && get_transient('wc_csv_is_mine_' . md5($file))) {
            @unlink($file);
            delete_transient('wc_csv_is_mine_' . md5($file));
        }
    }
});
add_action('wp_mail_failed', function($err){
    $data = method_exists($err, 'get_error_data') ? (array) $err->get_error_data() : [];
    foreach ((array)($data['attachments'] ?? []) as $file) {
        if ($file && get_transient('wc_csv_is_mine_' . md5($file))) {
            @unlink($file);
            delete_transient('wc_csv_is_mine_' . md5($file));
        }
    }
});