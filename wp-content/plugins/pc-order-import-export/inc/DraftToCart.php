<?php
namespace PaintCore\PCOE;

defined('ABSPATH') || exit;

class DraftToCart
{
    /** Подписка на AJAX */
    public static function hooks(): void
    {
        add_action('wp_ajax_pcoe_draft_to_cart',        [self::class,'handle']);
        add_action('wp_ajax_nopriv_pcoe_draft_to_cart', [self::class,'handle']);
    }

    /** Сборка ссылки */
    public static function action_url(int $order_id, array $args = []): string
    {
        $args = array_merge([
            'action'   => 'pcoe_draft_to_cart',
            'order_id' => $order_id,
            '_wpnonce' => wp_create_nonce('pcoe_draft_to_cart'),
            'clear'    => '1',
        ], $args);

        return add_query_arg($args, admin_url('admin-ajax.php'));
    }

    /** Обработчик: положить позиции заказа в корзину + редирект в корзину */
    public static function handle(): void
    {
        if ( ! isset($_GET['_wpnonce']) || ! wp_verify_nonce($_GET['_wpnonce'], 'pcoe_draft_to_cart') ) {
            wp_die( esc_html__( 'Security check failed (bad nonce).', 'pc-order-import-export' ), '', ['response'=>403] );
        }

        $order_id = isset($_GET['order_id']) ? (int) $_GET['order_id'] : 0;
        if ( ! $order_id ) {
            wp_die( esc_html__( 'Order ID is missing.', 'pc-order-import-export' ), '', ['response'=>400] );
        }

        $order = wc_get_order($order_id);
        if ( ! $order ) {
            wp_die( esc_html__( 'Order not found.', 'pc-order-import-export' ), '', ['response'=>404] );
        }

        // Разрешение: владелец заказа или менеджер
        $can_manage = current_user_can('manage_woocommerce');
        $is_owner   = (int) $order->get_user_id() === (int) get_current_user_id();
        if ( ! $can_manage && ! $is_owner ) {
            wp_die( esc_html__( 'You do not have permission to perform this action.', 'pc-order-import-export' ), '', ['response'=>403] );
        }

        if ( function_exists('WC') && WC()->cart ) {
            if ( isset($_GET['clear']) && $_GET['clear'] ) {
                WC()->cart->empty_cart();
            }

            foreach ( $order->get_items() as $item ) {
                /** @var \WC_Order_Item_Product $item */
                $product = $item->get_product();
                if ( ! $product ) continue;

                $qty = (float) $item->get_quantity();
                $qty = wc_stock_amount($qty);
                if ( $qty <= 0 ) continue;

                // Стандартное добавление (Woo сам проверяет лимиты/остатки)
                WC()->cart->add_to_cart($product->get_id(), $qty);
            }
        }

        // Редирект в корзину и завершение
        $to = wc_get_cart_url();
        wp_safe_redirect( $to ?: home_url('/') );
        exit;
    }
}