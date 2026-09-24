<?php
// Local-only fixtures; verifies the real Woo tile renderers and their warm cache.
if (!defined('WP_CLI') || !WP_CLI || wp_get_environment_type() !== 'local') throw new RuntimeException('Local only');
$original = get_option(PSU_Category_Menu_Visibility::OPTION, false);
$user = get_current_user_id(); $terms = []; $product = null; $checks = 0;
$check = static function ($ok, $message) use (&$checks) { if (!$ok) throw new RuntimeException($message); $checks++; };
$term = static function ($name, $parent = 0) use (&$terms) {
    $result = wp_insert_term('PSU tile ' . $name . ' ' . wp_generate_uuid4(), 'product_cat', ['parent'=>$parent]);
    if (is_wp_error($result)) throw new RuntimeException($result->get_error_message());
    return $terms[] = (int) $result['term_id'];
};
try {
    $root = $term('hidden'); $child = $term('child', $root); $visible = $term('visible');
    $product = new WC_Product_Simple(); $product->set_name('PSU tile fixture');
    $product->set_status('publish'); $product->set_regular_price('1'); $product->set_category_ids([$child, $visible]); $product->save();
    update_option(PSU_Category_Menu_Visibility::OPTION, []);
    $initial = woocommerce_get_product_subcategories(0);
    $check(in_array($root, wp_list_pluck($initial, 'term_id'), true), 'Populated root is in warmed Woo cache');
    update_option(PSU_Category_Menu_Visibility::OPTION, [$root]);
    $admins = get_users(['role'=>'administrator', 'number'=>1, 'fields'=>'ID']);
    foreach ([0, (int) $admins[0]] as $viewer) {
        wp_set_current_user($viewer);
        $roots = wp_list_pluck(woocommerce_get_product_subcategories(0), 'term_id');
        $check(!in_array($root, $roots, true), 'Excluded root absent from shop tiles');
        $check(in_array($visible, $roots, true), 'Other category retained');
        $check(woocommerce_get_product_subcategories($root) === [], 'Inherited exclusion affects child tiles');
        ob_start(); woocommerce_output_product_categories(['parent_id'=>0]); $html = ob_get_clean();
        $check(strpos($html, esc_url(get_term_link($root, 'product_cat'))) === false, 'Excluded tile has no rendered link');
        $html = WC_Shortcodes::product_categories(['ids'=>"$root,$child,$visible", 'hide_empty'=>0]);
        $check(strpos($html, esc_url(get_term_link($root, 'product_cat'))) === false, 'Shortcode cannot expose root');
        $check(strpos($html, esc_url(get_term_link($child, 'product_cat'))) === false, 'Shortcode cannot expose descendant');
        $check(strpos($html, esc_url(get_term_link($visible, 'product_cat'))) !== false, 'Shortcode keeps visible category');
    }
    $args = PSU_Category_Menu_Visibility::tile_args(['exclude'=>(string) $visible]);
    $check(in_array($visible, $args['exclude'], true) && in_array($child, $args['exclude'], true), 'Existing exclusions are merged');
    $check(get_term($root, 'product_cat') instanceof WP_Term, 'Direct category lookup remains available');
    $check(in_array($child, wc_get_product($product->get_id())->get_category_ids(), true), 'Product assignment is unchanged');
    update_option(PSU_Category_Menu_Visibility::OPTION, []);
    $check(in_array($root, wp_list_pluck(woocommerce_get_product_subcategories(0), 'term_id'), true), 'Clearing exclusions restores tiles');
    echo "Category tiles: $checks checks passed.\n";
} finally {
    if ($product && $product->get_id()) $product->delete(true);
    foreach (array_reverse($terms) as $id) wp_delete_term($id, 'product_cat');
    if ($original === false) delete_option(PSU_Category_Menu_Visibility::OPTION);
    else update_option(PSU_Category_Menu_Visibility::OPTION, $original);
    wp_set_current_user($user);
}
