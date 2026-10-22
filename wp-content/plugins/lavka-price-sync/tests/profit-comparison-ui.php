<?php
if (PHP_SAPI !== 'cli') exit;
define('ABSPATH', __DIR__);
function add_action(...$args) {}
function __($s, $d = '') { return $s; }
function esc_html__($s, $d = '') { return htmlspecialchars($s); }
function esc_attr__($s, $d = '') { return htmlspecialchars($s); }
function esc_html($s) { return htmlspecialchars($s); }
function esc_attr($s) { return htmlspecialchars($s); }
function esc_url($s) { return htmlspecialchars($s); }
function admin_url($s) { return $s; }
function wp_date($format, $time = null) { return date($format, $time ?? strtotime('2026-10-17')); }
function current_time($format) { return strtotime('2026-10-17'); }
function lps_analytics_snapshot_render() {}
function lps_availability_fields() {}
function lps_availability_sort_options() {}
define('LPS_ANALYTICS_SCENARIOS_PAGE', 'scenarios');
$base = dirname(__DIR__);
require $base . '/inc/product-analytics.php';
echo '<!doctype html><html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><style>body{font:14px Arial;margin:16px;background:#f0f0f1}button,input,select{font:inherit}td,th{padding:10px}table{border-collapse:collapse}';
echo file_get_contents($base . '/assets/product-analytics.css'); echo '</style>';
lps_render_product_analytics_v4_page();
echo '<script>window.LPS_PRODUCT_ANALYTICS=' . json_encode(['ajaxUrl'=>'https://fixture.local/api','locale'=>'en-US','currency'=>'UAH','i18n'=>lps_product_analytics_i18n()]) . ';window.LPS_AVAILABILITY=()=>({refresh(){},read(){return {}},apply(){}});</script><script>';
echo file_get_contents($base . '/assets/analytics-conditions.js');
echo file_get_contents($base . '/assets/product-analytics-v4.js'); echo '</script></html>';
