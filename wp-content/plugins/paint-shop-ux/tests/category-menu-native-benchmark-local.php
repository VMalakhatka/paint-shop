<?php
// Paired before/after benchmark in one local process; never registers baseline hooks.
if (!defined('WP_CLI') || !WP_CLI || wp_get_environment_type() !== 'local'
    || parse_url(home_url(), PHP_URL_HOST) !== 'paint.local') throw new RuntimeException('Local only');
$source = shell_exec('git show 531867a:wp-content/plugins/paint-shop-ux/inc/category-menu.php');
if (!$source || !str_starts_with($source, '<?php')) throw new RuntimeException('Known local baseline required');
$source = substr($source, 5);
$source = str_replace('final class PSU_Category_Menu {', 'final class PSU_Category_Menu_Baseline {', $source, $classes);
$source = str_replace('PSU_Category_Menu::boot();', '', $source, $boots);
if ($classes !== 1 || $boots !== 1) throw new RuntimeException('Unexpected baseline format');
eval($source);
$config = null;
foreach (get_option('widget_psu_category_menu', []) as $number => $settings) {
    if (is_numeric($number)) $config = PSU_Category_Menu::config('psu_category_menu-'.$number);
    if ($config) break;
}
if (!$config) throw new RuntimeException('Active native widget required');
$saved = [$GLOBALS['wp_query'],$GLOBALS['wp_the_query'],wp_get_current_user()];
$version = wp_generate_uuid4();
$namespace = static function () use (&$version) {return $version;};
add_filter('pre_option_psu_category_menu_version',$namespace);
$GLOBALS['current_user'] = new WP_User();
$widget = new PSU_Category_Widget(); $widget->id=$config['widget'];
$wrapper = ['before_widget'=>'<aside>','after_widget'=>'</aside>','before_title'=>'<h2>','after_title'=>'</h2>'];
$measure = static function ($class,$repeats) use ($config,$widget,$wrapper) {
    $queries=$GLOBALS['wpdb']->num_queries; $start=hrtime(true);
    for($i=0;$i<$repeats;$i++) {
        ob_start(); $class::render($config,$widget,$wrapper,$config); $html=ob_get_clean();
    }
    return ['ms_per_render'=>(hrtime(true)-$start)/1e6/$repeats,'queries'=>$GLOBALS['wpdb']->num_queries-$queries,'repeats'=>$repeats,'bytes'=>strlen($html)];
};
$results=['baseline'=>'531867a','php'=>PHP_VERSION,'surfaces'=>[]];
try {
    foreach(['home','category'] as $surface) {
        $version=wp_generate_uuid4(); $q=new WP_Query();
        if($surface==='category') {
            $q->queried_object=get_term_by('slug','farbi-akrilovi','product_cat');
            if(!$q->queried_object) throw new RuntimeException('Benchmark category missing');
            $q->queried_object_id=$q->queried_object->term_id; $q->is_tax=true;
        } else $q->is_home=true;
        $GLOBALS['wp_query']=$GLOBALS['wp_the_query']=$q;
        $classes=['before'=>'PSU_Category_Menu_Baseline','after'=>'PSU_Category_Menu'];
        $cold=[]; $warm=[];
        foreach($surface==='home'?$classes:array_reverse($classes,true) as $name=>$class) $cold[$name]=$measure($class,1);
        for($round=0;$round<7;$round++) {
            foreach($round%2?$classes:array_reverse($classes,true) as $name=>$class) $warm[$name][]=$measure($class,100);
        }
        $median=static function ($rows) {$values=array_column($rows,'ms_per_render');sort($values);return $values[3];};
        $results['surfaces'][$surface]=['first'=>$cold,'warm'=>$warm,'median_before_ms'=>$median($warm['before']),
            'median_after_ms'=>$median($warm['after']),'ratio_after_before'=>$median($warm['after'])/$median($warm['before'])];
    }
} finally {
    remove_filter('pre_option_psu_category_menu_version',$namespace);
    [$GLOBALS['wp_query'],$GLOBALS['wp_the_query'],$GLOBALS['current_user']]=$saved;
}
$results['note']='Seven alternating paired batches of 100 warm renders. Separate first namespace build; shared WP core caches, not fully cold process. No baseline hooks or global cache flush.';
file_put_contents('/tmp/psu-native-paired-benchmark.json',wp_json_encode($results,JSON_PRETTY_PRINT));
foreach($results['surfaces'] as &$surface) unset($surface['warm']); unset($surface);
echo wp_json_encode($results,JSON_PRETTY_PRINT),"\n";
