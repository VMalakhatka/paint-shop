<?php
namespace PaintCore\Orders;

defined('ABSPATH') || exit;

/**
 * Фейлсейф редукции склада.
 * Если заказ ушёл в processing/completed, а `_order_stock_reduced` ещё нет — дожмём редукцию.
 */

// Смена статуса → дожать редукцию
add_action('woocommerce_order_status_changed', function( $order_id, $from, $to ){
    $order = wc_get_order( $order_id );
    if ( ! $order ) return;

    // уже уменьшали — выходим
    if ( $order->get_meta('_order_stock_reduced') ) return;

    if ( in_array( $to, ['processing','completed'], true ) ) {
        wc_maybe_reduce_stock_levels( $order_id );
    }
}, 20, 3);

// Оплата завершена → дожать редукцию
add_action('woocommerce_payment_complete', function( $order_id ){
    $order = wc_get_order( $order_id );
    if ( ! $order ) return;
    if ( $order->get_meta('_order_stock_reduced') ) return;

    wc_maybe_reduce_stock_levels( $order_id );
}, 20);

// Кнопка в «Order actions» → принудительно уменьшить склад
add_filter('woocommerce_order_actions', function( $actions ){
    $actions['pc_force_reduce'] = __('Force reduce stock (failsafe)', 'paint-core');
    return $actions;
});

add_action('woocommerce_order_action_pc_force_reduce', function( $order ){
    if ( ! $order->get_meta('_order_stock_reduced') ) {
        wc_maybe_reduce_stock_levels( $order->get_id() );
    }
});