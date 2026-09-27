<?php
/** Read-only rendering contract. No WordPress bootstrap, database or network. */
$root = dirname(__DIR__);
require_once $root . '/wp-includes/pomo/mo.php';
define('ABSPATH', $root . '/');
$translations = new MO();
if (!$translations->import_from_file($root . '/wp-content/mu-plugins/languages/pc-wholesale-help-uk.mo')) exit(1);
function esc_html_e($text, $domain) { global $translations; echo htmlspecialchars($translations->translate($text), ENT_QUOTES, 'UTF-8'); }
function esc_url($url) { return htmlspecialchars($url, ENT_QUOTES, 'UTF-8'); }
function wc_get_page_permalink($page) { return 'https://example.invalid/my-account/'; }
function add_query_arg($key, $value, $url) { return $url . '?' . $key . '=' . $value; }
function pc_wholesale_help_asset_url($file) { return 'https://example.invalid/assets/' . $file; }
ob_start();
include $root . '/wp-content/mu-plugins/pc-wholesale-help/messages-guide.php';
$html = ob_get_clean();
foreach (['messages-new','messages-status','telegram-connect','telegram-reply','telegram-problems','telegram-disconnect'] as $id) {
    if (substr_count($html, 'id="' . $id . '"') !== 1) throw new RuntimeException('Missing/duplicate anchor: ' . $id);
}
foreach (['Підключити Telegram', 'Підтвердити мій Telegram-акаунт', '15 хвилин', '30 хвилин', '/stop', 'PDF, українською'] as $text) {
    if (!str_contains($html, $text)) throw new RuntimeException('Missing translation: ' . $text);
}
if (str_contains($html, 'start=')) throw new RuntimeException('Personal pairing link must never enter help');
echo "PASS: isolated Ukrainian help rendering, six anchors, PDF link and no pairing codes\n";
