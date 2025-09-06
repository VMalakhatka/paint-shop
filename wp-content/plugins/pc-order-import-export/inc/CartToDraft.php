<?php
namespace PaintCore\PCOE;

use WC_Order;
use WC_Product;

defined('ABSPATH') || exit;

class CartToDraft
{
    public static function hooks(): void
    {
        add_action('wp_ajax_pcoe_cart_to_draft',        [self::class,'handle']);
        add_action('wp_ajax_nopriv_pcoe_cart_to_draft', [self::class,'handle']);
    }

    /** URL для кнопки */
    public static function action_url(array $args = []): string
    {
        $base = admin_url('admin-ajax.php');
        $args = array_merge([
            'action'   => 'pcoe_cart_to_draft',
            '_wpnonce' => wp_create_nonce('pcoe_cart_to_draft'),
        ], $args);
        return add_query_arg($args, $base);
    }

    /** Обробник: створити чернетку з вмісту кошика */
    public static function handle(): void
    {
        if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'pcoe_cart_to_draft')) {
            wp_die('Bad nonce', '', ['response'=>403]);
        }
        if (!function_exists('WC') || !WC()->cart) {
            wp_die('Cart not available', '', ['response'=>400]);
        }

        $title = isset($_REQUEST['title']) ? sanitize_text_field((string)$_REQUEST['title']) : '';
        $clear = !empty($_REQUEST['clear']);

        $order = wc_create_order([
            'status'      => 'wc-pc-draft',
            'customer_id' => get_current_user_id() ?: 0,
        ]);

        if (!$order instanceof WC_Order) {
            wp_die('Cannot create draft', '', ['response'=>500]);
        }

        foreach (WC()->cart->get_cart() as $line) {
            /** @var WC_Product|null $p */
            $p   = $line['data'] ?? null;
            $qty = isset($line['quantity']) ? (float)$line['quantity'] : 0;
            if (!$p || $qty <= 0) continue;

            // Чернетка — додаємо як є (без перевірок залишку/мін-макс)
            $qty = wc_stock_amount($qty);
            if ($qty <= 0) continue;

            try { $order->add_product($p, $qty); } catch (\Throwable $e) { /* пропускаємо */ }
        }

        if ($title !== '') {
            $order->update_meta_data('_pc_draft_title', $title);
            $order->save();
        }

        try { $order->calculate_totals(false); } catch (\Throwable $e) {}

        if ($clear) { WC()->cart->empty_cart(); }

        // редірект у «Моє замовлення» або у кошик
        $redirect = wc_get_endpoint_url('view-order', $order->get_id(), wc_get_page_permalink('myaccount'));
        if (!$redirect) $redirect = wc_get_cart_url();

        wp_safe_redirect($redirect);
        exit;
    }
}