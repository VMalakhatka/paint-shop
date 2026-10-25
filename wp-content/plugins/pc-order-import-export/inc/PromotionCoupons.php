<?php
namespace PaintCore\PCOE;

/** Gift instructions travel with the order; WooCommerce owns coupon validation and usage. */
final class PromotionCoupons {
    const GIFT = '_pcoe_coupon_gift';
    const START = '_pcoe_coupon_start';
    const SNAPSHOT = '_pcoe_coupon_gifts';

    public static function hooks(): void {
        add_action('woocommerce_coupon_options', [self::class, 'fields'], 10, 2);
        add_action('woocommerce_coupon_options_save', [self::class, 'save'], 10, 2);
        add_filter('woocommerce_coupon_is_valid', [self::class, 'valid'], 10, 2);
        add_filter('woocommerce_coupon_get_amount', [self::class, 'amount'], 10, 2);
        add_filter('woocommerce_coupon_get_free_shipping', [self::class, 'shipping'], 10, 2);
        add_action('woocommerce_checkout_create_order', [self::class, 'snapshot']);
        add_action('woocommerce_store_api_checkout_update_order_meta', [self::class, 'snapshot']);
        add_action('woocommerce_admin_order_data_after_order_details', [self::class, 'render']);
        add_action('woocommerce_email_after_order_table', [self::class, 'email'], 10, 4);
    }

    public static function fields($id, $coupon): void {
        woocommerce_wp_text_input(['id'=>self::START, 'label'=>__('Promotion start date', 'pc-order-import-export'),
            'type'=>'date', 'value'=>$coupon->get_meta(self::START),
            'description'=>__('Starts at midnight in the site timezone. Leave empty to start immediately.', 'pc-order-import-export'), 'desc_tip'=>true]);
        woocommerce_wp_textarea_input(['id'=>self::GIFT, 'label'=>__('Gift instructions for the manager', 'pc-order-import-export'),
            'value'=>$coupon->get_meta(self::GIFT),
            'description'=>__('Enter the promotion and gift to add. A non-empty value makes this a gift-only coupon: no discount or free shipping. Leave empty for a regular discount coupon.', 'pc-order-import-export')]);
        echo '<p class="form-field">' . esc_html__('Use Generate coupon code above. Set the expiry, usage restrictions and usage limits in the standard coupon tabs. Blank limits mean unlimited usage.', 'pc-order-import-export') . '</p>';
        if (!wc_coupons_enabled()) {
            echo '<p class="form-field"><strong>' . esc_html__('Coupons are disabled. An administrator must enable coupons in WooCommerce settings before customers can enter codes.', 'pc-order-import-export') . '</strong></p>';
        }
    }

    public static function save($id, $coupon): void {
        // WooCommerce already verifies its edit nonce; retain object-specific permission here.
        if (!current_user_can('edit_shop_coupon', $id)) return;
        $gift = sanitize_textarea_field(wp_unslash($_POST[self::GIFT] ?? ''));
        $start = sanitize_text_field(wp_unslash($_POST[self::START] ?? ''));
        if ($start !== '' && !self::date_valid($start)) {
            \WC_Admin_Meta_Boxes::add_error(__('Invalid promotion start date. The previous date has been kept.', 'pc-order-import-export'));
            $start = (string) $coupon->get_meta(self::START);
        }
        $coupon->update_meta_data(self::GIFT, $gift);
        $coupon->update_meta_data(self::START, $start);
        if ($gift !== '') {
            $coupon->set_discount_type('fixed_cart');
            $coupon->set_amount(0);
            $coupon->set_free_shipping(false);
        }
        $coupon->save();
    }

    public static function date_valid(string $date): bool {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        return $parsed && $parsed->format('Y-m-d') === $date;
    }

    public static function valid($valid, $coupon) {
        $start = (string) $coupon->get_meta(self::START);
        if ($start !== '' && (!self::date_valid($start) || current_datetime()->format('Y-m-d') < $start)) {
            throw new \Exception(__('This promotion has not started yet.', 'pc-order-import-export'));
        }
        return $valid;
    }

    public static function amount($amount, $coupon) {
        return trim((string) $coupon->get_meta(self::GIFT)) !== '' ? 0 : $amount;
    }

    public static function shipping($enabled, $coupon) {
        return trim((string) $coupon->get_meta(self::GIFT)) !== '' ? false : $enabled;
    }

    public static function snapshot($order): void {
        $gifts = [];
        foreach ($order->get_coupon_codes() as $code) {
            $coupon = new \WC_Coupon($code);
            $gift = trim((string) $coupon->get_meta(self::GIFT));
            if ($gift !== '') $gifts[$code] = $gift;
        }
        // Called before checkout persistence; do not write separately or notify twice on retries.
        $order->update_meta_data(self::SNAPSHOT, $gifts);
    }

    public static function render($order): void {
        $gifts = $order->get_meta(self::SNAPSHOT);
        if (!is_array($gifts) || !$gifts) return;
        echo '<div class="pcoe-promotion-gifts" style="clear:both"><h3>' . esc_html__('Add promotional gifts', 'pc-order-import-export') . '</h3><ul>';
        foreach ($gifts as $code => $gift) echo '<li><strong>' . esc_html($code) . '</strong>: ' . nl2br(esc_html($gift)) . '</li>';
        echo '</ul></div>';
    }

    public static function email($order, $sent_to_admin, $plain_text, $email): void {
        if (!$sent_to_admin) return;
        if (!$plain_text) { self::render($order); return; }
        $gifts = $order->get_meta(self::SNAPSHOT);
        if (!is_array($gifts) || !$gifts) return;
        echo "\n" . __('Add promotional gifts', 'pc-order-import-export') . "\n";
        foreach ($gifts as $code => $gift) echo wp_strip_all_tags($code . ': ' . $gift) . "\n";
    }
}
