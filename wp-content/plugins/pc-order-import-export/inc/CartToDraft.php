<?php
namespace PaintCore\PCOE;

use WC_Order;
use WC_Product;

defined('ABSPATH') || exit;

/**
 * Кошик → Чернетка замовлення (wc-pc-draft)
 */
class CartToDraft
{
    /** Повісити AJAX-обробники */
    public static function hooks(): void
    {
        add_action('wp_ajax_pcoe_cart_to_draft',        [self::class, 'handle']);
        add_action('wp_ajax_nopriv_pcoe_cart_to_draft', [self::class, 'handle']);
    }

    /**
     * Допоміжне: побудувати URL (GET) для кнопки/посилання
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

    /**
     * Обробник: зберегти поточний кошик як чернетку (і опціонально очистити кошик)
     *
     * GET params:
     *   _wpnonce : nonce('pcoe_cart_to_draft')
     *   clear    : '1' → очистити кошик після створення (опційно)
     *   title    : назва чернетки (опційно)
     */
    public static function handle(): void
    {
        // nonce: принимаем и GET, и POST
        $nonce = isset($_REQUEST['_wpnonce']) ? (string) $_REQUEST['_wpnonce'] : '';
        if (!$nonce || !wp_verify_nonce($nonce, 'pcoe_cart_to_draft')) {
            wp_die('Bad nonce', '', ['response' => 403]);
        }

        if (!function_exists('WC') || !WC()->cart) {
            wp_die('Cart not available', '', ['response' => 400]);
        }

        $cart  = WC()->cart;
        $items = $cart->get_cart();
        if (empty($items)) {
            wp_safe_redirect(wc_get_cart_url());
            exit;
        }

        // создаём черновик
        $order = wc_create_order([
            'status'      => 'wc-pc-draft',
            'customer_id' => get_current_user_id() ?: 0,
        ]);
        if (!$order instanceof \WC_Order) {
            wp_die('Cannot create draft order', '', ['response' => 500]);
        }

        // название (опц.)
        $title = isset($_REQUEST['title']) ? sanitize_text_field((string) $_REQUEST['title']) : '';
        if ($title !== '') {
            $order->update_meta_data('_pc_draft_title', $title);
        }

        // переносим позиции из корзины
        $imported = 0;
        foreach ($items as $ci) {
            $product = $ci['data'] ?? null;
            if (!$product instanceof \WC_Product) continue;

            $qty = wc_stock_amount((float)($ci['quantity'] ?? 0));
            if ($qty <= 0) continue;

            $item_args = [];
            if (isset($ci['line_total'], $ci['line_subtotal']) && $qty > 0) {
                $unit = (float)$ci['line_total'] / (float)$qty;
                if ($unit > 0) {
                    $item_args['subtotal'] = $unit * $qty;
                    $item_args['total']    = $unit * $qty;
                }
            }

            try { $order->add_product($product, $qty, $item_args); $imported++; } catch (\Throwable $e) {}
        }

        try { $order->calculate_totals(false); } catch (\Throwable $e) {}
        $order->save();

        // очистить корзину?
        $clear = isset($_REQUEST['clear']) ? (string) $_REQUEST['clear'] : '';
        if ($clear === '1') {
            $cart->empty_cart();
        }

        // редирект
        $order_id = (int) $order->get_id();
        if (current_user_can('edit_shop_orders')) {
            wp_safe_redirect(admin_url('post.php?post='.$order_id.'&action=edit'));
        } else {
            $view = self::customer_view_link($order);
            wp_safe_redirect($view ?: wc_get_account_endpoint_url('orders'));
        }
        exit;
    }

    /** Посилання «Переглянути замовлення» для власника */
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