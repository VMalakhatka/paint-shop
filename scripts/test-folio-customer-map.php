<?php
// Offline profile/search regression. No WordPress, network or customer writes.
define('ABSPATH', __DIR__);
function add_action(...$args) {}
function __($s, $domain = '') { return $s; }
function current_user_can(...$args) { return true; }
function get_current_screen() { return (object)['id'=>'user-edit']; }
function get_current_user_id() { return 1; }
function get_user_meta(...$args) { return ''; }
function wp_create_nonce($s) { return 'fixture'; }
function check_ajax_referer(...$args) {}
function wp_json_encode($v) { return json_encode($v); }
function esc_js($s) { return addslashes($s); }
function esc_attr($s) { return htmlspecialchars($s, ENT_QUOTES); }
function esc_attr__($s, $domain='') { return esc_attr($s); }
function sanitize_text_field($s) { return $s; }
function wp_unslash($s) { return $s; }
function add_query_arg($args, $path) { $GLOBALS['query']=$args; return $path.'?'.http_build_query($args); }
function lps_java_get($path) { return []; }
function is_wp_error($v) { return false; }
function wp_remote_retrieve_response_code($v) { return 200; }
function wp_remote_retrieve_body($v) { return json_encode(['items'=>[['id'=>'fixture','name'=>'Fixture salon','type'=>'H','typeLabel'=>'']]]); }
function wp_send_json_success($data) { $GLOBALS['result']=$data; }
function wp_send_json_error(...$args) { throw new RuntimeException('Unexpected API error'); }
require __DIR__.'/../wp-content/mu-plugins/pc-folio-customer-map.php';
function check($ok, $message) { if (!$ok) throw new RuntimeException($message); }
foreach ([null, 'H', 'all', 'К'] as $types) {
    $_POST=['q'=>'Fixture'];
    if ($types!==null) $_POST['types']=$types;
    pc_folio_customer_map_ajax_search();
    check($GLOBALS['query']['types']===($types ?? 'П,Д,К,H'), 'Exact type filter must reach Java');
    check($GLOBALS['result']['items'][0]['typeLabel']==='Art salons', 'Empty Java label must use salon translation');
    check($GLOBALS['result']['items'][0]['type']==='H', 'Preserve Latin H for saved mapping');
}
ob_start(); pc_folio_customer_map_admin_footer(); $html=ob_get_clean();
check(str_contains($html, 'value="П,Д,К,H"'), 'Default visible filter includes art salons');
check(str_contains($html, 'value="H"'), 'Separate salon option is available');
check(str_contains($html, 'value="all"'), 'All organization types remain searchable');
echo "PASS: profile filter, AJAX defaults, Latin H, all types and translated salon label\n";
