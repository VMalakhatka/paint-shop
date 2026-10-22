<?php
/** Scoped analytics launch. Default preview; private notice supplied outside Git. */
if (!defined('WP_CLI') || !WP_CLI) { exit(1); }
if (get_option('lavka_ecosystem_sync_lock', false)) { throw new RuntimeException('Ecosystem operation in progress; no write.'); }
$expected = (string) getenv('LCA_EXPECTED_MEASUREMENT_ID');
$ga = (array) get_option('rank_math_google_analytic_options', []);
$property = (string) ($ga['property_id'] ?? ''); $stream = (string) ($ga['stream_id'] ?? $ga['view_id'] ?? '');
if (!ctype_digit($property) || !ctype_digit($stream) || !preg_match('/^G-[A-Z0-9]{6,20}$/', $expected)) { throw new RuntimeException('Selected stream required.'); }
$api = \RankMath\Google\Api::get();
$base = 'https://analyticsadmin.googleapis.com/v1alpha/properties/' . $property;
$selected = $api->http_get($base . '/dataStreams/' . $stream);
if (!$api->is_success() || ($selected['webStreamData']['measurementId'] ?? '') !== $expected) { throw new RuntimeException('Wrong stream; no write.'); }
$enhanced = $api->http_get($base . '/dataStreams/' . $stream . '/enhancedMeasurementSettings');
if (!$api->is_success() || empty($enhanced['name'])) { throw new RuntimeException('Google configuration unavailable.'); }
foreach (['pageChangesEnabled','siteSearchEnabled','formInteractionsEnabled','outboundClicksEnabled','fileDownloadsEnabled','videoEngagementEnabled','scrollsEnabled'] as $field) {
    if (!empty($enhanced[$field])) { throw new RuntimeException('Enhanced measurement gate not met.'); }
}
$verify = (array) get_option('rank-math-options-general', []);
if (empty($verify['google_verify'])) { throw new RuntimeException('Independent verification required.'); }
$file = (string) getenv('LCA_NOTICE_FILE');
$content = $file && is_file($file) ? file_get_contents($file) : false;
if (!$content || strlen($content) < 3000 || preg_match('/\[(?:ПОГОДИТИ|ПІДТВЕРДИТИ|ВКАЗАТИ)/u', $content)) { throw new RuntimeException('Reviewed finite analytics notice required.'); }
$page = get_page_by_path('analytics-cookies');
if ($page && hash('sha256', $page->post_content) !== hash('sha256', wp_kses_post($content))) { throw new RuntimeException('Existing notice differs; do not overwrite.'); }
$plugin = 'lavka-customer-analytics/lavka-customer-analytics.php';
$source = WP_PLUGIN_DIR . '/' . $plugin;
if (!is_file($source) || !str_contains(file_get_contents($source), "const VERSION = '0.2.0'")) { throw new RuntimeException('Reviewed module 0.2.0 required.'); }
$result = ['status'=>'preview','module_version'=>'0.2.0','notice_sha256'=>hash('sha256', wp_kses_post($content)),
    'notice_existing'=>(bool)$page, 'full_privacy_page_unchanged'=>true,'orders_payments_unchanged'=>true];
if (getenv('LCA_LAUNCH_APPLY') !== '1') { echo wp_json_encode($result, JSON_PRETTY_PRINT) . "\n"; return; }
require_once ABSPATH . 'wp-admin/includes/plugin.php';
$old = umask(0077); $snapshot = tempnam(sys_get_temp_dir(), 'kreul-analytics-launch-');
if (!$snapshot || file_put_contents($snapshot, wp_json_encode(['lca_settings'=>get_option('lca_settings', null),
    'plugin_active'=>is_plugin_active($plugin),'rank_math_install_code'=>$ga['install_code'] ?? null,
    'notice_id'=>$page ? $page->ID : null,'at_utc'=>gmdate('c')])) === false || !chmod($snapshot,0600)) { umask($old); throw new RuntimeException('Snapshot failed; no write.'); }
umask($old);
if (get_option('lavka_ecosystem_sync_lock', false)) { throw new RuntimeException('Operation started; no write.'); }
if (!$page) {
    $id = wp_insert_post(['post_type'=>'page','post_status'=>'publish','post_title'=>'Аналітика та cookies',
        'post_name'=>'analytics-cookies','post_content'=>wp_kses_post($content),'comment_status'=>'closed','ping_status'=>'closed'],true);
    if (is_wp_error($id)) { throw new RuntimeException('Notice publication failed; collection not enabled.'); }
} else { $id = $page->ID; }
// Persist only this existing flag so disabling our plugin does not revive non-consented GA.
$ga['install_code'] = false; update_option('rank_math_google_analytic_options', $ga);
$activated = activate_plugin($plugin);
if (is_wp_error($activated)) { throw new RuntimeException('Module activation failed; collection not enabled.'); }
update_option('lca_settings', ['enabled'=>true,'measurement_id'=>$expected,'debug'=>getenv('LCA_LAUNCH_DEBUG') === '1','notice_page_id'=>(int)$id],false);
clean_post_cache($id);
$settings = (array) get_option('lca_settings',[]);
$result += ['snapshot_path'=>$snapshot,'notice_url'=>get_permalink($id),'active'=>is_plugin_active($plugin),
    'enabled'=>$settings['enabled'] ?? false,'debug'=>$settings['debug'] ?? false];
$result['status'] = $result['active'] && $result['enabled'] && get_post_status($id) === 'publish' ? 'configured_pending_acceptance' : 'failed';
echo wp_json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
if ($result['status'] === 'failed') { exit(2); }
