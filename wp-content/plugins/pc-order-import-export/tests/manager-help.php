<?php
/** Isolated render/capability/i18n contract: no WordPress bootstrap or database. */
namespace PaintCore\PCOE { class ManagerWorkspace { const PAGE='pcoe-customers'; public static function url(int $id): string { return 'https://example.invalid/wp-admin/admin.php?page=pcoe-customers'; } } }
namespace {
    $root=dirname(__DIR__,4);
    define('ABSPATH',$root.'/'); define('PCOE_DIR',dirname(__DIR__));
    require $root.'/wp-includes/pomo/mo.php';
    $allowed=true; $locale='uk'; $translations=new \MO();
    function current_user_can($cap) { return $GLOBALS['allowed'] && $cap==='manage_woocommerce'; }
    function determine_locale() { return $GLOBALS['locale']; }
    function load_textdomain($domain,$path) { $GLOBALS['translations']=new \MO(); if (!$GLOBALS['translations']->import_from_file($path)) throw new \RuntimeException('Missing catalog'); }
    function __($s,$d) { return $GLOBALS['translations']->translate($s); }
    function esc_html($s) { return htmlspecialchars($s,ENT_QUOTES,'UTF-8'); }
    function esc_attr($s) { return esc_html($s); }
    function esc_url($s) { return esc_html($s); }
    function esc_html__($s,$d) { return esc_html(__($s,$d)); }
    function esc_attr__($s,$d) { return esc_attr(__($s,$d)); }
    function admin_url($s) { return 'https://example.invalid/wp-admin/'.$s; }
    function add_query_arg($args,$url) { return $url.'?'.http_build_query($args); }
    function sanitize_key($s) { return preg_replace('/[^a-z0-9_-]/','',strtolower($s)); }
    function wp_die($s) { throw new \RuntimeException($s); }
    require PCOE_DIR.'/inc/ManagerHelp.php';
    function render_help() { ob_start(); \PaintCore\PCOE\ManagerHelp::render(); return ob_get_clean(); }
    if (($argv[1]??'')==='--render') { $locale=$argv[2]??'uk'; echo render_help(); exit; }
    $canon=file_get_contents($root.'/docs/MANAGER_CUSTOMER_GUIDE_UK.md');
    foreach (['uk','ru_RU'] as $locale) {
        $html=render_help(); $sections=\PaintCore\PCOE\ManagerHelp::sections();
        if(count($sections)!==23) throw new \RuntimeException('Section count');
        foreach($sections as $id=>[$title,$paragraphs]) {
            if(substr_count($html,'id="'.$id.'"')!==1 || !str_contains($canon,'id="'.$id.'"')) throw new \RuntimeException('Anchor: '.$id);
            if(preg_match('/^[A-Za-z]/u',$title)) throw new \RuntimeException('Untranslated title: '.$title);
            foreach($paragraphs as $p) if($locale==='uk' && !str_contains($canon,$p)) throw new \RuntimeException('Canon drift: '.$id);
        }
        if(str_contains($html,'start=') || str_contains($html,'<form')) throw new \RuntimeException('Help must be static');
    }
    $allowed=false;
    ob_start(); \PaintCore\PCOE\ManagerHelp::link(); $output=ob_get_clean();
    if($output!=='') throw new \RuntimeException('Customer link leaked');
    try { \PaintCore\PCOE\ManagerHelp::render(); throw new \LogicException('Gate missing'); }
    catch(\RuntimeException $e) { if($e->getMessage()!=='Forbidden') throw $e; }
    echo "PASS: 23 anchors, UK/RU rendering, canonical parity, no forms or pairing codes, capability gate\n";
}
