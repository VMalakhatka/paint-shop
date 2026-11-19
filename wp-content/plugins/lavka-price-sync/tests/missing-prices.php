<?php
// Offline regression for the shared manual and scheduled sync path.
if (PHP_SAPI !== 'cli') exit;
define('ABSPATH', __DIR__);
define('LPS_OPT_MAPPING', 'mapping');
function add_action(...$args) {}
function get_option($key, $default) { return ['partner' => 'partner']; }
function sanitize_key($key) { return $key; }
function lps_get_options() { return ['path_prices' => '/prices']; }
function lps_java_post($path, $payload) { return $GLOBALS['reply']; }
function is_wp_error($reply) { return false; }
function wp_remote_retrieve_response_code($reply) { return 200; }
function wp_remote_retrieve_header($reply, $name) { return 'application/json'; }
function wp_remote_retrieve_body($reply) { return json_encode(['items' => [$reply]]); }
function lps_find_product_id_by_sku($sku) { return $sku === 'CL-96018C' ? 42 : 0; }
function lps_role_meta_key($role) { return '_wpc_price_role_' . $role; }
function get_transient($key) { return false; }
function set_transient(...$args) {}
function delete_transient($key) {}
function update_post_meta($id, $key, $value) { $GLOBALS['meta'][$key] = $value; }

require dirname(__DIR__) . '/inc/sync.php';
function check($condition, $message) {
    if (!$condition) throw new RuntimeException($message);
}

$before = ['_price' => 2862.0, '_regular_price' => 2862.0, '_wpc_price_role_partner' => 1949.31];
foreach ([['price' => null, 'prices' => ['partner' => null]], [],
          ['price' => '', 'prices' => ['partner' => '']],
          ['price' => false, 'prices' => ['partner' => 'invalid']]] as $fields) {
    $meta = $before;
    $reply = ['sku' => 'CL-96018C'] + $fields;
    $items = lps_java_fetch_prices_multi(['CL-96018C'])['items'];
    $report = lps_apply_prices_multi($items);
    check($report['updated_retail'] === 0 && $report['updated_roles'] === 0, 'Missing price counted');
    check($meta === $before, 'Missing price overwrote Woo');
}
$reply = ['sku' => 'CL-96018C', 'price' => 0, 'prices' => ['partner' => '0', 'opt' => 2025.16, 'school' => null]];
$items = lps_java_fetch_prices_multi(['CL-96018C'])['items'];
$dry = lps_apply_prices_multi($items, true);
check($meta === $before, 'Dry run changed prices');
$report = lps_apply_prices_multi($items);
check($dry === $report && $report['updated_retail'] === 1 && $report['updated_roles'] === 2, 'Wrong totals');
check($meta === ['_price' => 0.0, '_regular_price' => 0.0, '_wpc_price_role_partner' => 0.0,
                 '_wpc_price_role_opt' => 2025.16], 'Explicit price not applied');
echo "OK: missing prices preserved, numeric prices including zero applied.\n";
