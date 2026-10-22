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
$get = $_GET; $scripts = $GLOBALS['wp_scripts'] ?? null; $styles = $GLOBALS['wp_styles'] ?? null;
$loop = $GLOBALS['woocommerce_loop'] ?? null;
$enqueue_hooks = $GLOBALS['wp_filter']['wp_enqueue_scripts'];
try {
    $contexts = ['polimernij-modelin-cernit-number-one-56gr' => true, '56gr' => false, 'cernit-2' => false, 'skul-ptura-3' => false];
    foreach ($contexts as $slug => $expected) {
        $term = get_term_by('slug', $slug, 'product_cat');
        $check($term instanceof WP_Term, 'Existing category: ' . $slug);
        $q = new WP_Query(); $q->is_tax = true; $q->queried_object = $term; $q->queried_object_id = $term->term_id;
        $GLOBALS['wp_query'] = $q;
        $check(psu_catalog_uses_compact_titles() === $expected, 'Title context: ' . $slug);
        $check(psu_get_catalog_title('CERNIT 56 г | White | Extra') === ($expected ? 'White' : 'CERNIT 56 г White Extra'), 'Visible title: ' . $slug);
        $check(psu_get_catalog_title('Acrylic varnish 50 ml') === 'Acrylic varnish 50 ml', 'Name without separators: ' . $slug);
        $q->is_search = true;
        $check(!psu_catalog_uses_compact_titles(), 'Search uses complete title: ' . $slug);
    }
    $GLOBALS['wp_query'] = new WP_Query();
    $check(!psu_catalog_uses_compact_titles(), 'Shop uses complete title');
    $GLOBALS['wp_query']->is_single = true;
    $check(!psu_catalog_uses_compact_titles(), 'Product page uses complete title');
    $check(psu_get_compact_title('Full | Short') === 'Short', 'One separator remains supported');
    $check(psu_get_compact_title('Full | Short | Extra') === 'Short', 'Two separators remain supported');

    // Exercise the registered catalogue enqueue without unrelated application hooks.
    $catalog_enqueue = new WP_Hook();
    foreach ($enqueue_hooks->callbacks as $priority => $callbacks) {
        foreach ($callbacks as $callback) {
            if ($callback['function'] instanceof Closure
                && (new ReflectionFunction($callback['function']))->getFileName() === dirname(__DIR__) . '/inc/catalog-cards.php') {
                $catalog_enqueue->add_filter('wp_enqueue_scripts', $callback['function'], $priority, $callback['accepted_args']);
            }
        }
    }
    $check(!empty($catalog_enqueue->callbacks), 'Catalogue enqueue remains registered');
    $GLOBALS['wp_filter']['wp_enqueue_scripts'] = $catalog_enqueue;
    $GLOBALS['wp_scripts'] = new WP_Scripts(); $GLOBALS['wp_styles'] = new WP_Styles();
    unset($_GET['s'], $_GET['catalog_search']);
    $leaf = get_term_by('slug', 'number-one', 'product_cat');
    $parent = get_term_by('slug', '56gr', 'product_cat');
    $check($leaf instanceof WP_Term && $parent instanceof WP_Term, 'Current leaf and parent categories exist');
    $context_query = static function (WP_Term $term, int $paged = 0): WP_Query {
        $q = new WP_Query(); $q->is_tax = true;
        $q->queried_object = clone $term; $q->queried_object_id = $term->term_id;
        $q->set('paged', $paged);
        $GLOBALS['wp_query'] = $q;
        return $q;
    };
    foreach ([[$leaf, false, true], [$parent, false, false], [$leaf, true, false]] as [$term, $search, $expected]) {
        $q = $context_query($term); $q->is_search = $search;
        wp_dequeue_script('psu-catalog-context');
        do_action('wp_enqueue_scripts');
        $check(wp_script_is('psu-catalog-context', 'enqueued') === $expected,
            'Sticky context enqueue matches leaf/search scope: ' . $term->slug . '/' . (int) $search);
    }
    $context_query($leaf); $_GET['catalog_search'] = 'White';
    wp_dequeue_script('psu-catalog-context'); do_action('wp_enqueue_scripts');
    $check(!wp_script_is('psu-catalog-context', 'enqueued'), 'Category text search does not enqueue sticky context');
    unset($_GET['catalog_search']);
    $GLOBALS['wp_filter']['wp_enqueue_scripts'] = $enqueue_hooks;
    $render_description = static function (): string {
        ob_start(); do_action('woocommerce_archive_description'); return ob_get_clean();
    };
    wc_set_loop_prop('is_shortcode', false);
    $q = $context_query($leaf);
    $q->queried_object->description = '<p>Common <strong>product family</strong> 56 g</p>';
    $html = $render_description();
    $check(substr_count($html, 'class="term-description"') === 1, 'First page keeps exactly one native description');
    $q->set('paged', 2); $html = $render_description();
    $check(substr_count($html, 'class="term-description"') === 1, 'Second page restores exactly one common description');
    $check(str_contains($html, 'Common <strong>product family</strong> 56 g'), 'Paginated context preserves existing text and emphasis');
    $q->set('paged', 1);
    $check(substr_count($render_description(), 'class="term-description"') === 1, 'Explicit page one does not lose or duplicate context');
    $q->set('paged', 2);
    foreach ([
        '<p><img src="https://example.com/image.jpg" alt="Illustration"></p>',
        '<p>First paragraph</p><p>Second paragraph</p>',
        '<p>Context <a href="https://example.com/">link</a></p>',
        '<ul><li>List</li></ul>',
        '',
    ] as $description) {
        $q->queried_object->description = $description;
        $check($render_description() === '', 'Paginated rich or empty description remains omitted: ' . wp_strip_all_tags($description));
    }
    $q->queried_object->description = '<h3>Shared product family</h3>';
    $check(substr_count($render_description(), 'class="term-description"') === 1, 'One textual heading is supported');
    $q->queried_object->description = '<p>Shared product family</p>';
    $q->is_search = true;
    $check($render_description() === '', 'Search does not restore compact context');
    $q->is_search = false; $_GET['catalog_search'] = 'White';
    $check($render_description() === '', 'Category text search does not restore compact context');
    unset($_GET['catalog_search']);
    wc_set_loop_prop('is_shortcode', true);
    $check($render_description() === '', 'Shortcode loop does not restore compact context');
    wc_set_loop_prop('is_shortcode', false);
    $q = $context_query($parent, 2); $q->queried_object->description = '<p>Parent description</p>';
    $check($render_description() === '', 'Parent category does not restore compact context');
    $GLOBALS['wp_query'] = new WP_Query();
    $check($render_description() === '', 'Outside catalogue context no description is emitted');
    $check(get_term($leaf->term_id, 'product_cat')->description === $leaf->description, 'Description fixture did not change stored category');

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
    $_GET = $get; $GLOBALS['wp_scripts'] = $scripts; $GLOBALS['wp_styles'] = $styles;
    $GLOBALS['wp_filter']['wp_enqueue_scripts'] = $enqueue_hooks;
    if ($loop === null) unset($GLOBALS['woocommerce_loop']); else $GLOBALS['woocommerce_loop'] = $loop;
    if ($cookie === null) unset($_COOKIE['pc_alloc_pref']); else $_COOKIE['pc_alloc_pref'] = $cookie;
}
