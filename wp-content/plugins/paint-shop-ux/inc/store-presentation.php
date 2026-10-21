<?php
/** Store presentation settings that must survive a theme change. */
defined('ABSPATH') || exit;

function psu_store_breadcrumb_defaults(array $defaults): array {
    $defaults['delimiter'] = ' <span class="breadcrumb-delimiter">→</span> ';
    return $defaults;
}
add_filter('woocommerce_breadcrumb_defaults', 'psu_store_breadcrumb_defaults');
