<?php
// Local only. Synthetic terms/products and visibility settings are restored in finally.
if (!defined('WP_CLI') || !WP_CLI || wp_get_environment_type() !== 'local') throw new RuntimeException('Local CLI only');
$original = get_option(PSU_Category_Menu_Visibility::OPTION, false);
$user = get_current_user_id(); $terms = []; $product = null; $checks = 0;
$check = static function ($ok, $message) use (&$checks) { if (!$ok) throw new RuntimeException($message); $checks++; };
$term = static function ($name, $parent = 0) use (&$terms) {
    $result = wp_insert_term('PSU visibility ' . $name . ' ' . wp_generate_uuid4(), 'product_cat', ['parent' => $parent]);
    if (is_wp_error($result)) throw new RuntimeException($result->get_error_message());
    return $terms[] = (int) $result['term_id'];
};
$config = ['widget' => 'psu-visibility-test', 'orderby' => 'term_id', 'order' => 'ASC', 'hide_empty' => true, 'show_count' => false];
try {
    $admin = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID']);
    $check((bool) $admin, 'Local administrator exists');
    wp_set_current_user((int) $admin[0]);
    $check(PSU_Category_Menu_Visibility::save([]) === true, 'Clear exclusions');
    $empty = $term('empty'); $root = $term('root'); $child = $term('child', $root); $leaf = $term('leaf', $child);
    $siblings = [];
    for ($i = 0; $i < 33; $i++) $siblings[] = $term('sibling-' . $i, $root);
    $product = new WC_Product_Simple(); $product->set_name('PSU visibility fixture');
    $product->set_status('publish'); $product->set_regular_price('1');
    $product->set_category_ids(array_merge([$leaf], $siblings)); $product->save();
    $index = PSU_Category_Menu::index();
    $check(!isset($index['visible'][$empty]), 'Empty category hidden');
    $check(isset($index['visible'][$root], $index['visible'][$child], $index['visible'][$leaf]), 'Parents with products only in descendants retained');
    $check(is_wp_error(PSU_Category_Menu::branch($config, $empty)), 'Empty category REST branch rejected');
    $render = new ReflectionMethod(PSU_Category_Menu::class, 'render_branch'); $render->setAccessible(true);
    $budget = 60;
    ob_start(); $render->invokeArgs(null, [$config, 0, [$empty], $empty, $index, &$budget, 0]); $html = ob_get_clean();
    $check(strpos($html, 'data-category="' . $empty . '"') === false, 'Current empty page cannot pin itself into menu');
    $first = PSU_Category_Menu::branch($config, $root);
    $check($first['next'] === 30, 'Pagination before exclusions');
    $key = new ReflectionMethod(PSU_Category_Menu::class, 'key'); $key->setAccessible(true);
    $before_key = $key->invoke(null, 'visibility');
    $check(PSU_Category_Menu_Visibility::save([$child, $siblings[0], $siblings[1], $siblings[2]]) === true, 'Save explicit exclusions');
    $check($before_key !== $key->invoke(null, 'visibility'), 'Saving exclusions invalidates cached branches immediately');
    $index = PSU_Category_Menu::index();
    $check(isset($index['blocked'][$child], $index['blocked'][$leaf]), 'Exclusion hides the whole subtree');
    $check(is_wp_error(PSU_Category_Menu::branch($config, $leaf)), 'Direct descendant request cannot bypass exclusions');
    $all_config = $config; $all_config['hide_empty'] = false;
    $check(is_wp_error(PSU_Category_Menu::branch($all_config, $child)), 'Explicit exclusion overrides hide-empty setting');
    $page = PSU_Category_Menu::branch($config, $root);
    $check(count($page['items']) === 30 && $page['next'] === null, 'Pagination counted after exclusions; no empty last page');
    $check(!array_intersect(array_column($page['items'], 'id'), [$child, $siblings[0], $siblings[1], $siblings[2]]), 'Hidden categories absent from response');
    $check(PSU_Category_Menu_Visibility::save([$root]) === true, 'Exclude populated root');
    $index = PSU_Category_Menu::index(); $budget = 60;
    ob_start(); $render->invokeArgs(null, [$config, 0, [$root, $child, $leaf], $leaf, $index, &$budget, 0]); $html = ob_get_clean();
    $check(strpos($html, 'data-category="' . $root . '"') === false, 'Current hidden descendant cannot pin its ancestor');
    $check(PSU_Category_Menu_Visibility::save([]) === true, 'Clear selection restores branches');
    $check(!is_wp_error(PSU_Category_Menu::branch($config, $leaf)), 'Branch returns after clearing exclusions');
    wp_set_current_user(0);
    $check(is_wp_error(PSU_Category_Menu_Visibility::save([$root])), 'Guest cannot edit exclusions');
    $check(PSU_Category_Menu::excluded() === [], 'Denied write preserves setting');
    $rows = [(object) ['term_id'=>1,'parent'=>0,'count'=>0], (object) ['term_id'=>2,'parent'=>1,'count'=>1]];
    $synthetic = PSU_Category_Menu::build_index($rows, [2]);
    $check(empty($synthetic['visible']), 'Excluded-only child does not keep an empty parent visible');
    $cycle = PSU_Category_Menu::build_index([(object) ['term_id'=>1,'parent'=>2,'count'=>1], (object) ['term_id'=>2,'parent'=>1,'count'=>1]], [1]);
    $check(count($cycle['blocked']) === 2 && !$cycle['visible'], 'Exclusion terminates on corrupt cycles');
    echo "Category visibility: $checks checks passed.\n";
} finally {
    if ($product && $product->get_id()) $product->delete(true);
    foreach (array_reverse($terms) as $id) wp_delete_term($id, 'product_cat');
    if ($original === false) delete_option(PSU_Category_Menu_Visibility::OPTION);
    else update_option(PSU_Category_Menu_Visibility::OPTION, $original, false);
    wp_set_current_user($user);
}
