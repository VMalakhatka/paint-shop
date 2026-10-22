<?php
/** Read-only local checks for theme-independent settings and the footer adapter. */
if (!defined('WP_CLI') || !WP_CLI || wp_get_environment_type() !== 'local'
    || parse_url(home_url(), PHP_URL_HOST) !== 'paint.local') throw new RuntimeException('Local only');
$checks = 0;
$check = static function ($condition, string $label) use (&$checks): void {
    if (!$condition) throw new RuntimeException($label);
    $checks++;
};
$saved_get = $_GET;
try {
    $_GET = [];
    $check(psufp_calc_per_page() === 24, 'Catalogue default remains 24 without the theme override');
    foreach ([12, 48] as $size) {
        $_GET = ['pp' => (string) $size];
        $check(psufp_calc_per_page() === $size, 'Explicit page size preserved: ' . $size);
    }
    $crumbs = apply_filters('woocommerce_breadcrumb_defaults', ['delimiter' => '/', 'home' => 'sentinel']);
    $check(str_contains($crumbs['delimiter'], '→') && $crumbs['home'] === 'sentinel', 'Breadcrumb separator moved without changing other settings');
    $check(has_action('woocommerce_checkout_create_order', 'pc_checkout_set_created_date') === 10, 'Checkout date policy registered by Paint Core');
    $order = new WC_Order();
    $before = current_time('timestamp');
    pc_checkout_set_created_date($order, []);
    $check($order->get_id() === 0 && $order->get_date_created()->getTimestamp() >= $before
        && $order->get_date_created()->getTimestamp() <= current_time('timestamp'), 'Existing date semantics preserved on an unsaved order');

    $header_priority = has_action('generate_before_header', 'lavka_topbar');
    psu_site_footer_setup();
    $check(has_action('generate_before_header', 'lavka_topbar') === $header_priority, 'Header placement preserved');
    $check(has_action('generate_footer', 'generate_construct_footer') === false, 'Parent footer removed');
    $check(has_action('generate_footer', 'generate_construct_footer_widgets') === false, 'Parent widget layout removed');
    $check(has_action('generate_before_footer_content', 'pc_wayforpay_render_legal_footer') === false, 'Legal block detached from its old placement');
    $check(has_action('wp_footer', 'psu_render_site_footer') === 5, 'Standard WP fallback available');
    ob_start(); do_action('generate_footer'); psu_render_site_footer(); $html = ob_get_clean();
    $check(substr_count($html, '<footer class="psu-site-footer"') === 1, 'Footer renders exactly once across both placements');
    $check(substr_count($html, '<section class="pc-legal-footer"') === 1, 'Seller details render exactly once');
    foreach (['terms', 'payment_delivery', 'refund', 'contacts'] as $key) {
        $check(str_contains($html, esc_url(pc_wayforpay_page_url($key))), 'Existing policy link preserved: ' . $key);
    }
    $seller = pc_wayforpay_seller();
    $check(str_contains($html, esc_html($seller['name'])) && str_contains($html, esc_html($seller['tax_id'])), 'Seller identity comes from the legal module');
    $check(str_contains($html, 'https://wayforpay.com/') && !str_contains($html, 'generatepress.com'), 'Payment mark retained; theme credit removed');
    foreach (['uk' => 'Каталог', 'ru_RU' => 'Каталог'] as $locale => $translation) {
        $switched = switch_to_locale($locale);
        try {
            $check(__('Catalogue', 'paint-shop-ux') === $translation, 'Footer labels translated: ' . $locale);
        } finally { if ($switched) restore_previous_locale(); }
    }
    echo wp_json_encode(['passed' => $checks]), "\n";
} finally { $_GET = $saved_get; }
