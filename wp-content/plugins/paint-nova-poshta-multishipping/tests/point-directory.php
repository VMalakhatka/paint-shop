<?php
/** Standalone deterministic test: no WordPress database or network. */
define('ABSPATH', __DIR__); define('MINUTE_IN_SECONDS', 60); define('PNPM_NOVA_POSHTA_API_KEY', 'test-only');
class WP_Error { public function __construct(public string $code, public string $message) {} }
function __($text, $domain = '') { return $text; }
function is_wp_error($value) { return $value instanceof WP_Error; }
function sanitize_text_field($value) { return trim(strip_tags($value)); }
function esc_url_raw($value) { return $value; }
function get_option($name, $default = []) { return $default; }
function wp_json_encode($value) { return json_encode($value); }
$cache = []; $calls = []; $responses = [];
function get_transient($key) { return $GLOBALS['cache'][$key] ?? false; }
function set_transient($key, $value, $ttl) { $GLOBALS['cache'][$key] = $value; }
function wp_remote_post($url, $args) {
    $GLOBALS['calls'][] = json_decode($args['body'], true);
    if (!$GLOBALS['responses']) { throw new RuntimeException('Unexpected network call'); }
    $value = array_shift($GLOBALS['responses']);
    return $value instanceof WP_Error ? $value : ['response' => ['code' => 200], 'body' => json_encode($value)];
}
function wp_remote_retrieve_response_code($r) { return $r['response']['code']; }
function wp_remote_retrieve_body($r) { return $r['body']; }
require __DIR__ . '/../src/Infrastructure/ApiClient.php';
require __DIR__ . '/../src/Infrastructure/WarehouseDirectory.php';
$directory = new Paint\NovaPoshta\Infrastructure\WarehouseDirectory(new Paint\NovaPoshta\Infrastructure\ApiClient());
$checks = 0;
function check($value, $label) { if (!$value) { throw new RuntimeException($label); } $GLOBALS['checks']++; }
function row($id = 1, $overrides = []) { return array_replace([
    'Ref' => sprintf('00000000-0000-0000-0000-%012d', $id), 'CityRef' => 'city',
    'Description' => 'Point ' . $id, 'CategoryOfWarehouse' => 'Branch', 'WarehouseStatus' => 'Working', 'DenyToSelect' => '0',
    'PlaceMaxWeightAllowed' => '30', 'TotalMaxWeightAllowed' => '0', 'MaxDeclaredCost' => '29000',
    'ReceivingLimitationsOnDimensions' => ['Length' => 120, 'Width' => 70, 'Height' => 70],
    'SendingLimitationsOnDimensions' => ['Length' => 60, 'Width' => 40, 'Height' => 30],
    'Schedule' => ['Monday' => '08:00-20:00'], 'Reception' => ['Monday' => '08:00-18:00'], 'Delivery' => ['Monday' => '09:00-19:00'],
], $overrides); }
function response($rows, $total = null) { return ['success' => true, 'data' => $rows, 'info' => $total === null ? [] : ['totalCount' => $total]]; }
$responses[] = response(array_map(fn($n) => row($n, ['CategoryOfWarehouse' => 'Postomat']), range(1, 50)), 51);
$page = $directory->searchPage('city', '', 1, 'branch');
check($page['items'] === [] && $page['nextPage'] === 2, 'Empty filtered page preserves continuation');
$responses[] = response([row(51)], 51);
$page = $directory->searchPage('city', '', 2, 'branch');
check(count($page['items']) === 1 && $page['nextPage'] === null, 'Branch beyond first fifty is found');
check($calls[1]['methodProperties']['Page'] === 2, 'Raw page forwarded');
check(count($calls) === 2, 'One request per page, no unbounded scan');
$point = $page['items'][0];
check($point['placeWeight'] === 30.0 && $point['totalWeight'] === null, 'Place and shipment limits not conflated; zero is unknown');
check($point['declaredValue'] === 29000.0, 'Declared value retained');
check($point['receivingDimensions'] === [120.0, 70.0, 70.0] && $point['sendingDimensions'] === [60.0, 40.0, 30.0], 'Direction and L W H order retained');
check($point['hours']['Schedule']['Monday'] === '08:00-20:00' && $point['hours']['Reception']['Monday'] === '08:00-18:00' && $point['hours']['Delivery']['Monday'] === '09:00-19:00', 'All three schedules retained');
check($point['hours']['Schedule']['Sunday'] === '', 'Missing schedule not invented');
$page = $directory->searchPage('city', '', 1, 'postomat');
check(count($page['items']) === 50 && count($calls) === 2, 'Raw cache reusable across kinds');
$responses[] = response([row(1, ['WarehouseStatus' => 'Closed']), row(2, ['DenyToSelect' => '1']), row(3, ['WarehouseStatus' => '']), row(4, ['CityRef' => 'wrong']), row(5), row(5)], 6);
$page = $directory->searchPage('city', 'statuses');
check(count($page['items']) === 4, 'Wrong city omitted and duplicates removed');
check(!$page['items'][0]['selectable'] && !$page['items'][1]['selectable'] && !$page['items'][2]['selectable'], 'Closed, denied and unknown status cannot be selected');
check($page['items'][3]['selectable'], 'Working point selectable');
check(count($directory->search('city', 'statuses')) === 1, 'Sender settings receive only selectable points');
$responses[] = response([row(1, ['Description' => '<script>bad</script>Safe', 'PlaceMaxWeightAllowed' => '-1', 'TotalMaxWeightAllowed' => 'bogus', 'MaxDeclaredCost' => [], 'ReceivingLimitationsOnDimensions' => [], 'ApiKey' => 'PRIVATE'])]);
$point = $directory->searchPage('city', 'malformed')['items'][0];
check(!str_contains($point['label'], '<script>') && !isset($point['ApiKey']), 'Public whitelist and sanitization');
check($point['placeWeight'] === null && $point['totalWeight'] === null && $point['declaredValue'] === null, 'Invalid limits remain unknown');
check($point['receivingDimensions'] === [null, null, null], 'Missing dimensions remain unknown');
$responses[] = response(array_map(fn($n) => row($n), range(1, 50)));
check($directory->searchPage('city', 'no-total')['nextPage'] === 2, 'Missing total continues full raw page');
$responses[] = response([]);
check($directory->searchPage('city', 'no-total', 2)['nextPage'] === null, 'Empty raw page stops');
$responses[] = ['success' => false, 'errors' => ['PRIVATE']];
check(is_wp_error($directory->searchPage('city', 'error')), 'API failure is not empty success');
$responses[] = response([row()]);
check(count($directory->searchPage('city', 'error')['items']) === 1, 'Failure not cached, retry works');
$responses[] = new WP_Error('timeout', 'timeout');
check(is_wp_error($directory->searchPage('city', 'timeout')), 'Timeout visible');
$before = count($calls);
check(is_wp_error($directory->searchPage('', 'x')) && is_wp_error($directory->searchPage('city', 'x', -1)), 'Invalid input rejected');
check(count($calls) === $before, 'Invalid input no network');
$responses[] = response([row()]);
check($directory->find('city', row()['Ref'])['ref'] === row()['Ref'], 'Exact Ref lookup');
check(end($calls)['methodProperties']['Ref'] === row()['Ref'], 'Exact Ref sent to API');
$responses[] = response([row(1, ['CityRef' => 'wrong'])]);
check(is_wp_error($directory->find('city', row()['Ref'])), 'Exact lookup checks city');
$responses[] = response([row(2)]);
check(is_wp_error($directory->find('city', row()['Ref'])), 'Exact lookup checks Ref');
check(is_wp_error($directory->find('city', 'invalid')), 'Malformed Ref rejected');
check($responses === [], 'All mocked responses consumed');
echo "Point directory: {$checks} checks passed; no database/network.\n";
