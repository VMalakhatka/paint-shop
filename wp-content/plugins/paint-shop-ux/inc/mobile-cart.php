<?php
defined('ABSPATH') || exit;

/** Cache-neutral shell; session totals arrive only through uncached Woo AJAX. */
function psu_mobile_cart_html(bool $with_cart = false): string {
    $cart = $with_cart && function_exists('WC') ? WC()->cart : null;
    $count = $cart ? $cart->get_cart_contents_count() : 0;
    if (apply_filters('pcoe_catalog_draft_mode', false)) $count = 0;
    $html = '<div id="psu-mobile-cart" class="psu-mobile-cart" data-count="' . esc_attr((string) $count) . '" hidden>';
    $html .= '<div class="psu-mobile-cart__summary"><span class="psu-mobile-cart__count">';
    /* translators: %s: number of units in the cart, not distinct product lines. */
    $html .= esc_html(sprintf(__('In cart: %s', 'paint-shop-ux'), wc_format_localized_decimal($count)));
    $html .= '</span><span class="psu-mobile-cart__amount">' . esc_html__('Products:', 'paint-shop-ux') . ' ';
    $html .= $count > 0 ? wp_kses_post($cart->get_cart_subtotal()) : '';
    $html .= '</span><span class="psu-mobile-cart__notice" role="status" aria-live="polite"></span></div>';
    $html .= '<a class="psu-mobile-cart__link" href="' . esc_url(wc_get_cart_url()) . '">';
    $html .= esc_html__('Go to cart', 'paint-shop-ux') . '</a>';
    $html .= '<button type="button" class="psu-mobile-cart__hide" aria-label="' . esc_attr__('Hide the cart bar until the next product is added', 'paint-shop-ux') . '" title="' . esc_attr__('Hide the cart bar', 'paint-shop-ux') . '">';
    $html .= '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m3 3 18 18M10.6 10.6a2 2 0 0 0 2.8 2.8M9.5 5.3A11 11 0 0 1 12 5c6 0 10 7 10 7a18 18 0 0 1-3.3 3.9M6.2 6.2A19 19 0 0 0 2 12s4 7 10 7a11 11 0 0 0 5-1.3"/></svg></button></div>';
    return $html;
}

add_filter('woocommerce_add_to_cart_fragments', static function (array $fragments): array {
    $fragments['#psu-mobile-cart'] = psu_mobile_cart_html(true);
    return $fragments;
});

add_action('wc_ajax_psu_mobile_cart', static function (): void {
    nocache_headers();
    wp_send_json(['html' => psu_mobile_cart_html(true)]);
});

add_action('wp_enqueue_scripts', static function (): void {
    if (is_admin() || !function_exists('WC') || is_cart() || is_checkout() || is_account_page()
        || apply_filters('pcoe_catalog_draft_mode', false)) return;
    $base = dirname(__DIR__);
    $url = plugins_url('assets/', $base . '/paint-shop-ux.php');
    wp_enqueue_style('psu-mobile-cart', $url . 'mobile-cart.css', [], (string) filemtime($base . '/assets/mobile-cart.css'));
    wp_enqueue_script('psu-mobile-cart', $url . 'mobile-cart.js', ['jquery'], (string) filemtime($base . '/assets/mobile-cart.js'), true);
    wp_localize_script('psu-mobile-cart', 'psuMobileCart', [
        'url' => WC_AJAX::get_endpoint('psu_mobile_cart'),
        'added' => __('Product added', 'paint-shop-ux'),
    ]);
    add_action('wp_footer', static function (): void {
        echo psu_mobile_cart_html();
        echo '<div class="psu-mobile-cart-space" aria-hidden="true"></div>';
    }, 8);
}, 110);
