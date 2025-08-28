<?php
namespace PaintCore\Stock;
use function PaintCore\pc_log; 

defined('ABSPATH') || exit;

/**
 * PaintCore Stock Allocator
 *
 * Что делает:
 *  1) На этапе checkout/status строит ПЛАН списания по складам для каждой строки заказа:
 *     _pc_stock_breakdown = [ term_id => qty, ... ]
 *     + видимая мета "Склад: Київ × N, Одеса × M"
 *     + _stock_location_id / _stock_location_slug (первый реально использованный склад)
 *  2) На этапе checkout/status выполняет реальное списание из мет _stock_at_{term_id},
 *     пересчитывает _stock и _stock_status.
 *  3) Антидубль: пометка заказа _pc_stock_reduced = yes (ставится только если было реальное списание).
 *
 * Требования:
 *  - Таксономия location
 *  - Остатки по складам в мета: _stock_at_{TERM_ID}
 *  - Primary-склад в _yoast_wpseo_primary_location
 */

// Флаг «редукция выполнена»
const PC_STOCK_REDUCED_META = '_pc_stock_reduced';

/* ===================== ХЕЛПЕРЫ ===================== */

/** Primary location по Yoast‑мета (учёт вариаций через родителя) */
function pc_primary_location_id(int $product_id) : int {
    $id = (int) get_post_meta($product_id, '_yoast_wpseo_primary_location', true);
    if (!$id) {
        $parent_id = (int) wp_get_post_parent_id($product_id);
        if ($parent_id) {
            $id = (int) get_post_meta($parent_id, '_yoast_wpseo_primary_location', true);
        }
    }
    return $id ?: 0;
}

/** Прочитать остаток в мета `_stock_at_{TERM_ID}` (учтём вариации через родителя) */
function pc_read_stock_for_term(int $product_id, int $term_id) : int {
    $key = '_stock_at_' . (int)$term_id;
    $v = get_post_meta($product_id, $key, true);
    if ($v === '' || $v === null) {
        $parent_id = (int) wp_get_post_parent_id($product_id);
        if ($parent_id) {
            $v = get_post_meta($parent_id, $key, true);
        }
    }
    return (int) ( ($v === '' || $v === null) ? 0 : (float)$v );
}

/** Изменить остаток на складе (delta может быть отрицательным) */
function pc_update_stock_for_term(int $product_id, int $term_id, float $delta) : void {
    $key = '_stock_at_' . (int)$term_id;
    $cur = (float) get_post_meta($product_id, $key, true);
    $new = max(0.0, $cur + (float)$delta);
    update_post_meta($product_id, $key, wc_format_decimal($new, 3));
}

/** Пересчитать `_stock` как сумму всех `_stock_at_{TERM_ID}` */
function pc_recalc_total_stock(int $product_id) : void {
    $terms = get_terms(['taxonomy'=>'location','hide_empty'=>false]);
    if (is_wp_error($terms)) return;

    $sum = 0.0;
    foreach ($terms as $t) {
        $sum += (float) get_post_meta($product_id, '_stock_at_'.$t->term_id, true);
    }
    update_post_meta($product_id, '_stock', wc_format_decimal($sum, 3));
    update_post_meta($product_id, '_stock_status', ($sum > 0 ? 'instock' : 'outofstock'));
    if ( function_exists('wc_delete_product_transients') ) {
        wc_delete_product_transients($product_id);
    }
}

/* ============ ПОСТРОЕНИЕ ПЛАНА СПИСАНИЯ ПО СКЛАДАМ ============ */
/**
 * Пишет _pc_stock_breakdown в строки заказа на основе текущих остатков _stock_at_{TERM_ID}.
 * Также обновляет видимые меты: "Склад: Київ × N, Одеса × M", _stock_location_id/_slug.
 */
function pc_build_allocation_plan( $order_or_id ) : void {
    $order = ($order_or_id instanceof \WC_Order) ? $order_or_id : wc_get_order($order_or_id);
    if ( ! $order ) { pc_log('[PC PLAN] no order'); return; }

    foreach ( $order->get_items('line_item') as $item_id => $item ) {
        $product = $item->get_product();
        if ( ! $product ) { continue; }
        $pid  = (int) $product->get_id();
        $need = (int) $item->get_quantity();
        if ( $need <= 0 ) continue;

        /* ---------- 1) ПРОБУЕМ ЕДИНЫЙ ПЛАН (учитывает auto/manual/single) ---------- */
        $plan = [];
        if ( function_exists('\\slu_get_allocation_plan') ) {
            // третий аргумент — просто ярлык стратегии для хука; можешь оставить 'checkout'
            $plan = \slu_get_allocation_plan( $product, $need, 'checkout' );
        }

        /* ---------- 2) НОРМАЛИЗУЕМ/ФОЛБЭК, если общий план ничего не вернул ---------- */
        $norm = [];
        if ( is_array($plan) ) {
            foreach ( $plan as $k => $v ) {
                $tid = (int) $k;
                $q   = (int) $v;
                if ( $tid > 0 && $q > 0 ) $norm[$tid] = $q;
            }
        }

        if ( empty($norm) ) {
            // ЛЕГАСИ: снимок остатков и simple priority (primary → по имени)
            $terms = get_terms(['taxonomy'=>'location','hide_empty'=>false]);
            if ( is_wp_error($terms) || empty($terms) ) { pc_log('[PC PLAN] no terms'); continue; }

            $primary = pc_primary_location_id($pid);

            $rows = [];
            foreach ($terms as $t) {
                $rows[] = [
                    'term_id'    => (int)$t->term_id,
                    'name'       => $t->name,
                    'stock'      => (int) pc_read_stock_for_term($pid, (int)$t->term_id),
                    'is_primary' => ($primary && (int)$t->term_id === $primary) ? 1 : 0,
                ];
            }
            usort($rows, function($a,$b){
                if ($a['is_primary'] !== $b['is_primary']) return $a['is_primary'] ? -1 : 1;
                return strnatcasecmp($a['name'], $b['name']);
            });

            $rem = $need;
            foreach ($rows as $r) {
                if ($rem <= 0) break;
                $avail = (int)$r['stock'];
                if ($avail <= 0) continue;
                $take = min($avail, $rem);
                $norm[(int)$r['term_id']] = ($norm[(int)$r['term_id']] ?? 0) + $take;
                $rem -= $take;
            }
        }

        if ( empty($norm) ) {
            pc_log('[PC PLAN] order '.$order->get_id().' item '.$item_id.' no allocation (stock=0?)');
            continue;
        }

        /* ---------- 3) СОХРАНЯЕМ ПЛАН + ЧЕЛОВЕЧЕСКУЮ СТРОКУ ---------- */
        // соберём термы, чтобы красиво подписать
        $dict = [];
        $terms = get_terms(['taxonomy'=>'location','hide_empty'=>false]);
        if ( ! is_wp_error($terms) ) {
            foreach ($terms as $t) { $dict[(int)$t->term_id] = $t; }
        }

        // порядок «крупные вперёд», чтобы «первый склад» был реально самым весомым
        arsort($norm, SORT_NUMERIC);

        $parts = [];
        $first_id  = null;
        $first_slug= null;

        foreach ($norm as $tid => $qty) {
            $t    = $dict[(int)$tid] ?? null;
            $name = $t ? $t->name : '#'.$tid;
            $slug = $t ? $t->slug : (string)$tid;
            $parts[] = sprintf('%s × %d', $name, (int)$qty);
            if ($first_id === null) { $first_id = (int)$tid; $first_slug = $slug; }
        }

        $item->update_meta_data('_pc_stock_breakdown', $norm);
        $item->update_meta_data('_stock_location_id',   $first_id);
        $item->update_meta_data('_stock_location_slug', $first_slug);
        $item->update_meta_data( __('Склад','woocommerce'), implode(', ', $parts) );
        $item->save();

        pc_log('[PC PLAN] order '.$order->get_id().' item '.$item_id.' plan='.print_r($norm,true));
    }
}

/* Хуки построения плана:
 *  - сразу после чекоута
 *  - дополнительно при переходе в Processing/Completed (на случай поздних платёжных шлюзов)
 */
add_action('woocommerce_checkout_order_processed', __NAMESPACE__.'\\pc_build_allocation_plan', 40);
add_action('woocommerce_order_status_processing',  __NAMESPACE__.'\\pc_build_allocation_plan', 30);
add_action('woocommerce_order_status_completed',   __NAMESPACE__.'\\pc_build_allocation_plan', 30);
// план должен быть готов ДО отправки письма New Order
add_action('woocommerce_new_order', __NAMESPACE__.'\\pc_build_allocation_plan', 1);

// Кнопка «Build stock allocation plan (PaintCore)» в админке заказа
add_action('woocommerce_order_actions', function($actions){
    $actions['pc_build_alloc_plan'] = 'Build stock allocation plan (PaintCore)';
    return $actions;
});
add_action('woocommerce_order_action_pc_build_alloc_plan', function($order){
    \PaintCore\Stock\pc_build_allocation_plan( $order );
});


/* ============ РЕДУКЦИЯ ОСТАТКОВ ПО ПЛАНУ ============ */
/**
 * Читает _pc_stock_breakdown и уменьшает _stock_at_{TERM_ID} по каждому товару.
 * Пересчитывает _stock/_stock_status. Ставит флажок _pc_stock_reduced только если были изменения.
 * Если план пуст — строит его на лету и перечитывает.
 */
function pc_reduce_stock_from_breakdown( $order_or_id ) : void {
    $order = ($order_or_id instanceof \WC_Order) ? $order_or_id : wc_get_order($order_or_id);
    if ( ! $order ) {
        pc_log('[PC REDUCE] no WC_Order, abort (arg='. (is_object($order_or_id)? get_class($order_or_id) : (string)$order_or_id) .')');
        return;
    }
    $oid = (int) $order->get_id();
    pc_log("[PC REDUCE] start for order {$oid}");

    // анти‑повтор
    if ( $order->get_meta(PC_STOCK_REDUCED_META) ) {
        pc_log("[PC REDUCE] already reduced, skip (order {$oid})");
        return;
    }

    $changed_any = false;
    $changed_products = [];

    foreach ( $order->get_items('line_item') as $item_id => $item ) {
        $product = $item->get_product();
        if ( ! $product ) { pc_log("[PC REDUCE] item {$item_id}: no product, skip"); continue; }
        $pid  = (int) $product->get_id();

        // План может быть массивом или JSON; если его нет — построим на лету
        $plan = $item->get_meta('_pc_stock_breakdown', true);
        if ( !is_array($plan) || empty($plan) ) {
            pc_log("[PC REDUCE] item {$item_id}: plan empty → build now");
            pc_build_allocation_plan($order);
            $plan = $item->get_meta('_pc_stock_breakdown', true);
        }
        if ( !is_array($plan) ) {
            $plan = json_decode((string)$plan, true);
        }
        if ( !is_array($plan) || empty($plan) ) {
            pc_log("[PC REDUCE] item {$item_id}: plan still empty, skip");
            continue;
        }

        // Нормализуем
        $norm = [];
        foreach ( $plan as $k => $v ) {
            $tid = (int)$k;
            $q   = (float)$v;
            if ( $tid > 0 && $q > 0 ) $norm[$tid] = $q;
        }
        if ( empty($norm) ) {
            pc_log("[PC REDUCE] item {$item_id}: plan normalized empty, skip");
            continue;
        }

        // Списание по плану
        foreach ( $norm as $term_id => $qty ){
            $mkey = '_stock_at_' . (int)$term_id;

            $cur  = get_post_meta($pid, $mkey, true);
            $curf = ($cur === '' || $cur === null) ? 0.0 : (float)$cur;
            $newf = max(0.0, $curf - (float)$qty);

            pc_log("[PC REDUCE] order {$oid} item {$item_id} pid {$pid} {$mkey}: {$curf} -> {$newf} (reduce {$qty})");
            update_post_meta($pid, $mkey, wc_format_decimal($newf, 3));

            $changed_any = true;
            $changed_products[$pid] = true;
        }
    }

    // Пересчитать _stock/_stock_status и почистить кэш — только для затронутых товаров
    if ( $changed_any && !empty($changed_products) ) {
        global $wpdb;
        foreach ( array_keys($changed_products) as $pid ) {
            $rows = $wpdb->get_col( $wpdb->prepare(
                "SELECT meta_value FROM {$wpdb->postmeta}
                 WHERE post_id=%d AND meta_key LIKE %s",
                $pid, $wpdb->esc_like('_stock_at_') . '%'
            ) );
            $sum = 0.0;
            foreach ( (array)$rows as $v ) $sum += (float)$v;

            update_post_meta($pid, '_stock', wc_format_decimal($sum, 3));
            update_post_meta($pid, '_stock_status', ($sum > 0 ? 'instock' : 'outofstock'));
            if ( function_exists('wc_delete_product_transients') ) wc_delete_product_transients($pid);

            pc_log("[PC REDUCE] pid {$pid}: total _stock = {$sum}");
        }

        // Пометка — редукция выполнена
        $order->update_meta_data(PC_STOCK_REDUCED_META, 'yes');
        $order->save();
        pc_log("[PC REDUCE] done for order {$oid}");
    } else {
        pc_log("[PC REDUCE] nothing changed, not marking reduced (order {$oid})");
    }
}

/* Хуки списания по плану:
 *  - после checkout
 *  - при переводе заказа в Processing/Completed
 * (приоритет 60 — чтобы быть ПОСЛЕ построения плана на 30/40)
 */
add_action('woocommerce_checkout_order_processed', __NAMESPACE__.'\\pc_reduce_stock_from_breakdown', 60);
add_action('woocommerce_order_status_processing',  __NAMESPACE__.'\\pc_reduce_stock_from_breakdown', 60);
add_action('woocommerce_order_status_completed',   __NAMESPACE__.'\\pc_reduce_stock_from_breakdown', 60);

// Кнопка «Reduce stock from plan (PaintCore)» в админке заказа
add_action('woocommerce_order_actions', function($actions){
    $actions['pc_reduce_from_plan'] = 'Reduce stock from plan (PaintCore)';
    return $actions;
});
add_action('woocommerce_order_action_pc_reduce_from_plan', function($order){
    \PaintCore\Stock\pc_reduce_stock_from_breakdown( $order );
});