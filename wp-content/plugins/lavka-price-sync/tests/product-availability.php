<?php
if (PHP_SAPI !== 'cli') exit;
define('ABSPATH', __DIR__);
function add_action(...$args) {}
function __($s, $d = '') { return $s; }
function sanitize_key($s) { return strtolower((string)$s); }
function sanitize_text_field($s) { return (string)$s; }
function absint($n) { return abs((int)$n); }
function rest_sanitize_boolean($v) { return (bool)$v; }
function wp_json_encode($v, $flags = 0) { return json_encode($v, $flags); }
function wp_date($format) { return date($format); }
function current_time($type) { return time(); }
function wp_send_json_error($data, $status = 400) { throw new InvalidArgumentException($data['message']); }
function lavka_get_global_warehouse_groups() { return [['code' => 'kyiv', 'name' => 'Kyiv', 'warehouseIds' => [1, 7]]]; }
function lavka_get_global_warehouse_groups_revision() { return str_repeat('a', 64); }
class WP_Error {
    function __construct(public $code, public $message, public $data = []) {}
    function get_error_message() { return $this->message; }
}
function is_wp_error($v) { return $v instanceof WP_Error; }
function lps_get_options() { return ['java_base_url' => 'fixture']; }
function lps_java_post($path, $query, $options) {
    if ($path === LPS_PRODUCT_ANALYTICS_CAPABILITIES_PATH) return ['ok' => true];
    $GLOBALS['queries'][] = $query; return array_shift($GLOBALS['responses']);
}
function wp_remote_retrieve_response_code($r) { return $r['http'] ?? 200; }
function wp_remote_retrieve_body($r) { return json_encode($r); }
require __DIR__ . '/../inc/product-availability.php';
require __DIR__ . '/../inc/purchase-planning-model.php';
require __DIR__ . '/../inc/analytics-scenarios.php';
require __DIR__ . '/../inc/product-analytics.php';
require __DIR__ . '/../inc/product-analytics-export.php';
function check($ok, $message) { if (!$ok) throw new RuntimeException($message); }
function rejects($fn) { try { $fn(); } catch (InvalidArgumentException $e) { return; } throw new RuntimeException('Invalid filter accepted'); }
$availability = ['enabled' => true, 'groupCode' => 'kyiv', 'filter' => ['stockoutPercentFrom' => 10, 'availabilityStatus' => ['MEASURED']], 'warehouseGroups' => [['code' => 'fake']]];
$profile = lps_analytics_scenario_default_profile_v4();
$profile['context']['warehouseIds'] = [1, 7];
$profile['calculation']['availability'] = $availability;
$profile['sort'] = [['field' => 'stockoutPercent', 'direction' => 'DESC']];
$saved = lps_analytics_scenario_sanitize_profile_v4($profile);
check($saved['calculation']['availability']['filter']['stockoutPercentFrom'] === 10.0, 'Saved filter lost');
check(!isset($saved['calculation']['availability']['warehouseGroups']), 'Scenario copied group membership');
check($saved === lps_analytics_scenario_sanitize_profile_v4($saved), 'Scenario roundtrip changed');
$resolved = lps_product_analytics_v4_sanitize_availability($availability, [1, 7]);
check($resolved['warehouseGroups'][0]['warehouseIds'] === [1, 7], 'Must use current server group');
check($resolved['groupCode'] === 'kyiv', 'Group context lost');
rejects(fn() => lps_product_analytics_v4_sanitize_availability($availability, [1]));
rejects(fn() => lps_availability_profile(['enabled' => false, 'filter' => ['stockoutPercentFrom' => 0]]));
rejects(fn() => lps_availability_profile(['enabled' => true, 'filter' => ['stockoutPercentFrom' => 60, 'stockoutPercentTo' => 10]]));
rejects(fn() => lps_availability_profile(['enabled' => true, 'groupCode' => 'kyiv', 'warehouseId' => 1]));
$unknown = lps_product_analytics_export_availability(['status' => 'DATA_INCOMPLETE', 'stockoutDays' => 0, 'stockoutPercent' => 0]);
check($unknown === ['DATA_INCOMPLETE', '', ''], 'Unknown values must not be zero');
$measured = ['status' => 'MEASURED', 'basis' => 'PHYSICAL_END_OF_DAY', 'stockoutDays' => 0, 'stockoutPercent' => 0, 'warnings' => ['CURRENT_POLICY_APPLIED_TO_PERIOD']];
$query = ['warehouseIds' => [1, 7], 'period' => ['from' => '2026-08-01', 'to' => '2026-08-30'], 'calculation' => ['availability' => $resolved]];
$first = ['ok' => true, 'context' => ['warehouses' => [['id' => 1, 'generationId' => 11], ['id' => 7, 'generationId' => 12]], 'warehouseGroupsRevision' => str_repeat('a', 64)],
    'rows' => [['sku' => 'ONE', 'availability' => $measured]], 'totals' => ['productCount' => 2], 'nextCursor' => 'two'];
$last = $first; $last['rows'][0]['sku'] = 'TWO'; $last['nextCursor'] = null;
$responses = [$first, $last];
$result = lps_product_analytics_export_fetch_rows($query, 'products');
check(!is_wp_error($result) && count($result['rows']) === 2, 'Complete export failed');
check($result['rows'][0]['stockoutPercent'] === 0.0, 'Measured zero lost');
check(str_contains($result['metadata']['generationId'], 'generationId'), 'Generation IDs missing');
check(str_contains($result['rows'][0]['availabilityDetails'], 'CURRENT_POLICY'), 'Warnings missing');
check($queries[0]['calculation'] === $queries[1]['calculation'], 'Export filters drift');
$last['context']['warehouses'][0]['generationId'] = 99;
$responses = [$first, $last];
check(is_wp_error(lps_product_analytics_export_fetch_rows($query, 'products')), 'Generation change accepted');
$responses = [$first, ['http' => 409, 'ok' => false, 'code' => 'ANALYTICS_CURSOR_EXPIRED']];
check(is_wp_error(lps_product_analytics_export_fetch_rows($query, 'products')), 'Expired cursor accepted');
echo "PASS: v5 scenario roundtrip, live groups, filter validation, unknown values, export completeness and generation drift\n";
