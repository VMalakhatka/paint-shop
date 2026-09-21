<?php
/** Run with wp eval-file on paint.local. All fixtures stay in memory. */
if (!defined('WP_CLI') || !WP_CLI || wp_parse_url(home_url(), PHP_URL_HOST) !== 'paint.local') {
    throw new RuntimeException('Local WP CLI only.');
}

function retail_expect($expected, $actual, string $label): void {
    if ($expected !== $actual) throw new RuntimeException($label . ': ' . wp_json_encode($actual));
}

$original_user = $GLOBALS['current_user'];
$original_cart = WC()->cart;
$original_session = WC()->session;
$original_cache = $GLOBALS['slu_location_stock_request_cache'] ?? [];
try {
    $GLOBALS['current_user'] = new WP_User();
    WC()->session = new class extends WC_Session {};
    WC()->cart = new class {
        public $cart_contents = [];
        public function get_cart() { return $this->cart_contents; }
        public function get_cart_item($key) { return $this->cart_contents[$key]; }
    };
    $terms = get_terms(['taxonomy'=>'location','hide_empty'=>false]);
    if (is_wp_error($terms) || count($terms) < 2) throw new RuntimeException('Two stores required.');
    [$first, $second] = array_map(static function ($term) { return (int) $term->term_id; }, array_slice($terms, 0, 2));
    $products = wc_get_products(['limit'=>1,'status'=>'publish','type'=>'simple']);
    if (!$products) throw new RuntimeException('One published simple product required.');
    $product = $products[0];
    $id = $product->get_id();
    $GLOBALS['slu_location_stock_request_cache'][$id] = [
        $first=>['name'=>$terms[0]->name,'qty'=>2],
        $second=>['name'=>$terms[1]->name,'qty'=>5],
    ];
    $item = ['data'=>$product,'product_id'=>$id,'quantity'=>2,'pc_retail_location_id'=>$first];
    WC()->cart->cart_contents = ['first'=>$item];
    WC()->session->set('pc_alloc_pref', ['mode'=>'auto','term_id'=>$second]);
    retail_expect(5, slu_available_for_add($product), 'PDP ignores other-store quantity');
    retail_expect(5, PaintUX\Catalog\pcux_available_for_add($product), 'catalog ignores other-store quantity');
    retail_expect(2, pc_cartguard_get_allowed_qty_for_cart($product, 2, $item, 'first'), 'cart cap stays pinned');
    retail_expect(false, apply_filters('woocommerce_update_cart_validation', true, 'first', $item, 3), 'cannot use other-store stock to increase line');
    retail_expect(true, apply_filters('woocommerce_update_cart_validation', true, 'first', $item, 2), 'valid pinned quantity accepted');
    pc_recalc_alloc_plan_for_cart_item('first');
    retail_expect([$first=>2], WC()->cart->cart_contents['first']['pc_alloc_plan'], 'recalc keeps store');
    $GLOBALS['slu_location_stock_request_cache'][$id][$first]['qty'] = 0;
    retail_expect(0, pc_cartguard_get_allowed_qty_for_cart($product, 2, $item, 'first'), 'zero stock has no fallback');
    retail_expect([], pc_cart_item_alloc_plan($item), 'zero stock cannot allocate elsewhere');
    $panel = slu_render_stock_panel($product, ['retail_location_id'=>$first]);
    retail_expect(true, strpos($panel, esc_html($terms[0]->name) . ' — 0') !== false, 'selected zero remains visible');
    retail_expect(true, strpos($panel, esc_html($terms[1]->name) . ' — 5') !== false, 'other store remains visible');
    retail_expect(true, apply_filters('woocommerce_is_purchasable', true, $product), 'global store does not invalidate a cart product');

    $user = new WP_User(); $user->ID = PHP_INT_MAX; $user->roles = ['partner'];
    $GLOBALS['current_user'] = $user;
    retail_expect(true, pc_alloc_allows_multiple_locations(), 'wholesale retains modes');
    retail_expect([$second=>5], pc_cart_item_alloc_plan($item, 5), 'wholesale keeps automatic allocation');
    echo "Retail cart integration tests passed (memory-only fixtures).\n";
} finally {
    $GLOBALS['current_user'] = $original_user;
    WC()->cart = $original_cart;
    WC()->session = $original_session;
    $GLOBALS['slu_location_stock_request_cache'] = $original_cache;
}
