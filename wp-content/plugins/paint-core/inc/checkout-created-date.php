<?php
/** Preserve the existing checkout creation-date policy independently of the theme. */
defined('ABSPATH') || exit;

function pc_checkout_set_created_date(WC_Order $order, $data): void {
    // Keep the existing timestamp semantics during the theme migration.
    $order->set_date_created(current_time('timestamp'));
}
add_action('woocommerce_checkout_create_order', 'pc_checkout_set_created_date', 10, 2);
