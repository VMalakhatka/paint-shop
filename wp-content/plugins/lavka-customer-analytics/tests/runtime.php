<?php
/** Local-only read-only WP/Woo smoke; filters live for this process, no option/order saves. */
if (!defined('WP_CLI') || !WP_CLI || wp_get_environment_type() !== 'local') { exit(1); }
$checks = 0;
$assert = static function ($ok, $message) use (&$checks) { if (!$ok) { throw new RuntimeException($message); } $checks++; };
$assert(class_exists('Lavka_Customer_Analytics'), 'Plugin activated locally');
$assert(!Lavka_Customer_Analytics::enabled(), 'Real local settings remain disabled');
$before = get_option('rank_math_google_analytic_options', []);
add_filter('pre_option_lca_settings', static fn() => ['enabled' => true, 'measurement_id' => 'G-TEST1234']);
$assert(Lavka_Customer_Analytics::enabled(), 'Ephemeral settings enabled');
$filtered = get_option('rank_math_google_analytic_options', []);
$assert(empty($filtered['install_code']), 'Only runtime Rank Math snippet disabled');
$assert(!Lavka_Customer_Analytics::consent(), 'No consent by default');
$assert(!Lavka_Customer_Analytics::collection_request(), 'WP-CLI cannot collect storefront events');
$assert(has_action('woocommerce_add_to_cart', [Lavka_Customer_Analytics::class, 'add_to_cart']) === 999, 'Cart hook');
$assert(has_action('woocommerce_payment_complete', [Lavka_Customer_Analytics::class, 'payment_complete']) === 999, 'Payment evidence hook');
$assert(has_action('woocommerce_before_order_object_save', [Lavka_Customer_Analytics::class, 'inherit']) === 999, 'Split inheritance hook');
$_COOKIE['lca_consent'] = 'granted';
$_POST['lca_attribution'] = wp_slash('{"source":"google","medium":"organic","device":"mobile","campaign":"example@example.com"}');
$order = new WC_Order(); // in-memory, ID=0; never save.
Lavka_Customer_Analytics::snapshot($order);
$assert($order->get_id() === 0 && $order->get_meta('_lca_source') === 'google', 'Safe in-memory snapshot');
$assert($order->get_meta('_lca_campaign') === '', 'Reject email in attribution');
$assert($order->get_meta('_lca_device') === 'mobile', 'Device snapshot');
$assert($order->get_meta('_lca_segment') === 'retail', 'Historical segment snapshot');
switch_to_locale('uk');
$assert(__('Allow analytics', 'lavka-customer-analytics') === 'Дозволити аналітику', 'UK translation');
restore_previous_locale();
switch_to_locale('ru_RU');
$assert(__('Refuse analytics', 'lavka-customer-analytics') === 'Отказаться от аналитики', 'RU translation');
restore_previous_locale();
echo "PASS: $checks WordPress/Woo runtime assertions (no persistent writes)\n";
