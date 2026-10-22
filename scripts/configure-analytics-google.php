<?php
/** GA4 scoped preview/apply using existing Rank Math authorization. No token output. */
if (!defined('WP_CLI') || !WP_CLI) { exit(1); }
$options = (array) get_option('rank_math_google_analytic_options', []);
$property = (string) ($options['property_id'] ?? '');
$stream = (string) ($options['stream_id'] ?? $options['view_id'] ?? '');
$expected = (string) getenv('LCA_EXPECTED_MEASUREMENT_ID');
if (!ctype_digit($property) || !ctype_digit($stream) || !preg_match('/^G-[A-Z0-9]{6,20}$/', $expected)) {
    throw new RuntimeException('Require selected property/stream and LCA_EXPECTED_MEASUREMENT_ID.');
}
if (get_option('lavka_ecosystem_sync_lock', false)) {
    echo wp_json_encode(['status' => 'blocked_by_ecosystem_operation', 'write_attempted' => false]) . "\n";
    exit(3);
}
if (!class_exists('RankMath\\Google\\Api')) { throw new RuntimeException('Rank Math Google API unavailable.'); }
$api = \RankMath\Google\Api::get();
$base = 'https://analyticsadmin.googleapis.com/v1alpha/properties/' . $property;
$selected = $api->http_get($base . '/dataStreams/' . $stream);
if (!$api->is_success() || ($selected['webStreamData']['measurementId'] ?? '') !== $expected) {
    throw new RuntimeException('Selected measurement ID not confirmed.');
}
$kind = getenv('LCA_CONFIG_KIND') ?: 'retention';
if (!in_array($kind, ['retention', 'enhanced'], true)) { throw new RuntimeException('Unknown config kind.'); }
$url = $kind === 'retention' ? $base . '/dataRetentionSettings'
    : $base . '/dataStreams/' . $stream . '/enhancedMeasurementSettings';
$before = $api->http_get($url);
$resource = 'properties/' . $property . ($kind === 'retention' ? '/dataRetentionSettings'
    : '/dataStreams/' . $stream . '/enhancedMeasurementSettings');
if (!$api->is_success() || ($before['name'] ?? '') !== $resource) {
    throw new RuntimeException('Unable to confirm settings resource; no write.');
}
$fields = $kind === 'retention' ? ['eventDataRetention', 'resetUserDataOnNewActivity']
    : ['pageChangesEnabled', 'siteSearchEnabled', 'formInteractionsEnabled', 'outboundClicksEnabled',
        'fileDownloadsEnabled', 'videoEngagementEnabled', 'scrollsEnabled'];
$safe = static function ($data) use ($fields): array {
    $result = [];
    foreach ($fields as $field) {
        $result[$field] = $data[$field] ?? ($field === 'eventDataRetention' ? null : false);
    }
    return $result;
};
$desired = $kind === 'retention' ? ['eventDataRetention' => 'FOURTEEN_MONTHS', 'resetUserDataOnNewActivity' => false]
    : array_fill_keys($fields, false);
$mask = $kind === 'retention' ? 'event_data_retention,reset_user_data_on_new_activity'
    : 'page_changes_enabled,site_search_enabled,form_interactions_enabled,outbound_clicks_enabled,'
        . 'file_downloads_enabled,video_engagement_enabled,scrolls_enabled';
// Google omits default-valued false in responses; preserve all unrelated settings.
$matches = static function ($data) use ($desired): bool {
    foreach ($desired as $field => $value) {
        if (($data[$field] ?? ($value === false ? false : null)) !== $value) { return false; }
    }
    return true;
};
$result = ['checked_at_utc' => gmdate('c'), 'kind' => $kind, 'before' => $safe($before), 'desired' => $desired,
    'apply_requested' => getenv('LCA_CONFIG_APPLY') === '1', 'write_attempted' => false];
if (!$result['apply_requested'] || $matches($before)) {
    $result['status'] = $matches($before) ? 'already_configured' : 'preview_only';
    echo wp_json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    return;
}
// Create a closed, non-public snapshot without OAuth credentials or customer data.
$old_umask = umask(0077);
$snapshot = tempnam(sys_get_temp_dir(), 'kreul-ga-config-');
if (!$snapshot || file_put_contents($snapshot, wp_json_encode(['property' => $property, 'stream' => $stream,
    'kind' => $kind, 'before' => $safe($before), 'checked_at_utc' => gmdate('c')], JSON_PRETTY_PRINT)) === false || !chmod($snapshot, 0600)) {
    umask($old_umask);
    throw new RuntimeException('Snapshot unavailable; no write.');
}
umask($old_umask);
$result['snapshot_path'] = $snapshot;
// The prior official client GET refreshes this token; never print or store it.
if (!is_string($api->token) || $api->token === '') { throw new RuntimeException('Authorization unavailable; no write.'); }
// Recheck immediately before PATCH. This is a guard, not ownership of the sync lock.
if (get_option('lavka_ecosystem_sync_lock', false)) {
    $result['status'] = 'blocked_by_ecosystem_operation';
    echo wp_json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    exit(3);
}
$response = wp_remote_request($url . '?updateMask=' . $mask, [
    'method' => 'PATCH', 'timeout' => 20, 'headers' => ['Authorization' => 'Bearer ' . $api->token,
        'Content-Type' => 'application/json'], 'body' => wp_json_encode($desired),
]);
$result['write_attempted'] = true;
$result['http_status'] = is_wp_error($response) ? null : wp_remote_retrieve_response_code($response);
$body = is_wp_error($response) ? [] : json_decode(wp_remote_retrieve_body($response), true);
$result['error_code'] = $body['error']['code'] ?? null;
// Always reread after a write or timeout. Do not repeat PATCH automatically.
$after = $api->http_get($url);
$read_ok = $api->is_success() && ($after['name'] ?? '') === $resource;
$result['after'] = $read_ok ? $safe($after) : null;
$result['status'] = $read_ok && $matches($after) ? 'verified' : ($read_ok ? 'not_applied' : 'outcome_unknown');
echo wp_json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
if ($result['status'] !== 'verified') { exit(2); }
