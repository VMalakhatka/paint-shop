<?php
/** Help render/translation/canonical contract without WordPress or Folio calls. */
define('ABSPATH', __DIR__); define('LPS_CAP', 'manage_lavka_prices');
require dirname(__DIR__, 4) . '/wp-includes/pomo/mo.php';
$allowed = true; $locale = 'uk'; $catalog = new MO();
function add_action(...$args) {}
function current_user_can($cap) { return $GLOBALS['allowed'] && $cap === LPS_CAP; }
function determine_locale() { return $GLOBALS['locale']; }
function load_textdomain($domain, $path) { if (!$GLOBALS['catalog']->import_from_file($path)) throw new RuntimeException('Missing catalog'); }
function __($text, $domain = '') { return $GLOBALS['catalog']->translate($text); }
function esc_html($text) { return htmlspecialchars((string)$text, ENT_QUOTES, 'UTF-8'); }
function esc_attr($text) { return esc_html($text); }
function esc_url($text) { return esc_html($text); }
function esc_html__($text, $domain) { return esc_html(__($text, $domain)); }
function sanitize_key($text) { return preg_replace('/[^a-z0-9_-]/', '', strtolower($text)); }
function admin_url($path) { return 'https://example.invalid/wp-admin/' . $path; }
require dirname(__DIR__) . '/inc/accounting-price-help.php';
if (($argv[1] ?? '') === '--render') { $locale=$argv[2] ?? 'uk'; lps_accounting_help_render(); exit; }
$canon=file_get_contents(dirname(__DIR__, 4) . '/docs/FOLIO_ACCOUNTING_PRICES_MANAGER_UK.md');
foreach (['uk','ru_RU'] as $locale) {
    $catalog = new MO(); ob_start(); lps_accounting_help_render(); $html=ob_get_clean();
    $sections=lps_accounting_help_sections();
    if(count($sections)!==10) throw new RuntimeException('Section count');
    foreach($sections as $id=>[$title,$paragraphs]) {
        if(substr_count($html,'id="'.$id.'"')!==1) throw new RuntimeException('Anchor');
        if(preg_match('/^[A-Za-z]/u',$title) && $id!=='campaign') throw new RuntimeException('Untranslated title');
        foreach($paragraphs as $p) if($locale==='uk' && !str_contains($canon,$p)) throw new RuntimeException('Canon drift: '.$id);
    }
    if(str_contains($html,'<form') || str_contains($html,'<script')) throw new RuntimeException('Help must be read only');
}
$allowed=false; ob_start(); lps_accounting_help_render(); $html=ob_get_clean();
if($html!=='') throw new RuntimeException('Capability gate');
echo "PASS: 10 help anchors, UK/RU, canonical parity, read-only render and capability gate\n";
