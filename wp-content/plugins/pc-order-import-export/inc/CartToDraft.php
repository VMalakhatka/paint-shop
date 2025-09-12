<?php
namespace PaintCore\PCOE;

use WC_Order;
use WC_Product;

defined('ABSPATH') || exit;

// Ensure allocator functions are available
if (!function_exists('\\PaintCore\\Stock\\pc_compute_and_stamp_item_plan')
    || !function_exists('\\PaintCore\\Stock\\stamp_item_plan')) {
    require_once WP_PLUGIN_DIR . '/paint-core/inc/order-allocator.php';
}

/**
 * Cart → Draft Order (wc-pc-draft)
 *
 * - Creates a draft order from the current cart via AJAX.
 * - Optionally clears the cart after creation.
 * - Stamps the allocation plan for each line item right away.
 */
class CartToDraft
{
    /** Register AJAX handlers */
    public static function hooks(): void
    {
        add_action('wp_ajax_pcoe_cart_to_draft',        [self::class, 'handle']);
        add_action('wp_ajax_nopriv_pcoe_cart_to_draft', [self::class, 'handle']);
    }

    /**
     * Helper: build action URL (GET) for button/link
     */
    public static function action_url(array $args = []): string
    {
        $def = [
            'action'   => 'pcoe_cart_to_draft',
            '_wpnonce' => wp_create_nonce('pcoe_cart_to_draft'),
        ];
        $q = array_merge($def, $args);
        return add_query_arg($q, admin_url('admin-ajax.php'));
    }

    /** Safe redirect (works even if headers already sent) */
    protected static function redirect_now(string $url): void {
        if (!$url) {
            $url = wc_get_account_endpoint_url('orders');
        }

        if (!headers_sent()) {
            wp_safe_redirect($url);
            exit;
        }

        // Fallback for cases when headers are already sent
        $url_js = esc_url_raw($url);
        echo '<meta http-equiv="refresh" content="0;url='.esc_attr($url_js).'">';
        echo '<script>location.href='.json_encode($url_js).';</script>';
        exit;
    }

    /**
     * Handler: save current cart as a draft order (optionally clear the cart)
     *
     * GET/POST params:
     *   _wpnonce : nonce('pcoe_cart_to_draft')
     *   clear    : '1' → clear cart after creation (optional)  [kept for compatibility, not used here]
     *   title    : draft title (optional)
     *   dest     : 'admin' | 'view' | 'orders' (default: 'orders')
     */
    public static function handle(): void
    {
        if (!function_exists('\\PaintCore\\Stock\\pc_compute_and_stamp_item_plan')
            || !function_exists('\\PaintCore\\Stock\\stamp_item_plan')) {
            require_once WP_PLUGIN_DIR . '/paint-core/inc/order-allocator.php';
        }

        // 1) Nonce (GET/POST)
        $nonce = isset($_REQUEST['_wpnonce']) ? (string) $_REQUEST['_wpnonce'] : '';
        if (!$nonce || !wp_verify_nonce($nonce, 'pcoe_cart_to_draft')) {
            wp_die(
                esc_html__('Security check failed (bad nonce).', 'pc-order-import-export'),
                '',
                ['response' => 403]
            );
        }

        if (!function_exists('WC') || !WC()->cart) {
            wp_die(
                esc_html__('Cart is not available.', 'pc-order-import-export'),
                '',
                ['response' => 400]
            );
        }

        $cart  = WC()->cart;
        $items = $cart->get_cart();
        if (empty($items)) {
            self::redirect_now(wc_get_cart_url());
        }

        // 2) Anti double-click: snapshot hash + early LOCK
        $snapshot = [];
        foreach ($items as $ci) {
            $snapshot[] = [
                (int)($ci['product_id'] ?? 0),
                (int)($ci['variation_id'] ?? 0),
                (float)($ci['quantity'] ?? 0),
            ];
        }
        $hash = md5(wp_json_encode($snapshot));
        $uid  = get_current_user_id() ?: 0;
        $lock_key = 'pcoe_cart2draft_' . $uid . '_' . $hash;

        $lock_val = get_transient($lock_key);
        if ($lock_val && $lock_val !== 'LOCK') {
            // Draft already created — redirect there (or to Orders)
            $existing_id = (int) $lock_val;
            $url = current_user_can('edit_shop_orders')
                ? admin_url('post.php?post='.$existing_id.'&action=edit')
                : wc_get_account_endpoint_url('orders');
            self::redirect_now($url);
        }
        if ($lock_val === 'LOCK') {
            // Request is already in-flight — go to Orders
            self::redirect_now(wc_get_account_endpoint_url('orders'));
        }
        // Place LOCK immediately to avoid race
        set_transient($lock_key, 'LOCK', 30); // 30s is more than enough

        // 3) Create draft order
        $order = wc_create_order([
            'status'      => 'wc-pc-draft',
            'customer_id' => get_current_user_id() ?: 0,
        ]);
        if (!$order instanceof \WC_Order) {
            delete_transient($lock_key);
            wp_die(
                esc_html__('Cannot create draft order.', 'pc-order-import-export'),
                '',
                ['response' => 500]
            );
        }

        // 4) Optional title
        $title = isset($_REQUEST['title']) ? sanitize_text_field((string) $_REQUEST['title']) : '';
        if ($title !== '') {
            $order->update_meta_data('_pc_draft_title', $title);
        }

        // 5) Move items from cart and stamp allocation plan
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('pc(check): has allocator='.(int)function_exists('\\PaintCore\\Stock\\pc_compute_and_stamp_item_plan'));
        }

        foreach ($items as $ci) {
            $product = $ci['data'] ?? null;
            if (!$product instanceof \WC_Product) continue;

            $qty = wc_stock_amount((float)($ci['quantity'] ?? 0));
            if ($qty <= 0) continue;

            $item_args = [];
            // Preserve totals where possible (unit price * qty)
            if (isset($ci['line_total'], $ci['line_subtotal']) && $qty > 0) {
                $unit = (float)$ci['line_total'] / (float)$qty;
                if ($unit > 0) {
                    $item_args['subtotal'] = $unit * $qty;
                    $item_args['total']    = $unit * $qty;
                }
            }

            try {
                $item_id = $order->add_product($product, $qty, $item_args);
                if ($item_id) {
                    $item = $order->get_item($item_id);
                    if ($item instanceof \WC_Order_Item_Product) {
                        // Stamp allocation plan right away
                        \PaintCore\Stock\pc_compute_and_stamp_item_plan(
                            $item,
                            $product,
                            (int)$qty,
                            'cart-to-draft'
                        );
                        $item->save();

                        if (defined('WP_DEBUG') && WP_DEBUG) {
                            error_log(
                                'pc(plan): cart2draft order='.$order->get_id()
                                .' pid='.$product->get_id()
                                .' qty='.(int)$qty
                            );
                        }
                    }
                }
            } catch (\Throwable $e) {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('pc(plan-err): '.$e->getMessage());
                }
            }
        }

        try { $order->calculate_totals(false); } catch (\Throwable $e) {}
        $order->save();

        $order_id = (int) $order->get_id();

        // Switch LOCK to created ID (subsequent click will reuse it)
        set_transient($lock_key, $order_id, 30);

        // 6) Clear the cart (hard, including persistent)
        if ( WC()->cart ) {
            WC()->cart->empty_cart(true);
            if ( WC()->session ) {
                WC()->session->set('cart', array());
                WC()->session->set('applied_coupons', array());
                WC()->session->set('cart_totals', null);
                WC()->session->set('cart_hash', '');
            }
            if ( function_exists('wc_setcookie') ) {
                wc_setcookie('woocommerce_items_in_cart', 0);
                wc_setcookie('woocommerce_cart_hash', '');
            }
        }

        // --- Where to redirect
        $dest = isset($_REQUEST['dest']) ? sanitize_key((string) $_REQUEST['dest']) : 'orders';

        if ($dest === 'admin') {
            $redirect = admin_url('post.php?post='.$order_id.'&action=edit');
        } elseif ($dest === 'view') {
            $redirect = self::customer_view_link($order) ?: wc_get_account_endpoint_url('orders');
        } else { // default: 'orders'
            $redirect = wc_get_account_endpoint_url('orders');
        }

        self::redirect_now($redirect);
    }

    /** "View order" link for the owner */
    protected static function customer_view_link(WC_Order $order): string
    {
        $uid = get_current_user_id();
        if ($uid && (int)$order->get_user_id() === (int)$uid) {
            $base = wc_get_page_permalink('myaccount');
            if ($base) {
                return wc_get_endpoint_url('view-order', $order->get_id(), $base);
            }
        }
        return '';
    }
}