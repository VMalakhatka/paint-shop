<?php
// === Реальная фиксация списания по складам, устойчива к параллельным заказам ===

/**
 * Мы вешаемся на редукцию запаса для заказа.
 * На время редукции включаем перехват мета-апдейтов `_stock_at_%`,
 * считаем дельты и записываем их в строки заказа.
 */

add_action('woocommerce_reduce_order_stock', function(WC_Order $order){

    // Флаг «мы внутри редукции этого заказа»
    $order_id = $order->get_id();
    $GLOBALS['pc_capture_stock_for_order'] = $order_id;

    // В этих массивах копим «старые значения» и «дельты по продукту/термину»
    $GLOBALS['pc_stock_old_values'] = [];   // ["{$post_id}|{$meta_key}"] = float старое
    $GLOBALS['pc_stock_deltas']     = [];   // [$post_id][$term_id] = суммарная дельта (>0 — списали)

    // 1) До апдейта: запомним «старое» значение
    add_filter('update_post_metadata', 'pc_cap_old_stock_before_update', 10, 5);
    // 2) После апдейта: посчитаем дельту
    add_action('updated_post_meta', 'pc_cap_delta_after_update', 10, 4);

}, 8); // чуть раньше любых чужих обработчиков, но до самой редукции

function pc_cap_old_stock_before_update($check, $object_id, $meta_key, $meta_value, $prev_value){
    if (empty($GLOBALS['pc_capture_stock_for_order'])) return $check;

    if (strncmp($meta_key, '_stock_at_', 10) === 0) {
        $k = $object_id . '|' . $meta_key;
        // «Старое» значение читаем один раз
        if (!isset($GLOBALS['pc_stock_old_values'][$k])) {
            $old = get_post_meta($object_id, $meta_key, true);
            $GLOBALS['pc_stock_old_values'][$k] = ($old === '' || $old === null) ? 0.0 : (float)$old;
        }
    }
    return $check; // не блокируем апдейт
}

function pc_cap_delta_after_update($meta_id, $object_id, $meta_key, $meta_value){
    if (empty($GLOBALS['pc_capture_stock_for_order'])) return;

    if (strncmp($meta_key, '_stock_at_', 10) !== 0) return;

    $k   = $object_id . '|' . $meta_key;
    $old = isset($GLOBALS['pc_stock_old_values'][$k]) ? (float)$GLOBALS['pc_stock_old_values'][$k] : 0.0;
    $new = ($meta_value === '' || $meta_value === null) ? 0.0 : (float)$meta_value;
    $delta = $old - $new; // сколько СНИЗИЛОСЬ

    if ($delta > 0.0001) {
        // meta_key вида _stock_at_3942 → 3942
        $term_id = (int)substr($meta_key, 10);
        if ($term_id > 0) {
            if (empty($GLOBALS['pc_stock_deltas'][$object_id])) {
                $GLOBALS['pc_stock_deltas'][$object_id] = [];
            }
            if (empty($GLOBALS['pc_stock_deltas'][$object_id][$term_id])) {
                $GLOBALS['pc_stock_deltas'][$object_id][$term_id] = 0.0;
            }
            $GLOBALS['pc_stock_deltas'][$object_id][$term_id] += $delta;
        }
    }
}

// После того как WooCommerce и плагин складов закончат редукцию — оформим результат
add_action('woocommerce_reduce_order_stock', function(WC_Order $order){

    $order_id = $order->get_id();
    if (empty($GLOBALS['pc_capture_stock_for_order']) || $GLOBALS['pc_capture_stock_for_order'] != $order_id) {
        return;
    }

    // Снимем перехватчики
    remove_filter('update_post_metadata', 'pc_cap_old_stock_before_update', 10);
    remove_action('updated_post_meta',     'pc_cap_delta_after_update',   10);

    $deltas = $GLOBALS['pc_stock_deltas'] ?? [];
    $GLOBALS['pc_capture_stock_for_order'] = null;
    $GLOBALS['pc_stock_old_values']        = [];
    $GLOBALS['pc_stock_deltas']            = [];

    if (empty($deltas)) return;

    // Словарь терминов для имён/слагов
    $terms = get_terms(['taxonomy'=>'location','hide_empty'=>false]);
    $dict  = [];
    if (!is_wp_error($terms)) {
        foreach ($terms as $t) $dict[(int)$t->term_id] = $t;
    }

    // Пройдёмся по строкам заказа и проставим фактический склад(ы)
    foreach ($order->get_items('line_item') as $item) {
        $product = $item->get_product();
        if (!$product) continue;
        $pid = $product->get_id();

        if (empty($deltas[$pid])) continue;

        // Сортируем по убыванию списанного количества
        arsort($deltas[$pid], SORT_NUMERIC);

        $parts = [];
        $first_id   = null;
        $first_slug = null;

        foreach ($deltas[$pid] as $term_id => $qty) {
            $t    = $dict[$term_id] ?? null;
            $name = $t ? $t->name : ('#'.$term_id);
            $slug = $t ? $t->slug : (string)$term_id;

            $parts[] = sprintf('%s × %d', $name, (int)$qty);
            if ($first_id === null) {
                $first_id   = (int)$term_id;
                $first_slug = $slug;
            }
        }

        if ($parts) {
            $item->update_meta_data('_stock_location_id',   $first_id);
            $item->update_meta_data('_stock_location_slug', $first_slug);
            $item->update_meta_data(__('Склад','woocommerce'), implode(', ', $parts));
            $item->save();
        }
    }
}, 99);