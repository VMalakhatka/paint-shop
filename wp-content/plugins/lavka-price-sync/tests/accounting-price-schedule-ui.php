<?php
// Render the actual schedule controls without loading WordPress or starting jobs.
define('ABSPATH', __DIR__);
require dirname(__DIR__, 4) . '/wp-includes/pomo/mo.php';
$translations = new MO();
$translations->import_from_file(__DIR__ . '/../languages/lavka-price-sync-uk.mo');
function esc_attr($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function esc_html($v) { return esc_attr($v); }
function __($v, $domain = '') { return $GLOBALS['translations']->translate($v); }
function esc_html__($v, $domain = '') { return esc_html(__($v)); }
function esc_attr__($v, $domain = '') { return esc_attr(__($v)); }
function wp_json_encode($v) { return json_encode($v); }
function checked($value, $expected = true) { if ($value == $expected) echo ' checked'; }
$cron_options = ['warehouse_ids' => [7, 1, 99], 'weekdays' => ['sun'], 'time' => '20:01'];
$weekdays = ['mon' => 'Monday', 'tue' => 'Tuesday', 'wed' => 'Wednesday', 'thu' => 'Thursday', 'fri' => 'Friday', 'sat' => 'Saturday', 'sun' => 'Sunday'];
$weekdays = array_map('__', $weekdays);
$source = file_get_contents(__DIR__ . '/../inc/accounting-prices.php');
$start = strpos($source, '<div class="lps-ap-cron-grid">');
$end = strpos($source, '<label class="lps-ap-cron-confirm">', $start);
if ($start === false || $end === false) throw new RuntimeException('Schedule template boundaries changed');
?><!doctype html><html lang="uk"><meta charset="utf-8"><style>
body { margin: 0; padding: 16px; font: 14px/1.5 sans-serif; color: #1d2327; background: #f0f0f1; }
* { box-sizing: border-box; } input { min-height: 32px; } input[type=checkbox] { min-height: 0; } label { cursor: pointer; }
<?php echo file_get_contents(__DIR__ . '/../assets/accounting-prices.css'); ?>
</style><div id="lps-accounting-prices" class="lps-ap">
<h2>Облікові ціни ФОЛІО</h2>
<form id="schedule-form">
<?php eval('?>' . substr($source, $start, $end - $start)); ?>
<button type="submit">Зберегти розклад</button></form>
<p id="lps-ap-saved-warehouses" data-warehouse-ids="[7,1,99]"></p>
<div hidden>
<?php
$js = file_get_contents(__DIR__ . '/../assets/accounting-prices.js');
preg_match_all("/getElementById\('([^']+)'\)/", $js, $matches);
foreach (array_unique($matches[1]) as $id) {
    if (in_array($id, ['lps-accounting-prices', 'lps-ap-every-day', 'lps-ap-cron-warehouses', 'lps-ap-saved-warehouses'], true)) continue;
    if ($id === 'lps-ap-warehouse') echo '<select id="' . $id . '"></select>';
    else echo '<input id="' . $id . '">';
}
?>
</div></div><script>window.LPS_ACCOUNTING_PRICES={ajaxUrl:'https://fixture.local/api',i18n:{},pollOnLoad:false};</script>
<script><?php echo $js; ?></script></html>
