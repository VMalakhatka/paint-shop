<?php
if (!defined('WP_CLI') || !WP_CLI || wp_get_environment_type() !== 'local') throw new RuntimeException('Local CLI only');
$saved = [$_GET, $_COOKIE, $GLOBALS['wp_the_query']]; $results = [];
$cases = [
    ['default', [], [], 24],
    ['desktop cookie ignored', [], ['psu_cols'=>6,'psu_rows'=>3], 24],
    ['mobile cookie ignored', [], ['psu_cols'=>2,'psu_rows'=>2], 24],
    ['explicit 12', ['pp'=>'12'], ['psu_cols'=>6,'psu_rows'=>3], 12],
    ['explicit 24', ['pp'=>'24'], [], 24],
    ['explicit 48', ['pp'=>'48'], [], 48],
    ['legacy alias', ['per_page'=>'20'], [], 20],
    ['pp takes precedence', ['pp'=>'12','per_page'=>'48'], [], 12],
    ['invalid pp uses valid alias', ['pp'=>'no','per_page'=>'20'], [], 20],
    ['invalid inputs', ['pp'=>['48'],'per_page'=>'201'], [], 24],
    ['fraction is invalid', ['pp'=>'12.5'], [], 24],
];
try {
    foreach ($cases as [$name,$get,$cookies,$expected]) {
        $_GET = $get; $_COOKIE = $cookies;
        $actual = psufp_calc_per_page();
        $results[] = ['test'=>$name,'expected'=>$expected,'actual'=>$actual,'pass'=>$actual===$expected];
    }
    $_GET = ['pp'=>'48'];
    foreach (['archive','category','search','other-search','single','secondary','feed'] as $context) {
        $query = new WP_Query();
        $query->set('posts_per_page', 7); $query->set('posts_per_archive_page', 9);
        $GLOBALS['wp_the_query'] = $context === 'secondary' ? new WP_Query() : $query;
        if (in_array($context, ['archive','secondary','feed'], true)) {
            $query->is_post_type_archive = true; $query->set('post_type', 'product');
        }
        if ($context === 'feed') $query->is_feed = true;
        if ($context === 'category') {
            $query->is_tax = true;
            $query->queried_object = get_terms(['taxonomy'=>'product_cat','number'=>1,'hide_empty'=>false])[0];
        }
        if (in_array($context, ['search','other-search'], true)) {
            $query->is_search = true; $query->set('post_type', $context === 'search' ? 'product' : 'post');
        }
        psufp_apply_page_size($query);
        $expected = in_array($context, ['archive','category','search'], true) ? [48,48] : [7,9];
        $actual = [$query->get('posts_per_page'),$query->get('posts_per_archive_page')];
        $results[] = ['test'=>'query '.$context,'expected'=>$expected,'actual'=>$actual,'pass'=>$actual===$expected];
    }
} finally { [$_GET,$_COOKIE,$GLOBALS['wp_the_query']] = $saved; }
echo wp_json_encode($results, JSON_PRETTY_PRINT), "\n";
if (array_filter($results, static function ($r) { return !$r['pass']; })) WP_CLI::halt(1);
