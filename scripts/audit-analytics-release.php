<?php
/** Read-only analytics release preflight. No tokens, customer rows or external writes. */
if (!defined('WP_CLI') || !WP_CLI) { exit(1); }

$ga = (array) get_option('rank_math_google_analytic_options', []);
$lca = (array) get_option('lca_settings', []);
$active = (array) get_option('active_plugins', []);
$privacy_id = (int) get_option('wp_page_for_privacy_policy', 0);
$privacy = $privacy_id ? get_post($privacy_id) : null;
$result = [
    'checked_at_utc' => gmdate('c'),
    'environment' => wp_get_environment_type(),
    'module' => [
        'files_present' => is_file(WP_PLUGIN_DIR . '/lavka-customer-analytics/lavka-customer-analytics.php'),
        'active' => in_array('lavka-customer-analytics/lavka-customer-analytics.php', $active, true),
        'enabled_option' => in_array($lca['enabled'] ?? false, [true, 1, '1'], true),
        'file_sha256' => [],
    ],
    'privacy_page' => [
        'assigned' => $privacy_id > 0,
        'published' => $privacy && $privacy->post_status === 'publish',
        'content_bytes' => $privacy ? strlen((string) $privacy->post_content) : 0,
        'url' => $privacy && $privacy->post_status === 'publish' ? get_permalink($privacy) : null,
        'owner_review_required' => true,
    ],
    'tracking_plugins' => array_values(array_filter($active, static function ($name) {
        return preg_match('/analytics|rank-math|site-kit|gtm|clarity|cookie|consent|google|pixel|tiktok|pinterest/i', $name);
    })),
    'public_html' => [],
];
foreach (['lavka-customer-analytics.php', 'assets/storefront.js', 'assets/consent.css'] as $relative) {
    $file = WP_PLUGIN_DIR . '/lavka-customer-analytics/' . $relative;
    if (is_file($file)) { $result['module']['file_sha256'][$relative] = hash_file('sha256', $file); }
}

// Public unauthenticated HTML sample, not a browser/network consent test.
$pages = ['home' => home_url('/')];
foreach (['shop', 'cart', 'checkout'] as $page) {
    $id = wc_get_page_id($page);
    if ($id > 0) { $pages[$page] = get_permalink($id); }
}
$products = wc_get_products(['status' => 'publish', 'limit' => 1, 'return' => 'ids']);
if ($products) { $pages['product'] = get_permalink($products[0]); }
foreach ($pages as $kind => $url) {
    $response = wp_remote_get($url, ['timeout' => 20, 'redirection' => 3]);
    if (is_wp_error($response)) { $result['public_html'][$kind] = ['read_error' => $response->get_error_code()]; continue; }
    $body = wp_remote_retrieve_body($response);
    preg_match_all('~googletagmanager\.com/gtag/js\?[^\s"\'<>]*~i', $body, $tags);
    preg_match_all('/\bG-[A-Z0-9]{6,20}\b/', $body, $ids);
    $result['public_html'][$kind] = [
        'status' => wp_remote_retrieve_response_code($response),
        'gtag_script_occurrences' => count($tags[0]),
        'ga4_ids' => array_values(array_unique($ids[0])),
        'gtm_reference' => (bool) preg_match('/\bGTM-[A-Z0-9]+\b/', $body),
        'tiktok_reference' => (bool) preg_match('/analytics\.tiktok\.com|\bttq\.load\b/', $body),
        'pinterest_reference' => (bool) preg_match('/ct\.pinterest\.com|\bpintrk\s*\(/', $body),
        'google_verification_meta' => (bool) preg_match('/name=["\']google-site-verification["\']/i', $body),
        'new_module_config' => strpos($body, 'lavkaAnalyticsConfig') !== false,
    ];
}

$stream_id = (string) ($ga['stream_id'] ?? $ga['view_id'] ?? '');
if (class_exists('RankMath\\Google\\Api') && ctype_digit((string) ($ga['property_id'] ?? '')) && ctype_digit($stream_id)) {
    $api = \RankMath\Google\Api::get();
    $property = (string) $ga['property_id'];
    $stream = $stream_id;
    $start_date = gmdate('Y-m-d', time() - 7 * DAY_IN_SECONDS);
    $end_date = gmdate('Y-m-d', time() - DAY_IN_SECONDS);
    $measurement = $api->http_get('https://analyticsadmin.googleapis.com/v1alpha/properties/' . $property . '/dataStreams/' . $stream . '/enhancedMeasurementSettings');
    $fields = ['streamEnabled','scrollsEnabled','outboundClicksEnabled','siteSearchEnabled','videoEngagementEnabled',
        'fileDownloadsEnabled','pageChangesEnabled','formInteractionsEnabled','searchQueryParameter','uriQueryParameter'];
    $result['ga4_enhanced_measurement'] = ['success' => $api->is_success(),
        'error_code' => $measurement['error']['code'] ?? null,
        'settings' => array_intersect_key((array) $measurement, array_flip($fields))];
    $report = $api->http_post('https://analyticsdata.googleapis.com/v1beta/properties/' . $property . ':runReport', [
        'dateRanges' => [['startDate' => $start_date, 'endDate' => $end_date]],
        'dimensions' => [['name' => 'eventName']], 'metrics' => [['name' => 'eventCount']], 'limit' => 100,
    ]);
    $result['ga4_event_inventory'] = ['success' => $api->is_success(), 'period' => $start_date . '/' . $end_date,
        'error_code' => $report['error']['code'] ?? null,
        'total_event_types' => $report['rowCount'] ?? null,
        'truncated' => (int) ($report['rowCount'] ?? 0) > 100, 'rows' => []];
    foreach ($report['rows'] ?? [] as $row) {
        $name = (string) ($row['dimensionValues'][0]['value'] ?? '');
        if (preg_match('/^[a-zA-Z][a-zA-Z0-9_]{0,39}$/', $name)) {
            $result['ga4_event_inventory']['rows'][] = ['event' => $name, 'count' => (int) ($row['metricValues'][0]['value'] ?? 0)];
        }
    }
}
echo wp_json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
