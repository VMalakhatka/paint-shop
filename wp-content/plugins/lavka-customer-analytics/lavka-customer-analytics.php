<?php
/**
 * Plugin Name: Lavka Customer Analytics
 * Description: Consent-gated storefront events and anonymous Woo order segmentation.
 * Version: 0.1.0
 * Requires PHP: 8.0
 * Requires Plugins: woocommerce
 * Text Domain: lavka-customer-analytics
 * Domain Path: /languages
 */
defined('ABSPATH') || exit;

add_action('before_woocommerce_init', static function () {
    if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});

final class Lavka_Customer_Analytics {
    const VERSION = '0.1.0';
    const QUEUE = 'lca_event_queue';

    public static function settings(): array {
        $raw = (array) get_option('lca_settings', []);
        $id = (string) ($raw['measurement_id'] ?? '');
        return ['enabled' => in_array($raw['enabled'] ?? false, [true, 1, '1'], true) && preg_match('/^G-[A-Z0-9]{6,20}$/', $id),
            'measurement_id' => $id, 'debug' => !empty($raw['debug'])];
    }

    public static function enabled(): bool { return (bool) self::settings()['enabled']; }

    public static function staff(): bool {
        return current_user_can('manage_woocommerce') || current_user_can('manage_options') || current_user_can('edit_products')
            || current_user_can('manage_lavka_prices') || current_user_can('edit_lavka_workshops');
    }

    public static function consent(): bool {
        return ($_COOKIE['lca_consent'] ?? '') === 'granted';
    }

    public static function segment(): string {
        if (self::staff()) { return 'staff'; }
        $roles = function_exists('pc_wholesale_customer_roles') ? pc_wholesale_customer_roles() : ['opt', 'partner', 'opt_osn', 'schule'];
        return array_intersect($roles, (array) wp_get_current_user()->roles) ? 'wholesale' : 'retail';
    }

    /** Never export URLs from private routes, query strings or order receipt identifiers. */
    public static function public_path(string $url): string {
        $path = (string) wp_parse_url($url, PHP_URL_PATH);
        if (!$path || preg_match('~(?:wp-admin|wp-json|wp-login|my-account|miy-akaunt|order-pay|order-received|view-order|customer|balance|documents)~i', $path)) {
            return '/';
        }
        return substr($path, 0, 300);
    }

    public static function token($value): string {
        return is_string($value) && preg_match('/^[a-zA-Z0-9._-]{1,60}$/', $value) && !preg_match('/\d{5,}/', $value) ? $value : '';
    }

    public static function item(WC_Product $product, float $quantity = 1, ?float $price = null): array {
        return ['item_id' => $product->get_sku() ?: 'woo:' . $product->get_id(), 'item_name' => $product->get_name(),
            'price' => round($price ?? (float) wc_get_price_excluding_tax($product), 4), 'quantity' => $quantity];
    }

    public static function ecommerce(array $items): array {
        return ['currency' => get_woocommerce_currency(), 'value' => round(array_sum(array_map(static fn($i) => $i['price'] * $i['quantity'], $items)), 2),
            'items' => array_slice($items, 0, 200), 'items_truncated' => count($items) > 200];
    }

    public static function cart_items(): array {
        $items = [];
        if (!WC()->cart) { return $items; }
        foreach (WC()->cart->get_cart() as $row) {
            if (($row['data'] ?? null) instanceof WC_Product && $row['quantity'] > 0) {
                $price = isset($row['line_total']) ? (float) $row['line_total'] / $row['quantity'] : null;
                $items[] = self::item($row['data'], (float) $row['quantity'], $price);
            }
        }
        return $items;
    }

    public static function collection_request(): bool {
        if (!self::enabled() || !self::consent() || self::staff() || !function_exists('WC') || !WC()->session || defined('WP_CLI') && WP_CLI) { return false; }
        // Checkout/order payment cart-emptying is not a customer removal.
        if (!empty($_REQUEST['wc-ajax']) && $_REQUEST['wc-ajax'] === 'checkout') { return false; }
        if (wp_doing_ajax() || is_admin()) {
            $action = isset($_REQUEST['action']) ? sanitize_key(wp_unslash($_REQUEST['action'])) : '';
            return in_array($action, ['pc_cart_adjust', 'pc_cart_update_item', 'pc_bulk_add_to_cart', 'pcoe_import_cart', 'pcoe_draft_to_cart',
                    'pcoe_draft_folio_apply', 'pcoe_waitlist', 'pc_folio_customer_document_repeat'], true)
                || in_array($_REQUEST['wc-ajax'] ?? '', ['add_to_cart', 'remove_from_cart'], true);
        }
        return !is_admin() && !is_order_received_page() && !is_checkout_pay_page();
    }

    /** Queue actual Woo mutations, not button clicks. Nothing sends to Google on the server. */
    public static function queue(string $name, array $items): void {
        if (!self::collection_request() || !$items) { return; }
        $queue = (array) WC()->session->get(self::QUEUE, []);
        $queue[] = ['id' => wp_generate_uuid4(), 'name' => $name, 'params' => self::ecommerce($items), 'time' => time()];
        WC()->session->set(self::QUEUE, array_slice($queue, -100));
    }

    public static function add_to_cart($key, $id, $quantity, $variation): void {
        $row = WC()->cart ? WC()->cart->get_cart_item($key) : [];
        $product = $row['data'] ?? wc_get_product($variation ?: $id);
        if ($product instanceof WC_Product) { self::queue('add_to_cart', [self::item($product, (float) $quantity)]); }
    }

    public static function quantity($key, $quantity, $old, $cart): void {
        $row = $cart->get_cart_item($key);
        if ($quantity == $old || !(($row['data'] ?? null) instanceof WC_Product)) { return; }
        self::queue($quantity > $old ? 'add_to_cart' : 'remove_from_cart', [self::item($row['data'], abs((float) $quantity - (float) $old))]);
    }

    public static function removed($key, $cart): void {
        $row = $cart->removed_cart_contents[$key] ?? [];
        if (($row['data'] ?? null) instanceof WC_Product) { self::queue('remove_from_cart', [self::item($row['data'], (float) $row['quantity'])]); }
    }

    public static function emptied(): void {
        if (self::collection_request()) { self::queue('remove_from_cart', self::cart_items()); }
    }

    public static function flush(): void {
        check_ajax_referer('lca_events', 'nonce');
        nocache_headers();
        if (!self::enabled() || self::staff() || !function_exists('WC')) { wp_send_json_success(['events' => []]); }
        if (!WC()->session && function_exists('wc_load_cart')) { wc_load_cart(); }
        if (!WC()->session) { wp_send_json_success(['events' => []]); }
        if (!self::consent()) { WC()->session->set(self::QUEUE, []); wp_send_json_success(['events' => []]); }
        $events = array_values(array_filter((array) WC()->session->get(self::QUEUE, []), static fn($event) => ($event['time'] ?? 0) > time() - 1800));
        WC()->session->set(self::QUEUE, []); // At-most-once hand-off; network failure can lose events, never retry writes.
        wp_send_json_success(['events' => $events, 'segment' => self::segment()]);
    }

    public static function snapshot(WC_Order $order): void {
        if (!self::enabled() || !self::consent() || self::staff()) { return; }
        $order->update_meta_data('_lca_segment', self::segment());
        $order->update_meta_data('_lca_consent', 'granted');
        // Source labels are untrusted. Only short public campaign tokens are stored; never full URLs/search.
        $raw = $_POST['lca_attribution'] ?? '';
        $data = is_string($raw) && strlen($raw) <= 1024 ? json_decode(wp_unslash($raw), true) : null;
        if (is_array($data)) {
            foreach (['source', 'medium', 'campaign'] as $field) {
                $order->update_meta_data('_lca_' . $field, self::token($data[$field] ?? ''));
            }
            $device = $data['device'] ?? '';
            if (in_array($device, ['mobile', 'tablet', 'desktop'], true)) { $order->update_meta_data('_lca_device', $device); }
        }
        // Woo saves this order after the checkout_create_order hook.
    }

    public static function payment_complete($id): void {
        if (!self::enabled()) { return; }
        $order = wc_get_order($id);
        if ($order && $order->get_meta('_lca_consent') === 'granted' && !$order->get_meta('_lca_payment_confirmed_at')) {
            $order->update_meta_data('_lca_payment_confirmed_at', gmdate('c'));
            $order->save_meta_data();
        }
    }

    /** Children inherit attribution only, never their parent's payment evidence. */
    public static function inherit($order): void {
        if (!self::enabled() || !($order instanceof WC_Order)) { return; }
        $id = (int) ($order->get_meta('_folio_parent_order_id') ?: $order->get_meta('_folio_split_from_order_id'));
        $root = $id && $id !== $order->get_id() ? wc_get_order($id) : false;
        if (!$root || $root->get_customer_id() !== $order->get_customer_id() || $root->get_meta('_lca_consent') !== 'granted') { return; }
        foreach (['segment', 'consent', 'source', 'medium', 'campaign', 'device'] as $field) {
            if (!$order->meta_exists('_lca_' . $field)) { $order->update_meta_data('_lca_' . $field, $root->get_meta('_lca_' . $field)); }
        }
    }

    public static function loop_item(): void {
        if (!self::enabled() || self::staff()) { return; }
        global $product;
        if ($product instanceof WC_Product) {
            echo '<span hidden data-lca-item="' . esc_attr(wp_json_encode(self::item($product))) . '"></span>';
        }
    }

    public static function receipt_events(): array {
        $id = absint(get_query_var('order-received'));
        $order = $id ? wc_get_order($id) : false;
        $key = isset($_GET['key']) ? wc_clean(wp_unslash($_GET['key'])) : '';
        // Receipt exception is key/owner-protected; page URL/key/address never exported.
        if (!$order || !$key || !hash_equals($order->get_order_key(), $key)
            || ($order->get_customer_id() && $order->get_customer_id() !== get_current_user_id())
            || $order->get_meta('_lca_consent') !== 'granted') { return []; }
        if ($order->get_meta('_folio_parent_order_id') || $order->get_meta('_folio_split_from_order_id')) { return []; }
        $ids = (array) $order->get_meta('_folio_child_order_ids');
        $orders = $ids ? array_filter(array_map('wc_get_order', array_map('absint', $ids))) : [$order];
        $items = []; $confirmed = true; $shipping = 0; $tax = 0;
        foreach ($orders as $part) {
            if ($part->get_customer_id() !== $order->get_customer_id() || $part->get_currency() !== $order->get_currency()
                || ($part->get_id() !== $order->get_id() && (int) ($part->get_meta('_folio_parent_order_id') ?: $part->get_meta('_folio_split_from_order_id')) !== $order->get_id())) { return []; }
            if (!in_array($part->get_status(), ['pending', 'on-hold', 'processing', 'completed'], true)) { return []; }
            $confirmed = $confirmed && (bool) $part->get_meta('_lca_payment_confirmed_at');
            $shipping += (float) $part->get_shipping_total(); $tax += (float) $part->get_total_tax();
            foreach ($part->get_items() as $line) {
                $product = $line->get_product();
                if ($product && $line->get_quantity() > 0) {
                    $items[] = self::item($product, (float) $line->get_quantity(), (float) $line->get_total() / $line->get_quantity());
                }
            }
        }
        if (!$items) { return []; }
        $params = self::ecommerce($items);
        $params['currency'] = $order->get_currency();
        $params['transaction_id'] = 'woo-' . $order->get_id();
        $params['shipping'] = round($shipping, 2); $params['tax'] = round($tax, 2);
        // purchase means placed order, NOT proof of payment. Payment is separate.
        $params['payment_state'] = $confirmed ? 'confirmed' : 'unconfirmed';
        $events = [['name' => 'purchase', 'params' => $params]];
        if ($confirmed) { $events[] = ['name' => 'payment_confirmed', 'params' => $params]; }
        return $events;
    }

    public static function assets(): void {
        if (!self::enabled() || self::staff() || !function_exists('is_shop') || is_admin()) { return; }
        $receipt = is_order_received_page();
        if (is_account_page() || is_checkout_pay_page() || (!is_shop() && !is_product_taxonomy() && !is_product() && !is_cart() && !is_checkout()
                && !is_search() && !is_front_page() && !is_singular(['post', 'page'])
                && !has_shortcode((string) get_post_field('post_content', get_queried_object_id()), 'pc_quick_order'))) { return; }
        $path = (string) wp_parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
        if (!$receipt && $path !== '/' && self::public_path($path) === '/') { return; }
        if ($receipt) { nocache_headers(); }
        $events = [];
        if (is_product()) {
            $product = wc_get_product(get_queried_object_id());
            if ($product) { $events[] = ['name' => 'view_item', 'params' => self::ecommerce([self::item($product)])]; }
        }
        if (is_cart() || (is_checkout() && !$receipt)) {
            $items = self::cart_items();
            if ($items) { $events[] = ['name' => is_cart() ? 'view_cart' : 'begin_checkout', 'params' => self::ecommerce($items)]; }
        }
        if ($receipt) { $events = self::receipt_events(); }
        $query = isset($_GET['catalog_search']) ? (string) wp_unslash($_GET['catalog_search']) : get_search_query(false);
        global $wp_query;
        $count = (int) ($wp_query->found_posts ?? 0);
        if ($query !== '') {
            $events[] = ['name' => 'view_search_results', 'params' => ['search_term' => 'catalog_query_redacted', 'result_count' => $count, 'search_empty' => $count === 0]];
        }
        $filters = [];
        foreach (['brand', 'unit', 'location', 'in_stock', 'min_price', 'max_price', 'orderby'] as $field) {
            if (!empty($_GET[$field])) { $filters[] = $field; }
        }
        if ($filters) { $events[] = ['name' => 'catalog_filter', 'params' => ['filter_set' => implode(',', $filters), 'result_count' => $count]]; }
        $settings = self::settings();
        $config = ['id' => $settings['measurement_id'], 'debug' => $settings['debug'], 'segment' => self::segment(),
            'url' => admin_url('admin-ajax.php'), 'nonce' => wp_create_nonce('lca_events'), 'receipt' => $receipt,
            'page' => $receipt ? home_url('/analytics/order-receipt/') : home_url(self::public_path((string) ($_SERVER['REQUEST_URI'] ?? '/'))),
            'events' => $events, 'cart' => self::ecommerce(self::cart_items()), 'currency' => get_woocommerce_currency(),
            'privacy' => get_privacy_policy_url(), 'labels' => [
                'message' => __('Allow usage analytics to help improve product selection and checkout? You can refuse without affecting your order.', 'lavka-customer-analytics'),
                'allow' => __('Allow analytics', 'lavka-customer-analytics'), 'deny' => __('Refuse analytics', 'lavka-customer-analytics'),
                'settings' => __('Analytics preferences', 'lavka-customer-analytics'), 'privacy' => __('Privacy policy', 'lavka-customer-analytics')]];
        wp_enqueue_script('lca-storefront', plugins_url('assets/storefront.js', __FILE__), ['jquery'], self::VERSION, true);
        wp_add_inline_script('lca-storefront', 'window.lavkaAnalyticsConfig=' . wp_json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ';', 'before');
        wp_enqueue_style('lca-consent', plugins_url('assets/consent.css', __FILE__), [], self::VERSION);
    }

    public static function boot(): void {
        // Replaces only the frontend snippet; admin sees persisted Rank Math settings.
        add_filter('option_rank_math_google_analytic_options', static function ($value) {
            if (self::enabled() && !is_admin() && is_array($value)) { $value['install_code'] = false; }
            return $value;
        });
        add_action('init', static function () { load_plugin_textdomain('lavka-customer-analytics', false, dirname(plugin_basename(__FILE__)) . '/languages'); });
        add_action('wp_enqueue_scripts', [self::class, 'assets'], 90);
        add_action('woocommerce_after_shop_loop_item', [self::class, 'loop_item'], 99);
        add_action('woocommerce_add_to_cart', [self::class, 'add_to_cart'], 999, 4);
        add_action('woocommerce_after_cart_item_quantity_update', [self::class, 'quantity'], 999, 4);
        add_action('woocommerce_cart_item_removed', [self::class, 'removed'], 999, 2);
        add_action('woocommerce_before_cart_emptied', [self::class, 'emptied'], 999, 0);
        add_action('wp_ajax_lca_events', [self::class, 'flush']);
        add_action('wp_ajax_nopriv_lca_events', [self::class, 'flush']);
        add_action('woocommerce_checkout_create_order', [self::class, 'snapshot'], 999, 1);
        add_action('woocommerce_before_order_object_save', [self::class, 'inherit'], 999, 1);
        add_action('woocommerce_payment_complete', [self::class, 'payment_complete'], 999, 1);
        add_action('pc_wholesale_help_after_sections', [self::class, 'help']);
    }

    public static function help(): void {
        if (!self::enabled()) { return; }
        echo '<section class="pc-help-section" id="analytics-preferences"><h2>' . esc_html__('Analytics preferences', 'lavka-customer-analytics') . '</h2><p>';
        echo esc_html__('When analytics is enabled, choose Allow analytics or Refuse analytics on the storefront. Refusal does not change prices, the cart or ordering access.', 'lavka-customer-analytics');
        echo '</p><p>' . esc_html__('Use Analytics preferences on the storefront to change your choice. The customer account, balances, documents and payment forms are excluded from this analytics module.', 'lavka-customer-analytics') . '</p></section>';
    }
}
Lavka_Customer_Analytics::boot();
