<?php
// CLI-only fixture: real scenario markup/scripts, no WordPress or Folio connection.
if (PHP_SAPI !== 'cli') exit;
define('ABSPATH', __DIR__); define('LPS_CAP', 'manage_options'); define('LPS_PRODUCT_ANALYTICS_PAGE', 'analytics');
function add_action(...$args) {}
function selected($a, $b, $echo = true) { $s = (string)$a === (string)$b ? ' selected' : ''; if ($echo) echo $s; return $s; }
function lps_product_analytics_i18n() { return ['statusLabels'=>[], 'transitLabels'=>[]]; }
function __($s, $d = '') { return $s; }
function esc_html($s) { return htmlspecialchars((string)$s); }
function esc_attr($s) { return esc_html($s); }
function esc_url($s) { return esc_html($s); }
function esc_html__($s, $d = '') { return __($s, $d); }
function esc_attr__($s, $d = '') { return __($s, $d); }
function admin_url($s) { return $s; }
function wp_date($s, $time = null) { return date($s, $time ?? time()); }
function current_time($s) { return time(); }
$base = dirname(__DIR__);
require $base . '/inc/product-availability.php';
require $base . '/inc/purchase-planning-model.php';
require $base . '/inc/purchase-planning.php';
require $base . '/inc/analytics-scenarios.php';
echo '<!doctype html><html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><style>body{font:14px system-ui;margin:12px}input,select,button{font:inherit;max-width:100%}';
echo file_get_contents($base . '/assets/analytics-scenarios.css'); echo '</style>';
lps_render_analytics_scenarios_v4_page();
echo '<script>window.LPS_ANALYTICS_SCENARIOS={ajaxUrl:"https://fixture.local/api",nonce:"fixture",warehouseGroups:[]};</script>';
foreach (['product-availability.js', 'analytics-scenarios-v4.js'] as $script) echo '<script>' . file_get_contents($base . '/assets/' . $script) . '</script>';
echo '</html>';
