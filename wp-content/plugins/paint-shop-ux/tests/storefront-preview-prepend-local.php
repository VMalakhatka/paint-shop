<?php
/** Include ONLY from the PHP development-server prepend, never a plugin loader.
 * A guest cart exists in memory for one GET; no cart/order/customer is persisted.
 */
if (PHP_SAPI !== 'cli-server' || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET'
    || ($_SERVER['HTTP_HOST'] ?? '') !== '127.0.0.1:8088') return;
if (($_GET['psu_style_fixture'] ?? '') !== 'cart') return;

$GLOBALS['wp_filter']['plugins_loaded'][20][] = ['accepted_args' => 0, 'function' => static function () {
    if (wp_get_environment_type() !== 'local') throw new RuntimeException('Local only');
    class PSU_Style_Memory_Session extends WC_Session {
        public function has_session() { return true; }
        public function set_customer_session_cookie($set) {}
        public function save_data() {}
        public function get_session_cookie() { return false; }
        public function destroy_session() {}
    }
    add_filter('woocommerce_session_handler', static fn() => PSU_Style_Memory_Session::class);
    add_filter('woocommerce_cart_session_initialize', '__return_false');
    add_action('wp_loaded', static function () {
        // Guest only; never borrow a real customer's persistent cart or addresses.
        if (is_user_logged_in()) throw new RuntimeException('Guest fixture only');
        $product = wc_get_product(wc_get_product_id_by_sku('KR-79406'));
        if (!$product) throw new RuntimeException('Representative local product missing');
        $product->set_virtual(true); // No carrier/rate requests in a style test.
        $price = (float) $product->get_price();
        WC()->cart->set_cart_contents(['psu-style-fixture' => [
            'key' => 'psu-style-fixture', 'product_id' => $product->get_id(),
            'variation_id' => 0, 'variation' => [], 'quantity' => 2, 'data' => $product,
            'data_hash' => wc_get_cart_item_data_hash($product),
            'line_subtotal' => 2 * $price, 'line_subtotal_tax' => 0,
            'line_total' => 2 * $price, 'line_tax' => 0,
            'line_tax_data' => ['subtotal' => [], 'total' => []],
        ]]);
        WC()->cart->set_totals(['subtotal' => 2 * $price, 'cart_contents_total' => 2 * $price, 'total' => 2 * $price]);
    }, 100);
}];
