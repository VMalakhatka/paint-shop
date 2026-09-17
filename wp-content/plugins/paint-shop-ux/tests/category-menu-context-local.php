<?php
// Local integration test: in-memory users/queries, public category reads and isolated menu transients only.
if (!defined('WP_CLI') || !WP_CLI || wp_get_environment_type() !== 'local') throw new RuntimeException('Local CLI only');
$config = null;
foreach (get_option('widget_psu_category_menu', []) as $number => $settings) {
    if (is_numeric($number)) $config = PSU_Category_Menu::config('psu_category_menu-' . $number);
    if ($config) break;
}
if (!$config) throw new RuntimeException('Active native widget required');
$quick = null;
foreach (get_posts(['post_type'=>'page', 'post_status'=>'publish', 'numberposts'=>-1]) as $page) {
    if (has_shortcode($page->post_content, 'pc_quick_order') && !$page->post_password) { $quick = $page; break; }
}
if (!$quick) throw new RuntimeException('Published quick-order page required');
$saved = [$GLOBALS['wp_query'], $GLOBALS['wp_the_query'], $GLOBALS['post'] ?? null, wp_get_current_user(), $_GET];
$version = wp_generate_uuid4();
$namespace = static function () use (&$version) { return $version; };
add_filter('pre_option_psu_category_menu_version', $namespace);
$checks = []; $timings = [];
$check = static function ($ok, $name) use (&$checks) { $checks[] = ['test'=>$name, 'pass'=>(bool)$ok]; };
$context = static function ($page, $role) {
    $q = new WP_Query();
    $q->is_page = (bool)$page; $q->is_singular = (bool)$page; $q->is_home = !$page;
    $q->queried_object = $page; $q->queried_object_id = $page ? $page->ID : 0;
    $GLOBALS['wp_query'] = $GLOBALS['wp_the_query'] = $q; $GLOBALS['post'] = $page;
    $user = new WP_User();
    if ($role !== 'guest') {
        $user->ID = PHP_INT_MAX; $user->roles = [$role];
        $user->allcaps = get_role($role) ? get_role($role)->capabilities : [];
    }
    $GLOBALS['current_user'] = $user;
};
try {
    $roles = array_values(array_unique(array_merge(['guest','customer','shop_manager'], pc_wholesale_customer_roles())));
    foreach (['uk','ru_RU'] as $locale) {
        $switched = switch_to_locale($locale);
        try {
            $context(null, 'guest');
            $roots = get_terms(['taxonomy'=>'product_cat','parent'=>0,'hide_empty'=>false]);
            $canonical = [];
            foreach ($roots as $term) $canonical[$term->term_id] = get_term_link($term);
            foreach ([true, false] as $quick_first) {
                $version = wp_generate_uuid4();
                $context($quick_first ? $quick : null, pc_wholesale_customer_roles()[0]);
                PSU_Category_Menu::branch($config, 0);
                foreach ($roles as $role) {
                    $context(null, $role);
                    $branch = PSU_Category_Menu::branch($config, 0);
                    $ok = !is_wp_error($branch) && count($branch['items']) <= 30;
                    foreach ($branch['items'] as $node) $ok = $ok && $node['url'] === $canonical[$node['id']];
                    $check($ok, "$locale / " . ($quick_first ? 'quick-first' : 'catalog-first') . " / $role canonical cache");
                    $request = new WP_REST_Request('GET', '/paint-shop-ux/v1/categories');
                    $request->set_query_params(['widget'=>$config['widget'],'parent'=>0,'offset'=>0,'locale'=>$locale]);
                    $response = PSU_Category_Menu::request($request);
                    $check(!is_wp_error($response) && $response->get_data() === $branch, "$locale / $role public REST matches catalogue");
                    $context($quick, $role);
                    $widget = new PSU_Category_Widget(); $widget->id = $config['widget'];
                    ob_start(); PSU_Category_Menu::render($config, $widget, ['before_widget'=>'<aside>','after_widget'=>'</aside>','before_title'=>'<h2>','after_title'=>'</h2>'], $config); $html = ob_get_clean();
                    $dom = new DOMDocument(); @$dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
                    $xpath = new DOMXPath($dom);
                    $allowed = pc_wholesale_customer_can_access();
                    $ok = true;
                    foreach ($xpath->query('//nav//li[@data-category]/div/a') as $link) {
                        $id = (int)$link->parentNode->parentNode->getAttribute('data-category');
                        $term = get_term($id, 'product_cat');
                        $expected = $allowed ? add_query_arg('cat', $term->slug, get_permalink($quick)) : $canonical[$id];
                        $ok = $ok && html_entity_decode($link->getAttribute('href')) === $expected;
                    }
                    $check($ok && strlen($html) <= 50000, "$locale / $role quick-order initial links and bound");
                }
            }
        } finally { if ($switched) restore_previous_locale(); }
    }
    $context(null, 'guest'); $version = wp_generate_uuid4();
    $request = new WP_REST_Request('GET', '/paint-shop-ux/v1/categories');
    $request->set_query_params(['widget'=>$config['widget'],'parent'=>0,'offset'=>30,'locale'=>get_locale(),'quick_order_page'=>$quick->ID]);
    $response = PSU_Category_Menu::request($request);
    $ok = !is_wp_error($response);
    foreach ($ok ? $response->get_data()['items'] : [] as $node) {
        $ok = $ok && $node['url'] === add_query_arg('cat', get_term($node['id'], 'product_cat')->slug, get_permalink($quick));
    }
    $check($ok, 'Explicit quick-order REST pagination uses public page context');
    $request->set_param('quick_order_page', PHP_INT_MAX);
    $check(is_wp_error(PSU_Category_Menu::request($request)), 'Unknown quick-order page rejected');
    $root_branch = PSU_Category_Menu::branch($config, 0);
    $parent = array_values(array_filter($root_branch['items'], static function ($n) { return $n['children']; }))[0]['id'];
    $request->set_param('parent', $parent); $request->set_param('offset', 0); $request->set_param('quick_order_page', $quick->ID);
    $response = PSU_Category_Menu::request($request);
    $ok = !is_wp_error($response) && count($response->get_data()['items']) > 0 && count($response->get_data()['items']) <= 30;
    foreach ($ok ? $response->get_data()['items'] : [] as $node) {
        $ok = $ok && $node['url'] === add_query_arg('cat', get_term($node['id'], 'product_cat')->slug, get_permalink($quick));
    }
    $check($ok, 'Quick-order child REST bounded with contextual URLs');
    $index = PSU_Category_Menu::index(); $deep = 0; $max_depth = 0;
    foreach ($index['parents'] as $id => $_parent) {
        $depth = count(PSU_Category_Menu::path($id, $index));
        if ($depth > $max_depth && isset($index['visible'][$id])) { $deep = $id; $max_depth = $depth; }
    }
    $context($quick, pc_wholesale_customer_roles()[0]);
    $_GET['cat'] = get_term($deep, 'product_cat')->slug;
    $widget = new PSU_Category_Widget(); $widget->id = $config['widget'];
    ob_start(); PSU_Category_Menu::render($config, $widget, ['before_widget'=>'<aside>','after_widget'=>'</aside>','before_title'=>'<h2>','after_title'=>'</h2>'], $config); $deep_html = ob_get_clean();
    $dom = new DOMDocument(); @$dom->loadHTML('<?xml encoding="utf-8" ?>'.$deep_html); $xp = new DOMXPath($dom);
    $ok = true;
    foreach ($xp->query('//nav//li[@data-category]/div/a') as $link) {
        $id = (int)$link->parentNode->parentNode->getAttribute('data-category');
        $ok = $ok && $link->getAttribute('href') === add_query_arg('cat', get_term($id, 'product_cat')->slug, get_permalink($quick));
    }
    $check($ok && strlen($deep_html) <= 50000, 'Deep active quick-order path has consistent links and bounded HTML');
    $check($xp->query('//nav//a[@aria-current="page"]')->length === 1, 'Deep quick-order category marked current');
    $check($xp->query('//nav//button[not(@hidden)]')->length === 0, 'No-JS HTML hides inactive disclosure controls');
    unset($_GET['cat']);
    $context($quick, pc_wholesale_customer_roles()[0]);
    $term = get_term($parent, 'product_cat');
    $foreign_filter = static function ($url) { return add_query_arg('test_link_filter', 'kept', $url); };
    add_filter('term_link', $foreign_filter, 99);
    try {
        $url = PCQO_Category_Links::catalogue_url($term);
        $check(str_contains($url, 'test_link_filter=kept') && !str_contains($url, 'cat='), 'Other term_link filters preserved');
        $check(str_contains(get_term_link($term), 'cat='), 'Canonical scope restores contextual rewrite');
    } finally { remove_filter('term_link', $foreign_filter, 99); }
    $context(null, 'guest'); $version = wp_generate_uuid4();
    $widget = new PSU_Category_Widget(); $widget->id = $config['widget'];
    for ($i=0; $i<7; $i++) {
        $queries = $GLOBALS['wpdb']->num_queries; $start = microtime(true);
        ob_start(); PSU_Category_Menu::render($config, $widget, ['before_widget'=>'<aside>','after_widget'=>'</aside>','before_title'=>'<h2>','after_title'=>'</h2>'], $config); $html = ob_get_clean();
        $timings[] = ['run'=>$i+1,'seconds'=>microtime(true)-$start,'queries'=>$GLOBALS['wpdb']->num_queries-$queries,'bytes'=>strlen($html)];
    }
} finally {
    remove_filter('pre_option_psu_category_menu_version', $namespace);
    [$GLOBALS['wp_query'], $GLOBALS['wp_the_query'], $GLOBALS['post'], $GLOBALS['current_user'], $_GET] = $saved;
}
$result = ['passed'=>count(array_filter($checks, static function ($r) { return $r['pass']; })), 'failed'=>array_values(array_filter($checks, static function ($r) { return !$r['pass']; })), 'total'=>count($checks), 'renderer'=>$timings];
$stage = preg_replace('/[^a-z0-9-]/', '', $args[0] ?? 'current');
file_put_contents('/tmp/psu-context-' . $stage . '.json', wp_json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo wp_json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), "\n";
if ($result['failed']) WP_CLI::halt(1);
