<?php
if (!defined('WP_CLI') || !WP_CLI || wp_get_environment_type() !== 'local') throw new RuntimeException('Local CLI only');
$config = null;
foreach (get_option('widget_wpb_wmca_accordion_widget', []) as $number => $instance) {
    if (is_numeric($number)) $config = PSU_Category_Menu::config('wpb_wmca_accordion_widget-' . $number);
    if ($config) break;
}
if (!$config) throw new RuntimeException('Missing category widget');
$widget = new WPB_Accordion_Menu_Widget(); $widget->id = $config['widget'];
$args = ['before_widget'=>'<aside class="widget widget_wpb_wmca_accordion_widget">','after_widget'=>'</aside>','before_title'=>'<h2>','after_title'=>'</h2>'];
$category = get_term_by('slug', 'farbi-akrilovi', 'product_cat');
if (!$category) throw new RuntimeException('Benchmark category missing');
$GLOBALS['wp_query']->queried_object = $category;
$GLOBALS['wp_query']->queried_object_id = $category->term_id;
$GLOBALS['wp_query']->is_tax = true;
$version = wp_generate_uuid4();
add_filter('pre_option_psu_category_menu_version', static function () use (&$version) { return $version; });
$result = ['php'=>PHP_VERSION,'external_object_cache'=>(bool)wp_using_ext_object_cache(),'category'=>get_term_link($category)];
foreach (['old-widget','new-widget','old-sidebar','new-sidebar','new-branch'] as $mode) {
    if ($mode === 'new-branch') $version = wp_generate_uuid4();
    if (str_starts_with($mode, 'old')) add_filter('psu_lazy_category_menu_enabled', '__return_false');
    for ($i=0; $i<7; $i++) {
        $q=$GLOBALS['wpdb']->num_queries; $start=microtime(true); ob_start();
        if (str_contains($mode, 'sidebar')) dynamic_sidebar('sidebar-1');
        elseif ($mode==='new-branch') echo wp_json_encode(PSU_Category_Menu::branch($config, $category->term_id));
        elseif (false !== apply_filters('widget_display_callback', $instance, $widget, $args)) $widget->widget($args, $instance);
        $html=ob_get_clean();
        $result[$mode][]=['run'=>$i+1,'seconds'=>round(microtime(true)-$start,6),'queries'=>$GLOBALS['wpdb']->num_queries-$q,'bytes'=>strlen($html),'links'=>substr_count($html,'<a ')];
    }
    remove_filter('psu_lazy_category_menu_enabled', '__return_false');
}
$result['note']='First new-widget call: cold menu namespace; remaining calls warm. WordPress core caches are not globally flushed. Sidebar is measured separately after widget loops, therefore warm. CLI is not HTTP TTFB.';
file_put_contents('/tmp/psu-category-menu-benchmark.json', wp_json_encode($result, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
switch_to_locale('ru_RU');
ob_start(); PSU_Category_Menu::widget($instance, $widget, $args); $menu=ob_get_clean();
$html='<!doctype html><html lang="ru"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="'.plugins_url('../assets/category-menu.css',__FILE__).'"><style>body{margin:24px;font-family:Arial,sans-serif}.widget{max-width:330px}.screen-reader-text{position:absolute;clip-path:inset(50%);width:1px;height:1px;overflow:hidden}</style><body>'.$menu.'<script src="'.plugins_url('../assets/category-menu.js',__FILE__).'"></script></body></html>';
file_put_contents('/tmp/psu-category-menu-ru.html',$html);
restore_previous_locale();
echo wp_json_encode($result, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE),"\n";
