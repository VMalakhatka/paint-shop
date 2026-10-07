<?php
// Standalone hook scope test; no WordPress/database required.
define('ABSPATH', __DIR__);
$hooks = [];
$enqueued = [];
$cart = false;
function add_action($name, $callback, $priority = 10) { $GLOBALS['hooks'][$name] = $callback; }
function add_filter($name, $callback, $priority = 10) { $GLOBALS['hooks'][$name] = $callback; }
function is_cart() { return $GLOBALS['cart']; }
function plugins_url($path, $file) { return $path; }
function wp_enqueue_style(...$args) { $GLOBALS['enqueued'][] = $args; }
function expect($value, $message) { if (!$value) throw new RuntimeException($message); }
require dirname(__DIR__) . '/inc/cart-layout.php';
$hooks['wp_enqueue_scripts']();
expect(!$enqueued, 'No cart CSS outside the cart');
expect($hooks['generate_sidebar_layout']('right-sidebar') === 'right-sidebar', 'Other sidebars unchanged');
$cart = true;
$hooks['wp_enqueue_scripts']();
expect(count($enqueued) === 1 && $enqueued[0][0] === 'psu-cart-layout', 'Cart stylesheet loaded');
expect(ctype_digit($enqueued[0][3]), 'Stylesheet cache version follows file');
expect($hooks['generate_sidebar_layout']('right-sidebar') === 'no-sidebar', 'Cart has no sidebar');
echo "Cart layout hooks: OK\n";
