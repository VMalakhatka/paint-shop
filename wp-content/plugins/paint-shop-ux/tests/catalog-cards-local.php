<?php
// Read-only local integration test; synthetic stock lives only in request memory.
if (!defined('WP_CLI') || !WP_CLI || wp_get_environment_type() !== 'local'
    || parse_url(home_url(), PHP_URL_HOST) !== 'paint.local') throw new RuntimeException('Local only');
$checks = 0;
$check = static function ($ok, $label) use (&$checks) {
    if (!$ok) throw new RuntimeException($label);
    $checks++;
};
$query = $GLOBALS['wp_query']; $user = get_current_user_id(); $cookie = $_COOKIE['pc_alloc_pref'] ?? null;
$session = WC()->session; $stock_cache = $GLOBALS['slu_location_stock_request_cache'] ?? [];
try {
    $contexts = ['polimernij-modelin-cernit-number-one-56gr' => true, '56gr' => true, 'cernit-2' => false, 'skul-ptura-3' => false];
    foreach ($contexts as $slug => $expected) {
        $term = get_term_by('slug', $slug, 'product_cat');
        $check($term instanceof WP_Term, 'Existing category: ' . $slug);
        $q = new WP_Query(); $q->is_tax = true; $q->queried_object = $term; $q->queried_object_id = $term->term_id;
        $GLOBALS['wp_query'] = $q;
        $check(psu_catalog_uses_compact_titles() === $expected, 'Title context: ' . $slug);
        $check(psu_get_catalog_title('CERNIT 56 г | White | Extra') === ($expected ? 'White' : 'CERNIT 56 г White Extra'), 'Visible title: ' . $slug);
        $q->is_search = true;
        $check(!psu_catalog_uses_compact_titles(), 'Search uses complete title: ' . $slug);
    }
    $GLOBALS['wp_query'] = new WP_Query();
    $check(!psu_catalog_uses_compact_titles(), 'Shop uses complete title');
    $GLOBALS['wp_query']->is_single = true;
    $check(!psu_catalog_uses_compact_titles(), 'Product page uses complete title');
    $check(psu_get_compact_title('Full | Short') === 'Short', 'One separator remains supported');
    $check(psu_get_compact_title('Full | Short | Extra') === 'Short', 'Two separators remain supported');

    $locations = get_terms(['taxonomy' => 'location', 'hide_empty' => false]);
    $check(!is_wp_error($locations) && count($locations) >= 2, 'Two local warehouses');
    [$a, $b] = array_slice($locations, 0, 2);
    $product = new WC_Product_Simple();
    $stocks = [$a->term_id => ['name' => $a->name, 'qty' => 4], $b->term_id => ['name' => $b->name, 'qty' => 2]];
    $GLOBALS['slu_location_stock_request_cache'][0] = $stocks;
    $opts = ['wrap_class' => 'slu-stock-mini', 'hide_when_zero' => true];
    $catalog_line = static function ($row) { return esc_html($row['name']) . '–' . max(0, (int) $row['qty']); };
    WC()->session = null;
    $wholesale = get_users(['role__in' => pc_wholesale_customer_roles(), 'number' => 1, 'fields' => 'ID']);
    $check((bool) $wholesale, 'Local wholesale role available');
    wp_set_current_user($wholesale[0]);
    foreach (['auto', 'manual', 'single'] as $mode) {
        $_COOKIE['pc_alloc_pref'] = wp_json_encode(['mode' => $mode, 'term_id' => $b->term_id]);
        $view = pc_build_stock_view($product);
        $preferred = $mode === 'single' ? $b->term_id : array_key_first($view['ordered']);
        $html = slu_render_stock_panel($product, $opts);
        $check(substr_count($html, 'class="slu-stock-location') === 2, $mode . ': both warehouses visible');
        $check(preg_match('/class="slu-stock-location is-preferred"[^>]*>' . preg_quote($catalog_line($stocks[$preferred]), '/') . '<\/span>/', $html), $mode . ': preferred warehouse');
        $check(!str_contains($html, '<strong>') && !str_contains($html, 'slu-stock-total'), $mode . ': no visible labels or total');
    }
    wp_set_current_user(0);
    $_COOKIE['pc_alloc_pref'] = wp_json_encode(['mode' => 'single', 'term_id' => $a->term_id]);
    $GLOBALS['slu_location_stock_request_cache'][0][$a->term_id]['qty'] = 0;
    $html = slu_render_stock_panel($product, $opts);
    $check(str_contains($html, 'is-preferred') && str_contains($html, $catalog_line(['name' => $a->name, 'qty' => 0])), 'Retail selected empty warehouse retained');
    $check(str_contains($html, $catalog_line($stocks[$b->term_id])), 'Retail other warehouse remains informational');
    $check(str_contains(slu_render_stock_panel($product), 'slu-stock-total'), 'Product detail retains full panel');
    $GLOBALS['slu_location_stock_request_cache'][0][$b->term_id]['qty'] = 0;
    $check(slu_render_stock_panel($product, $opts) === '', 'All-zero mini panel hides cleanly');
    wp_set_current_user($wholesale[0]);
    foreach (['uk' => 'Назва в каталозі займає три рядки', 'ru_RU' => 'Название в каталоге занимает три строки'] as $locale => $translated) {
        $switched = switch_to_locale($locale);
        try {
            ob_start(); pc_wholesale_help_render_endpoint(); $help = ob_get_clean();
            $check(str_contains($help, $translated), 'Wholesale help translated: ' . $locale);
            $check(str_contains($help, 'catalog-cards-mobile-2026-10-07.jpg'), 'Wholesale help screenshot: ' . $locale);
        } finally { if ($switched) restore_previous_locale(); }
    }
    echo wp_json_encode(['passed' => $checks]), "\n";
} finally {
    $GLOBALS['wp_query'] = $query; wp_set_current_user($user); WC()->session = $session;
    $GLOBALS['slu_location_stock_request_cache'] = $stock_cache;
    if ($cookie === null) unset($_COOKIE['pc_alloc_pref']); else $_COOKIE['pc_alloc_pref'] = $cookie;
}
