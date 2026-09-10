<?php
// Isolated renderer: no WordPress bootstrap, credentials, network or database.
define('ABSPATH', __DIR__);
function esc_html($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function add_action(...$args) {}
function current_user_can($cap) { return true; }
function __($text, $domain = '') { return $text; }
function esc_html__($text, $domain = '') { return htmlspecialchars($text, ENT_QUOTES, 'UTF-8'); }
function esc_attr__($text, $domain = '') { return esc_html__($text, $domain); }
require dirname(__DIR__) . '/inc/class-profit-report.php';
require dirname(__DIR__) . '/inc/class-profit-history.php';
$page = new Lavka_Reports_Profit_Report();
$translation_method = new ReflectionMethod($page, 'translations');
$translation_method->setAccessible(true);
echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"></head><body>';
$page->render_page();
echo '<script>window.LavkaProfitReport=' . json_encode(['ajaxUrl' => 'https://profit.test/ajax', 'action' => 'test', 'nonce' => 'test', 'savedMode'=>true,'i18n' => $translation_method->invoke($page)]) . ';window.LavkaProfitHistoryConfig=' . json_encode(['ajaxUrl'=>'https://profit.test/history','action'=>'test','nonce'=>'test','i18n'=>Lavka_Reports_Profit_History::translations()]) . ';</script></body></html>';
