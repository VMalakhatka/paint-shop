<?php
// Local-only synthetic products; all products/terms are removed in finally.
if (!defined('WP_CLI') || !WP_CLI || wp_get_environment_type() !== 'local'
    || parse_url(home_url(), PHP_URL_HOST) !== 'paint.local') throw new RuntimeException('Local only');
$ids = []; $terms = []; $checks = 0; $user_id = get_current_user_id();
$check = static function ($ok, $label) use (&$checks) { if (!$ok) throw new RuntimeException($label); $checks++; };
$find = static function ($input) { return PSU_Catalog_Search::products(array_merge(['catalog_search'=>'psusearchfixture'], $input)); };
try {
    foreach (['psusearch-parent','psusearch-child','psusearch-other'] as $slug) {
        $term = wp_insert_term($slug, 'product_cat', ['slug'=>$slug, 'parent'=>$slug === 'psusearch-child' ? $terms[0] : 0]);
        if (is_wp_error($term)) throw new RuntimeException('Fixture term exists; inspect before retry');
        $terms[] = $term['term_id'];
    }
    foreach (['exact','description','outside','hidden','draft','password','barcode'] as $kind) {
        $product = new WC_Product_Simple();
        $product->set_name($kind === 'exact' ? 'Exact fixture' : 'Product '.$kind);
        $product->set_description('psusearchfixture indexedonlyneedle');
        $product->set_sku($kind === 'exact' ? 'psusearchfixture' : 'psusearch-'.$kind);
        $product->set_regular_price('100');
        $product->set_stock_status('instock');
        $product->set_status($kind === 'draft' ? 'draft' : 'publish');
        $product->set_category_ids([$kind === 'outside' ? $terms[2] : $terms[1]]);
        if ($kind === 'hidden') $product->set_catalog_visibility('hidden');
        $id = $product->save(); $ids[$kind] = $id;
        update_post_meta($id, '_edin_izmer', $kind === 'description' ? '125мл' : '250мл');
        if ($kind === 'password') wp_update_post(['ID'=>$id,'post_password'=>'fixture-password']);
        if ($kind === 'barcode') update_post_meta($id, '_gtin', '0012345678905');
        relevanssi_insert_edit($id);
    }
    $result = $find(['product_cat'=>'psusearch-parent']);
    $found = array_column($result, 'id');
    $check($found[0] === $ids['exact'], 'Exact SKU first');
    $check(in_array($ids['description'], $found, true), 'Description indexed by Relevanssi');
    $check(!array_intersect([$ids['outside'],$ids['hidden'],$ids['draft'],$ids['password']], $found), 'Scope and visibility protected');
    $check(in_array($ids['description'], array_column($find(['catalog_search'=>'indexedonlyneedle','product_cat'=>'psusearch-parent']), 'id'), true), 'Indexed-only field remains searchable');
    $check(array_column($find(['unit'=>'125мл','product_cat'=>'psusearch-parent']), 'id') === [$ids['description']], 'Relevanssi unit restriction');
    $check(!$find(['unit'=>'missing-unit']), 'Unknown unit fails closed');
    $check(!$find(['min_price'=>'101']), 'Price intersection');
    $check(!$find(['brand'=>'missing-supplier']), 'Supplier intersection');
    $check(!$find(['location'=>['missing-location']]), 'Warehouse intersection');
    foreach (['brand'=>'product_brand','location'=>'location'] as $key=>$taxonomy) {
        $term = get_terms(['taxonomy'=>$taxonomy,'hide_empty'=>false,'number'=>1])[0];
        wp_set_object_terms($ids['exact'], [$term->term_id], $taxonomy);
        $value = $key === 'location' ? [$term->slug] : $term->slug;
        $check(array_column($find([$key=>$value,'catalog_search'=>'indexedonlyneedle']), 'id') === [$ids['exact']], 'Existing '.$taxonomy.' restricts indexed search');
    }
    $check(array_column($find(['catalog_search'=>'0012345678905']), 'id') === [$ids['barcode']], 'Leading-zero barcode');
    $check(!$find(['catalog_search'=>['invalid']]) && !$find(['catalog_search'=>'x']), 'Malformed and short search rejected');
    $product = wc_get_product($ids['description']); $product->set_stock_status('outofstock'); $product->save();
    $check(!in_array($ids['description'], array_column($find(['in_stock'=>'1']), 'id'), true), 'Availability intersection');
    $users = get_users(['role__in'=>pc_wholesale_customer_roles(),'number'=>1]);
    $check((bool)$users, 'Wholesale user available locally');
    $user = $users[0];
    update_post_meta($ids['exact'], '_wpc_price_role_'.$user->roles[0], '73.12');
    wp_set_current_user(0);
    $guest = $find([])[0]['price'];
    wp_set_current_user($user->ID);
    $wholesale = $find([])[0]['price'];
    $check($guest !== $wholesale && str_contains(strip_tags($wholesale), '73,12'), 'Customer-specific price; no shared price cache');
    $query = new WP_Query(); $query->parse_query(['s'=>'indexedonlyneedle','_psu_catalog_query'=>true]);
    $GLOBALS['wp_the_query'] = $query;
    $check(!psu_catalog_use_supplier_order($query), 'Supplier order cannot override indexed relevance');
    echo wp_json_encode(['passed'=>$checks]), "\n";
} finally {
    wp_set_current_user($user_id);
    foreach ($ids as $id) wp_delete_post($id, true);
    foreach (array_reverse($terms) as $id) wp_delete_term($id, 'product_cat');
}
