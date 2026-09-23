<?php
// wp --exec='define("DISABLE_WP_CRON",true);define("WP_HTTP_BLOCK_EXTERNAL",true);' eval-file <this-file>
if (!defined('WP_CLI') || !WP_CLI || wp_get_environment_type() !== 'local') throw new RuntimeException('Local CLI only');
function psu_menu_check($test, $message) { if (!$test) throw new RuntimeException($message); }
$config = null;
foreach (['psu_category_menu', 'wpb_wmca_accordion_widget'] as $base) {
    foreach (get_option('widget_' . $base, []) as $number => $instance) {
        if (is_numeric($number)) $config = PSU_Category_Menu::config($base . '-' . $number);
        if ($config) break 2;
    }
}
psu_menu_check($config !== null, 'Expected active product category widget');
psu_menu_check(PSU_Category_Menu::config('text-2') === null, 'Other widgets must not be replaced');
psu_menu_check(PSU_Category_Menu::config('wpb_wmca_accordion_widget-999999') === null, 'Inactive widget rejected');
$index = PSU_Category_Menu::index();
psu_menu_check(!is_wp_error($index), 'Index query');
$expected = get_terms(['taxonomy' => 'product_cat', 'hide_empty' => $config['hide_empty'], 'hierarchical' => true, 'fields' => 'ids']);
$expected = array_values(array_filter($expected, static function ($id) use ($config, $index) {
    return !isset($index['blocked'][$id]) && (!$config['hide_empty'] || isset($index['visible'][$id]));
}));
$seen = []; $todo = [0]; $pages = 0; $max_rows = 0; $deepest = [];
while ($todo) {
    $parent = array_pop($todo); $offset = 0;
    do {
        $branch = PSU_Category_Menu::branch($config, $parent, $offset);
        psu_menu_check(!is_wp_error($branch), 'Branch response');
        $max_rows = max($max_rows, count($branch['items'])); $pages++;
        psu_menu_check(count($branch['items']) <= PSU_Category_Menu::PAGE_SIZE, 'Bounded page');
        foreach ($branch['items'] as $item) {
            psu_menu_check(!isset($seen[$item['id']]), 'No duplicates across tree/pagination');
            psu_menu_check($index['parents'][$item['id']] === $parent, 'Immediate children only');
            $seen[$item['id']] = true;
            psu_menu_check($item['url'] === get_term_link($item['id'], 'product_cat'), 'Canonical term link');
            if ($item['children']) $todo[] = $item['id'];
            $path = PSU_Category_Menu::path($item['id'], $index);
            if (count($path) > count($deepest)) $deepest = $path;
        }
        $offset = $branch['next'];
    } while ($offset !== null);
}
$actual = array_keys($seen); sort($actual); sort($expected);
psu_menu_check($actual === $expected, 'WordPress hierarchical hide_empty with configured exclusions');
psu_menu_check(is_wp_error(PSU_Category_Menu::branch($config, PHP_INT_MAX)), 'Unknown parent rejected');
psu_menu_check(PSU_Category_Menu::branch($config, 0, 100000)['items'] === [], 'Past-last page empty');
$leaf = array_values(array_filter($actual, static function ($id) use ($index) { return empty($index['children'][$id]); }))[0];
psu_menu_check(PSU_Category_Menu::branch($config, $leaf)['items'] === [], 'Empty branch');
$rows = [];
for ($i = 1; $i <= 40; $i++) $rows[] = (object) ['term_id' => $i, 'parent' => $i - 1, 'count' => $i === 40 ? 1 : 0];
$rows[] = (object) ['term_id' => 100, 'parent' => 0, 'count' => 0];
$synthetic = PSU_Category_Menu::build_index($rows);
psu_menu_check(count($synthetic['visible']) === 40 && empty($synthetic['visible'][100]), 'Empty ancestors of non-empty deep leaves retained');
psu_menu_check(count(PSU_Category_Menu::path(40, $synthetic)) === 40, 'Deep paths');
$cycle = PSU_Category_Menu::build_index([(object) ['term_id'=>1,'parent'=>2,'count'=>1], (object) ['term_id'=>2,'parent'=>1,'count'=>0]]);
psu_menu_check(count(PSU_Category_Menu::path(1, $cycle)) === 2, 'Corrupt cycle terminates');
$key_method = new ReflectionMethod(PSU_Category_Menu::class, 'key');
$key_method->setAccessible(true);
$uk = $key_method->invoke(null, 'test');
add_filter('locale', $locale_filter = static function () { return 'ru_RU'; });
$ru = $key_method->invoke(null, 'test'); remove_filter('locale', $locale_filter);
psu_menu_check($uk !== $ru, 'Locale-separated cache');
$version = get_option('psu_category_menu_version', '1');
PSU_Category_Menu::invalidate([], 'product_cat');
psu_menu_check(get_option('psu_category_menu_version', '1') !== $version, 'Term/count invalidation changes namespace');
echo wp_json_encode(['passed'=>true, 'visible_categories'=>count($actual),'branch_pages'=>$pages,'max_rows'=>$max_rows,'max_depth'=>count($deepest),
    'deep_url'=>get_term_link(end($deepest), 'product_cat'), 'leaf'=>$leaf, 'widget'=>$config['widget'],
    'shop'=>wc_get_page_permalink('shop'),'cart'=>wc_get_page_permalink('cart'),'checkout'=>wc_get_page_permalink('checkout'),
    'locales'=>get_available_languages()], JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT), "\n";
