<?php
if (!defined('WP_CLI') || !WP_CLI || wp_get_environment_type() !== 'local'
    || parse_url(home_url(), PHP_URL_HOST) !== 'paint.local') throw new RuntimeException('Local only');
$ids = []; $terms = []; $checks = 0;
$saved = [$_GET, $GLOBALS['wp_query'], $GLOBALS['wp_the_query']];
$check = static function ($ok, $label) use (&$checks) { if (!$ok) throw new RuntimeException($label); $checks++; };
try {
    foreach (['psuclean-root','psuclean-child','psuclean-other'] as $slug) {
        $result = wp_insert_term($slug, 'product_cat', ['slug'=>$slug,'parent'=>$slug === 'psuclean-child' ? $terms[0][0] : 0]);
        if (is_wp_error($result)) throw new RuntimeException('Fixture exists; inspect before retry');
        $terms[] = [$result['term_id'],'product_cat'];
    }
    $attr = wp_insert_term('50  мл.', 'pa_edin_izmer');
    if (is_wp_error($attr)) $attr_id = (int)$attr->get_error_data('term_exists');
    else { $attr_id = $attr['term_id']; $terms[] = [$attr_id,'pa_edin_izmer']; }
    $units = ['compact'=>'50мл','space'=>'50 мл','nbsp'=>"50\u{00a0}мл",'attribute'=>'','different'=>'500мл',
        'priority'=>'125мл','piece'=>'шт','dot'=>'шт.','decimal'=>'2,5г','outside'=>'999мл','hidden'=>'888мл'];
    foreach ($units as $kind=>$unit) {
        $p = new WC_Product_Simple(); $p->set_name('psucleanfixture '.$kind); $p->set_status('publish');
        $p->set_regular_price('100'); $p->set_stock_status('instock');
        $p->set_category_ids([$kind === 'outside' ? $terms[2][0] : $terms[1][0]]);
        if ($kind === 'hidden') $p->set_catalog_visibility('hidden');
        $id = $p->save(); $ids[$kind] = $id;
        if ($unit !== '') update_post_meta($id, '_edin_izmer', $unit);
        if (in_array($kind, ['attribute','priority'], true)) wp_set_object_terms($id, [$attr_id], 'pa_edin_izmer');
        relevanssi_insert_edit($id);
    }
    $root = get_term($terms[0][0], 'product_cat');
    $options = psu_catalog_unit_options(psu_catalog_unit_values($root));
    foreach (['location','product_brand'] as $taxonomy) {
        $all_terms = get_terms(['taxonomy'=>$taxonomy,'hide_empty'=>false]);
        $check(count($all_terms) >= 2, 'Local scope terms available');
        wp_set_object_terms($ids['compact'], [$all_terms[0]->term_id], $taxonomy);
        wp_set_object_terms($ids['outside'], [$all_terms[1]->term_id], $taxonomy);
        $scoped = psu_catalog_scoped_terms($all_terms, $taxonomy, $root);
        $check(wp_list_pluck($scoped,'term_id') === [$all_terms[0]->term_id], 'Only descendant product terms offered: '.$taxonomy);
        $selected = psu_catalog_scoped_terms($all_terms, $taxonomy, $root, [$all_terms[1]->slug]);
        $check(count($selected) === 2, 'Out-of-scope selection stays clearable');
    }
    $check(count(array_keys($options, '50 мл', true)) === 1, 'One normalized volume option');
    $check(count(array_keys($options, 'шт', true)) === 1 && !in_array('шт.', $options, true), 'One piece option');
    $check(!in_array('999 мл', $options, true) && !in_array('888 мл', $options, true), 'Category and visibility scope');
    $check(psu_catalog_unit_label('50МЛ.') === '50 мл', 'Case and punctuation');
    $check(psu_catalog_unit_label('2,5г') === '2,5 г' && psu_catalog_unit_label('25г') === '25 г', 'Decimal quantity retained');
    $check(psu_catalog_unit_label('т50мл') === 'т50мл' && psu_catalog_unit_label('0,05л') !== '50 мл', 'No conversion or ambiguous prefix merging');
    $expected = array_intersect_key($ids, array_flip(['compact','space','nbsp','attribute']));
    foreach (['50 мл','50мл','50МЛ.'] as $selection) {
        $rows = PSU_Catalog_Search::products(['catalog_search'=>'psucleanfixture','product_cat'=>$root->slug,'unit'=>$selection]);
        $found = array_column($rows,'id'); sort($found); $want = array_values($expected); sort($want);
        $check($found === $want, 'Indexed search matches all aliases: '.$selection);
    }
    $query = new WP_Query(['post_type'=>'product','post_status'=>'publish','posts_per_page'=>-1,'_psu_suggest'=>true,'_psu_unit_filter'=>'50 мл']);
    $found = wp_list_pluck($query->posts,'ID');
    $check(!array_diff($expected,$found) && !in_array($ids['priority'],$found,true), 'SQL matches both paths and meta remains authoritative');
    foreach ($units as $kind=>$unit) $check((string)get_post_meta($ids[$kind], '_edin_izmer', true) === $unit, 'Stored unit unchanged: '.$kind);
    $q = new WP_Query(); $q->queried_object=$root; $q->queried_object_id=$root->term_id;
    $GLOBALS['wp_query'] = $GLOBALS['wp_the_query'] = $q;
    $_GET = ['unit'=>'50мл','location'=>['kiev','odessa'],'in_stock'=>'1','catalog_search'=>'psucleanfixture',
        'min_price'=>'0','max_price'=>'150','brand'=>'test-brand','pp'=>'12','orderby'=>'price','paged'=>'3','s'=>'old-query'];
    $chips = psu_catalog_active_filters();
    $check(count($chips) === 8, 'All applied restrictions represented, one chip per warehouse');
    foreach ($chips as $chip) {
        parse_str(parse_url($chip['url'], PHP_URL_QUERY), $args);
        $check(!isset($args['paged']) && $args['pp'] === '12' && $args['orderby'] === 'price' && $args['product_cat'] === $root->slug, 'Removal preserves category/presentation and resets page');
        if ($chip['key'] === 'location') $check(count($args['location']) === 1 && $args['unit'] === '50мл', 'One warehouse removed, unit retained');
        else $check(!isset($args[$chip['key']]) && count($args['location']) === 2, 'Only selected restriction removed');
        if ($chip['key'] === 'catalog_search') $check(!isset($args['s']), 'Legacy s cannot restore removed search');
    }
    parse_str(parse_url(psu_catalog_clear_filters_url(), PHP_URL_QUERY), $cleared);
    $check($cleared === ['orderby'=>'price','pp'=>'12'], 'Clear all removes every restriction, not presentation');
    ob_start(); psu_render_active_filters(); $html=ob_get_clean();
    $check(str_contains($html,'50 мл') && str_contains($html,'data-filter="unit"'), 'Normalized applied chip visible');
    $_GET = ['catalog_search'=>'<script>alert(1)</script>'];
    ob_start(); psu_render_active_filters(); $html=ob_get_clean();
    $check(!str_contains($html,'<script>'), 'Labels cannot inject HTML');
    echo wp_json_encode(['passed'=>$checks]), "\n";
} finally {
    [$_GET,$GLOBALS['wp_query'],$GLOBALS['wp_the_query']]=$saved;
    foreach ($ids as $id) wp_delete_post($id,true);
    foreach (array_reverse($terms) as [$id,$taxonomy]) wp_delete_term($id,$taxonomy);
}
