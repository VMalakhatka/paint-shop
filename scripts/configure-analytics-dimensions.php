<?php
/** Finite event dimensions; preview by default, no automatic POST retry. */
if (!defined('WP_CLI') || !WP_CLI) { exit(1); }
if (get_option('lavka_ecosystem_sync_lock', false)) { throw new RuntimeException('Ecosystem operation; no write.'); }
$ga = (array)get_option('rank_math_google_analytic_options',[]);
$property = (string)($ga['property_id'] ?? ''); $stream = (string)($ga['stream_id'] ?? $ga['view_id'] ?? '');
$expected = (string)getenv('LCA_EXPECTED_MEASUREMENT_ID');
if (!ctype_digit($property) || !ctype_digit($stream) || !preg_match('/^G-[A-Z0-9]{6,20}$/',$expected)) { throw new RuntimeException('Selected stream required.'); }
$api = \RankMath\Google\Api::get(); $base = 'https://analyticsadmin.googleapis.com/v1beta/properties/' . $property;
$selected = $api->http_get($base . '/dataStreams/' . $stream);
if (!$api->is_success() || ($selected['webStreamData']['measurementId'] ?? '') !== $expected) { throw new RuntimeException('Wrong stream.'); }
$url = $base . '/customDimensions';
$read = static function() use($api,$url) {
    $data = $api->http_get($url . '?pageSize=200');
    if (!$api->is_success() || !empty($data['nextPageToken'])) { throw new RuntimeException('Unable to read complete dimension inventory.'); }
    return (array)($data['customDimensions'] ?? []);
};
$desired = ['customer_segment'=>'Customer segment','filter_set'=>'Catalog filter set','payment_state'=>'Payment evidence state',
    'search_empty'=>'Empty search result','result_count'=>'Search result count','error_type'=>'Checkout error category'];
$existing = $read(); $missing = [];
foreach ($desired as $key=>$label) {
    $match = array_values(array_filter($existing, static fn($d)=>($d['parameterName'] ?? '') === $key && ($d['scope'] ?? '') === 'EVENT'));
    if (!$match) { $missing[$key] = $label; }
}
$result = ['status'=>'preview','missing'=>array_keys($missing),'write_requested'=>getenv('LCA_DIMENSIONS_APPLY') === '1','created'=>[]];
if ($result['write_requested']) {
    foreach ($missing as $key=>$label) {
        if (get_option('lavka_ecosystem_sync_lock',false)) { throw new RuntimeException('Operation started; stopped.'); }
        $response = $api->http_post($url,['parameterName'=>$key,'displayName'=>$label,'scope'=>'EVENT','disallowAdsPersonalization'=>true]);
        // Reread even on timeout/error; unknown outcome is never repeated automatically.
        $verified = array_filter($read(),static fn($d)=>($d['parameterName'] ?? '') === $key && ($d['scope'] ?? '') === 'EVENT');
        if (!$verified) { throw new RuntimeException('Dimension not verified; stopped without retry.'); }
        $result['created'][] = $key;
    }
    $result['status'] = 'verified';
}
echo wp_json_encode($result,JSON_PRETTY_PRINT) . "\n";
