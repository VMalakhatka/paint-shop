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

    protected static function redirect_now(string $url): void {
        if (!$url) $url = wc_get_account_endpoint_url('orders');

        if (!headers_sent()) {
            wp_safe_redirect($url);
            exit;
        }

        // Фолбек, якщо заголовки вже відправлені або ajax-браузер не слідує 302
        $url_js = esc_url_raw($url);
        echo '<meta http-equiv="refresh" content="0;url='.esc_attr($url_js).'">';
        echo '<script>location.href='.json_encode($url_js).';</script>';
        exit;
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
        // 1) Nonce (GET/POST)
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
            self::redirect_now(wc_get_cart_url());
        }

        // 2) Анти-даблклік: ХЕШ кошика + ранній "LOCK"
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
            // вже є ID створеного чернетка — ведемо туди (або в Список)
            $existing_id = (int) $lock_val;
            $url = current_user_can('edit_shop_orders')
                ? admin_url('post.php?post='.$existing_id.'&action=edit')
                : wc_get_account_endpoint_url('orders');
            self::redirect_now($url);
        }
        if ($lock_val === 'LOCK') {
            // хтось вже відправив форму — просто на «Замовлення»
            self::redirect_now(wc_get_account_endpoint_url('orders'));
        }
        // Ставимо LOCK одразу, щоб не було гонки
        set_transient($lock_key, 'LOCK', 30); // 30 сек більше ніж досить

        // 3) Створюємо чернетку
        $order = wc_create_order([
            'status'      => 'wc-pc-draft',
            'customer_id' => get_current_user_id() ?: 0,
        ]);
        if (!$order instanceof \WC_Order) {
            // знімаємо lock у разі фейлу
            delete_transient($lock_key);
            wp_die('Cannot create draft order', '', ['response' => 500]);
        }

        // 4) Назва (опц.)
        $title = isset($_REQUEST['title']) ? sanitize_text_field((string) $_REQUEST['title']) : '';
        if ($title !== '') {
            $order->update_meta_data('_pc_draft_title', $title);
        }

        // 5) Переносимо позиції
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

            try { $order->add_product($product, $qty, $item_args); } catch (\Throwable $e) {}
        }

        try { $order->calculate_totals(false); } catch (\Throwable $e) {}
        $order->save();

        $order_id = (int) $order->get_id();

        // Міняємо LOCK на створений ID (другий клік піде сюди вище)
        set_transient($lock_key, $order_id, 30);

        // 6) ЧИСТИМО КОШИК (жорстко, включно з persistent)
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

        // --- Куди редиректити
        $order_id = (int) $order->get_id();
        $dest = isset($_REQUEST['dest']) ? sanitize_key((string) $_REQUEST['dest']) : 'orders';

        if ($dest === 'admin') {
            $redirect = admin_url('post.php?post='.$order_id.'&action=edit');
        } elseif ($dest === 'view') {
            $redirect = self::customer_view_link($order) ?: wc_get_account_endpoint_url('orders');
        } else { // 'orders' за замовчуванням
            $redirect = wc_get_account_endpoint_url('orders');
        }

        self::redirect_now($redirect);
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