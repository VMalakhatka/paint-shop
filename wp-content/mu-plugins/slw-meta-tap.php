<?php
/**
 * Plugin Name: SLW Meta Tap (diagnostic)
 * Description: Логирует мета строки заказа и апдейты _stock_at_% для диагностики Stock Locations.
 * Version: 0.1
 */

if (!defined('ABSPATH')) exit;

/** Убедимся, что лог включен */
add_action('init', function(){
    if ( !defined('WP_DEBUG_LOG') || !WP_DEBUG_LOG ) {
        error_log('[SLW TAP] WP_DEBUG_LOG is OFF. Turn it on in wp-config.php');
    }
});

/**
 * 1) Пишем ВСЕ апдейты пост‑мета ключей вида _stock_at_%
 *    (станет видно, что именно и когда меняется при редукции)
 */
add_action('updated_post_meta', function($meta_id, $object_id, $meta_key, $meta_value){
    if (strpos($meta_key, '_stock_at_') === 0) {
        error_log(sprintf('[SLW TAP] updated_post_meta %s (post %d) => %s',
            $meta_key, (int)$object_id, maybe_serialize($meta_value)
        ));
    }
}, 10, 4);

/**
 * 2) До создания строки заказа — какие меты кладёт кто‑то на этапе checkout
 */
add_action('woocommerce_checkout_create_order_line_item', function($item, $cart_item_key, $values, $order){
    error_log('=== [SLW TAP] BEFORE CREATE LINE ITEM #'.$item->get_id().' ('.$item->get_name().') ===');
    foreach ($item->get_meta_data() as $m) {
        error_log('  '.$m->key.' = '.maybe_serialize($m->value));
    }
}, 99, 4);

/**
 * 3) После создания строки — вдруг плагин пишет меты сразу на этом шаге
 */
add_action('woocommerce_new_order_item', function($item_id, $item, $order_id){
    if ($item instanceof WC_Order_Item_Product) {
        error_log('=== [SLW TAP] AFTER CREATE LINE ITEM #'.$item_id.' ('.$item->get_name().') ===');
        foreach ($item->get_meta_data() as $m) {
            error_log('  '.$m->key.' = '.maybe_serialize($m->value));
        }
    }
}, 99, 3);

/**
 * 4) После уменьшения остатков (это именно то событие, на котором
 *    SLW обычно распределяет количество по локациям)
 */
add_action('woocommerce_reduce_order_stock', function( WC_Order $order ){
    error_log('=== [SLW TAP] AFTER REDUCE STOCK: Order #'.$order->get_id().' ===');
    foreach ($order->get_items('line_item') as $item_id => $item) {
        error_log('  [ITEM '.$item_id.'] '.$item->get_name());
        foreach ($item->get_meta_data() as $m) {
            error_log('    '.$m->key.' = '.maybe_serialize($m->value));
        }
    }
}, 999);