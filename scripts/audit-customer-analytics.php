<?php
/** Read-only WP-CLI inventory. Does not print account tokens or customer details. */
if (!defined('WP_CLI') || !WP_CLI) { exit(1); }
$ga = (array) get_option('rank_math_google_analytic_options', []);
$last = wc_get_orders(['limit' => 1, 'orderby' => 'date', 'order' => 'DESC']);
$result = [
    'environment' => wp_get_environment_type(),
    'latest_order_date' => $last ? $last[0]->get_date_created()->date('Y-m-d') : null,
    'ga4' => [
        'rank_math_install_code' => !empty($ga['install_code']),
        'measurement_id' => preg_match('/^G-[A-Z0-9]+$/', $ga['measurement_id'] ?? '') ? $ga['measurement_id'] : null,
        'property_configured' => !empty($ga['property_id']),
        'google_connection_present' => !empty(get_option('rank_math_google_analytic_profile')),
    ],
    'woo_order_attribution' => get_option('woocommerce_feature_order_attribution_enabled', 'no'),
    'status_counts' => [],
    'tracking_plugins' => array_values(array_filter((array) get_option('active_plugins'), static function ($name) {
        return preg_match('/analytics|rank-math|site-kit|gtm|clarity|cookie|consent|google|pixel|tiktok|pinterest/', $name);
    })),
];
foreach (array_keys(wc_get_order_statuses()) as $status) {
    $result['status_counts'][$status] = wc_orders_count(substr($status, 3));
}
echo wp_json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
